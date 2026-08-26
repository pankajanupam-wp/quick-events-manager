<?php
/**
 * The reusable organisers feature module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Organizers;

use QuickEventsManager\Modules\Module;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * Contacts that repeat, typed once.
 *
 * Separate from venues rather than bundled with them, although the two are the
 * same idea. The site that most wants reusable venues — a group meeting in the
 * same three halls — very often has exactly one organiser, itself, and would
 * gain a whole admin screen listing one record. Modules are meant to be the
 * boundary that spares people features they have no use for, and folding these
 * together would mean switching on the one to get the other.
 *
 * Off on a fresh install, and off is a complete state: events carry their own
 * organiser fields whether this has ever been enabled or not.
 *
 * @since 26.0
 */
final class OrganizersModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'organizers';

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
		return __( 'Reusable organisers', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Keep the people or groups who run your events as records, and pick one instead of retyping their contact details.', 'quick-events-manager' );
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
		add_action( 'init', array( OrganizerMeta::class, 'register' ) );
		add_filter( 'the_posts', array( $this, 'prime_organizers' ), 10, 2 );

		if ( is_admin() ) {
			( new OrganizerMetaBox() )->register();
			( new EventOrganizerBox() )->register();
			( new Promoter() )->register();
		}
	}

	/**
	 * Warm the organiser cache for a set of events before anything renders them.
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
	public function prime_organizers( $posts, $query ) {
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

		Organizer::prime( $event_ids );

		return $posts;
	}

	/**
	 * Queue the promotion sweep, and nothing else.
	 *
	 * The organiser capabilities are granted by `Installer` on activation
	 * rather than here, so that `uninstall.php` removes the same set the
	 * installer added — a capability granted from a module escapes that parity
	 * check and is left behind on every site that ever switched it on.
	 *
	 * Promotion is queued rather than run: this is called inside the request
	 * that pressed the button, and `Promoter::schedule()` is called far more
	 * often than promotion should happen, so it only ever acts once.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Promoter::schedule();
	}

	/**
	 * Switching off leaves every organiser in place.
	 *
	 * The post type stops being registered, so organisers disappear from the
	 * admin — but the posts, their details and the `_qevm_organizer_id` on each
	 * event are all still there, and switching back on restores the lot. Events
	 * go on showing their contact details from their own meta throughout.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {}
}
