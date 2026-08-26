<?php
/**
 * The attendees admin screen.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Events\Event;

use QuickEventsManager\Domain\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Lists who has registered, with search, status filtering and status changes.
 *
 * @since 26.0
 */
final class AttendeesScreen {

	/**
	 * Menu slug.
	 */
	const SLUG = 'qevm-attendees';

	/**
	 * Nonce action for changing a status.
	 */
	const NONCE = 'qevm_attendee_action';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 25;

	/**
	 * Action name for entering a booking on somebody's behalf.
	 */
	const ADD_ACTION = 'qevm_add_registration';

	/**
	 * Nonce action for entering a booking.
	 */
	const ADD_NONCE = 'qevm_add_registration';

	/**
	 * Action name for sending a confirmation again.
	 */
	const RESEND_ACTION = 'qevm_resend_confirmation';

	/**
	 * Nonce action for sending a confirmation again.
	 */
	const RESEND_NONCE = 'qevm_resend_confirmation';

	/**
	 * Hook into the admin.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_qevm_update_registration', array( $this, 'handle_update' ) );
		add_action( 'admin_post_' . self::ADD_ACTION, array( $this, 'handle_add' ) );
		add_action( 'admin_post_' . self::RESEND_ACTION, array( $this, 'handle_resend' ) );
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
			__( 'Attendees', 'quick-events-manager' ),
			__( 'Attendees', 'quick-events-manager' ),
			'manage_qevm_registrations',
			self::SLUG,
			array( $this, 'render' )
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
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to view attendees.', 'quick-events-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/*
		 * Holding the capability says somebody manages guest lists. It does not
		 * say whose — see Access.
		 */
		Access::require_manage( $event_id );

		echo '<div class="wrap qevm-attendees">';
		echo '<h1>' . esc_html__( 'Attendees', 'quick-events-manager' ) . '</h1>';

		$this->render_notice();

		if ( 0 === $event_id ) {
			$this->render_event_picker();
			echo '</div>';

			return;
		}

		$event = new Event( $event_id );

		if ( ! $event->is_valid() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That event could not be found.', 'quick-events-manager' ) . '</p></div>';
			echo '</div>';

			return;
		}

		$args = array(
			'status'   => $status,
			'search'   => $search,
			'per_page' => self::PER_PAGE,
			'page'     => $paged,
		);

		$registrations = Repository::for_event( $event_id, $args );
		$total         = Repository::count_for_event( $event_id, $args );

		$this->render_header( $event );
		$this->render_filters( $event_id, $status, $search );
		$this->render_table( $registrations, $event_id );
		$this->render_pagination( $total, $paged, $event_id, $status, $search );
		$this->render_add_form( $event );

		/*
		 * Below the list and below the add form, in that order, because the two
		 * above it act on one person and this one acts on everybody. Putting a
		 * button that emails four hundred people next to a button that emails
		 * one is how the wrong one gets pressed.
		 */
		( new \QuickEventsManager\Email\BroadcastForm() )->render( $event );

