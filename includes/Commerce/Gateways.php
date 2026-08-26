<?php
/**
 * The gateways available to this site.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Which gateways exist, and which of them are usable.
 *
 * A registry rather than a hardcoded list, because a site that wants to take
 * money through something this plugin has never heard of should be able to,
 * and because it makes the built-in one testable against a fake.
 *
 * @since 26.0
 */
final class Gateways {

	/**
	 * Every gateway that has been registered.
	 *
	 * @since 26.0
	 *
	 * @return array<string, Gateway>
	 */
	public static function all(): array {
		/**
		 * Filter the payment gateways available.
		 *
		 * Each entry should be an object implementing
		 * `QuickEventsManager\Commerce\Gateway`. Typed loosely because a filter
		 * returns whatever a site returned from it, and anything that is not
		 * one is dropped below rather than trusted.
		 *
		 * @since 26.0
		 *
		 * @param array<string, mixed> $gateways Gateways by id.
		 */
		$gateways = (array) apply_filters( 'qevm_payment_gateways', array() );

		$valid = array();

		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof Gateway ) {
				$valid[ $gateway->id() ] = $gateway;
			}
		}

		return $valid;
	}

	/**
	 * The gateways that could actually take a payment right now.
	 *
	 * @since 26.0
	 *
	 * @return array<string, Gateway>
	 */
	public static function usable(): array {
		return array_filter( self::all(), static fn( $gateway ) => $gateway->is_configured() );
	}

	/**
	 * One gateway by id, or null.
	 *
	 * @since 26.0
	 *
	 * @param string $id Gateway id.
	 */
	public static function get( string $id ): ?Gateway {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Whether this site can take money at all.
	 *
	 * @since 26.0
	 */
	public static function any_usable(): bool {
		return array() !== self::usable();
	}
}
