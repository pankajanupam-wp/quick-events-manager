<?php
/**
 * Brings data written by version 1.0 forward.
 *
 * @package QuickEventsManager
 */

namespace QEM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Moves 1.0's `events` posts onto the prefixed post type.
 *
 * Version 1.0 (2012) was 34 lines that registered one post type, `events`,
 * and did nothing else — no meta, no options, no tables. So this is the whole
 * of the legacy surface: some sites have rows in wp_posts with that post_type
 * and nothing more.
 *
 * The key is renamed because `events` is generic enough that any other event
 * plugin or theme registering it silently collides. Public URLs are unaffected:
 * the new post type sets its rewrite slug and archive back to `events`, so
 * /events/ and /events/{slug} resolve exactly as before.
 *
 * @since 26.0
 */
final class Migrator {

	/**
	 * The post type version 1.0 registered.
	 */
	const LEGACY_POST_TYPE = 'events';

	/**
	 * Option recording that the migration has run.
	 */
	const OPTION = 'qem_migrated_legacy_post_type';

	/**
	 * Run the migration once, if it is needed.
	 *
	 * Guarded by an option rather than a row count so that a site which has
	 * legitimately created a post of type `events` with a different plugin
	 * years later does not get it silently absorbed.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( get_option( self::OPTION ) ) {
			return;
		}

		$migrated = self::migrate();

		update_option( self::OPTION, QEM_VERSION );

		if ( $migrated > 0 ) {
			/**
			 * Fires after legacy events have been moved to the new post type.
			 *
			 * @since 26.0
			 *
			 * @param int $migrated Number of posts updated.
			 */
			do_action( 'qem_legacy_posts_migrated', $migrated );
		}
	}

	/**
	 * Rewrite the post type on every legacy event.
	 *
	 * A single UPDATE rather than a loop over WP_Query: there is no meta to
	 * transform and no hook that needs to fire, so touching wp_posts directly
	 * is both correct and the only approach that stays constant-time on a site
	 * with a large number of events.
	 *
	 * The ids are collected first purely so the object cache can be cleaned
	 * afterwards. WordPress caches each post individually in the `posts` group
	 * keyed by id, and a direct UPDATE leaves those entries holding the old
	 * post_type. Bumping the group's last_changed value is not enough — that
	 * only invalidates cached *query* results, not the post objects — so on a
	 * site with a persistent object cache the events would keep reporting the
	 * legacy type until something else evicted them.
	 *
	 * @since 26.0
	 *
	 * @return int Number of rows updated.
	 */
	public static function migrate() {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
				self::LEGACY_POST_TYPE
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_type' => QEM_POST_TYPE ),
			array( 'post_type' => self::LEGACY_POST_TYPE ),
			array( '%s' ),
			array( '%s' )
		);

		if ( ! $updated ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			clean_post_cache( (int) $id );
		}

		return (int) $updated;
	}

	/**
	 * Whether any legacy posts are still waiting to be migrated.
	 *
	 * Used by the tests and by the admin notice, not on normal requests.
	 *
	 * @since 26.0
	 *
	 * @return int Count of remaining legacy posts.
	 */
	public static function pending_count() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				self::LEGACY_POST_TYPE
			)
		);
	}
}
