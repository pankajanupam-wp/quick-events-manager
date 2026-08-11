<?php
/**
 * Every database query against the occurrences table.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

/*
 * The occurrences table is custom, so direct queries are the only way to read
 * it — no core API knows it exists. Both sniffs are disabled for this file
 * rather than in phpcs.xml.dist, so that a $wpdb call appearing in a renderer or
 * a REST controller is still a finding everywhere else.
 *
 * NoCaching is disabled rather than answered because these rows back "what is
 * on next", and a stale answer there shows a visitor an event that has already
 * happened. The object cache is added deliberately in C1.4 alongside the query
 * layer that knows which reads are safe to cache and when to invalidate them,
 * not scattered through the repository now.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; see the note above.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching is C1.4's job; see the note above.

/**
 * The only class that talks to $wpdb about occurrences.
 *
 * Table names go through the `%i` identifier placeholder, so even the table name
 * is escaped by $wpdb rather than interpolated.
 *
 * @since 26.0
 */
final class OccurrenceRepository {

	/**
	 * Columns a caller may supply. Everything else is set here.
	 *
	 * @var string[]
	 */
	private const WRITABLE = array(
		'event_id',
		'series_uuid',
		'start_utc',
		'end_utc',
		'start_local',
		'end_local',
		'timezone',
		'all_day',
		'is_exception',
		'status',
	);

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 */
	public static function table(): string {
		return Installer::table( 'occurrences' );
	}

