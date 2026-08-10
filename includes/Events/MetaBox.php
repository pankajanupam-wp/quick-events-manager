<?php
/**
 * The event details box on the editor screen.
 *
 * @package QuickEventsManager
 */

namespace QEM\Events;

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
	const NONCE = 'qem_save_event_details';

	/**
	 * Hook into the editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEM_POST_TYPE, array( $this, 'save' ), 10, 2 );
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
			'qem-event-details',
			__( 'Event Details', 'quick-events-manager' ),
			array( $this, 'render' ),
			QEM_POST_TYPE,
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

		if ( get_post_type() !== QEM_POST_TYPE ) {
			return;
		}

		wp_enqueue_style( 'qem-admin', QEM_URL . 'assets/css/admin.css', array(), QEM_VERSION );
		wp_enqueue_script( 'qem-admin', QEM_URL . 'assets/js/admin.js', array(), QEM_VERSION, true );
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

		wp_nonce_field( self::NONCE, 'qem_event_details_nonce' );

		$start   = $event->start_local();
		$end     = $event->end_local();
		$all_day = $event->is_all_day();
		$online  = $event->is_online();
		?>
		<div class="qem-fields">
			<div class="qem-field-row">
				<div class="qem-field">
					<label for="qem_start_local"><?php esc_html_e( 'Starts', 'quick-events-manager' ); ?></label>
					<input type="datetime-local" id="qem_start_local" name="qem_start_local"
						value="<?php echo esc_attr( self::to_input( $start ) ); ?>" />
				</div>
				<div class="qem-field">
					<label for="qem_end_local"><?php esc_html_e( 'Ends', 'quick-events-manager' ); ?></label>
					<input type="datetime-local" id="qem_end_local" name="qem_end_local"
						value="<?php echo esc_attr( self::to_input( $end ) ); ?>" />
				</div>
			</div>

			<p class="qem-checkbox">
				<label>
					<input type="checkbox" name="qem_all_day" value="1" <?php checked( $all_day ); ?> />
					<?php esc_html_e( 'All-day event', 'quick-events-manager' ); ?>
				</label>
			</p>

			<div class="qem-field">
				<label for="qem_timezone"><?php esc_html_e( 'Timezone', 'quick-events-manager' ); ?></label>
				<select id="qem_timezone" name="qem_timezone">
					<?php echo wp_kses( self::timezone_options( $timezone ), self::allowed_option_html() ); ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Times are shown to visitors in this timezone, whatever your site timezone is.', 'quick-events-manager' ); ?>
				</p>
			</div>

			<p class="qem-checkbox">
				<label>
					<input type="checkbox" id="qem_is_online" name="qem_is_online" value="1" <?php checked( $online ); ?> />
					<?php esc_html_e( 'This is an online event', 'quick-events-manager' ); ?>
				</label>
			</p>

			<div class="qem-field qem-online-only" <?php echo $online ? '' : 'hidden'; ?>>
				<label for="qem_online_url"><?php esc_html_e( 'Joining link', 'quick-events-manager' ); ?></label>
				<input type="url" id="qem_online_url" name="qem_online_url" class="widefat"
					value="<?php echo esc_attr( $event->online_url() ); ?>" placeholder="https://" />
			</div>

			<details class="qem-more" <?php echo $event->has_location_details() ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Location and organiser', 'quick-events-manager' ); ?></summary>

				<div class="qem-venue-only" <?php echo $online ? 'hidden' : ''; ?>>
					<?php
					self::text_field( 'qem_venue_name', __( 'Venue name', 'quick-events-manager' ), $event->meta( Meta::VENUE_NAME ) );
					self::text_field( 'qem_venue_address', __( 'Address', 'quick-events-manager' ), $event->meta( Meta::VENUE_ADDRESS ) );
					?>
					<div class="qem-field-row">
						<?php
						self::text_field( 'qem_venue_city', __( 'City', 'quick-events-manager' ), $event->meta( Meta::VENUE_CITY ) );
						self::text_field( 'qem_venue_region', __( 'State / region', 'quick-events-manager' ), $event->meta( Meta::VENUE_REGION ) );
						?>
					</div>
					<div class="qem-field-row">
						<?php
						self::text_field( 'qem_venue_postal_code', __( 'Postal code', 'quick-events-manager' ), $event->meta( Meta::VENUE_POSTAL ) );
						self::text_field( 'qem_venue_country', __( 'Country', 'quick-events-manager' ), $event->meta( Meta::VENUE_COUNTRY ) );
						?>
					</div>
				</div>

				<div class="qem-field-row">
					<?php
					self::text_field( 'qem_organizer_name', __( 'Organiser', 'quick-events-manager' ), $event->meta( Meta::ORGANIZER_NAME ) );
					self::text_field( 'qem_organizer_email', __( 'Organiser email', 'quick-events-manager' ), $event->meta( Meta::ORGANIZER_EMAIL ), 'email' );
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
	 * @param \WP_Post $post    Event.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['qem_event_details_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qem_event_details_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$timezone = isset( $_POST['qem_timezone'] )
			? Meta::sanitize_timezone( sanitize_text_field( wp_unslash( $_POST['qem_timezone'] ) ) )
			: '';

		if ( '' === $timezone ) {
			$timezone = Meta::site_timezone();
		}

		$start_local = self::from_input( isset( $_POST['qem_start_local'] ) ? sanitize_text_field( wp_unslash( $_POST['qem_start_local'] ) ) : '' );
		$end_local   = self::from_input( isset( $_POST['qem_end_local'] ) ? sanitize_text_field( wp_unslash( $_POST['qem_end_local'] ) ) : '' );

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

		update_post_meta( $post_id, Meta::ALL_DAY, isset( $_POST['qem_all_day'] ) ? 1 : 0 );
		update_post_meta( $post_id, Meta::IS_ONLINE, isset( $_POST['qem_is_online'] ) ? 1 : 0 );

		update_post_meta(
			$post_id,
			Meta::ONLINE_URL,
			isset( $_POST['qem_online_url'] ) ? esc_url_raw( wp_unslash( $_POST['qem_online_url'] ) ) : ''
		);

		$text_fields = array(
			'qem_venue_name'        => Meta::VENUE_NAME,
			'qem_venue_address'     => Meta::VENUE_ADDRESS,
			'qem_venue_city'        => Meta::VENUE_CITY,
			'qem_venue_region'      => Meta::VENUE_REGION,
			'qem_venue_postal_code' => Meta::VENUE_POSTAL,
			'qem_venue_country'     => Meta::VENUE_COUNTRY,
			'qem_organizer_name'    => Meta::ORGANIZER_NAME,
			'qem_organizer_phone'   => Meta::ORGANIZER_PHONE,
		);

		foreach ( $text_fields as $field => $meta_key ) {
			update_post_meta(
				$post_id,
				$meta_key,
				isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''
			);
		}

		update_post_meta(
			$post_id,
			Meta::ORGANIZER_EMAIL,
			isset( $_POST['qem_organizer_email'] ) ? sanitize_email( wp_unslash( $_POST['qem_organizer_email'] ) ) : ''
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
		<div class="qem-field">
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
	 * @return array
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
