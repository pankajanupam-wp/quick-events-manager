<?php
/**
 * Every database query about ticket types.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tickets;

use QuickEventsManager\Domain\TicketTypeStatus;
use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

/*
 * A custom table, so direct queries are the only way to read it. Both sniffs are
 * disabled for this file rather than project-wide, so a $wpdb call appearing in
 * a renderer or a REST controller is still a finding everywhere else.
 *
 * NoCaching is disabled rather than answered because these rows back capacity
 * decisions from C7.2 onward, and a stale count oversells a ticket type — the
 * same failure insert-then-rank exists to prevent.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; see the note above.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Capacity must not read a stale count; see the note above.

/**
 * The only class that talks to $wpdb about ticket types.
 *
 * **A type belongs to an event, not to a date.** A weekly class has the same
 * "Member" and "Drop-in" tickets every week, and giving each date its own copies
 * would mean editing twelve identical things to change a price. How a per-type
 * capacity meets the per-date capacity from C6.6 is C7.2's question, and nothing
 * in this table forecloses either answer.
 *
 * **A type in use is archived, never deleted.** Somebody holds a ticket of that
 * type: the attendee list has to keep saying which one, and an export written
 * next year has to keep meaning something. Deleting is allowed only while
 * nothing points at it, and that is checked rather than trusted.
 *
 * @since 26.0
 */
final class TicketTypeRepository {

	/**
	 * Longest a name may be, matching the column.
	 */
	const MAX_NAME = 190;

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 */
	public static function table(): string {
		return Installer::table( 'ticket_types' );
	}

	/**
	 * Whether the table has been created.
	 *
	 * Memoised on a positive answer only, like every other repository here: the
	 * table can appear during a request when the module is switched on, and
	 * nothing drops it again except uninstall.
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
	 * The types for an event, in the order they are shown.
	 *
	 * @since 26.0
	 *
	 * @param int  $event_id        Event id.
	 * @param bool $sellable_only   Leave out archived types.
	 * @return TicketType[]
	 */
	public static function for_event( int $event_id, bool $sellable_only = false ): array {
		global $wpdb;

		if ( $event_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		if ( $sellable_only ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE event_id = %d AND status = %s ORDER BY sort_order ASC, id ASC',
					self::table(),
					$event_id,
					TicketTypeStatus::Active->value
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE event_id = %d ORDER BY sort_order ASC, id ASC',
					self::table(),
					$event_id
				),
				ARRAY_A
			);
		}

