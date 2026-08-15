<?php
/**
 * Runs when the site owner deletes the plugin.
 *
 * Deactivation leaves everything alone; deletion is the one point at which a
 * plugin is being told it is not wanted. Even then the events themselves are
 * left in place — they are posts the site owner wrote, and a plugin has no
 * business deleting somebody's content.
 *
 * What goes: the plugin's own options, its capabilities, and the registrations
 * table, which is data only this plugin can interpret.
 *
 * @package QuickEventsManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything this plugin created on one site.
 *
 * @return void
 */
function qevm_uninstall_site() {
	global $wpdb;

	$quick_events_manager_options = array(
		'qevm_settings',
		'qevm_enabled_modules',
		'qevm_db_version',
		'qevm_migration_lock',
	);

	foreach ( $quick_events_manager_options as $quick_events_manager_option ) {
		delete_option( $quick_events_manager_option );
	}

	/*
	 * Kept in step with Installer::capabilities() by a test, because this file
	 * cannot call it — uninstall.php runs with the plugin unloaded, so there is
	 * no autoloader and no class to ask. A capability added there and forgotten
	 * here would survive deletion and quietly stay on every role.
	 */
	$quick_events_manager_roles = array( 'administrator', 'editor' );
	$quick_events_manager_caps  = array(
		'edit_qevm_event',
		'read_qevm_event',
		'delete_qevm_event',
		'edit_qevm_events',
		'publish_qevm_events',
		'delete_qevm_events',
		'edit_others_qevm_events',
		'delete_others_qevm_events',
		'read_private_qevm_events',
		'edit_published_qevm_events',
		'delete_published_qevm_events',
		'manage_qevm_registrations',
		'manage_qevm_checkins',
	);

	foreach ( $quick_events_manager_roles as $quick_events_manager_role_name ) {
		$quick_events_manager_role = get_role( $quick_events_manager_role_name );

		if ( null === $quick_events_manager_role ) {
			continue;
		}

		foreach ( $quick_events_manager_caps as $quick_events_manager_cap ) {
			$quick_events_manager_role->remove_cap( $quick_events_manager_cap );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Dropping our own tables on uninstall; no caching applies.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}qevm_registrations" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As above.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}qevm_occurrences" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As above.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}qevm_attendees" );
}

/*
 * On a network install every site has its own options table, its own roles and
 * its own plugin tables, so each one has to be cleaned in turn.
 */
if ( is_multisite() ) {
	$qevm_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $qevm_sites as $qevm_site_id ) {
		switch_to_blog( $qevm_site_id );
		qevm_uninstall_site();
		restore_current_blog();
	}
} else {
	qevm_uninstall_site();
}
