<?php
/**
 * Seats held by a checkout, and what happens when nobody comes back.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Commerce\Holds;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\OrderService;
use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\Repository;

/**
 * The step most ticketing implementations forget.
 *
 * Without it an event sells out at eighty per cent, because every closed tab is
 * still holding a seat nobody will ever pay for. There is no "the customer went
 * away" event to listen for, so the whole mechanism is a moment written down at
 * the start and a job that comes back to it.
 */
final class HoldsTest extends TestCase {

	/**
	 * Commerce on, tables present.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->quieten_registration();
		$this->switch_module_on( CommerceModule::ID );

		if ( ! OrderRepository::table_exists() ) {
			( new CommerceModule() )->activate();

			$this->restore_schema();
		}

		( new CommerceModule() )->register();
	}

	/**
	 * A checkout that is not paid for gives its seat back.
	 *
	 * @return void
	 */
	public function test_an_abandoned_checkout_frees_the_seat() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$booking = $this->book( $event_id );

		Repository::update_status( $booking->id(), RegistrationStatus::Pending );

		$order_id = $this->an_order_for( $booking->id(), $event_id );

		$this->assertSame( 1, Repository::count_taken( $event_id ), 'a pending booking is holding the room' );

		$this->expire( $order_id );

		$this->assertSame( 1, Holds::run() );

