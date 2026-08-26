<?php
/**
 * The venue post type.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `qevm_venue`, an address worth typing once.
 *
 * A post type rather than a table because a venue is authored: it wants a
 * title, a description, a photo and the editor, all of which come free and none
 * of which a custom table would give without rebuilding them.
 *
 * @since 26.0
 */
final class PostType {

	/**
	 * Register the venue post type.
	 *
	 * Deliberately **not** publicly queryable, and with no archive and no
	 * rewrite rules. A venue page generated from this data would be a heading,
	 * an address and nothing else — a thin page on every site that switches the
	 * module on, indexed by search engines and answering a question nobody
	 * asked. "Every event at this venue" is a real feature and deserves a real
	 * design, not a by-product of a post type default.
	 *
	 * That choice pays for itself twice. Rewrite rules are the one thing a
	 * module cannot add cleanly: `activate()` runs from an admin request where
	 * `init` has already fired, so enabling the module would have to re-register
	 * the post type and flush the rules by hand, and disabling it would have to
	 * flush them again to take the now-dead URLs away. Registering no rules at
	 * all removes the problem rather than solving it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Venues', 'post type general name', 'quick-events-manager' ),
			'singular_name'         => _x( 'Venue', 'post type singular name', 'quick-events-manager' ),
			'menu_name'             => _x( 'Venues', 'admin menu', 'quick-events-manager' ),
			'name_admin_bar'        => _x( 'Venue', 'add new on admin bar', 'quick-events-manager' ),
			'add_new'               => __( 'Add New', 'quick-events-manager' ),
			'add_new_item'          => __( 'Add New Venue', 'quick-events-manager' ),
			'new_item'              => __( 'New Venue', 'quick-events-manager' ),
			'edit_item'             => __( 'Edit Venue', 'quick-events-manager' ),
			'view_item'             => __( 'View Venue', 'quick-events-manager' ),
			'view_items'            => __( 'View Venues', 'quick-events-manager' ),
			'all_items'             => __( 'Venues', 'quick-events-manager' ),
			'search_items'          => __( 'Search Venues', 'quick-events-manager' ),
			'not_found'             => __( 'No venues found.', 'quick-events-manager' ),
			'not_found_in_trash'    => __( 'No venues found in Trash.', 'quick-events-manager' ),
			'archives'              => __( 'Venue Archives', 'quick-events-manager' ),
			'featured_image'        => __( 'Venue Photo', 'quick-events-manager' ),
			'set_featured_image'    => __( 'Set venue photo', 'quick-events-manager' ),
			'remove_featured_image' => __( 'Remove venue photo', 'quick-events-manager' ),
			'use_featured_image'    => __( 'Use as venue photo', 'quick-events-manager' ),
			'item_published'        => __( 'Venue published.', 'quick-events-manager' ),
			'item_updated'          => __( 'Venue updated.', 'quick-events-manager' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Reusable places, so a repeated address is typed once.', 'quick-events-manager' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=' . QEVM_POST_TYPE,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => true,
			'capability_type'    => array( 'qevm_venue', 'qevm_venues' ),
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'revisions' ),
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
			'delete_with_user'   => false,
		);

		/**
		 * Filter the arguments used to register the venue post type.
		 *
		 * Making venues publicly queryable through this filter is supported, but
		 * the rewrite rules will not exist until something flushes them — the
		 * post type registers none of its own.
		 *
		 * @since 26.0
		 *
		 * @param array $args Arguments passed to register_post_type().
		 */
		$args = apply_filters( 'qevm_venue_post_type_args', $args );

		register_post_type( QEVM_POST_TYPE_VENUE, $args );
	}
}
