<?php
/**
 * Turning a cancellation link into a cancelled booking.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

/**
 * The two halves of withdrawing from an event: asking, and confirming.
 *
 * Following the link does **not** cancel anything. It shows a panel with the
 * booking on it and a button. That separation is not ceremony — a link in an
 * email is fetched by things that are not the recipient. Corporate mail
 * gateways follow every URL in every message to scan it, Outlook's Safe Links
 * rewrites and pre-fetches them, and browsers speculatively load what they
 * think you are about to click. Any of those turns a one-click cancel link
 * into a booking that cancels itself before the person has read the email, and
 * the organiser sees an empty place with no explanation.
 *
 * So the GET is a question and the POST is the answer, which is also what HTTP
 * has always said: a GET must not change anything.
 *
 * The POST carries a nonce as well as the signature. The signature proves the
 * link came from this site; the nonce proves the request came from the page.
 * They defend different things, and a logged-out nonce is weak enough on its
 * own that it is worth being explicit that it is not what is doing the work
 * here.
 *
 * @since 26.0
 */
final class CancellationHandler {

	/**
	 * Action name for admin-post.php.
	 */
	const ACTION = 'qevm_cancel_registration';

	/**
	 * Nonce action.
	 */
	const NONCE = 'qevm_cancel_registration';

	/**
	 * Query argument carrying the outcome back to the page.
	 */
	const RESULT_ARG = 'qevm_cancelled';

	/**
	 * Hook the POST endpoint.
	 *
	 * Both the logged-in and logged-out variants: an organiser testing their
	 * own event is logged in, and everybody else is not.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Cancel the booking a confirmed POST names.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle() {
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$code    = isset( $_POST[ CancellationLink::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_POST[ CancellationLink::QUERY_VAR ] ) ) : '';
		$expires = isset( $_POST[ CancellationLink::EXPIRES_VAR ] ) ? (int) $_POST[ CancellationLink::EXPIRES_VAR ] : 0;
		$token   = isset( $_POST[ CancellationLink::TOKEN_VAR ] ) ? sanitize_text_field( wp_unslash( $_POST[ CancellationLink::TOKEN_VAR ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$outcome = self::process( $code, $expires, $token );

		$this->redirect( $outcome['event_id'], $outcome['status'], $outcome['message'] );
	}

	/**
	 * Decide what a confirmed cancellation should do, without doing the HTTP.
	 *
	 * Split out from handle() so it can be tested. handle() reads $_POST,
	 * checks a nonce and ends in exit(), none of which a test can call — so
	 * everything that matters lived somewhere no test could reach it, and the
	 * nonce check was the only part anybody would have noticed missing.
	 *
	 * The nonce stays in handle(), deliberately: it is a fact about the
	 * request, not about the cancellation, and a method that took "is this
	 * request genuine" as an argument would be a method somebody could call
	 * with `true`.
	 *
	 * @since 26.0
	 *
	 * @param string $code    Booking reference.
	 * @param int    $expires Expiry from the link.
	 * @param string $token   Signature from the link.
	 * @return array{status: string, message: string, event_id: int}
	 */
	public static function process( $code, $expires, $token ) {
		$verified = CancellationLink::verify( $code, $expires, $token );

		if ( is_wp_error( $verified ) ) {
			return self::outcome( 'error', $verified->get_error_message() );
		}

		$registration = Repository::find_by_code( $code );

		if ( null === $registration ) {
			return self::outcome(
				'error',
				__( 'That booking could not be found. It may already have been removed.', 'quick-events-manager' )
			);
		}

		$event_id = $registration->event_id();

		if ( RegistrationStatus::Cancelled === $registration->status() ) {
			/*
			 * Already cancelled is the outcome the person wanted, so it is
			 * reported as success. Anything else invites them to try again,
			 * or to email the organiser about a booking that is already gone.
			 */
			return self::outcome( 'already', '', $event_id );
		}

		if ( ! Repository::update_status( $registration->id(), RegistrationStatus::Cancelled ) ) {
			return self::outcome(
				'error',
				__( 'Your booking could not be cancelled. Please contact the organiser.', 'quick-events-manager' ),
				$event_id
			);
		}

		/**
		 * Fires after somebody cancels their own booking from a link.
		 *
		 * Distinct from `qevm_registration_status_changed`, which also fires
		 * for an administrator changing a status in the admin. This one means
		 * the attendee did it themselves.
		 *
		 * @since 26.0
		 *
		 * @param Registration $registration Booking as it was before cancelling.
		 * @param int          $event_id     Event id.
		 */
		do_action( 'qevm_registration_self_cancelled', $registration, $event_id );

		return self::outcome( 'success', '', $event_id );
	}

	/**
	 * Build an outcome.
	 *
	 * @since 26.0
	 *
	 * @param string $status   success | already | error.
	 * @param string $message  Message for the error case.
	 * @param int    $event_id Event to return to.
	 * @return array{status: string, message: string, event_id: int}
	 */
	private static function outcome( $status, $message = '', $event_id = 0 ) {
		return array(
			'status'   => $status,
			'message'  => $message,
			'event_id' => (int) $event_id,
		);
	}

