<?php
/**
 * The shape shared by every record an event can point at.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Records;

use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

/**
 * A detail of an event that can live in two places at once.
 *
 * Venues and organisers have the same problem and therefore the same answer.
 * Each is a set of fields stored flat on the event; each can optionally be
 * promoted to a reusable post; each belongs to a module that can be switched
 * off, at which point the flat copy has to carry the event on its own.
 * [ADR-0014](../../docs/adr/0014-venue-records-with-flat-fallback.md) sets out
 * why the flat copy is never removed.
 *
 * The rules live here rather than in each subclass because the awkward one is
 * easy to get subtly wrong and impossible to notice: a record is used only when
 * the module is on, the event names one, the post exists, it is of the right
 * type and it is not in the trash — and *any* of those failing falls back to
 * the event's own fields. Two copies of that condition would eventually
 * disagree, and the disagreement would show up as a location or an organiser
 * quietly vanishing from a page.
 *
 * @since 26.0
 */
abstract class EventRecord {

	/**
	 * The post id this came from, or 0 when it came from event meta.
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Field values, keyed by the meta key they are stored under.
	 *
	 * @var array<string, string>
	 */
	protected $parts;

	/**
	 * Build from parts.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $parts Values keyed by meta key.
	 * @param int                   $id    Post id, or 0 for event meta.
	 */
	final protected function __construct( array $parts, $id = 0 ) {
		$this->parts = $parts;
		$this->id    = (int) $id;
	}

	/**
	 * The post type reusable records of this kind are stored as.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	abstract public static function post_type();

	/**
	 * The module that must be enabled for records to be used.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	abstract public static function module_id();

	/**
	 * The meta key on the event holding the record's id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	abstract public static function id_key();

	/**
	 * Every meta key this record is made of, in reading order.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	abstract public static function keys();

	/**
	 * The key holding the record's name.
	 *
	 * A record's name is its post title, so this key is only read from the
	 * event's own meta. Storing the name in the title *and* a meta key would be
	 * two places to change it, and they would not stay in step.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	abstract public static function name_key();

	/**
	 * The record for an event: a post if one applies, its own meta if not.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return static
	 */
	final public static function for_event( Event $event ) {
		if ( static::is_enabled() ) {
			$record_id = (int) $event->meta( static::id_key() );

			if ( $record_id > 0 ) {
				$record = static::from_post( $record_id );

				if ( null !== $record ) {
					return $record;
				}
			}
		}

		return static::from_meta( $event );
	}

	/**
	 * Read a record post.
	 *
	 * @since 26.0
	 *
	 * @param int $post_id Post id.
	 * @return static|null Null when the id is not a usable record.
	 */
	final public static function from_post( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post || static::post_type() !== $post->post_type ) {
			return null;
		}

		if ( 'trash' === $post->post_status ) {
			return null;
		}

		$parts = array();

		foreach ( static::keys() as $key ) {
			$parts[ $key ] = (string) get_post_meta( $post->ID, $key, true );
		}

		$parts[ static::name_key() ] = (string) get_post_field( 'post_title', $post->ID, 'raw' );

		return new static( $parts, $post->ID );
	}

	/**
	 * Read an event's own fields.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return static
	 */
	final public static function from_meta( Event $event ) {
		$parts = array();

		foreach ( static::keys() as $key ) {
			$parts[ $key ] = (string) $event->meta( $key );
		}

		return new static( $parts );
	}

	/**
	 * Build straight from a set of values, without touching the database.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $parts Values keyed by meta key.
	 * @return static
	 */
	final public static function from_parts( array $parts ) {
		$complete = array();

		foreach ( static::keys() as $key ) {
			$complete[ $key ] = isset( $parts[ $key ] ) ? (string) $parts[ $key ] : '';
		}

		return new static( $complete );
	}

	/**
	 * Warm the cache for the records a set of events point at.
	 *
	 * Resolution reads a second post per event, which on a list of twenty is
	 * twenty extra queries that were not there before the module existed. One
	 * prime call over the distinct ids replaces the lot. The event meta is
	 * already cached by this point, so reading the ids costs nothing.
	 *
	 * @since 26.0
	 *
	 * @param int[] $event_ids Events about to be rendered.
	 * @return void
	 */
	final public static function prime( array $event_ids ) {
		if ( array() === $event_ids || ! static::is_enabled() ) {
			return;
		}

		$record_ids = array();

		foreach ( $event_ids as $event_id ) {
			$record_id = (int) get_post_meta( (int) $event_id, static::id_key(), true );

			if ( $record_id > 0 ) {
				$record_ids[ $record_id ] = $record_id;
			}
		}

		if ( array() === $record_ids ) {
			return;
		}

		_prime_post_caches( array_values( $record_ids ), false, true );
	}

	/**
	 * Whether the owning module is switched on.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	final public static function is_enabled() {
		return \QuickEventsManager\Plugin::instance()->registry()->is_enabled( static::module_id() );
	}

	/**
	 * The post id, or 0 when these values came from the event.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	final public function id() {
		return $this->id;
	}

	/**
	 * Whether this came from a reusable record rather than from event meta.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	final public function is_record() {
		return $this->id > 0;
	}

	/**
	 * One value.
	 *
	 * @since 26.0
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	final public function part( $key ) {
		return isset( $this->parts[ $key ] ) ? (string) $this->parts[ $key ] : '';
	}

	/**
	 * Every value, keyed by meta key.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string>
	 */
	final public function parts() {
		return $this->parts;
	}

	/**
	 * The record's name.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	final public function name() {
		return $this->part( static::name_key() );
	}

	/**
	 * Whether every value is empty.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	final public function is_empty() {
		foreach ( $this->parts as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The non-empty values, in reading order.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	final protected function filled() {
		$filled = array();

		foreach ( static::keys() as $key ) {
			$value = $this->part( $key );

			if ( '' !== $value ) {
				$filled[] = $value;
			}
		}

		return $filled;
	}
}
