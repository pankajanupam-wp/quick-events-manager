<?php
/**
 * Migration 1 — brings version 1.0's posts onto the prefixed post type.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Install\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Moves 1.0's `events` posts onto `qevm_event`.
 *
 * Version 1.0 (2012) was 34 lines that registered one post type, `events`, and
 * did nothing else — no meta, no options, no tables. So this is the whole of the
 * legacy surface: some sites have rows in wp_posts with that post_type and
 * nothing besides.
 *
 * The key is renamed because `events` is generic enough that any other event
 * plugin or theme registering it silently collides. Public URLs are unaffected:
 * the new post type sets its rewrite slug and archive back to `events`, so
 * /events/ and /events/{slug} resolve exactly as before.
 *
 * Running once and then never again matters here beyond the usual reasons. A
 * site could legitimately create a post of type `events` with some other plugin
 * years from now, and that post must not be silently absorbed into this one's
 * content. The stored version is what guarantees it: once this migration has
 * completed, nothing re-enters it.
 *
 * @since 26.0
 */
final class LegacyPostType implements Migration {

	/**
	 * The post type version 1.0 registered.
	 */
	const LEGACY_POST_TYPE = 'events';

	/**
	 * Schema version this migration establishes.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function version(): int {
		return 1;
	}

	/**
	 * Description for logs and WP-CLI.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description(): string {
		return "Move version 1.0's `events` posts onto the `qevm_event` post type.";
	}

	/**
	 * Move one batch of legacy posts.
	 *
	 * @since 26.0
	 *
	 * @param int $batch_size Maximum rows to move.
	 * @return int Rows moved.
	 */
	public function run( int $batch_size ): int {
		global $wpdb;

		/*
		 * Direct queries, uncached, deliberately. These rows have a post_type
		 * that is no longer registered, so get_posts() and WP_Query filter them
		 * out entirely and no core API can see them. Caching a migration that
		 * runs once and then finds nothing would be worse than useless: the
		 * entry would outlive the only moment it mattered.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT %d",
				self::LEGACY_POST_TYPE,
				$batch_size
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids = array_map( 'intval', $ids );

		/*
		 * Updated by id rather than by post_type so the write touches exactly
		 * the rows just read. A blanket `WHERE post_type = 'events'` would also
		 * pick up anything inserted between the select and the update, which is
		 * the one case where a batch could move a row it never counted.
		 */
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_type = %s WHERE ID IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d, not data.
				array_merge( array( QEVM_POST_TYPE ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/*
		 * WordPress caches each post individually in the `posts` group keyed by
		 * id, and a direct UPDATE leaves those entries holding the old
		 * post_type. Bumping the group's last_changed value is not enough —
		 * that only invalidates cached *query* results, not the post objects —
		 * so on a site with a persistent object cache the events would keep
		 * reporting the legacy type until something else evicted them.
		 */
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}

		return (int) $updated;
	}

	/**
	 * How many legacy posts are still waiting.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function pending_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting posts of an unregistered type, which WP_Query cannot see; the answer changes the moment run() executes, so caching it would be wrong.
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				self::LEGACY_POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $pending;
	}
}
