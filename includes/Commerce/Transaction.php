<?php
/**
 * One movement of money.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Domain\TransactionKind;
use QuickEventsManager\Domain\TransactionStatus;

defined( 'ABSPATH' ) || exit;

/**
 * A row from `qevm_transactions`, read-only.
 *
 * One signed ledger rather than a payments table and a refunds table: charges
 * are positive, refunds negative, and an order's net position is a single `SUM`
 * that cannot disagree with itself.
 *
 * @since 26.0
 */
final class Transaction {

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
	 * One column, with a fallback.
	 *
	 * @since 26.0
	 *
	 * @param string $key      Column.
	 * @param mixed  $fallback Value when it is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = '' ): mixed {
		return $this->row[ $key ] ?? $fallback;
	}

	/**
	 * Every column, as stored.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->row;
	}

	/**
	 * Row id.
	 *
	 * @since 26.0
	 */
	public function id(): int {
		return (int) $this->get( 'id', 0 );
	}

	/**
	 * The order this line belongs to.
	 *
	 * @since 26.0
	 */
	public function order_id(): int {
		return (int) $this->get( 'order_id', 0 );
	}

	/**
	 * Money in or money back.
	 *
	 * @since 26.0
	 */
	public function kind(): TransactionKind {
		return TransactionKind::from_value( $this->get( 'kind', TransactionKind::Charge->value ) );
	}

	/**
	 * Whether it happened.
	 *
	 * @since 26.0
	 */
	public function status(): TransactionStatus {
		return TransactionStatus::from_value( $this->get( 'status', TransactionStatus::Pending->value ) );
	}

	/**
	 * The signed amount in minor units: positive charge, negative refund.
	 *
	 * @since 26.0
	 */
	public function amount_minor(): int {
		return (int) $this->get( 'amount_minor', 0 );
	}

	/**
	 * The amount without its sign, which is what a person is shown.
	 *
	 * @since 26.0
	 */
	public function absolute_minor(): int {
		return abs( $this->amount_minor() );
	}

	/**
	 * ISO-4217 code.
	 *
	 * @since 26.0
	 */
	public function currency(): string {
		return strtoupper( (string) $this->get( 'currency', '' ) );
	}

	/**
	 * Which gateway moved it.
	 *
	 * @since 26.0
	 */
	public function gateway(): string {
		return (string) $this->get( 'gateway', '' );
	}

	/**
	 * The gateway's own id for this movement, or '' before it has one.
	 *
	 * @since 26.0
	 */
	public function gateway_txn_id(): string {
		return (string) $this->get( 'gateway_txn_id', '' );
	}

	/**
	 * The key that makes retrying this safe, or ''.
	 *
	 * @since 26.0
	 */
	public function idempotency_key(): string {
		return (string) $this->get( 'idempotency_key', '' );
	}

	/**
	 * Why, for a refund.
	 *
	 * @since 26.0
	 */
	public function reason(): string {
		return (string) $this->get( 'reason', '' );
	}

	/**
	 * The gateway's error code, for a failure.
	 *
	 * @since 26.0
	 */
	public function error_code(): string {
		return (string) $this->get( 'error_code', '' );
	}
}
