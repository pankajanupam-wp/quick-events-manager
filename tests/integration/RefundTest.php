<?php
/**
 * Money going back, and what it does to a place.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\Refunds;
use QuickEventsManager\Commerce\Stripe\Gateway;
use QuickEventsManager\Commerce\Stripe\Keys;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;

/**
 * A partial refund is not a cancellation, and a full one is.
 *
 * The distinction is the whole of this chunk. Somebody given £5 back off a £25
 * ticket is still coming, and taking their seat away would be a worse outcome
 * than the overcharge was.
 */
final class RefundTest extends TestCase {

	/**
	 * What the fake Stripe answers with next.
	 *
	 * @var mixed
	 */
	private $answer = null;

	/**
	 * Everything on, network cut.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_attendee_email', '__return_empty_array' );
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
				'publishable' => 'pk_test_a',
				'secret'      => 'sk_test_a',
			)
		);

		$this->answer = $this->stripe_says(
			array(
				'id'            => 'pi_test_1',
				'client_secret' => 's',
				'status'        => 'requires_payment_method',
			)
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
	 * Some of it back leaves the place alone.
	 *
	 * @return void
	 */
	public function test_a_partial_refund_keeps_the_place() {
		list( $event_id, $booking, $order ) = $this->a_paid_booking();

		$this->answer = $this->stripe_says( array( 'id' => 're_test_1' ) );

		$this->assertTrue( Refunds::issue( $order->id(), 500, 'Late arrival' ) );

		$after = OrderRepository::find( $order->id() );

		$this->assertSame( 500, $after->refunded_minor() );
		$this->assertSame( 2000, $after->net_minor() );
		$this->assertSame( OrderStatus::PartiallyRefunded, $after->status() );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status(), 'they are still coming' );
		$this->assertSame( 1, Repository::count_taken( $event_id ) );
	}

	/**
	 * All of it back frees the place.
	 *
	 * @return void
	 */
	public function test_a_full_refund_frees_the_place() {
		list( $event_id, $booking, $order ) = $this->a_paid_booking();

		$this->answer = $this->stripe_says( array( 'id' => 're_test_1' ) );

		$this->assertTrue( Refunds::issue( $order->id() ) );

		$this->assertSame( OrderStatus::Refunded, OrderRepository::find( $order->id() )->status() );
		$this->assertSame( RegistrationStatus::Cancelled, Repository::find( $booking->id() )->status() );
		$this->assertSame( 0, Repository::count_taken( $event_id ) );
	}

	/**
	 * The freed place reaches whoever was waiting.
	 *
	 * @return void
	 */
	public function test_a_full_refund_moves_the_waiting_list() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 2500,
			)
		);

		$type = TicketTypeRepository::for_event( $event_id )[0];

		$first = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $type->id(),
				'email'          => 'first@example.com',
			)
		);

		$order = $this->pay_for( $first );

		$waiting = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $type->id(),
				'email'          => 'second@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Waitlisted, Repository::find( $waiting->id() )->status() );

		$this->answer = $this->stripe_says( array( 'id' => 're_test_1' ) );

		Refunds::issue( $order->id() );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $waiting->id() )->status() );
	}

	/**
	 * Several partial refunds that add up to everything free the place.
	 *
	 * Whether the place goes back is decided from the ledger rather than from
	 * the size of the refund being made, so £5 five times means the same as £25
	 * once.
	 *
	 * @return void
	 */
	public function test_partial_refunds_that_add_up_free_the_place() {
		list( , $booking, $order ) = $this->a_paid_booking();

		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $index => $suffix ) {
			$this->answer = $this->stripe_says( array( 'id' => 're_test_' . $suffix ) );

			$this->assertTrue( Refunds::issue( $order->id(), 500 ), 'refund ' . ( $index + 1 ) );
		}

		$this->assertSame( OrderStatus::Refunded, OrderRepository::find( $order->id() )->status() );
		$this->assertSame( RegistrationStatus::Cancelled, Repository::find( $booking->id() )->status() );
	}

	/**
	 * More than is left is refused rather than quietly reduced.
	 *
	 * @return void
	 */
	public function test_refunding_more_than_is_left_is_refused() {
		list( , , $order ) = $this->a_paid_booking();

		$this->answer = $this->stripe_says( array( 'id' => 're_test_1' ) );

		Refunds::issue( $order->id(), 2000 );

		$again = Refunds::issue( $order->id(), 1000 );

		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 'qevm_refund_too_much', $again->get_error_code() );
		$this->assertSame( 2000, OrderRepository::find( $order->id() )->refunded_minor(), 'and nothing more went back' );
	}

	/**
	 * An order nobody paid for cannot be refunded.
	 *
	 * @return void
	 */
	public function test_an_unpaid_order_cannot_be_refunded() {
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
		$order   = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		$result = Refunds::issue( $order->id() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_refund_not_paid', $result->get_error_code() );
	}

	/**
	 * The gateway refusing means nothing is recorded.
	 *
	 * A ledger row for money that never moved is worse than no row: every
	 * report from then on is wrong, and the place would be freed for a refund
	 * the customer never received.
	 *
	 * @return void
	 */
	public function test_a_refused_refund_records_nothing() {
		list( , $booking, $order ) = $this->a_paid_booking();

		$this->answer = array(
			'response' => array( 'code' => 400 ),
			'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Charge already refunded.' ) ) ),
		);

		$result = Refunds::issue( $order->id() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 0, OrderRepository::find( $order->id() )->refunded_minor() );
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
	}

	/**
	 * The same refund reference twice is recorded once.
	 *
	 * @return void
	 */
	public function test_the_same_refund_reference_is_recorded_once() {
		list( , , $order ) = $this->a_paid_booking();

		$this->answer = $this->stripe_says( array( 'id' => 're_same' ) );

		$this->assertTrue( Refunds::issue( $order->id(), 500 ) );
		$this->assertTrue( Refunds::issue( $order->id(), 500 ), 'answered, not an error' );

		$this->assertSame( 500, OrderRepository::find( $order->id() )->refunded_minor(), 'but only once in the ledger' );
	}

	/**
	 * A paid booking, and the order that paid for it.
	 *
	 * @return array{0: int, 1: \QuickEventsManager\Registration\Registration, 2: \QuickEventsManager\Commerce\Order}
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

		$type    = TicketTypeRepository::for_event( $event_id )[0];
		$booking = $this->book( $event_id, array( 'ticket_type_id' => $type->id() ) );

		return array( $event_id, $booking, $this->pay_for( $booking ) );
	}

	/**
	 * Take the payment for a booking.
	 *
	 * @param \QuickEventsManager\Registration\Registration $booking The booking.
	 * @return \QuickEventsManager\Commerce\Order
	 */
	private function pay_for( $booking ) {
		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		( new Gateway() )->start( $order );

		$this->answer = $this->stripe_says(
			array(
				'id'     => 'pi_test_1',
				'status' => 'succeeded',
			)
		);

		\QuickEventsManager\Commerce\Checkout::settle_from_stripe( $order, 'pi_test_1' );

		return OrderRepository::find( $order->id() );
	}

	/**
	 * A believable answer from Stripe.
	 *
	 * @param array<string, mixed> $body What it says.
	 * @return array<string, mixed>
	 */
	private function stripe_says( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	}
}
