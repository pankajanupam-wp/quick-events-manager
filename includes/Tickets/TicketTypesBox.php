<?php
/**
 * The "Ticket types" box on the event editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tickets;

use QuickEventsManager\Domain\TicketTypeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Editing an event's ticket types where the event is.
 *
 * A box rather than a screen of its own, because an event has two or three
 * types and they are decided while the event is being written. The dates screen
 * is separate for the opposite reason: a series has hundreds of dates and they
 * are managed long after.
 *
 * **Every row is in the markup, including a blank one.** Nothing here is built
 * by script: the labels stay in PHP where they are translated and escaped like
 * everything else, the box works with JavaScript switched off, and adding a
 * second type means filling in the blank row and saving. The script only ever
 * adds more blank rows to a form that already worked.
 *
 * @since 26.0
 */
final class TicketTypesBox {

	/**
	 * Nonce action.
	 */
	const NONCE = 'qevm_ticket_types';

	/**
	 * Blank rows offered at the bottom.
	 */
	const BLANK_ROWS = 1;

	/**
	 * Hook into the editor.
	 *
	 * Saving at priority 15, between the event's own details at 10 and the
	 * occurrence sync at 20 — not because the sync reads these, but because
	 * sitting outside that window is how a save order becomes accidental.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEVM_POST_TYPE, array( $this, 'save' ), 15, 2 );
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
			'qevm-ticket-types',
			__( 'Ticket types', 'quick-events-manager' ),
			array( $this, 'render' ),
			QEVM_POST_TYPE,
			'normal',
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
		$types = TicketTypeRepository::for_event( (int) $post->ID );

		wp_nonce_field( self::NONCE, 'qevm_ticket_types_nonce' );
		?>
		<div class="qevm-fields qevm-ticket-types">
			<p class="description">
				<?php esc_html_e( 'Leave this empty and everybody books the same kind of place. Add types to offer more than one — member and guest, full and concession.', 'quick-events-manager' ); ?>
			</p>

			<table class="widefat qevm-ticket-types__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'quick-events-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'quick-events-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Price', 'quick-events-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Places', 'quick-events-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On sale from', 'quick-events-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Until', 'quick-events-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On sale', 'quick-events-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $types as $index => $type ) : ?>
						<?php $this->render_row( (int) $index, $type ); ?>
					<?php endforeach; ?>

					<?php for ( $blank = 0; $blank < self::BLANK_ROWS; $blank++ ) : ?>
						<?php $this->render_row( count( $types ) + $blank, null ); ?>
					<?php endfor; ?>
				</tbody>
			</table>

			<p class="description">
				<?php esc_html_e( 'Places left empty means as many as the event allows. Clearing a name removes that type — or archives it, if somebody already holds one.', 'quick-events-manager' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * A price typed in whole units, as minor units.
	 *
	 * Rounded rather than truncated, and rounded once. `12.10` arrives as a
	 * float that is not exactly 12.10, and `(int) ( 12.10 * 100 )` is 1209 on
	 * every machine this will ever run on — a penny lost per ticket, which is
	 * the classic way money goes wrong in software that never tested it.
	 *
	 * @since 26.0
	 *
	 * @param string $typed What the organiser entered.
	 * @return int
	 */
	private static function price_to_minor( $typed ) {
		$typed = trim( str_replace( ',', '', $typed ) );

		if ( '' === $typed || ! is_numeric( $typed ) ) {
			return 0;
		}

		return max( 0, (int) round( ( (float) $typed ) * 100 ) );
	}

	/**
	 * A submitted local datetime as stored UTC, or ''.
	 *
	 * Read in the event's own timezone, because that is the clock the organiser
	 * is looking at. "On sale until Friday midnight" means Friday midnight
	 * where the event is, not wherever the server happens to be.
	 *
	 * @since 26.0
	 *
	 * The input has minute granularity, so the seconds are ours to choose. An
	 * end takes `:59` and a start takes `:00`, which means the minute somebody
	 * typed is included at both ends — "on sale until 23:59" is on sale for the
	 * whole of 23:59, not until it begins. The Repeats box makes the same call
	 * about a repeat rule's last date.
	 *
	 * @since 26.0
	 *
	 * @param int    $post_id   Event id.
	 * @param string $typed     Submitted `Y-m-d\TH:i` value.
	 * @param bool   $inclusive Whether this is the end of a window.
	 * @return string
	 */
	private static function window_to_utc( $post_id, $typed, $inclusive = false ) {
		$typed = trim( str_replace( 'T', ' ', $typed ) );

		if ( '' === $typed ) {
			return '';
		}

		if ( 16 === strlen( $typed ) ) {
			$typed .= $inclusive ? ':59' : ':00';
		}

		$event = new \QuickEventsManager\Events\Event( (int) $post_id );

		return \QuickEventsManager\Events\Meta::to_utc( $typed, $event->timezone() );
	}

