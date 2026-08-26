<?php
/**
 * Registered meta on the venue post type.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * The address keys, registered a second time against `qevm_venue`.
 *
 * The same keys and the same sanitisers as an event's own address, taken from
 * `Meta::definitions()` rather than restated here. Two lists of sanitisers for
 * one set of fields is two lists that drift, and the one that drifts is always
 * the one nobody is looking at.
 *
 * The venue's name is its post title, not a meta key, so it is not registered
 * here — see `Venue::from_post()`.
 *
 * @since 26.0
 */
final class VenueMeta {

	/**
	 * Register the address meta for the venue post type.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register() {
		$definitions = Meta::definitions();

		foreach ( self::keys() as $key ) {
			if ( ! isset( $definitions[ $key ] ) ) {
				continue;
			}

			register_post_meta(
				QEVM_POST_TYPE_VENUE,
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

	/**
	 * The address keys a venue record stores, name excluded.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function keys() {
		return Venue::meta_keys();
	}
}
