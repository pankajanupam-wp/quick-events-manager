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
function quick_events_manager_uninstall_site() {
	global $wpdb;

	$quick_events_manager_options = array(
		'qem_settings',
		'qem_enabled_modules',
		'qem_db_version',
		'qem_migrated_legacy_post_type',
	);

	foreach ( $quick_events_manager_options as $quick_events_manager_option ) {
		delete_option( $quick_events_manager_option );
	}

	$quick_events_manager_roles = array( 'administrator', 'editor' );
	$quick_events_manager_caps  = array(
		'edit_qem_event',
		'read_qem_event',
		'delete_qem_event',
		'edit_qem_events',
		'publish_qem_events',
		'delete_qem_events',
		'edit_others_qem_events',
		'delete_others_qem_events',
		'read_private_qem_events',
		'edit_published_qem_events',
		'delete_published_qem_events',
		'manage_qem_registrations',
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

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Dropping our own table on uninstall; no caching applies.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}qem_registrations" );
}

/*
 * On a network install every site has its own options table, its own roles and
 * its own registrations table, so each one has to be cleaned in turn.
 */
if ( is_multisite() ) {
	$quick_events_manager_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $quick_events_manager_sites as $quick_events_manager_site_id ) {
		switch_to_blog( $quick_events_manager_site_id );
		quick_events_manager_uninstall_site();
		restore_current_blog();
	}
} else {
	quick_events_manager_uninstall_site();
}
