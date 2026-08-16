<?php
/**
 * The screen where the emails are rewritten.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

defined( 'ABSPATH' ) || exit;

/**
 * One form per editable email, under the Events menu.
 *
 * Every field starts empty, and an empty field means "use the built-in wording"
 * rather than "send nothing". That is the difference between a template screen
 * somebody can safely look at and one that silently empties every confirmation
 * the first time it is saved.
 *
 * @since 26.0
 */
final class TemplatesScreen {

	/**
	 * Menu slug.
	 */
	const SLUG = 'qevm-email-templates';

	/**
	 * Nonce action.
	 */
	const NONCE = 'qevm_save_email_templates';

	/**
	 * Hook the screen up.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::NONCE, array( $this, 'save' ) );
	}

	/**
	 * Add the page under Events.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . QEVM_POST_TYPE,
			__( 'Email templates', 'quick-events-manager' ),
			__( 'Email templates', 'quick-events-manager' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stored = Templates::stored();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email templates', 'quick-events-manager' ); ?></h1>

			<p>
				<?php esc_html_e( 'Leave a template empty to use the wording this plugin ships with, which is what happens until you change it. Clearing a template you have written puts the built-in wording back.', 'quick-events-manager' ); ?>
			</p>

			<h2><?php esc_html_e( 'Placeholders', 'quick-events-manager' ); ?></h2>

			<table class="widefat striped" style="max-width: 40em;">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Placeholder', 'quick-events-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Becomes', 'quick-events-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Templates::placeholders() as $qevm_name => $qevm_means ) : ?>
						<tr>
							<td><code><?php echo esc_html( '{' . $qevm_name . '}' ); ?></code></td>
							<td><?php echo esc_html( $qevm_means ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE ); ?>" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<?php foreach ( Templates::names() as $qevm_id => $qevm_label ) : ?>
					<?php
					$qevm_current = isset( $stored[ $qevm_id ] ) ? (array) $stored[ $qevm_id ] : array();
					$qevm_format  = isset( $qevm_current['format'] ) ? (string) $qevm_current['format'] : Template::FORMAT_TEXT;
					?>
					<h2><?php echo esc_html( $qevm_label ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $qevm_id ); ?>-subject"><?php esc_html_e( 'Subject', 'quick-events-manager' ); ?></label>
							</th>
							<td>
								<input type="text" class="large-text" id="<?php echo esc_attr( $qevm_id ); ?>-subject"
									name="qevm_templates[<?php echo esc_attr( $qevm_id ); ?>][subject]"
									value="<?php echo esc_attr( isset( $qevm_current['subject'] ) ? (string) $qevm_current['subject'] : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $qevm_id ); ?>-body"><?php esc_html_e( 'Message', 'quick-events-manager' ); ?></label>
							</th>
							<td>
								<textarea class="large-text code" rows="8" id="<?php echo esc_attr( $qevm_id ); ?>-body"
									name="qevm_templates[<?php echo esc_attr( $qevm_id ); ?>][body]"><?php echo esc_textarea( isset( $qevm_current['body'] ) ? (string) $qevm_current['body'] : '' ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $qevm_id ); ?>-format"><?php esc_html_e( 'Format', 'quick-events-manager' ); ?></label>
							</th>
							<td>
								<select id="<?php echo esc_attr( $qevm_id ); ?>-format" name="qevm_templates[<?php echo esc_attr( $qevm_id ); ?>][format]">
									<option value="<?php echo esc_attr( Template::FORMAT_TEXT ); ?>" <?php selected( $qevm_format, Template::FORMAT_TEXT ); ?>>
										<?php esc_html_e( 'Plain text', 'quick-events-manager' ); ?>
									</option>
									<option value="<?php echo esc_attr( Template::FORMAT_HTML ); ?>" <?php selected( $qevm_format, Template::FORMAT_HTML ); ?>>
										<?php esc_html_e( 'HTML', 'quick-events-manager' ); ?>
									</option>
								</select>

								<p class="description">
									<?php esc_html_e( 'Plain text is delivered and read everywhere. Choose HTML only if you need it — some people read mail in clients that show the markup instead of rendering it.', 'quick-events-manager' ); ?>
								</p>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save templates', 'quick-events-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save every template on the form.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit email templates.', 'quick-events-manager' ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every value is sanitised in Templates::save(), which is where the format decides how.
		$submitted = isset( $_POST['qevm_templates'] ) ? wp_unslash( $_POST['qevm_templates'] ) : array();

		foreach ( Templates::names() as $id => $label ) {
			unset( $label );

			$values = isset( $submitted[ $id ] ) && is_array( $submitted[ $id ] ) ? $submitted[ $id ] : array();

			Templates::save( $id, $values );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => QEVM_POST_TYPE,
					'page'      => self::SLUG,
					'updated'   => '1',
				),
				admin_url( 'edit.php' )
			)
		);

		exit;
	}
}