	/**
	 * A stored UTC window edge as the local value the input wants.
	 *
	 * @since 26.0
	 *
	 * @param TicketType|null $type   The type, or null for a blank row.
	 * @param string          $column Which edge.
	 * @return string
	 */
	private static function window_for_input( $type, $column ) {
		if ( ! $type instanceof TicketType ) {
			return '';
		}

		$stored = 'sale_starts_utc' === $column ? $type->sale_starts_utc() : $type->sale_ends_utc();

		if ( '' === $stored ) {
			return '';
		}

		$event = new \QuickEventsManager\Events\Event( $type->event_id() );
		$local = \QuickEventsManager\Events\Meta::to_local( $stored, $event->timezone() );

		return '' !== $local ? str_replace( ' ', 'T', substr( $local, 0, 16 ) ) : '';
	}

	/**
	 * One row of the table.
	 *
	 * @since 26.0
	 *
	 * @param int             $index Row index in the submitted array.
	 * @param TicketType|null $type  Existing type, or null for a blank row.
	 * @return void
	 */
	private function render_row( $index, $type ) {
		$id       = null !== $type ? $type->id() : 0;
		$field    = 'qevm_ticket_types[' . (int) $index . ']';
		$archived = null !== $type && ! $type->is_sellable();
		?>
		<tr class="qevm-ticket-type<?php echo $archived ? ' qevm-ticket-type--archived' : ''; ?>">
			<td>
				<input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( (string) $id ); ?>" />
				<label class="screen-reader-text" for="qevm-ticket-name-<?php echo esc_attr( (string) $index ); ?>">
					<?php esc_html_e( 'Ticket type name', 'quick-events-manager' ); ?>
				</label>
				<input type="text" class="widefat" id="qevm-ticket-name-<?php echo esc_attr( (string) $index ); ?>"
					name="<?php echo esc_attr( $field ); ?>[name]"
					value="<?php echo esc_attr( null !== $type ? $type->name() : '' ); ?>" />
			</td>
			<td>
				<label class="screen-reader-text" for="qevm-ticket-description-<?php echo esc_attr( (string) $index ); ?>">
					<?php esc_html_e( 'Ticket type description', 'quick-events-manager' ); ?>
				</label>
				<input type="text" class="widefat" id="qevm-ticket-description-<?php echo esc_attr( (string) $index ); ?>"
					name="<?php echo esc_attr( $field ); ?>[description]"
					value="<?php echo esc_attr( null !== $type ? $type->description() : '' ); ?>" />
			</td>
			<td>
				<label class="screen-reader-text" for="qevm-ticket-price-<?php echo esc_attr( (string) $index ); ?>">
					<?php esc_html_e( 'Price', 'quick-events-manager' ); ?>
				</label>
				<?php
				/*
				 * Typed in whole units and stored in minor ones. Nobody enters a
				 * price in pence, and nobody should have to know that is how it
				 * is kept — the conversion belongs at this boundary, which is
				 * the only place a human number meets a stored one.
				 */
				?>
				<input type="number" min="0" step="0.01" id="qevm-ticket-price-<?php echo esc_attr( (string) $index ); ?>"
					name="<?php echo esc_attr( $field ); ?>[price]"
					value="<?php echo esc_attr( null !== $type && $type->price_minor() > 0 ? number_format( $type->price_minor() / 100, 2, '.', '' ) : '' ); ?>" />
			</td>
			<td>
				<label class="screen-reader-text" for="qevm-ticket-capacity-<?php echo esc_attr( (string) $index ); ?>">
					<?php esc_html_e( 'Places of this type', 'quick-events-manager' ); ?>
				</label>
				<input type="number" min="0" step="1" id="qevm-ticket-capacity-<?php echo esc_attr( (string) $index ); ?>"
					name="<?php echo esc_attr( $field ); ?>[capacity]"
					value="<?php echo esc_attr( null !== $type && $type->capacity() > 0 ? (string) $type->capacity() : '' ); ?>" />
			</td>
			<td>
				<label class="screen-reader-text" for="qevm-ticket-opens-<?php echo esc_attr( (string) $index ); ?>">
					<?php esc_html_e( 'On sale from', 'quick-events-manager' ); ?>
				</label>
				<input type="datetime-local" id="qevm-ticket-opens-<?php echo esc_attr( (string) $index ); ?>"
					name="<?php echo esc_attr( $field ); ?>[sale_starts]"
					value="<?php echo esc_attr( self::window_for_input( $type, 'sale_starts_utc' ) ); ?>" />
			</td>
			<td>
				<label class="screen-reader-text" for="qevm-ticket-closes-<?php echo esc_attr( (string) $index ); ?>">
					<?php esc_html_e( 'On sale until', 'quick-events-manager' ); ?>
				</label>
				<input type="datetime-local" id="qevm-ticket-closes-<?php echo esc_attr( (string) $index ); ?>"
					name="<?php echo esc_attr( $field ); ?>[sale_ends]"
					value="<?php echo esc_attr( self::window_for_input( $type, 'sale_ends_utc' ) ); ?>" />
			</td>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[active]" value="1"
						<?php checked( null === $type || $type->is_sellable() ); ?> />
					<?php esc_html_e( 'On sale', 'quick-events-manager' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Store the types.
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

		$nonce = isset( $_POST['qevm_ticket_types_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_ticket_types_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$submitted = isset( $_POST['qevm_ticket_types'] ) && is_array( $_POST['qevm_ticket_types'] )
			? map_deep( wp_unslash( $_POST['qevm_ticket_types'] ), 'sanitize_text_field' )
			: array();

		$position = 0;

		foreach ( (array) $submitted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			/*
			 * map_deep() walks nested arrays, so a hand-made request can still
			 * put an array where a value belongs. Casting one to string yields
			 * the literal "Array" and a warning — a ticket type called Array,
			 * created by somebody posting to their own event editor. Anything
			 * that is not a scalar is not an answer to the question asked.
			 */
			$row = array_filter( $row, 'is_scalar' );

			$id   = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';

			/*
			 * The id in the form is a claim, and this is where it stops being
			 * taken on trust. Somebody who may edit their own event may not
			 * rewrite the ticket types of somebody else's by putting its id in
			 * a field — an id that does not belong to the event being saved is
			 * treated as no id at all, so the row is added here rather than
			 * changed there.
			 */
			if ( $id > 0 ) {
				$existing = TicketTypeRepository::find( $id );

				if ( null === $existing || $existing->event_id() !== (int) $post_id ) {
					$id = 0;
				}
			}

			/*
			 * A row with no name is not a type. A blank one has never been one
			 * and is skipped; an existing one has just been emptied, which is
			 * how the box says "remove this".
			 */
			if ( '' === $name ) {
				if ( $id > 0 ) {
					TicketTypeRepository::delete( $id );
				}

				continue;
			}

			$values = array(
				'name'            => $name,
				'description'     => isset( $row['description'] ) ? (string) $row['description'] : '',
				'price_minor'     => self::price_to_minor( isset( $row['price'] ) ? (string) $row['price'] : '' ),
				'capacity'        => isset( $row['capacity'] ) ? absint( $row['capacity'] ) : 0,
				'sale_starts_utc' => self::window_to_utc( $post_id, isset( $row['sale_starts'] ) ? (string) $row['sale_starts'] : '' ),
				'sale_ends_utc'   => self::window_to_utc( $post_id, isset( $row['sale_ends'] ) ? (string) $row['sale_ends'] : '', true ),
				'sort_order'      => $position,
				'status'          => empty( $row['active'] )
					? TicketTypeStatus::Archived->value
					: TicketTypeStatus::Active->value,
			);

			if ( $id > 0 ) {
				TicketTypeRepository::update( $id, $values );
			} else {
				TicketTypeRepository::insert( (int) $post_id, $values );
			}

			++$position;
		}
	}
}
