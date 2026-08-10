<?php
/**
 * The event post type and its taxonomies.
 *
 * @package QuickEventsManager
 */

namespace QEM\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `qem_event` and the two taxonomies that classify it.
 *
 * @since 26.0
 */
final class PostType {

	/**
	 * Register the event post type.
	 *
	 * The rewrite slug and archive are pinned to `events` rather than left to
	 * default from the post type key. Version 1.0 registered a post type
	 * literally called `events`, so its permalinks were /events/{slug} with an
	 * archive at /events/. Keeping those strings here means every existing
	 * link, bookmark and search result still resolves after the upgrade, while
	 * the stored key moves to something namespaced that cannot collide with
	 * another plugin.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Events', 'post type general name', 'quick-events-manager' ),
			'singular_name'         => _x( 'Event', 'post type singular name', 'quick-events-manager' ),
			'menu_name'             => _x( 'Events', 'admin menu', 'quick-events-manager' ),
			'name_admin_bar'        => _x( 'Event', 'add new on admin bar', 'quick-events-manager' ),
			'add_new'               => __( 'Add New', 'quick-events-manager' ),
			'add_new_item'          => __( 'Add New Event', 'quick-events-manager' ),
			'new_item'              => __( 'New Event', 'quick-events-manager' ),
			'edit_item'             => __( 'Edit Event', 'quick-events-manager' ),
			'view_item'             => __( 'View Event', 'quick-events-manager' ),
			'view_items'            => __( 'View Events', 'quick-events-manager' ),
			'all_items'             => __( 'All Events', 'quick-events-manager' ),
			'search_items'          => __( 'Search Events', 'quick-events-manager' ),
			'not_found'             => __( 'No events found.', 'quick-events-manager' ),
			'not_found_in_trash'    => __( 'No events found in Trash.', 'quick-events-manager' ),
			'archives'              => __( 'Event Archives', 'quick-events-manager' ),
			'featured_image'        => __( 'Event Image', 'quick-events-manager' ),
			'set_featured_image'    => __( 'Set event image', 'quick-events-manager' ),
			'remove_featured_image' => __( 'Remove event image', 'quick-events-manager' ),
			'use_featured_image'    => __( 'Use as event image', 'quick-events-manager' ),
			'item_published'        => __( 'Event published.', 'quick-events-manager' ),
			'item_updated'          => __( 'Event updated.', 'quick-events-manager' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Events with dates, locations and registration.', 'quick-events-manager' ),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => true,
			'show_in_rest'       => true,
			'menu_icon'          => 'dashicons-calendar-alt',
			'menu_position'      => 20,
			'capability_type'    => array( 'qem_event', 'qem_events' ),
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
			'has_archive'        => 'events',
			'rewrite'            => array(
				'slug'       => 'events',
				'with_front' => false,
			),
			'query_var'          => true,
			'delete_with_user'   => false,
		);

		/**
		 * Filter the arguments used to register the event post type.
		 *
		 * @since 26.0
		 *
		 * @param array $args Arguments passed to register_post_type().
		 */
		$args = apply_filters( 'qem_post_type_args', $args );

		register_post_type( QEM_POST_TYPE, $args );
	}

	/**
	 * Register the event category and tag taxonomies.
	 *
	 * Separate taxonomies rather than reusing core's `category` and `post_tag`:
	 * an event category list has nothing to do with a blog category list, and
	 * sharing them makes both archives confusing.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			QEM_TAX_CATEGORY,
			QEM_POST_TYPE,
			array(
				'labels'            => array(
					'name'          => _x( 'Event Categories', 'taxonomy general name', 'quick-events-manager' ),
					'singular_name' => _x( 'Event Category', 'taxonomy singular name', 'quick-events-manager' ),
					'search_items'  => __( 'Search Event Categories', 'quick-events-manager' ),
					'all_items'     => __( 'All Event Categories', 'quick-events-manager' ),
					'edit_item'     => __( 'Edit Event Category', 'quick-events-manager' ),
					'update_item'   => __( 'Update Event Category', 'quick-events-manager' ),
					'add_new_item'  => __( 'Add New Event Category', 'quick-events-manager' ),
					'new_item_name' => __( 'New Event Category Name', 'quick-events-manager' ),
					'menu_name'     => __( 'Categories', 'quick-events-manager' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'event-category',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			QEM_TAX_TAG,
			QEM_POST_TYPE,
			array(
				'labels'            => array(
					'name'          => _x( 'Event Tags', 'taxonomy general name', 'quick-events-manager' ),
					'singular_name' => _x( 'Event Tag', 'taxonomy singular name', 'quick-events-manager' ),
					'search_items'  => __( 'Search Event Tags', 'quick-events-manager' ),
					'all_items'     => __( 'All Event Tags', 'quick-events-manager' ),
					'edit_item'     => __( 'Edit Event Tag', 'quick-events-manager' ),
					'update_item'   => __( 'Update Event Tag', 'quick-events-manager' ),
					'add_new_item'  => __( 'Add New Event Tag', 'quick-events-manager' ),
					'new_item_name' => __( 'New Event Tag Name', 'quick-events-manager' ),
					'menu_name'     => __( 'Tags', 'quick-events-manager' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'event-tag',
					'with_front' => false,
				),
			)
		);
	}
}
