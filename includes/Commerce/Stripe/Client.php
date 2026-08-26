<?php
/**
 * Talking to Stripe.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this plugin makes a request to somebody else's server.
 *
 * Written against `wp_remote_post()` rather than Stripe's PHP library, because
 * shipping a vendored SDK to wordpress.org means shipping its dependency tree,
 * its autoloader and whatever version of it another plugin loaded first — the
 * classic way two plugins break each other. The API is a form post; three
 * endpoints are needed and each is a handful of fields.
 *
 * **Every request carries an idempotency key.** Stripe honours it for 24 hours:
 * a request that times out on our side and is retried creates one charge, not
 * two. This is not defensive programming, it is the difference between a network
 * blip and charging somebody twice.
 *
 * **Nothing here logs a key, a card or a response body.** An error carries
 * Stripe's own message, which is written for a customer to read.
 *
 * @since 26.0
 */
final class Client {

	/**
	 * API root.
	 */
	const BASE = 'https://api.stripe.com/v1/';

	/**
	 * The version of the API this code was written against.
	 *
	 * Pinned deliberately. Stripe rolls its API forward and an account's
	 * default version moves with it; without this header, somebody else's
	 * account upgrade changes the shape of our responses on a day nobody
	 * touched this plugin.
	 */
	const API_VERSION = '2024-06-20';

	/**
	 * How long to wait for Stripe, in seconds.
	 *
	 * Longer than the WordPress default of five, because a payment request
	 * abandoned at five seconds may already have created the charge — and then
	 * the customer sees a failure for money that left their account.
	 */
	const TIMEOUT = 20;

	/**
	 * Create a PaymentIntent.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $fields          Stripe's own field names.
	 * @param string               $idempotency_key Key for this attempt.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_payment_intent( array $fields, string $idempotency_key ) {
		return self::post( 'payment_intents', $fields, $idempotency_key );
	}

	/**
	 * Read a PaymentIntent back.
	 *
	 * The return flow asks Stripe what happened rather than believing what the
	 * browser said happened: the customer's browser is not a trustworthy source
	 * on whether money moved.
	 *
	 * @since 26.0
	 *
	 * @param string $id PaymentIntent id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_payment_intent( string $id ) {
		return self::get( 'payment_intents/' . rawurlencode( $id ) );
	}

	/**
	 * Refund a charge, wholly or partly.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $fields          Stripe's own field names.
	 * @param string               $idempotency_key Key for this attempt.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_refund( array $fields, string $idempotency_key ) {
		return self::post( 'refunds', $fields, $idempotency_key );
	}

	/**
	 * A POST to Stripe.
	 *
	 * @since 26.0
	 *
	 * @param string               $path            Endpoint after the version.
	 * @param array<string, mixed> $fields          Body fields.
	 * @param string               $idempotency_key Key for this attempt.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function post( string $path, array $fields, string $idempotency_key = '' ) {
		$secret = Keys::secret();

		if ( '' === $secret ) {
			return new \WP_Error( 'qevm_stripe_no_key', __( 'This site is not set up to take card payments yet.', 'quick-events-manager' ) );
		}

		$headers = self::headers( $secret );

		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_remote_post(
			self::BASE . $path,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => self::flatten( $fields ),
			)
		);

		return self::read( $response );
	}

	/**
	 * A GET from Stripe.
	 *
	 * @since 26.0
	 *
	 * @param string $path Endpoint after the version.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function get( string $path ) {
		$secret = Keys::secret();

		if ( '' === $secret ) {
			return new \WP_Error( 'qevm_stripe_no_key', __( 'This site is not set up to take card payments yet.', 'quick-events-manager' ) );
		}

		$response = wp_remote_get(
			self::BASE . $path,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => self::headers( Keys::secret() ),
			)
		);

		return self::read( $response );
	}

	/**
	 * The headers every request carries.
	 *
	 * @since 26.0
	 *
	 * @param string $secret Secret key.
	 * @return array<string, string>
	 */
	private static function headers( string $secret ): array {
		return array(
			'Authorization'  => 'Bearer ' . $secret,
			'Stripe-Version' => self::API_VERSION,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		);
	}

	/**
	 * Turn a response into an array, or into an error worth showing somebody.
	 *
	 * Three different failures, told apart because they need different answers:
	 * the request never arrived, Stripe refused it, or Stripe answered with
	 * something that is not JSON. The last one is the one that silently becomes
	 * `null` and then a fatal three lines later if it is not checked.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed>|\WP_Error $response What wp_remote_* returned.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function read( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'qevm_stripe_unreachable',
				__( 'We could not reach the payment provider. Please try again in a moment.', 'quick-events-manager' ),
				array( 'detail' => $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'qevm_stripe_unreadable',
				__( 'The payment provider sent back something we could not read.', 'quick-events-manager' ),
				array( 'status' => $code )
			);
		}

		if ( $code >= 400 || isset( $body['error'] ) ) {
			$error = isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();

			return new \WP_Error(
				'qevm_stripe_refused',
				(string) ( $error['message'] ?? __( 'The payment could not be started.', 'quick-events-manager' ) ),
				array(
					'status' => $code,
					'code'   => (string) ( $error['code'] ?? '' ),
					'type'   => (string) ( $error['type'] ?? '' ),
				)
			);
		}

		return $body;
	}

	/**
	 * Flatten nested fields into Stripe's bracketed form encoding.
	 *
	 * `array( 'metadata' => array( 'order' => 'QEVO-1' ) )` becomes
	 * `metadata[order]=QEVO-1`, which is how Stripe expects structure in a form
	 * post.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $fields Fields, possibly nested.
	 * @param string               $prefix Parent key, for recursion.
	 * @return array<string, string>
	 */
	private static function flatten( array $fields, string $prefix = '' ): array {
		$flat = array();

		foreach ( $fields as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				$flat = array_merge( $flat, self::flatten( $value, $name ) );

				continue;
			}

			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$flat[ $name ] = (string) $value;
		}

		return $flat;
	}
}
