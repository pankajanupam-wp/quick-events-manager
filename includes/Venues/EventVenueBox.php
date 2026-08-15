<?php
/**
 * Choosing a venue record on the event editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Pick a venue, or keep typing the address on the event.
 *
 * Choosing a record is optional even with the module on. Plenty of events
 * happen somewhere once and never again, and forcing a reusable record for a
 * church hall booked one afternoon is more admin than the address it saves.
 * Leaving this on "no venue" is a permanent, supported state, not a migration
 * somebody has not got round to.
 *
 * A box of its own rather than a field inside the event details box, so that
 * the event editor knows nothing about venues. Switching the module off takes
 * this screen away entirely and leaves the rest of the editor untouched.
 *
 * @since 26.0
 */
final class EventVenueBox {

	/**
	 * Nonce action for saving.
	 */
	const NONCE = 'qevm_save_event_venue';

	/**
	 * Request key carrying the chosen venue.
	 */
	const FIELD = 'qevm_venue_id';

	/**
	 * Priority for the save hook.
	 *
	 * Later than the event details box, which runs at 10, and that ordering is
	 * load-bearing. Both write the event's flat address meta: the details box
	 * from its own inputs, this box by copying the chosen venue's address over
	 * the top. Running first would mean the details box overwrote the copy with
	 * whatever was in the form, and the fallback address would be stale the
	 * moment a venue was chosen.
	 */
	const SAVE_PRIORITY = 20;

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
			'qevm-event-venue',
			__( 'Venue', 'quick-events-manager' ),
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
		wp_nonce_field( self::NONCE, 'qevm_event_venue_nonce' );

		$selected = (int) get_post_meta( $post->ID, Meta::VENUE_ID, true );
		$venues   = self::choices( $selected );
		?>
		<p>
			<label class="screen-reader-text" for="<?php echo esc_attr( self::FIELD ); ?>">
				<?php esc_html_e( 'Venue', 'quick-events-manager' ); ?>
			</label>
			<select id="<?php echo esc_attr( self::FIELD ); ?>" name="<?php echo esc_attr( self::FIELD ); ?>" class="widefat">
				<option value="0"><?php esc_html_e( '— Address on this event —', 'quick-events-manager' ); ?></option>
				<?php foreach ( $venues as $venue_id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $venue_id ); ?>" <?php selected( $selected, $venue_id ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">
			<?php esc_html_e( 'Choosing a venue fills in the address under Location and organiser, and keeps it in step with the venue record.', 'quick-events-manager' ); ?>
		</p>
		<?php if ( array() === $venues ) : ?>
			<p class="description">
				<?php esc_html_e( 'No venues yet. Add one under Events → Venues.', 'quick-events-manager' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the chosen venue, and copy its address onto the event.
	 *
	 * The copy is what makes the venue record removable. Every render path
	 * falls back to the event's own address meta when no record resolves, so
	 * keeping that meta filled means switching the module off, deleting a venue
	 * or trashing one leaves the event still showing where it is rather than
	 * showing nothing.
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

		$nonce = isset( $_POST['qevm_event_venue_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_event_venue_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$venue_id = isset( $_POST[ self::FIELD ] ) ? absint( wp_unslash( $_POST[ self::FIELD ] ) ) : 0;
		$venue    = $venue_id > 0 ? Venue::from_post( $venue_id ) : null;

		if ( null === $venue ) {
			update_post_meta( $post_id, Meta::VENUE_ID, 0 );

			return;
		}

		update_post_meta( $post_id, Meta::VENUE_ID, $venue->id() );

		foreach ( $venue->parts() as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * How many venues the dropdown will list.
	 *
	 * A `<select>` stops being a usable control long before this, and a site
	 * with more venues than fit in one wants a search field rather than a
	 * longer list. The cap is here so the editor screen cannot be made slow by
	 * a site that keeps every room in every building as its own record.
	 */
	const MAX_CHOICES = 100;

	/**
	 * Published venues, as id => title, with the current one always included.
	 *
	 * Including the selected venue even when it falls outside the cap is not a
	 * nicety. A `<select>` posts whichever option is selected, so a venue
	 * missing from the list would submit as "no venue" and the save would
	 * quietly unassign it — the event would keep its address, because that is
	 * copied onto it, and the link back to the record would be gone with
	 * nothing on screen to say so.
	 *
	 * @since 26.0
	 *
	 * @param int $selected Venue currently assigned to the event, or 0.
	 * @return array<int, string>
	 */
	private static function choices( $selected = 0 ) {
		$posts = get_posts(
			array(
				'post_type'              => QEVM_POST_TYPE_VENUE,
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

		foreach ( $posts as $venue ) {
			$choices[ (int) $venue->ID ] = (string) $venue->post_title;
		}

		$selected = (int) $selected;

		if ( $selected > 0 && ! isset( $choices[ $selected ] ) ) {
			$current = get_post( $selected );

			if ( $current instanceof \WP_Post && QEVM_POST_TYPE_VENUE === $current->post_type ) {
				$choices[ $selected ] = (string) $current->post_title;

				asort( $choices, SORT_NATURAL | SORT_FLAG_CASE );
			}
		}

		return $choices;
	}
}
