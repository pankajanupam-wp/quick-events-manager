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
			'event_id'       => (int) $data['event_id'],
			'occurrence_id'  => isset( $data['occurrence_id'] ) ? (int) $data['occurrence_id'] : 0,
			'ticket_type_id' => isset( $data['ticket_type_id'] ) ? (int) $data['ticket_type_id'] : 0,
			'user_id'        => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'code'           => self::generate_code(),
			'status'         => RegistrationStatus::Pending->value,
			'booker_name'    => (string) $data['name'],
			'booker_email'   => (string) $data['email'],
			'booker_phone'   => isset( $data['phone'] ) ? (string) $data['phone'] : '',
			'quantity'       => isset( $data['quantity'] ) ? max( 1, (int) $data['quantity'] ) : 1,
			'fields'         => isset( $data['fields'] ) && ! empty( $data['fields'] ) ? wp_json_encode( $data['fields'] ) : null,
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		/*
		 * Consent is written only when it was actually given. An empty version
		 * with a timestamp beside it would read as "consented to nothing at a
		 * specific moment", which is worse than no record at all.
		 */
		if ( isset( $data['consent_version'] ) && '' !== (string) $data['consent_version'] ) {
			$row['consent_version'] = (string) $data['consent_version'];
			$row['consent_at']      = $now;
		}

		$inserted = $wpdb->insert( self::table(), $row, self::formats( $row ) );

		if ( ! $inserted ) {
			return new \WP_Error(
				'qevm_registration_failed',
				__( 'Your registration could not be saved. Please try again.', 'quick-events-manager' )
			);
		}

		$id = (int) $wpdb->insert_id;

		$status = self::resolve_status(
			$id,
			(int) $data['event_id'],
			$capacity,
			(int) $row['occurrence_id'],
			(int) $row['ticket_type_id'],
			isset( $data['ticket_capacity'] ) ? (int) $data['ticket_capacity'] : 0
		);

		/*
		 * set_status(), not update_status(). This is a row learning its initial
		 * status, not a booking being changed: nothing has happened to it yet,
		 * it has no attendee rows, and there is nothing to cascade to.
		 */
		self::set_status( $id, $status );

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
	 * A booking that names a date is ranked **within that date**. Twenty places
	 * on a weekly class means twenty places each week, which is what an
	 * organiser means by it and what a person booking the 3rd of June expects
	 * — counting the whole series against one capacity would sell out a term in
	 * the first fortnight.
	 *
	 * A booking with no date is ranked across the event, which is every booking
	 * on an event that has only one date to be on.
	 *
	 * @since 26.0
	 *
	 * @param int $id              Registration id.
	 * @param int $event_id        Event id.
	 * @param int $capacity        Places available on the event or date, 0 for unlimited.
	 * @param int $occurrence_id   Date booked, or 0.
	 * @param int $ticket_type_id  Kind of place booked, or 0.
	 * @param int $ticket_capacity Places of that kind, 0 for as many as the event allows.
	 * @return RegistrationStatus
	 */
	private static function resolve_status( $id, $event_id, $capacity, $occurrence_id = 0, $ticket_type_id = 0, $ticket_capacity = 0 ) {
		/*
		 * Two limits, and a booking has to fit both. Twelve places on the
		 * evening and four of them reserved for members means the fifth member
		 * waits even though the room is half empty, and the thirteenth person
		 * waits whichever ticket they hold.
		 *
		 * Each is the same question asked of a narrower set of rows, and each is
		 * asked the same way it was before ticket types existed: count the
		 * places taken by rows at or before this one. That is what preserves the
		 * concurrency guarantee — the row's own auto-increment id still fixes
		 * its position in every queue it is in, so two simultaneous bookings get
		 * different ids and exactly one of them is position N of each.
		 */
		$limits = array();

		if ( $capacity > 0 ) {
			$limits[] = $occurrence_id > 0
				? array( 'occurrence_id', $occurrence_id, $capacity )
				: array( 'event_id', $event_id, $capacity );
		}

		if ( $ticket_capacity > 0 && $ticket_type_id > 0 ) {
			$limits[] = array( 'ticket_type_id', $ticket_type_id, $ticket_capacity );
		}

		foreach ( $limits as $limit ) {
			list( $column, $value, $allowed ) = $limit;

			if ( self::taken_up_to( $id, $column, (int) $value, $occurrence_id ) > $allowed ) {
				return RegistrationStatus::Waitlisted;
			}
		}

		return RegistrationStatus::Confirmed;
	}

	/**
	 * Places taken by this row and everything queued before it.
	 *
	 * The one query the capacity decision rests on. `id <= %d` is the whole
	 * mechanism: a row cannot change its own id, so its position in the queue is
	 * fixed the moment it exists and no two rows share it.
	 *
	 * When a ticket type is being counted on an event that has dates, the count
	 * is narrowed to the date as well — twenty member places a week is not
	 * twenty member places a term.
	 *
	 * @since 26.0
	 *
	 * @param int    $id            Registration id.
	 * @param string $column        Column to scope by; one of a fixed set, never user input.
	 * @param int    $value         Value it must equal.
	 * @param int    $occurrence_id Narrow to this date as well, or 0.
	 * @return int
	 */
	private static function taken_up_to( $id, $column, $value, $occurrence_id = 0 ) {
		global $wpdb;

		$statuses = RegistrationStatus::occupying_values();

		/*
		 * $column is chosen from a literal list a few lines above and never
		 * comes from a request. Interpolating an identifier is the one thing
		 * prepare() cannot do for a column name, and the alternative — three
		 * near-identical queries — is how one of them drifts.
		 */
		$narrow = 'ticket_type_id' === $column && $occurrence_id > 0 ? ' AND occurrence_id = %d' : '';

		$params = array( self::table(), $value, $id, $statuses[0], $statuses[1] );

		if ( '' !== $narrow ) {
			$params[] = $occurrence_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $column and $narrow are literals chosen above; every value goes through prepare(), in the array form the sniff cannot count.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( quantity ), 0 ) FROM %i
				 WHERE {$column} = %d AND id <= %d AND status IN ( %s, %s ){$narrow}",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Places taken, for an event or for one of its dates.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id       Event id.
	 * @param int $occurrence_id  Count only this date, or 0 for the whole event.
	 * @param int $ticket_type_id Count only this kind of place, or 0 for all of them.
	 * @return int
	 */
	public static function count_taken( $event_id, $occurrence_id = 0, $ticket_type_id = 0 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$statuses       = RegistrationStatus::occupying_values();
		$occurrence_id  = (int) $occurrence_id;
		$ticket_type_id = (int) $ticket_type_id;

		if ( $ticket_type_id > 0 ) {
			if ( $occurrence_id > 0 ) {
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COALESCE( SUM( quantity ), 0 ) FROM %i
						 WHERE ticket_type_id = %d AND occurrence_id = %d AND status IN ( %s, %s )',
						self::table(),
						$ticket_type_id,
						$occurrence_id,
						$statuses[0],
						$statuses[1]
					)
				);
			}

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE( SUM( quantity ), 0 ) FROM %i
					 WHERE ticket_type_id = %d AND status IN ( %s, %s )',
					self::table(),
					$ticket_type_id,
					$statuses[0],
					$statuses[1]
				)
			);
		}

		if ( $occurrence_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE( SUM( quantity ), 0 ) FROM %i
					 WHERE occurrence_id = %d AND status IN ( %s, %s )',
					self::table(),
					$occurrence_id,
					$statuses[0],
					$statuses[1]
				)
			);
		}

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
	 * Whether an address is already registered for an event, or for one date.
	 *
	 * Cancelled registrations do not count, so someone who cancelled can sign
	 * up again.
	 *
	 * **Scoped to the date when there is one**, and that is the point of the
	 * argument rather than a convenience. Somebody who comes to the Tuesday
	 * class every week is booking the same event over and over; refusing the
	 * second week as a duplicate would make a weekly class bookable exactly
	 * once, which is worse than useless.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id      Event id.
	 * @param string $email         Email address.
	 * @param int    $occurrence_id Date booked, or 0 for the whole event.
	 * @return bool
	 */
	public static function email_is_registered( $event_id, $email, $occurrence_id = 0 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$occurrence_id = (int) $occurrence_id;

		if ( $occurrence_id > 0 ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i
					 WHERE occurrence_id = %d AND booker_email = %s AND status != %s',
					self::table(),
					$occurrence_id,
					$email,
					RegistrationStatus::Cancelled->value
				)
			);
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE event_id = %d AND booker_email = %s AND status != %s',
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
	 * Find the booking an order is paying for.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return Registration|null
	 */
	public static function find_by_order( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;

		if ( ! self::table_exists() || $order_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC LIMIT 1', self::table(), $order_id ),
			ARRAY_A
		);

		return null !== $row ? new Registration( $row ) : null;
	}

	/**
	 * Record which order is paying for a booking.
	 *
	 * Narrow on purpose. There is no general `update()` on this repository and
	 * there should not be: a booking's status goes through `update_status()`
	 * so that the waiting list hears about it, and its places are fixed at
	 * insert time so that ranking still means something. This writes one column
	 * that is nobody else's business.
	 *
	 * @since 26.0
	 *
	 * @param int $id       Registration id.
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public static function set_order( $id, $order_id ) {
		global $wpdb;

		$id = (int) $id;

		if ( ! self::table_exists() || $id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			array(
				'order_id'   => (int) $order_id,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map(
			static function ( $row ) {
				return new Registration( $row );
			},
			(array) $rows
		);
	}

	/**
	 * How many bookings are attached to one date.
	 *
	 * Counts every status including cancelled, because the question this answers
	 * is "would deleting this date destroy a record", and a cancelled booking is
	 * still a record of somebody having held a place.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id Occurrence id.
	 * @return int
	 */
	public static function count_for_occurrence( $occurrence_id ) {
		global $wpdb;

		$occurrence_id = (int) $occurrence_id;

		if ( $occurrence_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE occurrence_id = %d',
				self::table(),
				$occurrence_id
			)
		);
	}

	/**
	 * Point the bookings for a set of dates at a different event.
	 *
	 * A booking stores both the date it is for and the event that date belongs
	 * to. The second is redundant until it disagrees with the first, which is
	 * exactly what a "this and following" split causes: the occurrence changes
	 * hands and the booking is left naming the event it used to be part of.
	 * Nothing shows the disagreement, because each table is still internally
	 * consistent — the attendee screen simply stops listing people who are
	 * still coming.
	 *
	 * Bookings not attached to any date are left alone. They were made for the
	 * series as it stood, and moving them would hand somebody's place to a half
	 * of the series they never chose.
	 *
	 * @since 26.0
	 *
	 * @param int[] $occurrence_ids Dates that changed hands.
	 * @param int   $event_id       Event they now belong to.
	 * @return int Rows corrected.
	 */
	public static function repoint_to_event( array $occurrence_ids, $event_id ) {
		global $wpdb;

		$event_id       = (int) $event_id;
		$occurrence_ids = array_values(
			array_filter( array_map( 'intval', $occurrence_ids ), static fn( $id ) => $id > 0 )
		);

		if ( array() === $occurrence_ids || $event_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $occurrence_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated list of %d and every value goes through prepare(); the sniff counts only the placeholders it can see in the literal.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET event_id = %d WHERE occurrence_id IN ( {$placeholders} )",
				array_merge( array( self::table(), $event_id ), $occurrence_ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * How many separate people an event can be emailed to.
	 *
	 * Counted by distinct address rather than by booking, because somebody who
	 * booked twice is one person and should be told once. The number this
	 * returns is what a "email 137 people" button says, so it has to be
	 * produced by the same rule the send uses — which is why both this and
	 * recipients_for_event() share audience_clause() rather than each writing
	 * their own WHERE.
	 *
	 * `LOWER()` rather than trusting the column collation. The default on this
	 * table folds case already, but a site that created it under a binary
	 * collation would otherwise send twice to the same person spelled two ways.
	 *
	 * @since 26.0
	 *
	 * @param int      $event_id Event id.
	 * @param string[] $statuses Statuses to include.
	 * @return int
	 */
	public static function count_recipients( $event_id, array $statuses ) {
		global $wpdb;

		$audience = self::audience_clause( $event_id, $statuses );

		if ( array() === $audience['params'] || ! self::table_exists() ) {
			return 0;
		}

		$clause = $audience['clause'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is built from literals in audience_clause(); its values are bound below.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT LOWER( booker_email ) ) FROM %i WHERE {$clause}",
				array_merge( array( self::table() ), $audience['params'] )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * One booking per person to email, oldest first.
	 *
	 * The earliest booking wins the address, which is what makes paging through
	 * this stable: `MIN(id)` is fixed for a given set of rows, so page two
	 * cannot repeat somebody page one already had.
	 *
	 * @since 26.0
	 *
	 * @param int      $event_id Event id.
	 * @param string[] $statuses Statuses to include.
	 * @param int      $limit    How many to take.
	 * @param int      $offset   How many to skip.
	 * @return Registration[]
	 */
	public static function recipients_for_event( $event_id, array $statuses, $limit, $offset = 0 ) {
		global $wpdb;

		$audience = self::audience_clause( $event_id, $statuses );

		if ( array() === $audience['params'] || ! self::table_exists() ) {
			return array();
		}

		$clause = $audience['clause'];
		$table  = self::table();

		$params = array_merge(
			array( $table, $table ),
			$audience['params'],
			array( max( 1, (int) $limit ), max( 0, (int) $offset ) )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $clause is literals from audience_clause(); $params is assembled at runtime and unwrapped by prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.* FROM %i r
				 JOIN (
					SELECT MIN( id ) AS id
					FROM %i
					WHERE {$clause}
					GROUP BY LOWER( booker_email )
				 ) first ON first.id = r.id
				 ORDER BY r.id ASC
				 LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map(
			static function ( $row ) {
				return new Registration( $row );
			},
			(array) $rows
		);
	}

	/**
	 * The WHERE clause behind both recipient queries.
	 *
	 * Bookings with no address are excluded here rather than skipped later, so
	 * the count and the send agree about them. A manually entered booking can
	 * have an empty email — the organiser took it over the phone — and counting
	 * somebody the queue will never accept produces a "sent to 40 people"
	 * notice for 39 emails.
	 *
	 * An empty status list returns empty params, which every caller reads as
	 * "no audience" and stops. That is deliberate: defaulting an unrecognised
	 * audience to *everybody* is the failure mode worth designing out.
	 *
	 * @since 26.0
	 *
	 * @param int      $event_id Event id.
	 * @param string[] $statuses Statuses to include.
	 * @return array{clause: string, params: array<int, mixed>}
	 */
	private static function audience_clause( $event_id, array $statuses ) {
		$valid = array_values(
			array_intersect(
				array_map( 'strval', $statuses ),
				RegistrationStatus::values()
			)
		);

		if ( array() === $valid || (int) $event_id <= 0 ) {
			return array(
				'clause' => '',
				'params' => array(),
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $valid ), '%s' ) );

		return array(
			'clause' => "event_id = %d AND booker_email <> '' AND status IN ( {$placeholders} )",
			'params' => array_merge( array( (int) $event_id ), $valid ),
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

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

		$occurrence_id = isset( $args['occurrence_id'] ) ? (int) $args['occurrence_id'] : 0;

		if ( $occurrence_id > 0 ) {
			$where[]  = 'occurrence_id = %d';
			$params[] = $occurrence_id;
		}

		$ticket_type_id = isset( $args['ticket_type_id'] ) ? (int) $args['ticket_type_id'] : 0;

		if ( $ticket_type_id > 0 ) {
			$where[]  = 'ticket_type_id = %d';
			$params[] = $ticket_type_id;
		}

		$search = isset( $args['search'] ) ? (string) $args['search'] : '';

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( booker_name LIKE %s OR booker_email LIKE %s OR code LIKE %s )';
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
	 * Change a registration's status, and carry the change to the people on it.
	 *
	 * Cancelling a booking has to reach its attendee rows, or a cancelled
	 * booking still admits three colleagues at the door — each of them holding
	 * a ticket code that scans as active. Reinstating one has to put them back,
	 * or an administrator who cancels by mistake cannot undo it.
	 *
	 * The current status is read first so the cascade fires on the transition
	 * rather than on every save. That costs one SELECT on a path used by an
	 * administrator changing one row at a time, and it is what stops a later
	 * per-attendee cancellation from being silently undone by an unrelated edit
	 * to the booking.
	 *
	 * @since 26.0
	 *
	 * @param int                $id     Registration id.
	 * @param RegistrationStatus $status New status.
	 * @return bool
	 */
	public static function update_status( $id, RegistrationStatus $status ) {
		$id      = (int) $id;
		$current = self::find( $id );

		if ( null === $current ) {
			return false;
		}

		$previous = $current->status();

		/*
		 * A status that is already what it is being set to is a success with
		 * nothing to do, not a failure. $wpdb->update() returns 0 for a row it
		 * matched but did not change, and returning false on that would make
		 * "cancel this booking" report an error to anybody who clicked the
		 * link twice — the one thing a person is most likely to do when they
		 * are not sure the first click worked.
		 */
		if ( $previous === $status ) {
			return true;
		}

		if ( ! self::set_status( $id, $status ) ) {
			return false;
		}

		$was_cancelled = RegistrationStatus::Cancelled === $previous;
		$is_cancelled  = RegistrationStatus::Cancelled === $status;

		if ( $is_cancelled && ! $was_cancelled ) {
			AttendeeRepository::cancel_for_registration( $id );
		} elseif ( ! $is_cancelled && $was_cancelled ) {
			AttendeeRepository::reinstate_for_registration( $id );
		}

		/**
		 * Fires when a booking's status actually changes.
		 *
		 * Fired here rather than at the call site. It used to be fired by the
		 * attendees screen, which was correct for exactly as long as the admin
		 * screen was the only way to change a status — the moment a second
		 * route existed (the cancellation link), a booking could be cancelled
		 * without anything hearing about it. Waitlist promotion listens to
		 * this, so a miss is a place that stays empty with people queueing for
		 * it.
		 *
		 * @since 26.0
		 *
		 * @param int                $id       Registration id.
		 * @param RegistrationStatus $status   Status it now has.
		 * @param RegistrationStatus $previous Status it had before.
		 */
		do_action( 'qevm_registration_status_changed', $id, $status, $previous );

		return true;
	}

	/**
	 * Write the status column and the timestamps that go with it.
	 *
	 * `cancelled_at` is maintained here because it is not free-standing
	 * information — it is a fact about the status column, and any other writer
	 * would eventually disagree with it. It is cleared on the way back out so
	 * that a booking reinstated by mistake does not keep a cancellation date
	 * it no longer has.
	 *
	 * @since 26.0
	 *
	 * @param int                $id     Registration id.
	 * @param RegistrationStatus $status New status.
	 * @return bool
	 */
	private static function set_status( $id, RegistrationStatus $status ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$data    = array(
			'status'       => $status->value,
			'updated_at'   => $now,
			'cancelled_at' => RegistrationStatus::Cancelled === $status ? $now : null,
		);
		$formats = array( '%s', '%s', '%s' );

		return false !== $wpdb->update(
			self::table(),
			$data,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Delete a registration outright.
	 *
	 * Used by the privacy eraser and by explicit admin deletion, never as part
	 * of normal cancellation.
	 *
	 * The people on the booking go with it. There is no foreign key to do this
	 * — dbDelta does not create them, and WordPress does not assume InnoDB — so
	 * the parent owns the cascade, and it owns it here rather than at each call
	 * site so that no future caller can forget. An erasure that removed the
	 * booking and left the guests' names behind would be a data protection
	 * failure reported as a bug about a table nobody was looking at.
	 *
	 * The children go first. If the second delete fails the booking survives
	 * with an incomplete roster, which is repairable; the other order leaves
	 * rows belonging to nothing, which is not.
	 *
	 * @since 26.0
	 *
	 * @param int $id Registration id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		AttendeeRepository::delete_for_registration( $id );

		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete every registration for an event, and everyone on them.
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

		$event_id = (int) $event_id;

		AttendeeRepository::delete_for_event( $event_id );

		return (int) $wpdb->delete( self::table(), array( 'event_id' => $event_id ), array( '%d' ) );
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
			$wpdb->prepare( 'SELECT * FROM %i WHERE booker_email = %s ORDER BY id ASC', self::table(), (string) $email ),
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
	 * $wpdb format specifiers matching a row's columns, in order.
	 *
	 * Derived rather than written out, because the previous positional list had
	 * to be counted by hand against the row above it and would have been wrong
	 * the moment a column was inserted anywhere but the end.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Row about to be written.
	 * @return string[]
	 */
	private static function formats( array $row ) {
		$integers = array( 'event_id', 'occurrence_id', 'order_id', 'user_id', 'quantity' );

		return array_map(
			static fn( string $column ): string => in_array( $column, $integers, true ) ? '%d' : '%s',
			array_keys( $row )
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
		$allowed = array( 'id', 'booker_name', 'booker_email', 'status', 'created_at', 'quantity' );

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
