<?php
/**
 * A place an event happens, wherever its address is stored.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * One address, read from a venue record or from the event's own meta.
 *
 * An event's location can be stored in two places and the difference must not
 * leak: every caller asks the event for its venue and gets the same object back
 * whether the site uses venue records or has never heard of them.
 *
 * Resolution happens in `for_event()` and nowhere else. Six render paths read
 * this data — the two templates, the JSON-LD, the .ics, the admin column and
 * the confirmation email — and a check repeated six times is a check that will
 * be got wrong in one of them. The registration form has already produced that
 * exact bug once, which is why its module gate now sits at the render boundary.
 *
 * @since 26.0
 */
final class Venue {

	/**
	 * The venue post, when this venue came from a record.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Address parts, keyed by the Meta constant they are stored under.
	 *
	 * @var array<string, string>
	 */
	private $parts;

	/**
	 * Build a venue from its parts.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $parts Address parts, keyed by Meta constant.
	 * @param int                   $id    Venue post id, or 0 for flat meta.
	 */
	private function __construct( array $parts, $id = 0 ) {
		$this->parts = $parts;
		$this->id    = (int) $id;
	}

	/**
	 * The address keys that make up a venue, in the order they read.
	 *
	 * Venue records store their address under the same meta keys an event uses
	 * for a one-off address. Reusing them is what lets one value object read
	 * either source without a translation table, and it is what makes the
	 * promotion in C3.1b a copy rather than a mapping.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function address_keys() {
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
	 * The venue for an event, from a record if there is one and meta if not.
	 *
	 * Four things have to be true before a record is used: the module is on,
	 * the event names a venue, that post exists and is a venue, and it is not
	 * in the trash. Any of them failing falls back to the event's own meta —
	 * which is why promotion never deletes it. A site that switches the module
	 * off, or an organiser who deletes a venue somebody was still using, gets
	 * the address that was there before rather than an empty line where the
	 * location used to be.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return self
	 */
	public static function for_event( Event $event ) {
		$record = self::record_for( $event );

		if ( null !== $record ) {
			return $record;
		}

		return self::from_meta( $event );
	}

	/**
	 * The venue record an event points at, when one applies.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return self|null Null when the event has no usable record.
	 */
	private static function record_for( Event $event ) {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$venue_id = (int) $event->meta( Meta::VENUE_ID );

		if ( $venue_id <= 0 ) {
			return null;
		}

		return self::from_post( $venue_id );
	}

	/**
	 * Read a venue record.
	 *
	 * @since 26.0
	 *
	 * @param int $venue_id Venue post id.
	 * @return self|null Null when the id is not a published venue.
	 */
	public static function from_post( $venue_id ) {
		$post = get_post( (int) $venue_id );

		if ( ! $post instanceof \WP_Post || QEVM_POST_TYPE_VENUE !== $post->post_type ) {
			return null;
		}

		if ( 'trash' === $post->post_status ) {
			return null;
		}

		$parts = array();

		foreach ( self::address_keys() as $key ) {
			$parts[ $key ] = (string) get_post_meta( $post->ID, $key, true );
		}

		/*
		 * The post title is the venue's name. Storing it in the title as well
		 * as the meta key would be two places to change it, so the title wins
		 * and the meta key is left for the one-off addresses on events.
		 */
		$parts[ Meta::VENUE_NAME ] = (string) get_post_field( 'post_title', $post->ID, 'raw' );

		return new self( $parts, $post->ID );
	}

	/**
	 * Read an event's own address meta.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return self
	 */
	public static function from_meta( Event $event ) {
		$parts = array();

		foreach ( self::address_keys() as $key ) {
			$parts[ $key ] = (string) $event->meta( $key );
		}

		return new self( $parts );
	}

	/**
	 * Warm the cache for the venues a set of events point at.
	 *
	 * Resolution reads a second post per event, which on an archive of twenty
	 * events is twenty extra queries that were not there before this module
	 * existed. Stage 1 spent a whole chunk getting the archive down to a couple
	 * of milliseconds of database time, and quietly handing it back an N+1 is
	 * not an acceptable price for reusable addresses.
	 *
	 * One `_prime_post_caches()` call over the distinct venue ids replaces the
	 * lot. The event meta is already in cache by this point — `WP_Query` primes
	 * it — so reading the ids costs nothing.
	 *
	 * @since 26.0
	 *
	 * @param int[] $event_ids Events about to be rendered.
	 * @return void
	 */
	public static function prime( array $event_ids ) {
		if ( array() === $event_ids || ! self::is_enabled() ) {
			return;
		}

		$venue_ids = array();

		foreach ( $event_ids as $event_id ) {
			$venue_id = (int) get_post_meta( (int) $event_id, Meta::VENUE_ID, true );

			if ( $venue_id > 0 ) {
				$venue_ids[ $venue_id ] = $venue_id;
			}
		}

		if ( array() === $venue_ids ) {
			return;
		}

		_prime_post_caches( array_values( $venue_ids ), false, true );
	}

	/**
	 * Whether the venues module is switched on.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	private static function is_enabled() {
		return \QuickEventsManager\Plugin::instance()->registry()->is_enabled( VenuesModule::ID );
	}

	/**
	 * The venue post id, or 0 when this address came from the event.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Whether this venue came from a record rather than from event meta.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_record() {
		return $this->id > 0;
	}

	/**
	 * One address part.
	 *
	 * @since 26.0
	 *
	 * @param string $key Meta constant naming the part.
	 * @return string
	 */
	public function part( $key ) {
		return isset( $this->parts[ $key ] ) ? (string) $this->parts[ $key ] : '';
	}

	/**
	 * Every address part, keyed by meta key.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string>
	 */
	public function parts() {
		return $this->parts;
	}

	/**
	 * The venue's name.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function name() {
		return $this->part( Meta::VENUE_NAME );
	}

	/**
	 * Whether there is anything to show at all.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_empty() {
		return '' === $this->summary();
	}

	/**
	 * The address as a single readable line.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function summary() {
		$parts = array_filter(
			array_map(
				function ( $key ) {
					return $this->part( $key );
				},
				self::address_keys()
			),
			static function ( $part ) {
				return '' !== $part;
			}
		);

		return implode( ', ', $parts );
	}
}
