<?php
/**
 * Schema, capabilities and version bookkeeping.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Creates what the plugin needs on disk and in the database.
 *
 * @since 26.0
 */
final class Installer {

	/**
	 * Roles that get event capabilities, and whether they get the "others" set.
	 *
	 * @var array<string, bool>
	 */
	const ROLES = array(
		'administrator' => true,
		'editor'        => true,
	);

	/**
	 * Run the full install.
	 *
	 * Deliberately does not record the schema version. That is the migration
	 * runner's job, and only once it has actually applied everything.
	 *
	 * Stamping here would be wrong in a case that is easy to miss: a site still
	 * running 1.0 that deactivates it and activates 26.0 fires this hook, and if
	 * activation declared the schema current, the legacy post-type migration
	 * would be skipped and those events would stay invisible. On a genuinely
	 * fresh install nothing is lost by leaving it to the runner — every
	 * migration finds no work and completes immediately.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function install() {
		self::add_capabilities();
	}

	/**
	 * Create or update the tables belonging to enabled modules.
	 *
	 * Only tables belonging to modules that are switched on are touched, so a
	 * site that never enables registration never grows a registrations table.
	 *
	 * dbDelta is idempotent, so this is safe to call whenever the stored version
	 * is behind — including on each request of a migration that is taking more
	 * than one request to finish.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function upgrade_schema() {
		self::add_capabilities();

		$registry = \QuickEventsManager\Plugin::instance()->registry();

		foreach ( $registry->enabled_ids() as $id ) {
			$module = $registry->get( $id );

			if ( null !== $module ) {
				$module->activate();
			}
		}
	}

	/**
	 * Run a CREATE TABLE statement through dbDelta().
	 *
	 * The dbDelta() function lives in an admin include that is not loaded on
	 * front-end requests, so it has to be required explicitly. It is also
	 * famously picky: two spaces after PRIMARY KEY, no backticks around the
	 * table name, and one field per line.
	 *
	 * @since 26.0
	 *
	 * @param string $sql Full CREATE TABLE statement.
	 * @return void
	 */
	public static function run_schema( $sql ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}

	/**
	 * The charset/collate clause for a CREATE TABLE statement.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function charset_collate() {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}

	/**
	 * Prefixed name of one of the plugin's tables.
	 *
	 * @since 26.0
	 *
	 * @param string $name Unprefixed table name, e.g. 'registrations'.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'qevm_' . $name;
	}

	/**
	 * Grant event capabilities to the roles that should manage events.
	 *
	 * The post type uses a custom capability type rather than mapping onto
	 * `post`, which is what later allows a check-in-only staff role to exist
	 * without also handing out the right to edit posts.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function add_capabilities() {
		foreach ( self::ROLES as $role_name => $manages_others ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::capabilities( $manages_others ) as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the plugin's capabilities from every role.
	 *
	 * Used by uninstall.php. Left out of deactivation on purpose: a site owner
	 * deactivating to debug something should not have to rebuild their roles.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function remove_capabilities() {
		foreach ( array_keys( self::ROLES ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::capabilities( true ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * The capability names for the event post type.
	 *
	 * @since 26.0
	 *
	 * @param bool $include_others Whether to include the manage-others caps.
	 * @return string[]
	 */
	public static function capabilities( $include_others = true ) {
		$caps = array(
			'edit_qevm_event',
			'read_qevm_event',
			'delete_qevm_event',
			'edit_qevm_events',
			'publish_qevm_events',
			'delete_qevm_events',
		);

		if ( $include_others ) {
			$caps = array_merge(
				$caps,
				array(
					'edit_others_qevm_events',
					'delete_others_qevm_events',
					'read_private_qevm_events',
					'edit_published_qevm_events',
					'delete_published_qevm_events',
					'manage_qevm_registrations',
				)
			);
		}

		return $caps;
	}
}
