<?php
/**
 * Ticket types: the table, the repository, and the box that edits them.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

/*
 * This file writes the requests the box verifies for itself: every $_POST below
 * is built a line earlier and restored a line later. One test exists precisely
 * to prove the box refuses a request with no nonce.
 */
// phpcs:disable WordPress.Security.NonceVerification.Missing -- See the note above.

use QuickEventsManager\Domain\TicketTypeStatus;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketTypesBox;
use QuickEventsManager\Tickets\TicketsModule;

/**
 * What a ticket type is, and what may be done to one.
 *
 * The load-bearing behaviour here is not the CRUD. It is that a type somebody
 * holds a ticket of cannot be deleted, that a type belongs to the event it was
 * created on and no other, and that a form claiming otherwise is not believed.
 */
final class TicketTypesTest extends TestCase {

	/**
	 * Switch ticketing on, and make sure the table is there.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enable_registration();

		update_option(
			QEVM_OPTION_MODULES,
			array( \QuickEventsManager\Registration\RegistrationModule::ID, TicketsModule::ID )
		);

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

		wp_set_current_user( $this->an_administrator() );
	}

	/**
	 * The table exists with the columns the repository writes.
	 *
	 * @return void
	 */
	public function test_the_table_has_the_columns_it_writes() {
		global $wpdb;

		$table   = TicketTypeRepository::table();
		$columns = $wpdb->get_col( "DESCRIBE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading our own schema.

		foreach ( array( 'id', 'event_id', 'occurrence_id', 'name', 'description', 'price_minor', 'currency', 'capacity', 'min_per_order', 'max_per_order', 'sale_starts_utc', 'sale_ends_utc', 'sort_order', 'status', 'created_at', 'updated_at' ) as $column ) {
			$this->assertContains( $column, $columns, $column . ' is missing' );
		}
	}

	/**
	 * A type is stored, read back, and ordered.
	 *
	 * @return void
	 */
	public function test_types_are_stored_in_order() {
		$event_id = $this->make_event();

		$member = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'     => 'Member',
				'capacity' => 20,
			)
		);
		$guest  = TicketTypeRepository::insert( $event_id, array( 'name' => 'Guest' ) );

		$this->assertGreaterThan( 0, $member );
		$this->assertGreaterThan( 0, $guest );

		$types = TicketTypeRepository::for_event( $event_id );

