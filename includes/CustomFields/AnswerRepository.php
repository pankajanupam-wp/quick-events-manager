<?php
/**
 * Storage for the answers people give to custom questions.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CustomFields;

use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * All `$wpdb` access for `qevm_attendee_meta`.
 *
 * Answers get a table where the definitions get JSON, and the difference is
 * what is asked of each. A definition is read whole and never searched. An
 * answer is counted, filtered and exported: "how many vegetarians", "who needs
 * step-free access", "everybody who chose the morning session". JSON cannot be
 * indexed on MySQL 5.7, which WordPress still supports, so those questions
 * would each be a full scan and a `LIKE` over serialised text.
 *
 * One row per answer per attendee, mirroring core's `postmeta` shape so it is
 * recognisable on sight. A choose-any question stores one row per choice, which
 * is what makes "how many people picked this option" a `COUNT` rather than a
 * string search.
 *
 * @since 26.0
 */
final class AnswerRepository {

	/**
	 * The answers table.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'attendee_meta' );
	}

	/**
	 * Whether the table exists yet.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking for a table's existence cannot be cached; the answer is the thing being established.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Replace one attendee's answers.
	 *
	 * Delete-then-insert rather than a row-by-row diff. An attendee's answers
	 * are a handful of rows written once at registration and rarely edited, so
	 * the diff would be more code to be wrong in than it could ever save.
	 *
	 * @since 26.0
	 *
	 * @param int                            $attendee_id Attendee id.
	 * @param array<string, string|string[]> $answers     Answers, keyed by field key.
	 * @return void
	 */
	public static function save( $attendee_id, array $answers ) {
		global $wpdb;

		$attendee_id = (int) $attendee_id;

		if ( $attendee_id <= 0 ) {
			return;
		}

		self::delete_for_attendee( $attendee_id );

		$table = self::table();

		foreach ( $answers as $key => $value ) {
			foreach ( is_array( $value ) ? $value : array( $value ) as $single ) {
				if ( '' === (string) $single ) {
					continue;
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- Custom table with no core API. The meta_key and meta_value here are this table's own column names, not a WP_Query argument.
				$wpdb->insert(
					$table,
					array(
						'attendee_id' => $attendee_id,
						'meta_key'    => (string) $key,
						'meta_value'  => (string) $single,
					),
					array( '%d', '%s', '%s' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
			}
		}
	}

	/**
	 * One attendee's answers, keyed by field key.
	 *
	 * A question that allows several choices comes back as an array; every
	 * other question comes back as a string. The caller knows which it asked.
	 *
	 * @since 26.0
	 *
	 * @param int $attendee_id Attendee id.
	 * @return array<string, string|string[]>
	 */
	public static function for_attendee( $attendee_id ) {
		$all = self::for_attendees( array( (int) $attendee_id ) );

		return isset( $all[ (int) $attendee_id ] ) ? $all[ (int) $attendee_id ] : array();
	}

	/**
	 * Answers for several attendees at once.
	 *
	 * The attendee screen and the CSV export both list many people, and asking
	 * per row is the N+1 that makes an export of a thousand attendees time out.
	 *
	 * @since 26.0
	 *
	 * @param int[] $attendee_ids Attendee ids.
	 * @return array<int, array<string, string|string[]>>
	 */
	public static function for_attendees( array $attendee_ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $attendee_ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		/*
		 * The IN list is built from a count of integers, never from input, and
		 * the ids themselves go through prepare(). PHPCS cannot see that the
		 * interpolated string is only ever "%d, %d, %d".
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attendee_id, meta_key, meta_value
				 FROM {$table}
				 WHERE attendee_id IN ({$placeholders})
				 ORDER BY meta_id ASC",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$answers = array();

		foreach ( (array) $rows as $row ) {
			$attendee_id = (int) $row['attendee_id'];
			$key         = (string) $row['meta_key'];
			$value       = (string) $row['meta_value'];

			if ( ! isset( $answers[ $attendee_id ][ $key ] ) ) {
				$answers[ $attendee_id ][ $key ] = $value;

				continue;
			}

			/*
			 * A second row for the same key means a choose-any question, so
			 * the value becomes a list. Deciding it from the rows rather than
			 * from the definition means an answer still reads correctly after
			 * somebody changes a question's type.
			 */
			$answers[ $attendee_id ][ $key ] = array_merge(
				(array) $answers[ $attendee_id ][ $key ],
				array( $value )
			);
		}

		return $answers;
	}

	/**
	 * Delete every answer an attendee gave.
	 *
	 * @since 26.0
	 *
	 * @param int $attendee_id Attendee id.
	 * @return int Rows removed.
	 */
	public static function delete_for_attendee( $attendee_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; there is no core API for it.
		return (int) $wpdb->delete( self::table(), array( 'attendee_id' => (int) $attendee_id ), array( '%d' ) );
	}

	/**
	 * Delete every answer given by a list of attendees.
	 *
	 * @since 26.0
	 *
	 * @param int[] $attendee_ids Attendee ids.
	 * @return int Rows removed.
	 */
	public static function delete_for_attendees( array $attendee_ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $attendee_ids ) ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above: the IN list is a generated run of %d and the ids go through prepare().
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE attendee_id IN ({$placeholders})",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * The schema, for dbDelta().
	 *
	 * Deliberately mirrors core's `postmeta`: `meta_id`, `attendee_id`,
	 * `meta_key`, `meta_value`. Every WordPress developer already knows how to
	 * read it, and the shape is proven at a scale this will never reach.
	 *
	 * No `created_at` or `updated_at`, departing from the convention that every
	 * table carries them. A row here has no life of its own — it is created
	 * with its attendee and deleted with them, and the retention sweep works
	 * from the registration's date rather than the answer's. Two datetime
	 * columns per answer would be storage spent recording nothing anybody can
	 * ask a question about.
	 *
	 * @since 26.0
	 *
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	public static function schema() {
		$table   = self::table();
		$collate = Installer::charset_collate();

		return "CREATE TABLE {$table} (
			meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attendee_id bigint(20) unsigned NOT NULL,
			meta_key varchar(190) NOT NULL,
			meta_value longtext DEFAULT NULL,
			PRIMARY KEY  (meta_id),
			KEY attendee_key (attendee_id, meta_key),
			KEY meta_key (meta_key(60))
		) {$collate};";
	}
}
