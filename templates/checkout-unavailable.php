<?php
/**
 * What somebody sees when payment cannot be started at all.
 *
 * Override by copying to `your-theme/quick-events-manager/checkout-unavailable.php`.
 *
 * @package QuickEventsManager
 *
 * @var \QuickEventsManager\Commerce\Order $order   The order.
 * @var string                             $message What went wrong, if anything can be said.
 */

defined( 'ABSPATH' ) || exit;

$qevm_message = isset( $message ) ? (string) $message : '';

?>
<section class="qevm-checkout qevm-checkout--unavailable" id="qevm-checkout">
	<h2 class="qevm-checkout__title"><?php esc_html_e( 'Payment is not available right now', 'quick-events-manager' ); ?></h2>

	<p>
		<?php if ( '' !== $qevm_message ) : ?>
			<?php echo esc_html( $qevm_message ); ?>
		<?php else : ?>
			<?php esc_html_e( 'We could not start a payment for this booking. Your place is held for now — please try again shortly, or contact the organiser.', 'quick-events-manager' ); ?>
		<?php endif; ?>
	</p>

	<p class="qevm-checkout__reference">
		<?php
		printf(
			/* translators: %s: Order reference. */
			esc_html__( 'Reference: %s', 'quick-events-manager' ),
			esc_html( $order->number() )
		);
		?>
	</p>
</section>
