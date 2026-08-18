<?php
/**
 * Emailing everybody who registered.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\EmailStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Email\Broadcast;
use QuickEventsManager\Email\Queue;
use QuickEventsManager\Email\Worker;
use QuickEventsManager\Registration\Repository;

/**
 * The one action in this plugin that cannot be taken back.
 *
 * Most of these are about who is *not* emailed. Sending to somebody who
 * cancelled, or telling a waiting list "see you tomorrow", is not a cosmetic
 * bug — it is the plugin making a promise on the organiser's behalf that the
 * organiser did not make.
 */
final class BroadcastTest extends TestCase {

	/**
	 * Stop anything actually leaving the machine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter( 'pre_wp_mail', array( $this, 'accept_mail' ), 10, 2 );

		/*
		 * The rate limiter, and nothing else. quieten_registration() would also
		 * silence the confirmation emails, and one test here exists to prove a
		 * withdrawal does not touch them — it needs them queued.
		 */
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
	}

	/**
	 * Addresses handed to wp_mail() during a test.
	 *
	 * @var string[]
	 */
	private $delivered = array();

	/**
	 * Record a send instead of making one.
	 *
	 * @param null|bool            $short_circuit Whatever an earlier filter decided.
	 * @param array<string, mixed> $attributes    Mail attributes.
	 * @return bool
	 */
	public function accept_mail( $short_circuit, $attributes ) {
		unset( $short_circuit );

		foreach ( (array) ( $attributes['to'] ?? array() ) as $address ) {
			$this->delivered[] = (string) $address;
		}

		return true;
	}

	/**
	 * A message reaches everybody with a confirmed place, once each.
	 *
	 * @return void
	 */
	public function test_a_broadcast_reaches_every_confirmed_attendee() {
		$event_id = $this->make_event();

		$this->book(
			$event_id,
			array(
				'name'  => 'Priya',
				'email' => 'priya@example.com',
			)
		);
		$this->book(
			$event_id,
			array(
				'name'  => 'Sam',
				'email' => 'sam@example.com',
			)
		);
		$this->book(
			$event_id,
			array(
				'name'  => 'Lee',
				'email' => 'lee@example.com',
			)
		);

		$result = Broadcast::send( $event_id, 'confirmed', $this->message() );

		$this->assertNotWPError( $result );
		$this->assertSame( 3, $result['recipients'] );
		$this->assertSame( 3, $result['queued'] );

		$queued = $this->broadcast_rows( $event_id );

		$this->assertCount( 3, $queued );
		$this->assertEqualsCanonicalizing(
			array( 'priya@example.com', 'sam@example.com', 'lee@example.com' ),
			array_map( static fn ( $row ) => (string) $row['recipient'], $queued )
		);
	}

	/**
	 * Somebody who booked twice is emailed once.
	 *
	 * @return void
	 */
	public function test_one_person_with_two_bookings_is_emailed_once() {
		$event_id = $this->make_event();

		/*
		 * Inserted rather than booked. The service refuses a second booking on
		 * an address already registered for the event, so the only way to reach
		 * this state through the form is the way real sites reach it: the same
		 * person spelling their address differently, or an organiser entering a
		 * booking somebody had already made. Both land as two rows.
		 */
		$this->insert_registration( $event_id, 'priya@example.com' );
		$this->insert_registration( $event_id, 'PRIYA@example.com' );
		$this->insert_registration( $event_id, 'sam@example.com' );

		$this->assertSame( 3, Repository::count_for_event( $event_id ), 'the fixture did not make three bookings' );

		$result = Broadcast::send( $event_id, 'confirmed', $this->message() );

		$this->assertSame( 2, $result['queued'] );

		$recipients = array_map(
			static fn ( $row ) => strtolower( (string) $row['recipient'] ),
			$this->broadcast_rows( $event_id )
		);

		$this->assertSame( count( $recipients ), count( array_unique( $recipients ) ) );
	}

	/**
	 * A cancelled booking is in no audience at all.
	 *
	 * @return void
	 */
	public function test_a_cancelled_booking_is_never_emailed() {
		$event_id = $this->make_event();

		$staying = $this->book( $event_id, array( 'email' => 'staying@example.com' ) );
		$gone    = $this->book( $event_id, array( 'email' => 'gone@example.com' ) );

		$this->assertNotWPError( $staying );
		$this->assertNotWPError( $gone );

		Repository::update_status( $gone->id(), RegistrationStatus::Cancelled );

		foreach ( array_keys( Broadcast::audiences() ) as $audience ) {
			Queue::cancel_for_context( Broadcast::CONTEXT, $event_id );

			$result = Broadcast::send( $event_id, $audience, $this->message() );

			$recipients = array_map(
				static fn ( $row ) => (string) $row['recipient'],
				array_filter(
					$this->broadcast_rows( $event_id ),
					static fn ( $row ) => EmailStatus::Cancelled->value !== (string) $row['status']
				)
			);

			$this->assertNotWPError( $result );
			$this->assertContains( 'staying@example.com', $recipients, $audience . ' left out a confirmed place' );
			$this->assertNotContains( 'gone@example.com', $recipients, $audience . ' emailed somebody who cancelled' );
		}
	}

	/**
	 * The default audience leaves the waiting list out.
	 *
	 * A place somebody does not have is the worst thing an event email can
	 * imply, so "confirmed" has to mean confirmed.
	 *
	 * @return void
	 */
	public function test_the_default_audience_excludes_the_waiting_list() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$waitlisted = $this->book( $event_id, array( 'email' => 'queued@example.com' ) );

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			$waitlisted->status(),
			'the fixture did not produce a waitlisted booking'
		);

		Broadcast::send( $event_id, 'confirmed', $this->message() );

		$recipients = array_map( static fn ( $row ) => (string) $row['recipient'], $this->broadcast_rows( $event_id ) );

		$this->assertSame( array( 'first@example.com' ), $recipients );

		Queue::cancel_for_context( Broadcast::CONTEXT, $event_id );

		Broadcast::send( $event_id, 'waiting', $this->message() );

		$recipients = array_map(
			static fn ( $row ) => (string) $row['recipient'],
			array_filter(
				$this->broadcast_rows( $event_id ),
				static fn ( $row ) => EmailStatus::Cancelled->value !== (string) $row['status']
			)
		);

		$this->assertEqualsCanonicalizing(
			array( 'first@example.com', 'queued@example.com' ),
			$recipients,
			'the waiting list audience did not include the waiting list'
		);
	}

	/**
	 * An audience nobody defined sends nothing.
	 *
	 * The failure worth designing out: an unrecognised value must not fall
	 * through to the largest possible group.
	 *
	 * @return void
	 */
	public function test_an_unknown_audience_sends_to_nobody() {
		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'priya@example.com' ) );

		$result = Broadcast::send( $event_id, 'everybody-in-the-world', $this->message() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->broadcast_rows( $event_id ) );
	}

	/**
	 * Each message is addressed to the person who gets it.
	 *
	 * @return void
	 */
	public function test_placeholders_are_filled_in_per_recipient() {
		$event_id = $this->make_event( array( 'title' => 'Autumn meetup' ) );

		$this->book(
			$event_id,
			array(
				'name'  => 'Priya Raman',
				'email' => 'priya@example.com',
			)
		);
		$this->book(
			$event_id,
			array(
				'name'  => 'Sam Okoro',
				'email' => 'sam@example.com',
			)
		);

		Broadcast::send(
			$event_id,
			'confirmed',
			array(
				'subject' => 'About {event_title}',
				'body'    => 'Hi {attendee_name}, your reference is {reference}.',
				'format'  => 'text',
			)
		);

		$bodies = array();

		foreach ( $this->broadcast_rows( $event_id ) as $row ) {
			$bodies[ (string) $row['recipient'] ] = (string) $row['body'];

			$this->assertSame( 'About Autumn meetup', (string) $row['subject'] );
		}

		$this->assertStringContainsString( 'Hi Priya Raman,', $bodies['priya@example.com'] );
		$this->assertStringContainsString( 'Hi Sam Okoro,', $bodies['sam@example.com'] );
		$this->assertStringNotContainsString( 'Priya', $bodies['sam@example.com'] );
	}

	/**
	 * A name with markup in it cannot inject into an HTML broadcast.
	 *
	 * The same rule as the templates, checked again here because this renderer
	 * is reached by a different path: the body is trusted because a capability
	 * wrote it, and the values are not because a public form did.
	 *
	 * @return void
	 */
	public function test_a_value_cannot_inject_markup_into_an_html_broadcast() {
		$event_id = $this->make_event();

		$this->book(
			$event_id,
			array(
				'name'  => '<script>alert(1)</script>Priya',
				'email' => 'priya@example.com',
			)
		);

		Broadcast::send(
			$event_id,
			'confirmed',
			array(
				'subject' => 'Notice',
				'body'    => '<p>Hello {attendee_name}</p>',
				'format'  => 'html',
			)
		);

		$rows = $this->broadcast_rows( $event_id );
		$body = (string) $rows[0]['body'];

		$this->assertStringContainsString( '<p>Hello', $body, 'the message lost its own markup' );
		$this->assertStringNotContainsString( '<script>', $body );
	}

	/**
	 * A script tag in the message itself is not queued either.
	 *
	 * @return void
	 */
	public function test_a_script_tag_in_the_message_is_stripped() {
		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'priya@example.com' ) );

		Broadcast::send(
			$event_id,
			'confirmed',
			array(
				'subject' => 'Notice',
				'body'    => '<p>Fine</p><script>alert(1)</script>',
				'format'  => 'html',
			)
		);

		$rows = $this->broadcast_rows( $event_id );

		$this->assertStringContainsString( '<p>Fine</p>', (string) $rows[0]['body'] );
		$this->assertStringNotContainsString( '<script>', (string) $rows[0]['body'] );
	}

	/**
	 * With no queue to put them on, nothing is sent and it says so.
	 *
	 * Found on the dev site rather than here: a site that enabled registration
	 * before the queue table existed never got one, because a module's tables
	 * are created when it is switched on. `send()` reported a cheerful "queued
	 * for 0 people" and the organiser had no idea why nothing arrived.
	 *
	 * There is deliberately no fallback to a direct `wp_mail()` here, the way
	 * there is for a single confirmation. Four hundred synchronous sends is the
	 * thing the queue exists to prevent.
	 *
	 * @return void
	 */
	public function test_a_missing_queue_is_reported_rather_than_sending_nothing() {
		global $wpdb;

		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'priya@example.com' ) );

		$table = Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture standing in for a site that upgraded without the table being created; the name comes from Installer, never from input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$this->delivered = array();

		$result = Broadcast::send( $event_id, 'confirmed', $this->message() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_queue_unavailable', $result->get_error_code() );
		$this->assertSame( array(), $this->delivered, 'a missing queue fell back to sending inline' );

		$this->restore_schema();
	}

	/**
	 * A message with nothing in it is refused rather than sent.
	 *
	 * @return void
	 */
	public function test_an_empty_message_is_refused() {
		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'priya@example.com' ) );

		$result = Broadcast::send(
			$event_id,
			'confirmed',
			array(
				'subject' => 'Subject but no body',
				'body'    => '   ',
				'format'  => 'text',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->broadcast_rows( $event_id ) );
	}

	/**
	 * Withdrawing a broadcast leaves the confirmations alone.
	 *
	 * The reason broadcasts have their own context. Sharing `event` with the
	 * confirmations would make this button cancel somebody's booking
	 * confirmation as a side effect of the organiser fixing a typo.
	 *
	 * @return void
	 */
	public function test_withdrawing_a_broadcast_leaves_confirmations_alone() {
		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'priya@example.com' ) );

		$confirmations = count( Queue::for_context( 'event', $event_id ) );

		$this->assertGreaterThan( 0, $confirmations, 'the fixture queued no confirmation to protect' );

		Broadcast::send( $event_id, 'confirmed', $this->message() );

		$this->assertSame( 1, Broadcast::withdraw( $event_id ) );

		foreach ( $this->broadcast_rows( $event_id ) as $row ) {
			$this->assertSame( EmailStatus::Cancelled->value, (string) $row['status'] );
		}

		foreach ( Queue::for_context( 'event', $event_id ) as $row ) {
			$this->assertNotSame(
				EmailStatus::Cancelled->value,
				(string) $row['status'],
				'withdrawing a broadcast cancelled a registration confirmation'
			);
		}
	}

	/**
	 * Five hundred people are queued in one request and sent over several.
	 *
	 * The Stage 5 gate, from the sending end: the request that presses the
	 * button returns having sent nothing, and the queue carries a row per
	 * recipient that the worker drains afterwards.
	 *
	 * @return void
	 */
	public function test_five_hundred_attendees_queue_without_sending() {
		$event_id = $this->make_event();

		for ( $i = 0; $i < 500; $i++ ) {
			$this->insert_registration( $event_id, 'bulk' . $i . '@example.com' );
		}

		$this->delivered = array();

		$result = Broadcast::send( $event_id, 'confirmed', $this->message() );

		$this->assertSame( 500, $result['recipients'] );
		$this->assertSame( 500, $result['queued'] );
		$this->assertSame( array(), $this->delivered, 'queueing sent mail inside the request' );

		$counts = Queue::counts_for_context( Broadcast::CONTEXT, $event_id );

		$this->assertSame( 500, $counts[ EmailStatus::Pending->value ] );

		$sent = 0;

		for ( $run = 0; $run < 40 && $sent < 500; $run++ ) {
			$outcome = Worker::run( 5 );
			$sent   += $outcome['sent'];
		}

		$this->assertSame( 500, $sent, 'the queue did not drain' );
		$this->assertCount( 500, array_unique( $this->delivered ), 'somebody was emailed twice' );
	}

	/**
	 * The count on the button and the number queued come from the same rule.
	 *
	 * @return void
	 */
	public function test_the_count_matches_what_is_queued() {
		$event_id = $this->make_event();

		$this->insert_registration( $event_id, 'one@example.com' );
		$this->insert_registration( $event_id, 'two@example.com' );
		$this->insert_registration( $event_id, 'ONE@example.com' );

		$before = Broadcast::count( $event_id, 'confirmed' );

		$this->assertSame( 2, $before, 'the count did not fold the duplicate address' );
		$result = Broadcast::send( $event_id, 'confirmed', $this->message() );

		$this->assertSame( $before, $result['queued'] );
	}

	/**
	 * A test send goes to one address and is not part of the broadcast.
	 *
	 * @return void
	 */
	public function test_a_test_send_is_separate_from_the_real_thing() {
		$event_id = $this->make_event();

		$this->book(
			$event_id,
			array(
				'name'  => 'Priya Raman',
				'email' => 'priya@example.com',
			)
		);

		$this->assertTrue(
			Broadcast::send_test( $event_id, 'organiser@example.com', $this->message( 'Hello {attendee_name}' ) )
		);

		$this->assertSame( array(), $this->broadcast_rows( $event_id ), 'a test was queued as a broadcast' );

		$tests = Queue::for_context( Broadcast::TEST_CONTEXT, $event_id );

		$this->assertCount( 1, $tests );
		$this->assertSame( 'organiser@example.com', (string) $tests[0]['recipient'] );
		$this->assertStringContainsString( 'Hello Priya Raman', (string) $tests[0]['body'], 'the test rendered no placeholders' );

		$this->assertSame( 0, Broadcast::withdraw( $event_id ), 'withdrawing a broadcast took the test with it' );
	}

	/**
	 * Every recipient has its own record of what happened to it.
	 *
	 * @return void
	 */
	public function test_each_recipient_has_its_own_record() {
		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'one@example.com' ) );
		$this->book( $event_id, array( 'email' => 'two@example.com' ) );

		Broadcast::send( $event_id, 'confirmed', $this->message() );

		Worker::run( 5 );

		$counts = Queue::counts_for_context( Broadcast::CONTEXT, $event_id );

		$this->assertSame( 2, $counts[ EmailStatus::Sent->value ] );

		foreach ( $this->broadcast_rows( $event_id ) as $row ) {
			$this->assertNotSame( '', (string) $row['sent_at'], 'a sent message recorded no time' );
		}
	}

	/**
	 * A broadcast carries no calendar file.
	 *
	 * @return void
	 */
	public function test_a_broadcast_gets_no_calendar_attachment() {
		$event_id = $this->make_event();

		$this->book( $event_id, array( 'email' => 'priya@example.com' ) );

		Broadcast::send( $event_id, 'confirmed', $this->message() );

		$rows = $this->broadcast_rows( $event_id );

		$this->assertSame(
			array(),
			apply_filters( 'qevm_email_attachments', array(), $rows[0] ),
			'a broadcast would have attached a calendar file'
		);
	}

	/**
	 * The broadcast rows for an event.
	 *
	 * @param int $event_id Event id.
	 * @return array<int, array<string, mixed>>
	 */
	private function broadcast_rows( $event_id ) {
		return array_values( Queue::for_context( Broadcast::CONTEXT, (int) $event_id ) );
	}

	/**
	 * A usable message.
	 *
	 * @param string $body Body, or '' for a default.
	 * @return array<string, string>
	 */
	private function message( $body = '' ) {
		return array(
			'subject' => 'The venue has changed',
			'body'    => '' !== $body ? $body : 'We have moved to the hall next door.',
			'format'  => 'text',
		);
	}

	/**
	 * Insert a confirmed booking directly.
	 *
	 * Five hundred bookings through RegistrationService would also queue five
	 * hundred confirmations, and this test is about the broadcast rather than
	 * about them.
	 *
	 * @param int    $event_id Event id.
	 * @param string $email    Address.
	 * @return void
	 */
	private function insert_registration( $event_id, $email ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk fixture.
		$wpdb->insert(
			Repository::table(),
			array(
				'event_id'     => (int) $event_id,
				'code'         => Repository::generate_code(),
				'status'       => RegistrationStatus::Confirmed->value,
				'quantity'     => 1,
				'booker_name'  => 'Attendee',
				'booker_email' => $email,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
	}
}
