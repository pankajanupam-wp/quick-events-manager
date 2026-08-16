<?php
/**
 * Event date, time and location.
 *
 * Override by copying to `your-theme/quick-events-manager/event-details.php`.
 *
 * @package QuickEventsManager
 *
 * @var \QuickEventsManager\Events\Event $event Event being rendered.
 */

use QuickEventsManager\Frontend\Ics;

defined( 'ABSPATH' ) || exit;

$qevm_start     = $event->format_start();
$qevm_end       = $event->format_end();
$qevm_tz        = $event->timezone_label();
$qevm_venue     = $event->venue_summary();
$qevm_organizer = $event->organizer()->name();

$qevm_ics_url  = Ics::url( $event->id() );
$qevm_gcal_url = Ics::google_url( $event );
?>
<div class="qevm-event-details">
	<?php if ( $event->has_ended() ) : ?>
		<p class="qevm-event-status qevm-event-status--past">
			<?php esc_html_e( 'This event has finished.', 'quick-events-manager' ); ?>
		</p>
	<?php elseif ( $event->is_happening_now() ) : ?>
		<p class="qevm-event-status qevm-event-status--now">
			<?php esc_html_e( 'Happening now', 'quick-events-manager' ); ?>
		</p>
	<?php endif; ?>

	<dl class="qevm-event-meta">
		<?php if ( '' !== $qevm_start ) : ?>
			<div class="qevm-event-meta__item">
				<dt><?php esc_html_e( 'When', 'quick-events-manager' ); ?></dt>
				<dd>
					<time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $event->start_utc() ) . 'Z' ); ?>">
						<?php echo esc_html( $qevm_start ); ?>
					</time>
					<?php if ( '' !== $qevm_end ) : ?>
						<span class="qevm-event-meta__separator">&ndash;</span>
						<time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $event->end_utc() ) . 'Z' ); ?>">
							<?php echo esc_html( $qevm_end ); ?>
						</time>
					<?php endif; ?>
					<?php if ( '' !== $qevm_tz ) : ?>
						<span class="qevm-event-meta__tz"><?php echo esc_html( $qevm_tz ); ?></span>
					<?php endif; ?>
				</dd>
			</div>
		<?php endif; ?>

		<?php if ( $event->is_online() ) : ?>
			<div class="qevm-event-meta__item">
				<dt><?php esc_html_e( 'Where', 'quick-events-manager' ); ?></dt>
				<dd>
					<?php esc_html_e( 'Online', 'quick-events-manager' ); ?>
					<?php if ( '' !== $event->online_url() ) : ?>
						<br />
						<a href="<?php echo esc_url( $event->online_url() ); ?>" rel="noopener">
							<?php esc_html_e( 'Joining link', 'quick-events-manager' ); ?>
						</a>
					<?php endif; ?>
				</dd>
			</div>
		<?php elseif ( '' !== $qevm_venue ) : ?>
			<div class="qevm-event-meta__item">
				<dt><?php esc_html_e( 'Where', 'quick-events-manager' ); ?></dt>
				<dd><?php echo esc_html( $qevm_venue ); ?></dd>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $qevm_organizer ) : ?>
			<div class="qevm-event-meta__item">
				<dt><?php esc_html_e( 'Organiser', 'quick-events-manager' ); ?></dt>
				<dd><?php echo esc_html( $qevm_organizer ); ?></dd>
			</div>
		<?php endif; ?>
	</dl>

	<?php if ( '' !== $qevm_start && ! $event->has_ended() ) : ?>
		<p class="qevm-add-to-calendar">
			<a class="qevm-button qevm-button--secondary" href="<?php echo esc_url( $qevm_ics_url ); ?>">
				<?php esc_html_e( 'Add to calendar', 'quick-events-manager' ); ?>
			</a>
			<?php if ( '' !== $qevm_gcal_url ) : ?>
				<a class="qevm-link" href="<?php echo esc_url( $qevm_gcal_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Google Calendar', 'quick-events-manager' ); ?>
				</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
