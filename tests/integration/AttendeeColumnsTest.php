<?php
/**
 * What the attendee screen and the export say a booking is for.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Registration\AttendeesScreen;
use QuickEventsManager\Registration\Exporter;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;

/**
 * The gap this closes: both dimensions existed in the data and neither reached
 * the organiser.
 *
 * A booking has carried a date since one chunk and a ticket type since another,
 * and the screen an organiser actually uses showed neither — so sixty people
 * across twelve weeks and three kinds of place read as sixty identical rows.
 * Every check here is "would somebody standing at a door be able to tell".
 */
final class AttendeeColumnsTest extends TestCase {

	/**
	 * Switch the three modules on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enable_registration();

		update_option(
			QEVM_OPTION_MODULES,
			array( RegistrationModule::ID, TicketsModule::ID, RecurrenceModule::ID )
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
	 * An ordinary event gains no columns it has nothing to put in.
	 *
	 * @return void
	 */
	public function test_a_plain_event_gains_no_columns() {
		$event_id = $this->make_event();

		$this->book( $event_id );

		$markup = $this->render_screen( $event_id );

		$this->assertStringContainsString( 'Reference', $markup, 'the fixture rendered no table at all' );
		$this->assertStringNotContainsString( '>Date</th>', $markup );
		$this->assertStringNotContainsString( '>Ticket</th>', $markup );
	}

	/**
	 * A series shows which date each booking is for.
	 *
	 * @return void
	 */
	public function test_a_series_shows_the_date() {
		$event_id = $this->make_series();
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->book(
			$event_id,
			array(
				'occurrence_id' => $dates[2]->id(),
				'email'         => 'third@example.test',
			)
		);

		$markup = $this->render_screen( $event_id );

		$this->assertStringContainsString( '>Date</th>', $markup );

		/*
		 * Scoped to the rows. The add-attendee form below the table offers
		 * every date in a select, so asking the whole page whether it mentions
		 * the first date stopped meaning anything the moment that form gained
		 * its own date picker.
		 */
		$rows = $this->table_body( $markup );

		$this->assertStringContainsString( $dates[2]->format_start(), $rows, 'the booking does not say which date it is for' );
		$this->assertStringNotContainsString( $dates[0]->format_start(), $rows, 'a row says a date nobody booked' );
	}

	/**
	 * Ticket types show, including one that has been archived.
	 *
	 * @return void
	 */
	public function test_ticket_types_show_even_when_archived() {
		$event_id = $this->make_event();

		/*
		 * Deliberately unlovely names. The first version of this test used
		 * "Member" and "Guest" and passed with the archived type excluded from
		 * the lookup entirely — because this screen's add-attendee form labels
		 * extra places "Guest", so the assertion was matching the furniture
		 * rather than the ticket column.
		 */
		$member = TicketTypeRepository::insert( $event_id, array( 'name' => 'Zephyr member QX1' ) );
		$guest  = TicketTypeRepository::insert( $event_id, array( 'name' => 'Zephyr guest QX2' ) );

		$this->book(
			$event_id,
			array(
				'ticket_type_id' => $member,
				'email'          => 'member@example.test',
			)
		);
		$this->book(
			$event_id,
			array(
				'ticket_type_id' => $guest,
				'email'          => 'guest@example.test',
			)
		);

		TicketTypeRepository::archive( $guest );

		$markup = $this->render_screen( $event_id );

		$this->assertStringContainsString( '>Ticket</th>', $markup );
		$this->assertStringContainsString( 'Zephyr member QX1', $markup );
		$this->assertStringContainsString(
			'Zephyr guest QX2',
			$markup,
			'an archived type stopped naming the tickets somebody already holds'
		);
		$this->assertStringNotContainsString(
			'Removed',
			$markup,
			'a ticket somebody holds was reported as removed merely because the type was withdrawn'
		);
	}

	/**
	 * A booking made before the choice existed says so.
	 *
	 * @return void
	 */
	public function test_a_booking_without_a_type_reads_as_standard() {
		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'early@example.test' ) );

		// The type arrives after the booking, which is the ordinary way round.
		TicketTypeRepository::insert( $event_id, array( 'name' => 'Member' ) );

		$markup = $this->render_screen( $event_id );

		$this->assertStringContainsString( '>Ticket</th>', $markup );
		$this->assertStringContainsString( 'Standard', $markup );
	}

	/**
	 * The export carries the same two columns.
	 *
	 * @return void
	 */
	public function test_the_export_carries_both() {
		$event_id = $this->make_series();
		$dates    = OccurrenceRepository::for_event( $event_id );
		$type     = TicketTypeRepository::insert( $event_id, array( 'name' => 'Concession' ) );

		$this->book(
			$event_id,
			array(
				'occurrence_id'  => $dates[1]->id(),
				'ticket_type_id' => $type,
				'email'          => 'both@example.test',
			)
		);

		$csv = $this->export( $event_id );

		$this->assertStringContainsString( 'Date', $csv );
		$this->assertStringContainsString( 'Ticket', $csv );
		$this->assertStringContainsString( $dates[1]->start_local(), $csv, 'the export does not say which date' );
		$this->assertStringContainsString( 'Concession', $csv, 'the export does not say which ticket' );
	}

	/**
	 * The export of a plain event is unchanged.
	 *
	 * @return void
	 */
	public function test_a_plain_export_gains_no_columns() {
		$event_id = $this->make_event();

		$this->book( $event_id );

		$csv  = $this->export( $event_id );
		$line = strtok( $csv, "\n" );

		$this->assertStringNotContainsString( 'Ticket', (string) $line );
		$this->assertStringNotContainsString( 'Date', (string) $line );
	}

	/**
	 * The screen's markup for an event.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private function render_screen( $event_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Writing the request the screen reads, then putting it back.
		$previous = $_GET;
		$_GET     = array( 'event_id' => (string) $event_id );

		ob_start();

		try {
			( new AttendeesScreen() )->render();
		} finally {
			$markup = (string) ob_get_clean();
			$_GET   = $previous;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $markup;
	}

	/**
	 * Just the rows of the attendee table.
	 *
	 * @param string $markup The whole screen.
	 * @return string
	 */
	private function table_body( $markup ) {
		$start = strpos( $markup, '<tbody>' );
		$end   = strpos( $markup, '</tbody>' );

		$this->assertNotFalse( $start, 'the screen rendered no table body' );
		$this->assertNotFalse( $end );

		return substr( $markup, $start, $end - $start );
	}

	/**
	 * The CSV for an event.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private function export( $event_id ) {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An in-memory stream, not a file.

		$this->assertIsResource( $handle );

		Exporter::write( $handle, $event_id );

		rewind( $handle );

		$csv = (string) stream_get_contents( $handle );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- As above.

		return $csv;
	}

	/**
	 * A weekly series with dates on it.
	 *
	 * @return int
	 */
	private function make_series() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, Meta::START_LOCAL, '2027-04-06 18:00:00' );
		update_post_meta( $event_id, Meta::END_LOCAL, '2027-04-06 19:00:00' );
		update_post_meta( $event_id, Meta::START_UTC, '2027-04-06 18:00:00' );
		update_post_meta( $event_id, Meta::END_UTC, '2027-04-06 19:00:00' );
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=4' );

		OccurrenceSync::sync( $event_id );

		return $event_id;
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
