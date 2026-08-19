<?php
/**
 * Capacity when there is more than one kind of place.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;

/**
 * Two limits, and a booking has to fit both.
 *
 * The event's capacity is the room. A ticket type's capacity is a reservation
 * inside it. Twelve seats with four kept for members means the fifth member
 * waits while the room is half empty, and the thirteenth person waits whatever
 * ticket they hold. Getting either one to override the other is the failure.
 */
final class TicketCapacityTest extends TestCase {

	/**
	 * Switch both modules on and make sure the table is there.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enable_registration();

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, TicketsModule::ID ) );

		if ( ! TicketTypeRepository::table_exists() ) {
			( new TicketsModule() )->activate();

			$this->restore_schema();
		}
	}

	/**
	 * A type's capacity is reached before the event's.
	 *
	 * @return void
	 */
	public function test_a_type_sells_out_within_a_roomy_event() {
		$event_id = $this->make_event( array( 'capacity' => 20 ) );
		$member   = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Member',
				'capacity' => 2,
			)
		);
		$guest    = TicketTypeRepository::insert( $event_id, array( 'name' => 'Guest' ) );

		$this->assertSame( RegistrationStatus::Confirmed, $this->book_type( $event_id, $member, 'm1@example.test' )->status() );
		$this->assertSame( RegistrationStatus::Confirmed, $this->book_type( $event_id, $member, 'm2@example.test' )->status() );

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			$this->book_type( $event_id, $member, 'm3@example.test' )->status(),
			'the third member place was sold although only two exist'
		);

		// And the room is still open to everybody else.
		$this->assertSame(
			RegistrationStatus::Confirmed,
			$this->book_type( $event_id, $guest, 'g1@example.test' )->status(),
			'a full ticket type closed the whole event'
		);
	}

	/**
	 * The event's capacity is reached before the type's.
	 *
	 * @return void
	 */
	public function test_a_full_event_stops_a_type_with_places_left() {
		$event_id = $this->make_event( array( 'capacity' => 2 ) );
		$type     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Standard',
				'capacity' => 50,
			)
		);

		$this->book_type( $event_id, $type, 'one@example.test' );
		$this->book_type( $event_id, $type, 'two@example.test' );

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			$this->book_type( $event_id, $type, 'three@example.test' )->status(),
			'the room sold a third seat because the ticket type had places left'
		);
	}

	/**
	 * An uncapped type in an uncapped event is unlimited.
	 *
	 * @return void
	 */
	public function test_no_capacity_anywhere_means_no_waiting_list() {
		$event_id = $this->make_event( array( 'capacity' => 0 ) );
		$type     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Open' ) );

		foreach ( range( 1, 4 ) as $number ) {
			$this->assertSame(
				RegistrationStatus::Confirmed,
				$this->book_type( $event_id, $type, 'open' . $number . '@example.test' )->status()
			);
		}
	}

	/**
	 * A capped type inside an uncapped event still stops.
	 *
	 * "Twenty members, anybody else welcome" is a real thing to want, and it is
	 * the case where the event has no capacity at all to fall back on.
	 *
	 * @return void
	 */
	public function test_a_capped_type_stops_inside_an_uncapped_event() {
		$event_id = $this->make_event( array( 'capacity' => 0 ) );
		$member   = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Member',
				'capacity' => 1,
			)
		);
		$guest    = TicketTypeRepository::insert( $event_id, array( 'name' => 'Guest' ) );

		$this->assertSame( RegistrationStatus::Confirmed, $this->book_type( $event_id, $member, 'm1@example.test' )->status() );
		$this->assertSame( RegistrationStatus::Waitlisted, $this->book_type( $event_id, $member, 'm2@example.test' )->status() );
		$this->assertSame( RegistrationStatus::Confirmed, $this->book_type( $event_id, $guest, 'g1@example.test' )->status() );
	}

	/**
	 * A booking of several places is ranked as several.
	 *
	 * @return void
	 */
	public function test_a_booking_for_three_takes_three_of_the_type() {
		$event_id = $this->make_event( array( 'capacity' => 20 ) );
		$type     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Table',
				'capacity' => 4,
			)
		);

		$this->assertSame( RegistrationStatus::Confirmed, $this->book_type( $event_id, $type, 'party@example.test', 3 )->status() );
		$this->assertSame(
			RegistrationStatus::Waitlisted,
			$this->book_type( $event_id, $type, 'late@example.test', 2 )->status(),
			'two more places were sold when one was left'
		);
	}

	/**
	 * Once an event offers types, a booking has to name one.
	 *
	 * @return void
	 */
	public function test_a_booking_must_name_a_type_once_types_exist() {
		$event_id = $this->make_event();

		TicketTypeRepository::insert( $event_id, array( 'name' => 'Standard' ) );

		$result = $this->book( $event_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_ticket_type_required', $result->get_error_code() );
	}

	/**
	 * An archived type cannot be booked, and neither can somebody else's.
	 *
	 * @return void
	 */
	public function test_an_unavailable_type_is_refused() {
		$event_id = $this->make_event();
		$live     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Live' ) );
		$gone     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Withdrawn' ) );
		$theirs   = TicketTypeRepository::insert( $this->make_event(), array( 'name' => 'Somebody elses' ) );

		TicketTypeRepository::archive( $gone );

		foreach ( array( $gone, $theirs, 999999 ) as $unavailable ) {
			$result = $this->book(
				$event_id,
				array(
					'ticket_type_id' => $unavailable,
					'email'          => 'try' . $unavailable . '@example.test',
				)
			);

			$this->assertInstanceOf( \WP_Error::class, $result, 'ticket type ' . $unavailable . ' was accepted' );
			$this->assertSame( 'qevm_ticket_type_unavailable', $result->get_error_code() );
		}

		// The one that is on sale still works.
		$this->assertSame( RegistrationStatus::Confirmed, $this->book_type( $event_id, $live, 'ok@example.test' )->status() );
	}

	/**
	 * A place freed on one type goes to somebody waiting for that type.
	 *
	 * @return void
	 */
	public function test_the_waiting_list_moves_within_its_own_type() {
		$event_id = $this->make_event( array( 'capacity' => 20 ) );
		$member   = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Member',
				'capacity' => 1,
			)
		);
		$guest    = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Guest',
				'capacity' => 1,
			)
		);

		$holder        = $this->book_type( $event_id, $member, 'holder@example.test' );
		$waiting       = $this->book_type( $event_id, $member, 'waiting@example.test' );
		$guest_holder  = $this->book_type( $event_id, $guest, 'guest@example.test' );
		$guest_waiting = $this->book_type( $event_id, $guest, 'guestwait@example.test' );

		$this->assertSame( RegistrationStatus::Waitlisted, $waiting->status(), 'the fixture nobody is waiting' );
		$this->assertSame( RegistrationStatus::Waitlisted, $guest_waiting->status() );

		Repository::update_status( $holder->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $waiting->id() )->status(),
			'the member place was not offered to the member waiting for it'
		);

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $guest_waiting->id() )->status(),
			'a member place was given to somebody waiting for a guest ticket'
		);

		unset( $guest_holder );
	}

	/**
	 * Places remaining is the tighter of the two limits.
	 *
	 * @return void
	 */
	public function test_places_remaining_is_the_tighter_limit() {
		$event_id = $this->make_event( array( 'capacity' => 2 ) );
		$type     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Roomy',
				'capacity' => 50,
			)
		);
		$event    = new Event( $event_id );

		$this->assertSame( 2, RegistrationService::places_remaining( $event, 0, $type ), 'the roomy ticket type overrode the small room' );

		$this->book_type( $event_id, $type, 'one@example.test' );

		$this->assertSame( 1, RegistrationService::places_remaining( $event, 0, $type ) );
		$this->assertFalse( RegistrationService::is_full( $event, 0, $type ) );

		$this->book_type( $event_id, $type, 'two@example.test' );

		$this->assertTrue( RegistrationService::is_full( $event, 0, $type ) );
	}

	/**
	 * Book a place of one type.
	 *
	 * @param int    $event_id Event id.
	 * @param int    $type_id  Ticket type id.
	 * @param string $email    Booker's address.
	 * @param int    $quantity Places.
	 * @return \QuickEventsManager\Registration\Registration
	 */
	private function book_type( $event_id, $type_id, $email, $quantity = 1 ) {
		$result = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $type_id,
				'email'          => $email,
				'quantity'       => $quantity,
			)
		);

		$this->assertNotWPError( $result );

		return $result;
	}
}
