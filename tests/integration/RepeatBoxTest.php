<?php
/**
 * Building a repeat rule from the event editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\MetaBox;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Recurrence\RepeatBox;
use QuickEventsManager\Recurrence\Series;

/**
 * The box turns fields into an RRULE, and an RRULE back into fields.
 *
 * Two things here are not about the markup at all. One is the save priority:
 * the rule is derived from the start date and read by the occurrence sync, so it
 * has to be written after the first and before the second, and both halves of
 * that are checked through a real save rather than by reading the number 15 out
 * of the source. The other is what happens to a rule that cannot be read, which
 * must be nothing at all — regenerating a series from a half-understood rule is
 * a large silent change to make on the strength of a typo.
 */
final class RepeatBoxTest extends TestCase {

	/**
	 * Switch recurrence on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option( QEVM_OPTION_MODULES, array( RecurrenceModule::ID ) );
		wp_set_current_user( $this->an_administrator() );
	}

	/**
	 * Weekly on two named days becomes the rule that says so.
	 *
	 * @return void
	 */
	public function test_weekly_on_named_days_is_stored_and_generated() {
		$event_id = $this->make_dated_event( '2026-06-02 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'WEEKLY',
				'qevm_repeat_interval' => '2',
				'qevm_repeat_weekday'  => array( 'TU', 'TH' ),
				'qevm_repeat_end'      => 'count',
				'qevm_repeat_count'    => '6',
			)
		);

