<?php
/**
 * Every database query the registration module makes.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Install\Installer;

use QuickEventsManager\Domain\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/*
 * This file is the registration module's data access layer, and direct queries
 * are the whole reason it exists. `{prefix}qevm_registrations` is a custom
 * table: there is no core API that reads it, so WP_Query, get_posts() and the
 * meta functions have nothing to offer.
 *
 * The two sniffs disabled here are scoped to this one file on purpose rather
 * than excluded in phpcs.xml.dist. Project-wide they are worth keeping: a
 * $wpdb->get_results() appearing in a renderer or a REST controller is a real
 * finding, and silencing it globally to quiet this file would hide that.
 *
 * NoCaching is disabled rather than answered because the object cache is the
 * wrong tool here. Registration counts back capacity decisions, and a stale
 * count oversells an event — the same failure insert-then-rank exists to
 * prevent. The one query worth memoising, table_exists(), does so below.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; see the note above.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Capacity must not read a stale count; see the note above.

/**
 * The only class that talks to $wpdb about registrations.
 *
 * Keeping the SQL in one place means the escaping story is auditable: every
 * value reaching a query passes through $wpdb->prepare() here, and no caller
 * elsewhere has the opportunity to build a statement by hand.
 *
 * Table names go through the `%i` identifier placeholder that WordPress 6.2
 * added, so even the table name is escaped by $wpdb rather than interpolated.
 * The plugin's floor is 6.5, so it is always available.
 *
 * @since 26.0
 */
