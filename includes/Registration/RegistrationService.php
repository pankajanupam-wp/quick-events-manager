<?php
/**
 * The single path a registration takes, whatever submitted it.
 *
 * @package QuickEventsManager
 */

namespace QEM\Registration;

use QEM\Events\Event;
use QEM\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and creates registrations.
 *
 * Both transports — the public form posting to admin-post.php and the REST
 * endpoint — call create(). That is deliberate: validation, capacity, duplicate
 * detection and rate limiting are security-relevant, and having two copies of
 * them is how one copy quietly falls behind the other.
 *
 * @since 26.0
 */
final class RegistrationService {

	/**
	 * How many submissions one address may make in the window.
	 *
	 * Set high enough not to catch real people. An office, a university or a
	 * conference venue puts every visitor behind one NAT gateway, so they all
	 * share an address — and those are exactly the places that run events.
	 * A limit tight enough to be interesting to an attacker would lock out a
	 * whole building, so this is aimed only at crude flooding.
	 */
	const RATE_LIMIT = 30;

	/**
	 * Rate-limit window, in seconds.
	 */
	const RATE_WINDOW = 300;

	/**
	 * Register a person for an event.
	 *
	 * @since 26.0
	 *
	 * @param int   $event_id Event id.
	 * @param array $input    Raw, untrusted input.
	 * @return Registration|\WP_Error
	 */
	public function create( $event_id, array $input ) {
		$event = new Event( $event_id );

		if ( ! $event->is_valid() || 'publish' !== get_post_status( $event->id() ) ) {
			return new \WP_Error(
				'qem_event_not_found',
				__( 'That event could not be found.', 'quick-events-manager' ),
				array( 'status' => 404 )
			);
		}

		if ( ! self::is_open( $event ) ) {
			return new \WP_Error(
				'qem_registration_closed',
				__( 'Registration for this event is closed.', 'quick-events-manager' ),
				array( 'status' => 403 )
			);
		}

		$limited = $this->check_rate_limit();

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$fields = $this->validate( $input );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( Repository::email_is_registered( $event->id(), $fields['email'] ) ) {
			return new \WP_Error(
				'qem_already_registered',
				__( 'That email address is already registered for this event.', 'quick-events-manager' ),
				array( 'status' => 409 )
			);
		}

		$registration = Repository::insert_with_capacity(
			array(
				'event_id' => $event->id(),
				'user_id'  => get_current_user_id(),
				'name'     => $fields['name'],
				'email'    => $fields['email'],
				'phone'    => $fields['phone'],
				'quantity' => $fields['quantity'],
			),
			(int) $event->meta( Meta::CAPACITY, 0 )
		);

		if ( is_wp_error( $registration ) ) {
			return $registration;
		}

		$this->record_attempt();

		/**
		 * Fires once a registration has been stored.
		 *
		 * The confirmation email is sent on this hook, so removing it stops
		 * the email without touching the rest of the flow.
		 *
		 * @since 26.0
		 *
		 * @param Registration $registration The stored registration.
		 * @param Event        $event        The event registered for.
		 */
		do_action( 'qem_registration_created', $registration, $event );

		return $registration;
	}

	/**
	 * Whether an event is currently accepting registrations.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event to check.
	 * @return bool
	 */
	public static function is_open( Event $event ) {
		if ( ! $event->meta( Meta::REGISTRATION_ENABLED, false ) ) {
			return false;
		}

		if ( $event->has_ended() ) {
			return false;
		}

		$closes = (string) $event->meta( Meta::REGISTRATION_CLOSES );

		if ( '' !== $closes && $closes < Meta::now_utc() ) {
			return false;
		}

		/**
		 * Filter whether registration is open for an event.
		 *
		 * @since 26.0
		 *
		 * @param bool  $is_open Whether registration is open.
		 * @param Event $event   The event.
		 */
		return (bool) apply_filters( 'qem_registration_is_open', true, $event );
	}

	/**
	 * Places still available, or null when the event is uncapped.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event to check.
	 * @return int|null
	 */
	public static function places_remaining( Event $event ) {
		$capacity = (int) $event->meta( Meta::CAPACITY, 0 );

		if ( $capacity <= 0 ) {
			return null;
		}

		return max( 0, $capacity - Repository::count_taken( $event->id() ) );
	}

	/**
	 * Whether an event has run out of places.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event to check.
	 * @return bool
	 */
	public static function is_full( Event $event ) {
		$remaining = self::places_remaining( $event );

		return null !== $remaining && $remaining <= 0;
	}

	/**
	 * Validate and normalise the submitted fields.
	 *
	 * @since 26.0
	 *
	 * @param array $input Raw input.
	 * @return array|\WP_Error
	 */
	private function validate( array $input ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		$name = trim( $name );

		if ( '' === $name ) {
			return new \WP_Error(
				'qem_name_required',
				__( 'Please enter your name.', 'quick-events-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $name ) > 190 ) {
			$name = mb_substr( $name, 0, 190 );
		}

		$email = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error(
				'qem_email_invalid',
				__( 'Please enter a valid email address.', 'quick-events-manager' ),
				array( 'status' => 400 )
			);
		}

		$phone = isset( $input['phone'] ) ? sanitize_text_field( $input['phone'] ) : '';

		if ( mb_strlen( $phone ) > 50 ) {
			$phone = mb_substr( $phone, 0, 50 );
		}

		$quantity = isset( $input['quantity'] ) ? absint( $input['quantity'] ) : 1;
		$quantity = max( 1, min( 20, $quantity ) );

		return array(
			'name'     => $name,
			'email'    => $email,
			'phone'    => $phone,
			'quantity' => $quantity,
		);
	}

	/**
	 * Refuse a visitor who is submitting too often.
	 *
	 * Keyed on a hash of the address rather than the address itself, so the
	 * plugin never stores an IP anywhere — including in the options table,
	 * where a transient would otherwise leave one sitting in plain text.
	 *
	 * @since 26.0
	 *
	 * @return true|\WP_Error
	 */
	private function check_rate_limit() {
		$key = $this->rate_limit_key();

		if ( '' === $key ) {
			return true;
		}

		/**
		 * Filter how many registrations one address may submit per window.
		 *
		 * Return 0 to switch rate limiting off entirely — reasonable on a
		 * site where registration happens at a staffed desk on one connection.
		 *
		 * @since 26.0
		 *
		 * @param int $limit Submissions allowed per RATE_WINDOW seconds.
		 */
		$limit = (int) apply_filters( 'qem_registration_rate_limit', self::RATE_LIMIT );

		if ( $limit <= 0 ) {
			return true;
		}

		if ( (int) get_transient( $key ) >= $limit ) {
			return new \WP_Error(
				'qem_rate_limited',
				__( 'Too many registration attempts. Please wait a few minutes and try again.', 'quick-events-manager' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Count one submission against the rate limit.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private function record_attempt() {
		$key = $this->rate_limit_key();

		if ( '' === $key ) {
			return;
		}

		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	/**
	 * The transient key identifying this visitor.
	 *
	 * @since 26.0
	 *
	 * @return string Empty when the address is unavailable.
	 */
	private function rate_limit_key() {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $address ) {
			return '';
		}

		return 'qem_rl_' . substr( hash( 'sha256', $address . wp_salt() ), 0, 24 );
	}
}
