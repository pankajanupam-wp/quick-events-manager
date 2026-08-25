<?php
/**
 * The settings screen.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Admin;

use QuickEventsManager\Privacy\Consent;
use QuickEventsManager\Privacy\Retention;

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
			'qevm_display',
			array( 'label_for' => 'qevm-archive-per-page' )
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
			'qevm_notifications',
			array( 'label_for' => 'qevm-notification-email' )
		);

		/*
		 * What the site charges in is only a question if it charges. The
		 * currency belongs to the site rather than to an event: an event priced
		 * in one currency and another in a second is not a thing anybody wants,
		 * and a gateway account has one currency anyway.
		 */
		if ( \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\Commerce\CommerceModule::ID ) ) {
			add_settings_section(
				'qevm_money',
				__( 'Money', 'quick-events-manager' ),
				static function () {
					echo '<p>' . esc_html__( 'What prices on this site are in.', 'quick-events-manager' ) . '</p>';
				},
				self::SLUG
			);

			add_settings_field(
				'currency',
				__( 'Currency', 'quick-events-manager' ),
				array( $this, 'render_currency' ),
				self::SLUG,
				'qevm_money',
				array( 'label_for' => 'qevm-currency' )
			);

			add_settings_field(
				'stripe_keys',
				__( 'Stripe keys', 'quick-events-manager' ),
				array( $this, 'render_stripe_keys' ),
				self::SLUG,
				'qevm_money'
			);
		}

		/*
		 * Consent is only a question if the site takes registrations at all. A
		 * site publishing a calendar collects nothing from anybody, and a box
		 * asking it to word its consent notice would be a setting for a thing
		 * that never happens.
		 */
		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\Registration\RegistrationModule::ID ) ) {
			return;
		}

		add_settings_section(
			'qevm_privacy',
			__( 'Privacy', 'quick-events-manager' ),
			static function () {
				echo '<p>' . esc_html__( 'What people agree to when they register, and what is recorded about it.', 'quick-events-manager' ) . '</p>';
			},
			self::SLUG
		);

		add_settings_field(
			'consent_text',
			__( 'Consent wording', 'quick-events-manager' ),
			array( $this, 'render_consent_text' ),
			self::SLUG,
			'qevm_privacy',
			array( 'label_for' => 'qevm-consent-text' )
		);

		add_settings_field(
			Retention::SETTING,
			__( 'Delete registrations after', 'quick-events-manager' ),
			array( $this, 'render_retention_days' ),
			self::SLUG,
			'qevm_privacy',
			array( 'label_for' => 'qevm-retention-days' )
		);

		add_settings_field(
			'delete_on_uninstall',
			__( 'When the plugin is deleted', 'quick-events-manager' ),
			array( $this, 'render_delete_on_uninstall' ),
			self::SLUG,
			'qevm_privacy',
			array( 'label_for' => 'qevm-delete-on-uninstall' )
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
			'auto_details'        => true,
			'currency'            => \QuickEventsManager\Commerce\Currency::FALLBACK,

			/*
			 * Off, and it has to be off. Deleting an attendee list is not
			 * recoverable, and a plugin that does it on an accidental delete —
			 * or while somebody is trying another plugin for an afternoon — has
			 * done something unforgivable.
			 */
			'delete_on_uninstall' => false,
			'archive_per_page'    => 10,
			'notification_email'  => '',
			'consent_text'        => __( 'I agree to my details being stored so the organiser can contact me about this event.', 'quick-events-manager' ),

			/*
			 * Zero, meaning keep forever, and it has to stay zero. A plugin
			 * update that started deleting a site's attendee history because a
			 * retention feature appeared would be the worst thing this codebase
			 * could do to somebody.
			 */
			'retention_days'      => 0,
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

		/*
		 * The keys are not part of this option and never get written into it:
		 * they go to their own, unautoloaded, through the class that owns them.
		 * They arrive on this form because that is where somebody expects to
		 * type them, not because they belong in this array.
		 */
		if ( isset( $input['stripe'] ) && is_array( $input['stripe'] ) ) {
			\QuickEventsManager\Commerce\Stripe\Keys::save( $input['stripe'] );
		}

		return array(
			'auto_details'        => ! empty( $input['auto_details'] ),
			'currency'            => $this->sanitize_currency( $input ),
			'delete_on_uninstall' => $this->sanitize_delete_on_uninstall( $input ),
			'archive_per_page'    => $per_page,
			'notification_email'  => is_email( $email ) ? $email : '',
			'consent_text'        => $this->sanitize_consent_text( $input ),
			'retention_days'      => $this->sanitize_retention_days( $input ),
		);
	}

	/**
	 * Sanitise the delete-everything switch.
	 *
	 * Absent means unchanged rather than off, for the same reason the retention
	 * period and the consent wording work that way: this field renders only
	 * when registration is on, and a save from a site with it off must not
	 * silently change what happens to that site's data.
	 *
	 * A checkbox that is present and unticked is a deliberate "no", and is
	 * honoured — that is what the hidden companion field below is for.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return bool
	 */
	private function sanitize_delete_on_uninstall( array $input ) {
		if ( ! isset( $input['delete_on_uninstall_present'] ) ) {
			return (bool) self::get( 'delete_on_uninstall', false );
		}

		return ! empty( $input['delete_on_uninstall'] );
	}

	/**
	 * Sanitise the currency.
	 *
	 * Absent means unchanged, for the same reason the consent wording and the
	 * retention period do: this field only renders while paid tickets are on,
	 * and a save from a site that has them off must not quietly reset what its
	 * historic orders were priced in.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return string
	 */
	private function sanitize_currency( array $input ) {
		if ( ! isset( $input['currency'] ) ) {
			return (string) self::get( 'currency', \QuickEventsManager\Commerce\Currency::FALLBACK );
		}

		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $input['currency'] ) );

		return 3 === strlen( $code ) ? $code : \QuickEventsManager\Commerce\Currency::FALLBACK;
	}

	/**
	 * Sanitise the retention period.
	 *
	 * Absent means unchanged, for the same reason the consent wording does:
	 * this field only renders when the registration module is on, and treating
	 * a missing key as zero would quietly switch retention off for a site that
	 * had deliberately set it — or, read the other way round, an absent key
	 * treated as a number would switch deletion *on*. Neither is a thing a
	 * settings save should do silently.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $input Submitted settings.
	 * @return int
	 */
	private function sanitize_retention_days( array $input ) {
		if ( ! isset( $input[ Retention::SETTING ] ) ) {
			return (int) self::get( Retention::SETTING, 0 );
		}

		$days = absint( $input[ Retention::SETTING ] );

		return 0 === $days ? 0 : max( Retention::MINIMUM_DAYS, $days );
	}

	/**
	 * Sanitise the consent wording, or keep the one already stored.
	 *
	 * The absent case is the one that matters. This screen only renders the
	 * consent field when the registration module is on, and the settings API
	 * hands the sanitiser exactly what the form submitted — so treating a
	 * missing key as an empty string would quietly erase a site's consent
	 * wording the next time somebody saved the page with registration switched
	 * off. Absent means "not asked about", not "cleared".
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $input Submitted settings.
	 * @return string
	 */
	private function sanitize_consent_text( array $input ) {
		if ( ! isset( $input['consent_text'] ) ) {
			return (string) self::get( 'consent_text', '' );
		}

		return trim( wp_kses( (string) $input['consent_text'], Consent::allowed_html() ) );
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
		<input type="number" min="1" max="100" class="small-text" id="qevm-archive-per-page"
			name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[archive_per_page]"
			value="<?php echo esc_attr( (string) self::get( 'archive_per_page' ) ); ?>" />
		<?php
	}

	/**
	 * Render the delete-everything switch.
	 *
	 * Worded as what will happen rather than as a feature name, and the warning
	 * is beside the control rather than in a tooltip: this is the one setting on
	 * the screen that can destroy something nobody can get back.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_delete_on_uninstall() {
		?>
		<input type="hidden" name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[delete_on_uninstall_present]" value="1" />

		<label for="qevm-delete-on-uninstall">
			<input type="checkbox" id="qevm-delete-on-uninstall"
				name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[delete_on_uninstall]" value="1"
				<?php checked( (bool) self::get( 'delete_on_uninstall', false ) ); ?> />
			<?php esc_html_e( 'Delete all events, attendees and settings when this plugin is deleted', 'quick-events-manager' ); ?>
		</label>

		<p class="description">
			<?php esc_html_e( 'Off by default. With this off, deleting the plugin leaves your data in the database, so reinstalling picks up where you left off. With it on, everything goes and cannot be recovered.', 'quick-events-manager' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the currency chooser.
	 *
	 * A dropdown rather than a text field: a typo in an ISO code is a rejected
	 * charge at the gateway, discovered by a customer.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_currency() {
		$current = (string) self::get( 'currency', \QuickEventsManager\Commerce\Currency::FALLBACK );
		?>
		<select id="qevm-currency" name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[currency]">
			<?php foreach ( \QuickEventsManager\Commerce\Currency::choices() as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Prices already recorded keep the currency they were taken in.', 'quick-events-manager' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the Stripe key fields.
	 *
	 * The stored keys are never printed back into the form. A secret key in a
	 * `value` attribute is a secret key in the page source, in the browser's
	 * autofill store and in every screenshot anybody takes of this screen. Each
	 * field says how the stored key ends, and leaving it empty keeps what is
	 * there.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_stripe_keys() {
		$fields = array(
			'publishable' => array(
				'label' => __( 'Publishable key', 'quick-events-manager' ),
				'value' => \QuickEventsManager\Commerce\Stripe\Keys::publishable(),
				'hint'  => __( 'Starts pk_test_ or pk_live_. Safe to appear in a page.', 'quick-events-manager' ),
			),
			'secret'      => array(
				'label' => __( 'Secret key', 'quick-events-manager' ),
				'value' => \QuickEventsManager\Commerce\Stripe\Keys::secret(),
				'hint'  => __( 'Starts sk_test_ or sk_live_. Never share it.', 'quick-events-manager' ),
			),
			'webhook'     => array(
				'label' => __( 'Webhook signing secret', 'quick-events-manager' ),
				'value' => \QuickEventsManager\Commerce\Stripe\Keys::webhook_secret(),
				'hint'  => __( 'Starts whsec_. Stripe shows it when you add the endpoint.', 'quick-events-manager' ),
			),
		);

		foreach ( $fields as $name => $field ) {
			$stored = \QuickEventsManager\Commerce\Stripe\Keys::mask( $field['value'] );
			?>
			<p>
				<label for="<?php echo esc_attr( 'qevm-stripe-' . $name ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
				</label><br />
				<input type="password" class="regular-text" autocomplete="off"
					id="<?php echo esc_attr( 'qevm-stripe-' . $name ); ?>"
					name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[stripe][<?php echo esc_attr( $name ); ?>]"
					value=""
					placeholder="<?php echo '' !== $stored ? esc_attr( $stored ) : ''; ?>" />
				<span class="description">
					<?php echo esc_html( $field['hint'] ); ?>
					<?php if ( '' !== $stored ) : ?>
						<?php esc_html_e( 'Saved. Leave empty to keep it.', 'quick-events-manager' ); ?>
					<?php endif; ?>
				</span>
			</p>
			<?php
		}

		?>
		<p class="description">
			<?php esc_html_e( 'Add this address as a webhook endpoint in your Stripe dashboard, for the payment_intent events:', 'quick-events-manager' ); ?><br />
			<code><?php echo esc_html( \QuickEventsManager\Commerce\Stripe\Webhook::url() ); ?></code>
		</p>
		<?php

		if ( \QuickEventsManager\Commerce\Stripe\Keys::are_complete() && ! \QuickEventsManager\Commerce\Stripe\Keys::are_consistent() ) {
			?>
			<p class="notice notice-error" style="padding: 0.5rem;">
				<?php esc_html_e( 'One of these keys is a test key and the other is a live one. Card payments are switched off until they match.', 'quick-events-manager' ); ?>
			</p>
			<?php
		} elseif ( \QuickEventsManager\Commerce\Stripe\Keys::are_complete() && \QuickEventsManager\Commerce\Stripe\Keys::are_test() ) {
			?>
			<p class="description">
				<strong><?php esc_html_e( 'Test mode.', 'quick-events-manager' ); ?></strong>
				<?php esc_html_e( 'No real money will move while these are test keys.', 'quick-events-manager' ); ?>
			</p>
			<?php
		}
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
		<input type="email" class="regular-text" id="qevm-notification-email"
			name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[notification_email]"
			value="<?php echo esc_attr( (string) self::get( 'notification_email' ) ); ?>"
			placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Leave empty to use the site administration email.', 'quick-events-manager' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the retention period field.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_retention_days() {
		$days = (int) self::get( Retention::SETTING, 0 );
		?>
		<input type="number" id="qevm-retention-days" class="small-text"
			name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[<?php echo esc_attr( Retention::SETTING ); ?>]"
			value="<?php echo esc_attr( (string) $days ); ?>" min="0" step="1" />
		<?php esc_html_e( 'days', 'quick-events-manager' ); ?>

		<p class="description">
			<?php esc_html_e( '0 keeps registrations for ever, which is the default.', 'quick-events-manager' ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %d: Smallest retention period that can be set, in days. */
				esc_html__( 'Set a number and every registration for an event that finished that long ago is deleted, along with its attendees and their answers. This cannot be undone and there is no copy kept. Counted from when the event ended, not from when somebody registered. The smallest period is %d days.', 'quick-events-manager' ),
				(int) Retention::MINIMUM_DAYS
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the consent wording field.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function render_consent_text() {
		$version = Consent::version();
		?>
		<textarea class="large-text" rows="3" id="qevm-consent-text"
			name="<?php echo esc_attr( QEVM_OPTION_SETTINGS ); ?>[consent_text]"
		><?php echo esc_textarea( (string) self::get( 'consent_text', '' ) ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Shown beside a checkbox people have to tick before they can register. Links and simple emphasis are allowed — link your privacy policy here.', 'quick-events-manager' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Leave this empty to stop asking for consent at all.', 'quick-events-manager' ); ?>
		</p>
		<?php if ( '' !== $version ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: Short fingerprint identifying the current consent wording. */
					esc_html__( 'Current version: %s. Registrations record this alongside the time consent was given, so you can tell which wording somebody agreed to. Editing the wording gives it a new version; earlier wordings are not kept.', 'quick-events-manager' ),
					esc_html( $version )
				);
				?>
			</p>
		<?php endif; ?>
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
