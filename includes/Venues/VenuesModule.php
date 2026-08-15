<?php
/**
 * The reusable venues feature module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Modules\Module;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses that repeat, typed once.
 *
 * Off on a fresh install, and off is a complete state rather than a degraded
 * one: events carry their own address meta whether this module has ever been
 * enabled or not, and every render path reads that meta when no record applies.
 * Switching venues on adds somewhere to put an address that repeats; switching
 * it off takes the post type away and leaves every event still showing where it
 * is.
 *
 * @since 26.0
 */
final class VenuesModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'venues';

	/**
	 * Module id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * Module title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Reusable venues', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Keep the places you use often as records, and pick one instead of retyping the address on every event.', 'quick-events-manager' );
	}

	/**
	 * Which disclosure level this module belongs to.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Extended;
	}

	/**
	 * Can be switched off.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required() {
		return false;
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
		add_action( 'init', array( VenueMeta::class, 'register' ) );
		add_filter( 'the_posts', array( $this, 'prime_venues' ), 10, 2 );

		if ( is_admin() ) {
			( new VenueMetaBox() )->register();
			( new EventVenueBox() )->register();
		}
	}

	/**
	 * Warm the venue cache for a set of events before anything renders them.
	 *
	 * `the_posts` rather than the renderer, so that a theme or a third-party
	 * template listing events gets the same treatment as the plugin's own —
	 * and so that switching the module off removes the behaviour along with
	 * everything else it does.
	 *
	 * `$posts` is typed loosely on purpose. Core hands this filter an array of
	 * `WP_Post`, but by the time it reaches here any other plugin may have
	 * filtered it first, and several well-known ones replace the entries with
	 * their own objects or hand back something that is not an array at all.
	 * Documenting the type core promises would let static analysis call the
	 * checks below redundant and invite somebody to delete them, at which point
	 * one badly-behaved plugin fatals every archive on the site.
	 *
	 * @since 26.0
	 *
	 * @param mixed     $posts Posts the query found, whatever earlier filters left.
	 * @param \WP_Query $query The query. Unused.
	 * @return mixed Untouched, whatever it was.
	 */
	public function prime_venues( $posts, $query ) {
		unset( $query );

		if ( ! is_array( $posts ) || array() === $posts ) {
			return $posts;
		}

		$event_ids = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && QEVM_POST_TYPE === $post->post_type ) {
				$event_ids[] = (int) $post->ID;
			}
		}

		Venue::prime( $event_ids );

		return $posts;
	}

	/**
	 * Switching on needs no setup.
	 *
	 * There is no table, no option to seed and no capability to grant here.
	 * The venue capabilities are granted by `Installer` on activation rather
	 * than on enable, so that `uninstall.php` removes the same set the
	 * installer added — a capability granted from a module escapes that parity
	 * check and is left behind on every site that ever switched the module on.
	 *
	 * Rewrite rules would be the one thing needing work here, because this
	 * runs from an admin request where `init` has already fired. The post type
	 * registers none, deliberately; see `PostType::register_post_type()`.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {}

	/**
	 * Switching off leaves every venue in place.
	 *
	 * The post type stops being registered, so venues disappear from the admin
	 * — but the posts, their addresses and the `_qevm_venue_id` on each event
	 * are all still there, and switching back on restores the lot. Events go on
	 * rendering their address from their own meta throughout.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {}
}
