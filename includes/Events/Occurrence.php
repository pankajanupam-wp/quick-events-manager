<?php
/**
 * One date an event happens on.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

use QuickEventsManager\Domain\OccurrenceStatus;

defined( 'ABSPATH' ) || exit;

/**
 * A single row of the occurrences table, as an object.
 *
 * Derived data: post meta is what the editor writes and what a human reads, and
 * an occurrence is regenerated from it. Nothing should ever edit an occurrence
 * expecting the change to survive the next save of its event.
 *
 * A one-off event has exactly one of these, so the simple case carries no
 * conceptual overhead — there is no "does this event recur" branch anywhere in
 * the query path.
 *
 * @since 26.0
 */
final class Occurrence {

	/**
	 * Raw column values.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Wrap a database row.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Column values.
	 */
	public function __construct( array $row = array() ) {
		$this->data = $row;
	}

	/**
	 * Read a raw column.
	 *
	 * @since 26.0
	 *
	 * @param string $key      Column name.
	 * @param mixed  $fallback Returned when the column is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = '' ): mixed {
		return $this->data[ $key ] ?? $fallback;
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
	 * The event this date belongs to.
	 *
	 * @since 26.0
	 */
	public function event_id(): int {
		return (int) $this->get( 'event_id', 0 );
	}

	/**
	 * The slot the recurrence rule generated this row for.
	 *
	 * `''` for a one-off event's row, which no rule generated.
	 *
	 * This, and not `start_utc`, is what identifies a generated row. Moving an
	 * occurrence changes when it happens; it does not change which slot in the
	 * series it is. See docs/adr/0015-recurrence-identity-and-overrides.md.
	 *
	 * @since 26.0
	 */
	public function recurrence_id(): string {
		$value = $this->get( 'recurrence_id', '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether a recurrence rule generated this row.
	 *
	 * @since 26.0
	 */
	public function is_generated(): bool {
		return '' !== $this->recurrence_id();
	}

	/**
	 * Series identifier, empty until recurrence lands.
	 *
	 * @since 26.0
	 */
	public function series_uuid(): string {
		return (string) $this->get( 'series_uuid', '' );
	}

	/**
	 * Start, in UTC. The only value ever sorted or filtered on.
	 *
	 * @since 26.0
	 */
	public function start_utc(): string {
		return (string) $this->get( 'start_utc', '' );
	}

	/**
	 * End, in UTC.
	 *
	 * Never empty. An event with no stated end stores its start here, which is
	 * what collapses "is this still upcoming" into one indexed range scan
	 * instead of a three-branch OR. See docs/adr/0003-occurrence-table.md.
	 *
	 * @since 26.0
	 */
	public function end_utc(): string {
		return (string) $this->get( 'end_utc', '' );
	}

	/**
	 * Start as the organiser typed it, in the event's own timezone.
	 *
	 * @since 26.0
	 */
	public function start_local(): string {
		return (string) $this->get( 'start_local', '' );
	}

	/**
	 * End as the organiser typed it.
	 *
	 * @since 26.0
	 */
	public function end_local(): string {
		return (string) $this->get( 'end_local', '' );
	}

	/**
	 * IANA timezone identifier the local values are expressed in.
	 *
	 * @since 26.0
	 */
	public function timezone(): string {
		return (string) $this->get( 'timezone', '' );
	}

	/**
	 * Whether this is an all-day date.
	 *
	 * @since 26.0
	 */
	public function is_all_day(): bool {
		return (bool) (int) $this->get( 'all_day', 0 );
	}

	/**
	 * Whether this instance departs from its series.
	 *
	 * Always false until recurrence lands.
	 *
	 * @since 26.0
	 */
	public function is_exception(): bool {
		return (bool) (int) $this->get( 'is_exception', 0 );
	}

	/**
	 * Status, as an enum.
	 *
	 * @since 26.0
	 */
	public function status(): OccurrenceStatus {
		return OccurrenceStatus::coerce( $this->get( 'status' ), OccurrenceStatus::Scheduled );
	}

	/**
	 * Status, as the string the column holds.
	 *
	 * @since 26.0
	 */
	public function status_value(): string {
		return $this->status()->value;
	}

	/**
	 * Whether this date should appear in listings.
	 *
	 * @since 26.0
	 */
	public function is_listable(): bool {
		return $this->status()->is_listable();
	}

	/**
	 * Whether this date has finished, against a UTC comparison point.
	 *
	 * @since 26.0
	 *
	 * @param string $now_utc `Y-m-d H:i:s` in UTC. Defaults to now.
	 */
	public function has_ended( string $now_utc = '' ): bool {
		if ( '' === $now_utc ) {
			$now_utc = gmdate( 'Y-m-d H:i:s' );
		}

		return $this->end_utc() < $now_utc;
	}

	/**
	 * The row as an array, for writing.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
