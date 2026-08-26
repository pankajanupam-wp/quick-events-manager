<?php
/**
 * The registration state machine.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Where a registration stands.
 *
 * One of several separate state machines in this plugin, and deliberately not
 * shared with any other. An order being `paid` and a registration being
 * `confirmed` are unrelated facts; collapsing them into one vocabulary is how
 * status columns rot. See docs/database.md.
 *
 * Backed by string, and the values are exactly what the `status` column holds.
 * That is a contract: changing a case value is a schema change, not a rename.
 *
 * @since 26.0
 */
enum RegistrationStatus: string {

	/**
	 * Holding a place while something completes — payment, or approval.
	 */
	case Pending = 'pending';

	/**
	 * Has a place.
	 */
	case Confirmed = 'confirmed';

	/**
	 * The event was full. Next in line if a place frees up.
	 */
	case Waitlisted = 'waitlisted';

	/**
	 * Withdrawn. Frees the place.
	 */
	case Cancelled = 'cancelled';

	/**
	 * Whether this status takes up a place against capacity.
	 *
	 * The single definition of what counts. The capacity query and every
	 * displayed count read it from here, so they cannot drift apart.
	 *
	 * @since 26.0
	 */
	public function occupies_place(): bool {
		return self::Confirmed === $this || self::Pending === $this;
	}

	/**
	 * Whether a registration in this status can still be cancelled.
	 *
	 * @since 26.0
	 */
	public function is_cancellable(): bool {
		return self::Cancelled !== $this;
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending    => __( 'Pending', 'quick-events-manager' ),
			self::Confirmed  => __( 'Confirmed', 'quick-events-manager' ),
			self::Waitlisted => __( 'Waitlisted', 'quick-events-manager' ),
			self::Cancelled  => __( 'Cancelled', 'quick-events-manager' ),
		};
	}

	/**
	 * Every status, in the order a registration typically moves through them.
	 *
	 * @since 26.0
	 *
	 * @return self[]
	 */
	public static function all(): array {
		return array( self::Pending, self::Confirmed, self::Waitlisted, self::Cancelled );
	}

	/**
	 * Every status as its stored string, for building queries.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function values(): array {
		return array_map(
			static fn( self $status ): string => $status->value,
			self::all()
		);
	}

	/**
	 * The statuses that occupy a place.
	 *
	 * @since 26.0
	 *
	 * @return self[]
	 */
	public static function occupying(): array {
		return array_values( array_filter( self::all(), static fn( self $s ): bool => $s->occupies_place() ) );
	}

	/**
	 * The statuses that occupy a place, as stored strings.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function occupying_values(): array {
		return array_map(
			static fn( self $status ): string => $status->value,
			self::occupying()
		);
	}

	/**
	 * Resolve an untrusted value, falling back rather than throwing.
	 *
	 * Callers are usually reading a database column or a request parameter,
	 * where an unrecognised value means corrupt data or tampering — neither of
	 * which should take the page down.
	 *
	 * @since 26.0
	 *
	 * @param mixed     $value    Raw value.
	 * @param self|null $fallback Returned when the value is not a known status.
	 */
	public static function coerce( mixed $value, ?self $fallback = null ): ?self {
		if ( $value instanceof self ) {
			return $value;
		}

		return is_string( $value ) ? ( self::tryFrom( $value ) ?? $fallback ) : $fallback;
	}
}
