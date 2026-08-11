<?php
/**
 * The settings screen.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A deliberately short list of options.
 *
 * Anything that can be decided sensibly on the site owner's behalf is not a
 * setting. What is left is the handful of choices that genuinely differ from
 * site to site, and the answers a plugin cannot guess — like which address
 * registration notifications should go to.
 *
 * @since 26.0
 */
final class Settings {

	/**
	 * Menu slug.
	 */
	const SLUG = 'qevm-settings';

	/**
	 * Settings group.
	 */
	const GROUP = 'qevm_settings_group';

	/**
	 * Hook into the admin.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . QEVM_POST_TYPE,
			__( 'Event Settings', 'quick-events-manager' ),
			__( 'Settings', 'quick-events-manager' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the option and its fields.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			QEVM_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'qevm_display',
			__( 'Display', 'quick-events-manager' ),
			static function () {
				echo '<p>' . esc_html__( 'How events appear on the front end of your site.', 'quick-events-manager' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'auto_details',
			__( 'Event details', 'quick-events-manager' ),
			array( $this, 'render_auto_details' ),
			self::SLUG,
			'qevm_display'
		);

		add_settings_field(
			'archive_per_page',
			__( 'Events per page', 'quick-events-manager' ),
			array( $this, 'render_archive_per_page' ),
			self::SLUG,
			'qevm_display'
		);

		add_settings_section(
			'qevm_notifications',
			__( 'Notifications', 'quick-events-manager' ),
			static function () {
				echo '<p>' . esc_html__( 'Where messages about your events are sent.', 'quick-events-manager' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'notification_email',
			__( 'Send notifications to', 'quick-events-manager' ),
			array( $this, 'render_notification_email' ),
			self::SLUG,
			'qevm_notifications'
		);
	}

	/**
	 * Default settings.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'auto_details'       => true,
			'archive_per_page'   => 10,
			'notification_email' => '',
		);
	}

	/**
	 * Read one setting.
	 *
	 * @since 26.0
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Returned when the setting is unset.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$settings = get_option( QEVM_OPTION_SETTINGS, array() );
		$defaults = self::defaults();

		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		if ( null !== $fallback ) {
			return $fallback;
		}

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
	}

	/**
	 * The address notifications go to.
	 *
	 * Falls back to the site admin email, which is almost always right and
	 * saves the site owner having to fill anything in.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function notification_email() {
		$configured = (string) self::get( 'notification_email', '' );

		return '' !== $configured ? $configured : (string) get_option( 'admin_email' );
	}

	/**
	 * Sanitise the whole settings array.
	 *
	 * @since 26.0
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$email = isset( $input['notification_email'] )
			? sanitize_email( $input['notification_email'] )
			: '';

		$per_page = isset( $input['archive_per_page'] ) ? absint( $input['archive_per_page'] ) : $defaults['archive_per_page'];
		$per_page = max( 1, min( 100, $per_page ) );

		return array(
			'auto_details'       => ! empty( $input['auto_details'] ),
			'archive_per_page'   => $per_page,
			'notification_email' => is_email( $email ) ? $email : '',
		);
	}

	/**
	 * Render the auto-details checkbox.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_auto_details() {
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[auto_details]"
				value="1" <?php checked( (bool) self::get( 'auto_details' ) ); ?> />
			<?php esc_html_e( 'Show the date, time and location above the description on a single event page', 'quick-events-manager' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Turn this off if you would rather place the details yourself with the Event Details block.', 'quick-events-manager' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the per-page field.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_archive_per_page() {
		?>
		<input type="number" min="1" max="100" class="small-text"
			name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[archive_per_page]"
			value="<?php echo esc_attr( (string) self::get( 'archive_per_page' ) ); ?>" />
		<?php
	}

	/**
	 * Render the notification email field.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_notification_email() {
		?>
		<input type="email" class="regular-text"
			name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[notification_email]"
			value="<?php echo esc_attr( (string) self::get( 'notification_email' ) ); ?>"
			placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Leave empty to use the site administration email.', 'quick-events-manager' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'quick-events-manager' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Settings', 'quick-events-manager' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
