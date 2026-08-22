<?php
/**
 * Admitting people at the door.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CheckIn\CheckInModule;
use QuickEventsManager\CheckIn\CheckInRepository;
use QuickEventsManager\CheckIn\CheckInService;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\Repository;

/**
 * Three outcomes, and the second one is not an error.
 *
 * "Already in" is the second scan of the same ticket, which happens constantly
 * at a real door, and it has to carry the time of the first so the person
 * holding the phone can tell ten seconds ago from an hour ago.
 */
final class CheckInTest extends TestCase {

	/**
	 * Switch registration and check-in on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enable_registration();

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CheckInModule::ID ) );

		if ( ! CheckInRepository::table_exists() ) {
			( new CheckInModule() )->activate();

			$this->restore_schema();
		}

		( new CheckInModule() )->register();
	}

	/**
	 * The database is what stops the second insert, not the code above it.
	 *
	 * **First in this class deliberately.** Dropping an index is DDL, and MySQL
	 * commits the open transaction when it runs — so anything an earlier test
	 * had pending becomes permanent at that moment and turns up in the counts of
	 * every test after it. Running first means there is nothing pending to
	 * commit, and this test removes the rows it wrote itself. Same shape as the
	 * recurrence schema test, which drops a column and puts it back this way.
	 *
	 * The behavioural test below passes with the key dropped, which is not a
	 * criticism of it — it asks what the door was told, and the door is told the
	 * right thing either way. This asks the other question: what would happen if
	 * the key were not there. Two inserts, no key, two rows.
	 *
	 * @return void
	 */
	public function test_the_unique_key_is_what_prevents_the_second_row() {
		global $wpdb;

		$table = CheckInRepository::table();
		$probe = 987654;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removing the guarantee to show it was doing the work.

		/*
		 * Its own rows first. This test commits, so a run that fails part way
		 * leaves them behind — and the next run then counts those instead and
		 * reports a number that has nothing to do with what it just did.
		 */
		$wpdb->delete( $table, array( 'attendee_id' => $probe ), array( '%d' ) );
		$wpdb->delete( $table, array( 'attendee_id' => $probe + 1 ), array( '%d' ) );

		$wpdb->query( "ALTER TABLE {$table} DROP INDEX attendee_occurrence" );

		$without = array(
			CheckInRepository::record( $probe, 5 ),
			CheckInRepository::record( $probe, 5 ),
		);

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE attendee_id = %d', $table, $probe )
		);

		/*
		 * The duplicates go before the key comes back, and that order is the
		 * whole lesson of this test. A unique index cannot be added to a table
		 * whose rows already violate it — and dbDelta does not notice: it
		 * reports "Added index" from its own comparison of the schema, while the
		 * ALTER underneath it failed. Restoring first and cleaning afterwards
		 * left the index missing for every test that followed, with nothing
		 * anywhere saying so.
		 */
		$wpdb->delete( $table, array( 'attendee_id' => $probe ), array( '%d' ) );

		/*
		 * The module's own activate(), not Installer::upgrade_schema(). That
		 * walks the registry's enabled modules, and the registry read the option
		 * when the plugin booted — before this class switched check-in on — so
		 * it would skip the very module whose index needs putting back.
		 */
		( new CheckInModule() )->activate();

		$restored = array(
			CheckInRepository::record( $probe + 1, 5 ),
			CheckInRepository::record( $probe + 1, 5 ),
		);

