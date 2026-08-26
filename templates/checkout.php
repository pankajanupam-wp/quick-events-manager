<?php
/**
 * The payment screen.
 *
 * Override by copying to `your-theme/quick-events-manager/checkout.php`.
 *
 * @package QuickEventsManager
 *
 * @var \QuickEventsManager\Commerce\Order $order   The order being paid.
 * @var \QuickEventsManager\Commerce\Money $total   What is owed.
 * @var array<string, string>              $started What the gateway needs the browser to do.
 */

defined( 'ABSPATH' ) || exit;

?>
<section class="qevm-checkout" id="qevm-checkout">
	<h2 class="qevm-checkout__title"><?php esc_html_e( 'Pay for your place', 'quick-events-manager' ); ?></h2>

	<p class="qevm-checkout__total">
		<?php
		printf(
			/* translators: %s: Amount to pay. */
			esc_html__( 'Total: %s', 'quick-events-manager' ),
			'<strong>' . esc_html( $total->format() ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on the line above.
		);
		?>
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

	<?php
	/*
	 * Where Stripe draws its own card fields. Nothing inside this element
	 * belongs to this plugin, which is the point: the card number is typed into
	 * an iframe served by Stripe and never touches this site.
	 */
	?>
	<div id="qevm-card" class="qevm-checkout__card"></div>

	<p class="qevm-checkout__error" id="qevm-checkout-error" role="alert" aria-live="polite"></p>

	<p class="qevm-checkout__actions">
		<button type="button" class="qevm-button qevm-checkout__pay" id="qevm-pay">
			<?php
			printf(
				/* translators: %s: Amount to pay. */
				esc_html__( 'Pay %s', 'quick-events-manager' ),
				esc_html( $total->format() )
			);
			?>
		</button>
	</p>

	<p class="qevm-checkout__note">
		<?php esc_html_e( 'Your place is held while you pay. If you do not finish, it goes back to whoever is next.', 'quick-events-manager' ); ?>
	</p>
</section>
