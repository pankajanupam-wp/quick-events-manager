<?php
/**
 * A place an event happens, wherever its address is stored.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Records\EventRecord;

defined( 'ABSPATH' ) || exit;

/**
 * One address, read from a venue record or from the event's own meta.
 *
 * An event's location can be stored in two places and the difference must not
 * leak: every caller asks the event for its venue and gets the same object back
 * whether the site uses venue records or has never heard of them. Six render
 * paths read this — the two templates, the JSON-LD, the .ics, the admin column
 * and the confirmation email — and a check repeated six times is a check that
 * will be got wrong in one of them.
 *
 * Everything about resolving and falling back is in `EventRecord`, shared with
 * organisers. What is left here is what is actually specific to a venue:
 * which keys it is made of, and how an address reads as one line.
 *
 * @since 26.0
 */
final class Venue extends EventRecord {

	/**
	 * Reusable venues are `qevm_venue` posts.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function post_type() {
		return QEVM_POST_TYPE_VENUE;
	}

	/**
	 * Venue records need the venues module.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function module_id() {
		return VenuesModule::ID;
	}

	/**
	 * The event meta key naming a venue.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function id_key() {
		return Meta::VENUE_ID;
	}

	/**
	 * The address keys that make up a venue, in the order they read.
	 *
	 * Venue records store their address under the same meta keys an event uses
	 * for a one-off address. Reusing them is what lets one value object read
	 * either source without a translation table, and it is what makes promotion
	 * a copy rather than a mapping.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array(
			Meta::VENUE_NAME,
			Meta::VENUE_ADDRESS,
			Meta::VENUE_CITY,
			Meta::VENUE_REGION,
			Meta::VENUE_POSTAL,
			Meta::VENUE_COUNTRY,
		);
	}

	/**
	 * The venue's name is its post title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function name_key() {
		return Meta::VENUE_NAME;
	}

	/**
	 * The address keys a venue record stores as meta, name excluded.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array_values(
			array_filter(
				self::keys(),
				static function ( $key ) {
					return Meta::VENUE_NAME !== $key;
				}
			)
		);
	}

	/**
	 * The address as a single readable line.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function summary() {
		return implode( ', ', $this->filled() );
	}
}