final class Repository {

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'registrations' );
	}

	/**
	 * Whether the table has been created.
	 *
	 * The module creates its table when it is switched on, so a site that has
	 * just enabled it mid-request could otherwise query a table that is not
	 * there yet.
	 *
	 * Almost every method below calls this first, so without memoising it a
	 * single registration costs four or five SHOW TABLES round trips. Only a
	 * positive answer is remembered: the table can appear during a request, when
	 * the module is switched on, but nothing drops it again except uninstall,
	 * which does not go on to query it. A test that drops the table and keeps
	 * the same process alive is the one case this would answer wrongly.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function table_exists() {
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
	 * Insert a registration and work out whether it got a place.
	 *
	 * This is the heart of capacity handling, and it deliberately inserts
	 * first and decides afterwards.
	 *
	 * The obvious approach — count the existing registrations, compare against
	 * the capacity, then insert if there is room — has a race between the count
	 * and the insert. Two people submitting at the same moment both read the
	 * same count, both decide there is one place left, and the event oversells.
	 * Wrapping it in a transaction does not close the window either: under the
	 * REPEATABLE READ isolation level MySQL defaults to, both transactions read
	 * from the same snapshot and still agree.
	 *
	 * Inserting first removes the window entirely. Once a row exists, its
	 * auto-increment id fixes its position in the queue for good, so counting
	 * the rows at or before it yields that registration's place in line. Two
	 * simultaneous inserts get different ids, so exactly one of them is
	 * position N. No locks, no transaction, and nothing that can drift out of
	 * step with the rows the way a cached counter would.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data     Column values.
	 * @param int                  $capacity Places available, or 0 for unlimited.
	 * @return Registration|\WP_Error
	 */
	public static function insert_with_capacity( array $data, $capacity ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$row = array(
			'event_id'   => (int) $data['event_id'],
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'code'       => self::generate_code(),
			'status'     => RegistrationStatus::Pending->value,
			'name'       => (string) $data['name'],
			'email'      => (string) $data['email'],
			'phone'      => isset( $data['phone'] ) ? (string) $data['phone'] : '',
			'quantity'   => isset( $data['quantity'] ) ? max( 1, (int) $data['quantity'] ) : 1,
			'fields'     => isset( $data['fields'] ) && ! empty( $data['fields'] ) ? wp_json_encode( $data['fields'] ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$inserted = $wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error(
				'qevm_registration_failed',
				__( 'Your registration could not be saved. Please try again.', 'quick-events-manager' )
			);
		}

		$id = (int) $wpdb->insert_id;

		$status = self::resolve_status( $id, (int) $data['event_id'], $capacity );

		self::update_status( $id, $status );

		$row['id']     = $id;
		$row['status'] = $status->value;

		return new Registration( $row );
	}

	/**
	 * Decide whether a freshly inserted row has a place or is on the waitlist.
	 *
	 * Counts the places taken by every occupying registration up to and
	 * including this one, so the answer depends only on rows that already
	 * exist and on this row's own id.
	 *
	 * @since 26.0
	 *
	 * @param int $id       Registration id.
	 * @param int $event_id Event id.
	 * @param int $capacity Places available, 0 for unlimited.
	 * @return RegistrationStatus
	 */
	private static function resolve_status( $id, $event_id, $capacity ) {
		global $wpdb;

		if ( $capacity <= 0 ) {
			return RegistrationStatus::Confirmed;
		}

		$statuses = RegistrationStatus::occupying_values();

		// Two fixed placeholders: the occupying statuses are a constant, not user input.
		$taken = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( quantity ), 0 ) FROM %i
				 WHERE event_id = %d AND id <= %d AND status IN ( %s, %s )',
				self::table(),
				$event_id,
				$id,
				$statuses[0],
				$statuses[1]
			)
		);

		return $taken <= $capacity
			? RegistrationStatus::Confirmed
			: RegistrationStatus::Waitlisted;
	}

	/**
	 * Places taken for an event.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return int
	 */
	public static function count_taken( $event_id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$statuses = RegistrationStatus::occupying_values();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( quantity ), 0 ) FROM %i
				 WHERE event_id = %d AND status IN ( %s, %s )',
				self::table(),
				$event_id,
				$statuses[0],
				$statuses[1]
			)
		);
	}

	/**
	 * Whether an address is already registered for an event.
	 *
	 * Cancelled registrations do not count, so someone who cancelled can sign
	 * up again.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $email    Email address.
	 * @return bool
	 */
	public static function email_is_registered( $event_id, $email ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE event_id = %d AND email = %s AND status != %s',
				self::table(),
				$event_id,
				$email,
				RegistrationStatus::Cancelled->value
			)
		);
	}

	/**
	 * Find a registration by id.
	 *
	 * @since 26.0
	 *
	 * @param int $id Registration id.
	 * @return Registration|null
	 */
	public static function find( $id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ),
			ARRAY_A
		);

		return null !== $row ? new Registration( $row ) : null;
	}

	/**
	 * Find a registration by its public code.
	 *
	 * @since 26.0
	 *
	 * @param string $code Registration code.
	 * @return Registration|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE code = %s', self::table(), (string) $code ),
			ARRAY_A
		);

		return null !== $row ? new Registration( $row ) : null;
	}

	/**
	 * List registrations for an event.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string, mixed> $args     Query arguments: status, search, per_page, page, orderby, order.
	 * @return Registration[]
	 */
	public static function for_event( $event_id, array $args = array() ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'search'   => '',
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$filter   = self::build_filter( $event_id, $args );
		$clause   = $filter['clause'];
		$orderby  = self::safe_orderby( $args['orderby'] );
		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$params = array_merge(
			array( self::table() ),
			$filter['params'],
			array( $orderby, $per_page, $offset )
		);

		/*
		 * $clause carries the placeholders its values are bound to, so it has to
		 * be part of the query string before prepare() sees it. It is assembled
		 * from string literals in build_filter() and never from caller input.
		 *
		 * $order is the result of a comparison, so it is the literal 'ASC' or
		 * the literal 'DESC' and cannot be anything else. It is not a %i because
		 * a sort direction is a keyword, not an identifier, and %i would
		 * backtick it into a column name.
		 *
		 * The placeholder count cannot be checked statically either: $params is
		 * assembled at runtime and passed as one array, which wpdb::prepare()
		 * unwraps itself when it is the only argument.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- See above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$clause} ORDER BY %i {$order} LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map(
			static function ( $row ) {
				return new Registration( $row );
			},
			(array) $rows
		);
	}

	/**
	 * Count registrations matching a filter.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string, mixed> $args     Same status/search arguments as for_event().
	 * @return int
	 */
	public static function count_for_event( $event_id, array $args = array() ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$filter = self::build_filter( $event_id, $args );
		$clause = $filter['clause'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is built from literals in build_filter(); its values are bound through the params below.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE {$clause}",
				array_merge( array( self::table() ), $filter['params'] )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Build the shared WHERE clause for the event, status and search filters.
	 *
	 * The list and its count have to agree exactly: twenty rows displayed above
	 * a count that says nineteen is a bug report nobody can reproduce. They had
	 * already diverged — for_event() tested the status with `'' !== $status`
	 * and count_for_event() with `! empty( $status )`, which disagree on the
	 * string '0'. Building the clause in one place makes that impossible
	 * rather than merely unlikely.
	 *
	 * The returned clause contains placeholders, not values. Every value is in
	 * the params array and is bound by $wpdb->prepare() at the call site.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string, mixed> $args     Query arguments: status, search.
	 * @return array{clause: string, params: array<int, mixed>} SQL fragment and its bindings, in order.
	 */
	private static function build_filter( $event_id, array $args ) {
		global $wpdb;

		$where  = array( 'event_id = %d' );
		$params = array( (int) $event_id );

		$status = isset( $args['status'] ) ? (string) $args['status'] : '';

		if ( '' !== $status && in_array( $status, RegistrationStatus::values(), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$search = isset( $args['search'] ) ? (string) $args['search'] : '';

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( name LIKE %s OR email LIKE %s OR code LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array(
			'clause' => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * Change a registration's status.
	 *
	 * @since 26.0
	 *
	 * @param int                $id     Registration id.
	 * @param RegistrationStatus $status New status.
	 * @return bool
	 */
	public static function update_status( $id, RegistrationStatus $status ) {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table(),
			array(
				'status'     => $status->value,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a registration outright.
	 *
	 * Used by the privacy eraser and by explicit admin deletion, never as part
	 * of normal cancellation.
	 *
	 * @since 26.0
	 *
	 * @param int $id Registration id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete every registration for an event.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return int Rows removed.
	 */
	public static function delete_for_event( $event_id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->delete( self::table(), array( 'event_id' => (int) $event_id ), array( '%d' ) );
	}

	/**
	 * Every registration made with a given email address, across all events.
	 *
	 * Backs the privacy exporter and eraser.
	 *
	 * @since 26.0
	 *
	 * @param string $email Email address.
	 * @return Registration[]
	 */
	public static function find_by_email( $email ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id ASC', self::table(), (string) $email ),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return new Registration( $row );
			},
			(array) $rows
		);
	}

	/**
	 * Restrict ordering to real columns.
	 *
	 * @since 26.0
	 *
	 * @param string $orderby Requested column.
	 * @return string A column name that is safe to interpolate.
	 */
	private static function safe_orderby( $orderby ) {
		$allowed = array( 'id', 'name', 'email', 'status', 'created_at', 'quantity' );

		return in_array( $orderby, $allowed, true ) ? $orderby : 'created_at';
	}

	/**
	 * Generate a unique public reference code.
	 *
	 * Ambiguous characters are left out so the code survives being read aloud
	 * or copied off a screen at a check-in desk.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function generate_code() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$length   = strlen( $alphabet );

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$code = 'QEVM-';

			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $alphabet[ wp_rand( 0, $length - 1 ) ];
			}

			if ( null === self::find_by_code( $code ) ) {
				return $code;
			}
		}

		// Ten collisions against a 32^8 space means something is very wrong; fall back to something certainly unique.
		return 'QEVM-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 12 ) );
	}
}
