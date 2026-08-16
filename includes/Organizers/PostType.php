<?php
/**
 * The organiser post type.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Organizers;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `qevm_organizer`, a contact worth typing once.
 *
 * Not publicly queryable, no archive and no rewrite rules, for the same reasons
 * as venues: a generated page listing somebody's name, email address and phone
 * number is a page nobody asked for and a scraper will thank you for. It also
 * keeps the module free of rewrite flushing, which `activate()` cannot do
 * cleanly from an admin request where `init` has already run.
 *
 * @since 26.0
 */
final class PostType {

	/**
	 * Register the organiser post type.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Organisers', 'post type general name', 'quick-events-manager' ),
			'singular_name'         => _x( 'Organiser', 'post type singular name', 'quick-events-manager' ),
			'menu_name'             => _x( 'Organisers', 'admin menu', 'quick-events-manager' ),
			'name_admin_bar'        => _x( 'Organiser', 'add new on admin bar', 'quick-events-manager' ),
			'add_new'               => __( 'Add New', 'quick-events-manager' ),
			'add_new_item'          => __( 'Add New Organiser', 'quick-events-manager' ),
			'new_item'              => __( 'New Organiser', 'quick-events-manager' ),
			'edit_item'             => __( 'Edit Organiser', 'quick-events-manager' ),
			'view_item'             => __( 'View Organiser', 'quick-events-manager' ),
			'view_items'            => __( 'View Organisers', 'quick-events-manager' ),
			'all_items'             => __( 'Organisers', 'quick-events-manager' ),
			'search_items'          => __( 'Search Organisers', 'quick-events-manager' ),
			'not_found'             => __( 'No organisers found.', 'quick-events-manager' ),
			'not_found_in_trash'    => __( 'No organisers found in Trash.', 'quick-events-manager' ),
			'archives'              => __( 'Organiser Archives', 'quick-events-manager' ),
			'featured_image'        => __( 'Organiser Logo', 'quick-events-manager' ),
			'set_featured_image'    => __( 'Set organiser logo', 'quick-events-manager' ),
			'remove_featured_image' => __( 'Remove organiser logo', 'quick-events-manager' ),
			'use_featured_image'    => __( 'Use as organiser logo', 'quick-events-manager' ),
			'item_published'        => __( 'Organiser published.', 'quick-events-manager' ),
			'item_updated'          => __( 'Organiser updated.', 'quick-events-manager' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Reusable contacts, so a repeated organiser is typed once.', 'quick-events-manager' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=' . QEVM_POST_TYPE,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => true,
			'capability_type'    => array( 'qevm_organizer', 'qevm_organizers' ),
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'revisions' ),
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
			'delete_with_user'   => false,
		);

		/**
		 * Filter the arguments used to register the organiser post type.
		 *
		 * @since 26.0
		 *
		 * @param array $args Arguments passed to register_post_type().
		 */
		$args = apply_filters( 'qevm_organizer_post_type_args', $args );

		register_post_type( QEVM_POST_TYPE_ORGANIZER, $args );
	}
}
