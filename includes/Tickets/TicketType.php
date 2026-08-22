<?php
/**
 * One kind of ticket for one event.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tickets;

use QuickEventsManager\Domain\TicketTypeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * A row from `qevm_ticket_types`, read-only.
 *
 * **Free is a price of zero, not a different kind of thing.** "Standard — free"
 * and "Standard — £12" are the same entity with a different number in one
 * column, so there is no free path and no paid path to keep in step, and an
 * organiser who starts charging changes a number rather than migrating.
 *
 * Money is held in **minor units** — pence, cents, paise — as an integer.
 * Floating point cannot represent 0.10 exactly, and a price that is a float is a
 * total that is out by a penny at scale. The currency itself is not stored here:
 * a site has one, and giving every ticket type its own would be a modelling
 * mistake that is expensive to unpick later.
 *
 * @since 26.0
 */
final class TicketType {

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
	 * Row id.
	 *
	 * @since 26.0
	 */
	public function id(): int {
		return (int) $this->get( 'id', 0 );
	}

	/**
	 * The event this type belongs to.
	 *
	 * @since 26.0
	 */
	public function event_id(): int {
		return (int) $this->get( 'event_id', 0 );
	}

	/**
	 * What it is called on the form.
	 *
	 * @since 26.0
	 */
	public function name(): string {
		return (string) $this->get( 'name', '' );
	}

	/**
	 * The sentence under the name, or ''.
	 *
	 * @since 26.0
	 */
	public function description(): string {
		return (string) $this->get( 'description', '' );
	}

	/**
	 * Price in minor units. Zero is free.
	 *
	 * @since 26.0
	 */
	public function price_minor(): int {
		return (int) $this->get( 'price_minor', 0 );
	}

	/**
	 * Whether this type costs nothing.
	 *
	 * @since 26.0
	 */
	public function is_free(): bool {
		return 0 === $this->price_minor();
	}

	/**
	 * Places of this type, or 0 for as many as the event allows.
	 *
	 * @since 26.0
	 */
	public function capacity(): int {
		return (int) $this->get( 'capacity', 0 );
	}

	/**
	 * Where it sits in the list shown to a visitor.
	 *
	 * @since 26.0
	 */
	public function sort_order(): int {
		return (int) $this->get( 'sort_order', 0 );
	}

	/**
	 * The date this type is only for, or 0 for every date of the event.
	 *
	 * Zero for every type created today. The column is here because
	 * docs/database.md specified it two stages ago, and because a single date
	 * needing its own tickets — one workshop in a course, priced differently —
	 * is the obvious next request.
	 *
	 * @since 26.0
	 */
	public function occurrence_id(): int {
		return (int) $this->get( 'occurrence_id', 0 );
	}

	/**
	 * The currency code, or '' for the site's own.
	 *
	 * @since 26.0
	 */
	public function currency(): string {
		return (string) $this->get( 'currency', '' );
	}

	/**
	 * Fewest of this type in one booking.
	 *
	 * @since 26.0
	 */
	public function min_per_order(): int {
		return max( 1, (int) $this->get( 'min_per_order', 1 ) );
	}

	/**
	 * Most of this type in one booking, or 0 for no limit of its own.
	 *
	 * @since 26.0
	 */
	public function max_per_order(): int {
		return max( 0, (int) $this->get( 'max_per_order', 0 ) );
	}

	/**
	 * Whether it is on offer.
	 *
	 * @since 26.0
	 */
	public function status(): TicketTypeStatus {
		return TicketTypeStatus::coerce( (string) $this->get( 'status', TicketTypeStatus::Active->value ) );
	}

	/**
	 * The stored status value.
	 *
	 * @since 26.0
	 */
	public function status_value(): string {
		return $this->status()->value;
	}

	/**
	 * Whether somebody may choose this type today.
	 *
	 * Sale windows are C7.4 and are deliberately not consulted here — this
	 * answers what the organiser decided about the type, not what the clock
	 * says.
	 *
	 * @since 26.0
	 */
	public function is_sellable(): bool {
		return $this->status()->is_sellable();
	}

	/**
	 * When the sale opens, or '' for straight away.
	 *
	 * @since 26.0
	 */
	public function sale_starts_utc(): string {
		$value = $this->get( 'sale_starts_utc', '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * When the sale closes, or ''.
	 *
	 * @since 26.0
	 */
	public function sale_ends_utc(): string {
		$value = $this->get( 'sale_ends_utc', '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether this type's sale has not started yet.
	 *
	 * There is deliberately no `is_on_sale()` beside this and `closed_by()`. It
	 * was written, and sabotaging it to return true broke nothing — because
	 * every caller needs to know *which* of the two is false. The form says
	 * "on sale from Monday" for one and drops the type entirely for the other;
	 * the service returns a different error for each, because "not yet" and "no
	 * longer" need different things from the person reading them. A combined
	 * answer would have to be taken apart again at every call site.
	 *
	 * **An early bird is not a separate kind of thing.** It is a type whose
	 * window closes early and whose price is lower, which is why neither the
	 * schema nor the code has the words in it anywhere.
	 *
	 * @since 26.0
	 *
	 * @param string $now_utc Comparison point, `Y-m-d H:i:s` UTC. Defaults to now.
	 */
	public function opens_after( string $now_utc = '' ): bool {
		$starts = $this->sale_starts_utc();

		if ( '' === $starts ) {
			return false;
		}

		return ( '' === $now_utc ? gmdate( 'Y-m-d H:i:s' ) : $now_utc ) < $starts;
	}

	/**
	 * Whether this type's sale has finished.
	 *
	 * @since 26.0
	 *
	 * @param string $now_utc Comparison point, `Y-m-d H:i:s` UTC. Defaults to now.
	 */
	public function closed_by( string $now_utc = '' ): bool {
		$ends = $this->sale_ends_utc();

		if ( '' === $ends ) {
			return false;
		}

		return ( '' === $now_utc ? gmdate( 'Y-m-d H:i:s' ) : $now_utc ) > $ends;
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