	/**
	 * Whether the table has been created.
	 *
	 * Memoised on a positive answer only. The table can appear during a request
	 * — an upgrade running on `admin_init` creates it — but nothing drops it
	 * again except uninstall, which does not then query it.
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
	 * Every occurrence for an event, soonest first.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return Occurrence[]
	 */
	public static function for_event( int $event_id ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE event_id = %d ORDER BY start_utc ASC, id ASC',
				self::table(),
				$event_id
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): Occurrence => new Occurrence( $row ),
			(array) $rows
		);
	}

	/**
	 * One occurrence by id.
	 *
	 * @since 26.0
	 *
	 * @param int $id Occurrence id.
	 */
	public static function find( int $id ): ?Occurrence {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return null !== $row ? new Occurrence( $row ) : null;
	}

	/**
	 * The soonest occurrence for an event that has not finished.
	 *
	 * What a listing shows for a recurring event, and what a one-off event's
	 * only row is.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $now_utc  Comparison point, `Y-m-d H:i:s` UTC. Defaults to now.
	 */
	public static function next_for_event( int $event_id, string $now_utc = '' ): ?Occurrence {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		if ( '' === $now_utc ) {
			$now_utc = gmdate( 'Y-m-d H:i:s' );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE event_id = %d AND end_utc >= %s ORDER BY start_utc ASC, id ASC LIMIT 1',
				self::table(),
				$event_id,
				$now_utc
			),
			ARRAY_A
		);

		return null !== $row ? new Occurrence( $row ) : null;
	}

	/**
	 * How many occurrences an event has.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 */
	public static function count_for_event( int $event_id ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %d', self::table(), $event_id )
		);
	}

	/**
	 * How many occurrences exist in total.
	 *
	 * Used by the rebuild command to report what it did.
	 *
	 * @since 26.0
	 */
	public static function count_all(): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Insert one occurrence.
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

		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$inserted = $wpdb->insert( self::table(), $row, self::formats( $row ) );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update one occurrence in place.
	 *
	 * @since 26.0
	 *
	 * @param int                  $id   Occurrence id.
	 * @param array<string, mixed> $data Column values to change.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$row               = self::normalise( $data );
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
	 * Delete one occurrence.
	 *
	 * @since 26.0
	 *
	 * @param int $id Occurrence id.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete every occurrence for an event.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return int Rows removed.
	 */
	public static function delete_for_event( int $event_id ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->delete( self::table(), array( 'event_id' => $event_id ), array( '%d' ) );
	}

	/**
	 * Make an event's occurrences match the set given, preserving ids.
	 *
	 * Reconciles rather than deleting and reinserting, keyed on `start_utc`. A
	 * row whose start is unchanged keeps its id, is updated in place if any
	 * other column moved, and is left alone if nothing did.
	 *
	 * The ids matter. `qevm_registrations.occurrence_id` arrives in C1.6, so
	 * from then on a registration points at a specific date. Truncating and
	 * reinserting on every save would hand every attendee a dangling reference
	 * the first time an organiser corrected a typo in the event title — a
	 * save that changed nothing about the dates at all.
	 *
	 * @since 26.0
	 *
	 * @param int                              $event_id    Event id.
	 * @param array<int, array<string, mixed>> $occurrences Desired set; each needs at least start_utc and end_utc.
	 * @return array{inserted: int, updated: int, deleted: int, unchanged: int}
	 */
	public static function replace_for_event( int $event_id, array $occurrences ): array {
		$result = array(
			'inserted'  => 0,
			'updated'   => 0,
			'deleted'   => 0,
			'unchanged' => 0,
		);

		if ( ! self::table_exists() ) {
			return $result;
		}

		$existing = array();

		foreach ( self::for_event( $event_id ) as $occurrence ) {
			// Keyed on start, which is what identifies a date within an event.
			$existing[ $occurrence->start_utc() ] = $occurrence;
		}

		foreach ( $occurrences as $wanted ) {
			$wanted             = self::normalise( $wanted );
			$wanted['event_id'] = $event_id;
			$start              = (string) ( $wanted['start_utc'] ?? '' );

			if ( '' === $start ) {
				continue;
			}

			if ( ! isset( $existing[ $start ] ) ) {
				if ( self::insert( $wanted ) > 0 ) {
					++$result['inserted'];
				}

				continue;
			}

			$current = $existing[ $start ];
			unset( $existing[ $start ] );

			if ( self::differs( $current, $wanted ) ) {
				if ( self::update( $current->id(), $wanted ) ) {
					++$result['updated'];
				}

				continue;
			}

			++$result['unchanged'];
		}

		// Anything left in $existing is a date the event no longer has.
		foreach ( $existing as $stale ) {
			if ( self::delete( $stale->id() ) ) {
				++$result['deleted'];
			}
		}

		return $result;
	}

	/**
	 * Whether a stored row disagrees with the values wanted for it.
	 *
	 * Compared as strings on purpose: these came out of the database as strings
	 * and a loose comparison would call 0 and '' equal, so an all_day flag
	 * being cleared would read as no change.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence           $current Stored row.
	 * @param array<string, mixed> $wanted  Desired values, already normalised.
	 */
	private static function differs( Occurrence $current, array $wanted ): bool {
		foreach ( $wanted as $column => $value ) {
			if ( (string) $current->get( $column ) !== (string) $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep only writable columns, and coerce each to its column type.
	 *
	 * `end_utc` falls back to `start_utc` rather than being allowed to be empty.
	 * That is the whole reason the column is NOT NULL: an event with no stated
	 * end still answers "has this finished" with one indexed comparison instead
	 * of an OR across two keys.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $data ): array {
		$row = array();

		foreach ( self::WRITABLE as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$row[ $column ] = $data[ $column ];
		}

		if ( isset( $row['event_id'] ) ) {
			$row['event_id'] = (int) $row['event_id'];
		}

		foreach ( array( 'all_day', 'is_exception' ) as $flag ) {
			if ( isset( $row[ $flag ] ) ) {
				$row[ $flag ] = $row[ $flag ] ? 1 : 0;
			}
		}

		foreach ( array( 'series_uuid', 'start_utc', 'end_utc', 'start_local', 'end_local', 'timezone', 'status' ) as $text ) {
			if ( isset( $row[ $text ] ) ) {
				$row[ $text ] = (string) $row[ $text ];
			}
		}

		if ( isset( $row['start_utc'] ) && empty( $row['end_utc'] ) ) {
			$row['end_utc'] = $row['start_utc'];
		}

		if ( isset( $row['start_local'] ) && empty( $row['end_local'] ) ) {
			$row['end_local'] = $row['start_local'];
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
		$integers = array( 'event_id', 'all_day', 'is_exception' );

		return array_map(
			static fn( string $column ): string => in_array( $column, $integers, true ) ? '%d' : '%s',
			array_keys( $row )
		);
	}
}