		$this->assertSame( 'FREQ=WEEKLY;INTERVAL=2;COUNT=6;BYDAY=TU,TH', get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ) );

		\QuickEventsManager\Events\OccurrenceSync::sync( $event_id );

		$this->assertCount( 6, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * An end date is stored as the end of that day, in the event's own zone.
	 *
	 * Somebody typing "until the 31st" means the whole of the 31st. Storing
	 * midnight drops the last date of the series, and storing it as UTC drops it
	 * for anybody far enough east — which is the failure this fixture is set in
	 * Kolkata to catch.
	 *
	 * @return void
	 */
	public function test_an_end_date_covers_the_whole_of_that_day_in_the_events_zone() {
		$event_id = $this->make_dated_event( '2026-06-02 18:00:00', 'Asia/Kolkata' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'WEEKLY',
				'qevm_repeat_interval' => '1',
				'qevm_repeat_end'      => 'until',
				'qevm_repeat_until'    => '2026-12-31',
			)
		);

		$this->assertStringContainsString(
			'UNTIL=20261231T182959Z',
			get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ),
			'the end of the day in Kolkata is 18:29:59 UTC'
		);
	}

	/**
	 * "The last Wednesday" is worked out from the start date.
	 *
	 * @return void
	 */
	public function test_monthly_by_weekday_reads_the_position_from_the_start_date() {
		$event_id = $this->make_dated_event( '2026-06-24 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'             => '1',
				'qevm_repeat_freq'         => 'MONTHLY',
				'qevm_repeat_interval'     => '1',
				'qevm_repeat_monthly_mode' => 'weekday',
				'qevm_repeat_end'          => 'never',
			)
		);

		// The 24th of June 2026 is the last Wednesday of that month.
		$this->assertStringContainsString( 'BYDAY=-1WE', get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ) );
	}

	/**
	 * Monthly on a date carries no weekday at all.
	 *
	 * @return void
	 */
	public function test_monthly_by_date_stores_no_weekday() {
		$event_id = $this->make_dated_event( '2026-06-17 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'             => '1',
				'qevm_repeat_freq'         => 'MONTHLY',
				'qevm_repeat_interval'     => '1',
				'qevm_repeat_monthly_mode' => 'date',
				'qevm_repeat_end'          => 'never',
			)
		);

		$this->assertStringNotContainsString( 'BYDAY', get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ) );
	}

	/**
	 * Skipped dates arrive one a line and are stored as dates.
	 *
	 * @return void
	 */
	public function test_skipped_dates_are_read_a_line_at_a_time() {
		$event_id = $this->make_dated_event( '2026-06-02 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'           => '1',
				'qevm_repeat_freq'       => 'WEEKLY',
				'qevm_repeat_interval'   => '1',
				'qevm_repeat_end'        => 'never',
				'qevm_repeat_exclusions' => "2026-06-16\n2026-06-30\nnot a date\n2026-02-30",
			)
		);

		$this->assertSame(
			array( '2026-06-16', '2026-06-30' ),
			\QuickEventsManager\Recurrence\Exclusions::for_event( $event_id ),
			'a line-separated list was not read, or an impossible date was accepted'
		);
	}

	/**
	 * A rule that cannot be read changes nothing.
	 *
	 * @return void
	 */
	public function test_an_unreadable_rule_leaves_the_previous_one_alone() {
		$event_id = $this->make_dated_event( '2026-06-02 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'WEEKLY',
				'qevm_repeat_interval' => '1',
				'qevm_repeat_end'      => 'never',
			)
		);

		$stored = get_post_meta( $event_id, Meta::RECURRENCE_RULE, true );

		$this->assertSame( 'FREQ=WEEKLY', $stored, 'the fixture stored no rule to preserve' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'FORTNIGHTLY',
				'qevm_repeat_interval' => '1',
				'qevm_repeat_end'      => 'never',
			)
		);

		$this->assertSame( $stored, get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ), 'a rule that could not be read overwrote one that could' );
		$this->assertIsString(
			get_transient( RepeatBox::NOTICE . get_current_user_id() . '_' . $event_id ),
			'the refusal was silent'
		);
	}

	/**
	 * A readable rule the domain refuses changes nothing either.
	 *
	 * Separate from the test above, and not a duplicate of it. That one is
	 * refused by `Frequency::coerce()` before a rule is ever built, so it says
	 * nothing about what happens to a rule that is perfectly readable and still
	 * unacceptable — an interval of 5,000 weeks. Deleting the `validate()` call
	 * left the whole suite green until this existed.
	 *
	 * @return void
	 */
	public function test_a_rule_the_domain_refuses_leaves_the_previous_one_alone() {
		$event_id = $this->make_dated_event( '2026-06-02 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'WEEKLY',
				'qevm_repeat_interval' => '1',
				'qevm_repeat_end'      => 'never',
			)
		);

		$stored = get_post_meta( $event_id, Meta::RECURRENCE_RULE, true );

		$this->assertSame( 'FREQ=WEEKLY', $stored, 'the fixture stored no rule to preserve' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'WEEKLY',
				'qevm_repeat_interval' => '5000',
				'qevm_repeat_end'      => 'never',
			)
		);

		$this->assertSame( $stored, get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ), 'a rule the domain refuses was stored anyway' );
		$this->assertIsString(
			get_transient( RepeatBox::NOTICE . get_current_user_id() . '_' . $event_id ),
			'the refusal was silent'
		);
	}

	/**
	 * Unticking "repeats" drops the rule and keeps the series identifier.
	 *
	 * The identifier is the only thing joining this event to the other half of
	 * itself after a split, and to the rows already generated from it. Minting a
	 * fresh one when somebody unticks and reticks makes the two halves strangers.
	 *
	 * @return void
	 */
	public function test_unticking_repeats_drops_the_rule_but_keeps_the_series() {
		$event_id = $this->make_dated_event( '2026-06-02 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'WEEKLY',
				'qevm_repeat_interval' => '1',
				'qevm_repeat_end'      => 'count',
				'qevm_repeat_count'    => '4',
			)
		);

		\QuickEventsManager\Events\OccurrenceSync::sync( $event_id );

		$uuid = Series::for_event( $event_id );

		$this->assertNotSame( '', $uuid, 'the fixture never became a series' );
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );

		$this->save_box( $event_id, array( 'qevm_repeat_freq' => 'WEEKLY' ) );

		$this->assertSame( '', get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ) );
		$this->assertSame( $uuid, Series::for_event( $event_id ), 'the series identifier was thrown away' );

		\QuickEventsManager\Events\OccurrenceSync::sync( $event_id );

		$this->assertCount( 1, OccurrenceRepository::for_event( $event_id ), 'the event did not go back to a single date' );
	}

	/**
	 * One save writes the date, then the rule, then the occurrences.
	 *
	 * The ordering claim, tested through a real save rather than by reading the
	 * priority out of the source. The start date moves to a Friday in the last
	 * week of the month **in the same request** that asks for a monthly-by-weekday
	 * rule, so a rule built before the date was stored would say "the third
	 * Wednesday" — and the dates generated afterwards prove the sync saw the rule
	 * this save produced rather than the one before it.
	 *
	 * @return void
	 */
	public function test_one_save_writes_the_date_then_the_rule_then_the_dates() {
		$event_id = $this->make_dated_event( '2026-06-03 18:00:00' );

		( new MetaBox() )->register();
		( new RepeatBox() )->register();

		$request = array(
			'qevm_event_details_nonce' => wp_create_nonce( MetaBox::NONCE ),
			'qevm_recurrence_nonce'    => wp_create_nonce( RepeatBox::NONCE ),
			'qevm_timezone'            => 'UTC',
			'qevm_start_local'         => '2026-06-26T18:00',
			'qevm_end_local'           => '2026-06-26T19:00',
			'qevm_repeats'             => '1',
			'qevm_repeat_freq'         => 'MONTHLY',
			'qevm_repeat_interval'     => '1',
			'qevm_repeat_monthly_mode' => 'weekday',
			'qevm_repeat_end'          => 'count',
			'qevm_repeat_count'        => '3',
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Building the request the code under test verifies for itself.
		$previous = $_POST;
		$_POST    = wp_slash( $request );

		try {
			wp_update_post( array( 'ID' => $event_id ) );
		} finally {
			$_POST = $previous;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// The 26th of June 2026 is the last Friday: the rule was built from the date this save wrote.
		$this->assertStringContainsString( 'BYDAY=-1FR', get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ) );

		// And the sync ran afterwards, on the rule this save produced.
		$dates = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 3, $dates );
		$this->assertSame( '2026-06-26 18:00:00', $dates[0]->start_utc() );
	}

	/**
	 * The box renders the rule it stored.
	 *
	 * @return void
	 */
	public function test_the_box_shows_back_what_was_saved() {
		$event_id = $this->make_dated_event( '2026-06-02 18:00:00' );

		$this->save_box(
			$event_id,
			array(
				'qevm_repeats'         => '1',
				'qevm_repeat_freq'     => 'WEEKLY',
				'qevm_repeat_interval' => '2',
				'qevm_repeat_weekday'  => array( 'TU' ),
				'qevm_repeat_end'      => 'never',
			)
		);

		ob_start();
		( new RepeatBox() )->render( get_post( $event_id ) );
		$markup = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<input[^>]*name="qevm_repeats"[^>]*checked/', $markup, 'the box does not know the event repeats' );
		$this->assertMatchesRegularExpression( '/<option value="WEEKLY"[^>]*selected/', $markup, 'the frequency came back unselected' );
		$this->assertMatchesRegularExpression( '/name="qevm_repeat_interval"[^>]*value="2"/s', $markup, 'the interval came back as something else' );
		$this->assertMatchesRegularExpression( '/value="TU"[^>]*checked/', $markup, 'the chosen weekday came back unticked' );

		// And a day that was not chosen is not ticked, or the check above proves nothing.
		$this->assertDoesNotMatchRegularExpression( '/value="TH"[^>]*checked/', $markup );
	}

	/**
	 * An event with a start date and a timezone.
	 *
	 * @param string $start_local Local start, `Y-m-d H:i:s`.
	 * @param string $timezone    Timezone identifier.
	 * @return int
	 */
	private function make_dated_event( $start_local, $timezone = 'UTC' ) {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::TIMEZONE, $timezone );
		update_post_meta( $event_id, Meta::START_LOCAL, $start_local );
		update_post_meta( $event_id, Meta::END_LOCAL, gmdate( 'Y-m-d H:i:s', strtotime( $start_local ) + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::START_UTC, Meta::to_utc( $start_local, $timezone ) );
		update_post_meta( $event_id, Meta::END_UTC, Meta::to_utc( gmdate( 'Y-m-d H:i:s', strtotime( $start_local ) + HOUR_IN_SECONDS ), $timezone ) );

		return $event_id;
	}

	/**
	 * Submit the box, with its nonce.
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string, mixed> $request  Field values.
	 * @return void
	 */
	private function save_box( $event_id, array $request ) {
		$request['qevm_recurrence_nonce'] = wp_create_nonce( RepeatBox::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Building the request the code under test verifies for itself.
		$previous = $_POST;
		$_POST    = wp_slash( $request );

		try {
			( new RepeatBox() )->save( $event_id, get_post( $event_id ) );
		} finally {
			$_POST = $previous;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * An administrator id, so the capability check in save() passes.
	 *
	 * @return int
	 */
	private function an_administrator() {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1,
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 1;
	}
}
