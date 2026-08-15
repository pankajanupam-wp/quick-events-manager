<?php
/**
 * What replaces the form once registration has closed.
 *
 * Override by copying to `your-theme/quick-events-manager/registration-closed.php`.
 *
 * Available variables:
 *
 * @var \QuickEventsManager\Events\Event $event   The event.
 * @var string                           $reason  ended | expired.
 * @var string                           $message The explanation to show.
 *
 * @package QuickEventsManager
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="qevm-registration qevm-registration--closed" id="qevm-registration">
	<h2 class="qevm-registration__heading"><?php esc_html_e( 'Registration', 'quick-events-manager' ); ?></h2>

	<p class="qevm-notice qevm-notice--info"><?php echo esc_html( $message ); ?></p>
</div>
