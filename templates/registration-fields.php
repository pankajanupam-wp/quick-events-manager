<?php
/**
 * The custom questions on a registration form, for one place.
 *
 * Override by copying to `your-theme/quick-events-manager/registration-fields.php`.
 *
 * Available variables:
 *
 * @var \QuickEventsManager\CustomFields\Field[] $fields   Questions to ask.
 * @var int                                      $position Which place these belong to, from 1.
 * @var array<string, string|string[]>           $values   Previously submitted answers, keyed by field key.
 * @var array<string, string>                    $errors   Messages, keyed by field key.
 *
 * @package QuickEventsManager
 */

use QuickEventsManager\CustomFields\Answers;
use QuickEventsManager\Domain\FieldType;

defined( 'ABSPATH' ) || exit;

if ( array() === $fields ) {
	return;
}

foreach ( $fields as $qevm_field ) :
	$qevm_key      = $qevm_field->key();
	$qevm_name     = Answers::input_name( $position, $qevm_key );
	$qevm_id       = Answers::input_id( $position, $qevm_key );
	$qevm_type     = $qevm_field->type();
	$qevm_value    = isset( $values[ $qevm_key ] ) ? $values[ $qevm_key ] : ( $qevm_type->is_multiple() ? array() : '' );
	$qevm_error    = isset( $errors[ $qevm_key ] ) ? $errors[ $qevm_key ] : '';
	$qevm_help_id  = '' !== $qevm_field->description() ? $qevm_id . '-help' : '';
	$qevm_error_id = '' !== $qevm_error ? $qevm_id . '-error' : '';

	/*
	 * A field points at its help text and its error together. Screen readers
	 * announce both when focus lands, which is the only chance somebody has to
	 * hear why an input was rejected before they retype it.
	 */
	$qevm_described = trim( $qevm_help_id . ' ' . $qevm_error_id );
	?>
	<div class="qevm-field<?php echo '' !== $qevm_error ? ' qevm-field--error' : ''; ?>">
		<?php if ( $qevm_type->needs_options() && ! $qevm_type->is_multiple() && FieldType::Radio === $qevm_type ) : ?>

			<fieldset>
				<legend>
					<?php echo esc_html( $qevm_field->label() ); ?>
					<?php if ( $qevm_field->is_required() ) : ?>
						<span class="qevm-required" aria-hidden="true">*</span>
						<span class="screen-reader-text"><?php esc_html_e( '(required)', 'quick-events-manager' ); ?></span>
					<?php endif; ?>
				</legend>

				<?php if ( '' !== $qevm_field->description() ) : ?>
					<p class="qevm-field__help" id="<?php echo esc_attr( $qevm_help_id ); ?>">
						<?php echo esc_html( $qevm_field->description() ); ?>
					</p>
				<?php endif; ?>

				<?php foreach ( $qevm_field->options() as $qevm_index => $qevm_option ) : ?>
					<label class="qevm-choice" for="<?php echo esc_attr( $qevm_id . '-' . $qevm_index ); ?>">
						<input type="radio" id="<?php echo esc_attr( $qevm_id . '-' . $qevm_index ); ?>"
							name="<?php echo esc_attr( $qevm_name ); ?>"
							value="<?php echo esc_attr( $qevm_option ); ?>"
							<?php checked( $qevm_value, $qevm_option ); ?>
							<?php echo '' !== $qevm_described ? 'aria-describedby="' . esc_attr( $qevm_described ) . '"' : ''; ?> />
						<?php echo esc_html( $qevm_option ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

		<?php elseif ( $qevm_type->is_multiple() ) : ?>

			<fieldset>
				<legend>
					<?php echo esc_html( $qevm_field->label() ); ?>
					<?php if ( $qevm_field->is_required() ) : ?>
						<span class="qevm-required" aria-hidden="true">*</span>
						<span class="screen-reader-text"><?php esc_html_e( '(required)', 'quick-events-manager' ); ?></span>
					<?php endif; ?>
				</legend>

				<?php if ( '' !== $qevm_field->description() ) : ?>
					<p class="qevm-field__help" id="<?php echo esc_attr( $qevm_help_id ); ?>">
						<?php echo esc_html( $qevm_field->description() ); ?>
					</p>
				<?php endif; ?>

				<?php foreach ( $qevm_field->options() as $qevm_index => $qevm_option ) : ?>
					<label class="qevm-choice" for="<?php echo esc_attr( $qevm_id . '-' . $qevm_index ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $qevm_id . '-' . $qevm_index ); ?>"
							name="<?php echo esc_attr( $qevm_name ); ?>[]"
							value="<?php echo esc_attr( $qevm_option ); ?>"
							<?php checked( in_array( $qevm_option, (array) $qevm_value, true ) ); ?>
							<?php echo '' !== $qevm_described ? 'aria-describedby="' . esc_attr( $qevm_described ) . '"' : ''; ?> />
						<?php echo esc_html( $qevm_option ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

		<?php elseif ( FieldType::Checkbox === $qevm_type ) : ?>

			<label class="qevm-choice" for="<?php echo esc_attr( $qevm_id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $qevm_id ); ?>"
					name="<?php echo esc_attr( $qevm_name ); ?>" value="1"
					<?php checked( '1', (string) $qevm_value ); ?>
					<?php echo '' !== $qevm_error ? 'aria-invalid="true"' : ''; ?>
					<?php echo '' !== $qevm_described ? 'aria-describedby="' . esc_attr( $qevm_described ) . '"' : ''; ?> />
				<?php echo esc_html( $qevm_field->label() ); ?>
				<?php if ( $qevm_field->is_required() ) : ?>
					<span class="qevm-required" aria-hidden="true">*</span>
					<span class="screen-reader-text"><?php esc_html_e( '(required)', 'quick-events-manager' ); ?></span>
				<?php endif; ?>
			</label>

			<?php if ( '' !== $qevm_field->description() ) : ?>
				<p class="qevm-field__help" id="<?php echo esc_attr( $qevm_help_id ); ?>">
					<?php echo esc_html( $qevm_field->description() ); ?>
				</p>
			<?php endif; ?>

		<?php else : ?>

			<label for="<?php echo esc_attr( $qevm_id ); ?>">
				<?php echo esc_html( $qevm_field->label() ); ?>
				<?php if ( $qevm_field->is_required() ) : ?>
					<span class="qevm-required" aria-hidden="true">*</span>
					<span class="screen-reader-text"><?php esc_html_e( '(required)', 'quick-events-manager' ); ?></span>
				<?php endif; ?>
			</label>

			<?php if ( '' !== $qevm_field->description() ) : ?>
				<p class="qevm-field__help" id="<?php echo esc_attr( $qevm_help_id ); ?>">
					<?php echo esc_html( $qevm_field->description() ); ?>
				</p>
			<?php endif; ?>

			<?php if ( FieldType::Textarea === $qevm_type ) : ?>
				<textarea id="<?php echo esc_attr( $qevm_id ); ?>" name="<?php echo esc_attr( $qevm_name ); ?>"
					rows="3"
					<?php echo $qevm_field->is_required() ? 'required' : ''; ?>
					<?php echo '' !== $qevm_error ? 'aria-invalid="true"' : ''; ?>
					<?php echo '' !== $qevm_described ? 'aria-describedby="' . esc_attr( $qevm_described ) . '"' : ''; ?>><?php echo esc_textarea( (string) $qevm_value ); ?></textarea>
			<?php elseif ( FieldType::Select === $qevm_type ) : ?>
				<select id="<?php echo esc_attr( $qevm_id ); ?>" name="<?php echo esc_attr( $qevm_name ); ?>"
					<?php echo $qevm_field->is_required() ? 'required' : ''; ?>
					<?php echo '' !== $qevm_error ? 'aria-invalid="true"' : ''; ?>
					<?php echo '' !== $qevm_described ? 'aria-describedby="' . esc_attr( $qevm_described ) . '"' : ''; ?>>
					<option value=""><?php esc_html_e( '— Choose —', 'quick-events-manager' ); ?></option>
					<?php foreach ( $qevm_field->options() as $qevm_option ) : ?>
						<option value="<?php echo esc_attr( $qevm_option ); ?>" <?php selected( (string) $qevm_value, $qevm_option ); ?>>
							<?php echo esc_html( $qevm_option ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="<?php echo esc_attr( $qevm_type->input_type() ); ?>"
					id="<?php echo esc_attr( $qevm_id ); ?>" name="<?php echo esc_attr( $qevm_name ); ?>"
					value="<?php echo esc_attr( (string) $qevm_value ); ?>"
					<?php echo $qevm_field->is_required() ? 'required' : ''; ?>
					<?php echo '' !== $qevm_error ? 'aria-invalid="true"' : ''; ?>
					<?php echo '' !== $qevm_described ? 'aria-describedby="' . esc_attr( $qevm_described ) . '"' : ''; ?> />
			<?php endif; ?>

		<?php endif; ?>

		<?php if ( '' !== $qevm_error ) : ?>
			<span class="qevm-field__error" id="<?php echo esc_attr( $qevm_error_id ); ?>">
				<?php echo esc_html( $qevm_error ); ?>
			</span>
		<?php endif; ?>
	</div>
	<?php
endforeach;
