<?php
/**
 * The contact box on the organiser editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Organizers;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * How to reach an organiser.
 *
 * The name is the post title and any description is the editor, so this box
 * holds the contact details and nothing else.
 *
 * Both the form and the save routine are generated from `FIELDS`, so a field
 * cannot be saved without being rendered. The event editor kept its two lists
 * by hand and one of them silently wiped the organiser's phone number on every
 * save for as long as it existed.
 *
 * @since 26.0
 */
final class OrganizerMetaBox {

	/**
	 * Nonce action for saving.
	 */
	const NONCE = 'qevm_save_organizer_contact';

	/**
	 * Contact fields, as request key => meta key.
	 *
	 * @var array<string, string>
	 */
	const FIELDS = array(
		'qevm_organizer_email' => Meta::ORGANIZER_EMAIL,
		'qevm_organizer_phone' => Meta::ORGANIZER_PHONE,
		'qevm_organizer_url'   => Meta::ORGANIZER_URL,
	);

	/**
	 * Hook into the organiser editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEVM_POST_TYPE_ORGANIZER, array( $this, 'save' ) );
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
			'qevm-organizer-contact',
			__( 'Contact details', 'quick-events-manager' ),
			array( $this, 'render' ),
			QEVM_POST_TYPE_ORGANIZER,
			'normal',
			'high'
		);
	}

	/**
	 * Render the box.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Post $post Organiser being edited.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, 'qevm_organizer_contact_nonce' );

		$labels = self::labels();
		$types  = self::types();
		?>
		<div class="qevm-fields">
			<?php foreach ( self::FIELDS as $field => $meta_key ) : ?>
				<div class="qevm-field">
					<label for="<?php echo esc_attr( $field ); ?>">
						<?php echo esc_html( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ); ?>
					</label>
					<input type="<?php echo esc_attr( isset( $types[ $field ] ) ? $types[ $field ] : 'text' ); ?>"
						id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"
						class="widefat" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, $meta_key, true ) ); ?>" />
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Save the submitted contact details.
	 *
	 * @since 26.0
	 *
	 * @param int $post_id Organiser id.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['qevm_organizer_contact_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_organizer_contact_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( self::FIELDS as $field => $meta_key ) {
			/*
			 * The sniff looks for a sanitising call wrapping the superglobal and
			 * cannot see that self::sanitize() is one — it picks the right
			 * function for the field, because an email and a URL are not
			 * sanitised the same way and neither is sanitize_text_field().
			 */
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; self::sanitize() is the sanitiser.
			$value = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : '';

			update_post_meta( $post_id, $meta_key, self::sanitize( $meta_key, $value ) );
		}
	}

	/**
	 * Sanitise a value the way the event's own copy of the field is sanitised.
	 *
	 * @since 26.0
	 *
	 * @param string $meta_key Meta key.
	 * @param string $value    Raw value.
	 * @return string
	 */
	private static function sanitize( $meta_key, $value ) {
		if ( Meta::ORGANIZER_EMAIL === $meta_key ) {
			return sanitize_email( $value );
		}

		if ( Meta::ORGANIZER_URL === $meta_key ) {
			return esc_url_raw( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Field labels, kept out of the constant so they can be translated.
	 *
	 * A `const` is built at compile time and cannot call `__()`; putting the
	 * labels in the array would freeze them in English.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string>
	 */
	private static function labels() {
		return array(
			'qevm_organizer_email' => __( 'Email', 'quick-events-manager' ),
			'qevm_organizer_phone' => __( 'Phone', 'quick-events-manager' ),
			'qevm_organizer_url'   => __( 'Website', 'quick-events-manager' ),
		);
	}

	/**
	 * Input types, so browsers offer the right keyboard and validation.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string>
	 */
	private static function types() {
		return array(
			'qevm_organizer_email' => 'email',
			'qevm_organizer_phone' => 'tel',
			'qevm_organizer_url'   => 'url',
		);
	}
}
