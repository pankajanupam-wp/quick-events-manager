<?php
/**
 * Constant definitions for static analysis only.
 *
 * The plugin defines these in its main file before the autoloader runs, so
 * PHPStan cannot see them when analysing a class in isolation. Declaring them
 * here means a typo in a constant name is reported as an error rather than
 * silently treated as an undefined constant.
 *
 * This file is never loaded at runtime and never ships. It is listed in
 * phpstan.neon.dist as a bootstrap file and in .distignore as an exclusion.
 *
 * @package QuickEventsManager
 */

define( 'QEVM_VERSION', '26.0' );
define( 'QEVM_DB_VERSION', '1' );
define( 'QEVM_FILE', '' );
define( 'QEVM_PATH', '' );
define( 'QEVM_URL', '' );
define( 'QEVM_BASENAME', '' );
define( 'QEVM_POST_TYPE', 'qevm_event' );
define( 'QEVM_POST_TYPE_VENUE', 'qevm_venue' );
define( 'QEVM_POST_TYPE_ORGANIZER', 'qevm_organizer' );
define( 'QEVM_TAX_CATEGORY', 'qevm_event_category' );
define( 'QEVM_TAX_TAG', 'qevm_event_tag' );
define( 'QEVM_OPTION_MODULES', 'qevm_enabled_modules' );
define( 'QEVM_OPTION_SETTINGS', 'qevm_settings' );
define( 'QEVM_OPTION_DB_VERSION', 'qevm_db_version' );
