<?php
/**
 * The month grid's arithmetic and its markup.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Calendar\CalendarModule;
use QuickEventsManager\Calendar\Month;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Frontend\Renderer;
use QuickEventsManager\Registration\RegistrationModule;

/**
 * Dates, boundaries and the table they end up in.
 *
 * Calendar bugs are almost all off-by-one: the wrong first weekday, a month
 * that loses its last day, an event that lands a day early because somebody
 * converted a timezone that did not need converting. Most of these tests are
 * those cases rather than the happy one.
 */
final class CalendarMonthTest extends TestCase {

	/**
	 * Put the week start back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		update_option( 'start_of_week', 1 );

		parent::tearDown();
	}

	/**
	 * Every month is a whole number of weeks, and keeps all its days.
	 *
	 * @return void
	 */
	public function test_a_month_is_whole_weeks_and_loses_no_days() {
		foreach ( array( array( 2026, 2 ), array( 2024, 2 ), array( 2026, 8 ), array( 2026, 11 ) ) as $pair ) {
			$month = new Month( $pair[0], $pair[1] );
			$weeks = $month->weeks();
			$days  = array();

			foreach ( $weeks as $week ) {
				$this->assertCount( 7, $week, 'a week did not have seven cells' );

				foreach ( $week as $cell ) {
					if ( null !== $cell ) {
						$days[] = $cell['day'];
					}
				}
			}

			$this->assertSame(
				range( 1, $month->length() ),
				$days,
				sprintf( '%04d-%02d did not render exactly its own days in order', $pair[0], $pair[1] )
			);
		}
	}

	/**
	 * February 2024 is a leap year and has 29 days.
	 *
	 * @return void
	 */
	public function test_a_leap_year_february_has_twenty_nine_days() {
		$this->assertSame( 29, ( new Month( 2024, 2 ) )->length() );
		$this->assertSame( 28, ( new Month( 2026, 2 ) )->length() );
	}

	/**
	 * The grid starts on the day the site starts its week.
	 *
	 * @return void
	 */
	public function test_the_grid_respects_the_sites_first_day_of_the_week() {
		update_option( 'start_of_week', 0 );

		$sunday_start = ( new Month( 2026, 8 ) )->weeks();

		update_option( 'start_of_week', 1 );

		$monday_start = ( new Month( 2026, 8 ) )->weeks();

		$this->assertNotSame(
			$this->leading_blanks( $sunday_start ),
			$this->leading_blanks( $monday_start ),
			'changing the first day of the week did not move the grid'
		);
	}

	/**
	 * Stepping back from January lands in the previous December.
	 *
	 * @return void
	 */
	public function test_month_arithmetic_crosses_the_year() {
		$january = new Month( 2026, 1 );

		$this->assertSame( '2025-12', $january->previous()->key() );
		$this->assertSame( '2026-02', $january->next()->key() );

		$december = new Month( 2026, 12 );

		$this->assertSame( '2027-01', $december->next()->key() );
	}

	/**
	 * A nonsense month falls back to the current one rather than erroring.
	 *
	 * @return void
	 */
	public function test_an_unparseable_month_falls_back_to_now() {
		$now = Month::current();

		$this->assertSame( $now->key(), Month::from_string( 'not-a-month' )->key() );
		$this->assertSame( $now->key(), Month::from_string( '2026-13' )->key() );
		$this->assertSame( '2026-03', Month::from_string( '2026-03' )->key() );
	}

	/**
	 * An event lands on the date the organiser typed.
	 *
	 * @return void
	 */
	public function test_an_event_lands_on_its_own_local_date() {
		$this->enable_calendar();

		$event_id = $this->event_on( '2026-09-15 18:00:00', '2026-09-15 20:00:00' );

		$days = ( new Month( 2026, 9 ) )->occurrences_by_day();

		$this->assertArrayHasKey( '2026-09-15', $days );
		$this->assertCount( 1, $days['2026-09-15'] );
		$this->assertSame( $event_id, $days['2026-09-15'][0]->event_id() );
	}

