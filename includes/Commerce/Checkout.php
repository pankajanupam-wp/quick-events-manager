<?php
/**
 * Getting from a booking to a paid place.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Commerce\Stripe\Gateway as StripeGateway;
use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\TransactionStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The screen somebody pays on, and what happens when they come back.
 *
 * **A paid booking is a booking that exists and is not confirmed yet.** It is
 * inserted like any other, which is what makes it hold its place in the queue,
 * and then held at `pending` until the money arrives. It does not wait to be
 * created until after payment: a seat that is only reserved once the card
 * clears is a seat two people can be paying for at the same time.
 *
 * **They come back through Stripe, and we ask Stripe what happened.** The
 * browser is not a trustworthy source on whether money moved — anybody can type
 * a return URL — so the return handler reads the PaymentIntent from Stripe's own
 * API and believes that. The same answer arrives again by webhook in C9.5, and
 * arriving twice is fine: completing an order twice does nothing the second
 * time.
 *
 * @since 26.0
 */
final class Checkout {

	/**
	 * Query argument naming the order being paid for.
	 */
	const PAY_ARG = 'qevm_pay';

	/**
	 * Add the hooks.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		/*
		 * Before the confirmation email, which is hooked at 10. A booking that
		 * has not been paid for must not be told its place is confirmed, and
		 * the way that is arranged is by being pending by the time anybody
		 * asks.
		 */
		add_action( 'qevm_registration_created', array( __CLASS__, 'hold_for_payment' ), 5, 2 );

		add_filter( 'qevm_booking_awaits_payment', array( __CLASS__, 'is_awaiting_payment' ), 10, 2 );
		add_filter( 'qevm_booking_destination', array( __CLASS__, 'send_to_checkout' ), 10, 2 );

