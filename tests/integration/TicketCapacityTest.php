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

		/*
		 * Modules hook themselves up when the plugin boots, which happened
		 * before this option was written. Registering here is what a real
		 * request does on its next load — without it the site has the table and
		 * nothing answering for it.
		 */
		( new TicketsModule() )->register();
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
	 * The form asks which kind of place, and only when there is a choice.
	 *
	 * @return void
	 */
	public function test_the_form_offers_the_ticket_types() {
		$event_id = $this->make_event();
		$member   = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'description' => 'For members',
			)
		);
		$guest    = TicketTypeRepository::insert( $event_id, array( 'name' => 'Guest' ) );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'name="qevm_ticket_type_id"', $markup );
		$this->assertStringContainsString( 'value="' . $member . '"', $markup );
		$this->assertStringContainsString( 'value="' . $guest . '"', $markup );
		$this->assertStringContainsString( 'For members', $markup, 'the description is not shown' );
	}

	/**
	 * An event with no types asks nothing.
	 *
	 * @return void
	 */
	public function test_a_form_without_types_has_no_picker() {
		$this->assertStringNotContainsString( 'name="qevm_ticket_type_id"', $this->render_form( $this->make_event() ) );
	}

	/**
	 * An archived type is not offered.
	 *
	 * @return void
	 */
	public function test_an_archived_type_is_not_offered() {
		$event_id = $this->make_event();
		$live     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Live' ) );
		$gone     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Withdrawn' ) );

		TicketTypeRepository::archive( $gone );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'value="' . $live . '"', $markup );
		$this->assertStringNotContainsString( 'value="' . $gone . '"', $markup );
	}

	/**
	 * How many are left is shown for a single date and withheld for a series.
	 *
	 * A type's remaining places depend on which date is being booked, and on a
	 * series no date has been chosen when the form is built. "Four left" would
	 * be true of at most one week out of twelve.
	 *
	 * @return void
	 */
	public function test_places_left_are_shown_only_when_there_is_one_date() {
		$event_id = $this->make_event( array( 'capacity' => 10 ) );
		$type     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Standard',
				'capacity' => 3,
			)
		);

		$this->book_type( $event_id, $type, 'one@example.test' );

		$this->assertMatchesRegularExpression( '/2 places left/', $this->render_form( $event_id ) );

		// The same event, now with a series of dates on it.
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::START_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::END_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=4' );
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, TicketsModule::ID, \QuickEventsManager\Recurrence\RecurrenceModule::ID ) );

		\QuickEventsManager\Events\OccurrenceSync::sync( $event_id );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'name="qevm_ticket_type_id"', $markup, 'the types went away with the dates' );
		$this->assertDoesNotMatchRegularExpression(
			'/places left/',
			$markup,
			'a count was claimed for a type on an event whose date has not been chosen'
		);
	}

	/**
	 * A full type stays on the form and says so.
	 *
	 * @return void
	 */
	public function test_a_full_type_is_still_offered() {
		$event_id = $this->make_event( array( 'capacity' => 10 ) );
		$type     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Sold out',
				'capacity' => 1,
			)
		);

		$this->book_type( $event_id, $type, 'holder@example.test' );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'value="' . $type . '"', $markup, 'the full type was dropped' );
		$this->assertMatchesRegularExpression( '/waiting list/i', $markup );
	}

	/**
	 * The chosen type survives the transport.
	 *
	 * @return void
	 */
	public function test_the_chosen_type_survives_the_form_transport() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Writing the request the transport is about to read, then putting it back.
		$previous = $_POST;

		$_POST = array(
			'qevm_name'           => 'Booker',
			'qevm_email'          => 'transport@example.test',
			'qevm_ticket_type_id' => '77',
		);

		try {
			$input = \QuickEventsManager\Registration\FormHandler::collect_input();
		} finally {
			$_POST = $previous;
		}

		$this->assertArrayHasKey( 'ticket_type_id', $input, 'the form transport drops the chosen type' );
		$this->assertSame( '77', (string) $input['ticket_type_id'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A type whose window has not opened cannot be booked.
	 *
	 * @return void
	 */
	public function test_a_type_not_yet_on_sale_is_refused() {
		$event_id = $this->make_event();
		$early    = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'            => 'Opens later',
				'sale_starts_utc' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$result = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $early,
				'email'          => 'eager@example.test',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_ticket_type_not_yet', $result->get_error_code() );
	}

	/**
	 * A type whose window has closed cannot be booked.
	 *
	 * The submission arriving a minute after the window shut is the case this
	 * exists for: the form was rendered while it was open.
	 *
	 * @return void
	 */
	public function test_a_type_past_its_window_is_refused() {
		$event_id = $this->make_event();
		$gone     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'          => 'Early bird',
				'sale_ends_utc' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$result = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $gone,
				'email'          => 'late@example.test',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_ticket_type_closed', $result->get_error_code() );
	}

	/**
	 * A type inside its window books normally.
	 *
	 * @return void
	 */
	public function test_a_type_inside_its_window_books() {
		$event_id = $this->make_event();
		$open     = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'            => 'On sale',
				'sale_starts_utc' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'sale_ends_utc'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( RegistrationStatus::Confirmed, $this->book_type( $event_id, $open, 'intime@example.test' )->status() );
	}

	/**
	 * The form drops a closed type and keeps an upcoming one, unselectable.
	 *
	 * "Early bird from Monday" is the reason somebody comes back; a page that
	 * simply omits it looks like a page that forgot. A window that has closed
	 * has nothing useful left to say.
	 *
	 * @return void
	 */
	public function test_the_form_shows_what_opens_later_and_hides_what_has_closed() {
		$event_id = $this->make_event();
		$upcoming = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'            => 'Zephyr later QX3',
				'sale_starts_utc' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$closed   = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'          => 'Zephyr gone QX4',
				'sale_ends_utc' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);
		$open     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Zephyr open QX5' ) );

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'Zephyr open QX5', $markup );
		$this->assertStringContainsString( 'Zephyr later QX3', $markup, 'a type opening later vanished instead of saying when' );
		$this->assertStringNotContainsString( 'Zephyr gone QX4', $markup, 'a type whose sale has ended is still offered' );
		$this->assertMatchesRegularExpression( '/On sale from/', $markup );

		// The upcoming one is present and cannot be chosen.
		$this->assertMatchesRegularExpression(
			'/value="' . $upcoming . '"[^>]*disabled/',
			$markup,
			'a type that is not on sale yet can be selected'
		);
		$this->assertDoesNotMatchRegularExpression( '/value="' . $open . '"[^>]*disabled/', $markup );

		unset( $closed );
	}

	/**
	 * The box stores a price typed in whole units, and a window in the event's zone.
	 *
	 * @return void
	 */
	public function test_the_box_stores_a_price_and_a_window() {
		$event_id = $this->make_event();

		// The box checks who is saving, which the rest of this class never needs.
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1,
			)
		);

		wp_set_current_user( empty( $administrators ) ? 1 : (int) $administrators[0] );

		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::TIMEZONE, 'Asia/Kolkata' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Writing the request the box verifies for itself, then putting it back.
		$previous = $_POST;

		$_POST = wp_slash(
			array(
				'qevm_ticket_types_nonce' => wp_create_nonce( \QuickEventsManager\Tickets\TicketTypesBox::NONCE ),
				'qevm_ticket_types'       => array(
					array(
						'id'        => '0',
						'name'      => 'Priced',
						'price'     => '19.99',
						'capacity'  => '',
						'sale_ends' => '2027-12-31T23:59',
						'active'    => '1',
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

		$types = TicketTypeRepository::for_event( $event_id );

		$this->assertCount( 1, $types );

		/*
		 * 19.99 and not 12.10. The first version used 12.10, which is the one
		 * value in the range that cannot tell truncation from rounding — both
		 * give 1210 — so the sabotage that truncates passed. 19.99 truncates to
		 * 1998 and rounds to 1999.
		 */
		$this->assertSame( 1999, $types[0]->price_minor(), 'the price lost a penny on the way in' );
		$this->assertSame(
			'2027-12-31 18:29:59',
			$types[0]->sale_ends_utc(),
			'the end of the 31st in Kolkata is 18:29:59 UTC'
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
