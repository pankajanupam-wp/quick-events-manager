<?php
/**
 * Migration 4 — renames the booker columns on the registrations table.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Install\Migrations;

use QuickEventsManager\Registration\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Moves `name`, `email` and `phone` to `booker_name`, `booker_email`,
 * `booker_phone`, and rebuilds the index that referenced the old name.
 *
 * Once attendees exist, an unqualified `name` on a registration is ambiguous:
 * the booking has a name and so does every person on it, and a reader has no
 * way to tell which one a column means. Ambiguous column names are how schemas
 * become unreadable. See docs/adr/0004-registration-attendee-split.md.
 *
 * This exists because **dbDelta cannot rename a column.** Given the new schema
 * it adds `booker_name` and leaves `name` sitting beside it, so a site that
 * upgrades ends up holding both — the old one full and the new one empty. The
 * copy and the drop have to be done here.
 *
 * Idempotent by inspection rather than by flag: it asks the database whether the
 * old columns are still there. A site that has already been through this finds
 * nothing to do, whether that was a moment ago or in a previous release.
 *
 * @since 26.0
 */
final class BookerColumns implements Migration {

	/**
	 * Columns being renamed, old name => new name.
	 *
	 * @var array<string, string>
	 */
	private const RENAMES = array(
		'name'  => 'booker_name',
		'email' => 'booker_email',
		'phone' => 'booker_phone',
	);

	/**
	 * Schema version this migration establishes.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function version(): int {
		return 4;
	}

	/**
	 * Description for logs and WP-CLI.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description(): string {
		return 'Rename registration name, email and phone to booker_name, booker_email and booker_phone.';
	}

	/**
	 * Copy one batch of values across, then retire the old columns.
	 *
	 * @since 26.0
	 *
	 * @param int $batch_size Maximum rows to copy in this call.
	 * @return int Rows copied. Zero means there is nothing left to do.
	 */
	public function run( int $batch_size ): int {
		global $wpdb;

		if ( ! Repository::table_exists() ) {
			return 0;
		}

		$table   = Repository::table();
		$columns = self::columns( $table );

		// Nothing to rename: either already done, or a fresh install.
		if ( ! isset( $columns['name'] ) ) {
			return 0;
		}

		/*
		 * Both sets of columns must be present before anything is copied. If
		 * dbDelta has not run yet — an ordering nothing should produce, but
		 * which a hand-run migration could — stopping here is better than
		 * writing into a column that does not exist.
		 */
		if ( ! isset( $columns['booker_name'] ) ) {
			return 0;
		}

		$copied = self::copy_batch( $table, $batch_size );

		if ( $copied > 0 ) {
			return $copied;
		}

		// Everything is across. The old columns can go.
		self::drop_old_columns( $table, $columns );
		self::rebuild_email_index( $table );

		return 0;
	}

	/**
	 * Copy up to $batch_size rows from the old columns to the new ones.
	 *
	 * @since 26.0
	 *
	 * @param string $table      Table name.
	 * @param int    $batch_size Maximum rows.
	 * @return int Rows copied.
	 */
	private static function copy_batch( string $table, int $batch_size ): int {
		global $wpdb;

		/*
		 * Only rows that still have something to move. A booking whose name was
		 * genuinely empty has nothing to copy and is not a row this needs to
		 * visit; leaving booker_name empty is the correct outcome for it.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration on our own table; nothing to cache.
		$copied = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				    SET booker_name = name, booker_email = email, booker_phone = phone
				  WHERE booker_name = '' AND booker_email = ''
				    AND ( name != '' OR email != '' )
				  LIMIT %d",
				$table,
				$batch_size
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $copied;
	}

	/**
	 * Drop the columns that have been superseded.
	 *
	 * The one destructive step in the plugin's migrations, and it is safe for a
	 * reason worth stating: it runs only after every value has been copied into
	 * the column that replaces it, and only on a table that still carries both.
	 * Nothing is lost, because nothing is dropped until it exists twice.
	 *
	 * @since 26.0
	 *
	 * @param string                $table   Table name.
	 * @param array<string, string> $columns Columns present, keyed by name.
	 * @return void
	 */
	private static function drop_old_columns( string $table, array $columns ): void {
		global $wpdb;

		foreach ( array_keys( self::RENAMES ) as $old ) {
			if ( ! isset( $columns[ $old ] ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Changing the schema is what a migration is; $old comes from this class's own constant, never from input, and %i cannot name a column in DDL.
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i DROP COLUMN `{$old}`", $table ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Point the event_email index at the renamed column.
	 *
	 * The dbDelta function compares indexes by name and leaves an existing one
	 * alone, so `event_email` would keep covering a column that no longer exists — which
	 * MySQL resolves by dropping the index silently when the column goes. The
	 * lookup behind duplicate-email detection would then be a table scan, and
	 * nothing would report it.
	 *
	 * @since 26.0
	 *
	 * @param string $table Table name.
	 * @return void
	 */
	private static function rebuild_email_index( string $table ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Changing the schema is what a migration is; the DDL is fixed and %i cannot name an index or column.
		$existing = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );

		$covers = array();

		foreach ( (array) $existing as $index ) {
			if ( 'event_email' === $index['Key_name'] ) {
				$covers[] = $index['Column_name'];
			}
		}

		if ( array( 'event_id', 'booker_email' ) === $covers ) {
			return;
		}

		if ( ! empty( $covers ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX `event_email`', $table ) );
		}

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX `event_email` (`event_id`, `booker_email`)', $table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The columns a table currently has, keyed by name.
	 *
	 * @since 26.0
	 *
	 * @param string $table Table name.
	 * @return array<string, string>
	 */
	private static function columns( string $table ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading our own table's shape; the answer changes as this migration runs, so caching it would be wrong.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$columns = array();

		foreach ( (array) $rows as $column ) {
			$columns[ (string) $column ] = (string) $column;
		}

		return $columns;
	}
}
