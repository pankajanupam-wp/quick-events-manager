<?php
/**
 * The "Repeats" box on the event editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Domain\Frequency;
use QuickEventsManager\Domain\Weekday;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Building a recurrence rule without writing one.
 *
 * The stored form is an RFC 5545 RRULE string, which is right for storage and
 * hopeless as an editing surface — nobody should have to type
 * `FREQ=MONTHLY;BYDAY=-1FR` to mean "the last Friday of the month". This box is
 * the translation in both directions: the fields build a rule on save, and an
 * existing rule fills the fields back in.
 *
 * **It saves at priority 15, between the two things it sits between.** The event
 * details box writes the start date at 10 and the occurrence sync reads the rule
 * at 20. "The third Wednesday" is derived from the start date, so reading it at
 * 10 could read the date the event had before this save; generating at 20 must
 * see the rule this save produced. Fifteen is the only window where both are
 * true, and it is not an implementation detail — it is the whole ordering.
 *
 * A rule that cannot be read is refused rather than stored, and the previous one
 * is left alone. Storing a half-understood rule regenerates every date of the
 * series from it, which is a large silent change to make on the strength of a
 * mistyped number.
 *
 * @since 26.0
 */
final class RepeatBox {

	/**
	 * Nonce action.
	 */
	const NONCE = 'qevm_recurrence';

	/**
	 * Where a refusal is left for the next page load.
	 */
	const NOTICE = 'qevm_recurrence_notice_';

