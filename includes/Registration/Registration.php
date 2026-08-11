<?php
/**
 * A single registration row.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Value object over one row of the registrations table.
 *
 * @since 26.0
 */
final class Registration {

	/**
	 * Confirmed: counted against capacity, has a place.
	 */
	const STATUS_CONFIRMED = 'confirmed';

	/**
	 * Pending: counted against capacity, awaiting something.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Waitlisted: not counted, will be offered a place if one frees up.
	 */
	const STATUS_WAITLISTED = 'waitlisted';

	/**
	 * Cancelled: not counted, frees the place.
	 */
	const STATUS_CANCELLED = 'cancelled';

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
	 * @param array|object $row Database row.
	 */
	public function __construct( $row ) {
		$this->data = (array) $row;
	}

	/**
	 * Statuses that occupy a place.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function occupying_statuses() {
		return array( self::STATUS_CONFIRMED, self::STATUS_PENDING );
	}

	/**
	 * Every valid status.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array(
			self::STATUS_CONFIRMED,
			self::STATUS_PENDING,
			self::STATUS_WAITLISTED,
			self::STATUS_CANCELLED,
		);
	}

	/**
	 * Human-readable label for a status.
	 *
	 * @since 26.0
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_CONFIRMED  => __( 'Confirmed', 'quick-events-manager' ),
			self::STATUS_PENDING    => __( 'Pending', 'quick-events-manager' ),
			self::STATUS_WAITLISTED => __( 'Waitlisted', 'quick-events-manager' ),
			self::STATUS_CANCELLED  => __( 'Cancelled', 'quick-events-manager' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Read a raw column.
	 *
	 * @since 26.0
	 *
	 * @param string $key     Column name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = '' ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
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
	 * Status key.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function status() {
		return (string) $this->get( 'status' );
	}

	/**
	 * Attendee name.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function name() {
		return (string) $this->get( 'name' );
	}

	/**
	 * Attendee email.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function email() {
		return (string) $this->get( 'email' );
	}

	/**
	 * Attendee phone.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function phone() {
		return (string) $this->get( 'phone' );
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
	 * Extra fields stored as JSON.
	 *
	 * @since 26.0
	 *
	 * @return array
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
	 *
	 * @return bool
	 */
	public function occupies_place() {
		return in_array( $this->status(), self::occupying_statuses(), true );
	}

	/**
	 * The row as an array, for REST and CSV.
	 *
	 * @since 26.0
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'         => $this->id(),
			'event_id'   => $this->event_id(),
			'code'       => $this->code(),
			'status'     => $this->status(),
			'name'       => $this->name(),
			'email'      => $this->email(),
			'phone'      => $this->phone(),
			'quantity'   => $this->quantity(),
			'fields'     => $this->fields(),
			'created_at' => $this->created_at(),
		);
	}
}