		$this->assertSame( OrderStatus::Abandoned, OrderRepository::find( $order_id )->status() );
		$this->assertSame( RegistrationStatus::Cancelled, Repository::find( $booking->id() )->status() );
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'and the room has it back' );
	}

	/**
	 * The seat goes to whoever was waiting for it.
	 *
	 * The point of freeing it. Nothing new is written for this: cancelling is
	 * what promotes a waiting list, and abandonment cancels.
	 *
	 * @return void
	 */
	public function test_a_freed_seat_reaches_the_waiting_list() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first   = $this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$waiting = $this->book( $event_id, array( 'email' => 'second@example.com' ) );

		$this->assertSame( RegistrationStatus::Waitlisted, $waiting->status() );

		Repository::update_status( $first->id(), RegistrationStatus::Pending );

		$order_id = $this->an_order_for( $first->id(), $event_id );

		$this->expire( $order_id );

		Holds::run();

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $waiting->id() )->status(),
			'the person who was waiting has the place'
		);
	}

	/**
	 * A hold that has not run out is left alone.
	 *
	 * @return void
	 */
	public function test_a_live_hold_is_left_alone() {
		$event_id = $this->make_event();
		$booking  = $this->book( $event_id );

		Repository::update_status( $booking->id(), RegistrationStatus::Pending );

		$order_id = $this->an_order_for( $booking->id(), $event_id );

		$this->assertSame( 0, Holds::run(), 'somebody is still typing their card number' );
		$this->assertSame( OrderStatus::Pending, OrderRepository::find( $order_id )->status() );
		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status() );
	}

	/**
	 * Paying stops the sweep taking the seat away.
	 *
	 * @return void
	 */
	public function test_paying_keeps_the_seat() {
		$event_id = $this->make_event();
		$booking  = $this->book( $event_id );

		Repository::update_status( $booking->id(), RegistrationStatus::Pending );

		$order_id = $this->an_order_for( $booking->id(), $event_id );

		$this->assertTrue( OrderService::complete( $order_id ) );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );

		$this->expire( $order_id );

		$this->assertSame( 0, Holds::run(), 'a paid order has no hold to expire' );
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
	}

	/**
	 * Being told twice that an order was paid does not happen twice.
	 *
	 * Gateways deliver the same event more than once by design, and the second
	 * delivery must not send a second confirmation.
	 *
	 * @return void
	 */
	public function test_completing_twice_only_counts_once() {
		$event_id = $this->make_event();
		$booking  = $this->book( $event_id );

		Repository::update_status( $booking->id(), RegistrationStatus::Pending );

		$order_id = $this->an_order_for( $booking->id(), $event_id );

		$fired = 0;

		add_action(
			'qevm_booking_paid_for',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$this->assertTrue( OrderService::complete( $order_id ) );
		$this->assertFalse( OrderService::complete( $order_id ) );
		$this->assertSame( 1, $fired );
	}

	/**
	 * A refused card is not a finished order.
	 *
	 * The customer is still standing there and will try another card. The
	 * attempt belongs in the ledger; the booking is untouched and the seat is
	 * theirs until the hold runs out like anybody else's.
	 *
	 * @return void
	 */
	public function test_a_declined_card_leaves_the_booking_alone() {
		$event_id = $this->make_event();
		$booking  = $this->book( $event_id );

		Repository::update_status( $booking->id(), RegistrationStatus::Pending );

		$order_id = $this->an_order_for( $booking->id(), $event_id );

		\QuickEventsManager\Commerce\TransactionRepository::record(
			array(
				'order_id'     => $order_id,
				'kind'         => \QuickEventsManager\Domain\TransactionKind::Charge,
				'status'       => \QuickEventsManager\Domain\TransactionStatus::Failed,
				'amount_minor' => 2500,
				'currency'     => 'GBP',
				'gateway'      => 'test',
				'error_code'   => 'card_declined',
			)
		);

		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status() );
		$this->assertSame( OrderStatus::Pending, OrderRepository::find( $order_id )->status(), 'they can still try again' );
		$this->assertSame( 0, \QuickEventsManager\Commerce\TransactionRepository::net_for_order( $order_id ) );
	}

	/**
	 * An order the gateway says is finished gives its seat back at once.
	 *
	 * The first version left it `failed` with its hold intact — and the sweep
	 * only looks at pending orders, so that seat would have been held for
	 * ever. Exactly the bug this chunk exists to prevent, wearing a different
	 * status.
	 *
	 * @return void
	 */
	public function test_a_finished_order_releases_the_seat_immediately() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );
		$booking  = $this->book( $event_id );

		Repository::update_status( $booking->id(), RegistrationStatus::Pending );

		$order_id = $this->an_order_for( $booking->id(), $event_id );

		$this->assertTrue( OrderService::fail( $order_id, 'expired_authorisation' ) );

		$this->assertSame( OrderStatus::Failed, OrderRepository::find( $order_id )->status() );
		$this->assertSame( RegistrationStatus::Cancelled, Repository::find( $booking->id() )->status() );
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'the seat is back' );
		$this->assertNull( OrderRepository::find( $order_id )->hold_expires_utc(), 'and it is not waiting on a sweep' );
	}

	/**
	 * An order knows which booking it is for, and the booking knows too.
	 *
	 * @return void
	 */
	public function test_the_order_and_the_booking_find_each_other() {
		$event_id = $this->make_event();
		$booking  = $this->book( $event_id );

		$order_id = $this->an_order_for( $booking->id(), $event_id );

		$this->assertSame( $order_id, Repository::find( $booking->id() )->get( 'order_id' ) * 1 );
		$this->assertSame( $booking->id(), OrderService::booking_for( $order_id ) );
	}

	/**
	 * The hold is however long the site says.
	 *
	 * @return void
	 */
	public function test_the_hold_length_is_filterable() {
		$this->assertSame( 20, OrderService::hold_minutes() );

		add_filter( 'qevm_hold_minutes', static fn() => 45 );

		$this->assertSame( 45, OrderService::hold_minutes() );

		remove_all_filters( 'qevm_hold_minutes' );

		add_filter( 'qevm_hold_minutes', static fn() => 0 );

		$this->assertSame( 1, OrderService::hold_minutes(), 'a hold of no time at all is not a hold' );
	}

	/**
	 * Open an order for a booking.
	 *
	 * @param int $registration_id Booking.
	 * @param int $event_id        Event.
	 * @return int
	 */
	private function an_order_for( $registration_id, $event_id ): int {
		return OrderService::open(
			$registration_id,
			array(
				'billing_name'  => 'Ada Lovelace',
				'billing_email' => 'ada@example.com',
				'gateway'       => 'test',
				'lines'         => array(
					array(
						'event_id'         => $event_id,
						'name_snapshot'    => 'Standard',
						'event_snapshot'   => 'Integration event',
						'unit_price_minor' => 2500,
						'quantity'         => 1,
					),
				),
			)
		);
	}

	/**
	 * Put an order's hold in the past.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	private function expire( $order_id ) {
		OrderRepository::update(
			$order_id,
			array( 'hold_expires_utc' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) )
		);
	}
}
