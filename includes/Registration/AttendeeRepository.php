<?php
/**
 * Every database query against the attendees table.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\CustomFields\AnswerRepository;
use QuickEventsManager\Domain\AttendeeStatus;
use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

/*
 * A custom table, so direct queries are the only way to read it. Disabled for
 * this file rather than in phpcs.xml.dist, so a $wpdb call appearing in a
 * renderer or a REST controller is still a finding everywhere else.
 *
 * NoCaching is disabled rather than answered because these rows decide whether
 * a person gets through a door. A stale answer at a check-in desk is worse than
 * a slow one, and the volume is one query per scan.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; see the note above.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Check-in must not read a stale row; see the note above.

/**
 * The only class that talks to $wpdb about attendees.
 *
 * @since 26.0
 */
final class AttendeeRepository {

	/**
	 * Columns a caller may supply. Everything else is set here.
	 *
	 * @var string[]
	 */
	private const WRITABLE = array(
		'registration_id',
		'occurrence_id',
		'ticket_type_id',
		'ticket_code',
		'position',
		'name',
		'email',
		'status',
	);

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 */
	public static function table(): string {
		return Installer::table( 'attendees' );
	}

	/**
	 * Whether the table has been created.
	 *
	 * Memoised on a positive answer only, like the other repositories: the
	 * table appears when the registration module is switched on, and nothing
	 * drops it again except uninstall.
	 *
	 * @since 26.0
	 */
	public static function table_exists(): bool {
		global $wpdb;

		static $exists = false;

		if ( $exists ) {
			return true;
		}

		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) )
		);

		return $exists;
	}

	/**
	 * Create one row per place for a registration.
	 *
	 * Called after the registration has resolved, never before. Capacity is
	 * counted in places on the booking by the insert-then-rank routine in
	 * Repository, and creating attendee rows afterwards leaves that concurrency
	 * guarantee exactly as it was. See
	 * docs/adr/0004-registration-attendee-split.md.
	 *
	 * A name is optional for every place including the first. Somebody booking
	 * three places for their team may not know who is coming yet, and refusing
	 * the booking over it would be worse than an unnamed ticket.
	 *
	 * @since 26.0
	 *
	 * @param int                              $registration_id Booking id.
	 * @param int                              $quantity        Places booked.
	 * @param array<int, array<string, mixed>> $people          Per-place details, in order. Shorter than $quantity is fine.
	 * @param array<string, mixed>             $shared          Values applied to every row, e.g. occurrence_id.
	 * @return Attendee[]
	 */
	public static function create_for_registration( int $registration_id, int $quantity, array $people = array(), array $shared = array() ) {
		if ( ! self::table_exists() || $registration_id <= 0 ) {
			return array();
		}

		$quantity = max( 1, $quantity );
		$created  = array();
		$people   = array_values( $people );

		for ( $position = 1; $position <= $quantity; $position++ ) {
			$person = $people[ $position - 1 ] ?? array();

			$id = self::insert(
				array_merge(
					$shared,
					$person,
					array(
						'registration_id' => $registration_id,
						'position'        => $position,
					)
				)
			);

			if ( $id > 0 ) {
				$attendee = self::find( $id );

				if ( null !== $attendee ) {
					$created[] = $attendee;
				}
			}
		}

		return $created;
	}

	/**
	 * Insert one attendee.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data Column values; see self::WRITABLE.
	 * @return int New id, or 0 on failure.
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = self::normalise( $data );

		if ( '' === (string) ( $row['ticket_code'] ?? '' ) ) {
			$row['ticket_code'] = self::generate_ticket_code();
		}

		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$inserted = $wpdb->insert( self::table(), $row, self::formats( $row ) );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One attendee by id.
	 *
	 * @since 26.0
	 *
	 * @param int $id Attendee id.
	 */
	public static function find( int $id ): ?Attendee {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return null !== $row ? new Attendee( $row ) : null;
	}

	/**
	 * One attendee by the code on their ticket.
	 *
	 * The check-in lookup. Backed by a unique index, so it is one seek.
	 *
	 * @since 26.0
	 *
	 * @param string $code Ticket code.
	 */
	public static function find_by_ticket_code( string $code ): ?Attendee {
		global $wpdb;

		if ( ! self::table_exists() || '' === $code ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ticket_code = %s', self::table(), $code ),
			ARRAY_A
		);

		return null !== $row ? new Attendee( $row ) : null;
	}

	/**
	 * Everyone on a booking, in the order the places were taken.
	 *
	 * @since 26.0
	 *
	 * @param int $registration_id Booking id.
	 * @return Attendee[]
	 */
	public static function for_registration( int $registration_id ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE registration_id = %d ORDER BY position ASC, id ASC',
				self::table(),
				$registration_id
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): Attendee => new Attendee( $row ),
			(array) $rows
		);
	}

	/**
	 * How many places a booking has rows for.
	 *
	 * @since 26.0
	 *
	 * @param int $registration_id Booking id.
	 */
	public static function count_for_registration( int $registration_id ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE registration_id = %d', self::table(), $registration_id )
		);
	}

	/**
	 * Every attendee recorded against an email address.
	 *
	 * Backs the privacy exporter and eraser. A guest's own address is stored
	 * separately from the booker's, so both have to be reachable.
	 *
	 * @since 26.0
	 *
	 * @param string $email Email address.
	 * @return Attendee[]
	 */
	public static function find_by_email( string $email ): array {
		global $wpdb;

		if ( ! self::table_exists() || '' === $email ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id ASC', self::table(), $email ),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): Attendee => new Attendee( $row ),
			(array) $rows
		);
	}

	/**
	 * Update one attendee.
	 *
	 * @since 26.0
	 *
	 * @param int                  $id   Attendee id.
	 * @param array<string, mixed> $data Column values to change.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$row = self::normalise( $data );

		if ( empty( $row ) ) {
			return false;
		}

		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update(
			self::table(),
			$row,
			array( 'id' => $id ),
			self::formats( $row ),
			array( '%d' )
		);
	}

	/**
	 * Change one attendee's status.
	 *
	 * @since 26.0
	 *
	 * @param int            $id     Attendee id.
	 * @param AttendeeStatus $status New status.
	 */
	public static function update_status( int $id, AttendeeStatus $status ): bool {
		return self::update( $id, array( 'status' => $status->value ) );
	}

	/**
	 * Cancel every place on a booking.
	 *
	 * Cancelling the booking has to reach the people on it, or a cancelled
	 * registration would still admit three colleagues at the door. The rows are
	 * kept rather than deleted so a scan can say "cancelled" instead of
	 * "unknown ticket" — a very different conversation to have with someone
	 * standing in front of you.
	 *
	 * @since 26.0
	 *
	 * @param int $registration_id Booking id.
	 * @return int Rows changed.
	 */
	public static function cancel_for_registration( int $registration_id ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE registration_id = %d AND status != %s',
				self::table(),
				AttendeeStatus::Cancelled->value,
				gmdate( 'Y-m-d H:i:s' ),
				$registration_id,
				AttendeeStatus::Cancelled->value
			)
		);

		return (int) $changed;
	}

	/**
	 * Put everyone on a booking back, after the booking itself was reinstated.
	 *
	 * The mirror of cancel_for_registration(), and the reason an administrator
	 * who cancels the wrong row can undo it. Only rows that are cancelled are
	 * touched, so this cannot invent a place that was never held.
	 *
	 * It does reactivate somebody who dropped out of a booking that was later
	 * cancelled and reinstated. Nothing in 26.0 can cancel one person out of a
	 * booking, so there is no such row to get wrong yet; when per-attendee
	 * cancellation arrives the two cases have to be told apart, and this is
	 * where that happens.
	 *
	 * @since 26.0
	 *
	 * @param int $registration_id Booking id.
	 * @return int Rows changed.
	 */
	public static function reinstate_for_registration( int $registration_id ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE registration_id = %d AND status = %s',
				self::table(),
				AttendeeStatus::Active->value,
				gmdate( 'Y-m-d H:i:s' ),
				$registration_id,
				AttendeeStatus::Cancelled->value
			)
		);

		return (int) $changed;
	}

	/**
	 * Delete every attendee on a booking.
	 *
	 * Used by the privacy eraser and by explicit deletion, never by ordinary
	 * cancellation — that is what cancel_for_registration() is for.
	 *
	 * @since 26.0
	 *
	 * @param int $registration_id Booking id.
	 * @return int Rows removed.
	 */
	public static function delete_for_registration( int $registration_id ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		self::forget_answers( self::ids_for_registration( $registration_id ) );

		return (int) $wpdb->delete( self::table(), array( 'registration_id' => $registration_id ), array( '%d' ) );
	}

	/**
	 * The attendee ids on one booking.
	 *
	 * @since 26.0
	 *
	 * @param int $registration_id Booking id.
	 * @return int[]
	 */
	private static function ids_for_registration( int $registration_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the ids are read to delete what hangs off them, so a cached answer is the wrong one.
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE registration_id = %d', self::table(), $registration_id )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Remove the custom answers a set of attendees gave.
	 *
	 * Called from here rather than left to the custom fields module, and
	 * regardless of whether that module is switched on. The rows exist whatever
	 * the Features screen says, and an attendee deleted while the module was off
	 * would otherwise leave their dietary requirements in a table with nothing
	 * left pointing at them — including when the deletion is a privacy erasure,
	 * which is the one case where "we removed everything" has to be true.
	 *
	 * @since 26.0
	 *
	 * @param int[] $attendee_ids Attendee ids.
	 * @return void
	 */
	private static function forget_answers( array $attendee_ids ): void {
		if ( array() === $attendee_ids || ! AnswerRepository::table_exists() ) {
			return;
		}

		AnswerRepository::delete_for_attendees( $attendee_ids );
	}

	/**
	 * Delete everyone booked onto an event, across every booking.
	 *
	 * Attendee rows carry a registration id and no event id, so the event is
	 * reached through the booking. A join rather than a list of ids: an event
	 * with two thousand bookings would otherwise build a two-thousand
	 * placeholder IN clause, and the answer to "which rows" belongs in the
	 * database rather than in PHP memory.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return int Rows removed.
	 */
	public static function delete_for_event( int $event_id ): int {
		global $wpdb;

		if ( ! self::table_exists() || ! Repository::table_exists() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; the ids are read to delete what hangs off them.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT a.id FROM %i AS a INNER JOIN %i AS r ON a.registration_id = r.id WHERE r.event_id = %d',
				self::table(),
				Repository::table(),
				$event_id
			)
		);

		self::forget_answers( array_map( 'intval', (array) $ids ) );

		$removed = $wpdb->query(
			$wpdb->prepare(
				'DELETE a FROM %i AS a INNER JOIN %i AS r ON a.registration_id = r.id WHERE r.event_id = %d',
				self::table(),
				Repository::table(),
				$event_id
			)
		);

		return (int) $removed;
	}

	/**
	 * A unique code for one ticket.
	 *
	 * Ambiguous characters are left out so the code survives being read aloud
	 * or copied off a screen at a check-in desk.
	 *
	 * The prefix differs from a registration's on purpose. Both appear in
	 * confirmation emails, and somebody typing a booking reference into a
	 * check-in field should be told they have the wrong kind of code rather
	 * than simply not found.
	 *
	 * @since 26.0
	 */
	public static function generate_ticket_code(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$length   = strlen( $alphabet );

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$code = 'QEVT-';

			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $alphabet[ wp_rand( 0, $length - 1 ) ];
			}

			if ( null === self::find_by_ticket_code( $code ) ) {
				return $code;
			}
		}

		// Ten collisions against a 32^8 space means something is very wrong.
		return 'QEVT-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 12 ) );
	}

	/**
	 * Keep only writable columns, and coerce each to its column type.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $data ): array {
		$row = array();

		foreach ( self::WRITABLE as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$row[ $column ] = $data[ $column ];
			}
		}

		foreach ( array( 'registration_id', 'occurrence_id', 'ticket_type_id', 'position' ) as $number ) {
			if ( isset( $row[ $number ] ) ) {
				$row[ $number ] = max( 0, (int) $row[ $number ] );
			}
		}

		foreach ( array( 'ticket_code', 'name', 'email', 'status' ) as $text ) {
			if ( isset( $row[ $text ] ) ) {
				$row[ $text ] = (string) $row[ $text ];
			}
		}

		return $row;
	}

	/**
	 * $wpdb format specifiers matching a row's columns, in order.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Row about to be written.
	 * @return string[]
	 */
	private static function formats( array $row ): array {
		$integers = array( 'registration_id', 'occurrence_id', 'ticket_type_id', 'position' );

		return array_map(
			static fn( string $column ): string => in_array( $column, $integers, true ) ? '%d' : '%s',
			array_keys( $row )
		);
	}
}
