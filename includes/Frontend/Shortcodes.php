<?php
/**
 * Shortcodes.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrappers over the shared renderers.
 *
 * Every shortcode is prefixed. Version 1.0 registered none, so there is no
 * legacy name to keep compatible — and unprefixed names like `[event_list]`
 * are the kind that collide with whatever else a site has installed.
 *
 * @since 26.0
 */
final class Shortcodes {

	/**
	 * Register the shortcodes.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'qevm_event_list', array( $this, 'event_list' ) );
		add_shortcode( 'qevm_event_details', array( $this, 'event_details' ) );
		add_shortcode( 'qevm_event_registration', array( $this, 'registration_form' ) );
	}

	/**
	 * `[qevm_event_list]`
	 *
	 * @since 26.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function event_list( $atts ) {
		return Renderer::event_list( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * `[qevm_event_details]`
	 *
	 * @since 26.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function event_details( $atts ) {
		return Renderer::event_details( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * `[qevm_event_registration]`
	 *
	 * @since 26.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function registration_form( $atts ) {
		return Renderer::registration_form( is_array( $atts ) ? $atts : array() );
	}
}
