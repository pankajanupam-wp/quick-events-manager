<?php
/**
 * Orders, lines and the ledger, against real MySQL.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Commerce\OrderItemRepository;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\TransactionKind;
use QuickEventsManager\Domain\TransactionStatus;

/**
 * The parts of C9.1 that only a real database can answer.
 *
 * Unique keys, signed sums and what MySQL does with an empty string in an index
 * are not things a stub can be asked. The last of those is the reason this file
 * exists at all: the schema as specified could not have held two pending
 * charges.
 */
final class CommerceTablesTest extends TestCase {

	/**
	 * Create the tables, once, before anything asks for them.
	 *
	 * DDL commits the transaction this suite runs in, so it happens in setUp
	 * before any row exists rather than inside a test that would then be
	 * unrollbackable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->switch_module_on( CommerceModule::ID );

		if ( ! OrderRepository::table_exists() || ! TransactionRepository::table_exists() ) {
			( new CommerceModule() )->activate();

			$this->restore_schema();
		}
	}

	/**
	 * Two checkouts can be in progress at once.
	 *
	 * The schema in docs/database.md gave `gateway_txn_id` and
	 * `idempotency_key` `NOT NULL DEFAULT ''` under unique keys. MySQL treats
	 * `''` as a value, so the second row without a gateway id yet — the second
	 * checkout the site ever had — was rejected as a duplicate. Worse than a
	 * crash: a duplicate key is exactly the answer a webhook handler reads as
	 * "already processed", so a brand-new payment would have looked like a
	 * replay of an old one.
	 *
	 * @return void
	 */
	public function test_two_charges_can_be_pending_at_once() {
		$first  = $this->an_order();
		$second = $this->an_order();

		$one = TransactionRepository::record(
			array(
				'order_id'     => $first,
				'kind'         => TransactionKind::Charge,
				'amount_minor' => 1000,
				'currency'     => 'GBP',
				'gateway'      => 'stripe',
			)
		);

		$two = TransactionRepository::record(
			array(
				'order_id'     => $second,
				'kind'         => TransactionKind::Charge,
				'amount_minor' => 2500,
				'currency'     => 'GBP',
				'gateway'      => 'stripe',
			)
		);

		$this->assertGreaterThan( 0, $one );
		$this->assertGreaterThan( 0, $two, 'a second checkout with no gateway id yet must be able to exist' );
	}

	/**
	 * The same gateway event delivered twice is recorded once.
	 *
	 * @return void
	 */
	public function test_a_repeated_gateway_event_is_recorded_once() {
		$order_id = $this->an_order();

		$line = array(
			'order_id'       => $order_id,
			'kind'           => TransactionKind::Charge,
			'status'         => TransactionStatus::Succeeded,
			'amount_minor'   => 4999,
			'currency'       => 'GBP',
			'gateway'        => 'stripe',
			'gateway_txn_id' => 'pi_abc123',
		);

		$first  = TransactionRepository::record( $line );
		$second = TransactionRepository::record( $line );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $second, 'the second delivery is already done, not a new payment' );

		$found = TransactionRepository::find_by_gateway_txn( 'stripe', 'pi_abc123' );

