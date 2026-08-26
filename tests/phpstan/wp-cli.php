<?php
/**
 * WP-CLI's static entry points, for static analysis only.
 *
 * WP-CLI is not a dependency of this plugin and is absent when PHPStan runs, so
 * the commands in includes/Cli/ reference classes it cannot resolve. Declaring
 * the methods they actually call lets those files be analysed rather than
 * excluded.
 *
 * Why this rather than php-stubs/wp-cli-stubs: adding a dependency, even a dev
 * one, is a decision the engineering standards say to raise rather than take in
 * passing. If the CLI surface grows much beyond this, the package is the better
 * answer.
 *
 * Split across three files because each declares a different namespace, and the
 * standard allows one per file and forbids the curly-brace form.
 *
 * Never loaded at runtime, never shipped: listed in phpstan.neon.dist under
 * scanFiles, and tests/ is excluded by .distignore.
 *
 * @package QuickEventsManager
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Declaring WP-CLI's own names, not ours.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- $callable and $exit are WP-CLI's own parameter names; renaming them would make this signature file describe an API that does not exist.

if ( ! class_exists( 'WP_CLI' ) ) {

	/**
	 * WP-CLI's static entry points.
	 */
	class WP_CLI {

		/**
		 * Register a command.
		 *
		 * @param string                 $name     Command name.
		 * @param string|object|callable $callable Command implementation.
		 * @param array<string, mixed>   $args     Registration arguments.
		 * @return bool
		 *
		 * @phpstan-impure Registers a command; the empty body here is a signature, not the behaviour.
		 */
		public static function add_command( $name, $callable, $args = array() ) {
			return true;
		}

		/**
		 * Print a message and halt.
		 *
		 * @param string $message Message.
		 * @param bool   $exit    Whether to halt.
		 * @return void
		 *
		 * @phpstan-impure Writes to stderr and may halt.
		 */
		public static function error( $message, $exit = true ) {}

		/**
		 * Print a success message.
		 *
		 * @param string $message Message.
		 * @return void
		 *
		 * @phpstan-impure Writes to output.
		 */
		public static function success( $message ) {}

		/**
		 * Print a warning.
		 *
		 * @param string $message Message.
		 * @return void
		 *
		 * @phpstan-impure Writes to output.
		 */
		public static function warning( $message ) {}

		/**
		 * Print a line.
		 *
		 * @param string $message Message.
		 * @return void
		 *
		 * @phpstan-impure Writes to output.
		 */
		public static function line( $message = '' ) {}

		/**
		 * Print a log message.
		 *
		 * @param string $message Message.
		 * @return void
		 *
		 * @phpstan-impure Writes to output.
		 */
		public static function log( $message ) {}
	}
}
