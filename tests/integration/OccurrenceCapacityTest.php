<?php
/**
 * Capacity, duplicates and the waiting list, per date.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Registration\Waitlist;

/**
 * A place on the 3rd of June is not a place on the 10th.
 *
 * Until this chunk, `occurrence_id` was written as 0 by every booking and
 * capacity was counted across the whole series — so a twenty-place weekly class
 * sold out in the first fortnight and everybody after that was waitlisted for a
 * term. That is the contradiction docs/recurrence.md §6 described and the code
 * did not implement.
 */
final class OccurrenceCapacityTest extends TestCase {

	/**
	 * Switch both modules on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enable_registration();

		update_option(
			QEVM_OPTION_MODULES,
			array( RegistrationModule::ID, RecurrenceModule::ID )
		);
	}

	/**
	 * Twenty places a week means twenty each week.
	 *
	 * @return void
	 */
	public function test_capacity_is_counted_within_the_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4', 1 );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$first  = $this->book_date( $event_id, $dates[0]->id(), 'first@example.test' );
		$second = $this->book_date( $event_id, $dates[1]->id(), 'second@example.test' );

		$this->assertSame( RegistrationStatus::Confirmed, $first->status(), 'the first booking did not get its place' );
		$this->assertSame(
			RegistrationStatus::Confirmed,
			$second->status(),
			'a booking on the following week was counted against the first week'
		);

		// And the third, on a date that is now full, waits.
		$third = $this->book_date( $event_id, $dates[0]->id(), 'third@example.test' );

