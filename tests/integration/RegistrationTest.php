<?php
/**
 * Bookings and the people on them, against the tables that hold them.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\AttendeeStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Privacy\Privacy;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;

/**
 * The registration and attendee split, end to end.
 */
final class RegistrationTest extends TestCase {

	/**
	 * Quieten the rate limiter and the mailer for every test here.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->quieten_registration();
	}

	/**
	 * A booking for three is one registration and three people.
	 *
	 * The stage gate, stated as a test.
	 *
	 * @return void
	 */
	public function test_a_three_place_booking_creates_one_booking_and_three_attendees() {
		$event_id = $this->make_event();

		$registration = $this->book(
			$event_id,
			array(
				'quantity' => 3,
				'guests'   => array(
					2 => 'Grace Hopper',
					3 => 'Katherine Johnson',
				),
			)
		);

		$this->assertNotWPError( $registration );
		$this->assertSame( 1, $this->count_rows( 'registrations' ) );
		$this->assertSame( 3, $this->count_rows( 'attendees' ) );

		$people = AttendeeRepository::for_registration( $registration->id() );

		$this->assertSame( array( 1, 2, 3 ), array_map( static fn( $a ) => $a->position(), $people ) );
		$this->assertSame( 'Ada Lovelace', $people[0]->name() );
		$this->assertSame( 'Grace Hopper', $people[1]->name() );
		$this->assertSame( 'Katherine Johnson', $people[2]->name() );
	}

	/**
	 * Only the booker's address is stored, because only one was asked for.
	 *
	 * @return void
	 */
	public function test_a_guest_gets_no_invented_email_address() {
		$event_id     = $this->make_event();
		$registration = $this->book(
			$event_id,
			array(
				'quantity' => 2,
				'guests'   => array( 2 => 'Grace Hopper' ),
			)
		);

		$people = AttendeeRepository::for_registration( $registration->id() );

		$this->assertSame( 'ada@example.com', $people[0]->email() );
		$this->assertSame( '', $people[1]->email() );
	}

	/**
	 * Every place gets a ticket, named or not, and no two are the same.
	 *
	 * @return void
	 */
	public function test_every_place_gets_its_own_ticket() {
		$event_id     = $this->make_event();
		$registration = $this->book( $event_id, array( 'quantity' => 4 ) );

		$codes = array_map(
			static fn( $a ) => $a->ticket_code(),
			AttendeeRepository::for_registration( $registration->id() )
		);

		$this->assertCount( 4, array_filter( $codes ) );
		$this->assertCount( 4, array_unique( $codes ) );
		$this->assertNotContains( $registration->code(), $codes, 'a ticket is not a booking reference' );

		$scanned = AttendeeRepository::find_by_ticket_code( $codes[2] );

		$this->assertNotNull( $scanned );
		$this->assertSame( 3, $scanned->position() );
	}

	/**
	 * A booking for one is not a special case.
	 *
	 * @return void
	 */
	public function test_a_single_place_still_produces_a_person() {
		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertSame( 1, AttendeeRepository::count_for_registration( $registration->id() ) );
	}

	/**
	 * More names than places cannot become more places.
	 *
	 * Capacity was counted against the quantity, so a submission carrying
	 * twenty names and a quantity of one has to produce one place.
	 *
	 * @return void
	 */
	public function test_names_beyond_the_quantity_cannot_become_places() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$registration = $this->book(
			$event_id,
			array(
				'quantity' => 1,
				'guests'   => array(
					2 => 'Gatecrasher',
					3 => 'Another',
					4 => 'And another',
				),
			)
		);

