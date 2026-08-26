<?php
/**
 * One record of somebody arriving.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CheckIn;

defined( 'ABSPATH' ) || exit;

/**
 * A row from `qevm_checkins`, read-only.
 *
 * @since 26.0
 */
final class CheckIn {

	/**
	 * Raw column values.
	 *
	 * @var array<string, mixed>
	 */
	private $row;

	/**
	 * Wrap a row.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Column values.
	 */
	public function __construct( array $row = array() ) {
		$this->row = $row;
	}

	/**
	 * Row id.
	 *
	 * @since 26.0
	 */
	public function id(): int {
		return (int) ( $this->row['id'] ?? 0 );
	}

	/**
	 * The person.
	 *
	 * @since 26.0
	 */
	public function attendee_id(): int {
		return (int) ( $this->row['attendee_id'] ?? 0 );
	}

	/**
	 * The date they arrived at.
	 *
	 * @since 26.0
	 */
	public function occurrence_id(): int {
		return (int) ( $this->row['occurrence_id'] ?? 0 );
	}

	/**
	 * When, in UTC.
	 *
	 * @since 26.0
	 */
	public function checked_in_at(): string {
		return (string) ( $this->row['checked_in_at'] ?? '' );
	}

	/**
	 * Who recorded it, or 0.
	 *
	 * @since 26.0
	 */
	public function checked_in_by(): int {
		return (int) ( $this->row['checked_in_by'] ?? 0 );
	}

	/**
	 * How it was recorded: 'manual' or 'qr'.
	 *
	 * @since 26.0
	 */
	public function method(): string {
		return (string) ( $this->row['method'] ?? 'manual' );
	}

	/**
	 * Whether this arrival was undone.
	 *
	 * @since 26.0
	 */
	public function is_reversed(): bool {
		$reversed = $this->row['reversed_at'] ?? null;

		return null !== $reversed && '' !== $reversed;
	}

	/**
	 * When it was undone, or ''.
	 *
	 * @since 26.0
	 */
	public function reversed_at(): string {
		$reversed = $this->row['reversed_at'] ?? '';

		return is_string( $reversed ) ? $reversed : '';
	}

	/**
	 * The local time this arrival is shown as, in the site's format.
	 *
	 * @since 26.0
	 */
	public function format_time(): string {
		$time = '' !== $this->checked_in_at() ? strtotime( $this->checked_in_at() . ' UTC' ) : false;

		if ( false === $time ) {
			return '';
		}

		return (string) wp_date( (string) get_option( 'time_format' ), $time );
	}

	/**
	 * The row as an array.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->row;
	}
}
