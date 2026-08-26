<?php
/**
 * Building a registration form's questions on the event editor.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CustomFields;

use QuickEventsManager\Domain\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * The repeating list of questions.
 *
 * Ordering is a number against each row rather than drag-and-drop. Dragging is
 * nicer to use and it is not available to somebody working by keyboard or with
 * a screen reader, and a form builder that can only be operated with a mouse
 * excludes people from the part of this plugin that decides what everybody else
 * is asked. A number box works with no JavaScript at all, and dragging can be
 * layered on later as an enhancement over the top of something that already
 * works. See [ADR-0013](../../docs/adr/0013-accessibility-testing.md).
 *
 * Rows are submitted as one indexed array, so a browser with no JavaScript
 * still posts them and the blank row at the end is how a new question is added.
 *
 * @since 26.0
 */
final class FieldsMetaBox {

	/**
	 * Nonce action for saving.
	 */
	const NONCE = 'qevm_save_registration_fields';

	/**
	 * Request key holding the rows.
	 */
	const FIELD = 'qevm_fields';

	/**
	 * Blank rows offered for new questions.
	 */
	const SPARE_ROWS = 2;

	/**
	 * Hook into the event editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . QEVM_POST_TYPE, array( $this, 'save' ) );
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
			'qevm-registration-fields',
			__( 'Registration questions', 'quick-events-manager' ),
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
		wp_nonce_field( self::NONCE, 'qevm_fields_nonce' );

		$fields = Definitions::for_event( $post->ID );
		$rows   = count( $fields ) + self::SPARE_ROWS;
		$rows   = min( $rows, Definitions::limit() + self::SPARE_ROWS );
		?>
		<p class="description">
			<?php esc_html_e( 'Extra questions asked when somebody registers. Leave the question blank to remove it.', 'quick-events-manager' ); ?>
		</p>

		<div class="qevm-fields qevm-field-rows">
			<?php
			for ( $index = 0; $index < $rows; $index++ ) {
				$this->row( $index, isset( $fields[ $index ] ) ? $fields[ $index ] : null );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render one question's row.
	 *
	 * @since 26.0
	 *
	 * @param int        $index Row index.
	 * @param Field|null $field Existing question, or null for a blank row.
	 * @return void
	 */
	private function row( $index, ?Field $field ) {
		$name = self::FIELD . '[' . $index . ']';
		$id   = self::FIELD . '_' . $index;

		$key     = null !== $field ? $field->key() : Field::mint_key();
		$type    = null !== $field ? $field->type() : FieldType::Text;
		$options = null !== $field ? implode( "\n", $field->options() ) : '';
		$legend  = null !== $field
			/* translators: %d: question number. */
			? sprintf( __( 'Question %d', 'quick-events-manager' ), $index + 1 )
			: __( 'New question', 'quick-events-manager' );
		?>
		<fieldset class="qevm-field-row">
			<legend class="screen-reader-text"><?php echo esc_html( $legend ); ?></legend>

			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $key ); ?>" />

			<div class="qevm-field">
				<label for="<?php echo esc_attr( $id ); ?>_label"><?php esc_html_e( 'Question', 'quick-events-manager' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $id ); ?>_label" name="<?php echo esc_attr( $name ); ?>[label]"
					class="widefat" value="<?php echo esc_attr( null !== $field ? $field->label() : '' ); ?>" />
			</div>

