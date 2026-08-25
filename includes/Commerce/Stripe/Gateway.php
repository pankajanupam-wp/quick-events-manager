<?php
/**
 * Taking card payments through Stripe.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce\Stripe;

use QuickEventsManager\Commerce\Gateway as GatewayInterface;
use QuickEventsManager\Commerce\Order;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\TransactionKind;
use QuickEventsManager\Domain\TransactionStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The first-party gateway, and the only one this plugin ships.
 *
 * **PaymentIntents, not Charges.** The older API takes a card number and hands
 * back a result; the newer one expects the card to be collected by Stripe's own
 * elements and the payment to possibly bounce through a bank's authentication
 * screen before it completes. That is not an inconvenience to work around — it
 * is Strong Customer Authentication, it is the law in Europe, and a plugin built
 * on the older model starts failing in whole countries.
 *
 * **The intent is created here; the card is collected in the browser by Stripe.**
 * The plugin never sees a card number, which is the difference between needing
 * PCI compliance and not.
 *
 * **The ledger row is written before the customer is sent anywhere**, with the
 * intent's id on it. If the browser closes on the payment screen, the attempt is
 * still recorded and the webhook that arrives later has a row to find. Writing
 * it after would leave a paid intent with nothing pointing at it.
 *
 * @since 26.0
 */
final class Gateway implements GatewayInterface {

	/**
	 * Stored identifier.
	 */
	const ID = 'stripe';

	/**
	 * Stored identifier.
	 *
	 * @since 26.0
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * What somebody paying is shown.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return __( 'Card', 'quick-events-manager' );
	}

	/**
	 * Whether this site has what it needs to take a payment.
	 *
	 * Both keys, and a matching pair of them. A live secret with a test
	 * publishable key fails at the last step in front of a customer, so it
	 * counts as not configured rather than as configured-and-broken.
	 *
	 * @since 26.0
	 */
	public function is_configured(): bool {
		return Keys::are_complete() && Keys::are_consistent();
	}

	/**
	 * Start taking payment for an order.
	 *
	 * @since 26.0
	 *
	 * @param Order $order The order.
	 * @return array<string, string>|\WP_Error
	 */
	public function start( Order $order ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'qevm_stripe_not_configured', __( 'This site is not set up to take card payments yet.', 'quick-events-manager' ) );
		}

		if ( $order->total_minor() <= 0 ) {
			return new \WP_Error( 'qevm_stripe_nothing_to_pay', __( 'There is nothing to pay for this booking.', 'quick-events-manager' ) );
		}

		$key = self::idempotency_key( $order );

		$intent = Client::create_payment_intent(
			array(
				'amount'                    => $order->total_minor(),
				'currency'                  => strtolower( $order->currency() ),
				'description'               => $order->number(),

				/*
				 * Their address, so Stripe can send its own receipt, and the
				 * order number in metadata so a person looking at the Stripe
				 * dashboard can find the booking without a lookup table.
				 */
				'receipt_email'             => $order->billing_email(),
				'metadata'                  => array(
					'qevm_order'    => $order->number(),
					'qevm_order_id' => $order->id(),
					'qevm_site'     => home_url(),
				),
				'automatic_payment_methods' => array( 'enabled' => 'true' ),
			),
			$key
		);

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		$client_secret = (string) ( $intent['client_secret'] ?? '' );
		$intent_id     = (string) ( $intent['id'] ?? '' );

		if ( '' === $client_secret || '' === $intent_id ) {
			return new \WP_Error( 'qevm_stripe_unreadable', __( 'The payment provider sent back something we could not read.', 'quick-events-manager' ) );
		}

		TransactionRepository::record(
			array(
				'order_id'        => $order->id(),
				'kind'            => TransactionKind::Charge,
				'status'          => TransactionStatus::Pending,
				'amount_minor'    => $order->total_minor(),
				'currency'        => $order->currency(),
				'gateway'         => self::ID,
				'gateway_txn_id'  => $intent_id,
				'idempotency_key' => $key,
			)
		);

		return array(
			'intent'        => $intent_id,
			'client_secret' => $client_secret,
			'publishable'   => Keys::publishable(),
		);
	}

	/**
	 * Send money back.
	 *
	 * @since 26.0
	 *
	 * @param Order  $order        The order.
	 * @param int    $amount_minor How much, positive, in minor units.
	 * @param string $reason       Why.
	 * @return string|\WP_Error
	 */
	public function refund( Order $order, int $amount_minor, string $reason = '' ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'qevm_stripe_not_configured', __( 'This site is not set up to take card payments yet.', 'quick-events-manager' ) );
		}

		$charge = self::successful_charge( $order->id() );

		if ( null === $charge ) {
			return new \WP_Error( 'qevm_stripe_nothing_to_refund', __( 'There is no payment on this order to refund.', 'quick-events-manager' ) );
		}

		$amount_minor = abs( $amount_minor );

		$refund = Client::create_refund(
			array(
				'payment_intent' => $charge->gateway_txn_id(),
				'amount'         => $amount_minor,
				'metadata'       => array(
					'qevm_order'  => $order->number(),
					'qevm_reason' => $reason,
				),
			),
			self::idempotency_key( $order, 'refund-' . $amount_minor )
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		$id = (string) ( $refund['id'] ?? '' );

		return '' !== $id
			? $id
			: new \WP_Error( 'qevm_stripe_unreadable', __( 'The payment provider sent back something we could not read.', 'quick-events-manager' ) );
	}

	/**
	 * The successful charge on an order, if there is one.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return \QuickEventsManager\Commerce\Transaction|null
	 */
	private static function successful_charge( int $order_id ) {
		foreach ( TransactionRepository::for_order( $order_id ) as $transaction ) {
			if ( TransactionKind::Charge === $transaction->kind()
				&& TransactionStatus::Succeeded === $transaction->status()
				&& '' !== $transaction->gateway_txn_id() ) {
				return $transaction;
			}
		}

		return null;
	}

	/**
	 * A key that makes retrying an attempt safe.
	 *
	 * Derived from the order rather than random, which is the whole point: a
	 * request that times out on our side and is sent again carries the same key
	 * and Stripe returns the original result instead of charging twice. It is
	 * scoped to the site so that two installations restored from the same
	 * database backup cannot collide with each other.
	 *
	 * @since 26.0
	 *
	 * @param Order  $order   The order.
	 * @param string $purpose What this attempt is for.
	 */
	private static function idempotency_key( Order $order, string $purpose = 'charge' ): string {
		return substr(
			'qevm-' . $purpose . '-' . $order->number() . '-' . md5( home_url() . '|' . $order->id() . '|' . $purpose ),
			0,
			190
		);
	}
}
