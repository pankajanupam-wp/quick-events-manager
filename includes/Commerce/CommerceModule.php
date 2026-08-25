<?php
/**
 * Paid tickets.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Domain\ModuleLevel;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Modules\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Selling tickets rather than giving them away.
 *
 * A module, like everything else that is not events: switched off, there are no
 * order tables, no checkout, and a site that runs free meetups never grows a
 * ledger. Three chunks of this stage have been forgotten in exactly this way
 * already — the tables were built before the module that owns them existed —
 * which is why it is here in the chunk that creates them rather than in the
 * chunk that first misses it.
 *
 * **What is here is storage, a lifecycle and a sweep.** Tables and repositories
 * were C9.1; the gateway interface, the order lifecycle and the seat-hold sweep
 * are C9.3. A gateway that actually takes money is C9.4, and until one is
 * registered `Gateways::any_usable()` is false and nothing offers to charge
 * anybody.
 *
 * @since 26.0
 */
final class CommerceModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'commerce';

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
		return __( 'Paid tickets', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Charge for a place. Orders, payments and refunds are kept as a ledger, so what an event earned last year still adds up next year.', 'quick-events-manager' );
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
	 * Add the module's hooks.
	 *
	 * The sweep, and nothing else yet: a checkout screen and a gateway are
	 * C9.4 onwards. The five-minute interval belongs to the mail queue and is
	 * registered by the registration module, so it is asked for here too —
	 * paid tickets without registration is not a configuration, but a module
	 * that depends on another module's `cron_schedules` filter having run is a
	 * module with an invisible dependency.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( \QuickEventsManager\Email\Worker::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Five minutes; see above.

		add_action( Holds::HOOK, array( Holds::class, 'sweep' ) );

		/*
		 * The gateway this plugin ships, offered through the same filter
		 * anybody else's would use. Registered whether or not it has keys —
		 * `is_configured()` is what decides whether it can be chosen, so a
		 * half-set-up gateway is invisible to customers and visible on the
		 * settings screen, which is the right way round.
		 */
		add_filter( 'qevm_payment_gateways', array( __CLASS__, 'add_stripe' ), 10, 1 );

		( new Checkout() )->register();

		if ( is_admin() ) {
			( new AttendeePayments() )->register();
		}
		( new Stripe\Webhook() )->register();
	}

	/**
	 * Offer the built-in Stripe gateway.
	 *
	 * @since 26.0
	 *
	 * @param mixed $gateways Gateways so far.
	 * @return array<string, mixed>
	 */
	public static function add_stripe( $gateways ) {
		$gateways = is_array( $gateways ) ? $gateways : array();

		$gateways[ Stripe\Gateway::ID ] = new Stripe\Gateway();

		return $gateways;
	}

	/**
	 * Create the three commerce tables.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Installer::run_schema( OrderRepository::schema() );
		Installer::run_schema( OrderItemRepository::schema() );
		Installer::run_schema( TransactionRepository::schema() );

		Holds::schedule();
	}

	/**
	 * Switching off sells nothing further and deletes nothing.
	 *
	 * Financial records outlive the feature that created them. A site that
	 * stops selling tickets still has to be able to answer what it took last
	 * year, and a tax authority does not accept "we turned the module off".
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {
		Holds::unschedule();
	}

	/**
	 * Whether the site has switched paid tickets on.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return \QuickEventsManager\Plugin::instance()->registry()->is_enabled( self::ID );
	}
}
