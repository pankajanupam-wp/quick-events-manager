<?php
/**
 * A list of events.
 *
 * Override by copying to `your-theme/quick-events-manager/event-list.php`.
 *
 * @package QuickEventsManager
 *
 * @var \WP_Query $query   Events to display.
 * @var int       $columns Grid columns.
 * @var string    $show    'upcoming' or 'past'.
 */

defined( 'ABSPATH' ) || exit;

if ( ! $query->have_posts() ) {
	?>
	<p class="qem-event-list__empty">
		<?php
		echo 'past' === $show
			? esc_html__( 'No past events.', 'quick-events-manager' )
			: esc_html__( 'No upcoming events.', 'quick-events-manager' );
		?>
	</p>
	<?php

	return;
}
?>
<ul class="qem-event-list qem-event-list--cols-<?php echo esc_attr( (string) $columns ); ?>">
	<?php
	while ( $query->have_posts() ) :
		$query->the_post();

		$qem_event = new \QEM\Events\Event( get_post() );
		?>
		<li class="qem-event-card">
			<?php if ( has_post_thumbnail() ) : ?>
				<a class="qem-event-card__image" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
					<?php the_post_thumbnail( 'medium_large' ); ?>
				</a>
			<?php endif; ?>

			<div class="qem-event-card__body">
				<?php if ( '' !== $qem_event->format_start() ) : ?>
					<p class="qem-event-card__date">
						<time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $qem_event->start_utc() ) . 'Z' ); ?>">
							<?php echo esc_html( $qem_event->format_start() ); ?>
						</time>
					</p>
				<?php endif; ?>

				<h3 class="qem-event-card__title">
					<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
				</h3>

				<?php
				$qem_location = $qem_event->is_online()
					? __( 'Online', 'quick-events-manager' )
					: $qem_event->venue_summary();
				?>
				<?php if ( '' !== $qem_location ) : ?>
					<p class="qem-event-card__location"><?php echo esc_html( $qem_location ); ?></p>
				<?php endif; ?>

				<?php if ( has_excerpt() ) : ?>
					<p class="qem-event-card__excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>
			</div>
		</li>
		<?php
	endwhile;
	?>
</ul>
