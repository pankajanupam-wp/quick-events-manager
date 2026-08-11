<?php
/**
 * The public registration form's POST handler.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

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

		$input = array(
			'name'     => isset( $_POST['qevm_name'] ) ? wp_unslash( $_POST['qevm_name'] ) : '',
			'email'    => isset( $_POST['qevm_email'] ) ? wp_unslash( $_POST['qevm_email'] ) : '',
			'phone'    => isset( $_POST['qevm_phone'] ) ? wp_unslash( $_POST['qevm_phone'] ) : '',
			'quantity' => isset( $_POST['qevm_quantity'] ) ? wp_unslash( $_POST['qevm_quantity'] ) : 1,
		);

		$result = ( new RegistrationService() )->create( $event_id, $input );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $event_id, 'error', $result->get_error_message() );
		}

		$this->redirect(
			$event_id,
			Registration::STATUS_WAITLISTED === $result->status() ? 'waitlisted' : 'success',
			''
		);
	}

	/**
	 * Send the visitor back to the event with the outcome attached.
	 *
	 * The message is passed in the URL rather than a transient or the session,
	 * because there is no session for a logged-out visitor and a transient
	 * keyed on anything stable would need an identifier we deliberately do not
	 * store. It is escaped on output at the other end.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $status   One of success, waitlisted, error.
	 * @param string $message  Message to display.
	 * @return void
	 */
	private function redirect( $event_id, $status, $message ) {
		$url = $event_id > 0 ? get_permalink( $event_id ) : home_url( '/' );

		if ( ! $url ) {
			$url = home_url( '/' );
		}

		$args = array( self::RESULT_ARG => $status );

		if ( '' !== $message ) {
			$args['qevm_message'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, $url ) . '#qevm-registration' );

		exit;
	}

	/**
	 * The outcome of the last submission, read from the URL.
	 *
	 * @since 26.0
	 *
	 * @return array{status: string, message: string}|null
	 */
	public static function current_result() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the redirect's own arguments.
		if ( ! isset( $_GET[ self::RESULT_ARG ] ) ) {
			return null;
		}

		$status  = sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) );
		$message = isset( $_GET['qevm_message'] )
			? sanitize_text_field( rawurldecode( wp_unslash( $_GET['qevm_message'] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $status, array( 'success', 'waitlisted', 'error' ), true ) ) {
			return null;
		}

		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
