<?php
/**
 * One person holding one place.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Domain\AttendeeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * A single row of the attendees table, as an object.
 *
 * A registration is the booking — who arranged it, who pays, one status, one
 * confirmation email. An attendee is a person, and there is always one row per
 * place, including when only one place was booked. See
 * docs/adr/0004-registration-attendee-split.md.
 *
 * The name may be empty. Somebody booking three places for their team may not
 * know who is coming yet, and refusing the booking over it would be worse than
 * an unnamed ticket. That is a known state, not an error.
 *
 * @since 26.0
 */
final class Attendee {

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
	 * The booking this place belongs to.
	 *
	 * @since 26.0
	 */
	public function registration_id(): int {
		return (int) $this->get( 'registration_id', 0 );
	}

	/**
	 * The date this place is for.
	 *
	 * Zero until a booking is tied to a specific occurrence, which happens when
	 * recurring events land. A one-off event has exactly one date, so nothing is
	 * ambiguous in the meantime.
	 *
	 * @since 26.0
	 */
	public function occurrence_id(): int {
		return (int) $this->get( 'occurrence_id', 0 );
	}

	/**
	 * The ticket type chosen for this person.
	 *
	 * Reserved. Zero until ticketing lands in stage 7.
	 *
	 * @since 26.0
	 */
	public function ticket_type_id(): int {
		return (int) $this->get( 'ticket_type_id', 0 );
	}

	/**
	 * The unique reference a QR code resolves to.
	 *
	 * One code admits one person, which is the whole reason attendees are rows.
	 *
	 * @since 26.0
	 */
	public function ticket_code(): string {
		return (string) $this->get( 'ticket_code', '' );
	}

	/**
	 * Which place within the booking this is, counting from one.
	 *
	 * Position 1 is the booker. It gives a stable order for a list of guests
	 * that would otherwise be sorted by an id nobody chose.
	 *
	 * @since 26.0
	 */
	public function position(): int {
		return (int) $this->get( 'position', 1 );
	}

	/**
	 * The person's name, which may legitimately be empty.
	 *
	 * @since 26.0
	 */
	public function name(): string {
		return (string) $this->get( 'name', '' );
	}

	/**
	 * The person's own email, if one was given.
	 *
	 * Separate from the booker's. A guest may want their own ticket without the
	 * organiser having to forward it.
	 *
	 * @since 26.0
	 */
	public function email(): string {
		return (string) $this->get( 'email', '' );
	}

	/**
	 * Whether a name was recorded for this place.
	 *
	 * @since 26.0
	 */
	public function is_named(): bool {
		return '' !== trim( $this->name() );
	}

	/**
	 * A name to show, falling back to the position.
	 *
	 * An attendee list of blanks is unusable at a check-in desk; "Guest 2" at
	 * least tells the person on the door which place they are looking at.
	 *
	 * @since 26.0
	 */
	public function display_name(): string {
		if ( $this->is_named() ) {
			return $this->name();
		}

		return sprintf(
			/* translators: %d: Position of the guest within a booking. */
			__( 'Guest %d', 'quick-events-manager' ),
			$this->position()
		);
	}

	/**
	 * Status, as an enum.
	 *
	 * @since 26.0
	 */
	public function status(): AttendeeStatus {
		return AttendeeStatus::coerce( $this->get( 'status' ), AttendeeStatus::Active );
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
	 * Whether this person still holds their place.
	 *
	 * @since 26.0
	 */
	public function is_active(): bool {
		return $this->status()->is_active();
	}

	/**
	 * When the row was created.
	 *
	 * @since 26.0
	 */
	public function created_at(): string {
		return (string) $this->get( 'created_at', '' );
	}

	/**
	 * The row as an array.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
