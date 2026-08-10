<?php
/**
 * Event date, time and location.
 *
 * Override by copying to `your-theme/quick-events-manager/event-details.php`.
 *
 * @package QuickEventsManager
 *
 * @var \QEM\Events\Event $event Event being rendered.
 */

use QEM\Frontend\Ics;

defined( 'ABSPATH' ) || exit;

$qem_start    = $event->format_start();
$qem_end      = $event->format_end();
$qem_tz       = $event->timezone_label();
$qem_venue    = $event->venue_summary();
$qem_ics_url  = Ics::url( $event->id() );
$qem_gcal_url = Ics::google_url( $event );
?>
<div class="qem-event-details">
	<?php if ( $event->has_ended() ) : ?>
		<p class="qem-event-status qem-event-status--past">
			<?php esc_html_e( 'This event has finished.', 'quick-events-manager' ); ?>
		</p>
	<?php elseif ( $event->is_happening_now() ) : ?>
		<p class="qem-event-status qem-event-status--now">
			<?php esc_html_e( 'Happening now', 'quick-events-manager' ); ?>
		</p>
	<?php endif; ?>

	<dl class="qem-event-meta">
		<?php if ( '' !== $qem_start ) : ?>
			<div class="qem-event-meta__item">
				<dt><?php esc_html_e( 'When', 'quick-events-manager' ); ?></dt>
				<dd>
					<time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $event->start_utc() ) . 'Z' ); ?>">
						<?php echo esc_html( $qem_start ); ?>
					</time>
					<?php if ( '' !== $qem_end ) : ?>
						<span class="qem-event-meta__separator">&ndash;</span>
						<time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $event->end_utc() ) . 'Z' ); ?>">
							<?php echo esc_html( $qem_end ); ?>
						</time>
					<?php endif; ?>
					<?php if ( '' !== $qem_tz ) : ?>
						<span class="qem-event-meta__tz"><?php echo esc_html( $qem_tz ); ?></span>
					<?php endif; ?>
				</dd>
			</div>
		<?php endif; ?>

		<?php if ( $event->is_online() ) : ?>
			<div class="qem-event-meta__item">
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
		<?php elseif ( '' !== $qem_venue ) : ?>
			<div class="qem-event-meta__item">
				<dt><?php esc_html_e( 'Where', 'quick-events-manager' ); ?></dt>
				<dd><?php echo esc_html( $qem_venue ); ?></dd>
			</div>
		<?php endif; ?>

		<?php if ( '' !== (string) $event->meta( \QEM\Events\Meta::ORGANIZER_NAME ) ) : ?>
			<div class="qem-event-meta__item">
				<dt><?php esc_html_e( 'Organiser', 'quick-events-manager' ); ?></dt>
				<dd><?php echo esc_html( (string) $event->meta( \QEM\Events\Meta::ORGANIZER_NAME ) ); ?></dd>
			</div>
		<?php endif; ?>
	</dl>

	<?php if ( '' !== $qem_start && ! $event->has_ended() ) : ?>
		<p class="qem-add-to-calendar">
			<a class="qem-button qem-button--secondary" href="<?php echo esc_url( $qem_ics_url ); ?>">
				<?php esc_html_e( 'Add to calendar', 'quick-events-manager' ); ?>
			</a>
			<?php if ( '' !== $qem_gcal_url ) : ?>
				<a class="qem-link" href="<?php echo esc_url( $qem_gcal_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Google Calendar', 'quick-events-manager' ); ?>
				</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
