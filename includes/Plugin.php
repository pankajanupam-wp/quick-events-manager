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
	 * @return void
	 */
	public static function activate() {
		Installer::install();
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
	 * Plugin deactivation.
	 *
	 * Removes the rewrite rules this plugin added and nothing else. No user
	 * data is touched — that is uninstall.php's job, and only when the site
	 * owner deletes the plugin outright.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