		return array_map(
			static fn( array $row ): TicketType => new TicketType( $row ),
			(array) $rows
		);
	}

	/**
	 * One type by id.
	 *
	 * @since 26.0
	 *
	 * @param int $id Type id.
	 */
	public static function find( int $id ): ?TicketType {
		global $wpdb;

		if ( $id <= 0 || ! self::table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return null !== $row ? new TicketType( $row ) : null;
	}

	/**
	 * Add a type.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string, mixed> $data     Column values.
	 * @return int New id, or 0.
	 */
	public static function insert( int $event_id, array $data ): int {
		global $wpdb;

		if ( $event_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$row = self::normalise( $data );

		if ( '' === $row['name'] ) {
			return 0;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$row['event_id']   = $event_id;
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		if ( ! isset( $data['sort_order'] ) ) {
			$row['sort_order'] = self::next_sort_order( $event_id );
		}

		return $wpdb->insert( self::table(), $row, self::formats( $row ) ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Change a type.
	 *
	 * @since 26.0
	 *
	 * @param int                  $id   Type id.
	 * @param array<string, mixed> $data Column values to change.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$row = self::normalise( $data, array_keys( $data ) );

		if ( array() === $row ) {
			return false;
		}

		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update( self::table(), $row, array( 'id' => $id ), self::formats( $row ), array( '%d' ) );
	}

	/**
	 * Stop offering a type, keeping every ticket already sold.
	 *
	 * @since 26.0
	 *
	 * @param int $id Type id.
	 */
	public static function archive( int $id ): bool {
		return self::update( $id, array( 'status' => TicketTypeStatus::Archived->value ) );
	}

	/**
	 * Offer a type again.
	 *
	 * @since 26.0
	 *
	 * @param int $id Type id.
	 */
	public static function restore( int $id ): bool {
		return self::update( $id, array( 'status' => TicketTypeStatus::Active->value ) );
	}

	/**
	 * Remove a type nobody holds a ticket of.
	 *
	 * Refuses when anything points at it, and archives instead. Deleting a type
	 * that somebody bought leaves an attendee row naming a ticket that no longer
	 * exists — the attendee screen then shows a blank where the type was, and
	 * the export is wrong in a way nobody can reconstruct.
	 *
	 * @since 26.0
	 *
	 * @param int $id Type id.
	 * @return bool Whether the row was removed. False means it was archived.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}

		if ( self::is_in_use( $id ) ) {
			self::archive( $id );

			return false;
		}

		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Remove every type for an event.
	 *
	 * What uninstall and event deletion call. No archiving here: the event
	 * itself is going, so there is nothing left for a type to belong to.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return int Rows removed.
	 */
	public static function delete_for_event( int $event_id ): int {
		global $wpdb;

		if ( $event_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->delete( self::table(), array( 'event_id' => $event_id ), array( '%d' ) );
	}

	/**
	 * Whether anybody holds a ticket of this type.
	 *
	 * Asked of the attendees table, which is where `ticket_type_id` lives — one
	 * row per person, which is what a ticket is. The column has existed since
	 * stage 1 for exactly this.
	 *
	 * The registration module owns that table and may be switched off, in which
	 * case nothing holds a ticket and the answer is no.
	 *
	 * @since 26.0
	 *
	 * @param int $id Type id.
	 */
	public static function is_in_use( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 || ! \QuickEventsManager\Registration\AttendeeRepository::table_exists() ) {
			return false;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE ticket_type_id = %d',
				\QuickEventsManager\Registration\AttendeeRepository::table(),
				$id
			)
		);
	}

	/**
	 * The next free sort order for an event.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 */
	private static function next_sort_order( int $event_id ): int {
		global $wpdb;

		$highest = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT MAX(sort_order) FROM %i WHERE event_id = %d', self::table(), $event_id )
		);

		return $highest + 1;
	}

	/**
	 * Clean the values a caller supplied.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @param string[]|null        $only Limit to these keys, for an update.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $data, ?array $only = null ): array {
		$row = array();

		if ( null === $only || in_array( 'name', $only, true ) ) {
			$row['name'] = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, self::MAX_NAME );
		}

		if ( null === $only || in_array( 'description', $only, true ) ) {
			$row['description'] = sanitize_textarea_field( (string) ( $data['description'] ?? '' ) );
		}

		if ( null === $only || in_array( 'price_minor', $only, true ) ) {
			$row['price_minor'] = max( 0, (int) ( $data['price_minor'] ?? 0 ) );
		}

		if ( null === $only || in_array( 'capacity', $only, true ) ) {
			$row['capacity'] = max( 0, (int) ( $data['capacity'] ?? 0 ) );
		}

		if ( null === $only || in_array( 'sort_order', $only, true ) ) {
			$row['sort_order'] = max( 0, (int) ( $data['sort_order'] ?? 0 ) );
		}

		if ( null === $only || in_array( 'occurrence_id', $only, true ) ) {
			$row['occurrence_id'] = max( 0, (int) ( $data['occurrence_id'] ?? 0 ) );
		}

		if ( null === $only || in_array( 'currency', $only, true ) ) {
			/*
			 * Empty means the site's own currency, which is every row today.
			 * Stage 9 decides whether a type may ever say otherwise; storing it
			 * here keeps that decision open without taking it.
			 */
			$row['currency'] = strtoupper( substr( sanitize_text_field( (string) ( $data['currency'] ?? '' ) ), 0, 3 ) );
		}

		if ( null === $only || in_array( 'status', $only, true ) ) {
			$row['status'] = TicketTypeStatus::coerce( (string) ( $data['status'] ?? '' ) )->value;
		}

		foreach ( array( 'sale_starts_utc', 'sale_ends_utc' ) as $window ) {
			if ( null !== $only && ! in_array( $window, $only, true ) ) {
				continue;
			}

			/*
			 * NULL rather than an empty string, for the reason recurrence_id
			 * has the same treatment: '' is not a datetime. MySQL in strict
			 * mode rejects it and without strict mode stores a zero date, which
			 * compares equal to nothing and is not NULL either.
			 */
			$value = isset( $data[ $window ] ) && is_scalar( $data[ $window ] ) ? trim( (string) $data[ $window ] ) : '';

			$row[ $window ] = '' !== $value ? $value : null;
		}

		return $row;
	}

	/**
	 * Placeholder formats for the columns being written.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Column values.
	 * @return string[]
	 */
	private static function formats( array $row ): array {
		$integers = array( 'event_id', 'occurrence_id', 'price_minor', 'capacity', 'sort_order' );
		$formats  = array();

		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, $integers, true ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * The table definition.
	 *
	 * As specified in docs/database.md, which described this table two stages
	 * before it was built. Four of its columns are written now and read later —
	 * `occurrence_id` when a single date needs its own tickets, `currency` and
	 * the two per-order limits in stages 7 and 9 — because a column costs
	 * nothing today and altering a table that holds a row for every ticket ever
	 * sold costs a great deal. The same reasoning put `ticket_type_id` on the
	 * attendees table in stage 1, where it sat unused until now.
	 *
	 * `price_minor` is unsigned: a negative price is not a discount, it is a
	 * mistake, and a discount is a different feature with its own record.
	 *
	 * `occurrence_id = 0` means the type applies to every date of the event,
	 * which is what every type created today is.
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
			event_id bigint(20) unsigned NOT NULL,
			occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL,
			description text DEFAULT NULL,
			price_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			capacity int(10) unsigned NOT NULL DEFAULT 0,
			sale_starts_utc datetime DEFAULT NULL,
			sale_ends_utc datetime DEFAULT NULL,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event (event_id),
			KEY occ_status (occurrence_id, status)
		) {$collate};";
	}
}
