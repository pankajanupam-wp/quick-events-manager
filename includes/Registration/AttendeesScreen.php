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
	 * Hook into the admin.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_qevm_update_registration', array( $this, 'handle_update' ) );
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

		echo '<div class="wrap qevm-attendees">';
		echo '<h1>' . esc_html__( 'Attendees', 'quick-events-manager' ) . '</h1>';

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

		echo '</div>';
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
		echo '<select name="event_id">';

		foreach ( $events as $event_post ) {
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
		</form>
		<?php
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
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Phone', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Places', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reference', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Registered', 'quick-events-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'quick-events-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $registrations ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No registrations yet.', 'quick-events-manager' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $registrations as $registration ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $registration->name() ); ?></strong></td>
						<td><a href="mailto:<?php echo esc_attr( $registration->email() ); ?>"><?php echo esc_html( $registration->email() ); ?></a></td>
						<td><?php echo esc_html( $registration->phone() ); ?></td>
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
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
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
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		$new_status = RegistrationStatus::coerce( $status );

		if ( $id > 0 && $new_status instanceof RegistrationStatus ) {
			Repository::update_status( $id, $new_status );

			/**
			 * Fires after an administrator changes a registration's status.
			 *
			 * @since 26.0
			 *
			 * @param int    $id     Registration id.
			 * @param string $status New status.
			 */
			do_action( 'qevm_registration_status_changed', $id, $status );
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
