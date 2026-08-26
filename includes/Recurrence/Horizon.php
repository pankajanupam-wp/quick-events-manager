<?php
/**
 * Keeping the generation window moving.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceSync;

defined( 'ABSPATH' ) || exit;

/**
 * Regenerates recurring events so the horizon keeps two years ahead.
 *
 * Generation is bounded to two years, and that bound is relative to *now*. Set up
 * a weekly series today and it has two years of dates; do nothing for a year and
 * it has one. Left alone, every series on the site slowly runs out — invisibly,
 * because there is no error and the dates that exist are all correct. The
 * organiser finds out when somebody asks why the calendar stops in March.
 *
 * So the window is walked forward on a schedule. Extension is generation with a
 * later reference point: it inserts the dates that have come inside the horizon
 * and leaves everything already there untouched.
 *
 * **Events are visited in turn, by id, through a stored cursor** rather than by
 * picking whichever series has the fewest future dates. The obvious version —
 * "extend the ones closest to running out" — has a treadmill in it: a series
 * whose rule has already finished has no more dates to generate, stays closest to
 * running out for ever, and is picked every single run while nothing else is ever
 * reached. Rotating through by id costs a little redundant work on series that
 * need none, and guarantees every series is reached.
 *
 * @since 26.0
 */
final class Horizon {

	/**
	 * Cron hook.
	 */
	const HOOK = 'qevm_extend_recurrence_horizon';

	/**
	 * Option holding how far through the list the last run reached.
	 *
	 * A position in the list, not an event id. An id cursor would need a
	 * "WHERE ID > n" that WP_Query cannot express without a `posts_where` filter,
	 * and filtering the ids in PHP *after* the query has applied its LIMIT is
	 * worse than either: the first batch would be filtered down to nothing and
	 * the walk would never advance past it.
	 */
	const CURSOR = 'qevm_horizon_cursor';

	/**
	 * Events regenerated per run.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Seconds of work to attempt in one run.
	 */
	const TIME_BUDGET = 10;

	/**
	 * Hook the task up.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_on_cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Run from cron, discarding the counts.
	 *
	 * A separate method because an action callback that returns a value is a
	 * callback WordPress ignores and static analysis objects to. The counts are
	 * for tests and for anything that calls run() deliberately.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function run_on_cron() {
		self::run();
	}

	/**
	 * Keep the daily tick on the schedule.
	 *
	 * Daily rather than hourly. The horizon is two years out, so a day's drift
	 * is not something anybody can perceive, and a task that walks every
	 * recurring event on a site has no business running more often than the
	 * problem it solves.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Take the task off the schedule.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function unschedule() {
		$scheduled = wp_next_scheduled( self::HOOK );

		while ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::HOOK );

			$scheduled = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Regenerate the next batch of recurring events.
	 *
	 * @since 26.0
	 *
	 * @param int $budget Seconds to spend. Defaults to TIME_BUDGET.
	 * @return array{visited: int, added: int}
	 */
	public static function run( $budget = 0 ) {
		$deadline = microtime( true ) + ( $budget > 0 ? (int) $budget : self::TIME_BUDGET );
		$offset   = max( 0, (int) get_option( self::CURSOR, 0 ) );
		$ids      = self::recurring_event_ids( $offset, self::BATCH_SIZE );

		if ( array() === $ids ) {
			/*
			 * Either there are no recurring events, or the list is shorter than it
			 * was when the cursor was written. Back to the start; the next run
			 * picks up from the first event rather than sitting past the end and
			 * never doing anything again.
			 */
			update_option( self::CURSOR, 0, false );

			return array(
				'visited' => 0,
				'added'   => 0,
			);
		}

		$visited = 0;
		$added   = 0;

		foreach ( $ids as $event_id ) {
			$result = OccurrenceSync::sync( (int) $event_id );

			$added += (int) $result['inserted'];

			++$visited;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		/*
		 * A short batch means the end of the list, so the cursor wraps. Otherwise
		 * it advances by what was actually done rather than by the batch size — a
		 * run cut short by the budget must resume where it stopped, not skip the
		 * events it never reached.
		 */
		$found   = count( $ids );
		$wrapped = $found < self::BATCH_SIZE && $found === $visited;

		update_option( self::CURSOR, $wrapped ? 0 : $offset + $visited, false );

		return array(
			'visited' => $visited,
			'added'   => $added,
		);
	}

	/**
	 * A page of recurring event ids, oldest first.
	 *
	 * @since 26.0
	 *
	 * @param int $offset How many to skip.
	 * @param int $limit  How many to take.
	 * @return int[]
	 */
	public static function recurring_event_ids( $offset, $limit ) {
		$query = new \WP_Query(
			array(
				'post_type'              => QEVM_POST_TYPE,
				'post_status'            => array( 'publish', 'future', 'draft', 'private' ),
				'posts_per_page'         => max( 1, (int) $limit ),
				'offset'                 => max( 0, (int) $offset ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Selecting the recurring events *is* this task. The alternative is reading every event and discarding most of them, which is slower and reads the whole post table.
				'meta_query'             => array(
					array(
						'key'     => Meta::RECURRENCE_RULE,
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}
}
