<?php
/**
 * The form that emails everybody, and what it reports afterwards.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

use QuickEventsManager\Domain\EmailStatus;
use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

/**
 * A compose box on the attendee screen, and the delivery record under it.
 *
 * The delivery record is not a nicety. A queue that hides its failures is worse
 * than sending synchronously, because at least a synchronous send fails in
 * front of the person who pressed the button. Here the message goes away, the
 * page says "queued", and four of them bounce twenty minutes later with nobody
 * watching. So the same screen that sends shows what happened: how many went,
 * how many are waiting, and — by name and address — which ones were given up
 * on.
 *
 * @since 26.0
 */
final class BroadcastForm {

	/**
	 * Action and nonce for sending.
	 */
	const SEND_ACTION = 'qevm_send_broadcast';

	/**
	 * Action and nonce for withdrawing what has not gone yet.
	 */
	const WITHDRAW_ACTION = 'qevm_withdraw_broadcast';

	/**
	 * Failed recipients listed at once.
	 */
	const FAILURES_SHOWN = 20;

	/**
	 * Hook the handlers up.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::SEND_ACTION, array( $this, 'handle_send' ) );
		add_action( 'admin_post_' . self::WITHDRAW_ACTION, array( $this, 'handle_withdraw' ) );
	}

	/**
	 * The compose box.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event being emailed about.
	 * @return void
	 */
	public function render( Event $event ) {
		$counts = array();

		foreach ( Broadcast::audiences() as $key => $audience ) {
			$counts[ $key ] = Broadcast::count( $event->id(), $key );
		}

		$default = 'confirmed';
		?>
		<div class="qevm-broadcast">
			<h2><?php esc_html_e( 'Email everybody', 'quick-events-manager' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'One message to everyone in the audience you choose below. The search and status filters further up this page do not affect who it goes to.', 'quick-events-manager' ); ?>
			</p>

			<p class="description">
				<?php esc_html_e( 'Each person is emailed once, however many bookings they made. Guests booked by somebody else are reached through whoever booked, because that is the only address on the booking.', 'quick-events-manager' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SEND_ACTION ); ?>" />
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />
				<?php wp_nonce_field( self::SEND_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="qevm-broadcast-audience"><?php esc_html_e( 'Who it goes to', 'quick-events-manager' ); ?></label>
						</th>
						<td>
							<select id="qevm-broadcast-audience" name="audience">
								<?php foreach ( Broadcast::audiences() as $qevm_key => $qevm_audience ) : ?>
									<option value="<?php echo esc_attr( $qevm_key ); ?>" <?php selected( $default, $qevm_key ); ?>>
										<?php
										printf(
											/* translators: 1: Audience name, 2: Number of people in it. */
											esc_html__( '%1$s — %2$s', 'quick-events-manager' ),
											esc_html( $qevm_audience['label'] ),
											esc_html(
												sprintf(
													/* translators: %s: Number of people. */
													_n( '%s person', '%s people', $counts[ $qevm_key ], 'quick-events-manager' ),
													number_format_i18n( $counts[ $qevm_key ] )
												)
											)
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description">
								<?php esc_html_e( 'People who cancelled their booking are never included.', 'quick-events-manager' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qevm-broadcast-subject"><?php esc_html_e( 'Subject', 'quick-events-manager' ); ?></label>
						</th>
						<td><input type="text" class="large-text" id="qevm-broadcast-subject" name="subject" required /></td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qevm-broadcast-body"><?php esc_html_e( 'Message', 'quick-events-manager' ); ?></label>
						</th>
						<td>
							<textarea class="large-text code" rows="10" id="qevm-broadcast-body" name="body" required></textarea>

							<p class="description">
								<?php
								printf(
									/* translators: %s: Comma-separated list of placeholder names. */
									esc_html__( 'Placeholders you can use: %s', 'quick-events-manager' ),
									esc_html( implode( ', ', array_map( static fn ( $name ) => '{' . $name . '}', array_keys( Templates::placeholders() ) ) ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qevm-broadcast-format"><?php esc_html_e( 'Format', 'quick-events-manager' ); ?></label>
						</th>
						<td>
							<select id="qevm-broadcast-format" name="format">
								<option value="<?php echo esc_attr( Template::FORMAT_TEXT ); ?>"><?php esc_html_e( 'Plain text', 'quick-events-manager' ); ?></option>
								<option value="<?php echo esc_attr( Template::FORMAT_HTML ); ?>"><?php esc_html_e( 'HTML', 'quick-events-manager' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<p class="qevm-broadcast-actions">
					<button type="submit" name="qevm_test" value="1" class="button">
						<?php esc_html_e( 'Send me a test first', 'quick-events-manager' ); ?>
					</button>

					<button type="submit" class="button button-primary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Number of people the message goes to. */
								_n( 'Email %s person', 'Email %s people', $counts[ $default ], 'quick-events-manager' ),
								number_format_i18n( $counts[ $default ] )
							)
						);
						?>
					</button>
				</p>

				<p class="description">
					<?php esc_html_e( 'The button counts the default audience. Whichever audience you choose, the notice afterwards says how many were actually queued. Sending cannot be undone once a message has left, though anything still waiting can be withdrawn below.', 'quick-events-manager' ); ?>
				</p>
			</form>

			<?php $this->render_delivery( $event ); ?>
		</div>
		<?php
	}

	/**
	 * What the queue did with this event's mail.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return void
	 */
	private function render_delivery( Event $event ) {
		$counts = Queue::counts_for_context( Broadcast::event_contexts(), $event->id() );

		if ( array() === $counts ) {
			return;
		}

		$waiting = ( $counts[ EmailStatus::Pending->value ] ?? 0 ) + ( $counts[ EmailStatus::Sending->value ] ?? 0 );
		$sent    = $counts[ EmailStatus::Sent->value ] ?? 0;
		$failed  = $counts[ EmailStatus::Failed->value ] ?? 0;
		?>
		<h3><?php esc_html_e( 'Delivery', 'quick-events-manager' ); ?></h3>

		<p>
			<?php
			printf(
				/* translators: 1: Number sent, 2: Number waiting, 3: Number failed. */
				esc_html__( '%1$s sent, %2$s waiting to go, %3$s failed.', 'quick-events-manager' ),
				esc_html( number_format_i18n( $sent ) ),
				esc_html( number_format_i18n( $waiting ) ),
				esc_html( number_format_i18n( $failed ) )
			);
			?>
		</p>

		<p class="description">
			<?php esc_html_e( 'This covers every email about this event — confirmations as well as anything you sent from here. Mail leaves in the background, so a number waiting is normal for a few minutes after sending.', 'quick-events-manager' ); ?>
		</p>

		<?php if ( $waiting > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::WITHDRAW_ACTION ); ?>" />
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />
				<?php wp_nonce_field( self::WITHDRAW_ACTION ); ?>

				<button type="submit" class="button">
					<?php esc_html_e( 'Withdraw anything I sent from here that has not gone yet', 'quick-events-manager' ); ?>
				</button>

				<span class="description">
					<?php esc_html_e( 'Confirmations are left alone.', 'quick-events-manager' ); ?>
				</span>
			</form>
		<?php endif; ?>

		<?php
		$failures = $failed > 0
			? Queue::failures_for_context( Broadcast::event_contexts(), $event->id(), self::FAILURES_SHOWN )
			: array();

		if ( array() === $failures ) {
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Emails that could not be delivered', 'quick-events-manager' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Address', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tried', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What went wrong', 'quick-events-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $failures as $qevm_row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $qevm_row['recipient'] ); ?></td>
						<td><?php echo esc_html( (string) $qevm_row['subject'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $qevm_row['attempts'] ) ); ?></td>
						<td><?php echo esc_html( (string) $qevm_row['last_error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Queue a broadcast, or send a test of it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_send() {
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to email attendees.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::SEND_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$audience = isset( $_POST['audience'] ) ? sanitize_key( wp_unslash( $_POST['audience'] ) ) : '';
		$test     = ! empty( $_POST['qevm_test'] );

		$message = array(
			'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'format'  => isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : Template::FORMAT_TEXT,
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in Broadcast::compose(), which is where the chosen format decides how.
			'body'    => isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $test ) {
			$user   = wp_get_current_user();
			$result = Broadcast::send_test( $event_id, $user->user_email, $message );

			if ( is_wp_error( $result ) ) {
				self::redirect( $event_id, 'error', $result->get_error_message() );
			}

			self::redirect( $event_id, 'broadcast_tested', $user->user_email );
		}

		$result = Broadcast::send( $event_id, $audience, $message );

		if ( is_wp_error( $result ) ) {
			self::redirect( $event_id, 'error', $result->get_error_message() );
		}

		/*
		 * The number reported is what the queue accepted, not what was counted
		 * before the loop started. Those differ when an address the count
		 * included turns out to be one `Queue::add()` refuses, and the honest
		 * number is the one that describes messages that exist.
		 */
		self::redirect( $event_id, 'broadcast_queued', (string) $result['queued'] );
	}

	/**
	 * Withdraw whatever this event's broadcasts still have waiting.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_withdraw() {
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to email attendees.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::WITHDRAW_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;

		$withdrawn = Broadcast::withdraw( $event_id );

		self::redirect( $event_id, 'broadcast_withdrawn', (string) $withdrawn );
	}

	/**
	 * Back to the attendee screen with something to say.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event to return to.
	 * @param string $result   Result code.
	 * @param string $detail   Count or message for the notice.
	 * @return never
	 */
	private static function redirect( $event_id, $result, $detail = '' ) {
		$args = array(
			'post_type' => QEVM_POST_TYPE,
			'page'      => \QuickEventsManager\Registration\AttendeesScreen::SLUG,
			'event_id'  => (int) $event_id,
			'qevm_done' => $result,
		);

		if ( '' !== $detail ) {
			$args['qevm_message'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );

		exit;
	}
}
