<?php
/**
 * The public registration form's POST handler.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Domain\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts the front-end form and redirects back with the outcome.
 *
 * Posts to admin-post.php rather than being handled by JavaScript, so the form
 * works with scripts disabled and follows the POST-redirect-GET pattern — a
 * refresh after registering cannot submit twice.
 *
 * @since 26.0
 */
final class FormHandler {

	/**
	 * Nonce action.
	 */
	const NONCE = 'qevm_register';

	/**
	 * Query argument carrying the outcome back to the event page.
	 */
	const RESULT_ARG = 'qevm_result';

	/**
	 * Query argument naming a stashed submission.
	 */
	const STASH_ARG = 'qevm_form';

	/**
	 * Transient prefix for a stashed submission.
	 */
	const STASH_PREFIX = 'qevm_form_';

	/**
	 * How long a failed submission is kept.
	 *
	 * Long enough to survive a slow redirect and a moment's confusion, short
	 * enough that personal data does not sit in the options table. It is
	 * deleted on read in the ordinary case; this is the backstop for a visitor
	 * who closes the tab.
	 */
	const STASH_TTL = 900;

	/**
	 * Hook into admin-post.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_nopriv_qevm_register', array( $this, 'handle' ) );
		add_action( 'admin_post_qevm_register', array( $this, 'handle' ) );
	}

	/**
	 * Handle a submission.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle() {
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE . '_' . $event_id ) ) {
			$this->redirect( $event_id, 'error', __( 'Your session expired. Please try again.', 'quick-events-manager' ) );
		}

		/*
		 * A honeypot field a human never sees and never fills in. Cheap, and
		 * it removes the bulk of automated submissions without putting a
		 * CAPTCHA in front of real attendees.
		 */
		if ( ! empty( $_POST['qevm_website'] ) ) {
			$this->redirect( $event_id, 'success', '' );
		}

