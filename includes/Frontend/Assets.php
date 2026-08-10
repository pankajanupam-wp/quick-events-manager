<?php
/**
 * Front-end stylesheet loading.
 *
 * @package QuickEventsManager
 */

namespace QEM\Frontend;

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
	const HANDLE = 'qem-frontend';

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
			QEM_URL . 'assets/css/frontend.css',
			array(),
			QEM_VERSION
		);

		/*
		 * A single-event page always shows details, so enqueue eagerly there.
		 * Everywhere else waits until a block or shortcode asks.
		 */
		if ( is_singular( QEM_POST_TYPE ) || is_post_type_archive( QEM_POST_TYPE ) ) {
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
			wp_register_style( self::HANDLE, QEM_URL . 'assets/css/frontend.css', array(), QEM_VERSION );
		}

		wp_enqueue_style( self::HANDLE );
	}
}
