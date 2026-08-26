<?php
/**
 * Whether a ledger line actually happened.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A transaction is attempted, then it succeeded or it did not.
 *
 * A row exists before the gateway has answered, which is deliberate: the row is
 * what makes the attempt idempotent, so it has to be written before anybody can
 * ask "did we already try this?". Only `Succeeded` counts towards what an order
 * is worth.
 *
 * @since 26.0
 */
enum TransactionStatus: string {

	/**
	 * Sent, no answer yet.
	 */
	case Pending = 'pending';

	/**
	 * The money moved.
	 */
	case Succeeded = 'succeeded';

	/**
	 * It did not, and this row says why.
	 */
	case Failed = 'failed';

	/**
	 * Whether this line counts towards the order's net position.
	 *
	 * @since 26.0
	 */
	public function counts(): bool {
		return self::Succeeded === $this;
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		switch ( $this ) {
			case self::Succeeded:
				return __( 'Succeeded', 'quick-events-manager' );

			case self::Failed:
				return __( 'Failed', 'quick-events-manager' );

			case self::Pending:
			default:
				return __( 'In progress', 'quick-events-manager' );
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
