<?php
/**
 * Deleting registrations a set time after the event they were for.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Privacy;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Registration\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The one part of this plugin whose whole job is destroying data.
 *
 * Keeping names, email addresses, phone numbers and answers about people's
 * health forever, because nobody got round to deleting them, is the ordinary
 * failure of every event site — and "we still have it" is the thing that turns
 * a small breach into a serious one. A retention period is the fix, and it is
 * only a fix if it actually runs.
 *
 * Which makes this the most dangerous class in the codebase, so:
 *
 * **Off unless somebody sets a number.** Zero means keep forever, and zero is
 * the default. A plugin update must never start deleting a site's attendee
 * history because a feature appeared.
 *
 * **Measured from when the event ended**, never from when somebody registered.
 * A conference booked eleven months ahead would otherwise have its earliest
 * registrations swept before anybody arrived.
 *
 * **An event with no date is never swept.** There is no answer to "how long
 * ago did this finish", and guessing means guessing about deletion.
 *
 * **Batched, and bounded per run.** A sweep that tries to clear ten years of a
 * busy site in one cron tick is a sweep that dies half way through, which is
 * survivable, and one that takes the site down with it, which is not.
 *
 * @since 26.0
 */
final class Retention {

	/**
	 * Cron hook.
	 */
	const HOOK = 'qevm_retention_sweep';

	/**
	 * Settings key holding the number of days.
	 */
	const SETTING = 'retention_days';

	/**
	 * Events examined in one run.
	 */
	const BATCH_SIZE = 50;

	/**
	 * The shortest retention period that can be set.
	 *
	 * A week rather than a day. Somebody typing 1 while thinking in months
	 * would otherwise clear the attendee list of every event that finished
	 * yesterday, and the people who most want this feature are the least
	 * likely to have a database backup they know how to restore.
	 */
	const MINIMUM_DAYS = 7;

	/**
	 * Hook the sweep.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'sweep_on_cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Run the sweep from cron, discarding the count.
	 *
	 * `sweep()` returns how many events it cleared, which is what a test or a
	 * WP-CLI command wants and what an action callback must not do.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function sweep_on_cron() {
		self::sweep();
	}

	/**
	 * Make sure the sweep is on the schedule, or off it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function schedule() {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( 0 === self::days() ) {
			if ( $scheduled ) {
				wp_unschedule_event( $scheduled, self::HOOK );
			}

			return;
		}

		if ( ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Take the sweep off the schedule.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function unschedule() {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::HOOK );
		}
	}

	/**
	 * The retention period in days, or 0 for keep forever.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public static function days() {
		$days = (int) Settings::get( self::SETTING, 0 );

		if ( $days <= 0 ) {
			return 0;
		}

		return max( self::MINIMUM_DAYS, $days );
	}

	/**
	 * Delete the registrations for events that finished long enough ago.
	 *
	 * @since 26.0
	 *
	 * @return int Events cleared in this run.
	 */
	public static function sweep() {
		$days = self::days();

		if ( 0 === $days ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$events = self::events_finished_before( $cutoff, self::BATCH_SIZE );
		$swept  = 0;

		foreach ( $events as $event_id ) {
			/**
			 * Filters whether an event's registrations are deleted by the sweep.
			 *
			 * Return false to keep them. The event is named rather than the
			 * registration because retention is a decision about an event's
			 * records as a whole — an AGM whose attendance has to be minuted,
			 * a training course whose certificates depend on the list.
			 *
			 * @since 26.0
			 *
			 * @param bool   $delete   Whether to delete.
			 * @param int    $event_id Event id.
			 * @param int    $days     Retention period in days.
			 * @param string $cutoff   UTC datetime everything older than is being removed.
			 */
			if ( ! apply_filters( 'qevm_retention_delete_event', true, $event_id, $days, $cutoff ) ) {
				continue;
			}

			$removed = Repository::delete_for_event( $event_id );

			if ( $removed > 0 ) {
				++$swept;

				/**
				 * Fires after an event's registrations have been deleted by the sweep.
				 *
				 * The only record that it happened. Nothing is written to the
				 * database about a deletion, deliberately — a log of who was
				 * removed is the personal data this feature exists to be rid of.
				 *
				 * @since 26.0
				 *
				 * @param int $event_id Event id.
				 * @param int $removed  Registrations deleted.
				 */
				do_action( 'qevm_retention_swept_event', $event_id, $removed );
			}
		}

		return $swept;
	}

	/**
	 * Events that finished before a cutoff and still have registrations.
	 *
	 * Read from the occurrence table, which is the one place with real indexed
	 * datetime columns — the same reason every other date question in this
	 * plugin goes there. `end_utc` is what "finished" means; an occurrence with
	 * no end has its start written into that column by the sync, so an event
	 * with no date at all simply has no row and is never returned.
	 *
	 * Joined against the registrations table so a sweep on a site with ten
	 * years of dateless drafts does not return fifty events with nothing to
	 * delete and then do nothing, run after run, for ever.
	 *
	 * @since 26.0
	 *
	 * @param string $cutoff UTC datetime.
	 * @param int    $limit  Events to return.
	 * @return int[]
	 */
	private static function events_finished_before( $cutoff, $limit ) {
		global $wpdb;

		if ( ! Repository::table_exists() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; a cached answer to "what is old enough to delete" is the wrong answer.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT o.event_id
				 FROM %i o
				 INNER JOIN %i r ON r.event_id = o.event_id
				 WHERE o.end_utc < %s
				 GROUP BY o.event_id
				 HAVING MAX( o.end_utc ) < %s
				 ORDER BY o.event_id ASC
				 LIMIT %d',
				Installer::table( 'occurrences' ),
				Repository::table(),
				$cutoff,
				$cutoff,
				(int) $limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return array_map( 'intval', (array) $ids );
	}
}
