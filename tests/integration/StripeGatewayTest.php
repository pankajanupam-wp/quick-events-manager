<?php
/**
 * Talking to Stripe, without talking to Stripe.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Commerce\CommerceModule;
use QuickEventsManager\Commerce\Gateways;
use QuickEventsManager\Commerce\OrderRepository;
use QuickEventsManager\Commerce\Stripe\Gateway;
use QuickEventsManager\Commerce\Stripe\Keys;
use QuickEventsManager\Commerce\TransactionRepository;
use QuickEventsManager\Domain\TransactionStatus;

/**
 * Every request is intercepted at `pre_http_request`.
 *
 * **Nothing here proves the plugin can talk to the real Stripe.** It proves what
 * is sent, what is done with each kind of answer, and that a retry cannot charge
 * twice — which is the part that is ours to get right. Whether Stripe accepts it
 * is a question for a live key against their test mode, and that is a manual
 * check before release rather than something a suite can assert.
 *
 * Faking at `pre_http_request` rather than wrapping the client in an injectable
 * transport: the fake then sits exactly where a real request would leave the
 * process, so the assertions are about the actual arguments `wp_remote_post()`
 * receives.
 */
final class StripeGatewayTest extends TestCase {

	/**
	 * The requests the fake intercepted.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $sent = array();

	/**
	 * What the fake should answer with next.
	 *
	 * @var mixed
	 */
	private $answer = null;

	/**
	 * Commerce on, keys present, network cut.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->switch_module_on( CommerceModule::ID );

		if ( ! OrderRepository::table_exists() ) {
			( new CommerceModule() )->activate();

			$this->restore_schema();
		}

		Keys::save(
			array(
				'publishable' => 'pk_test_abc123',
				'secret'      => 'sk_test_abc123',
			)
		);

		$this->sent   = array();
		$this->answer = $this->intent_response();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->sent[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return $this->answer;
			},
			10,
			3
		);
	}

	/**
	 * Put the keys back the way the site had them.
	 *
	 * The option is written outside the transaction's reach in the same way the
	 * module option is, so it is cleared rather than left for the next test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Keys::forget();

		parent::tearDown();
	}

	/**
	 * The gateway is offered, and only when it has what it needs.
	 *
	 * @return void
	 */
	public function test_it_is_offered_only_when_configured() {
		( new CommerceModule() )->register();

		$this->assertArrayHasKey( Gateway::ID, Gateways::all() );
		$this->assertTrue( Gateways::any_usable() );

		Keys::forget();

		$this->assertArrayHasKey( Gateway::ID, Gateways::all(), 'still registered' );
		$this->assertFalse( Gateways::any_usable(), 'and not offered to anybody' );
	}

	/**
	 * A test key and a live key together is not a working configuration.
	 *
	 * It fails at the last step, in front of a customer, with a message about
	 * an unknown key. Counting it as unconfigured is the kinder answer.
	 *
	 * @return void
	 */
	public function test_a_mismatched_pair_counts_as_unconfigured() {
		Keys::forget();
		Keys::save(
			array(
				'publishable' => 'pk_test_abc',
				'secret'      => 'sk_live_abc',
			)
		);

		$this->assertFalse( ( new Gateway() )->is_configured() );
	}

	/**
	 * Starting a payment sends the order's own figures.
	 *
	 * @return void
	 */
	public function test_it_asks_stripe_for_an_intent() {
		$order = $this->an_order( 4999 );

		$result = ( new Gateway() )->start( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 'pi_test_1', $result['intent'] );
		$this->assertSame( 'pi_test_1_secret_xyz', $result['client_secret'] );
		$this->assertSame( 'pk_test_abc123', $result['publishable'] );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'https://api.stripe.com/v1/payment_intents', $this->sent[0]['url'] );

		$body = $this->sent[0]['args']['body'];

		$this->assertSame( '4999', $body['amount'], 'minor units, exactly as stored' );
		$this->assertSame( 'gbp', $body['currency'], 'Stripe wants it lower case' );
		$this->assertSame( $order->number(), $body['metadata[qevm_order]'], 'nested fields are bracketed' );

