<?php
/**
 * Every date in a series, and what can be done to one of them.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Domain\OccurrenceStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Occurrence;
use QuickEventsManager\Events\OccurrenceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The screen that makes stage 6 reachable by a person.
 *
 * Everything the last four chunks built — moving one date, calling one off,
 * handing one back to the rule, splitting a series in two — has been reachable
 * only from code until now. This is the list of dates those operations act on,
 * one row each, with the buttons beside the date they change.
 *
 * **It is a screen of its own rather than a box in the editor.** A series runs to
 * 730 dates, and 730 rows inside the post editor is a page nobody can save. It
 * also keeps a destructive-looking action off the screen where somebody is
 * typing a description: calling off a date that forty people have booked is not
 * something to do by tabbing past it.
 *
 * **Nothing here emails anybody**, which is inherited rather than decided again:
 * see OccurrenceEditor. The buttons do what they say and stop.
 *
 * @since 26.0
 */
final class DatesScreen {

	/**
	 * Page slug.
	 */
	const SLUG = 'qevm-dates';

	/**
	 * Nonce action, with an occurrence id appended.
	 */
	const NONCE = 'qevm_occurrence_action';

	/**
	 * Dates a page.
	 */
	const PER_PAGE = 50;

	/**
	 * Hook in.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_qevm_move_occurrence', array( $this, 'handle_move' ) );
		add_action( 'admin_post_qevm_cancel_occurrence', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_qevm_reinstate_occurrence', array( $this, 'handle_reinstate' ) );
		add_action( 'admin_post_qevm_restore_occurrence', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_qevm_split_series', array( $this, 'handle_split' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . QEVM_POST_TYPE,
			__( 'Dates', 'quick-events-manager' ),
			__( 'Dates', 'quick-events-manager' ),
			'edit_qevm_events',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The address of this screen for one event.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	public static function url( $event_id ) {
		return add_query_arg(
			array(
				'post_type' => QEVM_POST_TYPE,
				'page'      => self::SLUG,
				'event_id'  => (int) $event_id,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_qevm_events' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage event dates.', 'quick-events-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which event and which page to show.
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap qevm-dates">';
		echo '<h1>' . esc_html__( 'Dates', 'quick-events-manager' ) . '</h1>';

		$this->render_notice();

		$event = $event_id > 0 ? new Event( $event_id ) : null;

		if ( null === $event || ! $event->is_valid() ) {
			$this->render_event_picker();

			echo '</div>';

			return;
		}

		if ( ! current_user_can( 'edit_post', $event_id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this event.', 'quick-events-manager' ) );
		}

		$this->render_header( $event );

		$occurrences = OccurrenceRepository::for_event( $event_id );
		$total       = count( $occurrences );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;

		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'This event has no dates yet.', 'quick-events-manager' ) . '</p>';
			echo '</div>';

			return;
		}

		$this->render_table( array_slice( $occurrences, $offset, self::PER_PAGE ), $offset );
		$this->render_pagination( $total, $paged, $event_id );

		echo '</div>';
	}

	/**
	 * The event's title, its rule in words, and a way back to it.
	 *
	 * @since 26.0
	 *
	 * @param Event $event The event.
	 * @return void
	 */
	private function render_header( Event $event ) {
		$rule  = Series::rule_for_event( $event->id() );
		$other = $this->other_half( $event->id() );
		?>
		<h2><?php echo esc_html( get_the_title( $event->id() ) ); ?></h2>
		<p>
			<?php if ( null !== $rule ) : ?>
				<strong><?php echo esc_html( $rule->describe() ); ?></strong> &middot;
			<?php endif; ?>
			<a href="<?php echo esc_url( (string) get_edit_post_link( $event->id() ) ); ?>">
				<?php esc_html_e( 'Edit the event', 'quick-events-manager' ); ?>
			</a>
		</p>
		<?php if ( array() !== $other ) : ?>
			<p class="description">
				<?php esc_html_e( 'This series continues in:', 'quick-events-manager' ); ?>
				<?php foreach ( $other as $id ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
				<?php endforeach; ?>
			</p>
			<?php
		endif;
	}

	/**
	 * The other events sharing this one's series identifier.
	 *
	 * A split leaves two halves that look unrelated in the admin list, so each
	 * one says where the rest of itself is. Without this, "this and following"
	 * produces a second event the organiser has to go and find.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return int[]
	 */
	private function other_half( $event_id ) {
		$uuid = Series::for_event( $event_id );

		if ( '' === $uuid ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => QEVM_POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 10,
				'fields'           => 'ids',
				'exclude'          => array( (int) $event_id ),
				'meta_key'         => \QuickEventsManager\Events\Meta::SERIES_UUID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed meta lookup, on a screen showing one event.
				'meta_value'       => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				'suppress_filters' => false,
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The list of dates.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence[] $occurrences Dates on this page.
	 * @param int          $offset      How many dates come before this page.
	 * @return void
	 */
	private function render_table( array $occurrences, $offset ) {
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Move to', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'quick-events-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $occurrences as $index => $occurrence ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $occurrence->format_start() ); ?>
							<?php if ( $this->has_bookings( $occurrence ) ) : ?>
								<span class="qevm-date-flag"><?php esc_html_e( 'Booked', 'quick-events-manager' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( self::status_label( $occurrence ) ); ?>
						</td>
						<td>
							<?php $this->render_move_form( $occurrence ); ?>
						</td>
						<td class="qevm-date-actions">
							<?php $this->render_actions( $occurrence, 0 === $offset && 0 === $index ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The move form for one date.
	 *
	 * Pre-filled with where the date is now, because the common edit is a small
	 * one — an hour later, a day later — and retyping a date somebody can see
	 * two columns to the left is how a wrong year gets entered.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The date.
	 * @return void
	 */
	private function render_move_form( Occurrence $occurrence ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qevm-date-move">
			<?php wp_nonce_field( self::NONCE . '_' . $occurrence->id() ); ?>
			<input type="hidden" name="action" value="qevm_move_occurrence" />
			<input type="hidden" name="occurrence" value="<?php echo esc_attr( (string) $occurrence->id() ); ?>" />
			<label class="screen-reader-text" for="qevm-move-<?php echo esc_attr( (string) $occurrence->id() ); ?>">
				<?php esc_html_e( 'New date and time', 'quick-events-manager' ); ?>
			</label>
			<input type="datetime-local" id="qevm-move-<?php echo esc_attr( (string) $occurrence->id() ); ?>"
				name="start_local" value="<?php echo esc_attr( self::to_input( $occurrence->start_local() ) ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Move', 'quick-events-manager' ); ?></button>
		</form>
		<?php
	}

	/**
	 * The buttons for one date.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The date.
	 * @param bool       $is_first   Whether this is the first date of the series.
	 * @return void
	 */
	private function render_actions( Occurrence $occurrence, $is_first ) {
		$cancelled = OccurrenceStatus::Cancelled === $occurrence->status();

		if ( $cancelled ) {
			$this->render_button( $occurrence, 'qevm_reinstate_occurrence', __( 'Put back', 'quick-events-manager' ) );
		} else {
			$this->render_button( $occurrence, 'qevm_cancel_occurrence', __( 'Call off', 'quick-events-manager' ) );
		}

		if ( $occurrence->is_exception() ) {
			$this->render_button( $occurrence, 'qevm_restore_occurrence', __( 'Reset to the rule', 'quick-events-manager' ) );
		}

		/*
		 * Splitting at the first date is refused by the Splitter, because it
		 * leaves an empty event holding the bookings of every date that used to
		 * be its own. Offering a button that always fails is worse than not
		 * offering it, so the first row does not get one.
		 */
		if ( ! $is_first ) {
			$this->render_button( $occurrence, 'qevm_split_series', __( 'Split from here', 'quick-events-manager' ) );
		}
	}

	/**
	 * One action button, as its own form.
	 *
	 * A form rather than a link, because every one of these changes something
	 * and a link is a thing a browser may follow on its own.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The date.
	 * @param string     $action     admin-post action.
	 * @param string     $label      Button text.
	 * @return void
	 */
	private function render_button( Occurrence $occurrence, $action, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qevm-date-action">
			<?php wp_nonce_field( self::NONCE . '_' . $occurrence->id() ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="occurrence" value="<?php echo esc_attr( (string) $occurrence->id() ); ?>" />
			<button type="submit" class="button-link"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Move one date.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_move() {
		$occurrence = $this->authorised();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised() checks the nonce.
		$start = isset( $_POST['start_local'] ) ? sanitize_text_field( wp_unslash( $_POST['start_local'] ) ) : '';

		$this->finish( $occurrence, OccurrenceEditor::move( $occurrence->id(), self::from_input( $start ) ), 'moved' );
	}

	/**
	 * Call one date off.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_cancel() {
		$occurrence = $this->authorised();

		$this->finish( $occurrence, OccurrenceEditor::cancel( $occurrence->id() ), 'cancelled' );
	}

	/**
	 * Put a called-off date back on.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_reinstate() {
		$occurrence = $this->authorised();

		$this->finish( $occurrence, OccurrenceEditor::reinstate( $occurrence->id() ), 'reinstated' );
	}

	/**
	 * Hand one date back to the rule.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_restore() {
		$occurrence = $this->authorised();

		$this->finish( $occurrence, OccurrenceEditor::restore( $occurrence->id() ), 'restored' );
	}

	/**
	 * Split the series from one date onward.
	 *
	 * On success the organiser is taken to the **new** half rather than left on
	 * the old one. They split the series in order to change what happens from
	 * that date on, and the dates they were looking at are now on the other
	 * screen.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_split() {
		$occurrence = $this->authorised();
		$result     = Splitter::split( $occurrence->id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_back( $occurrence->event_id(), 'error', $result->get_error_message() );
		}

		$this->redirect_back( (int) $result, 'split' );
	}

	/**
	 * The occurrence this request is allowed to act on.
	 *
	 * Nonce, then capability, then existence — in that order, so a request with
	 * no nonce is refused before it can be used to find out whether an id
	 * exists.
	 *
	 * @since 26.0
	 *
	 * @return Occurrence
	 */
	private function authorised() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() is the next statement but one, and needs the id first.
		$id = isset( $_POST['occurrence'] ) ? absint( wp_unslash( $_POST['occurrence'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE . '_' . $id );

		$occurrence = OccurrenceRepository::find( $id );

		if ( null === $occurrence ) {
			wp_die( esc_html__( 'That date could not be found.', 'quick-events-manager' ) );
		}

		if ( ! current_user_can( 'edit_post', $occurrence->event_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to change this event.', 'quick-events-manager' ) );
		}

		return $occurrence;
	}

	/**
	 * Report what happened and go back to the list.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence     $occurrence The date acted on.
	 * @param true|\WP_Error $result     What the operation returned.
	 * @param string         $done       Result code for the notice.
	 * @return void
	 */
	private function finish( Occurrence $occurrence, $result, $done ) {
		if ( is_wp_error( $result ) ) {
			$this->redirect_back( $occurrence->event_id(), 'error', $result->get_error_message() );
		}

		$this->redirect_back( $occurrence->event_id(), $done );
	}

	/**
	 * Back to the list, with a result.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event to show.
	 * @param string $result   Result code.
	 * @param string $message  Error text, when there is one.
	 * @return never
	 */
	private function redirect_back( $event_id, $result, $message = '' ) {
		$args = array( 'qevm_done' => $result );

		if ( '' !== $message ) {
			$args['qevm_message'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, self::url( $event_id ) ) );

		exit;
	}

	/**
	 * Say what just happened.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a redirect result, which changes nothing.
		if ( ! isset( $_GET['qevm_done'] ) ) {
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['qevm_done'] ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() wraps the decode, which has to happen first.
		$message = isset( $_GET['qevm_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['qevm_message'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'moved'      => array( 'success', __( 'That date has moved. Nobody has been emailed about it.', 'quick-events-manager' ) ),
			'cancelled'  => array( 'success', __( 'That date is called off. Any bookings on it are untouched, and nobody has been emailed.', 'quick-events-manager' ) ),
			'reinstated' => array( 'success', __( 'That date is back on.', 'quick-events-manager' ) ),
			'restored'   => array( 'success', __( 'That date follows the repeat rule again.', 'quick-events-manager' ) ),
			'split'      => array( 'success', __( 'The series was split. You are now looking at the second half, which is where changes from this date on belong.', 'quick-events-manager' ) ),
			'error'      => array( 'error', __( 'That did not work.', 'quick-events-manager' ) ),
		);

		if ( ! isset( $notices[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notices[ $result ][0] ),
			esc_html( 'error' === $result && '' !== $message ? $message : $notices[ $result ][1] )
		);
	}

	/**
	 * Ask for an event when none was named.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private function render_event_picker() {
		$events = get_posts(
			array(
				'post_type'      => QEVM_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => \QuickEventsManager\Events\Meta::RECURRENCE_RULE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Listing the repeating events is the purpose of this screen.
			)
		);

		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'No event repeats yet. Open an event and tick "This event repeats".', 'quick-events-manager' ) . '</p>';

			return;
		}

		echo '<p>' . esc_html__( 'Choose a repeating event:', 'quick-events-manager' ) . '</p><ul>';

		foreach ( $events as $event ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( self::url( $event->ID ) ),
				esc_html( get_the_title( $event ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Page links.
	 *
	 * @since 26.0
	 *
	 * @param int $total    How many dates in total.
	 * @param int $paged    Current page.
	 * @param int $event_id Event id.
	 * @return void
	 */
	private function render_pagination( $total, $paged, $event_id ) {
		if ( $total <= self::PER_PAGE ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', self::url( $event_id ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => (int) ceil( $total / self::PER_PAGE ),
				'type'      => 'plain',
				'prev_text' => __( '&laquo;', 'quick-events-manager' ),
				'next_text' => __( '&raquo;', 'quick-events-manager' ),
			)
		);

		if ( is_string( $links ) && '' !== $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * Whether anything has claimed this date must survive.
	 *
	 * Asked through the same filter the reconciler asks, so this screen learns
	 * that a date has bookings on it without knowing that registrations exist.
	 * With that module switched off nothing answers and no flag is shown, which
	 * is correct rather than a gap.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The date.
	 * @return bool
	 */
	private function has_bookings( Occurrence $occurrence ) {
		return (bool) apply_filters( 'qevm_occurrence_is_protected', false, $occurrence );
	}

	/**
	 * What to call an occurrence's state.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The date.
	 * @return string
	 */
	private static function status_label( Occurrence $occurrence ) {
		if ( OccurrenceStatus::Cancelled === $occurrence->status() ) {
			return __( 'Called off', 'quick-events-manager' );
		}

		if ( $occurrence->is_exception() ) {
			return __( 'Edited', 'quick-events-manager' );
		}

		return __( 'From the rule', 'quick-events-manager' );
	}

	/**
	 * A stored datetime as a datetime-local input wants it.
	 *
	 * @since 26.0
	 *
	 * @param string $stored Local datetime, `Y-m-d H:i:s`.
	 * @return string
	 */
	private static function to_input( $stored ) {
		return '' !== $stored ? str_replace( ' ', 'T', substr( $stored, 0, 16 ) ) : '';
	}

	/**
	 * A datetime-local value as the rest of the plugin stores it.
	 *
	 * @since 26.0
	 *
	 * @param string $input Submitted value.
	 * @return string
	 */
	private static function from_input( $input ) {
		$input = trim( str_replace( 'T', ' ', $input ) );

		if ( '' === $input ) {
			return '';
		}

		return 16 === strlen( $input ) ? $input . ':00' : $input;
	}
}
