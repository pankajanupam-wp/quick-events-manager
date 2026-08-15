<?php
/**
 * The event the accessibility run needs, created idempotently.
 *
 * Run with:
 *
 *     wp eval-file wp-content/plugins/quick-events-manager/tests/e2e/fixture.php
 *
 * Idempotent on purpose: the accessibility suite books a place every time it
 * runs, so the fixture has to survive being seeded repeatedly without
 * accumulating events or tripping the duplicate-address check. The capacity is
 * left open for the same reason — a fixture that fills up starts returning the
 * waiting-list screen instead of the confirmation, and the failure looks like
 * an accessibility regression rather than a stale fixture.
 *
 * @package QuickEventsManager
 */

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Modules\Registry;
use QuickEventsManager\Registration\RegistrationModule;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$slug = getenv( 'QEVM_EVENT_SLUG' ) ? getenv( 'QEVM_EVENT_SLUG' ) : 'accessibility-fixture';

// Registration has to be on, or the form never renders and six tests pass vacuously.
$modules = (array) get_option( QEVM_OPTION_MODULES, array() );

if ( ! in_array( RegistrationModule::ID, $modules, true ) ) {
	$modules[] = RegistrationModule::ID;

	update_option( QEVM_OPTION_MODULES, $modules );

	$registry = new Registry();
	$module   = $registry->get( RegistrationModule::ID );

	if ( null !== $module ) {
		$module->activate();
	}
}

$existing = get_page_by_path( $slug, OBJECT, QEVM_POST_TYPE );
$start    = time() + ( 30 * DAY_IN_SECONDS );

$meta = array(
	Meta::START_UTC            => gmdate( Meta::FORMAT, $start ),
	Meta::END_UTC              => gmdate( Meta::FORMAT, $start + ( 2 * HOUR_IN_SECONDS ) ),
	Meta::START_LOCAL          => wp_date( Meta::FORMAT, $start ),
	Meta::END_LOCAL            => wp_date( Meta::FORMAT, $start + ( 2 * HOUR_IN_SECONDS ) ),
	Meta::TIMEZONE             => wp_timezone_string(),
	Meta::CAPACITY             => '0',
	Meta::REGISTRATION_ENABLED => '1',
	Meta::VENUE_NAME           => 'The Old Library',
	Meta::VENUE_CITY           => 'Sheffield',
	Meta::ORGANIZER_NAME       => 'Accessibility Fixture',
	Meta::ORGANIZER_EMAIL      => 'fixture@example.com',
);

$post = array(
	'post_type'    => QEVM_POST_TYPE,
	'post_status'  => 'publish',
	'post_name'    => $slug,
	'post_title'   => 'Accessibility fixture event',
	'post_content' => 'An event that exists so axe-core has something to look at. It has a description long enough to be rendered as real content rather than an empty page.',
	'meta_input'   => $meta,
);

if ( $existing instanceof WP_Post ) {
	$post['ID'] = $existing->ID;

	$event_id = wp_update_post( wp_slash( $post ), true );
} else {
	$event_id = wp_insert_post( wp_slash( $post ), true );
}

if ( is_wp_error( $event_id ) ) {
	WP_CLI::error( $event_id->get_error_message() );
}

/*
 * The error-state and confirmation tests submit real bookings, and the
 * duplicate-address check would refuse the second run. Clearing them keeps the
 * fixture reusable without weakening that check for anybody else.
 */
\QuickEventsManager\Registration\Repository::delete_for_event( (int) $event_id );

/*
 * A second event that has already happened, for the closed-registration screen.
 *
 * Registration closing is not one state: an event that has finished, a closing
 * date that has passed, and registration that was never switched on are three
 * different things and only two of them are worth explaining to a visitor.
 * This fixture covers the one a person is most likely to arrive at from an old
 * link.
 */
$past_slug = $slug . '-past';
$past      = get_page_by_path( $past_slug, OBJECT, QEVM_POST_TYPE );
$then      = time() - ( 30 * DAY_IN_SECONDS );

$past_post = array(
	'post_type'    => QEVM_POST_TYPE,
	'post_status'  => 'publish',
	'post_name'    => $past_slug,
	'post_title'   => 'Accessibility fixture event that has finished',
	'post_content' => 'An event in the past, so the closed-registration notice has something to render against.',
	'meta_input'   => array(
		Meta::START_UTC            => gmdate( Meta::FORMAT, $then ),
		Meta::END_UTC              => gmdate( Meta::FORMAT, $then + HOUR_IN_SECONDS ),
		Meta::START_LOCAL          => wp_date( Meta::FORMAT, $then ),
		Meta::END_LOCAL            => wp_date( Meta::FORMAT, $then + HOUR_IN_SECONDS ),
		Meta::TIMEZONE             => wp_timezone_string(),
		Meta::CAPACITY             => '0',
		Meta::REGISTRATION_ENABLED => '1',
	),
);

if ( $past instanceof WP_Post ) {
	$past_post['ID'] = $past->ID;

	wp_update_post( wp_slash( $past_post ), true );
} else {
	wp_insert_post( wp_slash( $past_post ), true );
}

/*
 * Flush the rewrite rules.
 *
 * The post type pins its permalinks to /events/{slug}/, and the form redirects
 * there after a submission. Without a flush the rules for that slug are simply
 * absent, the redirect lands on the home page with a 200, and every test that
 * submits the form fails claiming the confirmation is missing — which reads as
 * a plugin bug and is not one. wp-env starts from a fresh database, so this is
 * the normal state in CI rather than an edge case.
 */
flush_rewrite_rules( false );

WP_CLI::success( sprintf( 'Fixture event %d ready at /events/%s/', $event_id, $slug ) );