		/*
		 * Unslashed but not sanitised here, on purpose. RegistrationService is
		 * the single point where a registration is validated, and it sanitises
		 * each field as it validates it: sanitize_text_field() for the name and
		 * phone, sanitize_email() plus is_email() for the address, absint() for
		 * the quantity. The REST controller hands the service the same raw
		 * shape, so both transports get identical treatment.
		 *
		 * Sanitising a second time here would be the more obviously safe thing
		 * to do, and is exactly what makes validation drift: two places would
		 * then define what a valid name is, and only one of them would be
		 * updated the next time that changes.
		 */
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in RegistrationService::create(); see above.
		$input = array(
			'name'     => isset( $_POST['qevm_name'] ) ? wp_unslash( $_POST['qevm_name'] ) : '',
			'email'    => isset( $_POST['qevm_email'] ) ? wp_unslash( $_POST['qevm_email'] ) : '',
			'phone'    => isset( $_POST['qevm_phone'] ) ? wp_unslash( $_POST['qevm_phone'] ) : '',
			'quantity' => isset( $_POST['qevm_quantity'] ) ? wp_unslash( $_POST['qevm_quantity'] ) : 1,

			/*
			 * One name per further place, keyed by position. wp_unslash() walks
			 * an array, and RegistrationService::guest_names() is what decides
			 * which keys are real — anything past the quantity is discarded
			 * there rather than trusted here.
			 */
			'guests'   => isset( $_POST['qevm_guest_name'] ) ? wp_unslash( $_POST['qevm_guest_name'] ) : array(),

			/*
			 * Only whether the box was ticked. What was agreed to is read from
			 * the site's own settings when the row is written, never from the
			 * request — a submission does not get to name the wording it
			 * consented to.
			 */
			'consent'  => ! empty( $_POST['qevm_consent'] ),
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = ( new RegistrationService() )->create( $event_id, $input );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$event_id,
				'error',
				$result->get_error_message(),
				self::stash( $result, $input )
			);
		}

		$this->redirect(
			$event_id,
			RegistrationStatus::Waitlisted === $result->status() ? 'waitlisted' : 'success',
			''
		);
	}

	/**
	 * Send the visitor back to the event with the outcome attached.
	 *
	 * The result code and a short message travel in the URL, because neither is
	 * personal and both must survive a visitor who bookmarks the page. What was
	 * typed does not go there — see stash(), which is the other half of this.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $status   One of success, waitlisted, error.
	 * @param string $message  Message to display.
	 * @param string $token    Stash token for a failed submission, if there is one.
	 * @return void
	 */
	private function redirect( $event_id, $status, $message, $token = '' ) {
		$url = $event_id > 0 ? get_permalink( $event_id ) : home_url( '/' );

		if ( ! $url ) {
			$url = home_url( '/' );
		}

		$args = array( self::RESULT_ARG => $status );

		if ( '' !== $message ) {
			$args['qevm_message'] = rawurlencode( $message );
		}

		if ( '' !== $token ) {
			$args[ self::STASH_ARG ] = $token;
		}

		wp_safe_redirect( add_query_arg( $args, $url ) . '#qevm-registration' );

		exit;
	}

	/**
	 * Keep a failed submission long enough to render it again.
	 *
	 * Post/redirect/get throws the submission away, which is what makes a
	 * refresh safe and what makes the form come back empty. Empty is a real
	 * problem: somebody who mistyped one character retypes their name, email,
	 * phone and every guest name, and a screen reader user re-reads the whole
	 * form to do it.
	 *
	 * **Not in the URL.** The result code and a short message go there because
	 * neither is personal. Names, addresses and phone numbers cannot: a query
	 * string is written to every access log the request passes through, kept
	 * in browser history, and sent onward in the Referer header to any
	 * third-party asset the page loads. Putting an attendee's email there
	 * would leak it to places nobody audits.
	 *
	 * So the values live in a transient and the URL carries only a random
	 * token. The token identifies the *attempt*, not the person, is used once,
	 * and expires on its own — which is also the answer to the objection this
	 * method used to carry, that a transient would need an identifier the
	 * plugin deliberately does not store.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Error            $error Errors from the service.
	 * @param array<string, mixed> $input What was submitted.
	 * @return string Token, or an empty string if nothing could be stored.
	 */
	private static function stash( \WP_Error $error, array $input ) {
		$fields = array();

		foreach ( $error->get_error_codes() as $code ) {
			$data  = $error->get_error_data( $code );
			$field = is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : '_';

			// First message per field wins; they are ordered as validated.
			if ( ! isset( $fields[ $field ] ) ) {
				$fields[ $field ] = (string) $error->get_error_message( $code );
			}
		}

		$token = wp_generate_password( 20, false, false );

		$stored = set_transient(
			self::STASH_PREFIX . $token,
			array(
				'errors' => $fields,
				'values' => array(
					'name'     => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '',
					'email'    => isset( $input['email'] ) ? sanitize_text_field( (string) $input['email'] ) : '',
					'phone'    => isset( $input['phone'] ) ? sanitize_text_field( (string) $input['phone'] ) : '',
					'quantity' => isset( $input['quantity'] ) ? absint( $input['quantity'] ) : 1,
					'guests'   => RegistrationService::guest_names(
						isset( $input['guests'] ) ? $input['guests'] : array(),
						RegistrationService::MAX_PLACES
					),
				),
			),
			self::STASH_TTL
		);

		return $stored ? $token : '';
	}

	/**
	 * Read back a stashed submission, and consume it.
	 *
	 * Deleted on read, so that a shared or bookmarked URL does not show
	 * somebody else's half-filled form, and a refresh does not keep resurfacing
	 * an error the visitor has already dealt with.
	 *
	 * @since 26.0
	 *
	 * @return array{errors: array<string, string>, values: array<string, mixed>}
	 */
	private static function take_stash() {
		$empty = array(
			'errors' => array(),
			'values' => array(),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect result, which changes nothing.
		$token = isset( $_GET[ self::STASH_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::STASH_ARG ] ) ) : '';

		if ( '' === $token ) {
			return $empty;
		}

		$stash = get_transient( self::STASH_PREFIX . $token );

		delete_transient( self::STASH_PREFIX . $token );

		if ( ! is_array( $stash ) ) {
			return $empty;
		}

		return array(
			'errors' => isset( $stash['errors'] ) && is_array( $stash['errors'] ) ? $stash['errors'] : array(),
			'values' => isset( $stash['values'] ) && is_array( $stash['values'] ) ? $stash['values'] : array(),
		);
	}

	/**
	 * The outcome of the last submission, read from the URL.
	 *
	 * @since 26.0
	 *
	 * @return array{status: string, message: string, errors: array<string, string>, values: array<string, mixed>}|null
	 */
	public static function current_result() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the redirect's own arguments.
		if ( ! isset( $_GET[ self::RESULT_ARG ] ) ) {
			return null;
		}

		$status = sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) );

		/*
		 * The decode has to happen before the sanitise, not after: the value was
		 * rawurlencode()d into the redirect, so sanitising first would strip
		 * nothing useful and then hand percent-escapes back to the caller. The
		 * sniff only looks at the function wrapping the superglobal directly and
		 * cannot see that sanitize_text_field() encloses the whole expression.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() wraps the decode; see above.
		$message = isset( $_GET['qevm_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['qevm_message'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $status, array( 'success', 'waitlisted', 'error' ), true ) ) {
			return null;
		}

		$stash = self::take_stash();

		return array(
			'status'  => $status,
			'message' => $message,
			'errors'  => $stash['errors'],
			'values'  => $stash['values'],
		);
	}

	/**
	 * The value to put back in a field after a failed submission.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed>|null $result Result from current_result().
	 * @param string                    $field  Field name.
	 * @param mixed                     $fallback Value when there is nothing to restore.
	 * @return mixed
	 */
	public static function value( $result, $field, $fallback = '' ) {
		if ( ! is_array( $result ) || 'error' !== $result['status'] ) {
			return $fallback;
		}

		return isset( $result['values'][ $field ] ) ? $result['values'][ $field ] : $fallback;
	}

	/**
	 * The error message for one field, if it has one.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed>|null $result Result from current_result().
	 * @param string                    $field  Field name.
	 * @return string
	 */
	public static function error( $result, $field ) {
		if ( ! is_array( $result ) || 'error' !== $result['status'] ) {
			return '';
		}

		return isset( $result['errors'][ $field ] ) ? (string) $result['errors'][ $field ] : '';
	}
}
