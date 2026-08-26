<?php
/**
 * Letting go of seats nobody paid for.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * The sweep that makes a hold a hold rather than a reservation for ever.
 *
 * **This is the step most implementations forget**, and the symptom is an event
 * that sells out at eighty per cent because every abandoned checkout is still
 * holding a seat that nobody will ever pay for. There is no reliable "the
 * customer closed the tab" event to listen for — that is the whole difficulty —
 * so the only workable answer is a moment written down at the start and a job
 * that comes back to it.
 *
 * Five minutes, matching the mail queue rather than inventing a second interval.
 * A hold is twenty minutes by default, so the worst case is a seat coming back
 * five minutes late, which nobody notices; running every minute to fix that
 * would cost every site on the internet a cron tick a minute for it.
 *
 * @since 26.0
 */
final class Holds {

	/**
	 * Cron hook.
	 */
	const HOOK = 'qevm_release_expired_holds';

	/**
	 * How many to release in one run.
	 *
	 * Bounded because each one cancels a booking, which promotes a waiting
	 * list, which sends email. A thousand expiring at once is a queue to work
	 * through over several ticks, not one request to try to do it all in.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Put the sweep on the schedule.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'qevm_five_minutes', self::HOOK );
		}
	}

	/**
	 * Take it off again.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function unschedule() {
		$next = wp_next_scheduled( self::HOOK );

		while ( $next ) {
			wp_unschedule_event( $next, self::HOOK );

			$next = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * What cron calls.
	 *
	 * A wrapper that returns nothing, because an action callback that returns a
	 * value is a callback whose value nobody reads — and `run()` has one worth
	 * reading, for the code that calls it directly.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function sweep() {
		self::run();
	}

	/**
	 * Release everything whose hold has run out.
	 *
	 * @since 26.0
	 *
	 * @return int How many were released.
	 */
	public static function run(): int {
		$released = 0;

		foreach ( OrderRepository::expired_holds( '', self::BATCH_SIZE ) as $order ) {
			if ( OrderService::abandon( $order->id() ) ) {
				++$released;
			}
		}

		if ( $released > 0 ) {
			/**
			 * Fires after a sweep has let go of some seats.
			 *
			 * @since 26.0
			 *
			 * @param int $released How many orders were abandoned.
			 */
			do_action( 'qevm_holds_released', $released );
		}

		return $released;
	}
}
