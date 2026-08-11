<?php
/**
 * The occurrence state machine.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a particular date is still going ahead.
 *
 * Its own state machine, deliberately not shared with RegistrationStatus. A
 * registration being `cancelled` and a date being `cancelled` are unrelated
 * facts that happen to share a word, and collapsing them into one vocabulary is
 * how status columns rot. See docs/database.md.
 *
 * Backed by string, and the values are exactly what the `status` column holds.
 * Changing a case value is a schema change, not a rename.
 *
 * @since 26.0
 */
enum OccurrenceStatus: string {

	/**
	 * Going ahead. The overwhelming majority of rows.
	 */
	case Scheduled = 'scheduled';

	/**
	 * Called off, but kept so the date still shows as cancelled rather than
	 * silently disappearing from a calendar someone has already looked at.
	 */
	case Cancelled = 'cancelled';

	/**
	 * Rescheduled to a different time. Used when recurrence lands, where a
	 * single instance of a series moves without the series moving.
	 */
	case Moved = 'moved';

	/**
	 * Whether this occurrence should appear in listings.
	 *
	 * The single definition. Every archive, calendar and REST query reads it
	 * from here rather than restating the list of statuses it wants.
	 *
	 * @since 26.0
	 */
	public function is_listable(): bool {
		return self::Cancelled !== $this;
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return match ( $this ) {
			self::Scheduled => __( 'Scheduled', 'quick-events-manager' ),
			self::Cancelled => __( 'Cancelled', 'quick-events-manager' ),
			self::Moved     => __( 'Rescheduled', 'quick-events-manager' ),
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
		return array( self::Scheduled, self::Cancelled, self::Moved );
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
	 * The statuses that appear in listings, as stored strings.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function listable_values(): array {
		return array_values(
			array_map(
				static fn( self $status ): string => $status->value,
				array_filter( self::all(), static fn( self $status ): bool => $status->is_listable() )
			)
		);
	}

	/**
	 * Resolve an untrusted value, falling back rather than throwing.
	 *
	 * Callers are reading a database column, where an unrecognised value means
	 * corrupt data and should not take a page down.
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