	/**
	 * A late-evening event does not slide onto the following day.
	 *
	 * The bug this guards against is deciding which cell an event belongs in
	 * from its stored UTC instant rather than from the time the organiser typed.
	 *
	 * The timezone matters, and the first version of this test got it wrong.
	 * 23:30 in Kolkata is 18:00 UTC — the same date either way — so bucketing by
	 * UTC passed it and the test proved nothing. New York is behind UTC, so
	 * 23:30 there is 03:30 the next morning in UTC and the two answers finally
	 * disagree. The second assertion is there so the fixture cannot quietly stop
	 * spanning a UTC date boundary and take the test's meaning with it.
	 *
	 * @return void
	 */
	public function test_a_late_event_stays_on_its_own_day() {
		$this->enable_calendar();

		$this->event_on( '2026-09-15 23:30:00', '2026-09-16 00:30:00', 'America/New_York' );

		$days = ( new Month( 2026, 9 ) )->occurrences_by_day();

		$this->assertArrayHasKey( '2026-09-15', $days, 'a late event moved off its own date' );

		$this->assertSame(
			'2026-09-16 03:30:00',
			$days['2026-09-15'][0]->start_utc(),
			'the fixture no longer spans a UTC date boundary, so this test proves nothing'
		);
	}

	/**
	 * An event running over several days appears on each of them.
	 *
	 * @return void
	 */
	public function test_a_multi_day_event_appears_on_every_day_it_runs() {
		$this->enable_calendar();

		$this->event_on( '2026-09-10 09:00:00', '2026-09-12 17:00:00' );

		$days = ( new Month( 2026, 9 ) )->occurrences_by_day();

		foreach ( array( '2026-09-10', '2026-09-11', '2026-09-12' ) as $date ) {
			$this->assertArrayHasKey( $date, $days, $date . ' is missing a day of a multi-day event' );
		}

		$this->assertArrayNotHasKey( '2026-09-13', $days );
		$this->assertArrayNotHasKey( '2026-09-09', $days );
	}

	/**
	 * An event straddling a month boundary appears in both months.
	 *
	 * @return void
	 */
	public function test_an_event_across_a_month_boundary_appears_in_both() {
		$this->enable_calendar();

		$this->event_on( '2026-09-30 09:00:00', '2026-10-01 17:00:00' );

		$september = ( new Month( 2026, 9 ) )->occurrences_by_day();
		$october   = ( new Month( 2026, 10 ) )->occurrences_by_day();

		$this->assertArrayHasKey( '2026-09-30', $september );
		$this->assertArrayHasKey( '2026-10-01', $october );

		$this->assertArrayNotHasKey( '2026-10-01', $september, 'a day outside the month was bucketed into it' );
	}

	/**
	 * The grid renders as a real table with column headers.
	 *
	 * The markup half of the criterion the whole accessibility standard rests
	 * on. A grid built from divs looks identical and is unreadable.
	 *
	 * @return void
	 */
	public function test_the_grid_renders_as_a_table_with_headers() {
		$this->enable_calendar();

		$this->event_on( '2026-09-15 18:00:00', '2026-09-15 20:00:00', 'UTC', 'Autumn meetup' );

		$markup = Renderer::calendar( array( 'month' => '2026-09' ) );

		$this->assertStringContainsString( '<table', $markup );
		$this->assertStringContainsString( '<caption', $markup );
		$this->assertSame( 7, substr_count( $markup, 'scope="col"' ), 'the grid does not have seven column headers' );
		$this->assertStringContainsString( 'Autumn meetup', $markup );
		$this->assertStringContainsString( 'September 2026', $markup );
	}