	/**
	 * Hook into the editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEVM_POST_TYPE, array( $this, 'save' ), 15, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Register the box.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add() {
		add_meta_box(
			'qevm-recurrence',
			__( 'Repeats', 'quick-events-manager' ),
			array( $this, 'render' ),
			QEVM_POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render the box.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Post $post Event being edited.
	 * @return void
	 */
	public function render( $post ) {
		$event = new Event( $post );
		$rule  = Series::rule_for_event( (int) $post->ID );

		wp_nonce_field( self::NONCE, 'qevm_recurrence_nonce' );

		$repeats   = null !== $rule;
		$frequency = null !== $rule ? $rule->frequency() : Frequency::Weekly;
		$interval  = null !== $rule ? $rule->interval() : 1;
		$chosen    = array();

		if ( null !== $rule ) {
			foreach ( $rule->byday() as $day ) {
				$chosen[] = $day->weekday()->value;
			}
		}

		$monthly_mode = null !== $rule && array() !== $rule->byday() ? 'weekday' : 'date';
		$ending       = 'never';

		if ( null !== $rule && $rule->count() > 0 ) {
			$ending = 'count';
		} elseif ( null !== $rule && '' !== $rule->until() ) {
			$ending = 'until';
		}
		?>
		<div class="qevm-fields qevm-repeat">
			<div class="qevm-field">
				<label for="qevm_repeats">
					<input type="checkbox" id="qevm_repeats" name="qevm_repeats" value="1" <?php checked( $repeats ); ?> />
					<?php esc_html_e( 'This event repeats', 'quick-events-manager' ); ?>
				</label>
			</div>

			<div class="qevm-repeat-only" <?php echo $repeats ? '' : 'hidden'; ?>>
				<div class="qevm-field-row">
					<div class="qevm-field">
						<label for="qevm_repeat_interval"><?php esc_html_e( 'Every', 'quick-events-manager' ); ?></label>
						<input type="number" id="qevm_repeat_interval" name="qevm_repeat_interval"
							min="1" max="<?php echo esc_attr( (string) Rule::MAX_INTERVAL ); ?>" step="1"
							value="<?php echo esc_attr( (string) $interval ); ?>" />
					</div>
					<div class="qevm-field">
						<label for="qevm_repeat_freq"><?php esc_html_e( 'Repeats', 'quick-events-manager' ); ?></label>
						<select id="qevm_repeat_freq" name="qevm_repeat_freq">
							<?php foreach ( Frequency::all() as $option ) : ?>
								<option value="<?php echo esc_attr( $option->value ); ?>" <?php selected( $option->value, $frequency->value ); ?>>
									<?php echo esc_html( $option->label() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<fieldset class="qevm-field qevm-repeat-weekly" <?php echo Frequency::Weekly === $frequency ? '' : 'hidden'; ?>>
					<legend><?php esc_html_e( 'On these days', 'quick-events-manager' ); ?></legend>
					<p class="description">
						<?php esc_html_e( 'Leave every day unticked to repeat on the same weekday the event starts on.', 'quick-events-manager' ); ?>
					</p>
					<?php foreach ( Weekday::in_display_order() as $weekday ) : ?>
						<label class="qevm-weekday">
							<input type="checkbox" name="qevm_repeat_weekday[]"
								value="<?php echo esc_attr( $weekday->value ); ?>"
								<?php checked( in_array( $weekday->value, $chosen, true ) ); ?> />
							<?php echo esc_html( $weekday->label() ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<fieldset class="qevm-field qevm-repeat-monthly" <?php echo Frequency::Monthly === $frequency ? '' : 'hidden'; ?>>
					<legend><?php esc_html_e( 'Each month', 'quick-events-manager' ); ?></legend>
					<label>
						<input type="radio" name="qevm_repeat_monthly_mode" value="date" <?php checked( 'date' === $monthly_mode ); ?> />
						<?php echo esc_html( self::monthly_date_label( $event ) ); ?>
					</label>
					<label>
						<input type="radio" name="qevm_repeat_monthly_mode" value="weekday" <?php checked( 'weekday' === $monthly_mode ); ?> />
						<?php echo esc_html( self::monthly_weekday_label( $event ) ); ?>
					</label>
				</fieldset>

				<fieldset class="qevm-field">
					<legend><?php esc_html_e( 'Until', 'quick-events-manager' ); ?></legend>
					<label>
						<input type="radio" name="qevm_repeat_end" value="never" <?php checked( 'never' === $ending ); ?> />
						<?php esc_html_e( 'No end date', 'quick-events-manager' ); ?>
					</label>
					<label>
						<input type="radio" name="qevm_repeat_end" value="until" <?php checked( 'until' === $ending ); ?> />
						<?php esc_html_e( 'On', 'quick-events-manager' ); ?>
						<input type="date" name="qevm_repeat_until"
							value="<?php echo esc_attr( self::until_for_input( $rule, $event ) ); ?>" />
					</label>
					<label>
						<input type="radio" name="qevm_repeat_end" value="count" <?php checked( 'count' === $ending ); ?> />
						<?php esc_html_e( 'After', 'quick-events-manager' ); ?>
						<input type="number" name="qevm_repeat_count" min="1"
							max="<?php echo esc_attr( (string) Rule::MAX_OCCURRENCES ); ?>" step="1"
							value="<?php echo esc_attr( null !== $rule && $rule->count() > 0 ? (string) $rule->count() : '' ); ?>" />
						<?php esc_html_e( 'times', 'quick-events-manager' ); ?>
					</label>
					<p class="description">
						<?php
						printf(
							/* translators: 1: maximum number of dates, 2: number of months generated ahead. */
							esc_html__( 'A series without an end keeps going: dates are generated %2$d months ahead and topped up daily, up to %1$d in total.', 'quick-events-manager' ),
							(int) Rule::MAX_OCCURRENCES,
							(int) Generator::HORIZON_MONTHS
						);
						?>
					</p>
				</fieldset>

				<div class="qevm-field">
					<label for="qevm_repeat_exclusions"><?php esc_html_e( 'Skip these dates', 'quick-events-manager' ); ?></label>
					<textarea id="qevm_repeat_exclusions" name="qevm_repeat_exclusions" rows="3" class="widefat"
						placeholder="2026-12-25"><?php echo esc_textarea( implode( "\n", Exclusions::for_event( (int) $post->ID ) ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One date a line, as 2026-12-25. Holidays and the weeks you are away.', 'quick-events-manager' ); ?>
					</p>
				</div>

				<?php if ( null !== $rule ) : ?>
					<p class="qevm-repeat-summary">
						<strong><?php esc_html_e( 'Currently:', 'quick-events-manager' ); ?></strong>
						<?php echo esc_html( $rule->describe() ); ?>
						<a href="<?php echo esc_url( DatesScreen::url( (int) $post->ID ) ); ?>">
							<?php esc_html_e( 'Manage the dates in this series', 'quick-events-manager' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Store the rule.
	 *
	 * @since 26.0
	 *
	 * @param int      $post_id Event id.
	 * @param \WP_Post $post    Event. Unused; part of the save_post signature.
	 * @return void
	 */
	public function save( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the save_post hook signature.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['qevm_recurrence_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_recurrence_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta(
			$post_id,
			Meta::RECURRENCE_EXCLUSIONS,
			Exclusions::sanitize( isset( $_POST['qevm_repeat_exclusions'] ) ? wp_unslash( $_POST['qevm_repeat_exclusions'] ) : '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exclusions::sanitize() is the sanitiser, and it needs the line breaks a text-field sanitiser would strip.
		);

		if ( ! isset( $_POST['qevm_repeats'] ) ) {
			/*
			 * The rule goes, the series identifier stays. It is the only thing
			 * joining this event to the other half of itself after a split, and
			 * to the rows already generated from it — and "repeats" unticked by
			 * accident, then ticked again, would otherwise mint a new one and
			 * leave the two halves strangers.
			 */
			delete_post_meta( $post_id, Meta::RECURRENCE_RULE );

			return;
		}

		$rule = self::rule_from_request( $post_id );

		if ( is_wp_error( $rule ) ) {
			self::remember_notice( $post_id, $rule->get_error_message() );

			return;
		}

		update_post_meta( $post_id, Meta::RECURRENCE_RULE, $rule->to_string() );
	}

	/**
	 * Build a rule from what was submitted.
	 *
	 * @since 26.0
	 *
	 * @param int $post_id Event id.
	 * @return Rule|\WP_Error
	 */
	private static function rule_from_request( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller, which is the only caller.
		$frequency = Frequency::coerce(
			isset( $_POST['qevm_repeat_freq'] ) ? sanitize_text_field( wp_unslash( $_POST['qevm_repeat_freq'] ) ) : ''
		);

		if ( null === $frequency ) {
			return new \WP_Error(
				'qevm_repeat_frequency',
				__( 'That repeat interval could not be read, so the event was saved without changing how it repeats.', 'quick-events-manager' )
			);
		}

		$interval = isset( $_POST['qevm_repeat_interval'] ) ? absint( wp_unslash( $_POST['qevm_repeat_interval'] ) ) : 1;
		$event    = new Event( $post_id );
		$byday    = array();

		if ( Frequency::Weekly === $frequency ) {
			/*
			 * map_deep() rather than array_map(), because a checkbox list is the
			 * one input a browser sends as an array and anybody can send as an
			 * array of arrays. sanitize_text_field() takes a string, so the
			 * obvious array_map() is a fatal error waiting for the first
			 * malformed request; map_deep() walks whatever arrives.
			 */
			$submitted = isset( $_POST['qevm_repeat_weekday'] ) && is_array( $_POST['qevm_repeat_weekday'] )
				? (array) map_deep( wp_unslash( $_POST['qevm_repeat_weekday'] ), 'sanitize_text_field' )
				: array();

			foreach ( $submitted as $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}

				$weekday = Weekday::coerce( $value );

				if ( null !== $weekday ) {
					$byday[] = new Byday( $weekday );
				}
			}
		}

		$monthly_mode = isset( $_POST['qevm_repeat_monthly_mode'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_repeat_monthly_mode'] ) )
			: 'date';

		if ( Frequency::Monthly === $frequency && 'weekday' === $monthly_mode ) {
			$position = self::position_in_month( $event );

			if ( null !== $position ) {
				$byday = array( $position );
			}
		}

		$ending = isset( $_POST['qevm_repeat_end'] ) ? sanitize_text_field( wp_unslash( $_POST['qevm_repeat_end'] ) ) : 'never';
		$until  = '';
		$count  = 0;

		if ( 'until' === $ending ) {
			$typed = isset( $_POST['qevm_repeat_until'] ) ? sanitize_text_field( wp_unslash( $_POST['qevm_repeat_until'] ) ) : '';
			$until = '' !== $typed ? Meta::to_utc( $typed . ' 23:59:59', $event->timezone() ) : '';

			if ( '' === $until ) {
				return new \WP_Error(
					'qevm_repeat_until',
					__( 'That end date could not be read, so the event was saved without changing how it repeats.', 'quick-events-manager' )
				);
			}
		}

		if ( 'count' === $ending ) {
			$count = isset( $_POST['qevm_repeat_count'] ) ? absint( wp_unslash( $_POST['qevm_repeat_count'] ) ) : 0;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$rule  = new Rule( $frequency, $interval, $byday, array(), array(), $until, $count );
		$valid = $rule->validate();

		return is_wp_error( $valid ) ? $valid : $rule;
	}

	/**
	 * Where in its month the event's start date falls, as a BYDAY.
	 *
	 * "The third Wednesday", or "the last Friday" when the date is in the final
	 * week — which is what somebody picking a monthly pattern means by a date
	 * near the end of the month, and what keeps a 31-day month and a 28-day one
	 * agreeing.
	 *
	 * @since 26.0
	 *
	 * @param Event $event The event.
	 * @return Byday|null
	 */
	private static function position_in_month( Event $event ) {
		$start = $event->start_local();

		if ( '' === $start ) {
			return null;
		}

		$time = strtotime( $start );

		if ( false === $time ) {
			return null;
		}

		$weekday = Weekday::from_php_w( (int) gmdate( 'w', $time ) );

		if ( null === $weekday ) {
			return null;
		}

		$day      = (int) gmdate( 'j', $time );
		$in_month = (int) gmdate( 't', $time );
		$position = (int) ceil( $day / 7 );

		return new Byday( $weekday, $day + 7 > $in_month ? -1 : $position );
	}

	/**
	 * "On the 17th" — the monthly-by-date option's label.
	 *
	 * @since 26.0
	 *
	 * @param Event $event The event.
	 * @return string
	 */
	private static function monthly_date_label( Event $event ) {
		$start = $event->start_local();
		$time  = '' !== $start ? strtotime( $start ) : false;

		if ( false === $time ) {
			return __( 'On the same date each month', 'quick-events-manager' );
		}

		return sprintf(
			/* translators: %s: day of the month, e.g. 17. */
			__( 'On day %s of the month', 'quick-events-manager' ),
			gmdate( 'j', $time )
		);
	}

	/**
	 * "On the third Wednesday" — the monthly-by-weekday option's label.
	 *
	 * @since 26.0
	 *
	 * @param Event $event The event.
	 * @return string
	 */
	private static function monthly_weekday_label( Event $event ) {
		$position = self::position_in_month( $event );

		return null !== $position
			? $position->describe()
			: __( 'On the same weekday each month', 'quick-events-manager' );
	}

	/**
	 * The stored `UNTIL` as a local date for the date input.
	 *
	 * @since 26.0
	 *
	 * @param Rule|null $rule  The rule, if any.
	 * @param Event     $event The event, for its timezone.
	 * @return string
	 */
	private static function until_for_input( $rule, Event $event ) {
		if ( ! $rule instanceof Rule || '' === $rule->until() ) {
			return '';
		}

		$local = Meta::to_local( $rule->until(), $event->timezone() );

		return '' !== $local ? substr( $local, 0, 10 ) : '';
	}

	/**
	 * Leave a refusal where the next page load will find it.
	 *
	 * Per user as well as per post: two editors saving the same event should not
	 * be shown each other's mistakes.
	 *
	 * @since 26.0
	 *
	 * @param int    $post_id Event id.
	 * @param string $message What was wrong.
	 * @return void
	 */
	private static function remember_notice( $post_id, $message ) {
		set_transient( self::NOTICE . get_current_user_id() . '_' . (int) $post_id, $message, MINUTE_IN_SECONDS );
	}

	/**
	 * Show a refusal once, then forget it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || QEVM_POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which event is on screen, not acting on it.

		if ( $post_id <= 0 ) {
			return;
		}

		$key     = self::NOTICE . get_current_user_id() . '_' . $post_id;
		$message = get_transient( $key );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
