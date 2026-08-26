<?php
/**
 * Wires the plugin together.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager;

use QuickEventsManager\Install\Installer;
use QuickEventsManager\Install\Migrations\Runner;
use QuickEventsManager\Modules\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point: owns the module registry and the always-on wiring.
 *
 * @since 26.0
 */
final class Plugin {

	/**
	 * Sole instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Module registry.
	 *
	 * @var Registry|null
	 */
	private $registry = null;

	/**
	 * Private: use instance().
	 */
	private function __construct() {}

	/**
	 * The shared instance.
	 *
	 * @since 26.0
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The module registry, built on first use.
	 *
	 * @since 26.0
	 *
	 * @return Registry
	 */
	public function registry() {
		if ( null === $this->registry ) {
			$this->registry = new Registry();
		}

		return $this->registry;
	}

	/**
	 * Register the hooks that exist regardless of which modules are on.
	 *
	 * Modules are booted on `plugins_loaded` rather than immediately, so that
	 * another plugin has a chance to add its own through the `qevm_modules`
	 * filter before the registry is first built.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'load_modules' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		/*
		 * Registered outside the module system on purpose. A rebuild command is
		 * a recovery tool, and the moment it is most needed is the moment
		 * something is wrong — possibly the very module that would have
		 * registered it. It costs nothing when WP_CLI is undefined.
		 */
		Cli\OccurrenceCommand::register();
	}

	/**
	 * Boot every enabled module.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function load_modules() {
		$this->registry()->boot();

		if ( is_admin() ) {
			( new Admin\Assets() )->register();
			( new Admin\FeaturesScreen( $this->registry() ) )->register();
			( new Admin\Settings() )->register();
		}
	}

	/**
	 * Load translations shipped inside the plugin.
	 *
	 * Hooked to `init` rather than `plugins_loaded`: since WordPress 6.7,
	 * loading a text domain earlier triggers a `_load_textdomain_just_in_time`
	 * notice. Translations from wordpress.org load automatically.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'quick-events-manager', false, dirname( QEVM_BASENAME ) . '/languages' );
	}

	/**
	 * Run pending schema and data upgrades.
	 *
	 * On `admin_init` rather than activation, because a plugin updated in place
	 * through the dashboard or WP-CLI never fires its activation hook. The
	 * version check costs one option read when there is nothing to do, which is
	 * every request but a handful in the plugin's lifetime.
	 *
	 * Structure before data: a migration may well depend on a column that
	 * dbDelta has just added, so tables are brought up to date first and the
	 * numbered migrations run against the shape they expect.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( ! Runner::needs_upgrade() ) {
			return;
		}

		Installer::upgrade_schema();
		Runner::run();
	}

	/**
	 * Plugin activation.
	 *
	 * Deliberately minimal: it adds capabilities and flushes rewrites so
	 * `/events/` resolves immediately. Tables belong to the modules that need
	 * them and are created when those are switched on.
	 *
	 * Migrations run here too, and the version is recorded by the runner rather
	 * than by activation. A site still on 1.0 that deactivates it and activates
	 * 26.0 reaches this path, and it has legacy posts to move.
	 *
	 * @since 26.0
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		/*
		 * A network activation is one hook call for the whole network, and
		 * everything this sets up is per site: the tables carry the site's own
		 * prefix, the roles live in the site's own options table, and the
		 * rewrite rules are the site's own. Activating across a network without
		 * this loop left every site but the first with no roles and no tables —
		 * an installation where registration simply does not work, and nothing
		 * says why. Found by C10.5, which is the chunk that exists to find it.
		 */
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );

				self::activate_site();

				restore_current_blog();
			}

			return;
		}

		self::activate_site();
	}

	/**
	 * Set up one site.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private static function activate_site() {
		Installer::install();

		/*
		 * The tables, and this line is the whole of a fresh install working.
		 *
		 * Activation used to call `install()` — capabilities and roles, no
		 * tables — and then `Runner::run()`, which stamps the stored schema
		 * version as current once the migrations are through. `maybe_upgrade()`
		 * is the only other thing that creates tables and it runs *only* when
		 * the stored version is behind, so on a brand-new site it never ran
		 * again: the version said "up to date" and the occurrences table had
		 * never been created at all.
		 *
		 * Nothing noticed because every environment this was developed in had
		 * been through a version bump, which is exactly when `maybe_upgrade()`
		 * does fire. On a genuinely fresh install the event archive and the
		 * calendar are both empty, because both read the occurrences table.
		 * Found when CI ran the accessibility suite against a site installed
		 * five minutes earlier.
		 *
		 * Structure before data, the same order `maybe_upgrade()` uses: a
		 * migration may depend on a column dbDelta has just added.
		 */
		Installer::upgrade_schema();

		Runner::run();

		/*
		 * The post type is registered on `init`, which has already run by the
		 * time an activation hook fires, so register it once here or the
		 * flush below has no event rules to write.
		 */
		Events\PostType::register_post_type();
		Events\PostType::register_taxonomies();

		flush_rewrite_rules();
	}

	/**
	 * Set up a site that is created while the plugin is network active.
	 *
	 * Without this, a site added to the network tomorrow gets the code and none
	 * of the setup — the same broken half-installation as the loop above fixes,
	 * arriving later and even less visibly.
	 *
	 * @since 26.0
	 *
	 * @param mixed $site The new site.
	 * @return void
	 */
	public static function activate_new_site( $site ) {
		if ( ! is_multisite() || ! is_plugin_active_for_network( plugin_basename( QEVM_FILE ) ) ) {
			return;
		}

		$site_id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : (int) $site;

		if ( $site_id <= 0 ) {
			return;
		}

		switch_to_blog( $site_id );

		self::activate_site();

		restore_current_blog();
	}

	/**
	 * Plugin deactivation.
	 *
	 * Removes the rewrite rules this plugin added and takes the retention sweep
	 * off cron. No user data is touched — that is uninstall.php's job, and only
	 * when the site owner deletes the plugin outright.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		/*
		 * The retention sweep is a cron event that deletes attendee data. A
		 * deactivated plugin must not keep doing that, and WordPress does not
		 * clear a plugin's scheduled events for it.
		 */
		Privacy\Retention::unschedule();
		Email\Worker::unschedule();

		flush_rewrite_rules();
	}
}
