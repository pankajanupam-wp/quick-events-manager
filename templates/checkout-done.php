<?php
/**
 * What somebody sees when the order they asked for is already dealt with.
 *
 * Override by copying to `your-theme/quick-events-manager/checkout-done.php`.
 *
 * @package QuickEventsManager
 *
 * @var \QuickEventsManager\Commerce\Order $order The order.
 */

defined( 'ABSPATH' ) || exit;

$qevm_paid = \QuickEventsManager\Domain\OrderStatus::Paid === $order->status()
	|| $order->status()->is_settled();

?>
<section class="qevm-checkout qevm-checkout--done" id="qevm-checkout">
	<?php if ( $qevm_paid ) : ?>
		<h2 class="qevm-checkout__title"><?php esc_html_e( 'Paid — you are booked in', 'quick-events-manager' ); ?></h2>
		<p><?php esc_html_e( 'Thank you. Your confirmation is on its way by email.', 'quick-events-manager' ); ?></p>
	<?php else : ?>
		<h2 class="qevm-checkout__title"><?php esc_html_e( 'This booking is no longer waiting for payment', 'quick-events-manager' ); ?></h2>
		<p><?php esc_html_e( 'The place was held for a while and then went back to whoever was next. You are welcome to book again if there is still room.', 'quick-events-manager' ); ?></p>
	<?php endif; ?>

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
