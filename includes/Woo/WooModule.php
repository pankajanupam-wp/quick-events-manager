<?php
/**
 * Selling tickets through WooCommerce.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Woo;

use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Domain\ModuleLevel;
use QuickEventsManager\Modules\Exclusive;
use QuickEventsManager\Modules\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The other way to take money: hand the whole checkout to WooCommerce.
 *
 * **A few hundred lines inherit a decade of hardening.** Woo already owns
 * checkout, orders, refunds, coupons, tax rules, several gateways and every bug
 * report those have collected since 2011. A site that already runs it should not
 * be asked to configure a second payment system, learn a second orders screen,
 * or reconcile two sets of numbers at the end of the year.
 *
 * **Mutually exclusive with the built-in gateway**, declared here and enforced
 * by the registry. Two things that both own a checkout do not fail loudly when
 * both are on; they each half-work, and somebody has to work out afterwards
 * which one took the money.
 *
 * **Nothing happens without WooCommerce actually being active.** The module can
 * be switched on with Woo absent — a site might install it next week — and every
 * hook is behind that check, so the effect is a feature that does nothing rather
 * than a fatal on every page.
 *
 * @since 26.0
 */
final class WooModule implements Module, Exclusive {

	/**
	 * Module id.
	 */
	const ID = 'woocommerce';

	/**
	 * Module id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * Module title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Sell through WooCommerce', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Use the shop you already have. A ticket becomes a product, and WooCommerce handles the basket, the payment, the tax and the refunds.', 'quick-events-manager' );
	}

	/**
	 * Which disclosure level this module belongs to.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Advanced;
	}

	/**
	 * Can be switched off.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required() {
		return false;
	}

	/**
	 * What this cannot be used alongside.
	 *
	 * @since 26.0
	 *
	 * @return array<int, string>
	 */
	public function conflicts(): array {
		return array( CommerceModule::ID );
	}

	/**
	 * Add the hooks, if there is a WooCommerce to talk to.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		if ( ! self::woo_is_active() ) {
			return;
		}

		( new Products() )->register();
		( new Orders() )->register();
	}

	/**
	 * Nothing of its own to create.
	 *
	 * The products are Woo's and the orders are Woo's; the bookings already have
	 * a table. A bridge that grew its own storage would be a third place for the
	 * numbers to disagree.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
	}

	/**
	 * Switching off sells nothing further and deletes nothing.
	 *
	 * The products stay in the shop: they are Woo's record of things people
	 * bought, and removing them would tear a hole in somebody's order history.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {
	}

	/**
	 * Whether WooCommerce is here.
	 *
	 * @since 26.0
	 */
	public static function woo_is_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Whether the site has switched this on.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return \QuickEventsManager\Plugin::instance()->registry()->is_enabled( self::ID );
	}
}