		add_action( 'template_redirect', array( __CLASS__, 'handle_return' ) );
		add_filter( 'the_content', array( __CLASS__, 'inject_checkout' ), 20 );
	}

	/**
	 * Hold a booking that has to be paid for.
	 *
	 * @since 26.0
	 *
	 * @param mixed $registration The booking.
	 * @param mixed $event        The event.
	 * @return void
	 */
	public static function hold_for_payment( $registration, $event ) {
		if ( ! is_object( $registration ) || ! is_object( $event ) ) {
			return;
		}

		$due = self::amount_due( $registration, $event );

		if ( $due <= 0 || ! Gateways::any_usable() ) {
			return;
		}

		/*
		 * Only a booking that has a place. Somebody on the waiting list has
		 * nothing to pay for yet, and asking them for money for a place they
		 * may never get is the worst thing this screen could do.
		 */
		if ( 'confirmed' !== $registration->status()->value ) {
			return;
		}

		/**
		 * Fires when a booking needs paying for before it counts.
		 *
		 * The registration module listens and holds the booking at pending: it
		 * keeps its place in the room, and it is not confirmed until the money
		 * arrives. Fired before the order is opened, so nothing can observe a
		 * confirmed booking with an unpaid order against it.
		 *
		 * @since 26.0
		 *
		 * @param int $registration_id The booking.
		 */
		do_action( 'qevm_booking_awaiting_payment', $registration->id() );

		OrderService::open(
			$registration->id(),
			array(
				'user_id'       => (int) $registration->get( 'user_id', 0 ),
				'billing_name'  => $registration->booker_name(),
				'billing_email' => $registration->booker_email(),
				'gateway'       => StripeGateway::ID,
				'lines'         => array(
					array(
						'event_id'         => $event->id(),
						'occurrence_id'    => $registration->occurrence_id(),
						'ticket_type_id'   => $registration->ticket_type_id(),
						'name_snapshot'    => self::ticket_name( $registration, $event ),
						'event_snapshot'   => wp_strip_all_tags( get_the_title( $event->id() ) ),
						'unit_price_minor' => self::unit_price( $registration, $event ),
						'quantity'         => $registration->quantity(),
					),
				),
			)
		);
	}

	/**
	 * Whether a booking is waiting for money.
	 *
	 * Answers `qevm_booking_awaits_payment`, which is how the registration
	 * module knows not to send a confirmation yet without knowing what a
	 * payment is.
	 *
	 * @since 26.0
	 *
	 * @param mixed $awaiting Answer so far.
	 * @param mixed $registration_id The booking.
	 * @return bool
	 */
	public static function is_awaiting_payment( $awaiting, $registration_id ) {
		$order = self::order_for_booking( (int) $registration_id );

		if ( null === $order ) {
			return (bool) $awaiting;
		}

		return OrderStatus::Pending === $order->status();
	}

	/**
	 * Send somebody who owes money to the payment screen.
	 *
	 * Answers `qevm_booking_destination`, so the registration form does not
	 * need to know that a checkout exists.
	 *
	 * @since 26.0
	 *
	 * @param mixed $url             Where they were going.
	 * @param mixed $registration_id The booking.
	 * @return string
	 */
	public static function send_to_checkout( $url, $registration_id ) {
		$order = self::order_for_booking( (int) $registration_id );

		if ( null === $order || OrderStatus::Pending !== $order->status() ) {
			return (string) $url;
		}

		return add_query_arg( self::PAY_ARG, rawurlencode( $order->number() ), (string) $url );
	}

	/**
	 * Put the payment screen on the page when somebody is paying.
	 *
	 * @since 26.0
	 *
	 * @param mixed $content The post content.
	 * @return string
	 */
	public static function inject_checkout( $content ) {
		$content = (string) $content;

		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$order = self::order_being_paid();

		if ( null === $order ) {
			return $content;
		}

		return $content . self::render( $order );
	}

	/**
	 * Handle somebody arriving back from Stripe.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function handle_return() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a return from a payment provider; the answer is verified with the provider, not with this request.
		$intent = isset( $_GET['payment_intent'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) ) : '';

		if ( '' === $intent ) {
			return;
		}

		$order = self::order_being_paid();

		if ( null === $order ) {
			return;
		}

		self::settle_from_stripe( $order, $intent );
	}

	/**
	 * Ask Stripe what happened to an intent, and act on the answer.
	 *
	 * Shared with the webhook handler in C9.5, because "what does Stripe say
	 * about this intent" should have exactly one answer wherever the question
	 * comes from.
	 *
	 * @since 26.0
	 *
	 * @param Order  $order  The order.
	 * @param string $intent PaymentIntent id.
	 * @return bool Whether the order ended up paid.
	 */
	public static function settle_from_stripe( Order $order, string $intent ): bool {
		$transaction = TransactionRepository::find_by_gateway_txn( StripeGateway::ID, $intent );

		if ( null === $transaction || $transaction->order_id() !== $order->id() ) {
			return false;
		}

		$answer = \QuickEventsManager\Commerce\Stripe\Client::get_payment_intent( $intent );

		if ( is_wp_error( $answer ) ) {
			return false;
		}

		$status = (string) ( $answer['status'] ?? '' );

		if ( 'succeeded' === $status ) {
			TransactionRepository::settle( $transaction->id(), TransactionStatus::Succeeded );

			OrderService::complete( $order->id() );

			return true;
		}

		if ( in_array( $status, array( 'canceled', 'requires_payment_method' ), true ) && '' !== (string) ( $answer['last_payment_error']['code'] ?? '' ) ) {
			TransactionRepository::settle(
				$transaction->id(),
				TransactionStatus::Failed,
				array( 'error_code' => (string) $answer['last_payment_error']['code'] )
			);
		}

		return false;
	}

	/**
	 * The order named in the address bar, if it is real and unpaid.
	 *
	 * @since 26.0
	 *
	 * @return Order|null
	 */
	private static function order_being_paid(): ?Order {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which order to show; the order number is the credential and nothing here changes state.
		$number = isset( $_GET[ self::PAY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PAY_ARG ] ) ) : '';

		if ( '' === $number ) {
			return null;
		}

		return OrderRepository::find_by_number( $number );
	}

	/**
	 * The order paying for a booking.
	 *
	 * @since 26.0
	 *
	 * @param int $registration_id Booking id.
	 * @return Order|null
	 */
	private static function order_for_booking( int $registration_id ): ?Order {
		if ( $registration_id <= 0 ) {
			return null;
		}

		/** This filter is documented in includes/Commerce/OrderService.php */
		$order_id = (int) apply_filters( 'qevm_booking_order', 0, $registration_id );

		return $order_id > 0 ? OrderRepository::find( $order_id ) : null;
	}

	/**
	 * What a booking owes, in minor units.
	 *
	 * @since 26.0
	 *
	 * @param object $registration The booking.
	 * @param object $event        The event.
	 * @return int
	 */
	private static function amount_due( $registration, $event ): int {
		return self::unit_price( $registration, $event ) * max( 1, (int) $registration->quantity() );
	}

	/**
	 * What one place on this booking costs, in minor units.
	 *
	 * Read through `qevm_event_ticket_types`, so nothing here names a class
	 * from the ticketing module — and with that module off, every event is
	 * free and this returns nothing to pay.
	 *
	 * @since 26.0
	 *
	 * @param object $registration The booking.
	 * @param object $event        The event.
	 * @return int
	 */
	private static function unit_price( $registration, $event ): int {
		$type = self::ticket_type( $registration, $event );

		return null !== $type ? (int) $type->price_minor() : 0;
	}

	/**
	 * What the ticket is called, for the order line's snapshot.
	 *
	 * @since 26.0
	 *
	 * @param object $registration The booking.
	 * @param object $event        The event.
	 * @return string
	 */
	private static function ticket_name( $registration, $event ): string {
		$type = self::ticket_type( $registration, $event );

		return null !== $type
			? (string) $type->name()
			: __( 'Standard', 'quick-events-manager' );
	}

	/**
	 * The ticket type a booking is for, if there is one.
	 *
	 * @since 26.0
	 *
	 * @param object $registration The booking.
	 * @param object $event        The event.
	 * @return object|null
	 */
	private static function ticket_type( $registration, $event ) {
		$wanted = (int) $registration->ticket_type_id();

		if ( $wanted <= 0 ) {
			return null;
		}

		/** This filter is documented in includes/Registration/RegistrationService.php */
		$types = (array) apply_filters( 'qevm_event_ticket_types', array(), $event->id() );

		foreach ( $types as $type ) {
			if ( is_object( $type ) && method_exists( $type, 'id' ) && (int) $type->id() === $wanted ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * The payment screen.
	 *
	 * @since 26.0
	 *
	 * @param Order $order The order being paid.
	 * @return string
	 */
	private static function render( Order $order ): string {
		if ( OrderStatus::Pending !== $order->status() ) {
			return \QuickEventsManager\Frontend\Templates::render(
				'checkout-done.php',
				array( 'order' => $order )
			);
		}

		$gateway = Gateways::get( $order->gateway() );

		if ( null === $gateway || ! $gateway->is_configured() ) {
			return \QuickEventsManager\Frontend\Templates::render(
				'checkout-unavailable.php',
				array( 'order' => $order )
			);
		}

		$started = $gateway->start( $order );

		if ( is_wp_error( $started ) ) {
			return \QuickEventsManager\Frontend\Templates::render(
				'checkout-unavailable.php',
				array(
					'order'   => $order,
					'message' => $started->get_error_message(),
				)
			);
		}

		Assets::enqueue_checkout( $order, $started );

		return \QuickEventsManager\Frontend\Templates::render(
			'checkout.php',
			array(
				'order'   => $order,
				'total'   => Money::from_minor( $order->total_minor(), $order->currency() ),
				'started' => $started,
			)
		);
	}
}
