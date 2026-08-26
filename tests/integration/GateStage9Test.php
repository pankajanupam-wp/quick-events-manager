<?php
/**
 * The stage 9 gate, run as a test rather than a script.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Commerce\Checkout;
use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Commerce\Holds;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\Refunds;
use QuickEventsManager\Commerce\Stripe\Gateway;
use QuickEventsManager\Commerce\Stripe\Keys;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;
use QuickEventsManager\Woo\Orders;
use QuickEventsManager\Woo\Products;
use QuickEventsManager\Woo\WooModule;

/**
 * The five things stage 9 said had to be true before it was finished.
 *
 * Written as a test rather than as a throwaway script for one reason: the last
 * four gates were scripts, and each of them had to be rewritten mid-run because
 * a criterion turned out to prove nothing. A gate that lives in the suite is one
 * that keeps being true, and one whose own mistakes surface on the next run
 * rather than never.
 */
final class GateStage9Test extends TestCase {

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
				'publishable' => 'pk_test_gate',
				'secret'      => 'sk_test_gate',
				'webhook'     => 'whsec_gate',
			)
		);

		$this->answer = $this->says(
			array(
				'id'            => 'pi_gate',
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
	 * One: an abandoned checkout releases its seat.
	 *
	 * @return void
	 */
	public function test_gate_1_an_abandoned_checkout_releases_its_seat() {
		list( $event_id, $booking ) = $this->a_paid_booking( 1 );

		$order_id = (int) Repository::find( $booking->id() )->get( 'order_id', 0 );

		$this->assertSame( 1, Repository::count_taken( $event_id ), 'held while they pay' );

		OrderRepository::update( $order_id, array( 'hold_expires_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );

		$this->assertSame( 1, Holds::run() );
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'and back when they do not' );
	}

	/**
	 * Two: the same webhook twice creates one registration.
	 *
	 * @return void
	 */
	public function test_gate_2_a_repeated_webhook_creates_one_registration() {
		list( $event_id, $booking ) = $this->a_paid_booking();

		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		( new Gateway() )->start( $order );

		$this->answer = $this->says(
			array(
				'id'     => 'pi_gate',
				'status' => 'succeeded',
			)
		);

		$body = (string) wp_json_encode(
			array(
				'type' => 'payment_intent.succeeded',
				'data' => array( 'object' => array( 'id' => 'pi_gate' ) ),
			)
		);

		do_action( 'rest_api_init' );

		$this->assertSame( 200, $this->deliver( $body )->get_status() );
		$this->assertSame( 200, $this->deliver( $body )->get_status() );

		$this->assertCount( 1, Repository::for_event( $event_id ), 'one payment, one place' );
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
		$this->assertCount( 1, TransactionRepository::for_order( $order->id() ), 'and one line in the ledger' );
	}

	/**
	 * Three: a full refund frees capacity and promotes the waiting list; a partial one does not.
	 *
	 * @return void
	 */
	public function test_gate_3_only_a_full_refund_frees_the_place() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$type_id = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 2500,
			)
		);

		$first = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $type_id,
				'email'          => 'first@example.com',
			)
		);
		$order = $this->pay_for( $first );

		$waiting = $this->book(
			$event_id,
			array(
				'ticket_type_id' => $type_id,
				'email'          => 'second@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Waitlisted, Repository::find( $waiting->id() )->status() );

		$this->answer = $this->says( array( 'id' => 're_part' ) );

		Refunds::issue( $order->id(), 500 );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $first->id() )->status(), 'still coming' );
		$this->assertSame( RegistrationStatus::Waitlisted, Repository::find( $waiting->id() )->status(), 'nobody promoted' );

		$this->answer = $this->says( array( 'id' => 're_rest' ) );

		Refunds::issue( $order->id() );

		$this->assertSame( RegistrationStatus::Cancelled, Repository::find( $first->id() )->status() );
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $waiting->id() )->status(), 'and the place moved on' );
	}

	/**
	 * Four: an event sells and refunds end to end through WooCommerce.
	 *
	 * @return void
	 */
	public function test_gate_4_woocommerce_sells_and_refunds() {
		if ( ! WooModule::woo_is_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		$this->switch_module_on( WooModule::ID );

		( new WooModule() )->register();

		$event_id = $this->make_event();
		$type_id  = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 2500,
			)
		);

		$product_id = Products::sync( $type_id, $event_id );

		$this->assertGreaterThan( 0, $product_id );

		$order = wc_create_order();

		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_billing_first_name( 'Grace' );
		$order->set_billing_last_name( 'Hopper' );
		$order->set_billing_email( 'grace@example.com' );
		$order->calculate_totals();
		$order->save();

		Orders::book( $order->get_id() );

		$this->assertSame( 1, Repository::count_taken( $event_id ), 'sold' );

		Orders::release( $order->get_id() );

		$this->assertSame( 0, Repository::count_taken( $event_id ), 'refunded' );
	}

	/**
	 * Five: no card data touches the plugin.
	 *
	 * Read from the source, because the only way to prove a thing never happens
	 * is to show the code that would do it does not exist. Behaviour can show a
	 * card number is not stored today; it cannot show one is never handled.
	 *
	 * @return void
	 */
	public function test_gate_5_no_card_data_touches_the_plugin() {
		$root  = dirname( __DIR__, 2 );
		$files = array();

		$directory = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes' ) );

		foreach ( $directory as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$files[] = $root . '/assets/js/checkout.js';

		$forbidden = array( 'card_number', 'cardNumber', 'cvc', 'cvv', 'expiry_month', 'exp_month' );

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );

			foreach ( $forbidden as $term ) {
				$this->assertStringNotContainsString(
					$term,
					$source,
					basename( $file ) . ' should never name a card field'
				);
			}
		}

		$this->assertGreaterThan( 50, count( $files ), 'the sweep actually read the codebase' );
	}

	/**
	 * A booking waiting to be paid for.
	 *
	 * @param int $capacity Event capacity.
	 * @return array{0: int, 1: \QuickEventsManager\Registration\Registration}
	 */
	private function a_paid_booking( int $capacity = 0 ): array {
		$event_id = $this->make_event( array( 'capacity' => $capacity ) );
		$type_id  = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 2500,
			)
		);

		return array( $event_id, $this->book( $event_id, array( 'ticket_type_id' => $type_id ) ) );
	}

	/**
	 * Pay for a booking.
	 *
	 * @param \QuickEventsManager\Registration\Registration $booking The booking.
	 * @return \QuickEventsManager\Commerce\Order
	 */
	private function pay_for( $booking ) {
		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		( new Gateway() )->start( $order );

		$this->answer = $this->says(
			array(
				'id'     => 'pi_gate',
				'status' => 'succeeded',
			)
		);

		Checkout::settle_from_stripe( $order, 'pi_gate' );

		return OrderRepository::find( $order->id() );
	}

	/**
	 * Post a signed delivery.
	 *
	 * @param string $body Raw body.
	 * @return \WP_REST_Response
	 */
	private function deliver( string $body ) {
		$timestamp = time();

		$request = new \WP_REST_Request( 'POST', '/qevm/v1/stripe/webhook' );

		$request->set_header( 'Stripe-Signature', 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, 'whsec_gate' ) );
		$request->set_body( $body );

		return rest_do_request( $request );
	}

	/**
	 * A believable answer from Stripe.
	 *
	 * @param array<string, mixed> $body What it says.
	 * @return array<string, mixed>
	 */
	private function says( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	}
}