	/**
	 * Send the visitor back to the event with the outcome in the URL.
	 *
	 * Post/redirect/get, so a refresh does not repeat the request and the
	 * result survives being bookmarked or shared without carrying the
	 * signature along with it. The signed arguments are deliberately not put
	 * back on the URL: the link has done its job.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event to return to; 0 for the home page.
	 * @param string $status   success | already | error.
	 * @param string $message  Optional message for the error case.
	 * @return never
	 */
	private function redirect( $event_id, $status, $message = '' ) {
		$base = $event_id > 0 ? get_permalink( $event_id ) : home_url( '/' );

		if ( ! is_string( $base ) || '' === $base ) {
			$base = home_url( '/' );
		}

		$args = array( self::RESULT_ARG => $status );

		if ( '' !== $message ) {
			$args['qevm_message'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, $base ) . '#qevm-registration' );

		exit;
	}

	/**
	 * What the page should show about cancellation right now, if anything.
	 *
	 * Three states, and the renderer needs all of them:
	 *
	 * - `confirm` — a valid link was followed; show the booking and a button.
	 * - `done`    — a cancellation just completed; show the outcome.
	 * - `error`   — the link was forged, truncated or has expired.
	 *
	 * @since 26.0
	 *
	 * @return array{state: string, message: string, registration: Registration|null, request: array<string, mixed>}|null
	 */
	public static function current_state() {
		$outcome = self::completed_outcome();

		if ( null !== $outcome ) {
			return $outcome;
		}

		$request = CancellationLink::from_request();

		if ( null === $request ) {
			return null;
		}

		$verified = CancellationLink::verify( $request['code'], $request['expires'], $request['token'] );

		if ( is_wp_error( $verified ) ) {
			return self::state( 'error', $verified->get_error_message() );
		}

		$registration = Repository::find_by_code( $request['code'] );

		if ( null === $registration ) {
			return self::state( 'error', __( 'That booking could not be found. It may already have been removed.', 'quick-events-manager' ) );
		}

		if ( RegistrationStatus::Cancelled === $registration->status() ) {
			return self::state( 'done', __( 'This booking has already been cancelled.', 'quick-events-manager' ) );
		}

		return self::state( 'confirm', '', $registration, $request );
	}

	/**
	 * The outcome of a cancellation that has just happened, if there was one.
	 *
	 * @since 26.0
	 *
	 * @return array{state: string, message: string, registration: Registration|null, request: array<string, mixed>}|null
	 */
	private static function completed_outcome() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a redirect result, which changes nothing.
		if ( ! isset( $_GET[ self::RESULT_ARG ] ) ) {
			return null;
		}

		$status = sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) );

		/*
		 * Decode before sanitise, the same way FormHandler does and for the
		 * same reason: the value was rawurlencode()d into the redirect. The
		 * sniff only looks at the function wrapping the superglobal directly
		 * and cannot see that sanitize_text_field() encloses the whole thing.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() wraps the decode; see above.
		$message = isset( $_GET['qevm_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['qevm_message'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'success' === $status ) {
			return self::state( 'done', __( 'Your booking has been cancelled. Thank you for letting the organiser know.', 'quick-events-manager' ) );
		}

		if ( 'already' === $status ) {
			return self::state( 'done', __( 'This booking has already been cancelled.', 'quick-events-manager' ) );
		}

		if ( 'error' === $status ) {
			return self::state(
				'error',
				'' !== $message ? $message : __( 'Your booking could not be cancelled.', 'quick-events-manager' )
			);
		}

		return null;
	}

	/**
	 * Build a state array.
	 *
	 * @since 26.0
	 *
	 * @param string               $state        confirm | done | error.
	 * @param string               $message      Message to show.
	 * @param Registration|null    $registration Booking, when there is one.
	 * @param array<string, mixed> $request      Signed arguments to echo back in the form.
	 * @return array{state: string, message: string, registration: Registration|null, request: array<string, mixed>}
	 */
	private static function state( $state, $message = '', ?Registration $registration = null, array $request = array() ) {
		return array(
			'state'        => $state,
			'message'      => $message,
			'registration' => $registration,
			'request'      => $request,
		);
	}

	/**
	 * Whether the current request is asking about a cancellation at all.
	 *
	 * Lets the renderer decide before doing any work, and before the checks
	 * that would hide the panel — a person cancelling a booking for a full
	 * event, or one whose registration has closed, still has to be able to.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function is_cancellation_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only.
		return null !== CancellationLink::from_request() || isset( $_GET[ self::RESULT_ARG ] );
	}

	/**
	 * The event a cancellation state belongs to, for rendering.
	 *
	 * @since 26.0
	 *
	 * @param array{state: string, message: string, registration: Registration|null, request: array<string, mixed>} $state State from current_state().
	 * @return Event|null
	 */
	public static function event_for( array $state ) {
		if ( ! $state['registration'] instanceof Registration ) {
			return null;
		}

		$event = new Event( $state['registration']->event_id() );

		return $event->is_valid() ? $event : null;
	}
}
