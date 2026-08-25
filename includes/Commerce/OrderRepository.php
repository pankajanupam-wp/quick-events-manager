<?php
/**
 * Every database query about orders.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

/*
 * A custom table, so direct queries are the only way to read it. Both sniffs are
 * disabled for this file rather than project-wide, so a $wpdb call appearing in
 * a renderer or a REST controller is still a finding everywhere else.
 *
 * NoCaching is disabled rather than answered because these rows are money and
 * capacity: a stale total is a wrong refund, and a stale hold is a seat sold
 * twice.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; see the note above.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Money must not be read stale; see the note above.

/**
 * The only class that talks to $wpdb about orders.
 *
 * **Everything here is in minor units.** No float ever touches an amount, in
 * this class or any other: `0.1 + 0.2` is not `0.3` in binary floating point,
 * and an error of one paisa in a reconciliation is a support conversation that
 * costs more than the transaction did.
 *
 * @since 26.0
 */
final class OrderRepository {

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 */
	public static function table(): string {
		return Installer::table( 'orders' );
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
	 * Start an order.
	 *
	 * The totals are written by the caller rather than worked out here: what an
	 * order comes to is a question about lines, tax and whatever a gateway
	 * rounds, and this class stores what it is told. `create()` exists to give
	 * the row an id so that lines can point at it.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int New id, or 0.
	 */
	public static function create( array $data ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = self::sanitize( $data );

		$row['order_number'] = '' !== (string) ( $data['order_number'] ?? '' )
			? (string) $data['order_number']
			: self::generate_order_number();

		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		/*
		 * Silenced for the same reason as the ledger's insert: a duplicate
		 * order number is an answer this method has — return 0 — rather than a
		 * fault, and $wpdb would otherwise print a MySQL error where a caller
		 * is already handling it.
		 */
		$previous = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert( self::table(), $row, self::formats( $row ) );

		$wpdb->suppress_errors( $previous );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One order by id.
	 *
	 * @since 26.0
	 *
	 * @param int $id Order id.
	 */
	public static function find( int $id ): ?Order {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? new Order( $row ) : null;
	}

	/**
	 * One order by the reference the customer was given.
	 *
	 * @since 26.0
	 *
	 * @param string $number Order number.
	 */
	public static function find_by_number( string $number ): ?Order {
		global $wpdb;

		if ( ! self::table_exists() || '' === $number ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_number = %s', self::table(), $number ),
			ARRAY_A
		);

		return is_array( $row ) ? new Order( $row ) : null;
	}

	/**
	 * Change some columns.
	 *
	 * @since 26.0
	 *
	 * @param int                  $id   Order id.
	 * @param array<string, mixed> $data Columns to write.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 ) {
			return false;
		}

		$row = self::sanitize( $data, array_keys( $data ) );

		if ( array() === $row ) {
			return false;
		}

		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update( self::table(), $row, array( 'id' => $id ), self::formats( $row ), array( '%d' ) );
	}

	/**
	 * Mark an order paid.
	 *
	 * `paid_at` is stamped and the hold is released in the same write: seats
	 * held against a clock and seats held because they were paid for are the
	 * same seats, and leaving an expiry on a paid order would let the sweep
	 * abandon something somebody has been charged for.
	 *
	 * @since 26.0
	 *
	 * @param int    $id   Order id.
	 * @param string $when UTC `Y-m-d H:i:s`, defaulting to now.
	 * @return bool
	 */
	public static function mark_paid( int $id, string $when = '' ): bool {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 ) {
			return false;
		}

		$when = '' !== $when ? $when : gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update(
			self::table(),
			array(
				'status'           => OrderStatus::Paid->value,
				'paid_at'          => $when,
				'hold_expires_utc' => null,
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Orders whose hold has run out.
	 *
	 * The cron sweep's query, and the reason `KEY hold_expires` exists. Only
	 * pending orders can expire: a paid one has no expiry, and an abandoned one
	 * has already been dealt with.
	 *
	 * @since 26.0
	 *
	 * @param string $now   UTC `Y-m-d H:i:s`, defaulting to this moment.
	 * @param int    $limit How many to take at once.
	 * @return array<int, Order>
	 */
	public static function expired_holds( string $now = '', int $limit = 50 ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$now   = '' !== $now ? $now : gmdate( 'Y-m-d H:i:s' );
		$limit = max( 1, $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND hold_expires_utc IS NOT NULL AND hold_expires_utc <= %s ORDER BY hold_expires_utc ASC LIMIT %d',
				self::table(),
				OrderStatus::Pending->value,
				$now,
				$limit
			),
			ARRAY_A
		);

		return array_map( static fn( $row ) => new Order( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every order for one event, newest first.
	 *
	 * Reached through the items, because an order can hold tickets for more
	 * than one event and the header deliberately names none of them.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @param int $limit    How many.
	 * @return array<int, Order>
	 */
	public static function for_event( int $event_id, int $limit = 100 ): array {
		global $wpdb;

		if ( ! self::table_exists() || $event_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.* FROM %i o INNER JOIN %i i ON i.order_id = o.id WHERE i.event_id = %d GROUP BY o.id ORDER BY o.id DESC LIMIT %d',
				self::table(),
				OrderItemRepository::table(),
				$event_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map( static fn( $row ) => new Order( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A reference nobody else has, e.g. `QEVO-4H3K9AB2`.
	 *
	 * The prefix differs from a booking reference and from a ticket code on
	 * purpose: all three can end up in the same inbox, and somebody quoting the
	 * wrong one should be told it is the wrong kind rather than simply not
	 * found.
	 *
	 * @since 26.0
	 */
	public static function generate_order_number(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$length   = strlen( $alphabet );

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$number = 'QEVO-';

			for ( $i = 0; $i < 8; $i++ ) {
				$number .= $alphabet[ wp_rand( 0, $length - 1 ) ];
			}

			if ( null === self::find_by_number( $number ) ) {
				return $number;
			}
		}

		// Ten collisions against a 32^8 space means something is very wrong.
		return 'QEVO-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 12 ) );
	}

	/**
	 * Clean a set of column values.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed>    $data Raw values.
	 * @param array<int, string>|null $only Limit to these keys.
	 * @return array<string, mixed>
	 */
	private static function sanitize( array $data, ?array $only = null ): array {
		$row = array();

		$strings = array(
			'order_number'  => 32,
			'billing_name'  => 190,
			'billing_email' => 190,
			'gateway'       => 40,
		);

		foreach ( $strings as $key => $max ) {
			if ( null === $only || in_array( $key, $only, true ) ) {
				if ( null !== $only && ! array_key_exists( $key, $data ) ) {
					continue;
				}

				$value = 'billing_email' === $key
					? sanitize_email( (string) ( $data[ $key ] ?? '' ) )
					: sanitize_text_field( (string) ( $data[ $key ] ?? '' ) );

				$row[ $key ] = substr( $value, 0, $max );
			}
		}

		$integers = array( 'user_id', 'subtotal_minor', 'tax_minor', 'total_minor', 'refunded_minor' );

		foreach ( $integers as $key ) {
			if ( null === $only || in_array( $key, $only, true ) ) {
				if ( null !== $only && ! array_key_exists( $key, $data ) ) {
					continue;
				}

				$row[ $key ] = (int) ( $data[ $key ] ?? 0 );
			}
		}

		if ( null === $only || array_key_exists( 'status', $data ) ) {
			$row['status'] = OrderStatus::from_value( $data['status'] ?? OrderStatus::Pending->value )->value;
		}

		if ( null === $only || array_key_exists( 'currency', $data ) ) {
			/*
			 * Three letters, upper case, or nothing. A currency is not
			 * free text: it is an ISO-4217 code that a gateway will reject if
			 * it is wrong, and rejecting it here is cheaper than a failed
			 * charge. Which code a site uses is C9.2's question.
			 */
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['currency'] ?? '' ) ) );

			$row['currency'] = 3 === strlen( $code ) ? $code : '';
		}

		if ( null === $only || array_key_exists( 'hold_expires_utc', $data ) ) {
			$hold = (string) ( $data['hold_expires_utc'] ?? '' );

			$row['hold_expires_utc'] = '' !== $hold ? $hold : null;
		}

		if ( null !== $only && array_key_exists( 'paid_at', $data ) ) {
			$paid = (string) ( $data['paid_at'] ?? '' );

			$row['paid_at'] = '' !== $paid ? $paid : null;
		}

		return $row;
	}

	/**
	 * Placeholder formats for a row, in its own key order.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Row about to be written.
	 * @return array<int, string>
	 */
	private static function formats( array $row ): array {
		$integers = array( 'user_id', 'subtotal_minor', 'tax_minor', 'total_minor', 'refunded_minor' );

		$formats = array();

		foreach ( array_keys( $row ) as $key ) {
			$formats[] = in_array( $key, $integers, true ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * The `CREATE TABLE` statement.
	 *
	 * @since 26.0
	 */
	public static function schema(): string {
		$table   = self::table();
		$collate = Installer::charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_number varchar(32) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			currency char(3) NOT NULL DEFAULT '',
			subtotal_minor bigint(20) NOT NULL DEFAULT 0,
			tax_minor bigint(20) NOT NULL DEFAULT 0,
			total_minor bigint(20) NOT NULL DEFAULT 0,
			refunded_minor bigint(20) NOT NULL DEFAULT 0,
			billing_name varchar(190) NOT NULL DEFAULT '',
			billing_email varchar(190) NOT NULL DEFAULT '',
			gateway varchar(40) NOT NULL DEFAULT '',
			hold_expires_utc datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			paid_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_number (order_number),
			KEY status_created (status, created_at),
			KEY user_id (user_id),
			KEY hold_expires (hold_expires_utc)
		) {$collate};";
	}
}
