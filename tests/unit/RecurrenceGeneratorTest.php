<?php
/**
 * Turning a rule into dates.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Recurrence\Exclusions;
use QuickEventsManager\Recurrence\Generator;
use QuickEventsManager\Recurrence\LocalTime;
use QuickEventsManager\Recurrence\Rule;

/**
 * Generation, bounded, and correct across a clock change.
 *
 * Timezone fixtures use `America/New_York` on real transition dates rather than
 * a convenient offset. The plugin's earlier timezone tests learned this the hard
 * way: a fixture in `Asia/Kolkata` at 23:30 proved nothing, because it was still
 * the same date in UTC.
 */
#[CoversClass( Generator::class )]
#[CoversClass( LocalTime::class )]
#[CoversClass( Exclusions::class )]
final class RecurrenceGeneratorTest extends TestCase {

	/**
	 * Reset the stubbed WordPress between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		\WP_Stub_State::reset();
	}

	/**
	 * A weekly series for 52 weeks generates 52 occurrences.
	 *
	 * Straight from the Stage 6 gate.
	 *
	 * @return void
	 */
	public function test_a_year_of_weekly_generates_fifty_two_dates() {
		$rows = $this->expand( 'FREQ=WEEKLY;COUNT=52' );

		$this->assertCount( 52, $rows );

		// And they really are a week apart, not 52 copies of the same date.
		$this->assertCount( 52, array_unique( array_column( $rows, 'start_utc' ) ) );

		/*
		 * Stepped in local dates rather than in UTC seconds. Two consecutive
		 * occurrences either side of a clock change are seven days apart on the
		 * calendar and six days twenty-three hours apart in UTC — which is the
		 * whole point of generating in wall-clock time, and which an earlier
		 * version of this assertion got wrong by measuring the UTC gap.
		 */
		$previous = null;

		foreach ( $rows as $row ) {
			$date = substr( $row['start_local'], 0, 10 );

			if ( null !== $previous ) {
				$this->assertSame(
					7 * DAY_IN_SECONDS,
					strtotime( $date . ' 12:00:00 UTC' ) - strtotime( $previous . ' 12:00:00 UTC' ),
					$previous . ' to ' . $date . ' is not a week'
				);
			}

			$previous = $date;
		}
	}

	/**
	 * 18:00 stays 18:00 across a real daylight saving transition.
	 *
	 * The one the chunk definition names. In `America/New_York` the clocks go
	 * forward on 8 March 2026, so a Tuesday-evening series either side of it
	 * keeps its local time and changes its UTC one — and generating in UTC would
	 * do the opposite, moving the meeting an hour for half the year.
	 *
	 * @return void
	 */
	public function test_a_local_time_survives_a_daylight_saving_change() {
		$rows = $this->expand( 'FREQ=WEEKLY;COUNT=3', '2026-03-03 18:00:00', '2026-03-03 19:00:00' );

		$this->assertCount( 3, $rows );

		foreach ( $rows as $row ) {
			$this->assertSame( '18:00:00', substr( $row['start_local'], 11 ), 'the wall-clock time moved' );
		}

		/*
		 * The fixture is only meaningful if it actually spans the transition. An
		 * assertion that the UTC times differ is what proves this test is looking
		 * at a clock change rather than at three ordinary weeks.
		 */
		$this->assertSame( '2026-03-03 23:00:00', $rows[0]['start_utc'] );
		$this->assertSame( '2026-03-10 22:00:00', $rows[1]['start_utc'] );
		$this->assertNotSame(
			substr( $rows[0]['start_utc'], 11 ),
			substr( $rows[1]['start_utc'], 11 ),
			'the fixture does not span a transition, so it proves nothing'
		);
	}

	/**
	 * A wall-clock time that does not exist resolves to the instant clocks jumped to.
	 *
	 * PHP's own answer is different — it shifts the requested time by the offset,
	 * giving 03:30 — so this rule is implemented rather than inherited. See
	 * docs/recurrence.md §5.2.
	 *
	 * @return void
	 */
	public function test_a_time_that_does_not_exist_resolves_forward_to_the_transition() {
		$zone = new \DateTimeZone( 'America/New_York' );

		$this->assertFalse( LocalTime::exists( '2026-03-08 02:30:00', $zone ), 'the fixture time exists after all' );

		$resolved = LocalTime::resolve( '2026-03-08 02:30:00', $zone );

		$this->assertNotNull( $resolved );
		$this->assertSame( '2026-03-08 03:00:00', $resolved->format( 'Y-m-d H:i:s' ) );

		// What PHP would have said unaided, recorded so the difference stays visible.
		$this->assertSame(
			'2026-03-08 03:30:00',
			( new \DateTimeImmutable( '2026-03-08 02:30:00', $zone ) )->format( 'Y-m-d H:i:s' ),
			"PHP's own resolution changed; docs/recurrence.md §5.2 needs revisiting"
		);
	}

