<?php
/**
 * Plugin Name:       Quick Events Manager
 * Plugin URI:        https://www.pankajanupam.com/wordpress-plugins/quick-events-manager/
 * Description:       Create events, publish them, and take free registrations. Start simple and switch on more features only when you need them.
 * Version:           26.0
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      8.1
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

/*
 * Refuse to load on an unsupported platform, and say why.
 *
 * WordPress reads the Requires PHP and Requires at least headers and blocks
 * activation and auto-update accordingly, so this guard is not the first line
 * of defence. It is the second: a site that is already active and whose host
 * then downgrades PHP gets an admin notice here rather than a fatal error and
 * a white screen.
 *
 * This has to happen before the autoloader, because the classes in src/ use
 * enums and readonly properties. Those are parse errors on PHP below 8.1, and
 * a parse error cannot be caught by any check that runs after the file loads.
 *
 * Everything above this point must itself parse on ancient PHP, which is why
 * this block uses nothing newer than a closure.
 *
 * See docs/adr/0002-php-and-wordpress-versions.md.
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: 1: Required PHP version, 2: PHP version currently running. */
				esc_html__( 'Quick Events Manager requires PHP %1$s or newer. This site is running PHP %2$s, so the plugin has not been loaded.', 'quick-events-manager' ),
				'8.1',
				esc_html( PHP_VERSION )
			);
			echo '</p></div>';
		}
	);

	return;
}

if ( version_compare( get_bloginfo( 'version' ), '6.5', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: 1: Required WordPress version, 2: WordPress version currently running. */
				esc_html__( 'Quick Events Manager requires WordPress %1$s or newer. This site is running WordPress %2$s, so the plugin has not been loaded.', 'quick-events-manager' ),
				'6.5',
				esc_html( get_bloginfo( 'version' ) )
			);
			echo '</p></div>';
		}
	);

	return;
}

/**
 * Plugin version, kept in sync with the header and the readme stable tag.
 */
define( 'QEVM_VERSION', '26.0' );

/**
 * Schema version for the custom tables.
 *
 * An integer, bumped once per migration, and far less often than the plugin
 * version. The runner compares it against the stored qevm_db_version to decide
 * what is outstanding, so an upgrade with no migration behind it costs a single
 * option read.
 *
 * This must equal the highest version in includes/Install/Migrations/. A test
 * asserts it, because a migration added without bumping this would never run.
 */
define( 'QEVM_DB_VERSION', 7 );

/**
 * Absolute path to this file.
 */
define( 'QEVM_FILE', __FILE__ );

/**
 * Plugin directory path, with trailing slash.
 */
define( 'QEVM_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL, with trailing slash.
 */
define( 'QEVM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename, e.g. quick-events-manager/quick-events-manager.php.
 */
define( 'QEVM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Post type key for events.
 *
 * Version 1.0 registered the bare key `events`, which is generic enough that
 * any other event plugin or theme registering the same key silently wins.
 * The prefixed key is namespaced to this plugin; the public `/events/` URLs
 * are preserved by the rewrite slug instead, and migration 1 moves existing
 * rows across. Never change this value again — it is written into wp_posts.
 */
define( 'QEVM_POST_TYPE', 'qevm_event' );

/**
 * Post type key for reusable venues.
 *
 * Registered only while the venues module is enabled. Events keep their flat
 * address meta whether or not it is, so this post type is somewhere to put an
 * address that repeats, never the only place an address can live.
 */
define( 'QEVM_POST_TYPE_VENUE', 'qevm_venue' );

/**
 * Post type key for reusable organisers.
 *
 * Spelled the American way to match `_qevm_organizer_*`, which 26.0 already
 * registered and which cannot change. Everything a user reads says "organiser".
 */
define( 'QEVM_POST_TYPE_ORGANIZER', 'qevm_organizer' );

/**
 * Taxonomy key for event categories.
 */
define( 'QEVM_TAX_CATEGORY', 'qevm_event_category' );

/**
 * Taxonomy key for event tags.
 */
define( 'QEVM_TAX_TAG', 'qevm_event_tag' );

/**
 * Option holding the ids of the feature modules the site has switched on.
 */
define( 'QEVM_OPTION_MODULES', 'qevm_enabled_modules' );

/**
 * Option holding general plugin settings.
 */
define( 'QEVM_OPTION_SETTINGS', 'qevm_settings' );

/**
 * Option holding the installed schema version.
 */
define( 'QEVM_OPTION_DB_VERSION', 'qevm_db_version' );

require_once QEVM_PATH . 'includes/Autoloader.php';

QuickEventsManager\Autoloader::register();

register_activation_hook( __FILE__, array( 'QuickEventsManager\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'QuickEventsManager\Plugin', 'deactivate' ) );

QuickEventsManager\Plugin::instance()->boot();
