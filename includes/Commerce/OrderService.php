<?php
/**
 * The life of an order.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Domain\OrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Opening an order, finishing one, and letting one go.
 *
 * **Nothing here names a class from another module.** An order is opened from a
 * described basket — ids, quantities, a price and two snapshots — rather than
 * from a `Registration` object, and what happens to the booking afterwards is an
 * action other modules listen to. Commerce does not know how a booking is
 * stored; registration does not know that money exists. The same discipline as
 * `qevm_expected_attendees` and `qevm_event_ticket_types`, in the one direction
 * that had not needed it yet: telling somebody else's module that something has
 * happened.
 *
 * **A seat is held by the booking, not by the order.** The booking already
 * exists and is already `pending`, and pending already counts as occupying a
 * place — that is what makes insert-then-rank work. So a hold is a moment in
 * time written on the order, and a sweep that lets go of anything past it, not
 * a second capacity mechanism that could disagree with the first.
 *
 * @since 26.0
 */
final class OrderService {

	/**
	 * How long a checkout is given before its seats go back, in minutes.
	 *
	 * Long enough to find a card and get through a bank's verification screen;
	 * short enough that a sold-out event is not sold out because of people who
	 * closed the tab. Twenty minutes is the compromise most ticketing sites
	 * land on, and it is filterable because a conference selling £400 tickets
	 * and a yoga class selling £8 ones do not want the same number.
	 *
	 * @since 26.0
	 */
	public static function hold_minutes(): int {
		/**
		 * Filter how long an unpaid checkout holds its seats.
		 *
		 * @since 26.0
		 *
		 * @param int $minutes Minutes.
		 */
		$minutes = (int) apply_filters( 'qevm_hold_minutes', 20 );

		return max( 1, $minutes );
	}

	/**
	 * Open an order for a booking that is waiting to be paid for.
	 *
	 * The basket is described rather than looked up: the caller is holding the
	 * ticket type as it is at this moment, and re-reading it here is how a
	 * price change halfway through a checkout ends up in the wrong row. Each
	 * line is a snapshot from then on — see
	 * [ADR-0006](../../docs/adr/0006-money-and-immutability.md).
	 *
	 * @since 26.0
	 *
	 * @param int                  $registration_id The booking this pays for.
	 * @param array<string, mixed> $basket          Keys: lines, billing_name,
	 *                                              billing_email, user_id, gateway.
	 * @return int New order id, or 0.
	 */
	public static function open( int $registration_id, array $basket ): int {
		$lines = isset( $basket['lines'] ) && is_array( $basket['lines'] ) ? $basket['lines'] : array();

		if ( array() === $lines ) {
			return 0;
		}

		$currency = Currency::site();
		$subtotal = 0;
		$tax      = 0;

		foreach ( $lines as $line ) {
			$quantity  = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$subtotal += (int) ( $line['unit_price_minor'] ?? 0 ) * $quantity;
			$tax      += (int) ( $line['tax_minor'] ?? 0 );
		}

		$order_id = OrderRepository::create(
			array(
				'user_id'          => (int) ( $basket['user_id'] ?? 0 ),
				'status'           => OrderStatus::Pending->value,
				'currency'         => $currency,
				'subtotal_minor'   => $subtotal,
				'tax_minor'        => $tax,
				'total_minor'      => $subtotal + $tax,
				'billing_name'     => (string) ( $basket['billing_name'] ?? '' ),
				'billing_email'    => (string) ( $basket['billing_email'] ?? '' ),
				'gateway'          => (string) ( $basket['gateway'] ?? '' ),
				'hold_expires_utc' => gmdate( 'Y-m-d H:i:s', time() + ( self::hold_minutes() * MINUTE_IN_SECONDS ) ),
			)
		);

		if ( 0 === $order_id ) {
			return 0;
		}

		foreach ( $lines as $line ) {
			OrderItemRepository::add( array_merge( $line, array( 'order_id' => $order_id ) ) );
		}

		/**
		 * Fires when an order has been opened for a booking.
		 *
		 * The registration module listens for this to record which order a
		 * booking is being paid through. Carries ids rather than objects, so
		 * neither module has to know what the other stores.
		 *
		 * @since 26.0
		 *
		 * @param int $order_id        The new order.
		 * @param int $registration_id The booking it pays for.
		 */
		do_action( 'qevm_order_opened', $order_id, $registration_id );

		return $order_id;
	}