		echo '</div>';
	}


	/**
	 * Say what the last action did.
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

		$count = is_numeric( $message ) ? (int) $message : 0;

		$notices = array(
			'added'               => array( 'success', __( 'Attendee added.', 'quick-events-manager' ) ),
			'added_waitlisted'    => array( 'warning', __( 'Attendee added to the waiting list — the event is full.', 'quick-events-manager' ) ),
			'resent'              => array( 'success', __( 'Confirmation sent again.', 'quick-events-manager' ) ),
			'broadcast_queued'    => array(
				'success',
				sprintf(
					/* translators: %s: Number of people the message was queued for. */
					_n( 'Message queued for %s person. It goes out over the next few minutes.', 'Message queued for %s people. It goes out over the next few minutes.', $count, 'quick-events-manager' ),
					number_format_i18n( $count )
				),
			),
			'broadcast_withdrawn' => array(
				'success',
				sprintf(
					/* translators: %s: Number of messages withdrawn. */
					_n( '%s message withdrawn before it was sent.', '%s messages withdrawn before they were sent.', $count, 'quick-events-manager' ),
					number_format_i18n( $count )
				),
			),
			'broadcast_tested'    => array(
				'success',
				sprintf(
					/* translators: %s: Email address the test went to. */
					__( 'Test queued for %s.', 'quick-events-manager' ),
					$message
				),
			),
			'error'               => array( 'error', __( 'That did not work.', 'quick-events-manager' ) ),
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
	 * Prompt for an event when none is selected.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private function render_event_picker() {
		$events = get_posts(
			\QuickEventsManager\Events\OccurrenceQuery::all_args(
				array(
					'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
					'posts_per_page' => 100,
				),
				'DESC'
			)
		);

		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'You have not created any events yet.', 'quick-events-manager' ) . '</p>';

			return;
		}

		echo '<p>' . esc_html__( 'Choose an event to see who has registered.', 'quick-events-manager' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="post_type" value="' . esc_attr( QEVM_POST_TYPE ) . '" />';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';

		/*
		 * A label, even though the sentence above says what the control is for.
		 * A screen reader reaching this by tabbing lands on the control without
		 * the paragraph, and "combo box" on its own is not an instruction. The
		 * label is visually hidden because the sentence is already there for
		 * everybody else.
		 */
		echo '<label class="screen-reader-text" for="qevm-event-picker">'
			. esc_html__( 'Choose an event', 'quick-events-manager' )
			. '</label>';

		echo '<select name="event_id" id="qevm-event-picker">';

		foreach ( Access::only_theirs( $events ) as $event_post ) {
			$event = new Event( $event_post );

			printf(
				'<option value="%d">%s%s</option>',
				(int) $event_post->ID,
				esc_html( get_the_title( $event_post ) ),
				esc_html( '' !== $event->format_start() ? ' — ' . $event->format_start() : '' )
			);
		}

		echo '</select> ';
		submit_button( __( 'Show attendees', 'quick-events-manager' ), 'primary', '', false );
		echo '</form>';
	}

	/**
	 * Event title and counts.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return void
	 */
	private function render_header( Event $event ) {
		$taken     = Repository::count_taken( $event->id() );
		$remaining = RegistrationService::places_remaining( $event );

		echo '<h2 class="qevm-attendees-event">' . esc_html( get_the_title( $event->id() ) ) . '</h2>';
		echo '<p class="qevm-attendees-summary">';

		printf(
			/* translators: %s: Number of places taken. */
			esc_html( _n( '%s place taken', '%s places taken', $taken, 'quick-events-manager' ) ),
			esc_html( number_format_i18n( $taken ) )
		);

		if ( null !== $remaining ) {
			echo ' &middot; ';
			printf(
				/* translators: %s: Number of places remaining. */
				esc_html( _n( '%s place remaining', '%s places remaining', $remaining, 'quick-events-manager' ) ),
				esc_html( number_format_i18n( $remaining ) )
			);
		}

		echo '</p>';
	}

	/**
	 * Search box, status filter and export link.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $status   Active status filter.
	 * @param string $search   Active search term.
	 * @return void
	 */
	private function render_filters( $event_id, $status, $search ) {
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=qevm_export_registrations&event_id=' . $event_id ),
			Exporter::NONCE
		);

		$sensitive_url = wp_nonce_url(
			admin_url(
				'admin-post.php?action=qevm_export_registrations&event_id=' . $event_id
				. '&' . Exporter::SENSITIVE_ARG . '=1'
			),
			Exporter::NONCE
		);

		$has_sensitive = self::asks_anything_sensitive( $event_id );
		?>
		<form method="get" class="qevm-attendees-filters">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( QEVM_POST_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />

			<label class="screen-reader-text" for="qevm-status"><?php esc_html_e( 'Filter by status', 'quick-events-manager' ); ?></label>
			<select name="status" id="qevm-status">
				<option value=""><?php esc_html_e( 'All statuses', 'quick-events-manager' ); ?></option>
				<?php foreach ( RegistrationStatus::all() as $option ) : ?>
					<option value="<?php echo esc_attr( $option->value ); ?>" <?php selected( $status, $option->value ); ?>>
						<?php echo esc_html( $option->label() ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="qevm-search"><?php esc_html_e( 'Search attendees', 'quick-events-manager' ); ?></label>
			<input type="search" id="qevm-search" name="s" value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Name, email or reference', 'quick-events-manager' ); ?>" />

			<?php submit_button( __( 'Filter', 'quick-events-manager' ), 'secondary', '', false ); ?>

			<a class="button" href="<?php echo esc_url( $export_url ); ?>">
				<?php esc_html_e( 'Export CSV', 'quick-events-manager' ); ?>
			</a>

			<?php if ( $has_sensitive ) : ?>
				<a class="button" href="<?php echo esc_url( $sensitive_url ); ?>">
					<?php esc_html_e( 'Export CSV including sensitive answers', 'quick-events-manager' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<?php if ( $has_sensitive ) : ?>
			<p class="description">
				<?php esc_html_e( 'Answers marked sensitive — dietary needs, access requirements — are left out of the ordinary export. The second button includes them; that file holds health information about named people, so send it only where it needs to go.', 'quick-events-manager' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Whether this event asks anything marked sensitive.
	 *
	 * The second export button only appears when there is something for it to
	 * include. An always-present "including sensitive answers" button on an
	 * event with no such questions is a button that teaches people to click it.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return bool
	 */
	private static function asks_anything_sensitive( $event_id ) {
		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\CustomFields\CustomFieldsModule::ID ) ) {
			return false;
		}

		foreach ( \QuickEventsManager\CustomFields\Definitions::for_event( $event_id ) as $field ) {
			if ( $field->is_sensitive() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The attendee table.
	 *
	 * @since 26.0
	 *
	 * @param Registration[] $registrations Rows to show.
	 * @param int            $event_id      Event id.
	 * @return void
	 */
	private function render_table( array $registrations, $event_id ) {
		$fields  = self::answer_fields( $event_id );
		$answers = array() === $fields || array() === $registrations
			? array()
			: \QuickEventsManager\CustomFields\AnswerRepository::for_registrations(
				array_map( static fn ( $registration ) => $registration->id(), $registrations )
			);

		/*
		 * Both of these are shown only when the event actually has them. A
		 * "Date" column on an event with one date is the same value repeated,
		 * and a "Ticket" column on an event offering one kind of place is the
		 * same again — this screen is read at a glance by somebody standing at
		 * a door, and every column that says nothing costs the ones that do.
		 */
		$dates   = self::dates_for( $event_id );
		$tickets = self::tickets_for( $event_id );

		/**
		 * Filter the extra columns on the attendee screen.
		 *
		 * Keyed by an id this screen passes back when it asks for each cell.
		 * How another module adds what it knows about a booking — what was paid
		 * for it, say — without this screen having to know that module exists.
		 *
		 * @since 26.0
		 *
		 * @param array<string, string> $extra    Column id => heading.
		 * @param int                   $event_id The event being shown.
		 */
		$extra = (array) apply_filters( 'qevm_attendee_columns', array(), (int) $event_id );

		$columns = 8 + count( $extra ) + ( array() === $fields ? 0 : 1 ) + ( array() === $dates ? 0 : 1 ) + ( array() === $tickets ? 0 : 1 );
		?>
		<?php
		/*
		 * Not `fixed`. WordPress's fixed layout divides the width equally
		 * between columns, which is fine for the four or five a core list
		 * table has and not for the ten this one can reach once dates, tickets
		 * and answers are all in play: the cells become too narrow for their
		 * own buttons, and "Update" and "Resend" end up drawn on top of each
		 * other. Found by taking the listing screenshots in C10.7, which is
		 * the first time anybody looked at this screen full of columns.
		 */
		?>
		<table class="wp-list-table widefat striped qevm-attendees-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'quick-events-manager' ); ?></th>
					<?php if ( array() !== $dates ) : ?>
						<th scope="col"><?php esc_html_e( 'Date', 'quick-events-manager' ); ?></th>
					<?php endif; ?>
					<?php if ( array() !== $tickets ) : ?>
						<th scope="col"><?php esc_html_e( 'Ticket', 'quick-events-manager' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Phone', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Places', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reference', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Registered', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Confirmation', 'quick-events-manager' ); ?></th>
					<?php foreach ( $extra as $qevm_heading ) : ?>
						<th scope="col"><?php echo esc_html( $qevm_heading ); ?></th>
					<?php endforeach; ?>
					<?php if ( array() !== $fields ) : ?>
						<th scope="col"><?php esc_html_e( 'Answers', 'quick-events-manager' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $registrations ) ) : ?>
				<tr><td colspan="<?php echo esc_attr( (string) $columns ); ?>"><?php esc_html_e( 'No registrations yet.', 'quick-events-manager' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $registrations as $registration ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $registration->booker_name() ); ?></strong></td>
						<td><a href="mailto:<?php echo esc_attr( $registration->booker_email() ); ?>"><?php echo esc_html( $registration->booker_email() ); ?></a></td>
						<?php if ( array() !== $dates ) : ?>
							<td><?php echo esc_html( self::date_label( $registration, $dates ) ); ?></td>
						<?php endif; ?>
						<?php if ( array() !== $tickets ) : ?>
							<td><?php echo esc_html( self::ticket_label( $registration, $tickets ) ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( $registration->booker_phone() ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $registration->quantity() ) ); ?></td>
						<td><code><?php echo esc_html( $registration->code() ); ?></code></td>
						<td>
							<?php
							echo esc_html(
								date_i18n(
									get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
									strtotime( $registration->created_at() . ' UTC' )
								)
							);
							?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qevm-status-form">
								<input type="hidden" name="action" value="qevm_update_registration" />
								<input type="hidden" name="registration_id" value="<?php echo esc_attr( (string) $registration->id() ); ?>" />
								<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
								<?php wp_nonce_field( self::NONCE ); ?>
								<label class="screen-reader-text" for="qevm-status-<?php echo esc_attr( (string) $registration->id() ); ?>">
									<?php esc_html_e( 'Change status', 'quick-events-manager' ); ?>
								</label>
								<select name="status" id="qevm-status-<?php echo esc_attr( (string) $registration->id() ); ?>">
									<?php foreach ( RegistrationStatus::all() as $option ) : ?>
										<option value="<?php echo esc_attr( $option->value ); ?>" <?php selected( $registration->status_value(), $option->value ); ?>>
											<?php echo esc_html( $option->label() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Update', 'quick-events-manager' ); ?></button>
							</form>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qevm-resend-form">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::RESEND_ACTION ); ?>" />
								<input type="hidden" name="registration_id" value="<?php echo esc_attr( (string) $registration->id() ); ?>" />
								<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />
								<?php wp_nonce_field( self::RESEND_NONCE ); ?>
								<button type="submit" class="button button-small">
									<?php
									printf(
										/* translators: %s: Attendee name, for screen readers. */
										esc_html__( 'Resend%s', 'quick-events-manager' ),
										'<span class="screen-reader-text"> ' . esc_html(
											sprintf(
												/* translators: %s: Attendee name. */
												__( 'confirmation to %s', 'quick-events-manager' ),
												$registration->booker_name()
											)
										) . '</span>'
									);
									?>
								</button>
							</form>
						</td>
						<?php foreach ( array_keys( $extra ) as $qevm_column ) : ?>
							<td>
								<?php
								/**
								 * Filter one cell of an extra attendee column.
								 *
								 * Whatever answers is printed as it stands, so
								 * it is the answering module's job to escape
								 * it — this screen cannot escape markup it was
								 * handed without destroying it.
								 *
								 * @since 26.0
								 *
								 * @param string       $cell         Markup so far.
								 * @param string       $column       Column id.
								 * @param Registration $registration The booking.
								 */
								echo wp_kses_post( apply_filters( 'qevm_attendee_column', '', $qevm_column, $registration ) );
								?>
							</td>
						<?php endforeach; ?>
						<?php if ( array() !== $fields ) : ?>
							<td>
								<?php
								$qevm_given = isset( $answers[ $registration->id() ] ) ? $answers[ $registration->id() ] : array();

								self::render_answers( $fields, $qevm_given );
								?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The event's dates, keyed by id, or none when it has only one.
	 *
	 * Loaded once for the page rather than per row: twenty-five bookings would
	 * otherwise ask the same question twenty-five times.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return array<int, \QuickEventsManager\Events\Occurrence>
	 */
	private static function dates_for( $event_id ) {
		$occurrences = \QuickEventsManager\Events\OccurrenceRepository::for_event( (int) $event_id );

		if ( count( $occurrences ) <= 1 ) {
			return array();
		}

		$map = array();

		foreach ( $occurrences as $occurrence ) {
			$map[ $occurrence->id() ] = $occurrence;
		}

		return $map;
	}

	/**
	 * The ticket types an event offers, including withdrawn ones.
	 *
	 * Through `qevm_event_ticket_types`: with ticketing switched off there are
	 * no types, and this module never names a class from that one.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return array<int, object>
	 */
	private static function event_ticket_types( $event_id ) {
		$types = apply_filters( 'qevm_event_ticket_types', array(), (int) $event_id );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * The event's ticket types, keyed by id, or none when it offers no choice.
	 *
	 * Archived types are included, and that is the point: somebody holds a
	 * ticket of one, and a list that cannot name it has lost the fact rather
	 * than tidied it away.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return array<int, string>
	 */
	private static function tickets_for( $event_id ) {
		$map = array();

		foreach ( self::event_ticket_types( (int) $event_id ) as $type ) {
			$map[ $type->id() ] = $type->name();
		}

		return $map;
	}

	/**
	 * Which date a booking is for.
	 *
	 * A booking made before the event had several dates carries none, and says
	 * so plainly rather than being shown against a date nobody chose.
	 *
	 * @since 26.0
	 *
	 * @param Registration                                      $registration The booking.
	 * @param array<int, \QuickEventsManager\Events\Occurrence> $dates        The event's dates.
	 * @return string
	 */
	private static function date_label( Registration $registration, array $dates ) {
		$id = $registration->occurrence_id();

		if ( 0 === $id || ! isset( $dates[ $id ] ) ) {
			return __( 'Any date', 'quick-events-manager' );
		}

		return $dates[ $id ]->format_start();
	}

	/**
	 * Which kind of place a booking is for.
	 *
	 * A type that has been deleted outright is named as removed rather than
	 * left blank. Blank reads as "nobody chose", which is a different fact and
	 * the one thing this column exists to distinguish.
	 *
	 * @since 26.0
	 *
	 * @param Registration       $registration The booking.
	 * @param array<int, string> $tickets      The event's ticket types.
	 * @return string
	 */
	private static function ticket_label( Registration $registration, array $tickets ) {
		$id = $registration->ticket_type_id();

		if ( 0 === $id ) {
			return __( 'Standard', 'quick-events-manager' );
		}

		return isset( $tickets[ $id ] ) ? $tickets[ $id ] : __( 'Removed', 'quick-events-manager' );
	}

	/**
	 * The questions whose answers this screen shows.
	 *
	 * Every question, including the sensitive ones — unlike the CSV, which
	 * leaves them out unless asked. The difference is not inconsistency, it is
	 * the point: this screen is behind a capability and shows one event at a
	 * time to somebody already running it, and the caterer's numbers are the
	 * reason the question was asked. A CSV leaves the building. It gets
	 * emailed, copied to a laptop and left in a downloads folder, and it is the
	 * copy that outlives everybody's memory of why it existed.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return \QuickEventsManager\CustomFields\Field[]
	 */
	private static function answer_fields( $event_id ) {
		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\CustomFields\CustomFieldsModule::ID ) ) {
			return array();
		}

		return \QuickEventsManager\CustomFields\Definitions::for_event( $event_id );
	}

	/**
	 * One booking's answers, as a short description list.
	 *
	 * A column per question would be unreadable at twenty questions and unusable
	 * at five. One cell holding a labelled list stays legible however many were
	 * asked, and reads correctly to a screen reader as a set of pairs.
	 *
	 * @since 26.0
	 *
	 * @param \QuickEventsManager\CustomFields\Field[] $fields Questions asked.
	 * @param array<string, string|string[]>           $given  Answers given.
	 * @return void
	 */
	private static function render_answers( array $fields, array $given ) {
		$pairs = array();

		foreach ( $fields as $field ) {
			if ( ! isset( $given[ $field->key() ] ) ) {
				continue;
			}

			$value = $given[ $field->key() ];
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;

			if ( '' === trim( $value ) ) {
				continue;
			}

			$pairs[] = array(
				'label'     => $field->label(),
				'value'     => $value,
				'sensitive' => $field->is_sensitive(),
			);
		}

		if ( array() === $pairs ) {
			echo '<span aria-hidden="true">&mdash;</span>';

			return;
		}
		?>
		<dl class="qevm-answers">
			<?php foreach ( $pairs as $qevm_pair ) : ?>
				<dt<?php echo $qevm_pair['sensitive'] ? ' class="qevm-answers__label--sensitive"' : ''; ?>>
					<?php echo esc_html( $qevm_pair['label'] ); ?>
				</dt>
				<dd><?php echo esc_html( $qevm_pair['value'] ); ?></dd>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	/**
	 * Enter a booking somebody made another way.
	 *
	 * Phone calls, walk-ins and a paper sign-up sheet at the door are how a
	 * large share of any real event's attendees arrive. Without this the
	 * organiser either keeps a second list the plugin knows nothing about — so
	 * capacity, the waiting list and the attendee export are all wrong — or
	 * types the person's details into the public form pretending to be them.
	 *
	 * There is no consent checkbox here on purpose. The attendee never saw the
	 * wording, so nothing here can honestly record that they agreed to it; the
	 * note under the form says who is responsible instead.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event being added to.
	 * @return void
	 */
	private function render_add_form( Event $event ) {
		$full = RegistrationService::is_full( $event );
		?>
		<div class="qevm-add-attendee">
			<h2><?php esc_html_e( 'Add an attendee', 'quick-events-manager' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'For bookings taken by phone, by email or in person. The attendee is emailed a confirmation unless you say otherwise.', 'quick-events-manager' ); ?>
			</p>

			<?php if ( $full ) : ?>
				<p class="notice notice-warning inline">
					<?php esc_html_e( 'This event is full. Anybody you add now joins the waiting list.', 'quick-events-manager' ); ?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ADD_ACTION ); ?>" />
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>" />
				<?php wp_nonce_field( self::ADD_NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="qevm-add-name"><?php esc_html_e( 'Name', 'quick-events-manager' ); ?></label>
						</th>
						<td><input type="text" id="qevm-add-name" name="name" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qevm-add-email"><?php esc_html_e( 'Email', 'quick-events-manager' ); ?></label>
						</th>
						<td><input type="email" id="qevm-add-email" name="email" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qevm-add-phone"><?php esc_html_e( 'Phone', 'quick-events-manager' ); ?></label>
						</th>
						<td><input type="tel" id="qevm-add-phone" name="phone" class="regular-text" /></td>
					</tr>
					<?php
					/*
					 * The same two questions the public form asks, for the same
					 * reason: the service refuses a booking that does not answer
					 * them, and an organiser taking a booking by phone was left
					 * with an error naming a field that was not on the screen.
					 */
					$qevm_dates   = self::dates_for( $event->id() );
					$qevm_tickets = self::event_ticket_types( $event->id() );
					?>

					<?php if ( array() !== $qevm_dates ) : ?>
						<tr>
							<th scope="row">
								<label for="qevm-add-date"><?php esc_html_e( 'Date', 'quick-events-manager' ); ?></label>
							</th>
							<td>
								<select id="qevm-add-date" name="occurrence_id" required>
									<option value=""><?php esc_html_e( 'Choose a date', 'quick-events-manager' ); ?></option>
									<?php foreach ( $qevm_dates as $qevm_date ) : ?>
										<option value="<?php echo esc_attr( (string) $qevm_date->id() ); ?>">
											<?php echo esc_html( $qevm_date->format_start() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'A date that has already happened is allowed here — the sign-up sheet usually arrives afterwards.', 'quick-events-manager' ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( array() !== $qevm_tickets ) : ?>
						<tr>
							<th scope="row">
								<label for="qevm-add-ticket"><?php esc_html_e( 'Ticket', 'quick-events-manager' ); ?></label>
							</th>
							<td>
								<select id="qevm-add-ticket" name="qevm_ticket_type_id" required>
									<option value=""><?php esc_html_e( 'Choose a kind of place', 'quick-events-manager' ); ?></option>
									<?php foreach ( $qevm_tickets as $qevm_ticket ) : ?>
										<?php if ( ! $qevm_ticket->is_sellable() ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<option value="<?php echo esc_attr( (string) $qevm_ticket->id() ); ?>">
											<?php echo esc_html( $qevm_ticket->name() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row">
							<label for="qevm-add-quantity"><?php esc_html_e( 'Places', 'quick-events-manager' ); ?></label>
						</th>
						<td>
							<input type="number" id="qevm-add-quantity" name="quantity" value="1" min="1"
								max="<?php echo esc_attr( (string) RegistrationService::MAX_PLACES ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Confirmation', 'quick-events-manager' ); ?></th>
						<td>
							<label for="qevm-add-notify">
								<input type="checkbox" id="qevm-add-notify" name="notify" value="1" checked />
								<?php esc_html_e( 'Email the attendee a confirmation', 'quick-events-manager' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="description">
					<?php esc_html_e( 'No consent record is stored for an attendee added this way, because they were never shown the wording. Make sure you have their permission to keep their details.', 'quick-events-manager' ); ?>
				</p>

				<?php submit_button( __( 'Add attendee', 'quick-events-manager' ) ); ?>
			</form>
		</div>
		<?php
	}


	/**
	 * Enter a booking on somebody's behalf.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_add() {
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to add registrations.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::ADD_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;

		Access::require_manage( $event_id );
		$notify = ! empty( $_POST['notify'] );

		$input = array(
			'name'           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email'          => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'phone'          => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'quantity'       => isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1,

			/*
			 * Carried through from the two selects above. Without them the
			 * service refuses every booking on an event that has dates or
			 * ticket types, naming a field the organiser was never shown.
			 */
			'occurrence_id'  => isset( $_POST['occurrence_id'] ) ? absint( wp_unslash( $_POST['occurrence_id'] ) ) : 0,
			'ticket_type_id' => isset( $_POST['qevm_ticket_type_id'] ) ? absint( wp_unslash( $_POST['qevm_ticket_type_id'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/*
		 * Suppressing the email is done by unhooking the sender rather than by
		 * skipping the action, so that everything else listening to
		 * `qevm_registration_created` still runs. The organiser said "do not
		 * email them", not "pretend this booking did not happen".
		 */
		if ( ! $notify ) {
			add_filter( 'qevm_attendee_email', '__return_empty_array', 99 );
		}

		$registration = ( new RegistrationService() )->create(
			$event_id,
			$input,
			RegistrationService::CONTEXT_MANUAL
		);

		if ( ! $notify ) {
			remove_filter( 'qevm_attendee_email', '__return_empty_array', 99 );
		}

		if ( is_wp_error( $registration ) ) {
			$this->redirect_back( $event_id, 'error', $registration->get_error_message() );
		}

		$this->redirect_back(
			$event_id,
			RegistrationStatus::Waitlisted === $registration->status() ? 'added_waitlisted' : 'added'
		);
	}

	/**
	 * Send somebody their confirmation again.
	 *
	 * The most common support request an organiser gets, and until now the only
	 * answer was to read the reference code down the phone.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_resend() {
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to send confirmations.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::RESEND_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$id       = isset( $_POST['registration_id'] ) ? absint( wp_unslash( $_POST['registration_id'] ) ) : 0;
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;

		Access::require_manage( $event_id );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$registration = Repository::find( $id );

		if ( null === $registration ) {
			$this->redirect_back( $event_id, 'error', __( 'That booking could not be found.', 'quick-events-manager' ) );
		}

		$event = new Event( $registration->event_id() );

		if ( ! $event->is_valid() ) {
			$this->redirect_back( $event_id, 'error', __( 'That event could not be found.', 'quick-events-manager' ) );
		}

		/*
		 * The confirmation, not a new registration: nothing is inserted, no
		 * place is taken and `qevm_registration_created` does not fire again.
		 * Firing that would run every listener a second time — a second
		 * organiser notification, and anything a site has added of its own.
		 */
		( new Emails() )->send_attendee_confirmation( $registration, $event );

		$this->redirect_back( $event_id, 'resent' );
	}

	/**
	 * Back to the attendee list, with something to say.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event to return to.
	 * @param string $result   Result code for the notice.
	 * @param string $message  Optional detail for an error.
	 * @return never
	 */
	private function redirect_back( $event_id, $result, $message = '' ) {
		$args = array(
			'post_type' => QEVM_POST_TYPE,
			'page'      => self::SLUG,
			'event_id'  => (int) $event_id,
			'qevm_done' => $result,
		);

		if ( '' !== $message ) {
			$args['qevm_message'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );

		exit;
	}

	/**
	 * Pagination links.
	 *
	 * @since 26.0
	 *
	 * @param int    $total    Total rows.
	 * @param int    $paged    Current page.
	 * @param int    $event_id Event id.
	 * @param string $status   Active status filter.
	 * @param string $search   Active search term.
	 * @return void
	 */
	private function render_pagination( $total, $paged, $event_id, $status, $search ) {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'prev_text' => __( '&laquo;', 'quick-events-manager' ),
				'next_text' => __( '&raquo;', 'quick-events-manager' ),
				'total'     => $pages,
				'current'   => $paged,
				'add_args'  => array_filter(
					array(
						'post_type' => QEVM_POST_TYPE,
						'page'      => self::SLUG,
						'event_id'  => $event_id,
						'status'    => $status,
						's'         => $search,
					)
				),
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * Apply a status change.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle_update() {
		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to change registrations.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::NONCE );

		$id       = isset( $_POST['registration_id'] ) ? absint( wp_unslash( $_POST['registration_id'] ) ) : 0;
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;

		Access::require_manage( $event_id );
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		$new_status = RegistrationStatus::coerce( $status );

		if ( $id > 0 && $new_status instanceof RegistrationStatus ) {
			/*
			 * `qevm_registration_status_changed` used to be fired here. It now
			 * fires inside Repository::update_status(), because this screen
			 * stopped being the only way a status changes the moment
			 * cancellation links existed — and a hook that fires on one of two
			 * routes is worse than no hook, since what listens to it appears to
			 * work.
			 */
			Repository::update_status( $id, $new_status );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => QEVM_POST_TYPE,
					'page'      => self::SLUG,
					'event_id'  => $event_id,
				),
				admin_url( 'edit.php' )
			)
		);

		exit;
	}
}
