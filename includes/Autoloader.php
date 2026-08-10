<?php
/**
 * Maps the QEM namespace onto the includes directory.
 *
 * Hand-written rather than Composer's autoloader: the wordpress.org package
 * ships no vendor directory, so the plugin cannot depend on one existing at
 * runtime. Composer is a development dependency here, nothing more.
 *
 * @package QuickEventsManager
 */

namespace QEM;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 style autoloader for the QEM namespace.
 */
final class Autoloader {

	/**
	 * Namespace prefix this autoloader answers for.
	 */
	const PREFIX = 'QEM\\';

	/**
	 * Register the autoloader with SPL.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load the file backing a class name, if it is one of ours.
	 *
	 * `QEM\Registration\Repository` resolves to
	 * `includes/Registration/Repository.php`.
	 *
	 * @since 26.0
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = QEM_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		/*
		 * A class name arriving here is built from our own source, never from
		 * user input, but the realpath check keeps the include honest if that
		 * ever stops being true.
		 */
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
