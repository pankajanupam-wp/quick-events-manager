<?php
/**
 * The attendee state machine.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a place is still held for a person.
 *
 * The third separate state machine in this plugin, and again deliberately not
 * merged with the others. A registration being cancelled is a booking being
 * withdrawn; an attendee being cancelled is one person dropping out of a booking
 * that still stands. They coincide often and are not the same fact. See
 * docs/database.md.
 *
 * Deliberately smaller than RegistrationStatus. A person is on the list or they
 * are not — there is no per-person waitlist, because capacity is counted in
 * places on the booking and it is the booking that queues.
 *
 * @since 26.0
 */
enum AttendeeStatus: string {

	/**
	 * Holds a place and may be checked in.
	 */
	case Active = 'active';

	/**
	 * Dropped out. The row stays so a check-in scan can say "cancelled"
	 * rather than "unknown ticket", which is a very different conversation
	 * to have at a door.
	 */
	case Cancelled = 'cancelled';

	/**
	 * Whether this person still holds their place.
	 *
	 * @since 26.0
	 */
	public function is_active(): bool {
		return self::Active === $this;
	}

	/**
	 * Whether this person may be checked in.
	 *
	 * Separate from is_active() even though it agrees with it today: check-in
	 * gains its own reasons to refuse in stage 8, and the door should ask the
	 * question it means.
	 *
	 * @since 26.0
	 */
	public function is_admissible(): bool {
		return self::Active === $this;
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return match ( $this ) {
			self::Active    => __( 'Active', 'quick-events-manager' ),
			self::Cancelled => __( 'Cancelled', 'quick-events-manager' ),
		};
	}

	/**
	 * Every status.
	 *
	 * @since 26.0
	 *
	 * @return self[]
	 */
	public static function all(): array {
		return array( self::Active, self::Cancelled );
	}

	/**
	 * Every status as its stored string.
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
	 * Resolve an untrusted value, falling back rather than throwing.
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