			<div class="qevm-field">
				<label for="<?php echo esc_attr( $id ); ?>_type"><?php esc_html_e( 'Answer', 'quick-events-manager' ); ?></label>
				<select id="<?php echo esc_attr( $id ); ?>_type" name="<?php echo esc_attr( $name ); ?>[type]">
					<?php foreach ( FieldType::cases() as $case ) : ?>
						<?php
						/*
						 * The two stored values, not the two enum instances.
						 * `selected()` casts both sides to string, and a PHP
						 * enum cannot be cast — so this fatally errored on
						 * every event editor screen, before the block editor
						 * had a chance to boot. The page still returned 200
						 * with an empty editor and nothing in the console,
						 * which is why it survived to stage 10.
						 */
						?>
						<option value="<?php echo esc_attr( $case->value ); ?>" <?php selected( $type->value, $case->value ); ?>>
							<?php echo esc_html( $case->label() ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="qevm-field">
				<label for="<?php echo esc_attr( $id ); ?>_options"><?php esc_html_e( 'Choices, one per line', 'quick-events-manager' ); ?></label>
				<textarea id="<?php echo esc_attr( $id ); ?>_options" name="<?php echo esc_attr( $name ); ?>[options]"
					class="widefat" rows="3"><?php echo esc_textarea( $options ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Only used by the three "choose" answers.', 'quick-events-manager' ); ?></p>
			</div>

			<div class="qevm-field">
				<label for="<?php echo esc_attr( $id ); ?>_description"><?php esc_html_e( 'Help text', 'quick-events-manager' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $id ); ?>_description" name="<?php echo esc_attr( $name ); ?>[description]"
					class="widefat" value="<?php echo esc_attr( null !== $field ? $field->description() : '' ); ?>" />
			</div>

			<div class="qevm-field">
				<label for="<?php echo esc_attr( $id ); ?>_order"><?php esc_html_e( 'Position', 'quick-events-manager' ); ?></label>
				<input type="number" id="<?php echo esc_attr( $id ); ?>_order" name="<?php echo esc_attr( $name ); ?>[order]"
					class="small-text" min="1" step="1" value="<?php echo esc_attr( (string) ( $index + 1 ) ); ?>" />
			</div>

			<p class="qevm-checkbox">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1"
						<?php checked( null !== $field && $field->is_required() ); ?> />
					<?php esc_html_e( 'An answer is required', 'quick-events-manager' ); ?>
				</label>
			</p>

			<p class="qevm-checkbox">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sensitive]" value="1"
						<?php checked( null !== $field && $field->is_sensitive() ); ?> />
					<?php esc_html_e( 'The answer is sensitive — dietary needs, access requirements, health', 'quick-events-manager' ); ?>
				</label>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Save the submitted questions.
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

		$nonce = isset( $_POST['qevm_fields_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['qevm_fields_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every value is sanitised in field_from_row().
		$rows = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array();

		Definitions::save( $post_id, self::fields_from_rows( is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Turn submitted rows into questions, in the order the positions ask for.
	 *
	 * @since 26.0
	 *
	 * @param array<int, mixed> $rows Submitted rows.
	 * @return Field[]
	 */
	private static function fields_from_rows( array $rows ) {
		$ordered = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field = self::field_from_row( $row );

			if ( null === $field ) {
				continue;
			}

			/*
			 * The row index breaks ties, so two questions given the same
			 * position keep the order they appear on screen rather than
			 * swapping about between saves.
			 */
			$ordered[] = array(
				'order' => isset( $row['order'] ) ? (int) $row['order'] : PHP_INT_MAX,
				'index' => (int) $index,
				'field' => $field,
			);
		}

		usort(
			$ordered,
			static function ( $a, $b ) {
				return array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] );
			}
		);

		return array_map( static fn ( $entry ) => $entry['field'], $ordered );
	}

	/**
	 * Build one question from its submitted row.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $row Submitted row.
	 * @return Field|null Null for a row that is not a question.
	 */
	private static function field_from_row( array $row ) {
		$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';

		/*
		 * A blank question is how a row is deleted, and how the spare rows at
		 * the bottom stay harmless.
		 */
		if ( '' === trim( $label ) ) {
			return null;
		}

		$key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';

		if ( '' === $key ) {
			$key = Field::mint_key();
		}

		$options = array();

		if ( isset( $row['options'] ) && is_string( $row['options'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $row['options'] ) as $option ) {
				$option = sanitize_text_field( $option );

				if ( '' !== trim( $option ) ) {
					$options[] = $option;
				}
			}
		}

		return new Field(
			$key,
			$label,
			FieldType::from_stored( isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : 'text' ),
			! empty( $row['required'] ),
			$options,
			isset( $row['description'] ) ? sanitize_text_field( (string) $row['description'] ) : '',
			! empty( $row['sensitive'] )
		);
	}
}