	/**
	 * A wall-clock time that happens twice resolves to the first of them.
	 *
	 * PHP already does this, so the behaviour is inherited — and pinned here,
	 * because inheriting behaviour is only safe if you notice it changing.
	 *
	 * @return void
	 */
	public function test_a_time_that_happens_twice_resolves_to_the_earlier() {
		$zone = new \DateTimeZone( 'America/New_York' );

		$this->assertTrue( LocalTime::is_ambiguous( '2026-11-01 01:30:00', $zone ), 'the fixture time is not ambiguous' );

		$resolved = LocalTime::resolve( '2026-11-01 01:30:00', $zone );

		$this->assertNotNull( $resolved );
		$this->assertSame( '2026-11-01 01:30:00', $resolved->format( 'Y-m-d H:i:s' ) );

		// -04:00 is the offset before the change; -05:00 is after it.
		$this->assertSame( '-04:00', $resolved->format( 'P' ), 'the later of the two instants was chosen' );
	}

	/**
	 * An ordinary time is neither missing nor doubled.
	 *
	 * The control. Without it, an is_ambiguous() that always returned true would
	 * pass the test above.
	 *
	 * @return void
	 */
	public function test_an_ordinary_time_is_unambiguous() {
		$zone = new \DateTimeZone( 'America/New_York' );

		$this->assertTrue( LocalTime::exists( '2026-06-15 18:00:00', $zone ) );
		$this->assertFalse( LocalTime::is_ambiguous( '2026-06-15 18:00:00', $zone ) );
	}

	/**
	 * A named weekday earlier in the first week is not generated.
	 *
	 * A series that begins on a Wednesday and repeats on Mondays and Thursdays
	 * must not produce the Monday before it started.
	 *
	 * @return void
	 */
	public function test_the_series_does_not_start_before_it_starts() {
		// 2026-03-04 is a Wednesday.
		$rows = $this->expand( 'FREQ=WEEKLY;BYDAY=MO,TH;COUNT=3', '2026-03-04 18:00:00', '2026-03-04 19:00:00' );

		$this->assertSame( 'Wed', gmdate( 'D', strtotime( '2026-03-04 12:00:00 UTC' ) ), 'the fixture date is not a Wednesday' );

		$dates = array_map( static fn ( $row ) => substr( $row['start_local'], 0, 10 ), $rows );

		$this->assertSame( array( '2026-03-05', '2026-03-09', '2026-03-12' ), $dates );
	}

	/**
	 * Two weekdays a week produce two dates a week.
	 *
	 * @return void
	 */
	public function test_two_weekdays_produce_two_dates_a_week() {
		$rows  = $this->expand( 'FREQ=WEEKLY;BYDAY=MO,TH;COUNT=4', '2026-03-02 18:00:00', '2026-03-02 19:00:00' );
		$dates = array_map( static fn ( $row ) => substr( $row['start_local'], 0, 10 ), $rows );

		$this->assertSame( array( '2026-03-02', '2026-03-05', '2026-03-09', '2026-03-12' ), $dates );
	}

