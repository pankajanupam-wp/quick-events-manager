<?php
/**
 * What the calendar costs, in queries.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Calendar\CalendarModule;
use QuickEventsManager\Calendar\Month;

/**
 * A month must not cost more queries because it has more events in it.
 *
 * Found by the stage 10 benchmark, which measured **471 queries and 70ms** for a
 * single month view on a site with ten thousand events. The grid builds an
 * `Event` per occupied day, and one at a time that is a post read and a meta
 * read each — invisible on the ten-event site anybody develops against, and the
 * whole page on a real one.
 *
 * The assertion is a ceiling rather than an exact number, deliberately. Pinning
 * the exact count makes every unrelated change a failing test; what matters is
 * that the number does not follow the row count.
 */
final class CalendarQueryCountTest extends TestCase {

	/**
	 * Calendar on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->switch_module_on( CalendarModule::ID );
	}

	/**
	 * Twenty events in a month cost no more queries than two do.
	 *
	 * @return void
	 */
	public function test_a_busy_month_costs_no_more_queries_than_a_quiet_one() {
		$quiet = $this->cost_of_a_month_with( 2 );
		$busy  = $this->cost_of_a_month_with( 20 );

		$this->assertGreaterThan( 0, $quiet, 'the month was actually read' );

		$this->assertLessThanOrEqual(
			$quiet + 2,
			$busy,
			'ten times the events should not mean ten times the queries'
		);
	}

	/**
	 * And the month it read was not empty.
	 *
	 * A query count over nothing is a measurement of nothing — the mistake the
	 * benchmark made before it printed row counts.
	 *
	 * @return void
	 */
	public function test_the_month_being_measured_has_events_in_it() {
		$this->events_in_this_month( 5 );

		$month = $this->month_holding_the_fixtures();

		$this->assertGreaterThanOrEqual( 5, $month->event_count() );
	}

	/**
	 * Queries taken to read one month holding this many events.
	 *
	 * @param int $events How many events to put in the month.
	 * @return int
	 */
	private function cost_of_a_month_with( int $events ): int {
		global $wpdb;

		$this->empty_plugin_tables();

		$this->events_in_this_month( $events );

		wp_cache_flush();

		$month = $this->month_holding_the_fixtures();

		$before = $wpdb->num_queries;

		foreach ( $month->weeks() as $week ) {
			foreach ( $week as $cell ) {
				if ( null === $cell ) {
					continue;
				}

				foreach ( $cell['events'] as $occurrence ) {
					/*
					 * What the template does with each one: builds the event and
					 * asks it for the two things the grid prints. That is where
					 * the reads happen, so that is what has to be counted.
					 */
					$event = new \QuickEventsManager\Events\Event( $occurrence->event_id() );

					$event->format_start();
					get_the_title( $event->id() );
				}
			}
		}

		return $wpdb->num_queries - $before;
	}

	/**
	 * The month the fixtures land in.
	 *
	 * @return Month
	 */
	private function month_holding_the_fixtures(): Month {
		$when = time() + DAY_IN_SECONDS;

		return new Month( (int) gmdate( 'Y', $when ), (int) gmdate( 'n', $when ) );
	}

	/**
	 * Put some dated events into the month just ahead.
	 *
	 * @param int $count How many.
	 * @return void
	 */
	private function events_in_this_month( int $count ) {
		/*
		 * Spread over the next few days rather than pinned to dates in this
		 * calendar month: `make_event()` takes an offset from now, and on the
		 * 30th "this month" and "in three days" are different months. The month
		 * being measured is whichever one holds them.
		 */
		for ( $i = 0; $i < $count; $i++ ) {
			$this->make_event(
				array(
					'starts_in' => DAY_IN_SECONDS + ( $i * HOUR_IN_SECONDS ),
					'title'     => 'Calendar cost ' . $i,
				)
			);
		}
	}
}
