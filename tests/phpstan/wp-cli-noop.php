<?php
/**
 * WP-CLI's no-op progress bar, for static analysis only.
 *
 * See tests/phpstan/wp-cli.php for why these signatures exist.
 *
 * @package QuickEventsManager
 */

namespace WP_CLI;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Declaring WP-CLI's own names, not ours.

if ( ! class_exists( 'WP_CLI\NoOp' ) ) {

	/**
	 * Swallows progress-bar calls when there is nothing to draw to.
	 */
	class NoOp {

		/**
		 * Advance the bar.
		 *
		 * @param int    $increment How far.
		 * @param string $message   Label.
		 * @return void
		 */
		public function tick( $increment = 1, $message = '' ) {}

		/**
		 * Finish the bar.
		 *
		 * @return void
		 */
		public function finish() {}
	}
}
