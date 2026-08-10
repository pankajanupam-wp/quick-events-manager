<?php
/**
 * Plugin Name:       Quick Events Manager
 * Plugin URI:        https://www.pankajanupam.com/wordpress-plugins/quick-events-manager/
 * Description:       Create events, publish them, and take free registrations. Start simple and switch on more features only when you need them.
 * Version:           26.0
 * Requires at least: 5.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Pankaj Anupam
 * Author URI:        https://www.pankajanupam.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quick-events-manager
 * Domain Path:       /languages
 *
 * @package QuickEventsManager
 */

/**
 * Copyright 2011-2026 Pankaj Anupam (email: mymail.anupam@gmail.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version, kept in sync with the header and the readme stable tag.
 */
define( 'QEM_VERSION', '26.0' );

/**
 * Schema version for the custom tables.
 *
 * Bumped only when a table changes, which is far less often than the plugin
 * version. The installer compares this against the stored qem_db_version and
 * runs dbDelta() when they differ, so an upgrade that touches no table costs
 * nothing on the front end.
 */
define( 'QEM_DB_VERSION', '1' );

/**
 * Absolute path to this file.
 */
define( 'QEM_FILE', __FILE__ );

/**
 * Plugin directory path, with trailing slash.
 */
define( 'QEM_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL, with trailing slash.
 */
define( 'QEM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename, e.g. quick-events-manager/quick-events-manager.php.
 */
define( 'QEM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Post type key for events.
 *
 * Version 1.0 registered the bare key `events`, which is generic enough that
 * any other event plugin or theme registering the same key silently wins.
 * The prefixed key is namespaced to this plugin; the public `/events/` URLs
 * are preserved by the rewrite slug instead, and Migrator moves existing rows
 * across. Never change this value again — it is written into wp_posts.
 */
define( 'QEM_POST_TYPE', 'qem_event' );

/**
 * Taxonomy key for event categories.
 */
define( 'QEM_TAX_CATEGORY', 'qem_event_category' );

/**
 * Taxonomy key for event tags.
 */
define( 'QEM_TAX_TAG', 'qem_event_tag' );

/**
 * Option holding the ids of the feature modules the site has switched on.
 */
define( 'QEM_OPTION_MODULES', 'qem_enabled_modules' );

/**
 * Option holding general plugin settings.
 */
define( 'QEM_OPTION_SETTINGS', 'qem_settings' );

/**
 * Option holding the installed schema version.
 */
define( 'QEM_OPTION_DB_VERSION', 'qem_db_version' );

require_once QEM_PATH . 'includes/Autoloader.php';

QEM\Autoloader::register();

register_activation_hook( __FILE__, array( 'QEM\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'QEM\Plugin', 'deactivate' ) );

QEM\Plugin::instance()->boot();
