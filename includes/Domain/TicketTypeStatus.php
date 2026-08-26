<?php
/**
 * Whether a ticket type is still on sale.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A ticket type is on offer or it is not.
 *
 * Two states, and the second one exists so that a type can stop being sold
 * without being deleted. Somebody holds a ticket of that type; the attendee list
 * has to keep saying which type, and the export has to keep meaning something,
 * long after the organiser has decided not to sell any more of them.
 *
 * Backed by string, and the values are exactly what the `status` column holds.
 * Changing a case value is a schema change, not a rename.
 *
 * @since 26.0
 */
enum TicketTypeStatus: string {

	/**
	 * On offer.
	 */
	case Active = 'active';

	/**
	 * Withdrawn. Existing tickets of this type are untouched and still name it.
	 */
	case Archived = 'archived';

	/**
	 * Whether this type may still be chosen on a form.
	 *
	 * The single definition, so no caller restates the list of statuses it
	 * wants. A sale window can also close a type — that is C7.4 and a separate
	 * question, asked of the dates rather than of this.
	 *
	 * @since 26.0
	 */
	public function is_sellable(): bool {
		return self::Active === $this;
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		switch ( $this ) {
			case self::Archived:
				return __( 'Archived', 'quick-events-manager' );

			case self::Active:
			default:
				return __( 'On sale', 'quick-events-manager' );
		}
	}

	/**
	 * Read a stored value, falling back to active.
	 *
	 * Active rather than null, because a row exists and a type nobody can
	 * choose is worse than one that is offered — an unreadable status is a
	 * storage accident, not an instruction to withdraw somebody's tickets.
	 *
	 * @since 26.0
	 *
	 * @param string $value Stored value.
	 */
	public static function coerce( string $value ): self {
		return self::tryFrom( trim( $value ) ) ?? self::Active;
	}

	/**
	 * Every value the column may hold.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function values(): array {
		return array_map( static fn( self $status ): string => $status->value, self::cases() );
	}
}