	/**
	 * The money arrived.
	 *
	 * Marks the order paid, releases its hold and tells whoever owns the
	 * booking. Idempotent: a gateway that says "paid" twice — and they do —
	 * changes nothing the second time and fires nothing the second time, which
	 * is what stops a duplicate webhook sending two confirmation emails.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether this call is what paid it.
	 */
	public static function complete( int $order_id ): bool {
		$order = OrderRepository::find( $order_id );

		if ( null === $order || OrderStatus::Pending !== $order->status() ) {
			return false;
		}

		OrderRepository::mark_paid( $order_id );

		/**
		 * Fires when a booking has been paid for.
		 *
		 * The registration module listens for this and confirms the booking.
		 *
		 * @since 26.0
		 *
		 * @param int $registration_id The booking.
		 * @param int $order_id        The order that paid for it.
		 */
		do_action( 'qevm_booking_paid_for', self::booking_for( $order_id ), $order_id );

		return true;
	}

	/**
	 * Nobody came back.
	 *
	 * Marks the order abandoned and lets the booking go, which is what actually
	 * frees the seat — and freeing a seat is what promotes whoever is waiting
	 * for it, through the machinery that already exists for a cancellation.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether this call is what abandoned it.
	 */
	public static function abandon( int $order_id ): bool {
		$order = OrderRepository::find( $order_id );

		if ( null === $order || OrderStatus::Pending !== $order->status() ) {
			return false;
		}

		OrderRepository::update(
			$order_id,
			array(
				'status'           => OrderStatus::Abandoned->value,
				'hold_expires_utc' => '',
			)
		);

		/**
		 * Fires when no payment is coming for a booking.
		 *
		 * Either the hold ran out or the gateway said the order is finished;
		 * the booking's fate is the same and the difference is recorded on the
		 * order. The registration module listens for this and cancels the
		 * booking, which releases the place and lets the waiting list move.
		 *
		 * @since 26.0
		 *
		 * @param int $registration_id The booking.
		 * @param int $order_id        The order that was abandoned.
		 */
		do_action( 'qevm_booking_payment_abandoned', self::booking_for( $order_id ), $order_id );

		return true;
	}

	/**
	 * This order is not going to be paid.
	 *
	 * **A declined card is not this.** Somebody whose card is refused is still
	 * standing there and will try another one, and their seat is still theirs
	 * until the hold runs out like anybody else's — the attempt belongs in the
	 * ledger as a failed transaction, which is what the ledger is for. This is
	 * for the gateway saying the order itself is finished: cancelled at the
	 * gateway's end, or an authorisation that can no longer be completed.
	 *
	 * So it releases the seat, exactly as an expired hold does. The first
	 * version of this left the order `failed` with its hold intact, and the
	 * sweep only looks at pending orders — so the seat would have been held
	 * until the end of time. That is the same bug this whole chunk exists to
	 * prevent, wearing a different status.
	 *
	 * @since 26.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $code     The gateway's error code, if it gave one.
	 * @return bool Whether this call is what ended it.
	 */
	public static function fail( int $order_id, string $code = '' ): bool {
		$order = OrderRepository::find( $order_id );

		if ( null === $order || OrderStatus::Pending !== $order->status() ) {
			return false;
		}

		OrderRepository::update(
			$order_id,
			array(
				'status'           => OrderStatus::Failed->value,
				'hold_expires_utc' => '',
			)
		);

		unset( $code );

		/** This action is documented in includes/Commerce/OrderService.php */
		do_action( 'qevm_booking_payment_abandoned', self::booking_for( $order_id ), $order_id );

		return true;
	}

	/**
	 * Which booking an order is for.
	 *
	 * Asked of whoever owns bookings rather than read from a table here.
	 * Answering it is the registration module's job, and with that module off
	 * nobody answers, which is the correct outcome: there is no booking.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return int Registration id, or 0.
	 */
	public static function booking_for( int $order_id ): int {
		/**
		 * Filter which booking an order is paying for.
		 *
		 * @since 26.0
		 *
		 * @param int $registration_id Booking id, 0 if unknown.
		 * @param int $order_id        The order.
		 */
		return (int) apply_filters( 'qevm_order_booking', 0, $order_id );
	}
}
