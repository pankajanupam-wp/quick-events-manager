<?php
/**
 * A single registration row.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Domain\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Value object over one row of the registrations table.
 *
 * @since 26.0
 */
final class Registration {

	/**
	 * Row data.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed>|object $row Database row.
	 */
	public function __construct( $row ) {
		$this->data = (array) $row;
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
	public function get( $key, $fallback = '' ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $fallback;
	}

	/**
	 * Registration id.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function id() {
		return (int) $this->get( 'id', 0 );
	}

	/**
	 * Event id.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function event_id() {
		return (int) $this->get( 'event_id', 0 );
	}

	/**
	 * Public reference code.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function code() {
		return (string) $this->get( 'code' );
	}

	/**
	 * Where this registration stands.
	 *
	 * An unrecognised column value falls back to Confirmed rather than
	 * throwing. The row exists and somebody is expecting a place; refusing to
	 * render the attendee list because one status is corrupt helps nobody.
	 *
	 * @since 26.0
	 */
	public function status(): RegistrationStatus {
		return RegistrationStatus::coerce( $this->get( 'status' ), RegistrationStatus::Confirmed );
	}

	/**
	 * The status as stored, for queries, REST payloads and CSV.
	 *
	 * @since 26.0
	 */
	public function status_value(): string {
		return $this->status()->value;
	}

	/**
	 * The name of the person who made the booking.
	 *
	 * Not "the attendee": a booking may cover several people, and each of them
	 * has a name of their own on the attendees table. See
	 * docs/adr/0004-registration-attendee-split.md.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function booker_name() {
		return (string) $this->get( 'booker_name' );
	}

	/**
	 * The booker's email address, where the confirmation goes.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function booker_email() {
		return (string) $this->get( 'booker_email' );
	}

	/**
	 * The booker's phone number, if they gave one.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function booker_phone() {
		return (string) $this->get( 'booker_phone' );
	}

	/**
	 * Number of places taken.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function quantity() {
		return max( 1, (int) $this->get( 'quantity', 1 ) );
	}

	/**
	 * When the registration was made, in UTC.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function created_at() {
		return (string) $this->get( 'created_at' );
	}

	/**
	 * Which consent wording was agreed to, if any.
	 *
	 * A fingerprint of the text, not the text: see
	 * QuickEventsManager\Privacy\Consent.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function consent_version() {
		return (string) $this->get( 'consent_version' );
	}

	/**
	 * When consent was given, in UTC.
	 *
	 * @since 26.0
	 *
	 * @return string Empty when no consent was recorded.
	 */
	public function consent_at() {
		return (string) $this->get( 'consent_at', '' );
	}

	/**
	 * Whether this registration carries a record of consent.
	 *
	 * @since 26.0
	 */
	public function has_consent(): bool {
		return '' !== $this->consent_version() && '' !== $this->consent_at();
	}

	/**
	 * Extra fields stored as JSON.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public function fields() {
		$raw = $this->get( 'fields', '' );

		if ( '' === $raw || null === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Whether this registration occupies a place.
	 *
	 * @since 26.0
	 */
	public function occupies_place(): bool {
		return $this->status()->occupies_place();
	}

	/**
	 * The row as an array, for REST and CSV.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'id'           => $this->id(),
			'event_id'     => $this->event_id(),
			'code'         => $this->code(),
			'status'       => $this->status_value(),
			'booker_name'  => $this->booker_name(),
			'booker_email' => $this->booker_email(),
			'booker_phone' => $this->booker_phone(),
			'quantity'     => $this->quantity(),
			'fields'       => $this->fields(),
			'created_at'   => $this->created_at(),
		);
	}
}
