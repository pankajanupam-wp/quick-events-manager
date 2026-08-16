<?php
/**
 * Boots real WordPress, with a real database, for the integration suite.
 *
 * The unit suite stubs WordPress and says plainly that it cannot cover anything
 * involving $wpdb. Every defect this plugin has had that mattered lived in
 * exactly that gap: an occurrence query that kept serving a cancelled date, a
 * REST sanitiser that fataled, a the_content filter that recursed until memory
 * ran out. None of them could have failed a stubbed test, because none of them
 * were about our code in isolation — they were about what WordPress does with
 * it.
 *
 * ## Why this does not use WP_UnitTestCase
 *
 * The obvious harness is the WordPress test library, which wp-env already ships
 * at WP_TESTS_DIR. It was tried first and it does boot. Every test then errors
 * with `Call to undefined method PHPUnit\Util\Test::parseTestMethodAnnotations()`
 * — `WP_UnitTestCase::expectDeprecated()` runs before every test and reaches for
 * an API PHPUnit removed in 10. The library needs PHPUnit 9.
 *
 * Meeting that would mean a second PHPUnit in the project: 9 for integration,
 * 10–12 for the unit matrix, two toolchains to install, pin and keep alive in
 * CI, and integration tests written against an older assertion API than every
 * other test here. What actually catches defects is real WordPress and real
 * MySQL, not that particular base class — so this loads WordPress itself and
 * brings the twenty lines of isolation the base class was wanted for.
 *
 * Runs inside the wp-env tests container, where WordPress is already installed.
 * Nothing here downloads anything or creates an environment; there is exactly
 * one, defined at the plugins root.
 *
 * @package QuickEventsManager
 */

/*
 * The plugin lives at {abspath}wp-content/plugins/quick-events-manager, so
 * WordPress is five directories above this file. The environment variable is
 * there for anyone whose layout differs.
 */
$qevm_abspath = getenv( 'WP_ABSPATH' );

if ( ! is_string( $qevm_abspath ) || '' === $qevm_abspath ) {
	$qevm_abspath = dirname( __DIR__, 5 );
}

$qevm_abspath = rtrim( $qevm_abspath, '/\\' ) . '/';

if ( ! file_exists( $qevm_abspath . 'wp-load.php' ) ) {
	/*
	 * STDERR, not echo. This is a failure to start rather than output, and it
	 * is read in a terminal — an escaping function would mangle the very path
	 * the reader needs to see.
	 */
	fwrite(
		STDERR,
		"WordPress was not found at {$qevm_abspath}.\n\n"
		. "This suite runs inside the wp-env tests container, which has it:\n\n"
		. "  composer test:integration\n\n"
		. "Running it on the host will not work — there is no WordPress and no MySQL there.\n"
	);

	exit( 1 );
}

/*
 * WordPress reads these whatever the entry point, and there is no web server
 * here to have set them. Without a host it builds broken URLs; without a
 * request method it takes some branches meant for form submissions.
 */
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Loading WordPress to inspect it, not to render a page with it.
define( 'WP_USE_THEMES', false );

require_once $qevm_abspath . 'wp-load.php';

if ( ! defined( 'QEVM_VERSION' ) ) {
	/*
	 * The plugin has to be active rather than merely present, so that it loads
	 * in the order WordPress loads it in — early enough for `init`, which is
	 * where the post type is registered. Requiring the file here instead would
	 * load it after `init` had already run, and every test about events would
	 * fail for a reason that has nothing to do with events.
	 *
	 * `composer test:integration` activates it first. Anything that resets the
	 * test database — including the WordPress test library's own installer —
	 * empties `active_plugins`, so this is a state worth naming rather than
	 * assuming.
	 */
	fwrite(
		STDERR,
		"WordPress loaded, but Quick Events Manager did not.\n\n"
		. "It has to be active in the tests environment:\n\n"
		. "  npx @wordpress/env run tests-cli wp plugin activate quick-events-manager\n\n"
		. "Or just use `composer test:integration`, which does that first.\n"
	);

	exit( 1 );
}

/*
 * Bring the site up to date the way a real request does, so the suite starts
 * from the schema and capabilities an installed site has rather than from
 * whatever the container was left holding.
 */
QuickEventsManager\Install\Installer::upgrade_schema();
QuickEventsManager\Install\Migrations\Runner::run();

/*
 * Every module's tables, once, whatever the modules option happens to say.
 *
 * upgrade_schema() only activates the modules that are currently enabled, and
 * TestCase::setUp() rewrites that option for every test. A module whose table
 * is created on enable would therefore have one only if some earlier test had
 * switched it on — so the suite would pass or fail depending on the order it
 * ran in. DDL commits regardless of the transaction each test wraps itself in,
 * so creating them here once is both safe and the only way to make the run
 * deterministic.
 */
( new QuickEventsManager\CustomFields\CustomFieldsModule() )->activate();

require_once __DIR__ . '/TestCase.php';
