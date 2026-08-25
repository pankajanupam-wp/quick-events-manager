<?php
/**
 * Money going back.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Domain\TransactionKind;
use QuickEventsManager\Domain\TransactionStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Refunding an order, wholly or partly, and what each of those means to a place.
 *
 * **A partial refund is not a cancellation.** Somebody who was given £5 back
 * off a £25 ticket is still coming, and taking their seat away would be a worse
 * outcome than the overcharge was. Only a refund that returns everything the
 * order was worth releases the place — and that is decided from the ledger,
 * which is the only thing that knows what has already gone back.
 *
 * **The gateway moves the money; this decides what it means.** The ledger row is
 * written here rather than inside the gateway, so that one place decides what a
 * refund does to an order however many gateways there eventually are.
 *
 * @since 26.0
 */
final class Refunds {

	/**
	 * Send money back.
	 *
	 * @since 26.0
	 *
	 * @param int    $order_id     Order id.
	 * @param int    $amount_minor How much, positive; 0 means everything left.
	 * @param string $reason       Why, for the records.
	 * @return true|\WP_Error
	 */
	public static function issue( int $order_id, int $amount_minor = 0, string $reason = '' ) {
		$order = OrderRepository::find( $order_id );

		if ( null === $order ) {
			return new \WP_Error( 'qevm_refund_no_order', __( 'That order could not be found.', 'quick-events-manager' ) );
		}

		if ( ! $order->status()->is_settled() ) {
			return new \WP_Error( 'qevm_refund_not_paid', __( 'There is nothing to refund on an order that was never paid.', 'quick-events-manager' ) );
		}

		$remaining = $order->total_minor() - TransactionRepository::refunded_for_order( $order_id );

		if ( $remaining <= 0 ) {
			return new \WP_Error( 'qevm_refund_nothing_left', __( 'This order has already been refunded in full.', 'quick-events-manager' ) );
		}

		$amount_minor = 0 === $amount_minor ? $remaining : abs( $amount_minor );

		if ( $amount_minor > $remaining ) {
			/*
			 * Refused rather than clamped. Somebody typing more than is left
			 * has misunderstood something — which order, or how much has
			 * already gone back — and quietly refunding a different number than
			 * they asked for is how that misunderstanding survives.
			 */
			return new \WP_Error(
				'qevm_refund_too_much',
				sprintf(
					/* translators: %s: Amount still refundable. */
					__( 'Only %s is left to refund on this order.', 'quick-events-manager' ),
					Money::from_minor( $remaining, $order->currency() )->format()
				)
			);
		}

		$gateway = Gateways::get( $order->gateway() );

		if ( null === $gateway ) {
			return new \WP_Error( 'qevm_refund_no_gateway', __( 'The gateway this order was paid through is not available.', 'quick-events-manager' ) );
		}

		$reference = $gateway->refund( $order, $amount_minor, $reason );

		if ( is_wp_error( $reference ) ) {
			return $reference;
		}

		$recorded = TransactionRepository::record(
			array(
				'order_id'       => $order_id,
				'kind'           => TransactionKind::Refund,
				'status'         => TransactionStatus::Succeeded,
				'amount_minor'   => $amount_minor,
				'currency'       => $order->currency(),
				'gateway'        => $order->gateway(),
				'gateway_txn_id' => (string) $reference,
				'reason'         => $reason,
			)
		);

		if ( 0 === $recorded ) {
			/*
			 * The ledger already had this refund — the same reference, which
			 * means the gateway returned an earlier one rather than making a
			 * new one. Nothing further to do, and certainly not a second
			 * release of the place.
			 */
			return true;
		}

		self::consider_the_place( $order_id );

		return true;
	}

	/**
	 * Whether everything has gone back, and if so, let the place go.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private static function consider_the_place( int $order_id ) {
		$order = OrderRepository::find( $order_id );

		if ( null === $order ) {
			return;
		}

		if ( TransactionRepository::refunded_for_order( $order_id ) < $order->total_minor() ) {
			return;
		}

		/**
		 * Fires when an order has been refunded in full.
		 *
		 * The registration module listens and cancels the booking, which frees
		 * the place and moves the waiting list on — the same path a
		 * cancellation takes, rather than a second one to keep in step with it.
		 *
		 * @since 26.0
		 *
		 * @param int $registration_id The booking.
		 * @param int $order_id        The order.
		 */
		do_action( 'qevm_booking_refunded', OrderService::booking_for( $order_id ), $order_id );
	}
}
