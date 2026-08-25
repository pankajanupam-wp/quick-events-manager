<?php
/**
 * One purchase.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Domain\OrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * A row from `qevm_orders`, read-only.
 *
 * The header of a purchase: who bought, what it came to, where it stands and —
 * while it is still pending — how long the seats it is holding are held for.
 * What was actually bought is in `qevm_order_items`, and what money moved is in
 * `qevm_transactions`.
 *
 * **Every figure here is in minor units**: pence, cents, paise, held as an
 * integer. `£499.50` is `49950`. Nothing in this class formats anything; that
 * happens once, at the point of output, in C9.2's value object. A raw `49950`
 * reaching a template is a visible bug and is meant to be.
 *
 * **`refunded_minor` is a copy, not the truth.** The ledger is the truth, and
 * this column is a denormalised total so that a list of two hundred orders is
 * not two hundred `SUM` queries. Exactly one thing writes it —
 * `TransactionRepository` — from the ledger it has just written to, so the two
 * cannot drift apart without a test noticing.
 *
 * @since 26.0
 */
final class Order {

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
	 * The reference a customer is given, e.g. `QEVO-4H3K9A`.
	 *
	 * @since 26.0
	 */
	public function number(): string {
		return (string) $this->get( 'order_number', '' );
	}

	/**
	 * The account that bought, or 0 for a guest.
	 *
	 * @since 26.0
	 */
	public function user_id(): int {
		return (int) $this->get( 'user_id', 0 );
	}

	/**
	 * Where the order stands.
	 *
	 * @since 26.0
	 */
	public function status(): OrderStatus {
		return OrderStatus::from_value( $this->get( 'status', OrderStatus::Pending->value ) );
	}

	/**
	 * ISO-4217 code the figures are in.
	 *
	 * @since 26.0
	 */
	public function currency(): string {
		return strtoupper( (string) $this->get( 'currency', '' ) );
	}

	/**
	 * Before tax, in minor units.
	 *
	 * @since 26.0
	 */
	public function subtotal_minor(): int {
		return (int) $this->get( 'subtotal_minor', 0 );
	}

	/**
	 * Tax, in minor units.
	 *
	 * @since 26.0
	 */
	public function tax_minor(): int {
		return (int) $this->get( 'tax_minor', 0 );
	}

	/**
	 * What was charged, in minor units.
	 *
	 * @since 26.0
	 */
	public function total_minor(): int {
		return (int) $this->get( 'total_minor', 0 );
	}

	/**
	 * What has gone back, in minor units, as a positive number.
	 *
	 * @since 26.0
	 */
	public function refunded_minor(): int {
		return (int) $this->get( 'refunded_minor', 0 );
	}

	/**
	 * What the site is still holding, in minor units.
	 *
	 * @since 26.0
	 */
	public function net_minor(): int {
		return $this->total_minor() - $this->refunded_minor();
	}

	/**
	 * Name on the order.
	 *
	 * @since 26.0
	 */
	public function billing_name(): string {
		return (string) $this->get( 'billing_name', '' );
	}

	/**
	 * Email on the order.
	 *
	 * @since 26.0
	 */
	public function billing_email(): string {
		return (string) $this->get( 'billing_email', '' );
	}

	/**
	 * Which gateway is taking the money, or '' before one is chosen.
	 *
	 * @since 26.0
	 */
	public function gateway(): string {
		return (string) $this->get( 'gateway', '' );
	}

	/**
	 * When the seats this order is holding stop being held, or null.
	 *
	 * @since 26.0
	 */
	public function hold_expires_utc(): ?string {
		$value = $this->get( 'hold_expires_utc', null );

		return null === $value || '' === $value ? null : (string) $value;
	}

	/**
	 * Whether the hold has run out as of now.
	 *
	 * Asked of the row rather than of the sweep, so a screen can say "this one
	 * is gone" without waiting for cron to catch up.
	 *
	 * @since 26.0
	 *
	 * @param string $now UTC `Y-m-d H:i:s`, defaulting to this moment.
	 */
	public function hold_has_expired( string $now = '' ): bool {
		$expires = $this->hold_expires_utc();

		if ( null === $expires ) {
			return false;
		}

		$now = '' !== $now ? $now : gmdate( 'Y-m-d H:i:s' );

		return $expires <= $now;
	}

	/**
	 * When it was paid, or null.
	 *
	 * @since 26.0
	 */
	public function paid_at(): ?string {
		$value = $this->get( 'paid_at', null );

		return null === $value || '' === $value ? null : (string) $value;
	}
}
