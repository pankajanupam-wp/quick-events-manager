<?php
/**
 * What a payment gateway has to be able to do.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * The interface every gateway implements, and the whole of what the rest of the
 * plugin knows about taking money.
 *
 * Deliberately small. A gateway starts a payment and it reverses one; everything
 * else — what an order is worth, which seats it holds, when the hold expires,
 * what happens to the booking afterwards — belongs to the plugin and is the same
 * whoever is processing the card. Interfaces that grow to fit one provider stop
 * fitting the next, and the point of writing this before Stripe rather than
 * after it is that Stripe cannot quietly become the definition.
 *
 * **No card details ever reach this plugin.** `start()` hands back where to send
 * the browser, or what to hand a client-side SDK; the card itself is entered on
 * the gateway's own page or in its own iframe. That is not a nicety, it is the
 * difference between a plugin that needs PCI compliance and one that does not.
 *
 * @since 26.0
 */
interface Gateway {

	/**
	 * Stored identifier, e.g. `stripe`. Written to `qevm_orders.gateway`.
	 *
	 * @since 26.0
	 */
	public function id(): string;

	/**
	 * What a person choosing a payment method is shown.
	 *
	 * @since 26.0
	 */
	public function label(): string;

	/**
	 * Whether this gateway has everything it needs to take a payment.
	 *
	 * A gateway with no keys entered is not an error and not a broken feature:
	 * it is simply not offered. Anything that lists gateways asks this first,
	 * so half-configured never reaches a customer.
	 *
	 * @since 26.0
	 */
	public function is_configured(): bool;

	/**
	 * Begin taking payment for an order.
	 *
	 * Returns what should happen next in the browser:
	 *
	 * - `array( 'redirect' => $url )` — send them there.
	 * - `array( 'render' => $html )` — put this on the page.
	 *
	 * A `WP_Error` means the payment could not be started, and the order stays
	 * pending with its hold intact so the customer can try again.
	 *
	 * @since 26.0
	 *
	 * @param Order $order The order to be paid.
	 * @return array<string, string>|\WP_Error
	 */
	public function start( Order $order );

	/**
	 * Send money back.
	 *
	 * `$amount_minor` is positive and may be less than the order total: a
	 * partial refund is an ordinary thing, not an edge case.
	 *
	 * Returns the gateway's own id for the refund, or a `WP_Error`. The ledger
	 * entry is written by the caller, not here, so that one place decides what
	 * a refund does to an order.
	 *
	 * @since 26.0
	 *
	 * @param Order  $order        The order.
	 * @param int    $amount_minor How much, in minor units, positive.
	 * @param string $reason       Why, for the gateway's records.
	 * @return string|\WP_Error
	 */
	public function refund( Order $order, int $amount_minor, string $reason = '' );
}
