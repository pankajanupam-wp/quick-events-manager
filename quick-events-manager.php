<?php
/*
Plugin Name: Quick Events Manager
Plugin URI: http://pankajanupam.in/wordpress-plugins/quick-events-manager/
Description: Manage event
Version: 1.0
Author: PANKAJ ANUPAM
Author URI: http://pankajanupam.in

* LICENSE
    Copyright 2011 PANKAJ ANUPAM  (email : mymail.anupam@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
?>
<?php 
function pa_event(){
    
    $labels = array(
    'name' =>'Event',
    'singular_name' =>'Event',
    'add_new_item' => 'Add New Event',
    'edit_item' => 'Edit Event',
    'new_item' =>'New Event',
    'all_items' => 'All Event',
    'view_item' =>'View Event',
    'search_items' => 'Search Event',
    'not_found' =>  'No Event found',
    'not_found_in_trash' => 'No Event found in Trash', 
    'parent_item_colon' => '',
    'menu_name' => 'Event'

  );
  $args = array(
    'labels' => $labels,
    'public' => true,
    'publicly_queryable' => true,
    'show_ui' => true, 
    'show_in_menu' => true, 
    'query_var' => true,
    'rewrite' => true,
    'capability_type' => 'post',
    'has_archive' => true, 
    'hierarchical' => false,
    'menu_position' => null,
    'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments' )
  );   
  register_post_type('events', $args);
}
add_action('init', 'pa_event');

?>