		$this->assertSame( 1, AttendeeRepository::count_for_registration( $registration->id() ) );
		$this->assertSame( 1, Repository::count_taken( $event_id ) );
	}

	/**
	 * Capacity counts places on the booking, not people in the roster.
	 *
	 * @return void
	 */
	public function test_capacity_is_counted_in_places() {
		$event_id = $this->make_event( array( 'capacity' => 4 ) );
		$event    = new Event( $event_id );

		$first = $this->book(
			$event_id,
			array(
				'quantity' => 3,
				'email'    => 'first@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Confirmed, $first->status() );
		$this->assertSame( 3, Repository::count_taken( $event_id ) );
		$this->assertSame( 1, RegistrationService::places_remaining( $event ) );

		$second = $this->book(
			$event_id,
			array(
				'quantity' => 2,
				'email'    => 'second@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Waitlisted, $second->status() );
		$this->assertSame( 3, Repository::count_taken( $event_id ), 'a waitlisted booking holds no places' );
		$this->assertSame(
			2,
			AttendeeRepository::count_for_registration( $second->id() ),
			'a waitlisted booking still has its people'
		);

		$third = $this->book(
			$event_id,
			array(
				'quantity' => 1,
				'email'    => 'third@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Confirmed, $third->status() );
		$this->assertTrue( RegistrationService::is_full( $event ) );
	}

	/**
	 * Cancelling a booking reaches the people on it.
	 *
	 * A cancelled booking whose attendees stay active still admits three
	 * colleagues at the door, each holding a code that scans as valid.
	 *
	 * @return void
	 */
	public function test_cancelling_a_booking_cancels_its_people() {
		$event_id     = $this->make_event( array( 'capacity' => 10 ) );
		$registration = $this->book( $event_id, array( 'quantity' => 3 ) );

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );

		$people = AttendeeRepository::for_registration( $registration->id() );

		$this->assertCount( 3, $people, 'the rows are kept so a scan can say cancelled' );
		$this->assertSame( array(), array_filter( $people, static fn( $a ) => $a->is_active() ) );
		$this->assertSame( AttendeeStatus::Cancelled, $people[0]->status() );
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'the places are freed' );
	}

	/**
	 * Reinstating a booking puts them back.
	 *
	 * @return void
	 */
	public function test_reinstating_a_booking_reactivates_its_people() {
		$event_id     = $this->make_event();
		$registration = $this->book( $event_id, array( 'quantity' => 2 ) );

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );
		Repository::update_status( $registration->id(), RegistrationStatus::Confirmed );

		$active = array_filter(
			AttendeeRepository::for_registration( $registration->id() ),
			static fn( $a ) => $a->is_active()
		);

		$this->assertCount( 2, $active );
	}

	/**
	 * Deleting a booking takes its people with it.
	 *
	 * There is no foreign key — dbDelta does not create them — so the cascade
	 * is the plugin's own responsibility, and an erasure that missed it would
	 * leave guests' names in a table nobody was looking at.
	 *
	 * @return void
	 */
	public function test_deleting_a_booking_removes_its_people() {
		$event_id = $this->make_event();
		$doomed   = $this->book(
			$event_id,
			array(
				'quantity' => 3,
				'email'    => 'doomed@example.com',
			)
		);
		$kept     = $this->book(
			$event_id,
			array(
				'quantity' => 1,
				'email'    => 'kept@example.com',
			)
		);

		Repository::delete( $doomed->id() );

		$this->assertSame( 0, AttendeeRepository::count_for_registration( $doomed->id() ) );
		$this->assertSame( 1, AttendeeRepository::count_for_registration( $kept->id() ) );
	}

	/**
	 * Deleting an event takes everyone booked onto it.
	 *
	 * Attendee rows carry no event id, so this reaches them through a join —
	 * the one query in the plugin that spans two of its own tables.
	 *
	 * @return void
	 */
	public function test_deleting_an_event_removes_everyone_booked_onto_it() {
		$doomed_event = $this->make_event();
		$other_event  = $this->make_event();

		$this->book(
			$doomed_event,
			array(
				'quantity' => 3,
				'email'    => 'a@example.com',
			)
		);
		$this->book(
			$doomed_event,
			array(
				'quantity' => 2,
				'email'    => 'b@example.com',
			)
		);
		$survivor = $this->book(
			$other_event,
			array(
				'quantity' => 1,
				'email'    => 'c@example.com',
			)
		);

		$this->assertSame( 6, $this->count_rows( 'attendees' ) );

		$removed = Repository::delete_for_event( $doomed_event );

		$this->assertSame( 2, $removed );
		$this->assertSame( 1, $this->count_rows( 'attendees' ) );
		$this->assertSame( 1, AttendeeRepository::count_for_registration( $survivor->id() ) );
	}

	/**
	 * An erasure request removes the guests as well as the booker.
	 *
	 * @return void
	 */
	public function test_an_erasure_leaves_nobody_behind() {
		$event_id = $this->make_event();

		$this->book(
			$event_id,
			array(
				'quantity' => 3,
				'email'    => 'subject@example.com',
				'guests'   => array( 2 => 'Grace Hopper' ),
			)
		);

		$export = ( new Privacy() )->export( 'subject@example.com' );
		$values = array();

		foreach ( $export['data'] as $item ) {
			foreach ( $item['data'] as $pair ) {
				$values[ $pair['name'] ] = $pair['value'];
			}
		}

		$this->assertSame( 'Grace Hopper', $values['Guest 2'] ?? '' );
		$this->assertNotEmpty( $values['Consent given'] ?? '' );

		$result = ( new Privacy() )->erase( 'subject@example.com' );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertSame( 0, $this->count_rows( 'registrations' ) );
		$this->assertSame( 0, $this->count_rows( 'attendees' ) );
	}

	/**
	 * Consent is recorded against the booking, in UTC.
	 *
	 * @return void
	 */
	public function test_consent_is_recorded_with_the_booking() {
		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$stored = Repository::find( $registration->id() );

		$this->assertTrue( $stored->has_consent() );
		$this->assertSame( \QuickEventsManager\Privacy\Consent::version(), $stored->consent_version() );
		$this->assertLessThan( 120, abs( strtotime( $stored->consent_at() . ' UTC' ) - time() ) );
	}

	/**
	 * Registering without agreeing writes nothing at all.
	 *
	 * @return void
	 */
	public function test_a_booking_without_consent_is_refused() {
		$event_id = $this->make_event();

		$result = $this->book( $event_id, array( 'consent' => false ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_consent_required', $result->get_error_code() );
		$this->assertSame( 0, $this->count_rows( 'registrations' ) );
		$this->assertSame( 0, $this->count_rows( 'attendees' ) );
	}
}
