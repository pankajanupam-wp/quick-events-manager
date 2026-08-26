<?php
/**
 * The email queue and the worker that drains it.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\EmailStatus;
use QuickEventsManager\Email\Queue;
use QuickEventsManager\Email\Worker;

/**
 * Mail that must not be lost, and must not be sent twice.
 *
 * A queue is only worth having if both hold. Losing a confirmation leaves
 * somebody with a booking and no reference; sending it twice puts the same
 * reference in their inbox twice with no way to tell which is real. The tests
 * that matter here are the ones about claiming.
 */
final class EmailQueueTest extends TestCase {

	/**
	 * Stop anything actually leaving the machine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter( 'pre_wp_mail', array( $this, 'accept_mail' ), 10, 2 );
	}

	/**
	 * Addresses handed to wp_mail() during a test.
	 *
	 * @var string[]
	 */
	private $delivered = array();

	/**
	 * Addresses that should be refused.
	 *
	 * @var string[]
	 */
	private $refuse = array();

	/**
	 * Stand in for wp_mail(), recording what it was asked to send.
	 *
	 * @param null|bool            $short_circuit Whatever an earlier filter decided.
	 * @param array<string, mixed> $attributes    Mail attributes.
	 * @return bool
	 */
	public function accept_mail( $short_circuit, $attributes ) {
		unset( $short_circuit );

		$to = (array) ( $attributes['to'] ?? array() );

		foreach ( $to as $address ) {
			if ( in_array( $address, $this->refuse, true ) ) {
				return false;
			}

			$this->delivered[] = (string) $address;
		}

		return true;
	}

	/**
	 * A queued message is sent, once, and recorded.
	 *
	 * @return void
	 */
	public function test_a_queued_message_is_sent_and_recorded() {
		$id = $this->queue( 'one@example.com' );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( EmailStatus::Pending->value, $this->status_of( $id ) );

		$result = Worker::run( 5 );

		$this->assertSame( 1, $result['sent'] );
		$this->assertSame( array( 'one@example.com' ), $this->delivered );
		$this->assertSame( EmailStatus::Sent->value, $this->status_of( $id ) );
	}

	/**
	 * Running the worker twice does not send the same message twice.
	 *
	 * @return void
	 */
	public function test_a_second_run_does_not_resend() {
		$this->queue( 'one@example.com' );

		Worker::run( 5 );
		Worker::run( 5 );

		$this->assertSame( array( 'one@example.com' ), $this->delivered );
	}

	/**
	 * Two workers racing cannot both claim the same message.
	 *
	 * The property the whole design rests on. Claiming is an UPDATE with the
	 * status in its own WHERE clause, so MySQL applies it to a given row once —
	 * whoever else is looking at it. A plain SELECT here would hand both callers
	 * the same rows and send everything twice.
	 *
	 * @return void
	 */
	public function test_two_claims_never_take_the_same_message() {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->queue( 'racer' . $i . '@example.com' );
		}

		$first  = Queue::claim( 10 );
		$second = Queue::claim( 10 );

		$this->assertCount( 10, $first );
		$this->assertCount( 0, $second, 'a second claim took messages the first had already taken' );

		$ids = array_merge(
			array_map( static fn ( $row ) => (int) $row['id'], $first ),
			array_map( static fn ( $row ) => (int) $row['id'], $second )
		);

