<?php
/**
 * Choosing an organiser record on the event editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Organizers;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Pick an organiser, or keep the contact details on the event.
 *
 * Choosing a record is optional even with the module on, and for organisers
 * that matters more than it does for venues: most sites have exactly one
 * organiser, which is the site owner, and a reusable record for a contact that
 * never varies is filing for its own sake. Leaving this on "organiser on this
 * event" is a permanent, supported state.
 *
 * A box of its own rather than a field inside the event details box, so the
 * event editor knows nothing about organisers. Switching the module off takes
 * this away and leaves the rest of the editor untouched.
 *
 * @since 26.0
 */
final class EventOrganizerBox {

	/**
	 * Nonce action for saving.
	 */
	const NONCE = 'qevm_save_event_organizer';

	/**
	 * Request key carrying the chosen organiser.
	 */
	const FIELD = 'qevm_organizer_id';

	/**
	 * Priority for the save hook.
	 *
	 * Later than the event details box, which runs at 10, and that ordering is
	 * load-bearing. Both write the event's own organiser meta: the details box
	 * from its inputs, this box by copying the chosen record over the top.
	 * Running first would mean the details box overwrote the copy, and the
	 * fallback contact would be stale the moment a record was chosen.
	 */
	const SAVE_PRIORITY = 20;

	/**
	 * How many organisers the dropdown will list.
	 */
	const MAX_CHOICES = 100;

	/**
	 * Hook into the event editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEVM_POST_TYPE, array( $this, 'save' ), self::SAVE_PRIORITY );
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
			'qevm-event-organizer',
			__( 'Organiser', 'quick-events-manager' ),
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
		wp_nonce_field( self::NONCE, 'qevm_event_organizer_nonce' );

		$selected = (int) get_post_meta( $post->ID, Meta::ORGANIZER_ID, true );
		$records  = self::choices( $selected );
		?>
		<p>
			<label class="screen-reader-text" for="<?php echo esc_attr( self::FIELD ); ?>">
				<?php esc_html_e( 'Organiser', 'quick-events-manager' ); ?>
			</label>
			<select id="<?php echo esc_attr( self::FIELD ); ?>" name="<?php echo esc_attr( self::FIELD ); ?>" class="widefat">
				<option value="0"><?php esc_html_e( '— Organiser on this event —', 'quick-events-manager' ); ?></option>
				<?php foreach ( $records as $record_id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $record_id ); ?>" <?php selected( $selected, $record_id ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">
			<?php esc_html_e( 'Choosing an organiser fills in the contact details under Location and organiser, and keeps them in step with the record.', 'quick-events-manager' ); ?>
		</p>
		<?php if ( array() === $records ) : ?>
			<p class="description">
				<?php esc_html_e( 'No organisers yet. Add one under Events → Organisers.', 'quick-events-manager' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the chosen organiser, and copy its details onto the event.
	 *
	 * The copy is what makes the record removable. Every render path falls back
	 * to the event's own organiser meta when no record resolves, so keeping that
	 * meta filled means switching the module off, deleting an organiser or
	 * trashing one leaves the event still saying who to contact.
	 *
	 * @since 26.0
	 *
	 * @param int $post_id Event id.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['qevm_event_organizer_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_event_organizer_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$organizer_id = isset( $_POST[ self::FIELD ] ) ? absint( wp_unslash( $_POST[ self::FIELD ] ) ) : 0;
		$organizer    = $organizer_id > 0 ? Organizer::from_post( $organizer_id ) : null;

		if ( null === $organizer ) {
			update_post_meta( $post_id, Meta::ORGANIZER_ID, 0 );

			return;
		}

		update_post_meta( $post_id, Meta::ORGANIZER_ID, $organizer->id() );

		foreach ( $organizer->parts() as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Published organisers, as id => title, with the current one always included.
	 *
	 * Including the selected record even when it falls outside the cap is not a
	 * nicety. A `<select>` posts whichever option is selected, so a record
	 * missing from the list would submit as "none" and the save would quietly
	 * unassign it — the event would keep the contact details, because those are
	 * copied onto it, and the link back to the record would be gone with nothing
	 * on screen to say so.
	 *
	 * @since 26.0
	 *
	 * @param int $selected Organiser currently assigned to the event, or 0.
	 * @return array<int, string>
	 */
	private static function choices( $selected = 0 ) {
		$posts = get_posts(
			array(
				'post_type'              => QEVM_POST_TYPE_ORGANIZER,
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_CHOICES,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$choices = array();

		foreach ( $posts as $record ) {
			$choices[ (int) $record->ID ] = (string) $record->post_title;
		}

		$selected = (int) $selected;

		if ( $selected > 0 && ! isset( $choices[ $selected ] ) ) {
			$current = get_post( $selected );

			if ( $current instanceof \WP_Post && QEVM_POST_TYPE_ORGANIZER === $current->post_type ) {
				$choices[ $selected ] = (string) $current->post_title;

				asort( $choices, SORT_NATURAL | SORT_FLAG_CASE );
			}
		}

		return $choices;
	}
}
