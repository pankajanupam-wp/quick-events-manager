<?php
/**
 * The QR codes that travel with a confirmation.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CheckIn\CheckInModule;
use QuickEventsManager\CheckIn\CheckInRepository;
use QuickEventsManager\CheckIn\CheckInService;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\AttendeeRepository;

/**
 * Whether somebody who books actually receives something a door can scan.
 *
 * The stage gate proved the encoder draws a real symbol and the door admits a
 * code typed into it. Neither proved the code ever reaches the person holding
 * the ticket — the one step between them was the untested one, and the email
 * tests could not have caught it because they run with check-in switched off.
 */
final class TicketAttachmentTest extends TestCase {

	/**
	 * Attachments PHPMailer was given during the last send.
	 *
	 * PHPMailer's own shape: 0 body-or-path, 1 filename, 2 name, 3 encoding,
	 * 4 MIME type, 5 whether it was given as a string, 6 disposition, 7 cid.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private $attachments = array();

	/**
	 * Switch check-in on as well, and watch what reaches the mailer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->quieten_registration();

		/*
		 * Clear the wording filters *first*. The module registers onto
		 * `qevm_attendee_email` itself, and doing it the other way round
		 * removes the thing under test — which is how the first run of this
		 * file failed while the code was correct.
		 */
		remove_all_filters( 'qevm_attendee_email' );
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$this->switch_module_on( CheckInModule::ID );
		( new CheckInModule() )->register();
		add_filter( 'wp_mail_from', static fn() => 'events@example.com' );

		$this->attachments = array();