		$this->assertSame( RegistrationStatus::Waitlisted, $third->status() );
	}

	/**
	 * The same person can come back next week.
	 *
	 * @return void
	 */
	public function test_the_same_address_may_book_a_different_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->book_date( $event_id, $dates[0]->id(), 'regular@example.test' );

		$again = $this->book_date( $event_id, $dates[1]->id(), 'regular@example.test' );

		$this->assertNotWPError( $again );
		$this->assertSame( RegistrationStatus::Confirmed, $again->status() );
	}

	/**
	 * The same person cannot book the same date twice.
	 *
	 * @return void
	 */
	public function test_the_same_address_may_not_book_the_same_date_twice() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->book_date( $event_id, $dates[0]->id(), 'regular@example.test' );

		$result = ( new RegistrationService() )->create(
			$event_id,
			$this->fields( 'regular@example.test', $dates[0]->id() )
		);

		$this->assertWPErrorCode( 'qevm_already_registered', $result );
	}

	/**
	 * A booking on a series has to say which date.
	 *
	 * @return void
	 */
	public function test_a_series_booking_must_name_a_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );

		$result = ( new RegistrationService() )->create( $event_id, $this->fields( 'nodate@example.test', 0 ) );

		$this->assertWPErrorCode( 'qevm_occurrence_required', $result );
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'a booking with no date was stored anyway' );
	}

	/**
	 * A date belonging to another event is refused.
	 *
	 * @return void
	 */
	public function test_a_date_from_another_event_is_refused() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$other_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$theirs   = OccurrenceRepository::for_event( $other_id );

		$result = ( new RegistrationService() )->create(
			$event_id,
			$this->fields( 'wrong@example.test', $theirs[0]->id() )
		);

		$this->assertWPErrorCode( 'qevm_occurrence_not_found', $result );
	}

	/**
	 * A called-off date takes no bookings.
	 *
	 * @return void
	 */
	public function test_a_called_off_date_takes_no_bookings() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		\QuickEventsManager\Recurrence\OccurrenceEditor::cancel( $dates[1]->id() );

		$result = ( new RegistrationService() )->create(
			$event_id,
			$this->fields( 'hopeful@example.test', $dates[1]->id() )
		);

		$this->assertWPErrorCode( 'qevm_occurrence_cancelled', $result );
	}

	/**
	 * An event with one date needs no date on the booking.
	 *
	 * Its single occurrence is identified by its start time, so moving the
	 * event replaces that row — a booking pointing at it would be left behind
	 * by an ordinary reschedule.
	 *
	 * @return void
	 */
	public function test_a_one_off_event_still_books_without_a_date() {
		$event_id = $this->make_event( array( 'capacity' => 2 ) );

		$booking = $this->book_date( $event_id, 0, 'oneoff@example.test' );

		$this->assertSame( RegistrationStatus::Confirmed, $booking->status() );
		$this->assertSame( 0, $booking->occurrence_id(), 'a one-off booking was attached to a date' );
	}

	/**
	 * A place freed on one date is offered to somebody waiting for that date.
	 *
	 * @return void
	 */
	public function test_the_waiting_list_moves_within_its_own_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4', 1 );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$holder  = $this->book_date( $event_id, $dates[0]->id(), 'holder@example.test' );
		$waiting = $this->book_date( $event_id, $dates[0]->id(), 'waiting@example.test' );
		$other   = $this->book_date( $event_id, $dates[1]->id(), 'otherweek@example.test' );

		$this->assertSame( RegistrationStatus::Waitlisted, $waiting->status(), 'the fixture nobody is waiting' );
		$this->assertSame( RegistrationStatus::Confirmed, $other->status() );

		Repository::update_status( $holder->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $waiting->id() )->status(),
			'the place freed on that date was not offered to the person waiting for it'
		);
	}

	/**
	 * Somebody waiting for another date is not promoted by a cancellation.
	 *
	 * @return void
	 */
	public function test_a_cancellation_does_not_promote_another_dates_queue() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4', 1 );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$holder       = $this->book_date( $event_id, $dates[0]->id(), 'holder@example.test' );
		$other_holder = $this->book_date( $event_id, $dates[1]->id(), 'otherholder@example.test' );
		$other_queue  = $this->book_date( $event_id, $dates[1]->id(), 'otherqueue@example.test' );

		$this->assertSame( RegistrationStatus::Waitlisted, $other_queue->status(), 'the fixture nobody is waiting' );
		$this->assertSame( RegistrationStatus::Confirmed, $other_holder->status() );

		Repository::update_status( $holder->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $other_queue->id() )->status(),
			'a place freed on one date was given to somebody waiting for another'
		);
	}

	/**
	 * Every attendee row carries the date the booking is for.
	 *
	 * Check-in and per-person tickets read the date from the person, not from
	 * the booking, and a roster for the 3rd of June that lists everybody who
	 * ever booked the series is not a roster.
	 *
	 * @return void
	 */
	public function test_attendee_rows_carry_the_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$booking = $this->book_date( $event_id, $dates[2]->id(), 'people@example.test', 3 );

		$attendees = \QuickEventsManager\Registration\AttendeeRepository::for_registration( $booking->id() );

		$this->assertCount( 3, $attendees );

		foreach ( $attendees as $attendee ) {
			$this->assertSame( $dates[2]->id(), $attendee->occurrence_id() );
		}
	}

	/**
	 * Places remaining is a question about a date.
	 *
	 * @return void
	 */
	public function test_places_remaining_is_per_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4', 2 );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$event    = new \QuickEventsManager\Events\Event( $event_id );

		$this->book_date( $event_id, $dates[0]->id(), 'one@example.test' );

		$this->assertSame( 1, RegistrationService::places_remaining( $event, $dates[0]->id() ) );
		$this->assertSame( 2, RegistrationService::places_remaining( $event, $dates[1]->id() ) );
		$this->assertFalse( RegistrationService::is_full( $event, $dates[1]->id() ) );

		$this->book_date( $event_id, $dates[0]->id(), 'two@example.test' );

		$this->assertTrue( RegistrationService::is_full( $event, $dates[0]->id() ) );
		$this->assertFalse( RegistrationService::is_full( $event, $dates[1]->id() ) );
	}

	/**
	 * The form asks which date, and only when there is a choice.
	 *
	 * @return void
	 */
	public function test_the_form_offers_the_dates() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'name="qevm_occurrence_id"', $markup );

		foreach ( $dates as $date ) {
			$this->assertStringContainsString( 'value="' . $date->id() . '"', $markup );
		}
	}

	/**
	 * An event with one date asks nothing.
	 *
	 * @return void
	 */
	public function test_a_one_off_form_has_no_date_picker() {
		$event_id = $this->make_event();

		$this->assertStringNotContainsString( 'name="qevm_occurrence_id"', $this->render_form( $event_id ) );
	}

	/**
	 * A full date is offered, and says it is full.
	 *
	 * Removing it would show somebody a gap in the weeks with no way to ask for
	 * a place if one comes free.
	 *
	 * @return void
	 */
	public function test_a_full_date_stays_on_the_list_and_says_so() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4', 1 );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->book_date( $event_id, $dates[0]->id(), 'holder@example.test' );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'value="' . $dates[0]->id() . '"', $markup, 'the full date was dropped from the list' );
		$this->assertMatchesRegularExpression( '/waiting list/i', $markup, 'nothing on the form says that date is full' );
	}

	/**
	 * A date that has already happened is not offered.
	 *
	 * @return void
	 */
	public function test_a_past_date_is_not_offered() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		// Put the second date in the past, where nobody can book it.
		OccurrenceRepository::update(
			$dates[1]->id(),
			array(
				'start_utc' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'end_utc'   => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS + HOUR_IN_SECONDS ),
			)
		);

		$markup = $this->render_form( $event_id );

		$this->assertStringNotContainsString( 'value="' . $dates[1]->id() . '"', $markup );
		$this->assertStringContainsString( 'value="' . $dates[2]->id() . '"', $markup, 'the fixture removed more than the past date' );
	}

	/**
	 * A series stays open once its first date has passed.
	 *
	 * The event's own end time is the first date's, so reading it closed a
	 * twelve-week class the moment week one finished.
	 *
	 * @return void
	 */
	public function test_a_series_stays_open_after_its_first_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$event    = new \QuickEventsManager\Events\Event( $event_id );

		$past = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		update_post_meta( $event_id, Meta::START_UTC, $past );
		update_post_meta( $event_id, Meta::END_UTC, $past );

		OccurrenceRepository::update(
			$dates[0]->id(),
			array(
				'start_utc' => $past,
				'end_utc'   => $past,
			)
		);

		$this->assertSame( '', RegistrationService::closed_reason( new \QuickEventsManager\Events\Event( $event_id ) ) );
		$this->assertTrue( RegistrationService::is_open( new \QuickEventsManager\Events\Event( $event_id ) ) );

		unset( $event );
	}

	/**
	 * An event whose every date has passed is closed.
	 *
	 * @return void
	 */
	public function test_a_series_closes_when_every_date_has_passed() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=3' );
		$past     = time() - WEEK_IN_SECONDS;

		foreach ( OccurrenceRepository::for_event( $event_id ) as $index => $date ) {
			OccurrenceRepository::update(
				$date->id(),
				array(
					'start_utc' => gmdate( 'Y-m-d H:i:s', $past + ( $index * HOUR_IN_SECONDS ) ),
					'end_utc'   => gmdate( 'Y-m-d H:i:s', $past + ( $index * HOUR_IN_SECONDS ) + HOUR_IN_SECONDS ),
				)
			);
		}

		$this->assertSame( 'ended', RegistrationService::closed_reason( new \QuickEventsManager\Events\Event( $event_id ) ) );
	}

	/**
	 * The submitted date survives the transport.
	 *
	 * The picker and the service were both right and the form was still
	 * unbookable while the field between them was dropped — an error message
	 * asking somebody to choose a date they had just chosen.
	 *
	 * @return void
	 */
	public function test_the_chosen_date_survives_the_form_transport() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Writing the request the transport is about to read, then putting it back.
		$previous = $_POST;

		$_POST = array(
			'qevm_name'          => 'Booker',
			'qevm_email'         => 'transport@example.test',
			'qevm_occurrence_id' => '4242',
		);

		try {
			$input = \QuickEventsManager\Registration\FormHandler::collect_input();
		} finally {
			$_POST = $previous;
		}

		$this->assertArrayHasKey( 'occurrence_id', $input, 'the form transport drops the chosen date' );
		$this->assertSame( '4242', (string) $input['occurrence_id'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The form's markup for an event.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private function render_form( $event_id ) {
		return (string) \QuickEventsManager\Frontend\Renderer::registration_form( array( 'id' => (string) $event_id ) );
	}

	/**
	 * Book a place on one date.
	 *
	 * Named for the date rather than shadowing the base class's book(), which
	 * takes a different shape and is protected.
	 *
	 * @param int    $event_id      Event id.
	 * @param int    $occurrence_id Date id, or 0.
	 * @param string $email         Booker's address.
	 * @param int    $quantity      Places.
	 * @return \QuickEventsManager\Registration\Registration
	 */
	private function book_date( $event_id, $occurrence_id, $email, $quantity = 1 ) {
		$result = ( new RegistrationService() )->create(
			$event_id,
			$this->fields( $email, $occurrence_id, $quantity )
		);

		$this->assertNotWPError( $result );

		return $result;
	}

	/**
	 * A submitted form.
	 *
	 * @param string $email         Booker's address.
	 * @param int    $occurrence_id Date id, or 0.
	 * @param int    $quantity      Places.
	 * @return array<string, mixed>
	 */
	private function fields( $email, $occurrence_id, $quantity = 1 ) {
		return array(
			'name'          => 'Booker',
			'email'         => $email,
			'quantity'      => $quantity,
			'occurrence_id' => $occurrence_id,
			'consent'       => '1',
		);
	}

	/**
	 * Assert a WP_Error with a given code.
	 *
	 * @param string $code   Expected error code.
	 * @param mixed  $result What came back.
	 * @return void
	 */
	private function assertWPErrorCode( $code, $result ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches PHPUnit's own assertion naming.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * A recurring event with dates and a capacity.
	 *
	 * @param string $rrule    The rule.
	 * @param int    $capacity Places per date, or 0 for unlimited.
	 * @return int
	 */
	private function make_series( $rrule, $capacity = 0 ) {
		$event_id = $this->make_event( array( 'capacity' => $capacity ) );

		update_post_meta( $event_id, Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, Meta::START_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::END_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::START_UTC, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::END_UTC, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, $rrule );

		OccurrenceSync::sync( $event_id );

		return $event_id;
	}
}
