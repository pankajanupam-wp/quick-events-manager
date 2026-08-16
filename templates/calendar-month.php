<?php
/**
 * A month of events as a table.
 *
 * Override by copying to `your-theme/quick-events-manager/calendar-month.php`.
 *
 * Available variables:
 *
 * @var \QuickEventsManager\Calendar\Month $month     The month being shown.
 * @var string                             $list_url  Link to the same range as a list.
 * @var string                             $view      Which view is showing: grid | list.
 *
 * @package QuickEventsManager
 */

use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

$qevm_weeks    = $month->weeks();
$qevm_weekdays = \QuickEventsManager\Calendar\Month::weekdays();
$qevm_count    = $month->event_count();
?>
<div class="qevm-calendar" data-qevm-calendar>
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

	<?php
	/*
	 * A real <caption> rather than a heading above the table. It is what a
	 * screen reader announces when the table is entered, so somebody arriving
	 * by keyboard is told which month they are in and how much is in it before
	 * they start moving through thirty cells.
	 */
	?>
	<table class="qevm-calendar__grid">
		<caption class="qevm-calendar__caption">
			<span class="qevm-calendar__month"><?php echo esc_html( $month->label() ); ?></span>
			<span class="qevm-calendar__summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of events in the month. */
						_n( '%s event', '%s events', $qevm_count, 'quick-events-manager' ),
						number_format_i18n( $qevm_count )
					)
				);
				?>
			</span>
		</caption>

		<thead>
			<tr>
				<?php foreach ( $qevm_weekdays as $qevm_weekday ) : ?>
					<th scope="col">
						<?php
						/*
						 * The abbreviation is shown and the full name is read.
						 * A column header of "Mo" is fine to look at and poor
						 * to listen to, and every cell in the column inherits
						 * whichever one is here.
						 */
						?>
						<abbr title="<?php echo esc_attr( $qevm_weekday['full'] ); ?>"><?php echo esc_html( $qevm_weekday['short'] ); ?></abbr>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>

		<tbody>
			<?php foreach ( $qevm_weeks as $qevm_week ) : ?>
				<tr>
					<?php foreach ( $qevm_week as $qevm_cell ) : ?>
						<?php if ( null === $qevm_cell ) : ?>
							<?php
							/*
							 * Outside the month. Empty rather than filled with
							 * the neighbouring month's dates: a screen reader
							 * reading "31" in the row above the 1st, with no
							 * way to know it belongs elsewhere, is worse than
							 * reading nothing at all.
							 */
							?>
							<td class="qevm-calendar__day qevm-calendar__day--empty"></td>
						<?php else : ?>
							<td class="qevm-calendar__day<?php echo $qevm_cell['is_today'] ? ' qevm-calendar__day--today' : ''; ?><?php echo array() !== $qevm_cell['events'] ? ' qevm-calendar__day--has-events' : ''; ?>"
								data-qevm-day="<?php echo esc_attr( $qevm_cell['date'] ); ?>">

								<?php
								/*
								 * The date carries its own full label for
								 * assistive technology. "5" on its own is
								 * ambiguous read aloud out of context, and the
								 * column header only supplies the weekday.
								 */
								?>
								<span class="qevm-calendar__date" aria-hidden="true"><?php echo esc_html( number_format_i18n( $qevm_cell['day'] ) ); ?></span>
								<span class="screen-reader-text">
									<?php echo esc_html( wp_date( 'j F Y', strtotime( $qevm_cell['date'] . ' 12:00:00' ) ) ); ?>
									<?php
									if ( $qevm_cell['is_today'] ) {
										echo esc_html__( '(today)', 'quick-events-manager' );
									}
									?>
								</span>

								<?php if ( array() !== $qevm_cell['events'] ) : ?>
									<ul class="qevm-calendar__events">
										<?php foreach ( $qevm_cell['events'] as $qevm_occurrence ) : ?>
											<?php
											$qevm_event = new Event( $qevm_occurrence->event_id() );

											if ( ! $qevm_event->is_valid() ) {
												continue;
											}
											?>
											<li class="qevm-calendar__event">
												<a href="<?php echo esc_url( (string) get_permalink( $qevm_event->id() ) ); ?>">
													<?php echo esc_html( get_the_title( $qevm_event->id() ) ); ?>
													<?php if ( ! $qevm_occurrence->is_all_day() ) : ?>
														<span class="qevm-calendar__time"><?php echo esc_html( $qevm_event->format_start( (string) get_option( 'time_format' ) ) ); ?></span>
													<?php endif; ?>
												</a>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						<?php endif; ?>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	/*
	 * The list view is offered here rather than only in a settings screen,
	 * because a grid is a poor way to read a month with a screen reader however
	 * correct its markup is, and somebody who prefers the list should not have
	 * to go looking for it. See ADR-0013 and C4.2.
	 */
	?>
	<p class="qevm-calendar__alternative">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'See these events as a list', 'quick-events-manager' ); ?></a>
	</p>
</div>