		add_action(
			'phpmailer_init',
			function ( $phpmailer ) {
				$this->attachments = $phpmailer->getAttachments();
				$phpmailer->clearAllRecipients();
			},
			99
		);
	}

	/**
	 * Everybody on the booking gets their own code.
	 *
	 * Three places is three people arriving separately, so one file each,
	 * named by the code it carries — not one ticket for the person who paid.
	 *
	 * @return void
	 */
	public function test_a_confirmation_carries_one_ticket_per_person() {
		$registration = $this->book( $this->make_event(), array( 'quantity' => 3 ) );

		$this->assertNotWPError( $registration );
		$this->assertSame( RegistrationStatus::Confirmed, $registration->status() );

		$this->drain();

		$tickets = $this->tickets();

		$this->assertCount( 3, $tickets, 'three places, three tickets' );

		$codes = array();

		foreach ( AttendeeRepository::for_registration( $registration->id() ) as $attendee ) {
			$codes[] = $attendee->ticket_code();
		}

		sort( $codes );

		$named = array();

		foreach ( $tickets as $ticket ) {
			$named[] = str_replace( '.svg', '', (string) $ticket[2] );

			$this->assertStringStartsWith( 'image/svg+xml', (string) $ticket[4] );
			$this->assertStringContainsString( '<svg', (string) $ticket[0], 'the file should be a drawing, not a promise of one' );
			$this->assertTrue( (bool) $ticket[5], 'sent as a string, not read from disk' );
		}

		sort( $named );

		$this->assertSame( $codes, $named, 'each file should be named by the code inside it' );
	}

	/**
	 * A waiting place is not admission.
	 *
	 * @return void
	 */
	public function test_a_waiting_list_place_carries_no_ticket() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$this->book( $event_id, array( 'email' => 'first@example.com' ) );

		$this->drain();

		$this->attachments = array();

		$waiting = $this->book( $event_id, array( 'email' => 'second@example.com' ) );

		$this->assertSame( RegistrationStatus::Waitlisted, $waiting->status() );

		$this->drain();

		$this->assertSame( array(), $this->tickets(), 'nothing to admit yet' );
	}

	/**
	 * With check-in off, a confirmation is just a confirmation.
	 *
	 * The module boundary, observed from outside: no setting, no branch in the
	 * registration module — the hook simply is not there.
	 *
	 * @return void
	 */
	public function test_no_ticket_travels_when_check_in_is_off() {
		remove_filter( 'qevm_email_attachments', array( CheckInModule::class, 'attach_tickets' ), 10 );

		$this->book( $this->make_event() );

		$this->drain();

		$this->assertSame( array(), $this->tickets(), 'a module that is off attaches nothing' );
	}

	/**
	 * The code in the message is the code the door takes.
	 *
	 * The whole point of printing it. A code that reads back differently from
	 * the one the door accepts is worse than no code at all, because it fails
	 * in front of a queue of people.
	 *
	 * @return void
	 */
	public function test_a_code_read_out_of_the_email_opens_the_door() {
		$event_id = $this->make_event();

		$this->switch_module_on( CheckInModule::ID );

		if ( ! CheckInRepository::table_exists() ) {
			( new CheckInModule() )->activate();
		}

		$registration = $this->book( $event_id );

		$body = $this->last_body();

		$expected = AttendeeRepository::for_registration( $registration->id() )[0]->ticket_code();

		$this->assertStringContainsString( $expected, $body, 'the message should say the code' );

		/*
		 * Read back off the page the way a person would, hyphen included — a
		 * pattern that stopped at the hyphen typed half a code in and got a
		 * refusal, which is exactly what a doorman squinting at a phone would
		 * have got.
		 */
		preg_match( '/[A-Z0-9-]{6,}/', substr( $body, strpos( $body, $expected ) ), $found );

		$this->assertSame( $expected, $found[0], 'what is on the page should be the whole code' );

		$outcome = CheckInService::admit_by_code( $found[0], 0, 'manual' );

		$this->assertSame( CheckInService::ADMITTED, $outcome['result'], 'typing what the email said should admit them' );
	}

	/**
	 * Three people, three codes, each against a name.
	 *
	 * One code for a booking of three is one person getting in.
	 *
	 * @return void
	 */
	public function test_every_person_on_the_booking_is_named_with_their_own_code() {
		$registration = $this->book(
			$this->make_event(),
			array(
				'quantity' => 2,
				'guests'   => array( array( 'name' => 'Grace Hopper' ) ),
			)
		);

		$body = $this->last_body();

		foreach ( AttendeeRepository::for_registration( $registration->id() ) as $attendee ) {
			$this->assertStringContainsString( $attendee->ticket_code(), $body, 'every code should be in the message' );
			$this->assertStringContainsString( $attendee->name(), $body, 'and beside the name it belongs to' );
		}
	}

	/**
	 * With check-in off, no codes are printed either.
	 *
	 * @return void
	 */
	public function test_no_codes_are_printed_when_check_in_is_off() {
		remove_filter( 'qevm_attendee_email', array( CheckInModule::class, 'add_ticket_codes' ), 10 );

		$registration = $this->book( $this->make_event() );

		$code = AttendeeRepository::for_registration( $registration->id() )[0]->ticket_code();

		$this->assertStringNotContainsString( $code, $this->last_body(), 'a module that is off says nothing' );
	}

	/**
	 * A template writer is offered the placeholder that will substitute.
	 *
	 * @return void
	 */
	public function test_the_placeholder_is_offered_to_the_template_editor() {
		$this->assertArrayHasKey( 'ticket_codes', \QuickEventsManager\Email\Templates::placeholders() );
	}

	/**
	 * The body of the message this booking queued.
	 *
	 * Read off the queue rather than out of the mailer, because the wording is
	 * decided when the message is written and the attachments are not.
	 *
	 * @return string
	 */
	private function last_body() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reading the plugin's own queue.
		return (string) $wpdb->get_var(
			'SELECT body FROM ' . $wpdb->prefix . 'qevm_email_queue ORDER BY id DESC LIMIT 1'
		);
	}

	/**
	 * Send everything sitting on the queue.
	 *
	 * @return void
	 */
	private function drain() {
		\QuickEventsManager\Email\Worker::run( 5 );
	}

	/**
	 * The QR files out of what the mailer was holding.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function tickets() {
		$tickets = array();

		foreach ( $this->attachments as $file ) {
			if ( isset( $file[4] ) && false !== strpos( (string) $file[4], 'image/svg' ) ) {
				$tickets[] = $file;
			}
		}

		return $tickets;
	}
}
