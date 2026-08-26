<?php
/**
 * Bookings an organiser enters on somebody's behalf.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\Emails;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;

/**
 * Phone calls, walk-ins and the sign-up sheet at the door.
 */
final class ManualEntryTest extends TestCase {

	/**
	 * Enter a booking the way the admin screen does.
	 *
	 * @param int                  $event_id Event.
	 * @param array<string, mixed> $input    Overrides.
	 * @return \QuickEventsManager\Registration\Registration|\WP_Error
	 */
	private function add( $event_id, array $input = array() ) {
		return ( new RegistrationService() )->create(
			$event_id,
			wp_parse_args(
				$input,
				array(
					'name'     => 'Grace Hopper',
					'email'    => 'grace@example.com',
					'phone'    => '',
					'quantity' => 1,
				)
			),
			RegistrationService::CONTEXT_MANUAL
		);
	}

	/**
	 * A manual entry becomes a real booking with real attendee rows.
	 *
	 * @return void
	 */
	public function test_a_manual_entry_is_a_real_booking() {
		$this->quieten_registration();

		$event_id     = $this->make_event( array( 'capacity' => 10 ) );
		$registration = $this->add( $event_id, array( 'quantity' => 2 ) );

		$this->assertNotWPError( $registration );
		$this->assertSame( RegistrationStatus::Confirmed, $registration->status() );
		$this->assertSame( 2, Repository::count_taken( $event_id ) );
		$this->assertSame( 2, AttendeeRepository::count_for_registration( $registration->id() ) );
	}

	/**
	 * Capacity still applies, so a manual entry can be waitlisted.
	 *
	 * The room is the same size whichever way somebody got into it.
	 *
	 * @return void
	 */
	public function test_a_manual_entry_respects_capacity() {
		$this->quieten_registration();

		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first = $this->add( $event_id, array( 'email' => 'first@example.com' ) );

		$this->assertNotWPError( $first );
		$this->assertSame( RegistrationStatus::Confirmed, $first->status() );

		$second = $this->add( $event_id, array( 'email' => 'second@example.com' ) );

		$this->assertNotWPError( $second );
		$this->assertSame(
			RegistrationStatus::Waitlisted,
			$second->status(),
			'a manual entry must not oversell the room'
		);
	}

	/**
	 * No consent record is invented for somebody who never saw the wording.
	 *
	 * A fabricated consent record is worse than none: it survives an audit.
	 *
	 * @return void
	 */
	public function test_a_manual_entry_records_no_consent() {
		$this->quieten_registration();

		$event_id = $this->make_event();

		$public = $this->book( $event_id, array( 'email' => 'public@example.com' ) );

		$this->assertNotWPError( $public );
		$this->assertNotSame( '', $public->consent_version(), 'the public form should record consent' );

		$manual = $this->add( $event_id, array( 'email' => 'manual@example.com' ) );

		$this->assertNotWPError( $manual );
		$this->assertSame( '', $manual->consent_version() );
		$this->assertFalse( $manual->has_consent() );
	}

	/**
	 * A manual entry is not attributed to the administrator who typed it.
	 *
	 * Otherwise every event an organiser helps with becomes a booking of their
	 * own, and "my registrations" is nonsense for the one person who uses the
	 * admin most.
	 *
	 * @return void
	 */
	public function test_a_manual_entry_belongs_to_nobody() {
		$this->quieten_registration();

		$admin = wp_insert_user(
			array(
				'user_login' => 'organiser_' . wp_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'organiser' . wp_rand( 1000, 9999 ) . '@example.com',
				'role'       => 'administrator',
			)
		);

		$this->assertNotWPError( $admin );

		wp_set_current_user( (int) $admin );

		$registration = $this->add( $this->make_event() );

		$this->assertNotWPError( $registration );
		$this->assertSame( 0, (int) $registration->get( 'user_id' ) );

		wp_set_current_user( 0 );
		wp_delete_user( (int) $admin );
	}

