<?php
/**
 * One line of a purchase, as it stood when it was bought.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * A row from `qevm_order_items`, read-only in the strongest sense.
 *
 * **This is a snapshot, and that is the whole point of the table.** The name of
 * the ticket, the name of the event and the price are copied in at the moment of
 * purchase and never updated. A ticket that went from £4.99 to £9.99 leaves last
 * year's order reading £4.99, so last year's revenue still adds up. A report that
 * joins to `ticket_types` and reads today's price is a report that silently
 * rewrites history — see [ADR-0006](../../docs/adr/0006-money-and-immutability.md).
 *
 * `ticket_type_id` and `event_id` are kept for navigation — "show me this
 * event's orders" — and **no financial figure is ever read through them**.
 *
 * @since 26.0
 */
final class OrderItem {

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
	 * The order this belongs to.
	 *
	 * @since 26.0
	 */
	public function order_id(): int {
		return (int) $this->get( 'order_id', 0 );
	}

	/**
	 * The ticket type bought, for navigation only.
	 *
	 * @since 26.0
	 */
	public function ticket_type_id(): int {
		return (int) $this->get( 'ticket_type_id', 0 );
	}

	/**
	 * The event, for navigation only.
	 *
	 * @since 26.0
	 */
	public function event_id(): int {
		return (int) $this->get( 'event_id', 0 );
	}

	/**
	 * Which date, or 0 on a single-date event.
	 *
	 * @since 26.0
	 */
	public function occurrence_id(): int {
		return (int) $this->get( 'occurrence_id', 0 );
	}

	/**
	 * What the ticket was called when it was bought.
	 *
	 * @since 26.0
	 */
	public function name(): string {
		return (string) $this->get( 'name_snapshot', '' );
	}

	/**
	 * What the event was called when it was bought.
	 *
	 * @since 26.0
	 */
	public function event_name(): string {
		return (string) $this->get( 'event_snapshot', '' );
	}

	/**
	 * What one cost, in minor units, then.
	 *
	 * @since 26.0
	 */
	public function unit_price_minor(): int {
		return (int) $this->get( 'unit_price_minor', 0 );
	}

	/**
	 * How many.
	 *
	 * @since 26.0
	 */
	public function quantity(): int {
		return (int) $this->get( 'quantity', 1 );
	}

	/**
	 * Tax on this line, in minor units.
	 *
	 * @since 26.0
	 */
	public function tax_minor(): int {
		return (int) $this->get( 'tax_minor', 0 );
	}

	/**
	 * The line total, in minor units, as it was worked out then.
	 *
	 * Read rather than recomputed from unit price and quantity. If the two ever
	 * disagree the stored figure is what the customer was charged, and a
	 * discrepancy is something to find rather than to paper over.
	 *
	 * @since 26.0
	 */
	public function total_minor(): int {
		return (int) $this->get( 'total_minor', 0 );
	}
}
