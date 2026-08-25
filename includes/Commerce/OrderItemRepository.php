<?php
/**
 * Every database query about order lines.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Money must not be read stale.

/**
 * The only class that talks to $wpdb about order lines.
 *
 * **There is no `update()` here, and that is deliberate.** A line is what was
 * bought at the price it was bought at, and the table exists so that a report
 * written next year still adds up. Nothing may edit one afterwards: correcting a
 * mistake is a refund and a new order, which is also how the money actually
 * moved. See [ADR-0006](../../docs/adr/0006-money-and-immutability.md).
 *
 * `delete_for_order()` exists for one case — a checkout being rebuilt before
 * anybody has paid for it — and refuses once an order is out of `pending`.
 *
 * @since 26.0
 */
final class OrderItemRepository {

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 */
	public static function table(): string {
		return Installer::table( 'order_items' );
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
	 * Write one line.
	 *
	 * The snapshot columns are taken from what the caller hands over, not
	 * looked up here: the caller is holding the ticket type and the event as
	 * they are at the moment of purchase, and re-reading them a moment later is
	 * how a price change halfway through a checkout ends up in the wrong row.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int New id, or 0.
	 */
	public static function add( array $data ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$row = array(
			'order_id'         => (int) ( $data['order_id'] ?? 0 ),
			'ticket_type_id'   => (int) ( $data['ticket_type_id'] ?? 0 ),
			'event_id'         => (int) ( $data['event_id'] ?? 0 ),
			'occurrence_id'    => (int) ( $data['occurrence_id'] ?? 0 ),
			'name_snapshot'    => substr( sanitize_text_field( (string) ( $data['name_snapshot'] ?? '' ) ), 0, 190 ),
			'event_snapshot'   => substr( sanitize_text_field( (string) ( $data['event_snapshot'] ?? '' ) ), 0, 190 ),
			'unit_price_minor' => (int) ( $data['unit_price_minor'] ?? 0 ),
			'quantity'         => max( 1, (int) ( $data['quantity'] ?? 1 ) ),
			'tax_minor'        => (int) ( $data['tax_minor'] ?? 0 ),
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( $row['order_id'] <= 0 || '' === $row['name_snapshot'] ) {
			return 0;
		}

		/*
		 * The line total is worked out here rather than trusted, because it is
		 * the figure a report adds up. A caller that hands over a total which
		 * does not match its own unit price and quantity has a bug, and
		 * storing it would hide the bug inside the accounts.
		 */
		$row['total_minor'] = ( $row['unit_price_minor'] * $row['quantity'] ) + $row['tax_minor'];

		$inserted = $wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The lines of one order, in the order they were added.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return array<int, OrderItem>
	 */
	public static function for_order( int $order_id ): array {
		global $wpdb;

		if ( ! self::table_exists() || $order_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', self::table(), $order_id ),
			ARRAY_A
		);

		return array_map( static fn( $row ) => new OrderItem( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * What the lines of an order come to, in minor units.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 */
	public static function total_for_order( int $order_id ): int {
		global $wpdb;

		if ( ! self::table_exists() || $order_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(total_minor), 0) FROM %i WHERE order_id = %d', self::table(), $order_id )
		);
	}

	/**
	 * How many tickets of one type an event has sold.
	 *
	 * Counted from the lines of orders that are still holding their places, so
	 * an abandoned checkout does not show up as a sale.
	 *
	 * @since 26.0
	 *
	 * @param int $ticket_type_id Ticket type id.
	 * @return int
	 */
	public static function sold_for_ticket_type( int $ticket_type_id ): int {
		global $wpdb;

		if ( ! self::table_exists() || $ticket_type_id <= 0 ) {
			return 0;
		}

		$holding = array_map(
			static fn( $status ) => $status->value,
			array_filter(
				\QuickEventsManager\Domain\OrderStatus::cases(),
				static fn( $status ) => $status->holds_capacity()
			)
		);

		$placeholders = implode( ', ', array_fill( 0, count( $holding ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built from an enum, values passed to prepare().
		$sql = "SELECT COALESCE(SUM(i.quantity), 0) FROM %i i INNER JOIN %i o ON o.id = i.order_id WHERE i.ticket_type_id = %d AND o.status IN ( {$placeholders} )";

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built immediately above from an enum.
				array_merge( array( self::table(), OrderRepository::table(), $ticket_type_id ), $holding )
			)
		);
	}

	/**
	 * Remove the lines of an order that has not been paid for.
	 *
	 * The one case where a line may go: somebody changing what they are buying
	 * before paying. Refuses on anything that is not pending, because a line on
	 * a paid order is a financial record.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether the lines were removed.
	 */
	public static function delete_for_order( int $order_id ): bool {
		global $wpdb;

		if ( ! self::table_exists() || $order_id <= 0 ) {
			return false;
		}

		$order = OrderRepository::find( $order_id );

		if ( null === $order || \QuickEventsManager\Domain\OrderStatus::Pending !== $order->status() ) {
			return false;
		}

		return false !== $wpdb->delete( self::table(), array( 'order_id' => $order_id ), array( '%d' ) );
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
			order_id bigint(20) unsigned NOT NULL,
			ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name_snapshot varchar(190) NOT NULL,
			event_snapshot varchar(190) NOT NULL DEFAULT '',
			unit_price_minor bigint(20) NOT NULL,
			quantity smallint(5) unsigned NOT NULL DEFAULT 1,
			tax_minor bigint(20) NOT NULL DEFAULT 0,
			total_minor bigint(20) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY event_id (event_id),
			KEY ticket_type_id (ticket_type_id)
		) {$collate};";
	}
}
