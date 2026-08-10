<?php
/**
 * The core module: events themselves.
 *
 * @package QuickEventsManager
 */

namespace QEM\Events;

use QEM\Modules\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a site gets on a fresh install, and the only module that cannot
 * be switched off.
 *
 * @since 26.0
 */
final class EventsModule implements Module {

	/**
	 * Module id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id() {
		return 'events';
	}

	/**
	 * Module title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Events', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create events with dates, times, locations and categories, and list them on your site.', 'quick-events-manager' );
	}

	/**
	 * Disclosure level.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function level() {
		return 0;
	}

	/**
	 * Core cannot be switched off.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required() {
		return true;
	}

	/**
	 * Add the module's hooks.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( PostType::class, 'register_post_type' ) );
		add_action( 'init', array( PostType::class, 'register_taxonomies' ) );
		add_action( 'init', array( Meta::class, 'register' ) );

		( new Query() )->register();
		( new \QEM\Frontend\Shortcodes() )->register();
		( new \QEM\Frontend\Assets() )->register();
		( new \QEM\Frontend\SingleEvent() )->register();
		( new \QEM\Frontend\Schema() )->register();
		( new \QEM\Frontend\Ics() )->register();
		( new \QEM\Blocks\Blocks() )->register();
		( new \QEM\Rest\EventsController() )->register();

		if ( is_admin() ) {
			( new MetaBox() )->register();
			( new AdminColumns() )->register();
		}
	}

	/**
	 * Nothing to set up beyond what activation already did.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {}

	/**
	 * Never called: this module is required.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {}
}
