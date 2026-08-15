<?php
/**
 * Acceptance criteria nothing else in the suite was checking.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Frontend\Renderer;
use QuickEventsManager\Privacy\Consent;
use QuickEventsManager\Registration\CancellationHandler;
use QuickEventsManager\Registration\CancellationLink;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;

/**
 * The promises made to the person using the plugin, checked one at a time.
 *
 * Every test in here came out of an audit that compared the acceptance criteria
 * against what the suite actually asserted, and each one closes a gap where the
 * behaviour was implemented but nothing would have noticed it going away. They
 * have little in common with each other beyond that, which is deliberate: this
 * file is organised by the promise being kept rather than by the class keeping
 * it, because a criterion is a statement about the whole plugin and the code
 * that satisfies it is free to move.
 */
final class AcceptanceTest extends TestCase {

	/**
	 * The address the rate-limit test submits from.
	 *
	 * Documentation range, so it cannot collide with anything real, and its own
	 * value rather than the one NoIpAddressTest uses — the limiter counts per
	 * address, and sharing one with another test would mean starting from
	 * whatever that test had already spent.
	 */
	private const NAT_ADDRESS = '192.0.2.99';

	/**
	 * Leave no request state behind for the next test to read.
	 *
	 * Both superglobals here outlive a test: PHP has no request to end, so an
	 * address or a query argument set in one test is still set in the next one.
	 * The rate limiter reads REMOTE_ADDR on every public booking, so a leaked
	 * address makes an unrelated test start counting against a shared bucket.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_GET[ CancellationLink::QUERY_VAR ],
			$_GET[ CancellationLink::EXPIRES_VAR ],
			$_GET[ CancellationLink::TOKEN_VAR ],
			$_GET[ CancellationHandler::RESULT_ARG ],
			$_GET['qevm_message']
		);

		parent::tearDown();
	}

	/**
	 * AC-3.4 — booking twice with one address is refused in words that help.
	 *
	 * The refusal is the easy half and was already covered by the duplicate
	 * check itself. What was not covered is what the person is told, and that is
	 * the half that decides whether they email the organiser or fix it
	 * themselves. A message naming a table, a query or an exception is a message
	 * written for whoever wrote the plugin, and it reaches somebody who is
	 * trying to attend an event.
	 *
	 * The assertions are therefore about the wording rather than the code: no
	 * implementation detail leaks out, and it says enough for the reader to
	 * work out that they have already booked. Nothing is written either — a
	 * refusal that still leaves a half-made row would satisfy the visible
	 * behaviour and be the worse bug.
	 *
	 * @return void
	 */
	public function test_a_duplicate_address_is_refused_with_a_message_a_visitor_can_act_on() {
		$this->quieten_registration();

		$event_id = $this->make_event();
		$input    = array(
			'name'     => 'Ada Lovelace',
			'email'    => 'ada@example.com',
			'phone'    => '',
			'quantity' => 1,
			'guests'   => array(),
			'consent'  => true,
		);

		// The default context is the public form, which is the one being judged here.
		$service = new RegistrationService();

		$this->assertNotWPError( $service->create( $event_id, $input ), 'the first booking should be accepted' );

		$result = $service->create( $event_id, $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_already_registered', $result->get_error_code() );

		$message = $result->get_error_message();

		foreach ( array( 'SQL', 'query', 'Error:', 'Exception', 'wpdb' ) as $leak ) {
			$this->assertStringNotContainsStringIgnoringCase(
				$leak,
				$message,
				sprintf( 'the refusal says "%s" to somebody who only wanted a place at an event', $leak )
			);
		}

		$this->assertStringNotContainsString(
			'\\',
			$message,
			'a namespaced class name is not an explanation'
		);

		$this->assertStringContainsStringIgnoringCase(
			'email',
			$message,
			'the message has to name the thing that is wrong'
		);
		$this->assertStringContainsStringIgnoringCase(
			'registered',
			$message,
			'the message has to say what already happened'
		);

		$this->assertSame( 1, $this->count_rows( 'registrations' ), 'the refused booking must write nothing' );
		$this->assertSame( 1, $this->count_rows( 'attendees' ) );
	}

	/**
	 * AC-3.7 — thirty bookings from one address inside the window all succeed.
	 *
	 * This is the NAT-gateway criterion, and it is a usability requirement
	 * wearing a security requirement's clothes. An office, a university and a
	 * conference venue each put every visitor behind a single public address,
	 * so to the plugin an entire building looks like one very busy person — and
	 * those buildings are exactly where events get organised. A limit tight
	 * enough to interest an attacker locks all of them out at once.
	 *
	 * The limiter genuinely runs here. `quieten_registration()` would zero it
	 * through `qevm_registration_rate_limit` and leave a test that proves only
	 * that a switched-off limiter refuses nobody, so only the two mail filters
	 * are added and the limit is left exactly as a real site has it.
	 *
	 * The loop is sized by RATE_LIMIT so a failure inside it points at the
	 * limiter rather than at arithmetic, and the constant is then checked
	 * against the number in the criterion — the criterion says thirty, so a
	 * later change to twenty has to fail here rather than quietly resize the
	 * loop and stay green. The window is five minutes, which is RATE_WINDOW,
	 * and thirty consecutive calls take nowhere near that long.
	 *
	 * @return void
	 */
	public function test_thirty_submissions_from_one_address_in_five_minutes_all_succeed() {
		$_SERVER['REMOTE_ADDR'] = self::NAT_ADDRESS;

		add_filter( 'qevm_attendee_email', '__return_empty_array' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		// Uncapped, so a refusal can only have come from the limiter.
		$event_id = $this->make_event( array( 'capacity' => 0 ) );
		$service  = new RegistrationService();

		for ( $attempt = 1; $attempt <= RegistrationService::RATE_LIMIT; $attempt++ ) {
			$result = $service->create(
				$event_id,
				array(
					'name'     => 'Delegate ' . $attempt,
					'email'    => 'delegate' . $attempt . '@example.com',
					'phone'    => '',
					'quantity' => 1,
					'guests'   => array(),
					'consent'  => true,
				)
			);

			$this->assertNotWPError(
				$result,
				sprintf(
					'booking %d of %d from one address was refused; a whole office shares that address',
					$attempt,
					RegistrationService::RATE_LIMIT
				)
			);
		}

		$this->assertSame(
			RegistrationService::RATE_LIMIT,
			$this->count_rows( 'registrations' ),
			'every accepted submission should have become a booking'
		);

		$this->assertGreaterThanOrEqual(
			30,
			RegistrationService::RATE_LIMIT,
			'AC-3.7 names thirty submissions in five minutes from one address, so the limit cannot go below it'
		);

		/*
		 * Proof that the loop above was doing work. A limiter that never sees
		 * the address — an empty key, a filter left switched off — refuses
		 * nobody, and every assertion in this test would pass for that reason
		 * instead of the intended one. The submission past the limit has to be
		 * the one that is turned away.
		 */
		$flood = $service->create(
			$event_id,
			array(
				'name'     => 'One too many',
				'email'    => 'flood@example.com',
				'phone'    => '',
				'quantity' => 1,
				'guests'   => array(),
				'consent'  => true,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $flood, 'the limiter was never running' );
		$this->assertSame( 'qevm_rate_limited', $flood->get_error_code() );
	}

	/**
	 * AC-3.2c — the confirmation states the time in the event's own timezone.
	 *
	 * An event is in one place and the site running it may be administered from
	 * another, so the site's timezone is not the answer to "when should I turn
	 * up?". Getting this wrong is silent and expensive: the email looks correct,
	 * and somebody arrives hours out.
	 *
	 * The fixture sets a timezone several hours from the site's, which is the
	 * only way the two renderings are distinguishable at all — with the event
	 * on the site's zone every implementation, right or wrong, produces the same
	 * string.
	 *
	 * The label is asserted joined to the time rather than on its own, because
	 * an abbreviation floating somewhere in the body does not tell the reader
	 * which time it qualifies. `timezone_label()` returns an empty string when
	 * PHP has no abbreviation for the zone; if that ever happens for this
	 * fixture the assertion falls back to the formatted start alone, which still
	 * proves the shift was applied.
	 *
	 * @return void
	 */
	public function test_the_confirmation_email_states_the_time_in_the_events_timezone() {
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$body = '';

		add_filter(
			'qevm_attendee_email',
			static function ( $email ) use ( &$body ) {
				$body = (string) $email['body'];

				// Stop the send; there is nowhere to deliver inside a container.
				$email['to'] = '';

				return $email;
			}
		);

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::TIMEZONE, 'Asia/Kolkata' );

		$event = new Event( $event_id );

		$this->assertSame( 'Asia/Kolkata', $event->timezone(), 'the fixture did not take the timezone' );
		$this->assertNotSame(
			Meta::site_timezone(),
			$event->timezone(),
			'the event has to be in a different zone from the site or this test proves nothing'
		);

		$this->assertNotWPError( $this->book( $event_id ) );

		$start = $event->format_start();
		$label = $event->timezone_label();

		$this->assertNotSame( '', $start, 'the fixture should have a start time to state' );
		$this->assertNotSame( '', $body, 'no confirmation was sent to inspect' );

		$this->assertStringContainsString(
			$start,
			$body,
			'the confirmation does not state the start time in the event timezone'
		);

		if ( '' !== $label ) {
			$this->assertStringContainsString(
				trim( $start . ' ' . $label ),
				$body,
				'the time is stated without saying which zone it is in'
			);
		}
	}

	/**
	 * AC-5.1 — the consent box is required, starts unticked, and can be removed.
	 *
	 * Consent that is pre-ticked is not consent, and this is the failure mode
	 * that arrives by accident: a `checked()` call added to make the form
	 * friendlier turns every registration into a record of an agreement nobody
	 * made. So the real markup is rendered and the actual input element read,
	 * rather than trusting the service that stores the answer.
	 *
	 * `checked` is asserted against the input element rather than the whole
	 * page, because the form also carries a nonce whose value is arbitrary
	 * characters, and a page-wide search for a seven-letter word would be a test
	 * that fails once a year for no reason.
	 *
	 * The second half is the site owner's side of the same criterion. Emptying
	 * the wording is the documented way to stop asking for consent at all, and
	 * a form that kept an unlabelled required checkbox after that would be
	 * unsubmittable for a reason nobody could see.
	 *
	 * @return void
	 */
	public function test_the_consent_checkbox_is_required_and_starts_unticked() {
		$event_id = $this->make_event();
		$wording  = Consent::text();

		$this->assertNotSame( '', $wording, 'consent is meant to be asked for by default' );

		$markup = Renderer::registration_form( array( 'id' => $event_id ) );

		$this->assertStringContainsString( 'id="qevm-consent"', $markup );
		$this->assertStringContainsString(
			$wording,
			$markup,
			'the box has to show the wording the site configured, not a stand-in'
		);

		$this->assertSame(
			1,
			preg_match( '/<input[^>]*id="qevm-consent"[^>]*>/', $markup, $matches ),
			'the consent control should be a single input element'
		);

		$consent_input = $matches[0];

		$this->assertStringContainsString( 'type="checkbox"', $consent_input );
		$this->assertStringContainsString(
			'required',
			$consent_input,
			'a consent box the browser will let you skip is decoration'
		);
		$this->assertStringNotContainsString(
			'checked',
			$consent_input,
			'a pre-ticked box records an agreement the person never made'
		);

		// Emptying the wording is the documented switch for turning consent off.
		update_option(
			QEVM_OPTION_SETTINGS,
			array_merge( Settings::defaults(), array( 'consent_text' => '' ) )
		);

		$this->assertFalse( Consent::is_required(), 'an empty wording should stop consent being asked for' );

		$without_consent = Renderer::registration_form( array( 'id' => $event_id ) );

		$this->assertStringNotContainsString(
			'qevm-consent',
			$without_consent,
			'a required checkbox with nothing beside it cannot be agreed to or understood'
		);
	}

	/**
	 * AC-4.4 — a forged link cannot tell an attacker whether a booking exists.
	 *
	 * Booking references are short, printed in emails and quoted over the phone,
	 * so they are guessable in a way a signature is not. If the page answered
	 * differently for a real reference than for an invented one, somebody
	 * without the salt could still walk the reference space and learn who has
	 * booked what — an oracle built entirely out of error messages.
	 *
	 * What prevents it is an ordering property rather than a check: `verify()`
	 * touches no database at all, and `find_by_code()` runs only after it has
	 * passed. Both requests below therefore stop at the same place and are given
	 * the same words, and the assertion is on the messages being byte-identical
	 * rather than merely both being errors — two different sentences under one
	 * `error` state would leak just as much.
	 *
	 * If a future refactor moved the `find_by_code()` lookup above `verify()` —
	 * to name the event in the error, say, or to skip signing work for a code
	 * that does not exist — this test is what would catch it: the real code
	 * would come back with something about the booking and the invented one
	 * would not.
	 *
	 * @return void
	 */
	public function test_a_forged_link_cannot_be_used_to_discover_whether_a_booking_exists() {
		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id );

		$this->assertNotWPError( $registration );

		$unknown_code = 'QEVM-NOSUCH1';

		$this->assertNull(
			Repository::find_by_code( $unknown_code ),
			'the second request has to name a booking that genuinely does not exist'
		);

		/*
		 * Sixty-four characters, the length of a real SHA-256 signature, so the
		 * length short-circuit in verify() is not what rejects either request.
		 */
		$forged  = str_repeat( 'a', 64 );
		$expires = time() + HOUR_IN_SECONDS;

		$real     = $this->cancellation_state( $registration->code(), $expires, $forged );
		$invented = $this->cancellation_state( $unknown_code, $expires, $forged );

		$this->assertSame( 'error', $real['state'] );
		$this->assertSame( 'error', $invented['state'] );

		$this->assertSame(
			$real['message'],
			$invented['message'],
			'the reply differs between a real booking reference and an invented one, which is enough to enumerate bookings'
		);

		$this->assertNull( $real['registration'], 'a forged link must not have loaded the booking' );
		$this->assertNull( $invented['registration'] );
	}

	/**
	 * The cancellation state for a link built from these three arguments.
	 *
	 * The handler reads the query string rather than taking parameters, so this
	 * puts them where it looks. tearDown() clears them again.
	 *
	 * @param string $code    Booking reference to claim.
	 * @param int    $expires Expiry timestamp.
	 * @param string $token   Signature to present.
	 * @return array<string, mixed>
	 */
	private function cancellation_state( $code, $expires, $token ) {
		$_GET[ CancellationLink::QUERY_VAR ]   = $code;
		$_GET[ CancellationLink::EXPIRES_VAR ] = (string) $expires;
		$_GET[ CancellationLink::TOKEN_VAR ]   = $token;

		$state = CancellationHandler::current_state();

		$this->assertIsArray( $state, 'the handler should have recognised a cancellation request' );

		return $state;
	}
}