	/**
	 * The rate limit does not apply to somebody typing.
	 *
	 * An organiser entering a paper sign-up sheet looks exactly like a script:
	 * thirty entries in a few minutes from one address.
	 *
	 * @return void
	 */
	public function test_the_rate_limit_does_not_stop_an_organiser() {
		add_filter( 'qevm_attendee_email', '__return_empty_array' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$event_id = $this->make_event( array( 'capacity' => 0 ) );

		// Deliberately not quietened: the limit is what is under test.
		for ( $i = 0; $i < RegistrationService::RATE_LIMIT + 5; $i++ ) {
			$result = $this->add( $event_id, array( 'email' => 'person' . $i . '@example.com' ) );

			$this->assertNotWPError( $result, 'entry ' . $i . ' was refused' );
		}

		$this->assertSame( RegistrationService::RATE_LIMIT + 5, count( Repository::for_event( $event_id, array( 'per_page' => 100 ) ) ) );
	}

	/**
	 * Registration being closed does not stop the organiser.
	 *
	 * The phone call that starts "I know it says it's closed, but" is the whole
	 * reason manual entry exists, and it arrives after the closing date by
	 * definition.
	 *
	 * @return void
	 */
	public function test_a_closed_event_still_accepts_a_manual_entry() {
		$this->quieten_registration();

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::REGISTRATION_ENABLED, '' );

		$this->assertFalse( RegistrationService::is_open( new Event( $event_id ) ) );

		$registration = $this->add( $event_id );

		$this->assertNotWPError( $registration );
		$this->assertSame( RegistrationStatus::Confirmed, $registration->status() );
	}

	/**
	 * A draft event accepts manual entries; the public form does not.
	 *
	 * @return void
	 */
	public function test_a_draft_event_accepts_a_manual_entry_but_not_a_public_one() {
		$this->quieten_registration();

		$event_id = $this->make_event( array( 'status' => 'draft' ) );

		$public = $this->book( $event_id );

		$this->assertInstanceOf( \WP_Error::class, $public );
		$this->assertSame( 'qevm_event_not_found', $public->get_error_code() );

		$manual = $this->add( $event_id );

		$this->assertNotWPError( $manual );
	}

	/**
	 * The same address is still refused twice on one event.
	 *
	 * @return void
	 */
	public function test_a_manual_entry_still_refuses_a_duplicate_address() {
		$this->quieten_registration();

		$event_id = $this->make_event();

		$this->assertNotWPError( $this->add( $event_id ) );

		$second = $this->add( $event_id );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'qevm_already_registered', $second->get_error_code() );
	}

	/**
	 * Resending the confirmation sends the email and nothing else.
	 *
	 * It must not re-fire `qevm_registration_created`: that would run every
	 * listener a second time — a second organiser notification, and whatever
	 * the site has added of its own.
	 *
	 * @return void
	 */
	public function test_resending_a_confirmation_creates_nothing() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$rows    = count( Repository::for_event( $event_id ) );
		$created = 0;
		$sent    = array();

		add_action(
			'qevm_registration_created',
			static function () use ( &$created ) {
				++$created;
			}
		);

		remove_all_filters( 'qevm_attendee_email' );

		add_filter(
			'qevm_attendee_email',
			static function ( $email ) use ( &$sent ) {
				$sent[] = $email['to'];

				$email['to'] = '';

				return $email;
			}
		);

		( new Emails() )->send_attendee_confirmation( $registration, new Event( $event_id ) );

		$this->assertSame( array( $registration->booker_email() ), $sent );
		$this->assertSame( 0, $created, 'resending must not look like a new registration' );
		$this->assertSame( $rows, count( Repository::for_event( $event_id ) ) );
		$this->assertSame( 1, Repository::count_taken( $event_id ) );
	}

	/**
	 * A manual entry with the email switched off writes the booking anyway.
	 *
	 * @return void
	 */
	public function test_suppressing_the_email_still_stores_the_booking() {
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$sent = 0;

		add_filter(
			'qevm_attendee_email',
			static function ( $email ) use ( &$sent ) {
				++$sent;

				return $email;
			},
			1
		);

		// What the screen does when "Email the attendee" is unticked.
		add_filter( 'qevm_attendee_email', '__return_empty_array', 99 );

		$event_id     = $this->make_event();
		$registration = $this->add( $event_id );

		$this->assertNotWPError( $registration );
		$this->assertSame( 1, Repository::count_taken( $event_id ) );
		$this->assertSame(
			1,
			$sent,
			'the filter should still run, so nothing else listening is skipped'
		);
	}
}