		$this->assertNotNull( $found );
		$this->assertSame( $first, $found->id(), 'and the handler can find the one that was already there' );
		$this->assertCount( 1, TransactionRepository::for_order( $order_id ) );
	}

	/**
	 * A refund is stored negative however it was handed over.
	 *
	 * @return void
	 */
	public function test_a_refund_is_stored_negative() {
		$order_id = $this->an_order();

		$id = TransactionRepository::record(
			array(
				'order_id'     => $order_id,
				'kind'         => TransactionKind::Refund,
				'status'       => TransactionStatus::Succeeded,
				'amount_minor' => 500,
				'currency'     => 'GBP',
				'gateway'      => 'stripe',
			)
		);

		$transaction = TransactionRepository::find( $id );

		$this->assertSame( -500, $transaction->amount_minor(), 'the kind decides the sign, not the caller' );
		$this->assertSame( 500, $transaction->absolute_minor(), 'and a person is shown it without one' );
	}

	/**
	 * The order's cached figure never disagrees with the ledger.
	 *
	 * @return void
	 */
	public function test_the_cached_total_follows_the_ledger() {
		$order_id = $this->an_order( 5000 );

		OrderRepository::mark_paid( $order_id );

		TransactionRepository::record(
			array(
				'order_id'     => $order_id,
				'kind'         => TransactionKind::Charge,
				'status'       => TransactionStatus::Succeeded,
				'amount_minor' => 5000,
				'currency'     => 'GBP',
				'gateway'      => 'stripe',
			)
		);

		$this->assertSame( 0, OrderRepository::find( $order_id )->refunded_minor() );
		$this->assertSame( OrderStatus::Paid, OrderRepository::find( $order_id )->status() );

		TransactionRepository::record(
			array(
				'order_id'     => $order_id,
				'kind'         => TransactionKind::Refund,
				'status'       => TransactionStatus::Succeeded,
				'amount_minor' => 2000,
				'currency'     => 'GBP',
				'gateway'      => 'stripe',
			)
		);

		$order = OrderRepository::find( $order_id );

		$this->assertSame( 2000, $order->refunded_minor(), 'the copy' );
		$this->assertSame( 2000, TransactionRepository::refunded_for_order( $order_id ), 'the truth' );
		$this->assertSame( OrderStatus::PartiallyRefunded, $order->status() );
		$this->assertSame( 3000, $order->net_minor() );

		TransactionRepository::record(
			array(
				'order_id'     => $order_id,
				'kind'         => TransactionKind::Refund,
				'status'       => TransactionStatus::Succeeded,
				'amount_minor' => 3000,
				'currency'     => 'GBP',
				'gateway'      => 'stripe',
			)
		);

		$order = OrderRepository::find( $order_id );

		$this->assertSame( OrderStatus::Refunded, $order->status(), 'everything back means refunded' );
		$this->assertSame( 0, $order->net_minor() );
	}

	/**
	 * A charge that has not succeeded is not money.
	 *
	 * @return void
	 */
	public function test_only_succeeded_lines_count() {
		$order_id = $this->an_order( 1000 );

		TransactionRepository::record(
			array(
				'order_id'     => $order_id,
				'kind'         => TransactionKind::Charge,
				'status'       => TransactionStatus::Pending,
				'amount_minor' => 1000,
				'currency'     => 'GBP',
				'gateway'      => 'stripe',
			)
		);

		$this->assertSame( 0, TransactionRepository::net_for_order( $order_id ), 'attempted is not taken' );
	}

	/**
	 * A line keeps what it cost, whatever the ticket costs now.
	 *
	 * @return void
	 */
	public function test_a_line_is_a_snapshot() {
		$order_id = $this->an_order();

		$item_id = OrderItemRepository::add(
			array(
				'order_id'         => $order_id,
				'ticket_type_id'   => 7,
				'event_id'         => 42,
				'name_snapshot'    => 'Member',
				'event_snapshot'   => 'Spring social',
				'unit_price_minor' => 499,
				'quantity'         => 3,
			)
		);

		$this->assertGreaterThan( 0, $item_id );

		$item = OrderItemRepository::for_order( $order_id )[0];

		$this->assertSame( 'Member', $item->name() );
		$this->assertSame( 499, $item->unit_price_minor() );
		$this->assertSame( 1497, $item->total_minor(), 'the arithmetic as it stood' );
		$this->assertSame( 1497, OrderItemRepository::total_for_order( $order_id ) );
	}

	/**
	 * Lines can be rebuilt before payment and never after it.
	 *
	 * @return void
	 */
	public function test_lines_cannot_be_removed_once_paid() {
		$order_id = $this->an_order();

		OrderItemRepository::add(
			array(
				'order_id'         => $order_id,
				'name_snapshot'    => 'Standard',
				'unit_price_minor' => 1000,
				'quantity'         => 1,
			)
		);

		$this->assertTrue( OrderItemRepository::delete_for_order( $order_id ), 'a pending basket may change' );

		OrderItemRepository::add(
			array(
				'order_id'         => $order_id,
				'name_snapshot'    => 'Standard',
				'unit_price_minor' => 1000,
				'quantity'         => 2,
			)
		);

		OrderRepository::mark_paid( $order_id );

		$this->assertFalse( OrderItemRepository::delete_for_order( $order_id ), 'a paid line is a financial record' );
		$this->assertCount( 1, OrderItemRepository::for_order( $order_id ) );
	}

	/**
	 * Paying releases the hold.
	 *
	 * A hold left on a paid order would let the sweep abandon something
	 * somebody has been charged for.
	 *
	 * @return void
	 */
	public function test_paying_releases_the_hold() {
		$order_id = $this->an_order();

		OrderRepository::update( $order_id, array( 'hold_expires_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );

		$this->assertCount( 1, OrderRepository::expired_holds(), 'it is up for sweeping while it is pending' );

		OrderRepository::mark_paid( $order_id );

		$order = OrderRepository::find( $order_id );

		$this->assertNull( $order->hold_expires_utc() );
		$this->assertSame( array(), OrderRepository::expired_holds(), 'and not once it is paid' );
	}

	/**
	 * Only pending orders are swept.
	 *
	 * @return void
	 */
	public function test_the_sweep_finds_only_what_is_still_waiting() {
		$expired = $this->an_order();
		$fresh   = $this->an_order();

		OrderRepository::update( $expired, array( 'hold_expires_utc' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
		OrderRepository::update( $fresh, array( 'hold_expires_utc' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );

		$found = OrderRepository::expired_holds();

		$this->assertCount( 1, $found );
		$this->assertSame( $expired, $found[0]->id() );
		$this->assertTrue( $found[0]->hold_has_expired() );
	}

	/**
	 * Two orders cannot share a reference.
	 *
	 * @return void
	 */
	public function test_order_numbers_are_unique_and_look_like_orders() {
		$number = OrderRepository::find( $this->an_order() )->number();

		$this->assertStringStartsWith( 'QEVO-', $number, 'a reader can tell it from a booking reference' );

		$duplicate = OrderRepository::create(
			array(
				'order_number' => $number,
				'currency'     => 'GBP',
			)
		);

		$this->assertSame( 0, $duplicate, 'the database refuses it rather than the code remembering to check' );
	}

	/**
	 * Sold counts ignore checkouts that were walked away from.
	 *
	 * @return void
	 */
	public function test_an_abandoned_checkout_is_not_a_sale() {
		$paid      = $this->an_order();
		$abandoned = $this->an_order();

		foreach ( array( $paid, $abandoned ) as $order_id ) {
			OrderItemRepository::add(
				array(
					'order_id'         => $order_id,
					'ticket_type_id'   => 99,
					'name_snapshot'    => 'Member',
					'unit_price_minor' => 500,
					'quantity'         => 2,
				)
			);
		}

		OrderRepository::mark_paid( $paid );
		OrderRepository::update( $abandoned, array( 'status' => OrderStatus::Abandoned->value ) );

		$this->assertSame( 2, OrderItemRepository::sold_for_ticket_type( 99 ) );
	}

	/**
	 * The two columns nothing ever read are gone.
	 *
	 * @return void
	 */
	public function test_the_unread_ticket_columns_have_been_dropped() {
		global $wpdb;

		\QuickEventsManager\Install\Installer::drop_retired_columns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema question in a test.
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'qevm_ticket_types' );

		$this->assertNotContains( 'min_per_order', $columns );
		$this->assertNotContains( 'max_per_order', $columns );
	}

	/**
	 * An order to hang things off.
	 *
	 * @param int $total Total in minor units.
	 * @return int
	 */
	private function an_order( int $total = 1000 ): int {
		return OrderRepository::create(
			array(
				'currency'       => 'GBP',
				'subtotal_minor' => $total,
				'total_minor'    => $total,
				'billing_name'   => 'Ada Lovelace',
				'billing_email'  => 'ada@example.com',
				'gateway'        => 'stripe',
			)
		);
	}
}
