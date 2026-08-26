<?php
/**
 * What Stripe posts to us, and what stops anybody else doing the same.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\Stripe\Gateway;
use QuickEventsManager\Commerce\Stripe\Keys;
use QuickEventsManager\Commerce\Stripe\Webhook;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Domain\TransactionStatus;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;

/**
 * The endpoint is public, so its defences are the whole of its security.
 *
 * A signature that is checked before the body is read, a timestamp that stops a
 * captured delivery being replayed next year, and a second delivery of the same
 * event doing nothing — which Stripe's own documentation says to expect rather
 * than to guard against as an edge case.
 */
final class StripeWebhookTest extends TestCase {

	/**
	 * The signing secret these tests sign with.
	 */
	const SECRET = 'whsec_test_secret';

	/**
	 * What the fake Stripe API answers with.
	 *
	 * @var mixed
	 */
	private $answer = null;

	/**
	 * Everything on, keys present, network cut, route registered.
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
				'publishable' => 'pk_test_abc',
				'secret'      => 'sk_test_abc',
				'webhook'     => self::SECRET,
			)
		);

		$this->answer = $this->intent( 'requires_payment_method' );

		add_filter( 'pre_http_request', fn() => $this->answer );

		do_action( 'rest_api_init' );
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
	 * A properly signed success confirms the booking.
	 *
	 * @return void
	 */
	public function test_a_signed_success_confirms_the_booking() {
		list( $booking, $order ) = $this->a_booking_being_paid();

		$this->answer = $this->intent( 'succeeded' );

		$response = $this->deliver( $this->event_body( 'payment_intent.succeeded' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['handled'] );

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
		$this->assertSame( OrderStatus::Paid, OrderRepository::find( $order->id() )->status() );
	}

	/**
	 * The same delivery twice does everything once.
	 *
	 * Stripe delivers repeats by design. A handler that acts on both confirms
	 * one booking twice and emails the person twice.
	 *
	 * @return void
	 */
	public function test_the_same_delivery_twice_only_counts_once() {
		list( $booking, $order ) = $this->a_booking_being_paid();

		$this->answer = $this->intent( 'succeeded' );

		$confirmed = 0;

		add_action(
			'qevm_booking_paid_for',
			static function () use ( &$confirmed ) {
				++$confirmed;
			}
		);

		$body = $this->event_body( 'payment_intent.succeeded' );

		$this->assertSame( 200, $this->deliver( $body )->get_status() );
		$this->assertSame( 200, $this->deliver( $body )->get_status(), 'a repeat is not an error' );

		$this->assertSame( 1, $confirmed, 'and it is not a second confirmation either' );
		$this->assertSame( 1, count( TransactionRepository::for_order( $order->id() ) ), 'one payment, one ledger row' );
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
	}

	/**
	 * An unsigned delivery is refused without being read.
	 *
	 * @return void
	 */
	public function test_an_unsigned_delivery_is_refused() {
		list( $booking ) = $this->a_booking_being_paid();

		$request = new \WP_REST_Request( 'POST', '/qevm/v1/stripe/webhook' );
		$request->set_body( $this->event_body( 'payment_intent.succeeded' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status(), 'nobody got a free place' );
	}

	/**
	 * A delivery signed with the wrong secret is refused.
	 *
	 * @return void
	 */
	public function test_a_forged_signature_is_refused() {
		list( $booking ) = $this->a_booking_being_paid();

		$body      = $this->event_body( 'payment_intent.succeeded' );
		$timestamp = time();
		$forged    = hash_hmac( 'sha256', $timestamp . '.' . $body, 'whsec_not_the_secret' );

		$response = $this->deliver( $body, 't=' . $timestamp . ',v1=' . $forged );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status() );
	}

	/**
	 * A genuine delivery captured and replayed later is refused.
	 *
	 * The signature stays valid for ever; the timestamp is what stops it being
	 * useful for ever.
	 *
	 * @return void
	 */
	public function test_an_old_delivery_is_refused() {
		list( $booking ) = $this->a_booking_being_paid();

		$body      = $this->event_body( 'payment_intent.succeeded' );
		$timestamp = time() - ( Webhook::TOLERANCE + 60 );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );

		$response = $this->deliver( $body, 't=' . $timestamp . ',v1=' . $signature );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status() );
	}

	/**
	 * A signed success for somebody else's intent does nothing here.
	 *
	 * @return void
	 */
	public function test_an_unknown_intent_is_ignored() {
		list( $booking ) = $this->a_booking_being_paid();

		$body = wp_json_encode(
			array(
				'type' => 'payment_intent.succeeded',
				'data' => array( 'object' => array( 'id' => 'pi_not_ours' ) ),
			)
		);

		$response = $this->deliver( $body );

		$this->assertSame( 200, $response->get_status(), 'not an error, just not ours' );
		$this->assertFalse( $response->get_data()['handled'] );
		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status() );
	}

	/**
	 * An event type this plugin does not care about is answered politely.
	 *
	 * Anything other than a 200 teaches Stripe to retry, and eventually to
	 * disable the endpoint — taking the events we do care about with it.
	 *
	 * @return void
	 */
	public function test_an_uninteresting_event_is_answered_with_200() {
		$this->a_booking_being_paid();

		$response = $this->deliver( $this->event_body( 'customer.subscription.updated' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['handled'] );
	}

	/**
	 * A failure is recorded against the attempt without touching the booking.
	 *
	 * @return void
	 */
	public function test_a_failed_payment_is_recorded_on_the_attempt() {
		list( $booking, $order ) = $this->a_booking_being_paid();

		$body = wp_json_encode(
			array(
				'type' => 'payment_intent.payment_failed',
				'data' => array(
					'object' => array(
						'id'                 => 'pi_test_1',
						'last_payment_error' => array( 'code' => 'card_declined' ),
					),
				),
			)
		);

		$this->assertSame( 200, $this->deliver( $body )->get_status() );

		$recorded = TransactionRepository::for_order( $order->id() )[0];

		$this->assertSame( TransactionStatus::Failed, $recorded->status() );
		$this->assertSame( 'card_declined', $recorded->error_code() );
		$this->assertSame( RegistrationStatus::Pending, Repository::find( $booking->id() )->status(), 'they can still try another card' );
	}

	/**
	 * A cancelled intent gives the seat back.
	 *
	 * @return void
	 */
	public function test_a_cancelled_payment_releases_the_seat() {
		list( $booking, $order ) = $this->a_booking_being_paid();

		$this->assertSame( 200, $this->deliver( $this->event_body( 'payment_intent.canceled' ) )->get_status() );

		$this->assertSame( OrderStatus::Failed, OrderRepository::find( $order->id() )->status() );
		$this->assertSame( RegistrationStatus::Cancelled, Repository::find( $booking->id() )->status() );
	}

	/**
	 * With no signing secret stored, nothing is accepted at all.
	 *
	 * @return void
	 */
	public function test_nothing_is_accepted_without_a_signing_secret() {
		Keys::forget();
		Keys::save(
			array(
				'publishable' => 'pk_test_abc',
				'secret'      => 'sk_test_abc',
			)
		);

		$response = $this->deliver( $this->event_body( 'payment_intent.succeeded' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The signature is compared in constant time.
	 *
	 * The one property here that behaviour cannot show: `===` and
	 * `hash_equals()` accept and reject exactly the same strings, and differ
	 * only in how long they take to say so. String comparison stops at the
	 * first byte that differs, and that timing is enough to recover a secret
	 * one byte at a time. Swapping the call for `===` passes every other test
	 * in this file, which is precisely why this one reads the source.
	 *
	 * @return void
	 */
	public function test_the_signature_is_compared_in_constant_time() {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/Commerce/Stripe/Webhook.php'
		);

		$this->assertStringContainsString( 'hash_equals(', $source );
		$this->assertDoesNotMatchRegularExpression(
			'/\$expected\s*===\s*\$candidate/',
			$source,
			'a plain comparison of the two signatures leaks the secret through timing'
		);
	}

	/**
	 * A booking part-way through paying.
	 *
	 * @return array{0: \QuickEventsManager\Registration\Registration, 1: \QuickEventsManager\Commerce\Order}
	 */
	private function a_booking_being_paid(): array {
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

		$order = OrderRepository::find( (int) Repository::find( $booking->id() )->get( 'order_id', 0 ) );

		( new Gateway() )->start( $order );

		return array( $booking, $order );
	}

	/**
	 * Post a delivery to the endpoint, signed unless told otherwise.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature Signature header, or '' to sign it properly.
	 * @return \WP_REST_Response
	 */
	private function deliver( string $body, string $signature = '' ) {
		if ( '' === $signature ) {
			$timestamp = time();
			$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );
		}

		$request = new \WP_REST_Request( 'POST', '/qevm/v1/stripe/webhook' );

		$request->set_header( 'Stripe-Signature', $signature );
		$request->set_body( $body );

		return rest_do_request( $request );
	}

	/**
	 * A Stripe event about our intent.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	private function event_body( string $type ): string {
		return (string) wp_json_encode(
			array(
				'id'   => 'evt_test_1',
				'type' => $type,
				'data' => array( 'object' => array( 'id' => 'pi_test_1' ) ),
			)
		);
	}

	/**
	 * What the API says when asked about the intent.
	 *
	 * @param string $status PaymentIntent status.
	 * @return array<string, mixed>
	 */
	private function intent( string $status ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'            => 'pi_test_1',
					'client_secret' => 'pi_test_1_secret',
					'status'        => $status,
				)
			),
		);
	}
}
