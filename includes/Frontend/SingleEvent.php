<?php
/**
 * Automatic event details on single event pages.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Frontend;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the details and the registration form on a single event page.
 *
 * Uses `the_content` rather than a `single-qevm_event.php` template. A template
 * takeover only works in classic themes — a block theme renders singles through
 * its own block template and never looks at the plugin's file. Filtering the
 * content works in both, because a block theme's template still ends up calling
 * the `core/post-content` block, which applies `the_content`.
 *
 * @since 26.0
 */
final class SingleEvent {

	/**
	 * Whether this filter is already running.
	 *
	 * @var bool
	 */
	private static $rendering = false;

	/**
	 * Hook into content rendering.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'the_content', array( $this, 'append_details' ) );
	}

	/**
	 * Prepend the details and append the registration form.
	 *
	 * The three guards matter. `the_content` also runs for excerpts, feeds and
	 * REST responses, and for every post rendered in a sidebar or related-posts
	 * block. Without them the event details turn up in all of those.
	 *
	 * @since 26.0
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_details( $content ) {
		if ( ! is_singular( QEVM_POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		/*
		 * Re-entrancy guard, and not a theoretical one.
		 *
		 * Rendering the details calls get_the_excerpt(). For a post with no
		 * manually written excerpt, core generates one with wp_trim_excerpt(),
		 * which applies `the_content` — landing straight back in this method,
		 * inside the same loop iteration, with every guard above still true.
		 * That recursion exhausts PHP's memory limit rather than overflowing
		 * the stack, so it presents as a blank white page with a memory error
		 * and no hint of the cause.
		 */
		if ( self::$rendering ) {
			return $content;
		}

		if ( ! Settings::get( 'auto_details' ) ) {
			return $content;
		}

		$event = new Event( get_the_ID() );

		if ( ! $event->is_valid() ) {
			return $content;
		}

		self::$rendering = true;

		try {
			$details = Renderer::event_details( array( 'id' => $event->id() ) );
			$form    = Renderer::registration_form( array( 'id' => $event->id() ) );
		} finally {
			self::$rendering = false;
		}

		return $details . $content . $form;
	}
}
