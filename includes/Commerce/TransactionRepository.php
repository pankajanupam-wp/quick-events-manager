<?php
/**
 * The ledger.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\TransactionKind;
use QuickEventsManager\Domain\TransactionStatus;
use QuickEventsManager\Install\Installer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Money must not be read stale.

/**
 * The only class that talks to $wpdb about money moving.
 *
 * **Charges and refunds are one table with a sign.** An order's net position is
 * then a single `SUM` and cannot disagree with itself, which two tables joined
 * on each other can and eventually do.
 *
 * **The sign is not the caller's business.** `record()` takes an amount and a
 * kind and stores a refund negative, so a caller that passes 500 for a refund
 * cannot accidentally add money to an order.
 *
 * **Duplicates are prevented by the database, not by looking first.** Payment
 * gateways deliver the same webhook more than once by design. Checking whether a
 * transaction exists and then inserting it leaves a window between the two in
 * which the second delivery arrives; a unique key does not. `record()` inserts
 * and reads the failure — the same insert-then-resolve discipline as capacity
 * and as check-in.
 *
 * `gateway_txn_id` and `idempotency_key` are **NULL** when unknown rather than
 * `''`. MySQL treats `''` as a value in a unique index and two rows cannot both
 * hold it, so the specified `NOT NULL DEFAULT ''` would have rejected the second
 * charge a site ever started. Multiple NULLs are permitted, so "not known yet"
 * stops colliding with itself while a real id still cannot appear twice.
 *
 * @since 26.0
 */
final class TransactionRepository {

	/**
	 * Table name, with prefix.
	 *
	 * @since 26.0
	 */
	public static function table(): string {
		return Installer::table( 'transactions' );
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
	 * Write a line in the ledger.
	 *
	 * Returns 0 when the row already exists — a webhook arriving twice, a retry
	 * of something that went through — and the caller should treat that as
	 * "already done" rather than as an error. `find_by_gateway_txn()` gets the
	 * row that was already there.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $data Column values. `amount_minor` is given
	 *                                   unsigned; the kind decides the sign.
	 * @return int New id, or 0 when it was already recorded or could not be.
	 */
	public static function record( array $data ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$order_id = (int) ( $data['order_id'] ?? 0 );
		$kind     = TransactionKind::from_value( $data['kind'] ?? TransactionKind::Charge->value );
		$status   = TransactionStatus::from_value( $data['status'] ?? TransactionStatus::Pending->value );

		if ( $order_id <= 0 ) {
			return 0;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$row = array(
			'order_id'        => $order_id,
			'kind'            => $kind->value,
			'status'          => $status->value,
			'amount_minor'    => abs( (int) ( $data['amount_minor'] ?? 0 ) ) * $kind->sign(),
			'currency'        => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['currency'] ?? '' ) ), 0, 3 ) ),
			'gateway'         => substr( sanitize_text_field( (string) ( $data['gateway'] ?? '' ) ), 0, 40 ),
			'gateway_txn_id'  => self::or_null( $data['gateway_txn_id'] ?? '' ),
			'idempotency_key' => self::or_null( $data['idempotency_key'] ?? '' ),
			'reason'          => substr( sanitize_text_field( (string) ( $data['reason'] ?? '' ) ), 0, 190 ),
			'error_code'      => substr( sanitize_text_field( (string) ( $data['error_code'] ?? '' ) ), 0, 64 ),
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		/*
		 * Silenced because a duplicate key is an expected answer here, not a
		 * fault: it is how "this webhook has already been processed" is
		 * detected. Letting $wpdb print it would put a MySQL error on the
		 * screen every time a gateway does the thing gateways do.
		 */
		$previous = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( $previous );

		if ( ! $inserted ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		self::refresh_order( $order_id );

		return $id;
	}

	/**
	 * Change a line's outcome.
	 *
	 * The amount is not touched: a charge that fails is a failed charge for the
	 * amount attempted, and rewriting the figure would lose what was tried.
	 *
	 * @since 26.0
	 *
	 * @param int                  $id     Transaction id.
	 * @param TransactionStatus    $status Where it ended up.
	 * @param array<string, mixed> $data Optionally `gateway_txn_id`, `error_code`.
	 * @return bool
	 */
	public static function settle( int $id, TransactionStatus $status, array $data = array() ): bool {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 ) {
			return false;
		}

		$row = array(
			'status'     => $status->value,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( array_key_exists( 'gateway_txn_id', $data ) ) {
			$row['gateway_txn_id'] = self::or_null( $data['gateway_txn_id'] );
		}

		if ( array_key_exists( 'error_code', $data ) ) {
			$row['error_code'] = substr( sanitize_text_field( (string) $data['error_code'] ), 0, 64 );
		}

		$updated = $wpdb->update( self::table(), $row, array( 'id' => $id ), null, array( '%d' ) );

		if ( false === $updated ) {
			return false;
		}

		$transaction = self::find( $id );

		if ( null !== $transaction ) {
			self::refresh_order( $transaction->order_id() );
		}

		return true;
	}

