<?php
/**
 * WP-CLI utility functions, for static analysis only.
 *
 * See tests/phpstan/wp-cli.php for why these signatures exist.
 *
 * @package QuickEventsManager
 */

namespace WP_CLI\Utils;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Declaring WP-CLI's own names, not ours.

if ( ! function_exists( 'WP_CLI\Utils\make_progress_bar' ) ) {

	/**
	 * A progress bar, or a silent stand-in when output is not a terminal.
	 *
	 * @param string $message  Label.
	 * @param int    $count    Total ticks.
	 * @param int    $interval Redraw interval.
	 * @return \WP_CLI\NoOp
	 */
	function make_progress_bar( $message, $count, $interval = 100 ) {
		return new \WP_CLI\NoOp();
	}
}
