<?php
/**
 * Where an order stands.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The life of an order, from a checkout that has started to money that has gone back.
 *
 * Backed by string, and the values are exactly what the `status` column holds.
 * Changing a case value is a schema change, not a rename.
 *
 * **`Pending` is holding seats.** A booking counts as occupying a place from the
 * moment it is inserted — that is what makes insert-then-rank work — so an order
 * that somebody opened and walked away from is holding capacity until the sweep
 * in C9.3 lets it go. `Abandoned` is what the sweep leaves behind: not a failure,
 * because nothing was attempted, and not a cancellation, because nobody decided
 * anything. Telling the two apart is what makes an abandoned-checkout figure mean
 * something.
 *
 * @since 26.0
 */
enum OrderStatus: string {

	/**
	 * Checkout has started. Seats are held, no money has moved.
	 */
	case Pending = 'pending';

	/**
	 * Paid in full.
	 */
	case Paid = 'paid';

	/**
	 * Some of the money has gone back.
	 */
	case PartiallyRefunded = 'partially_refunded';

	/**
	 * All of it has.
	 */
	case Refunded = 'refunded';

	/**
	 * A payment was attempted and did not succeed.
	 */
	case Failed = 'failed';

	/**
	 * Nobody came back, and the hold expired.
	 */
	case Abandoned = 'abandoned';

	/**
	 * Whether this order is holding capacity.
	 *
	 * The single definition of "occupying a seat", so no caller restates the
	 * list. A refunded order is not holding anything: the places went back when
	 * the money did.
	 *
	 * @since 26.0
	 */
	public function holds_capacity(): bool {
		return self::Pending === $this
			|| self::Paid === $this
			|| self::PartiallyRefunded === $this;
	}

	/**
	 * Whether money has been taken and not entirely returned.
	 *
	 * @since 26.0
	 */
	public function is_settled(): bool {
		return self::Paid === $this || self::PartiallyRefunded === $this;
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		switch ( $this ) {
			case self::Paid:
				return __( 'Paid', 'quick-events-manager' );

			case self::PartiallyRefunded:
				return __( 'Partly refunded', 'quick-events-manager' );

			case self::Refunded:
				return __( 'Refunded', 'quick-events-manager' );

			case self::Failed:
				return __( 'Payment failed', 'quick-events-manager' );

			case self::Abandoned:
				return __( 'Abandoned', 'quick-events-manager' );

			case self::Pending:
			default:
				return __( 'Awaiting payment', 'quick-events-manager' );
		}
	}

	/**
	 * The status for a stored value, falling back to pending.
	 *
	 * @since 26.0
	 *
	 * @param mixed $value Stored value, or one of these already.
	 */
	public static function from_value( $value ): self {
		if ( $value instanceof self ) {
			return $value;
		}

		$found = self::tryFrom( (string) $value );

		return null !== $found ? $found : self::Pending;
	}
}
