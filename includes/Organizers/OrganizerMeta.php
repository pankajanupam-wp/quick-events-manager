<?php
/**
 * Registered meta on the organiser post type.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Organizers;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * The contact keys, registered a second time against `qevm_organizer`.
 *
 * The same keys and the same sanitisers as an event's own organiser fields,
 * taken from `Meta::definitions()` rather than restated. Two lists of
 * sanitisers for one set of fields is two lists that drift, and the one that
 * drifts is the one nobody is looking at — here that would mean an organiser
 * URL escaped on events and not on records.
 *
 * @since 26.0
 */
final class OrganizerMeta {

	/**
	 * Register the contact meta for the organiser post type.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register() {
		$definitions = Meta::definitions();

		foreach ( Organizer::meta_keys() as $key ) {
			if ( ! isset( $definitions[ $key ] ) ) {
				continue;
			}

			register_post_meta(
				QEVM_POST_TYPE_ORGANIZER,
				$key,
				array(
					'type'              => $definitions[ $key ]['type'],
					'single'            => true,
					'default'           => $definitions[ $key ]['default'],
					'show_in_rest'      => true,
					'sanitize_callback' => $definitions[ $key ]['sanitize'],
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}
}