	/**
	 * One line by id.
	 *
	 * @since 26.0
	 *
	 * @param int $id Transaction id.
	 */
	public static function find( int $id ): ?Transaction {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? new Transaction( $row ) : null;
	}

	/**
	 * The line a gateway's own id belongs to, if there is one.
	 *
	 * What a webhook handler asks after `record()` has told it the row already
	 * existed.
	 *
	 * @since 26.0
	 *
	 * @param string $gateway Gateway id.
	 * @param string $txn_id  The gateway's id for the movement.
	 */
	public static function find_by_gateway_txn( string $gateway, string $txn_id ): ?Transaction {
		global $wpdb;

		if ( ! self::table_exists() || '' === $gateway || '' === $txn_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE gateway = %s AND gateway_txn_id = %s',
				self::table(),
				$gateway,
				$txn_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? new Transaction( $row ) : null;
	}

	/**
	 * The line an idempotency key belongs to, if there is one.
	 *
	 * @since 26.0
	 *
	 * @param string $key Idempotency key.
	 */
	public static function find_by_idempotency_key( string $key ): ?Transaction {
		global $wpdb;

		if ( ! self::table_exists() || '' === $key ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s', self::table(), $key ),
			ARRAY_A
		);

		return is_array( $row ) ? new Transaction( $row ) : null;
	}

	/**
	 * Every line of one order, oldest first.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return array<int, Transaction>
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

		return array_map( static fn( $row ) => new Transaction( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * What an order is worth right now, in minor units, straight from the ledger.
	 *
	 * The authority. `qevm_orders.refunded_minor` is a copy of part of this
	 * kept so that a list of orders is not a list of `SUM` queries, and this is
	 * what it is a copy of.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 */
	public static function net_for_order( int $order_id ): int {
		global $wpdb;

		if ( ! self::table_exists() || $order_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount_minor), 0) FROM %i WHERE order_id = %d AND status = %s',
				self::table(),
				$order_id,
				TransactionStatus::Succeeded->value
			)
		);
	}

	/**
	 * What has gone back on an order, in minor units, as a positive number.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 */
	public static function refunded_for_order( int $order_id ): int {
		global $wpdb;

		if ( ! self::table_exists() || $order_id <= 0 ) {
			return 0;
		}

		$sum = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount_minor), 0) FROM %i WHERE order_id = %d AND kind = %s AND status = %s',
				self::table(),
				$order_id,
				TransactionKind::Refund->value,
				TransactionStatus::Succeeded->value
			)
		);

		return abs( $sum );
	}

	/**
	 * Put the order's cached figure and status back in step with the ledger.
	 *
	 * The single writer of `refunded_minor`, and the reason a cached total
	 * cannot drift: every path that changes the ledger comes through here in
	 * the same call that changed it.
	 *
	 * A refund that covers everything charged makes the order refunded; part of
	 * it makes it partly refunded. Nothing here marks an order paid — that is a
	 * decision about a gateway's answer, not about arithmetic, and it belongs
	 * to `OrderRepository::mark_paid()`.
	 *
	 * @since 26.0
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function refresh_order( int $order_id ) {
		$order = OrderRepository::find( $order_id );

		if ( null === $order ) {
			return;
		}

		$refunded = self::refunded_for_order( $order_id );
		$changes  = array( 'refunded_minor' => $refunded );

		if ( $order->status()->is_settled() || OrderStatus::Refunded === $order->status() ) {
			if ( $refunded <= 0 ) {
				$changes['status'] = OrderStatus::Paid->value;
			} elseif ( $refunded >= $order->total_minor() ) {
				$changes['status'] = OrderStatus::Refunded->value;
			} else {
				$changes['status'] = OrderStatus::PartiallyRefunded->value;
			}
		}

		OrderRepository::update( $order_id, $changes );
	}

	/**
	 * A string, or NULL when it is empty.
	 *
	 * @since 26.0
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function or_null( $value ): ?string {
		$value = substr( sanitize_text_field( (string) $value ), 0, 190 );

		return '' !== $value ? $value : null;
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
			kind varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			amount_minor bigint(20) NOT NULL,
			currency char(3) NOT NULL DEFAULT '',
			gateway varchar(40) NOT NULL DEFAULT '',
			gateway_txn_id varchar(190) DEFAULT NULL,
			idempotency_key varchar(190) DEFAULT NULL,
			reason varchar(190) NOT NULL DEFAULT '',
			error_code varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_txn (gateway, gateway_txn_id),
			UNIQUE KEY idempotency (idempotency_key),
			KEY order_kind (order_id, kind)
		) {$collate};";
	}
}