	/**
	 * A fortnightly rule skips the weeks between.
	 *
	 * @return void
	 */
	public function test_an_interval_skips_weeks() {
		$rows  = $this->expand( 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;COUNT=3', '2026-03-02 18:00:00', '2026-03-02 19:00:00' );
		$dates = array_map( static fn ( $row ) => substr( $row['start_local'], 0, 10 ), $rows );

		$this->assertSame( array( '2026-03-02', '2026-03-16', '2026-03-30' ), $dates );
	}

	/**
	 * A monthly rule does not walk forward through the calendar.
	 *
	 * `'+1 month'` from the 31st of January lands on the 3rd of March, and a
	 * monthly series that drifts a few days each month is the classic way this
	 * goes wrong. Stepping is anchored to the first of the month instead.
	 *
	 * @return void
	 */
	public function test_a_monthly_rule_does_not_drift() {
		$rows  = $this->expand( 'FREQ=MONTHLY;COUNT=4', '2026-01-31 18:00:00', '2026-01-31 19:00:00' );
		$dates = array_map( static fn ( $row ) => substr( $row['start_local'], 0, 10 ), $rows );

		/*
		 * February, April and June have no 31st, so they contribute nothing —
		 * RFC 5545 skips an invalid date rather than clamping it. Clamping would
		 * put a "monthly on the 31st" event on the 28th of February, three days
		 * early, for a date nobody named.
		 */
		$this->assertSame( array( '2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31' ), $dates );
	}

	/**
	 * "The last Friday of the month" lands on the last Friday.
	 *
	 * @return void
	 */
	public function test_the_last_weekday_of_a_month() {
		$rows  = $this->expand( 'FREQ=MONTHLY;BYDAY=-1FR;COUNT=3', '2026-01-30 18:00:00', '2026-01-30 19:00:00' );
		$dates = array_map( static fn ( $row ) => substr( $row['start_local'], 0, 10 ), $rows );

		$this->assertSame( array( '2026-01-30', '2026-02-27', '2026-03-27' ), $dates );

		foreach ( $dates as $date ) {
			$this->assertSame( 'Fri', gmdate( 'D', strtotime( $date . ' 12:00:00 UTC' ) ), $date . ' is not a Friday' );
		}
	}

	/**
	 * "The second Tuesday of the month" lands on the second Tuesday.
	 *
	 * @return void
	 */
	public function test_the_second_weekday_of_a_month() {
		$rows  = $this->expand( 'FREQ=MONTHLY;BYDAY=2TU;COUNT=3', '2026-01-13 18:00:00', '2026-01-13 19:00:00' );
		$dates = array_map( static fn ( $row ) => substr( $row['start_local'], 0, 10 ), $rows );

		$this->assertSame( array( '2026-01-13', '2026-02-10', '2026-03-10' ), $dates );
	}

	/**
	 * A yearly rule repeats on the same date, as far as the horizon allows.
	 *
	 * Also the clearest demonstration that the horizon bounds a rule that has its
	 * own ending: `COUNT=3` asks for three, and only two are inside two years, so
	 * two is what exists until time moves on. The third is not lost — it appears
	 * when the horizon reaches it, which is what the rolling extension in
	 * Horizon::run() is for.
	 *
	 * @return void
	 */
	public function test_a_yearly_rule_keeps_its_date_within_the_horizon() {
		$dates = array_map(
			static fn ( $row ) => substr( $row['start_local'], 0, 10 ),
			$this->expand( 'FREQ=YEARLY;COUNT=3', '2026-06-15 18:00:00', '2026-06-15 19:00:00' )
		);

		$this->assertSame( array( '2026-06-15', '2027-06-15' ), $dates );

		// A year later, the third is inside the horizon and appears.
		$later = array_map(
			static fn ( $row ) => substr( $row['start_local'], 0, 10 ),
			Generator::expand(
				Rule::from_string( 'FREQ=YEARLY;COUNT=3' ),
				array(
					'event_id'    => 1,
					'series_uuid' => 'series-uuid',
					'start_local' => '2026-06-15 18:00:00',
					'end_local'   => '2026-06-15 19:00:00',
					'timezone'    => 'America/New_York',
					'all_day'     => false,
				),
				array(),
				'2027-03-01 00:00:00'
			)
		);

		$this->assertSame( array( '2026-06-15', '2027-06-15', '2028-06-15' ), $later );
	}

	/**
	 * An excluded date is skipped, and does not use up one of the count.
	 *
	 * @return void
	 */
	public function test_an_excluded_date_is_skipped_without_shortening_the_series() {
		$rows = $this->expand(
			'FREQ=WEEKLY;COUNT=3',
			'2026-03-03 18:00:00',
			'2026-03-03 19:00:00',
			array( '2026-03-10' )
		);

		$dates = array_map( static fn ( $row ) => substr( $row['start_local'], 0, 10 ), $rows );

		$this->assertSame( array( '2026-03-03', '2026-03-17', '2026-03-24' ), $dates );
		$this->assertCount( 3, $rows, 'the exclusion used up one of the three dates asked for' );
	}

	/**
	 * Generation stops at the horizon.
	 *
	 * @return void
	 */
	public function test_an_endless_rule_stops_at_the_horizon() {
		$rows = $this->expand( 'FREQ=WEEKLY', '2026-03-03 18:00:00', '2026-03-03 19:00:00' );

		$this->assertNotSame( array(), $rows );
		$this->assertLessThanOrEqual( Rule::MAX_OCCURRENCES, count( $rows ) );

		$last    = end( $rows );
		$horizon = Generator::horizon( '2026-03-01 00:00:00' );

		$this->assertLessThanOrEqual( $horizon, $last['start_utc'] );

		// Two years of weeks, give or take the week the horizon lands in.
		$this->assertGreaterThan( 100, count( $rows ) );
		$this->assertLessThan( 110, count( $rows ) );
	}

	/**
	 * A daily rule with no end is capped rather than left to run.
	 *
	 * @return void
	 */
	public function test_a_daily_rule_is_capped() {
		$rows = $this->expand( 'FREQ=DAILY', '2026-03-03 18:00:00', '2026-03-03 19:00:00' );

		$this->assertLessThanOrEqual( Rule::MAX_OCCURRENCES, count( $rows ) );
		$this->assertGreaterThan( 700, count( $rows ), 'a daily rule should reach nearly the ceiling in two years' );
	}

	/**
	 * Every generated row records the slot it came from.
	 *
	 * @return void
	 */
	public function test_every_row_records_its_slot() {
		$rows = $this->expand( 'FREQ=WEEKLY;COUNT=5' );

		foreach ( $rows as $row ) {
			$this->assertSame( $row['start_utc'], $row['recurrence_id'], 'a generated row has no slot' );
			$this->assertSame( 'series-uuid', $row['series_uuid'] );
			$this->assertSame( 0, $row['is_exception'] );
		}

		$slots = array_column( $rows, 'recurrence_id' );

		$this->assertCount( 5, array_unique( $slots ), 'two rows share a slot' );
	}

	/**
	 * The event's duration is carried to every date.
	 *
	 * @return void
	 */
	public function test_every_date_lasts_as_long_as_the_first() {
		$rows = $this->expand( 'FREQ=WEEKLY;COUNT=4', '2026-03-03 18:00:00', '2026-03-03 19:30:00' );

		foreach ( $rows as $row ) {
			$this->assertSame(
				90 * MINUTE_IN_SECONDS,
				strtotime( $row['end_utc'] . ' UTC' ) - strtotime( $row['start_utc'] . ' UTC' ),
				'an occurrence changed length'
			);
		}
	}

	/**
	 * A rule that generates nothing usable returns nothing.
	 *
	 * @return void
	 */
	public function test_a_rule_with_no_start_generates_nothing() {
		$rows = Generator::expand(
			Rule::from_string( 'FREQ=WEEKLY;COUNT=5' ),
			array(
				'event_id'    => 1,
				'start_local' => '',
				'timezone'    => 'UTC',
			)
		);

		$this->assertSame( array(), $rows );
	}

	/**
	 * An unknown timezone generates nothing rather than guessing.
	 *
	 * @return void
	 */
	public function test_an_unusable_timezone_generates_nothing() {
		$rows = Generator::expand(
			Rule::from_string( 'FREQ=WEEKLY;COUNT=5' ),
			array(
				'event_id'    => 1,
				'start_local' => '2026-03-03 18:00:00',
				'end_local'   => '2026-03-03 19:00:00',
				'timezone'    => 'Mars/Olympus_Mons',
			)
		);

		$this->assertSame( array(), $rows );
	}

	/**
	 * Only real calendar dates can be excluded.
	 *
	 * `strtotime( '2026-02-30' )` is the 2nd of March, so a list read with it
	 * would exclude a date nobody named.
	 *
	 * @return void
	 */
	public function test_exclusions_are_checked_against_the_calendar() {
		$this->assertSame( '2026-12-25', Exclusions::normalise( '2026-12-25' ) );
		$this->assertSame( '', Exclusions::normalise( '2026-02-30' ) );
		$this->assertSame( '', Exclusions::normalise( '2026-13-01' ) );
		$this->assertSame( '', Exclusions::normalise( '25/12/2026' ) );
		$this->assertSame( '', Exclusions::normalise( 'next tuesday' ) );

		// A leap day in a leap year is real; in a common year it is not.
		$this->assertSame( '2028-02-29', Exclusions::normalise( '2028-02-29' ) );
		$this->assertSame( '', Exclusions::normalise( '2026-02-29' ) );
	}

	/**
	 * An exclusion list is sorted, deduplicated and bounded.
	 *
	 * @return void
	 */
	public function test_an_exclusion_list_is_tidied() {
		$parsed = Exclusions::parse( '2026-12-25, 2026-01-01,2026-12-25 , rubbish, 2026-06-15' );

		$this->assertSame( array( '2026-01-01', '2026-06-15', '2026-12-25' ), $parsed );

		$many = array();

		for ( $day = 1; $day <= 300; $day++ ) {
			$many[] = gmdate( 'Y-m-d', strtotime( '2026-01-01 +' . $day . ' days' ) );
		}

		$this->assertCount( Exclusions::MAX, Exclusions::parse( implode( ',', $many ) ) );
	}

	/**
	 * Expand a rule against a standard fixture.
	 *
	 * @param string   $rrule   The rule.
	 * @param string   $start   Local start.
	 * @param string   $end     Local end.
	 * @param string[] $exclude Excluded dates.
	 * @return array<int, array<string, mixed>>
	 */
	private function expand( $rrule, $start = '2026-03-03 18:00:00', $end = '2026-03-03 19:00:00', array $exclude = array() ) {
		$rule = Rule::from_string( $rrule );

		$this->assertNotNull( $rule, $rrule . ' did not parse' );

		return Generator::expand(
			$rule,
			array(
				'event_id'    => 1,
				'series_uuid' => 'series-uuid',
				'start_local' => $start,
				'end_local'   => $end,
				'timezone'    => 'America/New_York',
				'all_day'     => false,
			),
			$exclude,
			'2026-03-01 00:00:00'
		);
	}
}