		$wpdb->delete( $table, array( 'attendee_id' => $probe + 1 ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertGreaterThan( 0, $without[0], 'the first insert failed, so this test measured nothing' );
		$this->assertGreaterThan( 0, $without[1], 'the second insert failed for some reason other than the key' );
		$this->assertSame( 2, $rows, 'without the unique key there is nothing to stop two doors admitting one person' );

		$this->assertGreaterThan( 0, $restored[0] );
		$this->assertSame( 0, $restored[1], 'the key was not put back, so every later test is running without it' );
	}

	/**
	 * Somebody arrives.
	 *
	 * @return void
	 */
	public function test_a_ticket_admits_its_holder() {
		$attendee = $this->an_attendee();

		$outcome = CheckInService::admit_by_code( $attendee->ticket_code() );

		$this->assertSame( CheckInService::ADMITTED, $outcome['result'] );
		$this->assertNotNull( $outcome['checkin'] );
		$this->assertTrue( CheckInService::is_present( $attendee->id() ) );
	}

	/**
	 * The second scan says when the first one was.
	 *
	 * @return void
	 */
	public function test_a_second_scan_reports_the_first() {
		$attendee = $this->an_attendee();

		$first = CheckInService::admit_by_code( $attendee->ticket_code() );
		$again = CheckInService::admit_by_code( $attendee->ticket_code() );

		$this->assertSame( CheckInService::ADMITTED, $first['result'] );
		$this->assertSame( CheckInService::ALREADY, $again['result'] );
		$this->assertMatchesRegularExpression( '/Already checked in at /', $again['message'] );

		// And the room still holds one person, not two.
		$this->assertSame( 1, CheckInRepository::count_present( $first['checkin']->occurrence_id() ) );
	}

	/**
	 * Two doors, one person, one row.
	 *
	 * The unique key is the guarantee, so this asserts against the table rather
	 * than against what either caller was told.
	 *
	 * @return void
	 */
	public function test_two_doors_cannot_admit_the_same_person_twice() {
		$attendee = $this->an_attendee();

		$door_one = CheckInService::admit( $attendee->id(), 1, 'qr' );
		$door_two = CheckInService::admit( $attendee->id(), 2, 'manual' );

		$this->assertSame( CheckInService::ADMITTED, $door_one['result'] );
		$this->assertSame( CheckInService::ALREADY, $door_two['result'] );

		global $wpdb;

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE attendee_id = %d',
				CheckInRepository::table(),
				$attendee->id()
			)
		);

