<?php
/**
 * Front-end stylesheet loading.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the stylesheet only on pages that actually show an event.
 *
 * Registering on `wp_enqueue_scripts` and enqueueing from the renderers means
 * a page with no events on it downloads nothing at all, which is the whole
 * reason the enqueue is deferred rather than unconditional.
 *
 * @since 26.0
 */
final class Assets {

	/**
	 * Handle for the front-end stylesheet.
	 */
	const HANDLE = 'qevm-frontend';

	/**
	 * Handle for the registration form's script.
	 *
	 * The only script the plugin puts on the front end, and it loads on pages
	 * that show a registration form and nowhere else.
	 */
	const FORM_HANDLE = 'qevm-registration';

	/**
	 * Hook into asset loading.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register — but do not enqueue — the stylesheet.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			self::HANDLE,
			QEVM_URL . 'assets/css/frontend.css',
			array(),
			QEVM_VERSION
		);

		self::register_form_script();

		/*
		 * A single-event page always shows details, so enqueue eagerly there.
		 * Everywhere else waits until a block or shortcode asks.
		 */
		if ( is_singular( QEVM_POST_TYPE ) || is_post_type_archive( QEVM_POST_TYPE ) ) {
			self::enqueue_frontend();
		}
	}

	/**
	 * Enqueue the stylesheet.
	 *
	 * Safe to call repeatedly and safe to call late: WordPress prints styles
	 * in the footer for anything enqueued after wp_head has run.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function enqueue_frontend() {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, QEVM_URL . 'assets/css/frontend.css', array(), QEVM_VERSION );
		}

		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Enqueue the script that reveals a name field per guest.
	 *
	 * Called by the renderer when a form is actually output, so a site that
	 * never takes registrations never loads it — and neither does an event page
	 * whose registration has closed.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function enqueue_registration_form() {
		self::register_form_script();

		wp_enqueue_script( self::FORM_HANDLE );
	}

	/**
	 * Register — but do not enqueue — the registration form's script.
	 *
	 * Deferred rather than loaded in the head. The rows it manages are hidden
	 * by an attribute in the markup, not by the script, so there is no state to
	 * correct before the page paints and nothing to be gained by blocking the
	 * parser for it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private static function register_form_script() {
		if ( wp_script_is( self::FORM_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_script(
			self::FORM_HANDLE,
			QEVM_URL . 'assets/js/registration.js',
			array(),
			QEVM_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
