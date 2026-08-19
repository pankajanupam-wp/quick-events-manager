<?php
/**
 * Every database query against the occurrences table.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

use QuickEventsManager\Domain\OccurrenceStatus;
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
		'recurrence_id',
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
	 * Every listable occurrence touching a range of local dates.
	 *
	 * Bounded on `start_local` and `end_local` rather than the UTC columns,
	 * because a calendar grid is a set of *dates* and the date an event belongs
	 * on is the one the organiser typed. A 23:00 event in Kolkata is not on the
	 * previous day because a visitor is reading from London, and the grid would
	 * be lying to both of them if it were.
	 *
	 * An occurrence is returned when it overlaps the range at all, so an event
	 * running from the 30th to the 2nd appears in both months.
	 *
	 * @since 26.0
	 *
	 * @param string $from Local date, `Y-m-d`, inclusive.
	 * @param string $to   Local date, `Y-m-d`, inclusive.
	 * @return Occurrence[]
	 */
	public static function for_local_range( string $from, string $to ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE status = %s
				   AND start_local <= %s
				   AND end_local >= %s
				 ORDER BY start_local ASC, id ASC',
				self::table(),
				OccurrenceStatus::Scheduled->value,
				$to . ' 23:59:59',
				$from . ' 00:00:00'
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

		if ( ! $inserted ) {
			return 0;
		}

		self::invalidate_query_cache();

		return (int) $wpdb->insert_id;
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

		$updated = $wpdb->update(
			self::table(),
			$row,
			array( 'id' => $id ),
			self::formats( $row ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		self::invalidate_query_cache();

		return true;
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

		$deleted = (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			self::invalidate_query_cache();
		}

		return $deleted;
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

		$deleted = (int) $wpdb->delete( self::table(), array( 'event_id' => $event_id ), array( '%d' ) );

		if ( $deleted > 0 ) {
			self::invalidate_query_cache();
		}

		return $deleted;
	}

	/**
	 * Hand a set of occurrences to a different event, keeping their ids.
	 *
	 * What a "this and following" split runs, and the reason it is an `UPDATE`
	 * rather than a delete and a regenerate. The rows keep their ids, so
	 * anything pointing at a date — a booking, an attendee, a ticket — still
	 * points at the same date afterwards, and this method never has to know
	 * those tables exist.
	 *
	 * One statement rather than a loop, because a split interrupted half way
	 * through leaves a series in two halves that each hold some of the other's
	 * dates, which is worse than either outcome of it not running at all.
	 *
	 * @since 26.0
	 *
	 * @param int[] $ids      Occurrence ids to move.
	 * @param int   $event_id Event to move them to.
	 * @return int Rows moved.
	 */
	public static function move_to_event( array $ids, int $event_id ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );

		if ( array() === $ids || $event_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated list of %d and every value goes through prepare(); the sniff counts only the placeholders it can see in the literal.
		$moved = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET event_id = %d WHERE id IN ( {$placeholders} )",
				array_merge( array( self::table(), $event_id ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( $moved > 0 ) {
			self::invalidate_query_cache();
		}

		return $moved;
	}

	/**
	 * Make an event's occurrences match the set given, preserving ids.
	 *
	 * Reconciles rather than deleting and reinserting. A row that the new set
	 * still contains keeps its id, is updated in place if any column moved, and
	 * is left alone if nothing did.
	 *
	 * The ids matter. `qevm_registrations.occurrence_id` points at a specific
	 * date, so truncating and reinserting on every save would hand every attendee
	 * a dangling reference the first time an organiser corrected a typo in the
	 * event title — a save that changed nothing about the dates at all.
	 *
	 * **Rows are matched by the slot they came from, not by when they happen.**
	 * A generated row carries `recurrence_id`, the start the rule produced, which
	 * never changes however far the date is moved. Matching on `start_utc` — which
	 * this method did until C6.4a — stops identifying anything the moment a single
	 * occurrence can be moved: the moved row reads as two facts, a date the event
	 * no longer has and a new one, so it is deleted and reinserted and the
	 * organiser's edit disappears on the next unrelated save. A one-off event has
	 * no slot and is still matched on its start.
	 *
	 * See docs/adr/0015-recurrence-identity-and-overrides.md.
	 *
	 * @since 26.0
	 *
	 * @param int                              $event_id    Event id.
	 * @param array<int, array<string, mixed>> $occurrences Desired set; each needs at least start_utc and end_utc.
	 * @return array{inserted: int, updated: int, deleted: int, unchanged: int, cancelled: int}
	 */
	public static function replace_for_event( int $event_id, array $occurrences ): array {
		$result = array(
			'inserted'  => 0,
			'updated'   => 0,
			'deleted'   => 0,
			'unchanged' => 0,
			'cancelled' => 0,
		);

		if ( ! self::table_exists() ) {
			return $result;
		}

		$existing = array();

		foreach ( self::for_event( $event_id ) as $occurrence ) {
			$existing[ self::identity( $occurrence->recurrence_id(), $occurrence->start_utc() ) ] = $occurrence;
		}

		foreach ( $occurrences as $wanted ) {
			$wanted             = self::normalise( $wanted );
			$wanted['event_id'] = $event_id;
			$start              = (string) ( $wanted['start_utc'] ?? '' );

			if ( '' === $start ) {
				continue;
			}

			$key = self::identity( (string) ( $wanted['recurrence_id'] ?? '' ), $start );

			if ( ! isset( $existing[ $key ] ) ) {
				if ( self::insert( $wanted ) > 0 ) {
					++$result['inserted'];
				}

				continue;
			}

			$current = $existing[ $key ];
			unset( $existing[ $key ] );

			$wanted = self::respect_exception( $current, $wanted );

			if ( self::differs( $current, $wanted ) ) {
				if ( self::update( $current->id(), $wanted ) ) {
					++$result['updated'];
				}

				continue;
			}

			++$result['unchanged'];
		}

		// Anything left is a slot the rule no longer produces.
		foreach ( $existing as $stale ) {
			if ( self::must_survive( $stale ) ) {
				if ( OccurrenceStatus::Cancelled !== $stale->status() ) {
					self::update( $stale->id(), array( 'status' => OccurrenceStatus::Cancelled->value ) );
				}

				++$result['cancelled'];

				continue;
			}

			if ( self::delete( $stale->id() ) ) {
				++$result['deleted'];
			}
		}

		return $result;
	}

	/**
	 * The key a row is recognised by.
	 *
	 * Prefixed, because a slot and a start are both `Y-m-d H:i:s` and an
	 * unprefixed map would let a generated row's slot collide with a one-off
	 * row's start time.
	 *
	 * @since 26.0
	 *
	 * @param string $recurrence_id The slot, or '' if the row was not generated.
	 * @param string $start_utc     When it starts.
	 * @return string
	 */
	private static function identity( string $recurrence_id, string $start_utc ): string {
		return '' !== $recurrence_id ? 'slot:' . $recurrence_id : 'start:' . $start_utc;
	}

	/**
	 * Keep an exception's own times and status through a regeneration.
	 *
	 * `is_exception = 1` means the rule no longer owns this row's times or its
	 * status — an organiser has moved this one date, or called it off, and the
	 * rule has nothing to say about it any more. Everything else the rule still
	 * owns: which series the row belongs to, the timezone, whether it is all day.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence           $current The stored row.
	 * @param array<string, mixed> $wanted  What the rule would write.
	 * @return array<string, mixed>
	 */
	private static function respect_exception( Occurrence $current, array $wanted ): array {
		if ( ! $current->is_exception() ) {
			return $wanted;
		}

		foreach ( array( 'start_utc', 'end_utc', 'start_local', 'end_local', 'status', 'is_exception' ) as $own ) {
			unset( $wanted[ $own ] );
		}

		return $wanted;
	}

	/**
	 * Whether a row the rule has stopped producing must be kept anyway.
	 *
	 * Two reasons, and both end in the date being cancelled rather than removed.
	 *
	 * An **exception** is a decision somebody made about this date by hand.
	 * Deleting it because the rule changed throws that away silently.
	 *
	 * A date with **registrations** on it is worse. Deleting it destroys the only
	 * link between a booking and what it was for, and the organiser finds out when
	 * twelve people arrive. Cancelling keeps the record, keeps the attendee list,
	 * and leaves the decision about telling them with the person who made the
	 * change.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The row.
	 * @return bool
	 */
	private static function must_survive( Occurrence $occurrence ): bool {
		if ( $occurrence->is_exception() ) {
			return true;
		}

		/**
		 * Filters whether an occurrence must be kept rather than deleted.
		 *
		 * A filter rather than a direct query, because this class must not know
		 * what a registration is. The registration module answers it when it is
		 * switched on, and nothing answers it when it is off — which is right:
		 * with registration off there are no bookings to protect.
		 *
		 * That is also the dependency direction ADR-0009 requires. Modules depend
		 * on the domain; the domain does not reach into a module that may not be
		 * loaded.
		 *
		 * @since 26.0
		 *
		 * @param bool       $protected  Whether the row must survive.
		 * @param Occurrence $occurrence The row about to be removed.
		 */
		return (bool) apply_filters( 'qevm_occurrence_is_protected', false, $occurrence );
	}

	/**
	 * Tell WordPress that any cached post query may now be wrong.
	 *
	 * WP_Query caches the ids a set of arguments resolved to, keyed partly on
	 * the `posts` group's `last_changed` value. Editing a post bumps that;
	 * writing to this table does not, because core has never heard of it.
	 *
	 * Without this, a date that is cancelled or rescheduled keeps appearing in
	 * listings until something unrelated happens to touch a post — and
	 * `wp qevm occurrence rebuild` would appear to do nothing at all. It is the
	 * derived-data drift ADR-0003 warns about, arriving through the cache
	 * rather than through the table.
	 *
	 * Found by running the same query either side of a status change against a
	 * real WordPress. No stub could have shown it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private static function invalidate_query_cache() {
		wp_cache_set_last_changed( 'posts' );
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

		/*
		 * recurrence_id is the one nullable column, and it must not go through
		 * the loop above. An empty string is not a datetime: MySQL in strict mode
		 * rejects it outright, and without strict mode stores `0000-00-00
		 * 00:00:00`, which is a value that compares equal to nothing, is not
		 * NULL, and cannot be read back as a date. Either way the row stops being
		 * matchable, which is the whole reason the column exists.
		 */
		if ( array_key_exists( 'recurrence_id', $row ) ) {
			$value = is_scalar( $row['recurrence_id'] ) ? trim( (string) $row['recurrence_id'] ) : '';

			$row['recurrence_id'] = '' !== $value ? $value : null;
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
