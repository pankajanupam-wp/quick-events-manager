<?php
/**
 * Which direction money moved.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A line in the ledger is money coming in or money going back.
 *
 * Charges and refunds share one table with a signed amount rather than living in
 * two tables that have to be reconciled against each other. An order's net
 * position is then one `SUM` and cannot disagree with itself — see
 * [ADR-0006](../../docs/adr/0006-money-and-immutability.md).
 *
 * This enum is the sign. `amount_minor` is stored positive for a charge and
 * negative for a refund, and the repository derives that from the kind rather
 * than trusting a caller to get it right.
 *
 * @since 26.0
 */
enum TransactionKind: string {

	/**
	 * Money in.
	 */
	case Charge = 'charge';

	/**
	 * Money back out.
	 */
	case Refund = 'refund';

	/**
	 * The sign an amount of this kind is stored with.
	 *
	 * @since 26.0
	 */
	public function sign(): int {
		return self::Refund === $this ? -1 : 1;
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return self::Refund === $this
			? __( 'Refund', 'quick-events-manager' )
			: __( 'Payment', 'quick-events-manager' );
	}

	/**
	 * The kind for a stored value, falling back to a charge.
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

		return null !== $found ? $found : self::Charge;
	}
}
