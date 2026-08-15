<?php
/**
 * The event details box on the editor screen.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Collects when and where an event happens.
 *
 * Deliberately one box with a short list of fields. Location and organiser
 * details are folded away behind a toggle, so the screen someone sees on their
 * first event asks for a date and nothing else.
 *
 * @since 26.0
 */
final class MetaBox {

	/**
	 * Nonce action for saving.
	 */
	const NONCE = 'qevm_save_event_details';

	/**
	 * Plain text fields, as request key => meta key.
	 *
	 * Saving writes every key in this list, using `''` when the request does
	 * not carry it — which is right for a checkbox-free text field, because a
	 * field the user emptied arrives as an empty string and a field they never
	 * touched arrives with its old value.
	 *
	 * It is only right while the form renders an input for every key here. It
	 * did not: `qevm_organizer_phone` was in this list with no field on the
	 * screen, so every save of every event wrote an empty string over any phone
	 * number set through the REST API or by code, and nothing reported it.
	 * `MetaBoxFieldsTest` now renders the box and fails if a key in this list
	 * has no input, which is the version of this bug that cannot come back.
	 *
	 * @since 26.0
	 * @var array<string, string>
	 */
	const TEXT_FIELDS = array(
		'qevm_venue_name'        => Meta::VENUE_NAME,
		'qevm_venue_address'     => Meta::VENUE_ADDRESS,
		'qevm_venue_city'        => Meta::VENUE_CITY,
		'qevm_venue_region'      => Meta::VENUE_REGION,
		'qevm_venue_postal_code' => Meta::VENUE_POSTAL,
		'qevm_venue_country'     => Meta::VENUE_COUNTRY,
		'qevm_organizer_name'    => Meta::ORGANIZER_NAME,
		'qevm_organizer_phone'   => Meta::ORGANIZER_PHONE,
	);

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
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
			'qevm-event-details',
			__( 'Event Details', 'quick-events-manager' ),
			array( $this, 'render' ),
			QEVM_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Load the editor styles and script.
	 *
	 * @since 26.0
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( get_post_type() !== QEVM_POST_TYPE ) {
			return;
		}

		wp_enqueue_style( 'qevm-admin', QEVM_URL . 'assets/css/admin.css', array(), QEVM_VERSION );
		wp_enqueue_script( 'qevm-admin', QEVM_URL . 'assets/js/admin.js', array(), QEVM_VERSION, true );
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
		$timezone = $event->timezone();

		wp_nonce_field( self::NONCE, 'qevm_event_details_nonce' );

		$start   = $event->start_local();
		$end     = $event->end_local();
		$all_day = $event->is_all_day();
		$online  = $event->is_online();
		?>
		<div class="qevm-fields">
			<div class="qevm-field-row">
				<div class="qevm-field">
					<label for="qevm_start_local"><?php esc_html_e( 'Starts', 'quick-events-manager' ); ?></label>
					<input type="datetime-local" id="qevm_start_local" name="qevm_start_local"
						value="<?php echo esc_attr( self::to_input( $start ) ); ?>" />
				</div>
				<div class="qevm-field">
					<label for="qevm_end_local"><?php esc_html_e( 'Ends', 'quick-events-manager' ); ?></label>
					<input type="datetime-local" id="qevm_end_local" name="qevm_end_local"
						value="<?php echo esc_attr( self::to_input( $end ) ); ?>" />
				</div>
			</div>

			<p class="qevm-checkbox">
				<label>
					<input type="checkbox" name="qevm_all_day" value="1" <?php checked( $all_day ); ?> />
					<?php esc_html_e( 'All-day event', 'quick-events-manager' ); ?>
				</label>
			</p>

			<div class="qevm-field">
				<label for="qevm_timezone"><?php esc_html_e( 'Timezone', 'quick-events-manager' ); ?></label>
				<select id="qevm_timezone" name="qevm_timezone">
					<?php echo wp_kses( self::timezone_options( $timezone ), self::allowed_option_html() ); ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Times are shown to visitors in this timezone, whatever your site timezone is.', 'quick-events-manager' ); ?>
				</p>
			</div>

			<p class="qevm-checkbox">
				<label>
					<input type="checkbox" id="qevm_is_online" name="qevm_is_online" value="1" <?php checked( $online ); ?> />
					<?php esc_html_e( 'This is an online event', 'quick-events-manager' ); ?>
				</label>
			</p>

			<div class="qevm-field qevm-online-only" <?php echo $online ? '' : 'hidden'; ?>>
				<label for="qevm_online_url"><?php esc_html_e( 'Joining link', 'quick-events-manager' ); ?></label>
				<input type="url" id="qevm_online_url" name="qevm_online_url" class="widefat"
					value="<?php echo esc_attr( $event->online_url() ); ?>" placeholder="https://" />
			</div>

			<details class="qevm-more" <?php echo $event->has_location_details() ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Location and organiser', 'quick-events-manager' ); ?></summary>

				<div class="qevm-venue-only" <?php echo $online ? 'hidden' : ''; ?>>
					<?php
					self::text_field( 'qevm_venue_name', __( 'Venue name', 'quick-events-manager' ), $event->meta( Meta::VENUE_NAME ) );
					self::text_field( 'qevm_venue_address', __( 'Address', 'quick-events-manager' ), $event->meta( Meta::VENUE_ADDRESS ) );
					?>
					<div class="qevm-field-row">
						<?php
						self::text_field( 'qevm_venue_city', __( 'City', 'quick-events-manager' ), $event->meta( Meta::VENUE_CITY ) );
						self::text_field( 'qevm_venue_region', __( 'State / region', 'quick-events-manager' ), $event->meta( Meta::VENUE_REGION ) );
						?>
					</div>
					<div class="qevm-field-row">
						<?php
						self::text_field( 'qevm_venue_postal_code', __( 'Postal code', 'quick-events-manager' ), $event->meta( Meta::VENUE_POSTAL ) );
						self::text_field( 'qevm_venue_country', __( 'Country', 'quick-events-manager' ), $event->meta( Meta::VENUE_COUNTRY ) );
						?>
					</div>
				</div>

