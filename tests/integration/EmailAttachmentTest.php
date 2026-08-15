<?php
/**
 * The calendar file that travels with a confirmation.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\Repository;

/**
 * Attaching an .ics without going near the filesystem.
 */
final class EmailAttachmentTest extends TestCase {

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
	 * Watch what actually reaches PHPMailer.
	 *
	 * Asserting on the filtered array would only prove the plugin agrees with
	 * itself. The question is whether the mailer ends up holding the file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->quieten_registration();

		remove_all_filters( 'qevm_attendee_email' );
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		/*
		 * wp-env's site URL is `localhost`, with no dot in it, so the default
		 * sender works out to `wordpress@localhost` — which PHPMailer rejects
		 * as an invalid address. wp_mail() then returns false *before* firing
		 * `phpmailer_init`, and every assertion here would fail while pointing
		 * at the attachment code, which is fine. A sender with a real-looking
		 * domain is all it takes.
		 */
		add_filter( 'wp_mail_from', static fn() => 'events@example.com' );

		$this->attachments = array();

		add_action(
			'phpmailer_init',
			function ( $phpmailer ) {
				$this->attachments = $phpmailer->getAttachments();

				/*
				 * Then stop it going any further. There is no mail transport
				 * inside the container, so letting the send proceed writes
				 * "sendmail: can't connect to remote host" to stderr for every
				 * message — noise that buries a real failure in CI output.
				 * With no recipients PHPMailer throws in preSend(), which
				 * wp_mail() catches, so nothing reaches the transport at all.
				 */
				$phpmailer->clearAllRecipients();
			},
			// After the plugin's own listener, so it sees what that added.
			99
		);
	}

	/**
	 * A confirmed booking gets the event as a calendar file.
	 *
	 * @return void
	 */
	public function test_a_confirmation_carries_the_calendar_file() {
		$registration = $this->book( $this->make_event() );

		$this->assertNotWPError( $registration );
		$this->assertSame( RegistrationStatus::Confirmed, $registration->status() );

		$this->assertCount( 1, $this->captured(), 'exactly one attachment' );

		$attachment = $this->captured()[0];

		$this->assertStringEndsWith( '.ics', $attachment[2], 'the filename should say what it is' );
		$this->assertStringStartsWith( 'text/calendar', $attachment[4] );
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $attachment[0] );
		$this->assertStringContainsString( 'END:VCALENDAR', $attachment[0] );
		$this->assertTrue( (bool) $attachment[5], 'it must be sent as a string, not read from disk' );
	}

	/**
	 * A waiting list place gets no calendar file.
	 *
	 * An .ics says "this is happening and you are going". Putting a
	 * provisional place into somebody's calendar is how a person turns up to
	 * an event they were never confirmed for.
	 *
	 * @return void
	 */
	public function test_a_waiting_list_place_carries_no_calendar_file() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$this->book( $event_id, array( 'email' => 'first@example.com' ) );

		$this->attachments = array();

		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );

		$this->assertSame( RegistrationStatus::Waitlisted, $second->status() );
		$this->assertSame( array(), $this->captured() );
	}

	/**
	 * Being promoted brings the calendar file with it.
	 *
	 * @return void
	 */
	public function test_promotion_carries_the_calendar_file() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first = $this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$this->book( $event_id, array( 'email' => 'second@example.com' ) );

		$this->attachments = array();

		Repository::update_status( $first->id(), RegistrationStatus::Cancelled );

		$this->assertCount( 1, $this->captured(), 'a promoted place is a real place' );
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $this->captured()[0][0] );
	}

	/**
	 * An event with no date attaches nothing rather than an empty calendar.
	 *
	 * @return void
	 */
	public function test_an_undated_event_attaches_nothing() {
		$event_id = $this->make_event();

		delete_post_meta( $event_id, \QuickEventsManager\Events\Meta::START_UTC );
		delete_post_meta( $event_id, \QuickEventsManager\Events\Meta::END_UTC );

		$this->attachments = array();

		$this->book( $event_id );

		$this->assertSame( array(), $this->captured() );
	}

	/**
	 * The listener does not survive the send.
	 *
	 * `phpmailer_init` fires for every email the site sends. A listener left
	 * attached would staple this event's calendar file to password resets and
	 * every other plugin's mail.
	 *
	 * @return void
	 */
	public function test_the_attachment_does_not_leak_into_the_next_email() {
		$this->book( $this->make_event() );

		$this->assertCount( 1, $this->captured(), 'the booking should have attached one' );

		$this->attachments = array();

		wp_mail( 'somebody@example.com', 'Unrelated', 'Nothing to do with events.' );

		$this->assertSame(
			array(),
			$this->captured(),
			'an unrelated email must not carry an event calendar'
		);
	}

	/**
	 * What PHPMailer was holding, read through a call so that static analysis
	 * does not narrow the property to the empty array a test just reset it to.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function captured() {
		return $this->attachments;
	}
}
