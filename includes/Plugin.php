<?php
/**
 * Wires the plugin together.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager;

use QuickEventsManager\Install\Installer;
use QuickEventsManager\Install\Migrator;
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
	 * On `admin_init` rather than activation, because a plugin updated in
	 * place through the dashboard or WP-CLI never fires its activation hook.
	 * Both routines compare a stored version first and cost one option read
	 * when there is nothing to do.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		Installer::maybe_upgrade();
		Migrator::maybe_migrate();
	}

	/**
	 * Plugin activation.
	 *
	 * Deliberately minimal: it adds capabilities, records the version, and
	 * flushes rewrites so `/events/` resolves immediately. Tables belong to
	 * the modules that need them and are created when those are switched on.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function activate() {
		Installer::install();
		Migrator::maybe_migrate();

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
