<?php
/**
 * Withdrawing from an event, end to end.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\AttendeeStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Frontend\Renderer;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\CancellationHandler;
use QuickEventsManager\Registration\CancellationLink;
use QuickEventsManager\Registration\Repository;

/**
 * The cancellation link, against real rows.
 */
final class CancellationTest extends TestCase {

	/**
	 * Leave no query arguments behind for the next test to read.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$_GET[ CancellationLink::QUERY_VAR ],
			$_GET[ CancellationLink::EXPIRES_VAR ],
			$_GET[ CancellationLink::TOKEN_VAR ],
			$_GET[ CancellationHandler::RESULT_ARG ],
			$_GET['qevm_message']
		);

		parent::tearDown();
	}

	/**
	 * A link is issued for a real booking and verifies against it.
	 *
	 * @return void
	 */
	public function test_a_booking_gets_a_link_that_verifies() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$url = CancellationLink::url( $registration, new Event( $event_id ) );

		$args = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		$this->assertSame( $registration->code(), $args[ CancellationLink::QUERY_VAR ] );
		$this->assertTrue(
			CancellationLink::verify(
				$args[ CancellationLink::QUERY_VAR ],
				$args[ CancellationLink::EXPIRES_VAR ],
				$args[ CancellationLink::TOKEN_VAR ]
			)
		);
	}

	/**
	 * The link expires when the event ends.
	 *
	 * @return void
	 */
	public function test_the_link_expires_when_the_event_does() {
		$event_id = $this->make_event( array( 'starts_in' => WEEK_IN_SECONDS ) );
		$event    = new Event( $event_id );

		$this->assertSame(
			strtotime( $event->end_utc() . ' UTC' ),
			CancellationLink::expiry( $event )
		);
	}

	/**
	 * Cancelling frees the places and cancels everybody on the booking.
	 *
	 * The stage gate: a three-place booking must free three places, not one.
	 *
	 * @return void
	 */
	public function test_cancelling_a_three_place_booking_frees_three_places() {
		$this->quieten_registration();

		$event_id     = $this->make_event( array( 'capacity' => 10 ) );
		$registration = $this->book( $event_id, array( 'quantity' => 3 ) );

		$this->assertNotWPError( $registration );
		$this->assertSame( 3, Repository::count_taken( $event_id ) );
		$this->assertSame( 3, AttendeeRepository::count_for_registration( $registration->id() ) );

		$this->assertTrue( Repository::update_status( $registration->id(), RegistrationStatus::Cancelled ) );

		$this->assertSame( 0, Repository::count_taken( $event_id ), 'all three places should be free again' );

		foreach ( AttendeeRepository::for_registration( $registration->id() ) as $attendee ) {
			$this->assertSame(
				AttendeeStatus::Cancelled,
				$attendee->status(),
				'a cancelled booking must not leave an admissible ticket behind'
			);
		}
	}

	/**
	 * Cancelling stamps the time it happened, and reinstating clears it.
	 *
	 * The column existed from C1.6 and nothing had ever written to it.
	 *
	 * @return void
	 */
	public function test_cancelling_records_when() {
		$this->quieten_registration();

		$registration = $this->book( $this->make_event() );

		$this->assertNotWPError( $registration );
		$this->assertSame( '', (string) $this->cancelled_at( $registration->id() ) );

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );

		$stamped = $this->cancelled_at( $registration->id() );

		$this->assertNotEmpty( $stamped, 'cancelled_at was never written' );
		$this->assertLessThanOrEqual(
			2,
			abs( time() - (int) strtotime( $stamped . ' UTC' ) ),
			'cancelled_at should be now, in UTC'
		);

		Repository::update_status( $registration->id(), RegistrationStatus::Confirmed );

		$this->assertNull(
			$this->cancelled_at( $registration->id() ),
			'a reinstated booking must not keep a cancellation date'
		);
	}

	/**
	 * The status-changed action fires wherever the change came from.
	 *
	 * It used to fire in the attendees screen, so a cancellation from a link
	 * would have been silent — and waitlist promotion listens to it.
	 *
	 * @return void
	 */
	public function test_the_status_action_fires_from_the_repository() {
		$this->quieten_registration();

		$registration = $this->book( $this->make_event() );

		$this->assertNotWPError( $registration );

		$seen = array();

		add_action(
			'qevm_registration_status_changed',
			static function ( $id, $status, $previous ) use ( &$seen ) {
				$seen[] = array( $id, $status->value, $previous->value );
			},
			10,
			3
		);

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			array( array( $registration->id(), 'confirmed', 'cancelled' ) ),
			array_map(
				static fn( $entry ) => array( $entry[0], $entry[2], $entry[1] ),
				$seen
			),
			'the hook should report both what it is now and what it was'
		);
	}

	/**
	 * Setting the status to what it already is is a success, not a failure.
	 *
	 * Clicking a cancellation link twice is the most likely thing a person
	 * does when they are not sure the first click worked.
	 *
	 * @return void
	 */
	public function test_cancelling_twice_is_not_an_error() {
		$this->quieten_registration();

		$registration = $this->book( $this->make_event() );

		$this->assertNotWPError( $registration );
		$this->assertTrue( Repository::update_status( $registration->id(), RegistrationStatus::Cancelled ) );
		$this->assertTrue(
			Repository::update_status( $registration->id(), RegistrationStatus::Cancelled ),
			'a no-op status change is the outcome the caller asked for'
		);
	}

	/**
	 * A status change fires the action once, not once per attempt.
	 *
	 * @return void
	 */
	public function test_an_unchanged_status_fires_nothing() {
		$this->quieten_registration();

		$registration = $this->book( $this->make_event() );

		$this->assertNotWPError( $registration );

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );

		$fired = 0;

		add_action(
			'qevm_registration_status_changed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );

		$this->assertSame( 0, $fired, 'nothing changed, so nothing should have been announced' );
	}

	/**
	 * A forged signature does not reach the booking.
	 *
	 * @return void
	 */
	public function test_a_forged_link_cancels_nothing() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$_GET[ CancellationLink::QUERY_VAR ]   = $registration->code();
		$_GET[ CancellationLink::EXPIRES_VAR ] = (string) ( time() + HOUR_IN_SECONDS );
		$_GET[ CancellationLink::TOKEN_VAR ]   = str_repeat( 'f', 64 );

		$state = CancellationHandler::current_state();

		$this->assertSame( 'error', $state['state'] );
		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $registration->id() )->status(),
			'looking at a forged link must not change anything'
		);
	}

	/**
	 * Following a genuine link shows a confirmation rather than acting.
	 *
	 * A GET that cancels is a booking that cancels itself the moment a mail
	 * scanner or a link prefetcher follows the URL.
	 *
	 * @return void
	 */
	public function test_following_the_link_asks_before_it_acts() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$expires = CancellationLink::expiry( new Event( $event_id ) );

		$_GET[ CancellationLink::QUERY_VAR ]   = $registration->code();
		$_GET[ CancellationLink::EXPIRES_VAR ] = (string) $expires;
		$_GET[ CancellationLink::TOKEN_VAR ]   = CancellationLink::sign( $registration->code(), $expires );

		$state = CancellationHandler::current_state();

		$this->assertSame( 'confirm', $state['state'] );
		$this->assertSame( $registration->id(), $state['registration']->id() );
		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $registration->id() )->status(),
			'a GET must not have cancelled anything'
		);
	}

	/**
	 * The panel renders for an event whose registration has closed.
	 *
	 * This is the case that matters: registration closes as an event fills up
	 * and approaches, which is exactly when a place given back is worth most.
	 *
	 * @return void
	 */
	public function test_the_panel_survives_a_closed_registration() {
		$this->quieten_registration();

		$event_id     = $this->make_event( array( 'capacity' => 1 ) );
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		// Shut registration for this event the way the meta box would.
		update_post_meta( $event_id, \QuickEventsManager\Events\Meta::REGISTRATION_ENABLED, '' );

		$event = new Event( $event_id );

		$this->assertFalse(
			\QuickEventsManager\Registration\RegistrationService::is_open( $event ),
			'the fixture should have closed registration'
		);

		$expires = CancellationLink::expiry( $event );

		$_GET[ CancellationLink::QUERY_VAR ]   = $registration->code();
		$_GET[ CancellationLink::EXPIRES_VAR ] = (string) $expires;
		$_GET[ CancellationLink::TOKEN_VAR ]   = CancellationLink::sign( $registration->code(), $expires );

		$markup = \QuickEventsManager\Frontend\Renderer::registration_form( array( 'id' => $event_id ) );

		$this->assertStringContainsString( 'qevm-cancellation', $markup );
		$this->assertStringContainsString( $registration->code(), $markup );
		$this->assertStringNotContainsString(
			'qevm-registration--closed',
			$markup,
			'the cancellation panel must win over the closed notice'
		);
	}

	/**
	 * An already-cancelled booking reports success, not an error.
	 *
	 * @return void
	 */
	public function test_an_already_cancelled_booking_reads_as_done() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		Repository::update_status( $registration->id(), RegistrationStatus::Cancelled );

		$expires = CancellationLink::expiry( new Event( $event_id ) );

		$_GET[ CancellationLink::QUERY_VAR ]   = $registration->code();
		$_GET[ CancellationLink::EXPIRES_VAR ] = (string) $expires;
		$_GET[ CancellationLink::TOKEN_VAR ]   = CancellationLink::sign( $registration->code(), $expires );

		$this->assertSame( 'done', CancellationHandler::current_state()['state'] );
	}

	/**
	 * The confirmation email carries a working link.
	 *
	 * @return void
	 */
	public function test_the_confirmation_email_contains_the_link() {
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );

		$captured = '';

		add_filter(
			'qevm_attendee_email',
			static function ( $email ) use ( &$captured ) {
				$captured = $email['body'];

				// Stop the send; there is nowhere to deliver inside a container.
				$email['to'] = '';

				return $email;
			}
		);

		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );
		$this->assertStringContainsString( CancellationLink::QUERY_VAR . '=', $captured );
		$this->assertStringContainsString( $registration->code(), $captured );
		$this->assertStringContainsString( CancellationLink::TOKEN_VAR . '=', $captured );
	}

	/**
	 * A finished event explains itself instead of showing nothing.
	 *
	 * AC-3.6. The form used to simply vanish once registration closed, which is
	 * the one answer that is wrong in every case — somebody who followed a link
	 * to register arrives at a page that looks broken and emails the organiser
	 * to ask.
	 *
	 * @return void
	 */
	public function test_a_finished_event_says_why_there_is_no_form() {
		$event_id = $this->make_event( array( 'starts_in' => -DAY_IN_SECONDS ) );

		$this->assertSame( 'ended', RegistrationService::closed_reason( new Event( $event_id ) ) );

		$markup = Renderer::registration_form( array( 'id' => $event_id ) );

		$this->assertStringContainsString( 'qevm-registration--closed', $markup );
		$this->assertStringContainsString( 'already taken place', $markup );
		$this->assertStringNotContainsString( 'data-qevm-registration-form', $markup );
	}

	/**
	 * A passed closing date explains itself too, and differently.
	 *
	 * @return void
	 */
	public function test_a_passed_closing_date_says_so() {
		$event_id = $this->make_event();

		update_post_meta(
			$event_id,
			\QuickEventsManager\Events\Meta::REGISTRATION_CLOSES,
			gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
		);

		$this->assertSame( 'expired', RegistrationService::closed_reason( new Event( $event_id ) ) );

		$markup = Renderer::registration_form( array( 'id' => $event_id ) );

		$this->assertStringContainsString( 'qevm-registration--closed', $markup );
		$this->assertStringContainsString( 'has closed', $markup );
	}

	/**
	 * An event that never offered registration says nothing.
	 *
	 * Nothing was offered, so there is nothing to explain — and an
	 * "unavailable" notice on every event that does not take bookings is noise
	 * on the pages where it is least wanted.
	 *
	 * @return void
	 */
	public function test_an_event_without_registration_shows_no_notice() {
		$event_id = $this->make_event( array( 'registration' => false ) );

		$this->assertSame( 'disabled', RegistrationService::closed_reason( new Event( $event_id ) ) );
		$this->assertSame( '', Renderer::registration_form( array( 'id' => $event_id ) ) );
	}

	/**
	 * Somebody else's filter closing registration is not explained for them.
	 *
	 * A site closing registration to non-members has a reason only that site
	 * knows. Inventing wording on its behalf would put the plugin's words in
	 * somebody else's mouth on their own front page.
	 *
	 * @return void
	 */
	public function test_a_filtered_closure_is_left_unexplained() {
		$event_id = $this->make_event();

		add_filter( 'qevm_registration_is_open', '__return_false' );

		$this->assertSame( 'filtered', RegistrationService::closed_reason( new Event( $event_id ) ) );
		$this->assertSame( '', Renderer::registration_form( array( 'id' => $event_id ) ) );
	}

	/**
	 * The wording is filterable, including into silence.
	 *
	 * @return void
	 */
	public function test_the_closed_notice_can_be_replaced_or_removed() {
		$event_id = $this->make_event( array( 'starts_in' => -DAY_IN_SECONDS ) );

		add_filter(
			'qevm_registration_closed_notice',
			static function ( $messages ) {
				$messages['ended'] = 'We are sorry, you have missed it.';

				return $messages;
			}
		);

		$this->assertStringContainsString(
			'We are sorry, you have missed it.',
			Renderer::registration_form( array( 'id' => $event_id ) )
		);

		add_filter( 'qevm_registration_closed_notice', '__return_empty_array', 99 );

		$this->assertSame( '', Renderer::registration_form( array( 'id' => $event_id ) ) );
	}

	/**
	 * Confirming actually cancels — the half a test had never reached.
	 *
	 * Every other test here calls `Repository::update_status()` directly, which
	 * proves the effect and skips the decision. `process()` is where the
	 * signature is checked, the booking is found and the status is set, and
	 * until it was split out of `handle()` no test could call it: `handle()`
	 * reads `$_POST`, verifies a nonce and ends in `exit()`.
	 *
	 * @return void
	 */
	public function test_confirming_a_genuine_link_cancels_the_booking() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$expires = CancellationLink::expiry( new Event( $event_id ) );

		$outcome = CancellationHandler::process(
			$registration->code(),
			$expires,
			CancellationLink::sign( $registration->code(), $expires )
		);

		$this->assertSame( 'success', $outcome['status'] );
		$this->assertSame( $event_id, $outcome['event_id'] );
		$this->assertSame(
			RegistrationStatus::Cancelled,
			Repository::find( $registration->id() )->status()
		);
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'the place should be free again' );
	}

	/**
	 * Confirming fires the action a site can hang its own behaviour on.
	 *
	 * @return void
	 */
	public function test_confirming_announces_that_the_attendee_did_it_themselves() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$seen = 0;

		add_action(
			'qevm_registration_self_cancelled',
			static function () use ( &$seen ) {
				++$seen;
			}
		);

		$expires = CancellationLink::expiry( new Event( $event_id ) );

		CancellationHandler::process(
			$registration->code(),
			$expires,
			CancellationLink::sign( $registration->code(), $expires )
		);

		$this->assertSame( 1, $seen );
	}

	/**
	 * Confirming a forged link changes nothing at all.
	 *
	 * @return void
	 */
	public function test_confirming_a_forged_link_changes_nothing() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$outcome = CancellationHandler::process(
			$registration->code(),
			time() + HOUR_IN_SECONDS,
			str_repeat( 'a', 64 )
		);

		$this->assertSame( 'error', $outcome['status'] );
		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $registration->id() )->status()
		);
		$this->assertSame( 1, Repository::count_taken( $event_id ) );
	}

	/**
	 * Confirming twice reports success both times.
	 *
	 * @return void
	 */
	public function test_confirming_twice_is_still_a_success() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$expires = CancellationLink::expiry( new Event( $event_id ) );
		$token   = CancellationLink::sign( $registration->code(), $expires );

		$this->assertSame( 'success', CancellationHandler::process( $registration->code(), $expires, $token )['status'] );
		$this->assertSame( 'already', CancellationHandler::process( $registration->code(), $expires, $token )['status'] );
	}

	/**
	 * The raw cancelled_at value for a booking.
	 *
	 * @param int $id Registration id.
	 * @return string|null
	 */
	private function cancelled_at( $id ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( 'SELECT cancelled_at FROM %i WHERE id = %d', Repository::table(), (int) $id )
		);
	}
}
