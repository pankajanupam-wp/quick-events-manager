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
use QuickEventsManager\CustomFields\CustomFieldsModule;
use QuickEventsManager\CustomFields\Definitions;
use QuickEventsManager\CustomFields\Field;
use QuickEventsManager\Domain\FieldType;
use QuickEventsManager\Calendar\CalendarModule;
use QuickEventsManager\Modules\Registry;
use QuickEventsManager\Registration\RegistrationModule;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$slug = getenv( 'QEVM_EVENT_SLUG' ) ? getenv( 'QEVM_EVENT_SLUG' ) : 'accessibility-fixture';

/*
 * Registration has to be on, or the form never renders and six tests pass
 * vacuously. Custom questions have to be on for the same reason: they add
 * fieldsets, radio groups and checkbox groups, which are the controls most
 * often got wrong and the ones axe has most to say about.
 */
$modules  = (array) get_option( QEVM_OPTION_MODULES, array() );
$registry = new Registry();
$changed  = false;

foreach ( array( RegistrationModule::ID, CustomFieldsModule::ID, CalendarModule::ID ) as $required ) {
	if ( in_array( $required, $modules, true ) ) {
		continue;
	}

	$modules[] = $required;
	$changed   = true;
}

if ( $changed ) {
	update_option( QEVM_OPTION_MODULES, $modules );

	foreach ( array( RegistrationModule::ID, CustomFieldsModule::ID, CalendarModule::ID ) as $required ) {
		$module = $registry->get( $required );

		if ( null !== $module ) {
			$module->activate();
		}
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
 * One question of each shape the form can render: a plain input, a long answer,
 * a dropdown, a list of radios, a single checkbox and a checkbox group. Between
 * them they cover every branch of registration-fields.php, so the axe scan sees
 * all of it rather than the one case that happened to be in the fixture.
 */
Definitions::save(
	$event_id,
	array(
		new Field( 'fdietary0001', 'Dietary requirements', FieldType::Text, false, array(), 'Tell us about allergies too.' ),
		new Field( 'faccess00001', 'Access requirements', FieldType::Textarea, false, array(), '', true ),
		new Field( 'fsession0001', 'Which session?', FieldType::Select, true, array( 'Morning', 'Afternoon' ) ),
		new Field( 'ftravel00001', 'How are you travelling?', FieldType::Radio, false, array( 'Walking', 'Public transport', 'Driving' ) ),
		new Field( 'fnewsletter1', 'Add me to the newsletter', FieldType::Checkbox ),
		new Field( 'fhelp0000001', 'Can you help on the day?', FieldType::Checkboxes, false, array( 'Setting up', 'Welcome desk', 'Clearing away' ) ),
	)
);

/*
 * The error-state and confirmation tests submit real bookings, and the
 * duplicate-address check would refuse the second run. Clearing them keeps the
 * fixture reusable without weakening that check for anybody else.
 */
\QuickEventsManager\Registration\Repository::delete_for_event( (int) $event_id );

/*
 * A page carrying the calendar shortcode, so the grid and the list can be
 * scanned and driven in a browser. Until this existed the calendar markup had
 * never been through axe at all — there was nowhere to put it.
 */

/*
 * Events in the month the calendar opens on, and in the one after it.
 *
 * Without these the calendar page renders a perfectly valid empty grid, and
 * every axe scan of it was passing on markup that contained no event links, no
 * populated list and none of the has-events styling — the same way the form
 * scans passed before the custom questions were added to this fixture. An empty
 * grid is worth scanning; it is not worth scanning instead of a full one.
 *
 * Dated relative to now, so the fixture does not quietly stop covering anything
 * the month it was written in goes by.
 */
$calendar_events = array(
	array(
		'offset' => '+2 days',
		'title'  => 'Calendar fixture: single day',
		'days'   => 0,
	),
	array(
		'offset' => '+3 days',
		'title'  => 'Calendar fixture: same day again',
		'days'   => 0,
	),
	array(
		'offset' => '+5 days',
		'title'  => 'Calendar fixture: across three days',
		'days'   => 2,
	),
	array(
		'offset' => '+35 days',
		'title'  => 'Calendar fixture: next month',
		'days'   => 0,
	),
);

foreach ( $calendar_events as $index => $spec ) {
	$start_at = new DateTimeImmutable( $spec['offset'] . ' 18:00', wp_timezone() );
	$end_at   = $start_at->modify( '+' . $spec['days'] . ' days' )->modify( '+2 hours' );

	$calendar_event_slug = 'qevm-calendar-event-' . $index;
	$calendar_existing   = get_page_by_path( $calendar_event_slug, OBJECT, QEVM_POST_TYPE );

	$calendar_event = array(
		'post_type'    => QEVM_POST_TYPE,
		'post_status'  => 'publish',
		'post_name'    => $calendar_event_slug,
		'post_title'   => $spec['title'],
		'post_content' => 'An event that exists so the calendar has something in it to scan.',
		'meta_input'   => array(
			Meta::START_LOCAL => $start_at->format( 'Y-m-d H:i:s' ),
			Meta::END_LOCAL   => $end_at->format( 'Y-m-d H:i:s' ),
			Meta::TIMEZONE    => wp_timezone_string(),
			Meta::START_UTC   => Meta::to_utc( $start_at->format( 'Y-m-d H:i:s' ), wp_timezone_string() ),
			Meta::END_UTC     => Meta::to_utc( $end_at->format( 'Y-m-d H:i:s' ), wp_timezone_string() ),
		),
	);

	if ( $calendar_existing instanceof WP_Post ) {
		$calendar_event['ID'] = $calendar_existing->ID;

		wp_update_post( wp_slash( $calendar_event ), true );
	} else {
		wp_insert_post( wp_slash( $calendar_event ), true );
	}
}

$calendar_slug = 'qevm-calendar-fixture';
$calendar_page = get_page_by_path( $calendar_slug, OBJECT, 'page' );

$calendar = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_name'    => $calendar_slug,
	'post_title'   => 'Calendar fixture',
	'post_content' => '[qevm_event_calendar]',
);

if ( $calendar_page instanceof WP_Post ) {
	$calendar['ID'] = $calendar_page->ID;

	wp_update_post( wp_slash( $calendar ), true );
} else {
	wp_insert_post( wp_slash( $calendar ), true );
}

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
