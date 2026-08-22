<?php
/**
 * Every database query about check-ins.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CheckIn;

use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

/*
 * A custom table, so direct queries are the only way to read it. Both sniffs
 * are disabled for this file rather than project-wide, so a $wpdb call in a
 * renderer or a REST controller is still a finding everywhere else.
 *
 * NoCaching is disabled rather than answered because a door screen that reads a
 * cached answer tells the second member of staff that somebody has not arrived
 * when the first one just admitted them.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; see the note above.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- A door must not read a stale answer; see the note above.

/**
 * The only class that talks to $wpdb about check-ins.
 *
 * **The unique key is the concurrency guarantee, and it is the whole design.**
 * Two members of staff scanning the same person at two doors both attempt an
 * insert; the database lets exactly one through and the other comes back with a
 * duplicate-key error, which this reads as "already checked in, at 18:42". There
 * is no read-then-write, so there is no window between the read and the write —
 * the same reasoning as insert-then-rank in the registration repository, and for
 * the same reason: a check that happens before the write can always be raced.
 *
 * @since 26.0
 */
final class CheckInRepository {

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 */
	public static function table(): string {
		return Installer::table( 'checkins' );
	}

	/**
	 * Whether the table has been created.
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
	 * Record an arrival, or report that it is already recorded.
	 *
	 * Returns the new row's id, or 0 when the insert was refused — which on
	 * this table means one thing only: somebody is already checked in for that
	 * attendee and that date. The caller reads the existing row to say when.
	 *
	 * `$wpdb->insert()` suppresses errors and returns false, so a duplicate key
	 * looks the same as any other failure here. That is why the caller checks
	 * for an existing row rather than trusting a reason code: the outcome that
	 * matters — the person is already in — is true either way.
	 *
	 * @since 26.0
	 *
	 * @param int    $attendee_id   Attendee id.
	 * @param int    $occurrence_id Date arrived at, or 0.
	 * @param int    $by            User recording it, or 0.
	 * @param string $method        'manual' or 'qr'.
	 * @return int New id, or 0.
	 */
	public static function record( int $attendee_id, int $occurrence_id, int $by = 0, string $method = 'manual' ): int {
		global $wpdb;

		if ( $attendee_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'attendee_id'   => $attendee_id,
				'occurrence_id' => max( 0, $occurrence_id ),
				'checked_in_at' => gmdate( 'Y-m-d H:i:s' ),
				'checked_in_by' => max( 0, $by ),
				'method'        => 'qr' === $method ? 'qr' : 'manual',
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The check-in for one attendee on one date, reversed or not.
	 *
	 * @since 26.0
	 *
	 * @param int $attendee_id   Attendee id.
	 * @param int $occurrence_id Date, or 0.
	 */
	public static function find( int $attendee_id, int $occurrence_id ): ?CheckIn {
		global $wpdb;

		if ( $attendee_id <= 0 || ! self::table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE attendee_id = %d AND occurrence_id = %d',
				self::table(),
				$attendee_id,
				max( 0, $occurrence_id )
			),
			ARRAY_A
		);

		return null !== $row ? new CheckIn( $row ) : null;
	}

	/**
	 * Undo a check-in, keeping the record of it.
	 *
	 * Attendance is history: "checked in and then reversed" is different
	 * information from "never arrived", and only one of them can be told from
	 * the other after the row is gone.
	 *
	 * @since 26.0
	 *
	 * @param int $id Check-in id.
	 * @param int $by User reversing it, or 0.
	 */
	public static function reverse( int $id, int $by = 0 ): bool {
		global $wpdb;

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			array(
				'reversed_at' => gmdate( 'Y-m-d H:i:s' ),
				'reversed_by' => max( 0, $by ),
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Put a reversed check-in back, for the person admitted by mistake twice.
	 *
	 * @since 26.0
	 *
	 * @param int $id Check-in id.
	 */
	public static function unreverse( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			array(
				'reversed_at' => null,
				'reversed_by' => 0,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Everybody recorded as arriving at one date, most recent first.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id Date, or 0.
	 * @return CheckIn[]
	 */
	public static function for_occurrence( int $occurrence_id ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE occurrence_id = %d ORDER BY checked_in_at DESC, id DESC',
				self::table(),
				max( 0, $occurrence_id )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): CheckIn => new CheckIn( $row ),
			(array) $rows
		);
	}

	/**
	 * How many people are recorded as present at one date.
	 *
	 * Reversals do not count. Somebody admitted by mistake and sent back out is
	 * not in the room, and the number on a door screen has to mean the room.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id Date, or 0.
	 */
	public static function count_present( int $occurrence_id ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE occurrence_id = %d AND reversed_at IS NULL',
				self::table(),
				max( 0, $occurrence_id )
			)
		);
	}

	/**
	 * Remove every check-in for a set of attendees.
	 *
	 * What deletion cascades call. The parent owns the cascade here for the same
	 * reason it does everywhere else in this schema: dbDelta creates no foreign
	 * keys and WordPress does not assume InnoDB.
	 *
	 * @since 26.0
	 *
	 * @param int[] $attendee_ids Attendee ids.
	 * @return int Rows removed.
	 */
	public static function delete_for_attendees( array $attendee_ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $attendee_ids ), static fn( int $id ): bool => $id > 0 ) );

		if ( array() === $ids || ! self::table_exists() ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated list of %d and every value goes through prepare(), in the array form the sniff cannot count.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE attendee_id IN ( {$placeholders} )",
				array_merge( array( self::table() ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * The table definition.
	 *
	 * As specified in docs/database.md, including the departure from this
	 * schema's "every table carries created_at and updated_at" convention. A
	 * check-in row is created at the moment it records, so `checked_in_at` is
	 * its created_at under a name that says what the time means; `reversed_at`
	 * is the only update it can ever receive. Two more timestamps would be a
	 * copy of one and a duplicate of the other.
	 *
	 * @since 26.0
	 *
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	public static function schema(): string {
		$table   = self::table();
		$collate = Installer::charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attendee_id bigint(20) unsigned NOT NULL,
			occurrence_id bigint(20) unsigned NOT NULL,
			checked_in_at datetime NOT NULL,
			checked_in_by bigint(20) unsigned NOT NULL DEFAULT 0,
			method varchar(20) NOT NULL DEFAULT 'manual',
			reversed_at datetime DEFAULT NULL,
			reversed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY attendee_occurrence (attendee_id, occurrence_id),
			KEY occurrence_time (occurrence_id, checked_in_at)
		) {$collate};";
	}
}
