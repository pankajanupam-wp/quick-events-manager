<?php
/**
 * What Stripe tells us, unprompted.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce\Stripe;

use QuickEventsManager\Commerce\Checkout;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\OrderService;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\TransactionStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The endpoint Stripe posts to, and the two things that make it safe.
 *
 * **The return flow is not enough on its own.** A customer who pays and then
 * closes the tab before the redirect completes has paid, and nothing on the site
 * knows it: their place would be released by the sweep while their money sits in
 * Stripe. The webhook is how that booking gets confirmed anyway, which is why it
 * is not optional polish.
 *
 * **Signature verification, because this URL is public.** Anybody can post
 * "payment succeeded" to it. Stripe signs every delivery with a shared secret
 * and a timestamp; the signature is checked before the body is looked at, and
 * the timestamp is checked so that a delivery captured today cannot be replayed
 * next year. Compared with `hash_equals()`, because comparing secrets with
 * `===` leaks their contents through how long the comparison takes.
 *
 * **Idempotency, because Stripe delivers twice by design.** Not an edge case —
 * their own documentation says to expect it. The defence is the same one used
 * for capacity and for check-in: the database's unique key, not a lookup
 * followed by a decision. `OrderService::complete()` refuses anything that is
 * not pending, so the second delivery confirms nothing twice and emails nobody
 * twice.
 *
 * @since 26.0
 */
final class Webhook {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_ID = 'qevm/v1';

	/**
	 * How far out of step a delivery's timestamp may be, in seconds.
	 *
	 * Five minutes, which is Stripe's own recommendation. Wide enough for
	 * ordinary clock drift on a shared host, narrow enough that a captured
	 * delivery cannot be replayed later.
	 */
	const TOLERANCE = 300;

	/**
	 * Add the route.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'add_route' ) );
	}

	/**
	 * Register the endpoint.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_route() {
		register_rest_route(
			self::NAMESPACE_ID,
			'/stripe/webhook',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive' ),

					/*
					 * Public, and it has to be: Stripe is not a logged-in user
					 * and never will be. The signature is the authentication,
					 * which is why it is checked first and why nothing before
					 * that check touches the body.
					 */
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * The address to give Stripe.
	 *
	 * @since 26.0
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE_ID . '/stripe/webhook' );
	}

	/**
	 * Handle a delivery.
	 *
	 * @since 26.0
	 *
	 * @param \WP_REST_Request $request The delivery.
	 * @return \WP_REST_Response
	 */
	public function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = Keys::webhook_secret();

		if ( '' === $secret ) {
			return new \WP_REST_Response(
				array(
					'received' => false,
					'reason'   => 'not_configured',
				),
				400
			);
		}

		$body      = (string) $request->get_body();
		$signature = (string) $request->get_header( 'stripe_signature' );

		if ( ! self::signature_is_good( $body, $signature, $secret ) ) {
			/*
			 * 400 rather than 401. Stripe retries a 5xx and gives up on a 4xx,
			 * and a delivery we cannot authenticate is one we never want again
			 * — retrying it would just repeat the same failure for days.
			 */
			return new \WP_REST_Response(
				array(
					'received' => false,
					'reason'   => 'bad_signature',
				),
				400
			);
		}

		$event = json_decode( $body, true );

		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response(
				array(
					'received' => false,
					'reason'   => 'unreadable',
				),
				400
			);
		}

		$handled = self::act_on( $event );

		/*
		 * 200 whatever happened, once the delivery is genuine. An event this
		 * plugin does not care about is not a failure, and answering anything
		 * else teaches Stripe to retry it — and eventually to disable the
		 * endpoint, which would take the events we do care about with it.
		 */
		return new \WP_REST_Response(
			array(
				'received' => true,
				'handled'  => $handled,
			),
			200
		);
	}

	/**
	 * Do whatever this event means, if it means anything here.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $event Stripe's event.
	 * @return bool Whether it was one we act on.
	 */
	private static function act_on( array $event ): bool {
		$type   = (string) ( $event['type'] ?? '' );
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] )
			? $event['data']['object']
			: array();

		$intent = (string) ( $object['id'] ?? '' );

		if ( '' === $intent ) {
			return false;
		}

		$transaction = TransactionRepository::find_by_gateway_txn( Gateway::ID, $intent );

		if ( null === $transaction ) {
			return false;
		}

		$order = OrderRepository::find( $transaction->order_id() );

		if ( null === $order ) {
			return false;
		}

		if ( 'payment_intent.succeeded' === $type ) {
			/*
			 * Asked of Stripe rather than taken from the delivery, even though
			 * the delivery is signed and says so. One path to "this order is
			 * paid", shared with the return flow, so there is one place where
			 * that decision can be wrong.
			 */
			Checkout::settle_from_stripe( $order, $intent );

			return true;
		}

		if ( 'payment_intent.payment_failed' === $type ) {
			TransactionRepository::settle(
				$transaction->id(),
				TransactionStatus::Failed,
				array( 'error_code' => (string) ( $object['last_payment_error']['code'] ?? '' ) )
			);

			return true;
		}

		if ( 'payment_intent.canceled' === $type ) {
			OrderService::fail( $order->id(), 'canceled' );

			return true;
		}

		return false;
	}

	/**
	 * Whether a delivery really came from Stripe.
	 *
	 * The header looks like `t=1698765432,v1=abc…,v1=def…`. The signed payload
	 * is the timestamp, a full stop and the raw body — so it has to be the raw
	 * body, byte for byte, and not something JSON has been through and back.
	 *
	 * @since 26.0
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature The `Stripe-Signature` header.
	 * @param string $secret    Signing secret.
	 * @return bool
	 */
	public static function signature_is_good( string $body, string $signature, string $secret ): bool {
		if ( '' === $signature || '' === $secret ) {
			return false;
		}

		$timestamp  = '';
		$candidates = array();

		foreach ( explode( ',', $signature ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			}

			if ( 'v1' === $pair[0] ) {
				$candidates[] = $pair[1];
			}
		}

		if ( '' === $timestamp || array() === $candidates ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		foreach ( $candidates as $candidate ) {
			/*
			 * hash_equals(), not ===. String comparison stops at the first
			 * byte that differs, so how long it takes says how much of the
			 * secret was guessed correctly — which is enough to recover it one
			 * byte at a time.
			 */
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return false;
	}
}