		$this->assertCount( 2, $types );
		$this->assertSame( 'Member', $types[0]->name() );
		$this->assertSame( 'Guest', $types[1]->name() );
		$this->assertSame( 20, $types[0]->capacity() );
		$this->assertSame( 0, $types[1]->capacity(), 'no capacity means as many as the event allows' );
		$this->assertLessThan( $types[1]->sort_order(), $types[0]->sort_order(), 'the second type did not go after the first' );
	}

	/**
	 * Free is a price of zero, not a different kind of thing.
	 *
	 * @return void
	 */
	public function test_free_is_a_price_of_zero() {
		$event_id = $this->make_event();

		$free = TicketTypeRepository::find( TicketTypeRepository::insert( $event_id, array( 'name' => 'Free' ) ) );
		$paid = TicketTypeRepository::find(
			TicketTypeRepository::insert(
				$event_id,
				array(
					'name'        => 'Paid',
					'price_minor' => 1250,
				)
			)
		);

		$this->assertNotNull( $free );
		$this->assertNotNull( $paid );
		$this->assertTrue( $free->is_free() );
		$this->assertSame( 0, $free->price_minor() );
		$this->assertFalse( $paid->is_free() );
		$this->assertSame( 1250, $paid->price_minor(), 'money is stored in minor units, as an integer' );
	}

	/**
	 * A negative price is a mistake, not a discount.
	 *
	 * @return void
	 */
	public function test_a_negative_price_is_refused() {
		global $wpdb;

		$event_id = $this->make_event();

		/*
		 * Under MySQL's default settings the unsigned column quietly stores 0
		 * whatever PHP hands it, so this passed with the clamp deleted — the
		 * column was doing the work and the test could not tell. In strict
		 * mode, which plenty of hosts run, the same insert *fails* and the type
		 * is silently never created. That is the behaviour worth pinning, so
		 * the session says so.
		 */
		$previous_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );

		$wpdb->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A fixed string, setting up the condition under test.

		try {
			$type = TicketTypeRepository::find(
				TicketTypeRepository::insert(
					$event_id,
					array(
						'name'        => 'Odd',
						'price_minor' => -500,
					)
				)
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $previous_mode ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Putting the session back.
		}

		$this->assertNotNull( $type, 'a negative price lost the whole ticket type on a strict-mode database' );
		$this->assertSame( 0, $type->price_minor() );
	}

	/**
	 * A type with no name is not a type.
	 *
	 * @return void
	 */
	public function test_a_nameless_type_is_not_stored() {
		$event_id = $this->make_event();

		$this->assertSame( 0, TicketTypeRepository::insert( $event_id, array( 'name' => '   ' ) ) );
		$this->assertCount( 0, TicketTypeRepository::for_event( $event_id ) );
	}

	/**
	 * Archiving withdraws a type without touching what was sold.
	 *
	 * @return void
	 */
	public function test_archiving_withdraws_a_type_from_sale() {
		$event_id = $this->make_event();
		$id       = TicketTypeRepository::insert( $event_id, array( 'name' => 'Early bird' ) );

		$this->assertTrue( TicketTypeRepository::archive( $id ) );

		$type = TicketTypeRepository::find( $id );

		$this->assertNotNull( $type );
		$this->assertSame( TicketTypeStatus::Archived, $type->status() );
		$this->assertFalse( $type->is_sellable() );

		$this->assertCount( 0, TicketTypeRepository::for_event( $event_id, true ), 'an archived type is still on sale' );
		$this->assertCount( 1, TicketTypeRepository::for_event( $event_id ), 'an archived type vanished from the editor' );

		TicketTypeRepository::restore( $id );

		$this->assertCount( 1, TicketTypeRepository::for_event( $event_id, true ) );
	}

	/**
	 * A type somebody holds a ticket of is archived rather than deleted.
	 *
	 * The gate's third criterion, checked at the level that decides it.
	 *
	 * @return void
	 */
	public function test_a_type_in_use_is_archived_not_deleted() {
		$event_id = $this->make_event();
		$id       = TicketTypeRepository::insert( $event_id, array( 'name' => 'Sold' ) );

		$registration = $this->book( $event_id, array( 'ticket_type_id' => $id ) );

		$this->assertNotWPError( $registration );

		$attendees = AttendeeRepository::for_registration( $registration->id() );

		$this->assertNotEmpty( $attendees, 'the fixture booked nobody' );
		$this->assertSame( $id, $attendees[0]->ticket_type_id(), 'the booking did not put the type on the person' );

		$this->assertTrue( TicketTypeRepository::is_in_use( $id ) );
		$this->assertFalse( TicketTypeRepository::delete( $id ), 'a type in use reported itself deleted' );

		$type = TicketTypeRepository::find( $id );

		$this->assertNotNull( $type, 'a type somebody holds a ticket of was deleted' );
		$this->assertSame( TicketTypeStatus::Archived, $type->status() );

		// And the ticket still names it.
		$attendee = AttendeeRepository::find( $attendees[0]->id() );

		$this->assertNotNull( $attendee );
		$this->assertSame( $id, $attendee->ticket_type_id(), 'the ticket stopped naming its type' );
	}

	/**
	 * A type nobody holds is removed outright.
	 *
	 * @return void
	 */
	public function test_an_unused_type_is_deleted() {
		$event_id = $this->make_event();
		$id       = TicketTypeRepository::insert( $event_id, array( 'name' => 'Never sold' ) );

		$this->assertTrue( TicketTypeRepository::delete( $id ) );
		$this->assertNull( TicketTypeRepository::find( $id ) );
	}

	/**
	 * The box stores what was submitted.
	 *
	 * @return void
	 */
	public function test_the_box_stores_submitted_types() {
		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'id'          => '0',
					'name'        => 'Member',
					'description' => 'For members',
					'capacity'    => '20',
					'active'      => '1',
				),
				array(
					'id'       => '0',
					'name'     => 'Guest',
					'capacity' => '',
					'active'   => '1',
				),
				array(
					'id'       => '0',
					'name'     => '',
					'capacity' => '',
				),
			)
		);

		$types = TicketTypeRepository::for_event( $event_id );

		$this->assertCount( 2, $types, 'the blank row became a type' );
		$this->assertSame( 'Member', $types[0]->name() );
		$this->assertSame( 'For members', $types[0]->description() );
		$this->assertSame( 20, $types[0]->capacity() );
		$this->assertSame( 'Guest', $types[1]->name() );
	}

	/**
	 * Clearing a name removes the type.
	 *
	 * @return void
	 */
	public function test_clearing_a_name_removes_the_type() {
		$event_id = $this->make_event();
		$id       = TicketTypeRepository::insert( $event_id, array( 'name' => 'Going' ) );

		$this->submit(
			$event_id,
			array(
				array(
					'id'       => (string) $id,
					'name'     => '',
					'capacity' => '',
				),
			)
		);

		$this->assertNull( TicketTypeRepository::find( $id ) );
	}

	/**
	 * Unticking "on sale" archives rather than deletes.
	 *
	 * @return void
	 */
	public function test_unticking_on_sale_archives() {
		$event_id = $this->make_event();
		$id       = TicketTypeRepository::insert( $event_id, array( 'name' => 'Paused' ) );

		$this->submit(
			$event_id,
			array(
				array(
					'id'       => (string) $id,
					'name'     => 'Paused',
					'capacity' => '',
				),
			)
		);

		$type = TicketTypeRepository::find( $id );

		$this->assertNotNull( $type );
		$this->assertSame( TicketTypeStatus::Archived, $type->status() );
	}

	/**
	 * A submitted id belonging to another event is not believed.
	 *
	 * @return void
	 */
	public function test_a_type_from_another_event_cannot_be_rewritten() {
		$mine   = $this->make_event();
		$theirs = $this->make_event();
		$id     = TicketTypeRepository::insert(
			$theirs,
			array(
				'name'     => 'Theirs',
				'capacity' => 5,
			)
		);

		$this->submit(
			$mine,
			array(
				array(
					'id'       => (string) $id,
					'name'     => 'Mine now',
					'capacity' => '999',
					'active'   => '1',
				),
			)
		);

		$untouched = TicketTypeRepository::find( $id );

		$this->assertNotNull( $untouched );
		$this->assertSame( 'Theirs', $untouched->name(), 'another event rewrote this type' );
		$this->assertSame( 5, $untouched->capacity() );
		$this->assertSame( $theirs, $untouched->event_id() );

		// The row was added to the event that was actually being saved.
		$this->assertCount( 1, TicketTypeRepository::for_event( $mine ) );
	}

	/**
	 * Without a nonce the box stores nothing.
	 *
	 * @return void
	 */
	public function test_the_box_needs_its_nonce() {
		$event_id = $this->make_event();

		$previous = $_POST;
		$_POST    = wp_slash(
			array(
				'qevm_ticket_types' => array(
					array(
						'id'     => '0',
						'name'   => 'Sneaky',
						'active' => '1',
					),
				),
			)
		);

		try {
			( new TicketTypesBox() )->save( $event_id, get_post( $event_id ) );
		} finally {
			$_POST = $previous;
		}

		$this->assertCount( 0, TicketTypeRepository::for_event( $event_id ) );
	}

	/**
	 * Submit the box.
	 *
	 * @param int                               $event_id Event id.
	 * @param array<int, array<string, string>> $rows Submitted rows.
	 * @return void
	 */
	private function submit( $event_id, array $rows ) {
		$previous = $_POST;

		$_POST = wp_slash(
			array(
				'qevm_ticket_types_nonce' => wp_create_nonce( TicketTypesBox::NONCE ),
				'qevm_ticket_types'       => $rows,
			)
		);

		try {
			( new TicketTypesBox() )->save( $event_id, get_post( $event_id ) );
		} finally {
			$_POST = $previous;
		}
	}

	/**
	 * An administrator id.
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
