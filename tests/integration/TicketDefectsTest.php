<?php
/**
 * Defects a review found in the ticketing work, each reproduced before it was fixed.
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
 * Six findings, six failures, six fixes.
 *
 * Every test here was written against the code as it stood and watched to fail
 * before anything was changed. That order matters more than usual: these came
 * from a review rather than from building the feature, and a test written after
 * the fix would only prove that the fix is still there — not that the defect was
 * real.
 */
final class TicketDefectsTest extends TestCase {

	/**
	 * Both modules on, table present.
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

		/*
		 * Modules hook themselves up when the plugin boots, which happened
		 * before this option was written — so a test that switches ticketing on
		 * by writing the option alone gets a site with the table but no filter
		 * answering for it. Registering here is what a real request does on its
		 * next load.
		 */
		( new TicketsModule() )->register();
	}

	/**
	 * A place freed on the event's capacity reaches somebody waiting for another type.
	 *
	 * Two uncapped types share one room. The room fills, somebody waits, and a
	 * cancellation frees a seat that only the event's limit was holding — so
	 * the person waiting must get it, whatever ticket either of them holds.
	 *
	 * @return void
	 */
	public function test_a_freed_seat_reaches_a_waiter_holding_another_type() {
		$event_id = $this->make_event( array( 'capacity' => 2 ) );
		$member   = TicketTypeRepository::insert( $event_id, array( 'name' => 'Member' ) );
		$guest    = TicketTypeRepository::insert( $event_id, array( 'name' => 'Guest' ) );

		$alice = $this->book_type( $event_id, $member, 'alice@example.test' );
		$bob   = $this->book_type( $event_id, $member, 'bob@example.test' );
		$carol = $this->book_type( $event_id, $guest, 'carol@example.test' );

		$this->assertSame( RegistrationStatus::Confirmed, $alice->status() );
		$this->assertSame( RegistrationStatus::Confirmed, $bob->status() );
		$this->assertSame( RegistrationStatus::Waitlisted, $carol->status(), 'the fixture did not fill the room' );

		Repository::update_status( $alice->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $carol->id() )->status(),
			'a seat freed on the event capacity was offered only to the same ticket type, and stays empty'
		);
	}

	/**
	 * A queue for a full ticket type does not block a different queue.
	 *
	 * @return void
	 */
	public function test_a_full_type_does_not_hold_up_another_queue() {
		$event_id = $this->make_event( array( 'capacity' => 10 ) );
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

		$holder         = $this->book_type( $event_id, $member, 'holder@example.test' );
		$member_waiting = $this->book_type( $event_id, $member, 'waiting@example.test' );
		$guest_holder   = $this->book_type( $event_id, $guest, 'guestholder@example.test' );
		$guest_waiting  = $this->book_type( $event_id, $guest, 'guestwaiting@example.test' );

		$this->assertSame( RegistrationStatus::Waitlisted, $member_waiting->status() );
		$this->assertSame( RegistrationStatus::Waitlisted, $guest_waiting->status() );

		// A guest place comes free. The member queue is still full and is ahead in line.
		Repository::update_status( $guest_holder->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $guest_waiting->id() )->status(),
			'a guest place was held up by a member queue that could not move'
		);

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $member_waiting->id() )->status(),
			'the member queue moved although no member place came free'
		);

		unset( $holder );
	}

	/**
	 * The organiser's own form can say which ticket and which date.
	 *
	 * @return void
	 */
	public function test_the_admin_form_can_say_which_ticket_and_which_date() {
		$event_id = $this->make_event();
		$type     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Zephyr standard QX7' ) );

		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1,
			)
		);

		wp_set_current_user( empty( $administrators ) ? 1 : (int) $administrators[0] );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Writing the request the screen reads.
		$previous = $_GET;
		$_GET     = array( 'event_id' => (string) $event_id );

		ob_start();

		try {
			( new \QuickEventsManager\Registration\AttendeesScreen() )->render();
		} finally {
			$markup = (string) ob_get_clean();
			$_GET   = $previous;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->assertStringContainsString(
			'qevm_ticket_type_id',
			$markup,
			'the organiser cannot say which kind of place a phone booking is for, and the service will refuse it'
		);
		$this->assertStringContainsString( 'Zephyr standard QX7', $markup );

		unset( $type );
	}

	/**
	 * A manual booking that names a type is accepted and keeps it.
	 *
	 * @return void
	 */
	public function test_a_manual_booking_can_name_a_ticket_type() {
		$event_id = $this->make_event();
		$type     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Standard' ) );

		$result = ( new RegistrationService() )->create(
			$event_id,
			array(
				'name'           => 'Phone booking',
				'email'          => 'phone@example.test',
				'quantity'       => 1,
				'ticket_type_id' => $type,
			),
			RegistrationService::CONTEXT_MANUAL
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $type, $result->ticket_type_id() );
	}

	/**
	 * Switching ticketing off stops types being demanded of anybody.
	 *
	 * The organiser who tries the feature, decides against it and switches it
	 * off must not be left with a public form that insists on a choice and an
	 * editor with no way to remove it.
	 *
	 * @return void
	 */
	public function test_switching_the_module_off_stops_types_being_required() {
		$event_id = $this->make_event();

		TicketTypeRepository::insert( $event_id, array( 'name' => 'Member' ) );

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		remove_filter( 'qevm_event_ticket_types', array( TicketsModule::class, 'supply_types' ), 10 );

		$result = $this->book( $event_id, array( 'email' => 'after@example.test' ) );

		$this->assertNotWPError( $result, 'the form still demands a ticket type with the module switched off' );

		$this->assertStringNotContainsString(
			'name="qevm_ticket_type_id"',
			$this->render_form( $event_id ),
			'the public form still offers types the site has switched off'
		);
	}

	/**
	 * An uncapped type does not print the room's remaining count as its own.
	 *
	 * @return void
	 */
	public function test_an_uncapped_type_claims_no_count_of_its_own() {
		$event_id = $this->make_event( array( 'capacity' => 5 ) );

		TicketTypeRepository::insert( $event_id, array( 'name' => 'Member' ) );
		TicketTypeRepository::insert( $event_id, array( 'name' => 'Guest' ) );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'name="qevm_ticket_type_id"', $markup, 'the fixture offered no types at all' );

		/*
		 * Counted inside the ticket options rather than across the page: the
		 * form has its own "Only 5 places left" line above, which is the room's
		 * number and is correct where it is.
		 */
		$this->assertSame(
			0,
			substr_count( $markup, 'qevm-ticket-option__state' ),
			'each uncapped type printed the whole room\'s remaining count, which reads as twice the places'
		);
	}

	/**
	 * A series with no bookable date left is not treated as a single-date event.
	 *
	 * @return void
	 */
	public function test_a_series_with_no_dates_left_claims_no_per_type_count() {
		$event_id = $this->make_event( array( 'capacity' => 20 ) );
		$type     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Standard',
				'capacity' => 3,
			)
		);

		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::START_LOCAL, '2027-06-01 18:00:00' );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::END_LOCAL, '2027-06-01 19:00:00' );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::START_UTC, '2027-06-01 18:00:00' );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::END_UTC, '2027-06-01 19:00:00' );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=3' );
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, TicketsModule::ID, \QuickEventsManager\Recurrence\RecurrenceModule::ID ) );

		\QuickEventsManager\Events\OccurrenceSync::sync( $event_id );

		$dates = \QuickEventsManager\Events\OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 3, $dates, 'the fixture built no series' );

		// Every date is called off, so none can be booked - but the event is not over.
		foreach ( $dates as $date ) {
			\QuickEventsManager\Recurrence\OccurrenceEditor::cancel( $date->id() );
		}

		$markup = $this->render_form( $event_id );

		$this->assertSame(
			0,
			substr_count( $markup, 'places left' ),
			'with no date to book, a per-type count was printed from the whole series'
		);

		unset( $type );
	}

	/**
	 * The ticket types box refuses a field submitted as an array.
	 *
	 * @return void
	 */
	public function test_the_box_refuses_a_field_that_is_not_a_string() {
		$event_id = $this->make_event();

		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1,
			)
		);

		wp_set_current_user( empty( $administrators ) ? 1 : (int) $administrators[0] );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Writing the request the box verifies for itself.
		$previous = $_POST;

		$_POST = wp_slash(
			array(
				'qevm_ticket_types_nonce' => wp_create_nonce( \QuickEventsManager\Tickets\TicketTypesBox::NONCE ),
				'qevm_ticket_types'       => array(
					array(
						'id'       => '0',
						'name'     => array( 'x' => 'y' ),
						'capacity' => array( '3' ),
						'active'   => '1',
					),
				),
			)
		);

		try {
			( new \QuickEventsManager\Tickets\TicketTypesBox() )->save( $event_id, get_post( $event_id ) );
		} finally {
			$_POST = $previous;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->assertCount(
			0,
			TicketTypeRepository::for_event( $event_id ),
			'a ticket type called "Array" was created from a hand-made request'
		);
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
	 * Book a place of one type.
	 *
	 * @param int    $event_id Event id.
	 * @param int    $type_id  Ticket type id.
	 * @param string $email    Booker's address.
	 * @return \QuickEventsManager\Registration\Registration
	 */
	private function book_type( $event_id, $type_id, $email ) {
		$result = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $type_id,
				'email'          => $email,
			)
		);

		$this->assertNotWPError( $result );

		return $result;
	}
}
