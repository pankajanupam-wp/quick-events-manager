<?php
/**
 * A Woo order, as a place at an event.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Woo;

use QuickEventsManager\Domain\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Turning what somebody bought into somebody who is coming.
 *
 * **The booking is made when the order is paid, not when it is placed.** This is
 * the one place the Woo bridge deliberately behaves unlike the built-in
 * checkout, and it is because Woo already owns the part in between: it holds the
 * basket, it expires the session, it knows about failed and cancelled orders and
 * about the customer who comes back an hour later. Reserving a seat at
 * `checkout` and then having to decide when Woo has given up on it would be
 * reimplementing a stock system Woo already has.
 *
 * The trade is stated rather than hidden: with this bridge, a seat is not held
 * while somebody is at the payment step. For the sites this is aimed at — ones
 * that already run a shop — that is how everything else in their shop already
 * behaves.
 *
 * **A refund in Woo cancels the booking**, so the organiser never has to
 * remember to do it twice. Woo's own screens stay the place money is managed.
 *
 * @since 26.0
 */
final class Orders {

	/**
	 * Meta on the Woo order line naming the booking it produced.
	 */
	const BOOKING_META = '_qevm_registration_id';

	/**
	 * Add the hooks.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'book' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'book' ), 10, 1 );

		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'release' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release' ), 10, 1 );
	}

	/**
	 * Make a booking for every ticket line on a paid order.
	 *
	 * Idempotent: Woo moves an order through several statuses and fires more
	 * than one of these hooks for the same purchase, so a line that already has
	 * a booking against it is left alone. Without that, one payment produces
	 * two people at the door with the same name.
	 *
	 * @since 26.0
	 *
	 * @param mixed $order_id Woo order id.
	 * @return void
	 */
	public static function book( $order_id ) {
		if ( ! WooModule::woo_is_active() ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$event_id   = Products::event_for( $product_id );
			$type_id    = Products::ticket_type_for( $product_id );

			if ( $event_id <= 0 || $type_id <= 0 ) {
				continue;
			}

			if ( (int) $item->get_meta( self::BOOKING_META ) > 0 ) {
				continue;
			}

			$registration = self::make_booking( $order, $event_id, $type_id, (int) $item->get_quantity() );

			if ( null === $registration ) {
				continue;
			}

			$item->add_meta_data( self::BOOKING_META, $registration->id(), true );
			$item->save();

			$order->add_order_note(
				sprintf(
					/* translators: %s: Booking reference. */
					__( 'Event booking created: %s', 'quick-events-manager' ),
					$registration->code()
				)
			);
		}
	}

	/**
	 * Give the places back when Woo says the money went back.
	 *
	 * @since 26.0
	 *
	 * @param mixed $order_id Woo order id.
	 * @return void
	 */
	public static function release( $order_id ) {
		if ( ! WooModule::woo_is_active() ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$registration_id = (int) $item->get_meta( self::BOOKING_META );

			if ( $registration_id <= 0 ) {
				continue;
			}

			$registration = \QuickEventsManager\Registration\Repository::find( $registration_id );

			if ( null === $registration || ! $registration->status()->occupies_place() ) {
				continue;
			}

			\QuickEventsManager\Registration\Repository::update_status( $registration_id, RegistrationStatus::Cancelled );
		}
	}

	/**
	 * Book one line.
	 *
	 * Through the ordinary registration service, so capacity, the waiting list
	 * and the confirmation email all work exactly as they do for a free
	 * booking. A purchase that arrives when the room is full becomes a waiting
	 * list place rather than an oversold seat — and the organiser can see it and
	 * refund it, which is the honest outcome.
	 *
	 * @since 26.0
	 *
	 * @param object $order    The Woo order.
	 * @param int    $event_id Event id.
	 * @param int    $type_id  Ticket type id.
	 * @param int    $quantity How many.
	 * @return \QuickEventsManager\Registration\Registration|null
	 */
	private static function make_booking( $order, int $event_id, int $type_id, int $quantity ) {
		$result = ( new \QuickEventsManager\Registration\RegistrationService() )->create(
			$event_id,
			array(
				'name'           => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'          => $order->get_billing_email(),
				'phone'          => $order->get_billing_phone(),
				'quantity'       => max( 1, $quantity ),
				'ticket_type_id' => $type_id,
				'guests'         => array(),

				/*
				 * Consent was given at the shop's own checkout, under the
				 * shop's terms. Asking again on a form nobody sees would be
				 * theatre; recording that it happened elsewhere is the honest
				 * version.
				 *
				 * Booked in the manual context for the same reason: this is not
				 * somebody filling in the public form, so the rate limit and the
				 * honeypot have nothing to do with it. Five people buying tickets
				 * from one office must not start being refused.
				 */
				'consent'        => true,
			),
			\QuickEventsManager\Registration\RegistrationService::CONTEXT_MANUAL
		);

		return $result instanceof \QuickEventsManager\Registration\Registration ? $result : null;
	}
}
