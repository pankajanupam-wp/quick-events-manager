<?php
/**
 * Per-event registration settings.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * The "Registration" box on the event editor.
 *
 * Registration is off per event as well as per site: switching the module on
 * does not suddenly put a form on every event that already exists. The
 * organiser opts in for each event, which is the behaviour that cannot
 * surprise anybody.
 *
 * @since 26.0
 */
final class EventMetaBox {

	/**
	 * Nonce action.
	 */
	const NONCE = 'qevm_save_event_registration';

	/**
	 * Hook into the editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEVM_POST_TYPE, array( $this, 'save' ), 10, 2 );
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
			'qevm-event-registration',
			__( 'Registration', 'quick-events-manager' ),
			array( $this, 'render' ),
			QEVM_POST_TYPE,
			'side',
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
		$event    = new Event( $post );
		$enabled  = (bool) $event->meta( Meta::REGISTRATION_ENABLED, false );
		$capacity = (int) $event->meta( Meta::CAPACITY, 0 );
		$closes   = (string) $event->meta( Meta::REGISTRATION_CLOSES );
		$taken    = Repository::count_taken( $event->id() );

		wp_nonce_field( self::NONCE, 'qevm_event_registration_nonce' );
		?>
		<p class="qevm-checkbox">
			<label>
				<input type="checkbox" name="qevm_registration_enabled" value="1" <?php checked( $enabled ); ?> />
				<strong><?php esc_html_e( 'Let people register', 'quick-events-manager' ); ?></strong>
			</label>
		</p>

		<p>
			<label for="qevm_capacity"><?php esc_html_e( 'Places available', 'quick-events-manager' ); ?></label><br />
			<input type="number" min="0" step="1" id="qevm_capacity" name="qevm_capacity" class="small-text"
				value="<?php echo esc_attr( (string) $capacity ); ?>" />
			<span class="description"><?php esc_html_e( '0 for unlimited', 'quick-events-manager' ); ?></span>
		</p>

		<p>
			<label for="qevm_registration_closes"><?php esc_html_e( 'Registration closes', 'quick-events-manager' ); ?></label><br />
			<input type="datetime-local" id="qevm_registration_closes" name="qevm_registration_closes"
				value="<?php echo esc_attr( '' !== $closes ? str_replace( ' ', 'T', substr( Meta::to_local( $closes, $event->timezone() ), 0, 16 ) ) : '' ); ?>" />
			<span class="description"><?php esc_html_e( 'Leave empty to accept registrations until the event starts.', 'quick-events-manager' ); ?></span>
		</p>

		<?php if ( $taken > 0 ) : ?>
			<p class="qevm-registration-count">
				<?php
				printf(
					/* translators: %s: Number of places taken. */
					esc_html( _n( '%s place taken so far.', '%s places taken so far.', $taken, 'quick-events-manager' ) ),
					esc_html( number_format_i18n( $taken ) )
				);
				?>
				<br />
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . QEVM_POST_TYPE . '&page=' . AttendeesScreen::SLUG . '&event_id=' . $event->id() ) ); ?>">
					<?php esc_html_e( 'View attendees', 'quick-events-manager' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the submitted settings.
	 *
	 * @since 26.0
	 *
	 * @param int      $post_id Event id.
	 * @param \WP_Post $post    Event.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST['qevm_event_registration_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_event_registration_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Meta::REGISTRATION_ENABLED, isset( $_POST['qevm_registration_enabled'] ) ? 1 : 0 );
		update_post_meta( $post_id, Meta::CAPACITY, isset( $_POST['qevm_capacity'] ) ? absint( wp_unslash( $_POST['qevm_capacity'] ) ) : 0 );

		$closes_local = isset( $_POST['qevm_registration_closes'] )
			? str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST['qevm_registration_closes'] ) ) )
			: '';

		if ( 16 === strlen( $closes_local ) ) {
			$closes_local .= ':00';
		}

		$timezone = (string) get_post_meta( $post_id, Meta::TIMEZONE, true );

		update_post_meta(
			$post_id,
			Meta::REGISTRATION_CLOSES,
			'' !== $closes_local ? Meta::to_utc( $closes_local, '' !== $timezone ? $timezone : Meta::site_timezone() ) : ''
		);
	}
}
