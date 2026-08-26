<?php
/**
 * The address box on the venue editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Where a venue is.
 *
 * The name is the post title and the description is the editor, so this box
 * holds the address and nothing else.
 *
 * Both the form and the save routine are generated from `FIELDS`, so a field
 * cannot be saved without being rendered. That is not tidiness: the event box
 * kept its two lists by hand and one of them silently wiped the organiser's
 * phone number on every save for as long as it existed.
 *
 * @since 26.0
 */
final class VenueMetaBox {

	/**
	 * Nonce action for saving.
	 */
	const NONCE = 'qevm_save_venue_address';

	/**
	 * Address fields, as request key => meta key.
	 *
	 * @var array<string, string>
	 */
	const FIELDS = array(
		'qevm_venue_address'     => Meta::VENUE_ADDRESS,
		'qevm_venue_city'        => Meta::VENUE_CITY,
		'qevm_venue_region'      => Meta::VENUE_REGION,
		'qevm_venue_postal_code' => Meta::VENUE_POSTAL,
		'qevm_venue_country'     => Meta::VENUE_COUNTRY,
	);

	/**
	 * Hook into the venue editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEVM_POST_TYPE_VENUE, array( $this, 'save' ) );
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
			'qevm-venue-address',
			__( 'Address', 'quick-events-manager' ),
			array( $this, 'render' ),
			QEVM_POST_TYPE_VENUE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the box.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Post $post Venue being edited.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, 'qevm_venue_address_nonce' );

		$labels = self::labels();
		?>
		<div class="qevm-fields">
			<?php foreach ( self::FIELDS as $field => $meta_key ) : ?>
				<div class="qevm-field">
					<label for="<?php echo esc_attr( $field ); ?>">
						<?php echo esc_html( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ); ?>
					</label>
					<input type="text" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"
						class="widefat" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, $meta_key, true ) ); ?>" />
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Save the submitted address.
	 *
	 * @since 26.0
	 *
	 * @param int $post_id Venue id.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['qevm_venue_address_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_venue_address_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( self::FIELDS as $field => $meta_key ) {
			update_post_meta(
				$post_id,
				$meta_key,
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
				isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''
			);
		}
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
			'qevm_venue_address'     => __( 'Address', 'quick-events-manager' ),
			'qevm_venue_city'        => __( 'City', 'quick-events-manager' ),
			'qevm_venue_region'      => __( 'State / region', 'quick-events-manager' ),
			'qevm_venue_postal_code' => __( 'Postal code', 'quick-events-manager' ),
			'qevm_venue_country'     => __( 'Country', 'quick-events-manager' ),
		);
	}
}