		$this->assertSame( count( $ids ), count( array_unique( $ids ) ) );
	}

	/**
	 * A failed send is retried rather than lost, and backed off.
	 *
	 * @return void
	 */
	public function test_a_failure_is_retried_later() {
		$this->refuse = array( 'broken@example.com' );

		$id = $this->queue( 'broken@example.com' );

		$result = Worker::run( 5 );

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( EmailStatus::Pending->value, $this->status_of( $id ), 'a first failure gave up immediately' );

		$row = $this->row( $id );

		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertNotSame( '', (string) $row['last_error'], 'nothing was recorded about why it failed' );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $row['scheduled_for'], 'the retry was not backed off' );
	}

	/**
	 * A message is given up on after the last attempt, and says why.
	 *
	 * @return void
	 */
	public function test_a_message_is_given_up_on_after_the_last_attempt() {
		$this->refuse = array( 'broken@example.com' );

		$id = $this->queue( 'broken@example.com' );

		for ( $attempt = 0; $attempt < Queue::MAX_ATTEMPTS; $attempt++ ) {
			$this->make_due( $id );

			Worker::run( 5 );
		}

		$row = $this->row( $id );

		$this->assertSame( EmailStatus::Failed->value, (string) $row['status'] );
		$this->assertSame( Queue::MAX_ATTEMPTS, (int) $row['attempts'] );
		$this->assertNotSame( '', (string) $row['last_error'] );
	}

	/**
	 * One bad address does not stop the rest of the batch.
	 *
	 * The reason there is a row per recipient rather than one row with a list
	 * of addresses on it.
	 *
	 * @return void
	 */
	public function test_one_bad_address_does_not_stop_the_others() {
		$this->refuse = array( 'broken@example.com' );

		$this->queue( 'first@example.com' );
		$this->queue( 'broken@example.com' );
		$this->queue( 'last@example.com' );

		$result = Worker::run( 5 );

		$this->assertSame( 2, $result['sent'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertContains( 'first@example.com', $this->delivered );
		$this->assertContains( 'last@example.com', $this->delivered );
	}

	/**
	 * A message scheduled for later is left alone until then.
	 *
	 * @return void
	 */
	public function test_a_future_message_is_not_sent_yet() {
		$id = $this->queue( 'later@example.com', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		Worker::run( 5 );

		$this->assertSame( array(), $this->delivered );
		$this->assertSame( EmailStatus::Pending->value, $this->status_of( $id ) );
	}

	/**
	 * A message abandoned by a dead worker is picked up again.
	 *
	 * A worker that dies mid-send leaves its row claimed. Without this it would
	 * sit in `sending` for ever and nobody would ever be told.
	 *
	 * @return void
	 */
	public function test_an_abandoned_message_is_recovered() {
		global $wpdb;

		$id = $this->queue( 'stuck@example.com' );

		Queue::claim( 10 );

		$this->assertSame( EmailStatus::Sending->value, $this->status_of( $id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture standing in for a worker that died.
		$wpdb->update(
			Queue::table(),
			array( 'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		Worker::run( 5 );

		$this->assertSame( array( 'stuck@example.com' ), $this->delivered );
		$this->assertSame( EmailStatus::Sent->value, $this->status_of( $id ) );
	}

	/**
	 * Everything queued against something can be withdrawn together.
	 *
	 * @return void
	 */
	public function test_queued_mail_can_be_cancelled_by_context() {
		$this->queue( 'one@example.com', '', 'registration', 42 );
		$this->queue( 'two@example.com', '', 'registration', 42 );
		$this->queue( 'three@example.com', '', 'registration', 99 );

		$this->assertSame( 2, Queue::cancel_for_context( 'registration', 42 ) );

		Worker::run( 5 );

		$this->assertSame( array( 'three@example.com' ), $this->delivered );
	}

	/**
	 * Five hundred recipients queue without sending anything yet.
	 *
	 * Half of the Stage 5 gate: the request that triggers the mail must return
	 * rather than spending itself on five hundred SMTP conversations.
	 *
	 * @return void
	 */
	public function test_five_hundred_recipients_queue_without_sending() {
		for ( $i = 0; $i < 500; $i++ ) {
			$this->queue( 'bulk' . $i . '@example.com' );
		}

		$this->assertSame( array(), $this->delivered, 'queueing sent mail inside the request' );
		$this->assertSame( 500, $this->count_rows( 'email_queue' ) );

		$counts = Queue::counts();

		$this->assertSame( 500, $counts[ EmailStatus::Pending->value ] );
	}

	/**
	 * Add a message to the queue.
	 *
	 * @param string $recipient Address.
	 * @param string $when      Scheduled time, or '' for now.
	 * @param string $type      Context type.
	 * @param int    $id        Context id.
	 * @return int
	 */
	private function queue( $recipient, $when = '', $type = '', $id = 0 ) {
		return Queue::add(
			array(
				'template'      => 'test',
				'recipient'     => $recipient,
				'subject'       => 'Subject',
				'body'          => 'Body',
				'context_type'  => $type,
				'context_id'    => $id,
				'scheduled_for' => '' !== $when ? $when : gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Bring a message's next attempt forward to now.
	 *
	 * @param int $id Queue id.
	 * @return void
	 */
	private function make_due( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture skipping the backoff wait.
		$wpdb->update(
			Queue::table(),
			array( 'scheduled_for' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * One queue row.
	 *
	 * @param int $id Queue id.
	 * @return array<string, mixed>
	 */
	private function row( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the row the test just acted on.
		return (array) $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Queue::table(), (int) $id ),
			ARRAY_A
		);
	}

	/**
	 * One message's status.
	 *
	 * @param int $id Queue id.
	 * @return string
	 */
	private function status_of( $id ) {
		$row = $this->row( $id );

		return isset( $row['status'] ) ? (string) $row['status'] : '';
	}
}
