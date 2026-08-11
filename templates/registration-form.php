<?php
/**
 * The registration form.
 *
 * Override by copying to `your-theme/quick-events-manager/registration-form.php`.
 *
 * @package QuickEventsManager
 *
 * @var \QuickEventsManager\Events\Event $event     Event being registered for.
 * @var bool              $is_full   Whether every place is taken.
 * @var int|null          $remaining Places left, or null when uncapped.
 * @var array|null        $result    Outcome of the previous submission.
 */

use QuickEventsManager\Registration\FormHandler;

defined( 'ABSPATH' ) || exit;
?>
<div class="qevm-registration" id="qevm-registration">
	<h2 class="qevm-registration__heading"><?php esc_html_e( 'Register for this event', 'quick-events-manager' ); ?></h2>

	<?php if ( null !== $result ) : ?>
		<?php if ( 'success' === $result['status'] ) : ?>
			<p class="qevm-notice qevm-notice--success" role="status">
				<?php esc_html_e( 'You are registered. We have sent a confirmation to your email address.', 'quick-events-manager' ); ?>
			</p>
		<?php elseif ( 'waitlisted' === $result['status'] ) : ?>
			<p class="qevm-notice qevm-notice--info" role="status">
				<?php esc_html_e( 'This event is full, so you have been added to the waiting list. We will email you if a place becomes available.', 'quick-events-manager' ); ?>
			</p>
		<?php else : ?>
			<p class="qevm-notice qevm-notice--error" role="alert">
				<?php
				echo '' !== $result['message']
					? esc_html( $result['message'] )
					: esc_html__( 'Your registration could not be completed. Please try again.', 'quick-events-manager' );
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $is_full ) : ?>
		<p class="qevm-notice qevm-notice--info">
			<?php esc_html_e( 'This event is full. You can still join the waiting list below.', 'quick-events-manager' ); ?>
		</p>
	<?php elseif ( null !== $remaining && $remaining <= 10 ) : ?>
		<p class="qevm-registration__remaining">
			<?php
			printf(
				/* translators: %s: Number of places remaining. */
				esc_html( _n( 'Only %s place left.', 'Only %s places left.', $remaining, 'quick-events-manager' ) ),
				esc_html( number_format_i18n( $remaining ) )
			);
			?>
		</p>
	<?php endif; ?>

	<form class="qevm-registration__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="qevm_register" />
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />
		<?php wp_nonce_field( FormHandler::NONCE . '_' . $event->id() ); ?>

		<p class="qevm-field">
			<label for="qevm-name"><?php esc_html_e( 'Name', 'quick-events-manager' ); ?> <span class="qevm-required" aria-hidden="true">*</span></label>
			<input type="text" id="qevm-name" name="qevm_name" required autocomplete="name" />
		</p>

		<p class="qevm-field">
			<label for="qevm-email"><?php esc_html_e( 'Email', 'quick-events-manager' ); ?> <span class="qevm-required" aria-hidden="true">*</span></label>
			<input type="email" id="qevm-email" name="qevm_email" required autocomplete="email" />
		</p>

		<p class="qevm-field">
			<label for="qevm-phone"><?php esc_html_e( 'Phone', 'quick-events-manager' ); ?></label>
			<input type="tel" id="qevm-phone" name="qevm_phone" autocomplete="tel" />
		</p>

		<p class="qevm-field">
			<label for="qevm-quantity"><?php esc_html_e( 'Number of places', 'quick-events-manager' ); ?></label>
			<input type="number" id="qevm-quantity" name="qevm_quantity" value="1" min="1" max="20" />
		</p>

		<?php
		/*
		 * Honeypot. Hidden from people with CSS and from screen readers with
		 * aria-hidden, but visible to the naive bots that fill in every field
		 * they find.
		 */
		?>
		<p class="qevm-honeypot" aria-hidden="true">
			<label for="qevm-website"><?php esc_html_e( 'Leave this field empty', 'quick-events-manager' ); ?></label>
			<input type="text" id="qevm-website" name="qevm_website" tabindex="-1" autocomplete="off" />
		</p>

		<p class="qevm-field qevm-field--submit">
			<button type="submit" class="qevm-button">
				<?php
				echo $is_full
					? esc_html__( 'Join the waiting list', 'quick-events-manager' )
					: esc_html__( 'Register', 'quick-events-manager' );
				?>
			</button>
		</p>
	</form>
</div>