		$this->assertSame(
			'Bearer sk_test_abc123',
			$this->sent[0]['args']['headers']['Authorization']
		);
	}

	/**
	 * The attempt is recorded before the customer goes anywhere.
	 *
	 * If the browser closes on the payment screen, the webhook that arrives
	 * later needs a row to find. Written afterwards, a paid intent would have
	 * nothing pointing at it.
	 *
	 * @return void
	 */
	public function test_the_attempt_is_recorded_with_the_intent_id() {
		$order = $this->an_order();

		( new Gateway() )->start( $order );

		$recorded = TransactionRepository::for_order( $order->id() );

		$this->assertCount( 1, $recorded );
		$this->assertSame( 'pi_test_1', $recorded[0]->gateway_txn_id() );
		$this->assertSame( TransactionStatus::Pending, $recorded[0]->status(), 'asked for, not yet paid' );
		$this->assertSame( 0, TransactionRepository::net_for_order( $order->id() ), 'and worth nothing until it succeeds' );
	}

	/**
	 * The same order retried carries the same idempotency key.
	 *
	 * The one thing standing between a timeout on our side and charging
	 * somebody twice.
	 *
	 * @return void
	 */
	public function test_a_retry_carries_the_same_idempotency_key() {
		$order = $this->an_order();

		( new Gateway() )->start( $order );
		( new Gateway() )->start( $order );

		$this->assertCount( 2, $this->sent );

		$first  = $this->sent[0]['args']['headers']['Idempotency-Key'];
		$second = $this->sent[1]['args']['headers']['Idempotency-Key'];

		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $second, 'Stripe returns the first result rather than charging again' );
	}

	/**
	 * Two different orders do not share a key.
	 *
	 * @return void
	 */
	public function test_two_orders_have_different_keys() {
		( new Gateway() )->start( $this->an_order() );
		( new Gateway() )->start( $this->an_order() );

		$this->assertNotSame(
			$this->sent[0]['args']['headers']['Idempotency-Key'],
			$this->sent[1]['args']['headers']['Idempotency-Key']
		);
	}

	/**
	 * Stripe refusing is passed on in words a customer can read.
	 *
	 * @return void
	 */
	public function test_a_refusal_comes_back_as_an_error() {
		$this->answer = array(
			'response' => array( 'code' => 402 ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'message' => 'Your card was declined.',
						'code'    => 'card_declined',
						'type'    => 'card_error',
					),
				)
			),
		);

		$result = ( new Gateway() )->start( $this->an_order() );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_stripe_refused', $result->get_error_code() );
		$this->assertSame( 'Your card was declined.', $result->get_error_message() );
	}

	/**
	 * A network failure is not a payment failure.
	 *
	 * @return void
	 */
	public function test_an_unreachable_stripe_is_told_apart_from_a_refusal() {
		$this->answer = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$result = ( new Gateway() )->start( $this->an_order() );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_stripe_unreachable', $result->get_error_code() );
		$this->assertStringNotContainsString( 'cURL', $result->get_error_message(), 'the customer gets words, not a transport error' );
	}

	/**
	 * Something that is not JSON does not become a fatal three lines later.
	 *
	 * @return void
	 */
	public function test_an_unreadable_answer_is_an_error() {
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html>maintenance</html>',
		);

		$result = ( new Gateway() )->start( $this->an_order() );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_stripe_unreadable', $result->get_error_code() );
	}

	/**
	 * Nothing is sent at all when there is nothing to pay.
	 *
	 * @return void
	 */
	public function test_a_free_order_is_not_sent_to_stripe() {
		$result = ( new Gateway() )->start( $this->an_order( 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->sent, 'no request was made' );
	}

	/**
	 * With no keys, nothing leaves the site.
	 *
	 * @return void
	 */
	public function test_nothing_is_sent_without_keys() {
		Keys::forget();

		$result = ( new Gateway() )->start( $this->an_order() );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->sent );
	}

	/**
	 * An order to pay for.
	 *
	 * @param int $total Total in minor units.
	 * @return \QuickEventsManager\Commerce\Order
	 */
	private function an_order( int $total = 2500 ) {
		$id = OrderRepository::create(
			array(
				'currency'       => 'GBP',
				'subtotal_minor' => $total,
				'total_minor'    => $total,
				'billing_name'   => 'Ada Lovelace',
				'billing_email'  => 'ada@example.com',
				'gateway'        => Gateway::ID,
			)
		);

		return OrderRepository::find( $id );
	}

	/**
	 * A believable PaymentIntent.
	 *
	 * @return array<string, mixed>
	 */
	private function intent_response(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'            => 'pi_test_1',
					'client_secret' => 'pi_test_1_secret_xyz',
					'status'        => 'requires_payment_method',
				)
			),
		);
	}

	/**
	 * Assert a WP_Error came back.
	 *
	 * @param mixed $value What was returned.
	 * @return void
	 */
	private function assertWPError( $value ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches PHPUnit's own assertion naming.
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
