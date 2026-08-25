<?php
/**
 * A module that cannot be on at the same time as another.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by a module that owns something another module also owns.
 *
 * A second interface rather than a method on `Module`, because almost nothing
 * conflicts with anything: a calendar view and a check-in door have no opinion
 * about each other, and making every module declare an empty list to say so
 * would be noise in eight files to serve one pair.
 *
 * **The pair here is a checkout.** WooCommerce and the built-in gateway both
 * want to own taking money for a ticket. With both on, neither fails loudly —
 * they each half-work, and the site owner is left with two payment paths and no
 * way to know which one a customer used. That is worse than either being
 * unavailable.
 *
 * Declaring it at one end is enough. The registry reads the pair in both
 * directions, because requiring both modules to name each other is how two
 * lists drift apart.
 *
 * @since 26.0
 */
interface Exclusive {

	/**
	 * Module ids this one cannot be enabled alongside.
	 *
	 * @since 26.0
	 *
	 * @return array<int, string>
	 */
	public function conflicts(): array;
}
