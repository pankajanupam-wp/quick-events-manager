<?php
/**
 * A paid booking, from the form to a confirmed place.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Commerce\Checkout;
use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Commerce\Holds;
use QuickEventsManager\Commerce\OrderItemRepository;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\Stripe\Keys;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;

/**
 * The whole journey, with Stripe replaced by a fake at the transport.
 *
 * The interesting states are the ones in between: a booking that holds a seat
 * and is not confirmed, a confirmation email that has not gone out yet, and a
 * seat that comes back if nobody pays.
 */
final class CheckoutTest extends TestCase {

	/**
	 * What the fake Stripe answers with next.
	 *
	 * @var mixed
	 */
	private $answer = null;

	/**
	 * Everything on, keys present, network cut.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		/*
		 * Not `quieten_registration()`: that empties the message arrays, so
		 * nothing ever reaches the queue and "was a confirmation sent" cannot
		 * be asked. The rate limit still goes, and the organiser's copy is
		 * silenced so the count is attendee messages only.
		 */
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$this->switch_module_on( TicketsModule::ID );
		$this->switch_module_on( CommerceModule::ID );

		if ( ! TicketTypeRepository::table_exists() || ! OrderRepository::table_exists() ) {
			( new TicketsModule() )->activate();
			( new CommerceModule() )->activate();

			$this->restore_schema();
		}

		( new TicketsModule() )->register();
		( new CommerceModule() )->register();

