<?php
/**
 * Signed, expiring links that let somebody withdraw their own booking.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

/**
 * The capability to cancel one booking, expressed as a URL.
 *
 * People who book through the public form are not logged in and never will be,
 * so there is no account to authenticate and no session to check. What proves
 * the request is genuine has to travel in the link itself.
 *
 * A booking reference alone is not enough. `QEVM-7F3K9A` is printed in the
 * confirmation email, quoted in replies, read out over the phone and pasted
 * into support tickets; anything that treats it as a secret is treating a
 * public identifier as a password. So the reference says *which* booking and a
 * keyed signature says the link was issued by this site:
 *
 *     ?qevm_cancel=QEVM-7F3K9A&qevm_expires=1789…&qevm_token=9f2c…
 *
 * The signature covers the expiry as well as the reference, which is the whole
 * reason it is safe to carry the expiry in the URL where anyone can see it.
 * Sign only the reference and the timestamp becomes a suggestion — change it
 * and the same signature still verifies.
 *
 * Three properties this deliberately has:
 *
 * - **Stateless.** Nothing is stored when the link is issued, so nothing has to
 *   be cleaned up, and a link keeps working if the confirmation email is read
 *   six weeks later on a different device.
 * - **Constant-time comparison.** `hash_equals()`, never `===`. A byte-at-a-time
 *   comparison leaks how much of a guess was right through how long the reply
 *   took, which is enough to reconstruct a signature one byte at a time.
 * - **Its own secret.** `wp_salt()` with a scheme of our own rather than the
 *   default `auth`, so a cancellation link is not signed with the key that
 *   protects logins. For an unrecognised scheme core derives a distinct value
 *   from the `secret_key` site option (pluggable.php), which is exactly the
 *   separation wanted.
 *
 * @since 26.0
 */
final class CancellationLink {

	/**
	 * Query argument holding the booking reference.
	 */
	const QUERY_VAR = 'qevm_cancel';

	/**
	 * Query argument holding the expiry timestamp.
	 */
	const EXPIRES_VAR = 'qevm_expires';

	/**
	 * Query argument holding the signature.
	 */
	const TOKEN_VAR = 'qevm_token';

	/**
	 * Salt scheme, so this key is not the one protecting logins.
	 */
	const SALT_SCHEME = 'qevm_cancellation';

	/**
	 * Hash algorithm. Length is asserted on the way in.
	 */
	const ALGO = 'sha256';

	/**
	 * How long a link lives when the event has no end date to expire against.
	 */
	const FALLBACK_LIFETIME = YEAR_IN_SECONDS;

	/**
	 * The cancellation URL for a booking.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Booking.
	 * @param Event        $event        Event it is for.
	 * @return string
	 */
	public static function url( Registration $registration, Event $event ) {
		$expires = self::expiry( $event );

		return add_query_arg(
			array(
				self::QUERY_VAR   => rawurlencode( $registration->code() ),
				self::EXPIRES_VAR => $expires,
				self::TOKEN_VAR   => self::sign( $registration->code(), $expires ),
			),
			get_permalink( $event->id() )
		) . '#qevm-registration';
	}

	/**
	 * Sign a reference and an expiry together.
	 *
	 * The two are joined with a character that cannot appear in either, so no
	 * pair of different inputs can produce the same signed string. Codes are
	 * alphanumeric with one hyphen and the expiry is digits, which makes `|`
	 * safe here — concatenating without a separator would not be.
	 *
	 * @since 26.0
	 *
	 * @param string $code    Booking reference.
	 * @param int    $expires Unix timestamp the link stops working at.
	 * @return string Hex signature.
	 */
	public static function sign( $code, $expires ) {
		return hash_hmac(
			self::ALGO,
			(string) $code . '|' . (int) $expires,
			wp_salt( self::SALT_SCHEME )
		);
	}

	/**
	 * Whether a signature is genuine and still current.
	 *
	 * Order matters slightly: the signature is checked before the clock, so an
	 * expired-but-genuine link and a forged one are distinguishable to the
	 * caller and can be given different wording. Telling somebody their link
	 * has expired when it was never valid would send them looking for a
	 * newer email that does not exist.
	 *
	 * @since 26.0
	 *
	 * @param string $code    Booking reference from the URL.
	 * @param mixed  $expires Expiry from the URL.
	 * @param string $token   Signature from the URL.
	 * @return true|\WP_Error True, or why not.
	 */
	public static function verify( $code, $expires, $token ) {
		$code    = (string) $code;
		$token   = (string) $token;
		$expires = is_numeric( $expires ) ? (int) $expires : 0;

		if ( '' === $code || '' === $token || $expires <= 0 ) {
			return new \WP_Error(
				'qevm_cancel_incomplete',
				__( 'That cancellation link is incomplete. Please use the link exactly as it appears in your confirmation email.', 'quick-events-manager' )
			);
		}

		/*
		 * hash_equals() throws on a non-string and returns false immediately
		 * for a length mismatch, so the length is settled first. It also means
		 * a truncated URL fails here rather than in the comparison.
		 */
		$expected = self::sign( $code, $expires );

		if ( strlen( $token ) !== strlen( $expected ) || ! hash_equals( $expected, $token ) ) {
			return new \WP_Error(
				'qevm_cancel_invalid',
				__( 'That cancellation link is not valid. Please use the link from your confirmation email.', 'quick-events-manager' )
			);
		}

		if ( $expires < time() ) {
			return new \WP_Error(
				'qevm_cancel_expired',
				__( 'That cancellation link has expired. Please contact the organiser to cancel your booking.', 'quick-events-manager' )
			);
		}

		return true;
	}

	/**
	 * When a link issued for this event should stop working.
	 *
	 * The event's own end, because cancelling a booking for something that has
	 * already happened does nothing useful — the place cannot be given to
	 * anybody else, and the attendance record is worth keeping. An event with
	 * no usable end date falls back to a year, which is long enough that the
	 * link outlives the reason somebody kept the email.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return int Unix timestamp.
	 */
	public static function expiry( Event $event ) {
		$end       = $event->end_utc();
		$timestamp = '' !== $end ? strtotime( $end . ' UTC' ) : false;

		if ( false === $timestamp || $timestamp <= time() ) {
			$timestamp = time() + self::FALLBACK_LIFETIME;
		}

		/**
		 * Filters when a cancellation link expires.
		 *
		 * @since 26.0
		 *
		 * @param int   $timestamp Unix timestamp.
		 * @param Event $event     Event the link is for.
		 */
		return (int) apply_filters( 'qevm_cancellation_link_expiry', $timestamp, $event );
	}

	/**
	 * The signed request present in the current URL, if there is one.
	 *
	 * Reads the query string rather than a parsed query variable, because the
	 * link is followed on the event permalink and these three arguments are
	 * ours rather than WordPress's.
	 *
	 * @since 26.0
	 *
	 * @return array{code: string, expires: int, token: string}|null
	 */
	public static function from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a signed link, not acting on it; the signature is the verification and the POST that follows carries a nonce.
		if ( ! isset( $_GET[ self::QUERY_VAR ], $_GET[ self::EXPIRES_VAR ], $_GET[ self::TOKEN_VAR ] ) ) {
			return null;
		}

		$request = array(
			'code'    => sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ),
			'expires' => (int) $_GET[ self::EXPIRES_VAR ],
			'token'   => sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_VAR ] ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $request;
	}
}
