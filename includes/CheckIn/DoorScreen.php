<?php
/**
 * The screen somebody holds at the door.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CheckIn;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\OccurrenceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One hand, one thumb, a queue in front of you and probably no signal.
 *
 * Every decision here comes from that. The list is one column of large rows
 * because a table with six columns is unusable on a phone held at chest height.
 * Each row has one primary action and it is a button, not a link, because it
 * changes something. The count is at the top, in words, because "12 of 40 in" is
 * the question the organiser is actually being asked every few minutes.
 *
 * **It works with no JavaScript at all.** Every action is a form post that
 * reloads the page — slower, and completely reliable in a church hall with two
 * bars of signal. Scanning with a camera is added on top of this in C8.4b, and
 * is an enhancement rather than the mechanism.
 *
 * The whole screen is behind `manage_qevm_checkins`, which exists so that a
 * staff role can work a door without being able to edit anything.
 *
 * @since 26.0
 */
final class DoorScreen {

	/**
	 * Page slug.
	 */
	const SLUG = 'qevm-checkin';

	/**
	 * The hook suffix WordPress gave this page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Nonce action for admitting somebody.
	 */
	const NONCE = 'qevm_door';

	/**
	 * Hook in.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_qevm_admit', array( $this, 'handle_admit' ) );
		add_action( 'admin_post_qevm_reverse', array( $this, 'handle_reverse' ) );
		add_action( 'admin_post_qevm_admit_code', array( $this, 'handle_admit_code' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the scanner, on this screen only.
	 *
	 * The script is an enhancement over a form that already works. Nothing
	 * below depends on it loading, or on the browser having a camera, or on the
	 * browser having heard of `BarcodeDetector`.
	 *
	 * @since 26.0
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( '' === $this->page_hook || $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_script( 'qevm-checkin', QEVM_URL . 'assets/js/checkin.js', array(), QEVM_VERSION, true );

		wp_localize_script(
			'qevm-checkin',
			'qevmCheckIn',
			array(
				'scan'     => __( 'Scan a ticket', 'quick-events-manager' ),
				'stop'     => __( 'Stop scanning', 'quick-events-manager' ),
				'noCamera' => __( 'This browser cannot use the camera. Type the code instead.', 'quick-events-manager' ),
				'looking'  => __( 'Point the camera at the ticket.', 'quick-events-manager' ),
			)
		);
	}

	/**
	 * Add the submenu page.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_page() {
		$this->page_hook = (string) add_submenu_page(
			'edit.php?post_type=' . QEVM_POST_TYPE,
			__( 'Check in', 'quick-events-manager' ),
			__( 'Check in', 'quick-events-manager' ),
			'manage_qevm_checkins',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The address of this screen for one event and date.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id      Event id.
	 * @param int    $occurrence_id Date, or 0.
	 * @param string $search        Current search, if any.
	 * @return string
	 */
	public static function url( $event_id, $occurrence_id = 0, $search = '' ) {
		$args = array(
			'post_type' => QEVM_POST_TYPE,
			'page'      => self::SLUG,
			'event_id'  => (int) $event_id,
		);

		if ( $occurrence_id > 0 ) {
			$args['occurrence_id'] = (int) $occurrence_id;
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Render the screen.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_qevm_checkins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check people in.', 'quick-events-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which door is being run.
		$event_id      = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$occurrence_id = isset( $_GET['occurrence_id'] ) ? absint( wp_unslash( $_GET['occurrence_id'] ) ) : 0;
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap qevm-door">';
		echo '<h1>' . esc_html__( 'Check in', 'quick-events-manager' ) . '</h1>';

		$this->render_notice();

		$event = $event_id > 0 ? new Event( $event_id ) : null;

		if ( null === $event || ! $event->is_valid() ) {
			$this->render_event_picker();

			echo '</div>';

			return;
		}

		$occurrence_id = $this->resolve_date( $event, $occurrence_id );

		$this->render_header( $event, $occurrence_id, $search );
		$this->render_code_form( $event, $occurrence_id );
		$this->render_list( $event, $occurrence_id, $search );

		echo '</div>';
	}

	/**
	 * Which date this door is for.
	 *
	 * Defaults to the next one rather than asking. Somebody opening this screen
	 * at ten to seven is running tonight's door, and making them choose from a
	 * list of fifty-two weeks first is a question with an obvious answer.
	 *
	 * @since 26.0
	 *
	 * @param Event $event  The event.
	 * @param int   $chosen What the request asked for, or 0.
	 * @return int
	 */
	private function resolve_date( Event $event, $chosen ) {
		if ( $chosen > 0 ) {
			return $chosen;
		}

		$next = OccurrenceRepository::next_for_event( $event->id() );

		return null !== $next ? $next->id() : 0;
	}

	/**
	 * The event, the date, the count and the search box.
	 *
	 * @since 26.0
	 *
	 * @param Event  $event         The event.
	 * @param int    $occurrence_id Date being run.
	 * @param string $search        Current search.
	 * @return void
	 */
	private function render_header( Event $event, $occurrence_id, $search ) {
		$expected   = count( $this->expected( $event, $occurrence_id ) );
		$present    = CheckInRepository::count_present( $occurrence_id );
		$dates      = OccurrenceRepository::for_event( $event->id() );
		$occurrence = OccurrenceRepository::find( $occurrence_id );
		?>
		<h2 class="qevm-door__event"><?php echo esc_html( get_the_title( $event->id() ) ); ?></h2>

		<?php if ( null !== $occurrence ) : ?>
			<p class="qevm-door__date"><?php echo esc_html( $occurrence->format_start() ); ?></p>
		<?php endif; ?>

		<p class="qevm-door__count" role="status">
			<?php
			printf(
				/* translators: 1: How many people are in. 2: How many are expected. */
				esc_html__( '%1$s of %2$s in', 'quick-events-manager' ),
				esc_html( number_format_i18n( $present ) ),
				esc_html( number_format_i18n( $expected ) )
			);
			?>
		</p>

		<form method="get" class="qevm-door__search">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( QEVM_POST_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />
			<input type="hidden" name="occurrence_id" value="<?php echo esc_attr( (string) $occurrence_id ); ?>" />

			<label for="qevm-door-search"><?php esc_html_e( 'Find somebody', 'quick-events-manager' ); ?></label>
			<input type="search" id="qevm-door-search" name="s" value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Name, email or ticket code', 'quick-events-manager' ); ?>"
				autocomplete="off" enterkeyhint="search" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'quick-events-manager' ); ?></button>

			<?php if ( '' !== $search ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $event->id(), $occurrence_id ) ); ?>">
					<?php esc_html_e( 'Show everybody', 'quick-events-manager' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<?php if ( count( $dates ) > 1 ) : ?>
			<form method="get" class="qevm-door__dates">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( QEVM_POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />

				<label for="qevm-door-date"><?php esc_html_e( 'Which date', 'quick-events-manager' ); ?></label>
				<select id="qevm-door-date" name="occurrence_id">
					<?php foreach ( $dates as $date ) : ?>
						<option value="<?php echo esc_attr( (string) $date->id() ); ?>" <?php selected( $date->id(), $occurrence_id ); ?>>
							<?php echo esc_html( $date->format_start() ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Go', 'quick-events-manager' ); ?></button>
			</form>
			<?php
		endif;
	}

	/**
	 * Take a ticket code straight in.
	 *
	 * A scanner — a phone camera, or the barcode reader on a lanyard that types
	 * for you — produces a ticket code and nothing else. This turns one into an
	 * arrival in a single step, which is the difference between a queue moving
	 * and a queue waiting for somebody to find a name in a list.
	 *
	 * It is an ordinary form. With no JavaScript, the person on the door types
	 * or scans into the box and presses the button; with JavaScript and a
	 * browser that can see a camera, the script fills the box and submits it.
	 * The server does not know or care which happened.
	 *
	 * @since 26.0
	 *
	 * @param Event $event         The event.
	 * @param int   $occurrence_id Date being run.
	 * @return void
	 */
	private function render_code_form( Event $event, $occurrence_id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qevm-door__scan"
			data-qevm-scan>
			<?php wp_nonce_field( self::NONCE . '_code' ); ?>
			<input type="hidden" name="action" value="qevm_admit_code" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />
			<input type="hidden" name="occurrence_id" value="<?php echo esc_attr( (string) $occurrence_id ); ?>" />

			<label for="qevm-door-code"><?php esc_html_e( 'Ticket code', 'quick-events-manager' ); ?></label>
			<input type="text" id="qevm-door-code" name="ticket_code" autocomplete="off" autocapitalize="characters"
				spellcheck="false" enterkeyhint="done" data-qevm-code
				placeholder="<?php esc_attr_e( 'QEVT-…', 'quick-events-manager' ); ?>" />

			<button type="submit" class="button button-primary"><?php esc_html_e( 'Check in', 'quick-events-manager' ); ?></button>

			<?php
			/*
			 * The camera controls are added here by the script, and only when
			 * the browser can actually do it. Rendering a "Scan" button in PHP
			 * would mean offering a button that does nothing on every browser
			 * without `BarcodeDetector`, which today is most of them.
			 */
			?>
			<span class="qevm-door__scanner" data-qevm-scanner></span>
		</form>
		<?php
	}

	/**
	 * Admit whoever holds a ticket code.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_admit_code() {
		check_admin_referer( self::NONCE . '_code' );

		if ( ! current_user_can( 'manage_qevm_checkins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check people in.', 'quick-events-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$code = isset( $_POST['ticket_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_code'] ) ) : '';

		if ( '' === $code ) {
			$this->go_back( CheckInService::REFUSED, __( 'No ticket code was given.', 'quick-events-manager' ) );
		}

		$outcome = CheckInService::admit_by_code( strtoupper( $code ), get_current_user_id(), 'qr' );

		$this->go_back( $outcome['result'], $this->name_the_person( $outcome ) );
	}

	/**
	 * The message, with whose ticket it was.
	 *
	 * A door screen says "Checked in." to somebody who scanned three tickets in
	 * ten seconds, and that is not enough to know which one worked. The name
	 * goes in front of it.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $outcome What the service returned.
	 * @return string
	 */
	private function name_the_person( array $outcome ) {
		$attendee = isset( $outcome['attendee'] ) ? $outcome['attendee'] : null;
		$message  = isset( $outcome['message'] ) ? (string) $outcome['message'] : '';

		if ( ! is_object( $attendee ) || ! method_exists( $attendee, 'name' ) || '' === $attendee->name() ) {
			return $message;
		}

		return sprintf(
			/* translators: 1: Attendee name. 2: What happened, e.g. "Checked in." */
			__( '%1$s — %2$s', 'quick-events-manager' ),
			$attendee->name(),
			$message
		);
	}

	/**
	 * Everybody expected, with a button each.
	 *
	 * @since 26.0
	 *
	 * @param Event  $event         The event.
	 * @param int    $occurrence_id Date being run.
	 * @param string $search        Current search.
	 * @return void
	 */
	private function render_list( Event $event, $occurrence_id, $search ) {
		$attendees = $this->expected( $event, $occurrence_id, $search );

		if ( array() === $attendees ) {
			echo '<p class="qevm-door__empty">';
			echo '' !== $search
				? esc_html__( 'Nobody matches that.', 'quick-events-manager' )
				: esc_html__( 'Nobody is expected at this date yet.', 'quick-events-manager' );
			echo '</p>';

			return;
		}

		echo '<ul class="qevm-door__list">';

		foreach ( $attendees as $attendee ) {
			$checkin = CheckInRepository::find( $attendee->id(), $occurrence_id );
			$present = null !== $checkin && ! $checkin->is_reversed();

			printf(
				'<li class="qevm-door__person%1$s">',
				$present ? ' qevm-door__person--in' : ''
			);

			printf(
				'<span class="qevm-door__name">%s</span>',
				esc_html( '' !== $attendee->name() ? $attendee->name() : __( 'Guest', 'quick-events-manager' ) )
			);

			printf(
				'<span class="qevm-door__code">%s</span>',
				esc_html( $attendee->ticket_code() )
			);

			if ( $present ) {
				printf(
					'<span class="qevm-door__state">%s</span>',
					esc_html(
						sprintf(
							/* translators: %s: The time they arrived, e.g. "18:42". */
							__( 'In at %s', 'quick-events-manager' ),
							$checkin->format_time()
						)
					)
				);
			}

			$this->render_action( $attendee->id(), $event->id(), $occurrence_id, $search, $present );

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * The one button on a row.
	 *
	 * @since 26.0
	 *
	 * @param int    $attendee_id   Attendee id.
	 * @param int    $event_id      Event id.
	 * @param int    $occurrence_id Date being run.
	 * @param string $search        Current search, so the page comes back the same.
	 * @param bool   $present       Whether they are already in.
	 * @return void
	 */
	private function render_action( $attendee_id, $event_id, $occurrence_id, $search, $present ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qevm-door__action">
			<?php wp_nonce_field( self::NONCE . '_' . (int) $attendee_id ); ?>
			<input type="hidden" name="action" value="<?php echo $present ? 'qevm_reverse' : 'qevm_admit'; ?>" />
			<input type="hidden" name="attendee" value="<?php echo esc_attr( (string) $attendee_id ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
			<input type="hidden" name="occurrence_id" value="<?php echo esc_attr( (string) $occurrence_id ); ?>" />
			<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />

			<button type="submit" class="button <?php echo $present ? 'button-secondary' : 'button-primary button-hero'; ?>">
				<?php echo $present ? esc_html__( 'Undo', 'quick-events-manager' ) : esc_html__( 'Check in', 'quick-events-manager' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Admit somebody.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_admit() {
		$attendee = $this->authorised();
		$outcome  = CheckInService::admit( $attendee, get_current_user_id(), 'manual' );

		$this->go_back( $outcome['result'], $outcome['message'] );
	}

	/**
	 * Undo an arrival.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_reverse() {
		$attendee = $this->authorised();

		$done = CheckInService::reverse( $attendee, get_current_user_id() );

		$this->go_back(
			$done ? 'undone' : CheckInService::REFUSED,
			$done ? __( 'Taken back out.', 'quick-events-manager' ) : __( 'That was not undone.', 'quick-events-manager' )
		);
	}

	/**
	 * The attendee this request may act on.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	private function authorised() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() is the next statement, and needs the id first.
		$attendee = isset( $_POST['attendee'] ) ? absint( wp_unslash( $_POST['attendee'] ) ) : 0;

		check_admin_referer( self::NONCE . '_' . $attendee );

		if ( ! current_user_can( 'manage_qevm_checkins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check people in.', 'quick-events-manager' ) );
		}

		return $attendee;
	}

	/**
	 * Back to the door, with what happened.
	 *
	 * @since 26.0
	 *
	 * @param string $result  Outcome.
	 * @param string $message What to say.
	 * @return never
	 */
	private function go_back( $result, $message ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in authorised(); these carry the page back to where it was.
		$event_id      = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$occurrence_id = isset( $_POST['occurrence_id'] ) ? absint( wp_unslash( $_POST['occurrence_id'] ) ) : 0;
		$search        = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'qevm_done'    => $result,
					'qevm_message' => rawurlencode( $message ),
				),
				self::url( $event_id, $occurrence_id, $search )
			)
		);

		exit;
	}

	/**
	 * Say what just happened, loudly.
	 *
	 * A door is read at arm's length, so this is a banner rather than a line of
	 * text, and it is a live region so a screen reader announces it without the
	 * page having to be re-read.
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

		$tone = array(
			CheckInService::ADMITTED => 'success',
			CheckInService::ALREADY  => 'warning',
			CheckInService::REFUSED  => 'error',
			'undone'                 => 'info',
		);

		if ( ! isset( $tone[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s qevm-door__result" role="alert" tabindex="-1"><p>%2$s</p></div>',
			esc_attr( $tone[ $result ] ),
			esc_html( '' !== $message ? $message : __( 'Done.', 'quick-events-manager' ) )
		);
	}

	/**
	 * Which events have somebody to check in.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private function render_event_picker() {
		$events = get_posts(
			array(
				'post_type'      => QEVM_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'There are no events to check anybody in to.', 'quick-events-manager' ) . '</p>';

			return;
		}

		echo '<p>' . esc_html__( 'Which door are you on?', 'quick-events-manager' ) . '</p><ul class="qevm-door__events">';

		foreach ( $events as $event ) {
			printf(
				'<li><a class="button button-hero" href="%1$s">%2$s</a></li>',
				esc_url( self::url( $event->ID ) ),
				esc_html( get_the_title( $event ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Everybody expected, asked of whoever owns the people.
	 *
	 * @since 26.0
	 *
	 * @param Event  $event         The event.
	 * @param int    $occurrence_id Date being run.
	 * @param string $search        Current search.
	 * @return array<int, object>
	 */
	private function expected( Event $event, $occurrence_id, $search = '' ) {
		$attendees = apply_filters( 'qevm_expected_attendees', array(), $event->id(), (int) $occurrence_id, (string) $search );

		return is_array( $attendees ) ? $attendees : array();
	}
}
