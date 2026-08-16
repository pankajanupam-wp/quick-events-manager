<?php
/**
 * Where a queued email stands.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The state machine for one message to one recipient.
 *
 * Its own vocabulary, shared with nothing. A registration being `confirmed` and
 * an email being `sent` are unrelated facts; see docs/database.md.
 *
 * Backed by string, and the values are exactly what the `status` column holds.
 * Changing a case value is a schema change, not a rename.
 *
 * @since 26.0
 */
enum EmailStatus: string {

	/**
	 * Waiting its turn.
	 */
	case Pending = 'pending';

	/**
	 * Claimed by a worker and being handed to `wp_mail()`.
	 *
	 * A row sits here for the length of one send. It exists so that two workers
	 * running at once — a cron tick and somebody clicking through the admin —
	 * cannot both pick up the same message and send it twice.
	 */
	case Sending = 'sending';

	/**
	 * `wp_mail()` accepted it.
	 *
	 * Which is not the same as it arriving. `wp_mail()` returns true when the
	 * message was handed off successfully, and everything after that is between
	 * the mail server and the recipient. The column is honest about what it
	 * knows.
	 */
	case Sent = 'sent';

	/**
	 * Given up on, after the last attempt.
	 */
	case Failed = 'failed';

	/**
	 * Withdrawn before sending — the event was deleted, or somebody cancelled.
	 */
	case Cancelled = 'cancelled';

	/**
	 * Whether a worker may still pick this up.
	 *
	 * @since 26.0
	 */
	public function is_workable(): bool {
		return self::Pending === $this || self::Sending === $this;
	}

	/**
	 * Whether this is the end of the road for a message.
	 *
	 * @since 26.0
	 */
	public function is_final(): bool {
		return ! $this->is_workable();
	}

	/**
	 * The name shown on an admin screen.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending   => __( 'Queued', 'quick-events-manager' ),
			self::Sending   => __( 'Sending', 'quick-events-manager' ),
			self::Sent      => __( 'Sent', 'quick-events-manager' ),
			self::Failed    => __( 'Failed', 'quick-events-manager' ),
			self::Cancelled => __( 'Cancelled', 'quick-events-manager' ),
		};
	}

	/**
	 * Resolve a stored value, falling back to pending.
	 *
	 * @since 26.0
	 *
	 * @param string $value Stored status.
	 */
	public static function from_stored( string $value ): self {
		return self::tryFrom( $value ) ?? self::Pending;
	}
}