	/**
	 * Nothing renders while the module is off.
	 *
	 * @return void
	 */
	public function test_nothing_renders_with_the_module_off() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		$this->assertSame( '', Renderer::calendar( array( 'month' => '2026-09' ) ) );
	}

	/**
	 * Drawing a month costs one query for its events, not one per day.
	 *
	 * @return void
	 */
	public function test_a_month_is_one_query_for_its_events() {
		$this->enable_calendar();

		for ( $day = 1; $day <= 10; $day++ ) {
			$this->event_on( sprintf( '2026-09-%02d 18:00:00', $day ), sprintf( '2026-09-%02d 20:00:00', $day ) );
		}

		$month = new Month( 2026, 9 );

		$before = get_num_queries();

		$month->occurrences_by_day();

		$this->assertSame( 1, get_num_queries() - $before );
	}

	/**
	 * The list holds exactly the events the grid does.
	 *
	 * The Stage 4 gate. Both views read the same month object, so this is true
	 * by construction rather than by two queries kept in step — and this test is
	 * what says the construction has not been quietly undone.
	 *
	 * @return void
	 */
	public function test_the_list_shows_the_same_events_as_the_grid() {
		$this->enable_calendar();

		$this->event_on( '2026-09-01 09:00:00', '2026-09-01 10:00:00', 'UTC', 'First' );
		$this->event_on( '2026-09-15 18:00:00', '2026-09-15 20:00:00', 'UTC', 'Middle' );
		$this->event_on( '2026-09-30 09:00:00', '2026-09-30 10:00:00', 'UTC', 'Last' );
		$this->event_on( '2026-10-05 09:00:00', '2026-10-05 10:00:00', 'UTC', 'Next month' );

		$grid = Renderer::calendar(
			array(
				'month' => '2026-09',
				'view'  => 'grid',
			)
		);
		$list = Renderer::calendar(
			array(
				'month' => '2026-09',
				'view'  => 'list',
			)
		);

		foreach ( array( 'First', 'Middle', 'Last' ) as $title ) {
			$this->assertStringContainsString( $title, $grid, $title . ' is missing from the grid' );
			$this->assertStringContainsString( $title, $list, $title . ' is missing from the list' );
		}

		$this->assertStringNotContainsString( 'Next month', $grid );
		$this->assertStringNotContainsString( 'Next month', $list );

		// The list is a list, not a table with different styling.
		$this->assertStringNotContainsString( '<table', $list );
		$this->assertStringContainsString( '<table', $grid );
	}

	/**
	 * Each view links to the other, for the month being looked at.
	 *
	 * @return void
	 */
	public function test_each_view_links_to_the_other_for_the_same_month() {
		$this->enable_calendar();

		$grid = Renderer::calendar(
			array(
				'month' => '2026-09',
				'view'  => 'grid',
			)
		);
		$list = Renderer::calendar(
			array(
				'month' => '2026-09',
				'view'  => 'list',
			)
		);

		$this->assertStringContainsString( 'qevm_view=list', $grid );
		$this->assertStringContainsString( 'qevm_month=2026-09', $grid );

		$this->assertStringContainsString( 'qevm_view=grid', $list );
		$this->assertStringContainsString( 'qevm_month=2026-09', $list );
	}

	/**
	 * A site can make the list the default without touching a template.
	 *
	 * @return void
	 */
	public function test_a_filter_can_make_the_list_the_default() {
		$this->enable_calendar();

		$this->assertStringContainsString( '<table', Renderer::calendar( array( 'month' => '2026-09' ) ) );

		add_filter( 'qevm_calendar_default_view', static fn () => 'list' );

		$this->assertStringNotContainsString( '<table', Renderer::calendar( array( 'month' => '2026-09' ) ) );
	}

	/**
	 * An empty month says so rather than showing nothing.
	 *
	 * A grid can be empty and still look like a calendar. An empty list looks
	 * like something failed to load.
	 *
	 * @return void
	 */
	public function test_an_empty_month_says_so_in_the_list() {
		$this->enable_calendar();

		$list = Renderer::calendar(
			array(
				'month' => '2030-01',
				'view'  => 'list',
			)
		);

		$this->assertStringContainsString( 'no events', $list );
		$this->assertStringContainsString( 'January 2030', $list );
	}

	/**
	 * The shortcode and the block render through the same function.
	 *
	 * "One renderer" is the whole point of the chunk. Two implementations of a
	 * calendar is two sets of escaping decisions and two things to keep in step,
	 * and the editor preview would eventually stop matching the page.
	 *
	 * @return void
	 */
	public function test_the_block_and_the_shortcode_share_a_renderer() {
		$this->enable_calendar();

		$this->event_on( '2026-09-15 18:00:00', '2026-09-15 20:00:00', 'UTC', 'Shared renderer' );

		$mapped = \QuickEventsManager\Blocks\Blocks::BLOCKS['event-calendar'];

		$this->assertSame( 'calendar', $mapped, 'the block no longer points at the calendar renderer' );

		$from_shortcode = do_shortcode( '[qevm_event_calendar month="2026-09"]' );
		$from_renderer  = Renderer::calendar( array( 'month' => '2026-09' ) );

		$this->assertStringContainsString( 'Shared renderer', $from_shortcode );
		$this->assertSame( $from_renderer, $from_shortcode );
	}

	/**
	 * The calendar block is not offered while its module is off.
	 *
	 * A block that can be inserted, shows nothing, saves nothing and explains
	 * none of it reads as a broken block rather than as a feature that is
	 * switched off.
	 *
	 * @return void
	 */
	public function test_the_block_is_not_registered_with_the_module_off() {
		$this->assertArrayHasKey( 'event-calendar', \QuickEventsManager\Blocks\Blocks::MODULE_BLOCKS );

		$this->assertSame(
			CalendarModule::ID,
			\QuickEventsManager\Blocks\Blocks::MODULE_BLOCKS['event-calendar']
		);

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		/*
		 * Every plugin block comes out of the registry first. register_blocks()
		 * runs on `init`, so they are all registered by the time any test runs,
		 * and calling it again without clearing them makes WordPress complain
		 * about each one — noise that says nothing about the thing under test.
		 */
		$registry = \WP_Block_Type_Registry::get_instance();
		$names    = array();

		foreach ( array_keys( \QuickEventsManager\Blocks\Blocks::BLOCKS ) as $block ) {
			$names[] = 'qevm/' . $block;
		}

		foreach ( $names as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}

		try {
			( new \QuickEventsManager\Blocks\Blocks() )->register_blocks();

			$this->assertFalse(
				$registry->is_registered( 'qevm/event-calendar' ),
				'the calendar block was registered with its module switched off'
			);

			$this->assertTrue(
				$registry->is_registered( 'qevm/event-list' ),
				'gating one block on its module took a core block down with it'
			);
		} finally {
			/*
			 * Put the registry back. It is global and survives the transaction
			 * this test is wrapped in, so leaving the calendar block missing
			 * would break whatever ran next.
			 */
			foreach ( $names as $name ) {
				if ( $registry->is_registered( $name ) ) {
					$registry->unregister( $name );
				}
			}

			$this->enable_calendar();

			( new \QuickEventsManager\Blocks\Blocks() )->register_blocks();
		}
	}

	/**
	 * How many blank cells a grid starts with.
	 *
	 * @param array<int, array<int, mixed>> $weeks Weeks.
	 * @return int
	 */
	private function leading_blanks( array $weeks ) {
		$blanks = 0;

		foreach ( $weeks[0] as $cell ) {
			if ( null === $cell ) {
				++$blanks;
			}
		}

		return $blanks;
	}

	/**
	 * Switch the calendar module on.
	 *
	 * @return void
	 */
	private function enable_calendar() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CalendarModule::ID ) );
	}

	/**
	 * An event at a given local time, with its occurrence row.
	 *
	 * @param string $start    Local start, `Y-m-d H:i:s`.
	 * @param string $end      Local end, `Y-m-d H:i:s`.
	 * @param string $timezone Event timezone.
	 * @param string $title    Event title.
	 * @return int
	 */
	private function event_on( $start, $end, $timezone = 'UTC', $title = 'Calendar event' ) {
		$event_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
				'meta_input'  => array(
					Meta::START_LOCAL => $start,
					Meta::END_LOCAL   => $end,
					Meta::TIMEZONE    => $timezone,
					Meta::START_UTC   => Meta::to_utc( $start, $timezone ),
					Meta::END_UTC     => Meta::to_utc( $end, $timezone ),
				),
			)
		);

		$this->assertGreaterThan( 0, $event_id, 'the calendar event fixture could not be created' );

		return (int) $event_id;
	}
}
