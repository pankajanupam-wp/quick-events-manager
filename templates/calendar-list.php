<?php
/**
 * A month of events as a list.
 *
 * Override by copying to `your-theme/quick-events-manager/calendar-list.php`.
 *
 * Available variables:
 *
 * @var \QuickEventsManager\Calendar\Month $month    The month being shown.
 * @var string                             $grid_url Link to the same range as a grid.
 * @var string                             $view     Which view is showing: grid | list.
 *
 * @package QuickEventsManager
 */

use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

$qevm_days  = $month->occurrences_by_day();
$qevm_count = $month->event_count();

ksort( $qevm_days );
?>
<div class="qevm-calendar qevm-calendar--list" data-qevm-calendar>
	<?php
	/*
	 * The same heading and count as the grid's caption. Somebody switching
	 * between the two views should land somewhere recognisable rather than
	 * having to work out whether they are still in the same month.
	 */
	?>
	<div class="qevm-calendar__caption">
		<h2 class="qevm-calendar__month"><?php echo esc_html( $month->label() ); ?></h2>
		<p class="qevm-calendar__summary">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of events in the month. */
					_n( '%s event', '%s events', $qevm_count, 'quick-events-manager' ),
					number_format_i18n( $qevm_count )
				)
			);
			?>
		</p>
	</div>

	<?php
	/*
	 * Real links, not buttons. Without script they navigate, which is the whole
	 * no-JavaScript story for this feature; with script they are intercepted.
	 * Building them as buttons and adding href-less click handlers would mean
	 * the calendar simply stopped at the current month for anybody whose script
	 * failed to load.
	 *
	 * rel="prev"/"next" because that is what they are, and it costs nothing.
	 */
	?>
	<nav class="qevm-calendar__nav" aria-label="<?php esc_attr_e( 'Calendar months', 'quick-events-manager' ); ?>">
		<a class="qevm-calendar__nav-link" rel="prev" data-qevm-calendar-prev
			href="<?php echo esc_url( \QuickEventsManager\Frontend\Renderer::calendar_url( $month->previous(), $view ) ); ?>">
			<span aria-hidden="true">&larr;</span>
			<?php echo esc_html( $month->previous()->label() ); ?>
		</a>

		<a class="qevm-calendar__nav-link" data-qevm-calendar-today
			href="<?php echo esc_url( \QuickEventsManager\Frontend\Renderer::calendar_url( \QuickEventsManager\Calendar\Month::current(), $view ) ); ?>">
			<?php esc_html_e( 'This month', 'quick-events-manager' ); ?>
		</a>

		<a class="qevm-calendar__nav-link" rel="next" data-qevm-calendar-next
			href="<?php echo esc_url( \QuickEventsManager\Frontend\Renderer::calendar_url( $month->next(), $view ) ); ?>">
			<?php echo esc_html( $month->next()->label() ); ?>
			<span aria-hidden="true">&rarr;</span>
		</a>
	</nav>

	<?php
	/*
	 * Empty at load, filled after a month is swapped in.
	 *
	 * This is the opposite case to the registration form's error summary, and
	 * the difference is worth being explicit about because getting it the wrong
	 * way round is silent. A region that already has its text when the page
	 * parses announces nothing — there is no change to report — which is why the
	 * form moves focus instead. A region that is empty at parse and is written
	 * into later is announced, and is the right tool here: focus stays on the
	 * button so somebody stepping through months can keep pressing it.
	 */
	?>
	<p class="screen-reader-text" aria-live="polite" data-qevm-calendar-status></p>

	<?php if ( array() === $qevm_days ) : ?>
		<?php
		/*
		 * Said out loud rather than shown as blank space. The grid can be empty
		 * and still look like a calendar; an empty list looks like something
		 * failed to load.
		 */
		?>
		<p class="qevm-calendar__empty">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: month and year, e.g. September 2026. */
					__( 'There are no events in %s.', 'quick-events-manager' ),
					$month->label()
				)
			);
			?>
		</p>
	<?php else : ?>
		<?php
		/*
		 * Only the days that have something on them, which is the one place
		 * this view deliberately differs from the grid. A grid shows every date
		 * because the shape is the information; a list reading "2 September, no
		 * events" thirty times is a list nobody finishes.
		 */
		?>
		<dl class="qevm-calendar__days">
			<?php foreach ( $qevm_days as $qevm_date => $qevm_occurrences ) : ?>
				<dt class="qevm-calendar__day-heading">
					<?php echo esc_html( wp_date( 'l j F', strtotime( $qevm_date . ' 12:00:00' ) ) ); ?>
				</dt>
				<dd>
					<ul class="qevm-calendar__events">
						<?php foreach ( $qevm_occurrences as $qevm_occurrence ) : ?>
							<?php
							$qevm_event = new Event( $qevm_occurrence->event_id() );

							if ( ! $qevm_event->is_valid() ) {
								continue;
							}
							?>
							<li class="qevm-calendar__event">
								<a href="<?php echo esc_url( (string) get_permalink( $qevm_event->id() ) ); ?>">
									<?php echo esc_html( get_the_title( $qevm_event->id() ) ); ?>
								</a>

								<?php if ( ! $qevm_occurrence->is_all_day() ) : ?>
									<span class="qevm-calendar__time"><?php echo esc_html( $qevm_event->format_start( (string) get_option( 'time_format' ) ) ); ?></span>
								<?php endif; ?>

								<?php if ( '' !== $qevm_event->venue_summary() ) : ?>
									<span class="qevm-calendar__where"><?php echo esc_html( $qevm_event->venue_summary() ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>

	<p class="qevm-calendar__alternative">
		<a href="<?php echo esc_url( $grid_url ); ?>"><?php esc_html_e( 'See these events as a month grid', 'quick-events-manager' ); ?></a>
	</p>
</div>
