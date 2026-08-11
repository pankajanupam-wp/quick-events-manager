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

define( 'QEM_VERSION', '26.0' );
define( 'QEM_DB_VERSION', '1' );
define( 'QEM_FILE', '' );
define( 'QEM_PATH', '' );
define( 'QEM_URL', '' );
define( 'QEM_BASENAME', '' );
define( 'QEM_POST_TYPE', 'qem_event' );
define( 'QEM_TAX_CATEGORY', 'qem_event_category' );
define( 'QEM_TAX_TAG', 'qem_event_tag' );
define( 'QEM_OPTION_MODULES', 'qem_enabled_modules' );
define( 'QEM_OPTION_SETTINGS', 'qem_settings' );
define( 'QEM_OPTION_DB_VERSION', 'qem_db_version' );
