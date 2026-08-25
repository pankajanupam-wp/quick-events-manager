<?php
/**
 * What the payment screen loads.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * The one script this plugin loads from somebody else's server.
 *
 * **Stripe.js has to come from Stripe.** Not a preference: their terms require
 * it, and it is also the only way the fraud signalling and the bank
 * authentication flow work. It loads on the payment screen and nowhere else, so
 * a visitor reading about a meetup fetches nothing from anybody.
 *
 * @since 26.0
 */
final class Assets {

	/**
	 * Stripe's own script.
	 */
	const STRIPE_JS = 'https://js.stripe.com/v3/';

	/**
	 * Load what the payment screen needs.
	 *
	 * Called while the screen renders rather than on `wp_enqueue_scripts`,
	 * because whether anybody is paying is not known until the content filter
	 * runs. That is late for a script, which is why it is enqueued in the
	 * footer.
	 *
	 * @since 26.0
	 *
	 * @param Order                 $order   The order being paid.
	 * @param array<string, string> $started What the gateway handed back.
	 * @return void
	 */
	public static function enqueue_checkout( Order $order, array $started ) {
		wp_enqueue_script( 'qevm-stripe', self::STRIPE_JS, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe versions this URL itself and forbids copying the file.

		wp_enqueue_script(
			'qevm-checkout',
			QEVM_URL . 'assets/js/checkout.js',
			array( 'qevm-stripe' ),
			QEVM_VERSION,
			true
		);

		wp_localize_script(
			'qevm-checkout',
			'qevmCheckout',
			array(
				'publishable' => (string) ( $started['publishable'] ?? '' ),
				'secret'      => (string) ( $started['client_secret'] ?? '' ),
				'returnUrl'   => self::return_url( $order ),
				'strings'     => array(
					'paying'  => __( 'Paying…', 'quick-events-manager' ),
					'failed'  => __( 'The payment could not be completed. Your card has not been charged.', 'quick-events-manager' ),
					'blocked' => __( 'Your browser blocked the payment form. Check for a script blocker and reload the page.', 'quick-events-manager' ),
				),
			)
		);
	}

	/**
	 * Where Stripe sends somebody when they are done.
	 *
	 * Back to the same page, still naming the order, so the return handler
	 * knows what it is looking at.
	 *
	 * @since 26.0
	 *
	 * @param Order $order The order.
	 */
	private static function return_url( Order $order ): string {
		$url = get_permalink();

		if ( ! $url ) {
			$url = home_url( '/' );
		}

		return add_query_arg( Checkout::PAY_ARG, rawurlencode( $order->number() ), $url );
	}
}