		Keys::save(
			array(
				'publishable' => 'pk_test_abc',
				'secret'      => 'sk_test_abc',
			)
		);

		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'            => 'pi_test_1',
					'client_secret' => 'pi_test_1_secret',
					'status'        => 'requires_payment_method',
				)
			),
		);

		add_filter( 'pre_http_request', fn() => $this->answer );
	}

	/**
	 * Put the keys back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Keys::forget();

		parent::tearDown();
	}

	/**
	 * A booking for a priced ticket holds its seat and is not confirmed.
	 *
	 * @return void
	 */
	public function test_a_paid_booking_holds_its_seat_without_being_confirmed() {
		list( $event_id, $booking ) = $this->a_paid_booking();

		$stored = Repository::find( $booking->id() );

		$this->assertSame( RegistrationStatus::Pending, $stored->status(), 'not theirs until they pay' );
		$this->assertSame( 1, Repository::count_taken( $event_id ), 'and nobody else can have the seat meanwhile' );

		$order = OrderRepository::find( (int) $stored->get( 'order_id', 0 ) );

		$this->assertNotNull( $order );
		$this->assertSame( OrderStatus::Pending, $order->status() );
		$this->assertSame( 2500, $order->total_minor(), 'the price at the moment of booking' );
		$this->assertNotNull( $order->hold_expires_utc(), 'and it is holding against a clock' );
	}

	/**
	 * The line keeps what the ticket was called and what it cost.
	 *
	 * @return void
	 */
	public function test_the_line_is_a_snapshot_of_the_ticket() {
		list( , $booking ) = $this->a_paid_booking();

		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );
		$lines = OrderItemRepository::for_order( $order->id() );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Member', $lines[0]->name() );
		$this->assertSame( 2500, $lines[0]->unit_price_minor() );
		$this->assertSame( 'Integration event', $lines[0]->event_name() );
	}

	/**
	 * Nobody is told their place is confirmed before they have paid.
	 *
	 * The message a free booking gets at once, a paid one gets when the money
	 * arrives. Sending it earlier is a promise the plugin cannot keep.
	 *
	 * @return void
	 */
	public function test_no_confirmation_goes_out_before_payment() {
		list( , $booking ) = $this->a_paid_booking();

		$this->assertSame( 0, $this->queued(), 'nothing yet' );

		$this->pay( $booking );

		$this->assertGreaterThan( 0, $this->queued(), 'and now they are told' );
	}

	/**
	 * Paying confirms the place.
	 *
	 * @return void
	 */
	public function test_paying_confirms_the_booking() {
		list( , $booking ) = $this->a_paid_booking();

		$this->pay( $booking );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );

		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		$this->assertSame( OrderStatus::Paid, $order->status() );
		$this->assertSame( 2500, TransactionRepository::net_for_order( $order->id() ), 'the money is in the ledger' );
	}

	/**
	 * Walking away gives the seat back.
	 *
	 * @return void
	 */
	public function test_walking_away_gives_the_seat_back() {
		list( $event_id, $booking ) = $this->a_paid_booking();

		$order_id = (int) Repository::find( $booking->id() )->get( 'order_id', 0 );

		OrderRepository::update( $order_id, array( 'hold_expires_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );

		$this->assertSame( 1, Holds::run() );

		$this->assertSame( RegistrationStatus::Cancelled, Repository::find( $booking->id() )->status() );
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'somebody else can have it now' );
	}

	/**
	 * A free ticket never reaches a payment screen.
	 *
	 * @return void
	 */
	public function test_a_free_ticket_is_confirmed_at_once() {
		$event_id = $this->make_event();

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Free',
				'price_minor' => 0,
			)
		);
		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Supporter',
				'price_minor' => 1000,
			)
		);

		$types   = TicketTypeRepository::for_event( $event_id );
		$booking = $this->book( $event_id, array( 'ticket_type_id' => $types[0]->id() ) );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
		$this->assertSame( 0, (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );
	}

	/**
	 * Somebody on the waiting list is not asked for money.
	 *
	 * The worst thing this screen could do: charge for a place that may never
	 * exist.
	 *
	 * @return void
	 */
	public function test_the_waiting_list_is_not_asked_to_pay() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 2500,
			)
		);

		$type = TicketTypeRepository::for_event( $event_id )[0];

		$this->book(
			$event_id,
			array(
				'ticket_type_id' => $type->id(),
				'email'          => 'first@example.com',
			)
		);
		$waiting = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $type->id(),
				'email'          => 'second@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Waitlisted, Repository::find( $waiting->id() )->status() );
		$this->assertSame( 0, (int) Repository::find( $waiting->id() )->get( 'order_id', 0 ) );
	}

	/**
	 * With no gateway configured, nothing is held for payment.
	 *
	 * A site that has switched paid tickets on and not finished setting them up
	 * must not leave every booking stuck at pending, waiting for a payment
	 * screen that cannot appear.
	 *
	 * @return void
	 */
	public function test_without_keys_a_booking_is_confirmed_as_before() {
		Keys::forget();

		$event_id = $this->make_event();

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 2500,
			)
		);

		$type    = TicketTypeRepository::for_event( $event_id )[0];
		$booking = $this->book( $event_id, array( 'ticket_type_id' => $type->id() ) );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
	}

	/**
	 * The browser saying "paid" is not evidence that anybody paid.
	 *
	 * Anybody can type a return URL. The return handler asks Stripe and
	 * believes that.
	 *
	 * @return void
	 */
	public function test_a_return_is_verified_with_stripe_not_believed() {
		list( , $booking ) = $this->a_paid_booking();

		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		$this->start_payment( $order );

		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'                 => 'pi_test_1',
					'status'             => 'requires_payment_method',
					'last_payment_error' => array( 'code' => 'card_declined' ),
				)
			),
		);

		$this->assertFalse( Checkout::settle_from_stripe( $order, 'pi_test_1' ) );

		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status() );
		$this->assertSame( OrderStatus::Pending, OrderRepository::find( $order->id() )->status() );
	}

	/**
	 * An intent belonging to somebody else's order settles nothing.
	 *
	 * @return void
	 */
	public function test_an_intent_from_another_order_is_refused() {
		list( , $first ) = $this->a_paid_booking();

		$order = OrderRepository::find( (int) Repository::find( $first->id() )->get( 'order_id', 0 ) );

		/*
		 * With a payment already started, so the order *has* a pending charge
		 * for a foreign intent to be mistaken for. Without that this asserted
		 * nothing: there was no row to match either way, and a version that
		 * settled the order's own transaction regardless of the intent it was
		 * handed passed it.
		 */
		$this->start_payment( $order );

		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'     => 'pi_somebody_else',
					'status' => 'succeeded',
				)
			),
		);

		$this->assertFalse( Checkout::settle_from_stripe( $order, 'pi_somebody_else' ) );
		$this->assertSame( OrderStatus::Pending, OrderRepository::find( $order->id() )->status() );
		$this->assertSame( RegistrationStatus::Pending, Repository::find( $first->id() )->status() );
	}

	/**
	 * An event with a priced ticket, booked.
	 *
	 * @return array{0: int, 1: \QuickEventsManager\Registration\Registration}
	 */
	private function a_paid_booking(): array {
		$event_id = $this->make_event();

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 2500,
			)
		);

		$type = TicketTypeRepository::for_event( $event_id )[0];

		return array( $event_id, $this->book( $event_id, array( 'ticket_type_id' => $type->id() ) ) );
	}

	/**
	 * Take the payment.
	 *
	 * @param \QuickEventsManager\Registration\Registration $booking The booking.
	 * @return void
	 */
	private function pay( $booking ) {
		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		$this->start_payment( $order );

		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'     => 'pi_test_1',
					'status' => 'succeeded',
				)
			),
		);

		Checkout::settle_from_stripe( $order, 'pi_test_1' );
	}

	/**
	 * Ask the gateway to start, which is what records the attempt.
	 *
	 * @param \QuickEventsManager\Commerce\Order $order The order.
	 * @return void
	 */
	private function start_payment( $order ) {
		( new \QuickEventsManager\Commerce\Stripe\Gateway() )->start( $order );
	}

	/**
	 * How many messages are sitting on the queue.
	 *
	 * @return int
	 */
	private function queued(): int {
		return $this->count_rows( 'email_queue' );
	}
}