				<div class="qevm-field-row">
					<?php
					self::text_field( 'qevm_organizer_name', __( 'Organiser', 'quick-events-manager' ), $event->meta( Meta::ORGANIZER_NAME ) );
					self::text_field( 'qevm_organizer_email', __( 'Organiser email', 'quick-events-manager' ), $event->meta( Meta::ORGANIZER_EMAIL ), 'email' );
					?>
				</div>

				<div class="qevm-field-row">
					<?php
					self::text_field( 'qevm_organizer_phone', __( 'Organiser phone', 'quick-events-manager' ), $event->meta( Meta::ORGANIZER_PHONE ), 'tel' );
					?>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Save the submitted details.
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

		$nonce = isset( $_POST['qevm_event_details_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_event_details_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$timezone = isset( $_POST['qevm_timezone'] )
			? Meta::sanitize_timezone( sanitize_text_field( wp_unslash( $_POST['qevm_timezone'] ) ) )
			: '';

		if ( '' === $timezone ) {
			$timezone = Meta::site_timezone();
		}

		$start_local = self::from_input( isset( $_POST['qevm_start_local'] ) ? sanitize_text_field( wp_unslash( $_POST['qevm_start_local'] ) ) : '' );
		$end_local   = self::from_input( isset( $_POST['qevm_end_local'] ) ? sanitize_text_field( wp_unslash( $_POST['qevm_end_local'] ) ) : '' );

		/*
		 * An end before the start is a typo, not an intention. Dropping it is
		 * kinder than storing a negative-length event that then sorts oddly
		 * and breaks the "is it over yet" comparison.
		 */
		if ( '' !== $start_local && '' !== $end_local && $end_local < $start_local ) {
			$end_local = '';
		}

		update_post_meta( $post_id, Meta::TIMEZONE, $timezone );
		update_post_meta( $post_id, Meta::START_LOCAL, $start_local );
		update_post_meta( $post_id, Meta::END_LOCAL, $end_local );
		update_post_meta( $post_id, Meta::START_UTC, Meta::to_utc( $start_local, $timezone ) );
		update_post_meta( $post_id, Meta::END_UTC, Meta::to_utc( $end_local, $timezone ) );

		update_post_meta( $post_id, Meta::ALL_DAY, isset( $_POST['qevm_all_day'] ) ? 1 : 0 );
		update_post_meta( $post_id, Meta::IS_ONLINE, isset( $_POST['qevm_is_online'] ) ? 1 : 0 );

		update_post_meta(
			$post_id,
			Meta::ONLINE_URL,
			isset( $_POST['qevm_online_url'] ) ? esc_url_raw( wp_unslash( $_POST['qevm_online_url'] ) ) : ''
		);

		foreach ( self::TEXT_FIELDS as $field => $meta_key ) {
			update_post_meta(
				$post_id,
				$meta_key,
				isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''
			);
		}

		update_post_meta(
			$post_id,
			Meta::ORGANIZER_EMAIL,
			isset( $_POST['qevm_organizer_email'] ) ? sanitize_email( wp_unslash( $_POST['qevm_organizer_email'] ) ) : ''
		);
	}

	/**
	 * Render one labelled text input.
	 *
	 * @since 26.0
	 *
	 * @param string $name  Field name.
	 * @param string $label Label text.
	 * @param string $value Current value.
	 * @param string $type  Input type.
	 * @return void
	 */
	private static function text_field( $name, $label, $value, $type = 'text' ) {
		?>
		<div class="qevm-field">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $name ); ?>"
				name="<?php echo esc_attr( $name ); ?>" class="widefat"
				value="<?php echo esc_attr( $value ); ?>" />
		</div>
		<?php
	}

	/**
	 * Build the timezone dropdown.
	 *
	 * Core's wp_timezone_choice() renders a full optgroup list already
	 * translated and grouped by continent, so there is no reason to build one.
	 *
	 * @since 26.0
	 *
	 * @param string $selected Currently selected zone.
	 * @return string HTML option markup.
	 */
	private static function timezone_options( $selected ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		return wp_timezone_choice( $selected );
	}

	/**
	 * The subset of HTML wp_timezone_choice() emits.
	 *
	 * @since 26.0
	 *
	 * @return array<string, array<string, array<string, bool>>>
	 */
	private static function allowed_option_html() {
		return array(
			'optgroup' => array( 'label' => array() ),
			'option'   => array(
				'value'    => array(),
				'selected' => array(),
			),
		);
	}

	/**
	 * Convert stored `Y-m-d H:i:s` to the value a datetime-local input wants.
	 *
	 * @since 26.0
	 *
	 * @param string $stored Stored datetime.
	 * @return string
	 */
	private static function to_input( $stored ) {
		if ( '' === $stored ) {
			return '';
		}

		return str_replace( ' ', 'T', substr( $stored, 0, 16 ) );
	}

	/**
	 * Convert a datetime-local input value back to `Y-m-d H:i:s`.
	 *
	 * @since 26.0
	 *
	 * @param string $input Submitted value.
	 * @return string Normalised datetime, or ''.
	 */
	private static function from_input( $input ) {
		if ( '' === trim( $input ) ) {
			return '';
		}

		$value = str_replace( 'T', ' ', trim( $input ) );

		if ( 16 === strlen( $value ) ) {
			$value .= ':00';
		}

		return Meta::sanitize_datetime( $value );
	}
}
