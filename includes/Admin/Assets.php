<?php
/**
 * The admin stylesheet, on the screens that are ours.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One place that decides when the admin stylesheet loads.
 *
 * It used to be the event editor's job, which meant the stylesheet loaded on
 * `post.php` and nowhere else — so the attendee screen's own styles, and the
 * broadcast box added to it later, had never been applied on the screen they
 * were written for. Nothing reports that: the markup is right, the classes are
 * right, and the page simply looks like unstyled HTML.
 *
 * The rule is "a screen this plugin owns", answered from the current screen
 * rather than from a list of page slugs. A list is a thing that goes stale
 * silently the next time somebody adds a screen — which is exactly how the bug
 * this class exists to fix came about.
 *
 * @since 26.0
 */
final class Assets {

	/**
	 * Stylesheet handle.
	 */
	const HANDLE = 'qevm-admin';

	/**
	 * Hook in.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the stylesheet if this screen belongs to the plugin.
	 *
	 * @since 26.0
	 *
	 * @param string $hook Current admin page. Unused; the screen is the better witness.
	 * @return void
	 */
	public function enqueue( $hook ) {
		unset( $hook );

		if ( ! self::is_ours() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, QEVM_URL . 'assets/css/admin.css', array(), QEVM_VERSION );
	}

	/**
	 * Whether the screen being rendered is one of the plugin's.
	 *
	 * Two ways of belonging, because the plugin has both kinds of screen: the
	 * event editor and list, which carry the post type, and the submenu pages
	 * hanging off it, which carry a `qevm-` page slug.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	private static function is_ours() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}

		if ( QEVM_POST_TYPE === $screen->post_type ) {
			return true;
		}

		/*
		 * A settings page registered outside the events menu still belongs to
		 * this plugin, and its screen id is the only thing that says so.
		 */
		return false !== strpos( $screen->id, 'qevm-' );
	}
}
