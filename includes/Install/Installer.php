<?php
/**
 * Schema, capabilities and version bookkeeping.
 *
 * @package QuickEventsManager
 */

namespace QEM\Install;

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
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function install() {
		self::add_capabilities();

		update_option( QEM_OPTION_DB_VERSION, QEM_DB_VERSION );
	}

	/**
	 * Create or update the tables if the stored schema version is behind.
	 *
	 * Only tables belonging to modules that are switched on are touched, so a
	 * site that never enables registration never grows a registrations table.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( QEM_OPTION_DB_VERSION ) === (string) QEM_DB_VERSION ) {
			return;
		}

		self::add_capabilities();

		$registry = \QEM\Plugin::instance()->registry();

		foreach ( $registry->enabled_ids() as $id ) {
			$module = $registry->get( $id );

			if ( null !== $module ) {
				$module->activate();
			}
		}

		update_option( QEM_OPTION_DB_VERSION, QEM_DB_VERSION );
	}

	/**
	 * Run a CREATE TABLE statement through dbDelta().
	 *
	 * dbDelta() lives in an admin include that is not loaded on front-end
	 * requests, so it has to be required explicitly. It is also famously
	 * picky: two spaces after PRIMARY KEY, no backticks around the table
	 * name, and one field per line.
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

		return $wpdb->prefix . 'qem_' . $name;
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
			'edit_qem_event',
			'read_qem_event',
			'delete_qem_event',
			'edit_qem_events',
			'publish_qem_events',
			'delete_qem_events',
		);

		if ( $include_others ) {
			$caps = array_merge(
				$caps,
				array(
					'edit_others_qem_events',
					'delete_others_qem_events',
					'read_private_qem_events',
					'edit_published_qem_events',
					'delete_published_qem_events',
					'manage_qem_registrations',
				)
			);
		}

		return $caps;
	}
}
