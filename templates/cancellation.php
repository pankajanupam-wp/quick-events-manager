<?php
/**
 * Cancelling a booking: the confirmation panel and its outcome.
 *
 * Override by copying to `your-theme/quick-events-manager/cancellation.php`.
 *
 * Available variables:
 *
 * @var array                                             $state        state | message | registration | request.
 * @var \QuickEventsManager\Events\Event|null             $event        Event, when it is known.
 *
 * @package QuickEventsManager
 */

defined( 'ABSPATH' ) || exit;

use QuickEventsManager\Registration\CancellationHandler;
use QuickEventsManager\Registration\CancellationLink;

$qevm_registration = $state['registration'];
?>
<div class="qevm-registration qevm-cancellation" id="qevm-registration">
	<h2 class="qevm-registration__heading"><?php esc_html_e( 'Cancel your booking', 'quick-events-manager' ); ?></h2>

	<?php if ( 'error' === $state['state'] ) : ?>
		<p class="qevm-notice qevm-notice--error"><?php echo esc_html( $state['message'] ); ?></p>

	<?php elseif ( 'done' === $state['state'] ) : ?>
		<p class="qevm-notice qevm-notice--success"><?php echo esc_html( $state['message'] ); ?></p>

	<?php elseif ( 'confirm' === $state['state'] && $qevm_registration instanceof \QuickEventsManager\Registration\Registration ) : ?>

		<p class="qevm-cancellation__intro">
			<?php
			printf(
				/* translators: %s: event title. */
				esc_html__( 'You are about to cancel your booking for %s.', 'quick-events-manager' ),
				'<strong>' . esc_html( $event instanceof \QuickEventsManager\Events\Event ? get_the_title( $event->id() ) : '' ) . '</strong>'
			);
			?>
		</p>

		<dl class="qevm-cancellation__summary">
			<div class="qevm-cancellation__item">
				<dt><?php esc_html_e( 'Booked by', 'quick-events-manager' ); ?></dt>
				<dd><?php echo esc_html( $qevm_registration->booker_name() ); ?></dd>
			</div>
			<div class="qevm-cancellation__item">
				<dt><?php esc_html_e( 'Places', 'quick-events-manager' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $qevm_registration->quantity() ) ); ?></dd>
			</div>
			<div class="qevm-cancellation__item">
				<dt><?php esc_html_e( 'Reference', 'quick-events-manager' ); ?></dt>
				<dd><code><?php echo esc_html( $qevm_registration->code() ); ?></code></dd>
			</div>
		</dl>

		<p class="qevm-cancellation__warning">
			<?php esc_html_e( 'This cannot be undone. If you change your mind you will need to book again, and the event may be full by then.', 'quick-events-manager' ); ?>
		</p>

		<form class="qevm-cancellation__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( CancellationHandler::ACTION ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( CancellationLink::QUERY_VAR ); ?>" value="<?php echo esc_attr( $state['request']['code'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( CancellationLink::EXPIRES_VAR ); ?>" value="<?php echo esc_attr( (string) $state['request']['expires'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( CancellationLink::TOKEN_VAR ); ?>" value="<?php echo esc_attr( $state['request']['token'] ); ?>" />
			<?php wp_nonce_field( CancellationHandler::NONCE ); ?>

			<p class="qevm-field qevm-field--submit">
				<button type="submit" class="qevm-button qevm-button--danger">
					<?php esc_html_e( 'Cancel my booking', 'quick-events-manager' ); ?>
				</button>

				<?php if ( $event instanceof \QuickEventsManager\Events\Event ) : ?>
					<a class="qevm-link" href="<?php echo esc_url( get_permalink( $event->id() ) ); ?>">
						<?php esc_html_e( 'Keep my booking', 'quick-events-manager' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</form>

	<?php endif; ?>
</div>