		$this->assertSame( 1, $rows, 'the same person has two check-in rows for one date' );
	}

	/**
	 * A reversal is recorded, not deleted.
	 *
	 * @return void
	 */
	public function test_a_reversal_keeps_the_record() {
		$attendee = $this->an_attendee();

		CheckInService::admit( $attendee->id() );

		$this->assertTrue( CheckInService::reverse( $attendee->id(), 7 ) );
		$this->assertFalse( CheckInService::is_present( $attendee->id() ) );

		$outcome = CheckInService::admit( $attendee->id() );

		$this->assertSame(
			CheckInService::ADMITTED,
			$outcome['result'],
			'somebody sent out by mistake cannot be let back in'
		);
		$this->assertTrue( CheckInService::is_present( $attendee->id() ) );
	}

	/**
	 * A cancelled booking is refused, and told why.
	 *
	 * @return void
	 */
	public function test_a_cancelled_booking_is_refused() {
		$attendee     = $this->an_attendee();
		$registration = Repository::find( $attendee->registration_id() );

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );

		$outcome = CheckInService::admit_by_code( $attendee->ticket_code() );

		$this->assertSame( CheckInService::REFUSED, $outcome['result'] );
		$this->assertMatchesRegularExpression( '/cancelled/i', $outcome['message'] );
		$this->assertFalse( CheckInService::is_present( $attendee->id() ) );
	}

	/**
	 * Somebody on the waiting list has no place, and hears that rather than "no".
	 *
	 * @return void
	 */
	public function test_a_waitlisted_booking_is_refused_by_name() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$this->book( $event_id, array( 'email' => 'holder@example.test' ) );

		$waiting   = $this->book( $event_id, array( 'email' => 'waiting@example.test' ) );
		$attendees = AttendeeRepository::for_registration( $waiting->id() );

		$this->assertSame( RegistrationStatus::Waitlisted, $waiting->status(), 'the fixture nobody is waiting' );

		$outcome = CheckInService::admit( $attendees[0]->id() );

		$this->assertSame( CheckInService::REFUSED, $outcome['result'] );
		$this->assertMatchesRegularExpression( '/waiting list/i', $outcome['message'] );
	}

	/**
	 * An unknown code is refused without a fuss.
	 *
	 * @return void
	 */
	public function test_an_unknown_code_is_refused() {
		$outcome = CheckInService::admit_by_code( 'QEVT-NOTAREALCODE' );

		$this->assertSame( CheckInService::REFUSED, $outcome['result'] );
		$this->assertNull( $outcome['attendee'] );
	}

	/**
	 * A booking on a series checks into its own date.
	 *
	 * @return void
	 */
	public function test_a_series_booking_arrives_at_its_own_date() {
		$event_id = $this->make_series();
		$dates    = OccurrenceRepository::for_event( $event_id );

		$booking   = $this->book(
			$event_id,
			array(
				'occurrence_id' => $dates[2]->id(),
				'email'         => 'third@example.test',
			)
		);
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		$outcome = CheckInService::admit( $attendees[0]->id() );

		$this->assertSame( CheckInService::ADMITTED, $outcome['result'] );
		$this->assertSame( $dates[2]->id(), $outcome['checkin']->occurrence_id() );

		// The other weeks have nobody in the room.
		$this->assertSame( 0, CheckInRepository::count_present( $dates[0]->id() ) );
		$this->assertSame( 1, CheckInRepository::count_present( $dates[2]->id() ) );
	}

	/**
	 * A booking with no date of its own arrives at the event's date.
	 *
	 * Every booking on an event with one date carries no occurrence, so the
	 * door resolves it — otherwise a door report for that date would be empty
	 * while people stood in the room.
	 *
	 * @return void
	 */
	public function test_a_dateless_booking_arrives_at_the_events_own_date() {
		$attendee = $this->an_attendee();

		$this->assertSame( 0, $attendee->occurrence_id(), 'the fixture booking already names a date' );

		$outcome    = CheckInService::admit( $attendee->id() );
		$occurrence = OccurrenceRepository::next_for_event( $this->event_of( $attendee ) );

		$this->assertNotNull( $occurrence );
		$this->assertSame( $occurrence->id(), $outcome['checkin']->occurrence_id() );
		$this->assertSame( 1, CheckInRepository::count_present( $occurrence->id() ) );
	}

	/**
	 * Deleting a booking takes its check-ins with it.
	 *
	 * @return void
	 */
	public function test_deleting_a_booking_forgets_its_check_ins() {
		$attendee = $this->an_attendee();

		CheckInService::admit( $attendee->id() );

		$this->assertTrue( CheckInService::is_present( $attendee->id() ) );

		Repository::delete( $attendee->registration_id() );

		global $wpdb;

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE attendee_id = %d',
				CheckInRepository::table(),
				$attendee->id()
			)
		);

		$this->assertSame( 0, $rows, 'a deleted booking left its check-in behind' );
	}

	/**
	 * One booked attendee on a plain event.
	 *
	 * @return \QuickEventsManager\Registration\Attendee
	 */
	private function an_attendee() {
		$event_id  = $this->make_event();
		$booking   = $this->book( $event_id );
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		$this->assertNotEmpty( $attendees, 'the fixture booked nobody' );

		return $attendees[0];
	}

	/**
	 * The event an attendee belongs to.
	 *
	 * @param \QuickEventsManager\Registration\Attendee $attendee The person.
	 * @return int
	 */
	private function event_of( $attendee ) {
		$registration = Repository::find( $attendee->registration_id() );

		return null !== $registration ? $registration->event_id() : 0;
	}

	/**
	 * A weekly series with dates on it.
	 *
	 * @return int
	 */
	private function make_series() {
		$event_id = $this->make_event();

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CheckInModule::ID, RecurrenceModule::ID ) );

		update_post_meta( $event_id, Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, Meta::START_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::END_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::START_UTC, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::END_UTC, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=4' );

		OccurrenceSync::sync( $event_id );

		return $event_id;
	}
}
