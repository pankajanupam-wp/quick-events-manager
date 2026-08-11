<?php
/**
 * WP-CLI commands for the occurrences table.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Cli;

use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuild and inspect the derived occurrences table.
 *
 * The occurrences table is derived from post meta, and a derived table can drift
 * from its source — a failed write, a plugin bypassing the hooks, a database
 * restored from a partial backup. A derived table with no rebuild path is a
 * table that eventually drifts with no way back, which is why this ships in the
 * same chunk as the table rather than being retrofitted during an incident.
 *
 * @since 26.0
 */
final class OccurrenceCommand {

	/**
	 * Events processed per batch.
	 */
	const BATCH_SIZE = 200;

	/**
	 * Register the command.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'qevm occurrence', self::class );
	}

	/**
	 * Regenerate every occurrence row from post meta.
	 *
	 * Safe to run at any time: post meta is the source of truth, so the worst a
	 * rebuild can do is restore the table to what the meta says it should be.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--event=<id>]
	 * : Rebuild a single event rather than all of them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qevm occurrence rebuild
	 *     wp qevm occurrence rebuild --dry-run
	 *     wp qevm occurrence rebuild --event=123
	 *
	 * @since 26.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public function rebuild( $args, $assoc_args ) {
		$dry_run  = isset( $assoc_args['dry-run'] );
		$event_id = isset( $assoc_args['event'] ) ? (int) $assoc_args['event'] : 0;

		if ( ! OccurrenceRepository::table_exists() ) {
			\WP_CLI::error( 'The occurrences table does not exist. Visit any admin page to run the upgrade, or reactivate the plugin.' );
		}

		$ids = $event_id > 0 ? array( $event_id ) : self::all_event_ids();

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'No events found. Nothing to rebuild.' );

			return;
		}

		$totals = array(
			'inserted'  => 0,
			'updated'   => 0,
			'deleted'   => 0,
			'unchanged' => 0,
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Rebuilding occurrences', count( $ids ) );

		foreach ( $ids as $id ) {
			$result = $dry_run ? self::preview( (int) $id ) : OccurrenceSync::sync( (int) $id );

			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + $result[ $key ];
			}

			$progress->tick();
		}

		$progress->finish();

		$summary = sprintf(
			'%d event(s): %d inserted, %d updated, %d deleted, %d unchanged.',
			count( $ids ),
			$totals['inserted'],
			$totals['updated'],
			$totals['deleted'],
			$totals['unchanged']
		);

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run. ' . $summary . ' Nothing was written.' );

			return;
		}

		\WP_CLI::success( $summary . ' ' . OccurrenceRepository::count_all() . ' occurrence row(s) in total.' );
	}

	/**
	 * Report how the table compares to what post meta says it should hold.
	 *
	 * Answers "has this drifted?" without changing anything, which is the
	 * question worth asking before deciding to rebuild.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qevm occurrence status
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function status() {
		if ( ! OccurrenceRepository::table_exists() ) {
			\WP_CLI::error( 'The occurrences table does not exist.' );
		}

		$ids     = self::all_event_ids();
		$drifted = array();

		foreach ( $ids as $id ) {
			$result = self::preview( (int) $id );

			if ( $result['inserted'] > 0 || $result['updated'] > 0 || $result['deleted'] > 0 ) {
				$drifted[] = (int) $id;
			}
		}

		\WP_CLI::line( sprintf( 'Events:      %d', count( $ids ) ) );
		\WP_CLI::line( sprintf( 'Occurrences: %d', OccurrenceRepository::count_all() ) );

		if ( empty( $drifted ) ) {
			\WP_CLI::success( 'Every event matches its meta. No drift.' );

			return;
		}

		\WP_CLI::warning(
			sprintf(
				'%d event(s) differ from their meta: %s',
				count( $drifted ),
				implode( ', ', array_slice( $drifted, 0, 20 ) )
			)
		);
		\WP_CLI::line( 'Run `wp qevm occurrence rebuild` to correct them.' );
	}

	/**
	 * Work out what a rebuild would change for one event, without writing.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return array{inserted: int, updated: int, deleted: int, unchanged: int}
	 */
	private static function preview( $event_id ) {
		$result = array(
			'inserted'  => 0,
			'updated'   => 0,
			'deleted'   => 0,
			'unchanged' => 0,
		);

		$wanted   = OccurrenceSync::build( $event_id );
		$existing = array();

		foreach ( OccurrenceRepository::for_event( $event_id ) as $occurrence ) {
			$existing[ $occurrence->start_utc() ] = $occurrence;
		}

		foreach ( $wanted as $row ) {
			$start = (string) ( $row['start_utc'] ?? '' );

			if ( ! isset( $existing[ $start ] ) ) {
				++$result['inserted'];

				continue;
			}

			$current = $existing[ $start ];
			unset( $existing[ $start ] );

			$differs = false;

			foreach ( $row as $column => $value ) {
				if ( (string) $current->get( $column ) !== (string) $value ) {
					$differs = true;

					break;
				}
			}

			if ( $differs ) {
				++$result['updated'];
			} else {
				++$result['unchanged'];
			}
		}

		$result['deleted'] = count( $existing );

		return $result;
	}

	/**
	 * Every event id, in every post status.
	 *
	 * Read straight from wp_posts rather than through WP_Query: this walks the
	 * whole table, and get_posts() would build a WP_Post object and prime the
	 * meta cache for each one only for the id to be taken off it.
	 *
	 * @since 26.0
	 *
	 * @return int[]
	 */
	private static function all_event_ids() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off maintenance walk of every event; caching it would serve nothing and evict warm entries.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s ORDER BY ID ASC",
				QEVM_POST_TYPE,
				'auto-draft'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $ids );
	}
}
