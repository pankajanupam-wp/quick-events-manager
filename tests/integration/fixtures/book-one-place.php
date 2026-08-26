<?php
/**
 * Books one place, in its own process, at a moment agreed with its siblings.
 *
 * Run by CapacityRaceTest, eight at a time. Each copy boots WordPress — which
 * takes long enough that simply starting eight processes would stagger their
 * inserts — and then waits for a shared wall-clock instant before submitting.
 * That is what makes the race a race rather than eight bookings in a queue.
 *
 * Usage: php book-one-place.php <abspath> <event-id> <email> <start-unix-time> [ticket-type-id]
 *
 * Prints one line: the resulting status, or `error:<code>`.
 *
 * @package QuickEventsManager
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- A CLI fixture writing to a pipe, not a page.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$qevm_abspath = rtrim( (string) ( $argv[1] ?? '' ), '/\\' ) . '/';
$qevm_event   = (int) ( $argv[2] ?? 0 );
$qevm_email   = (string) ( $argv[3] ?? '' );
$qevm_start   = (float) ( $argv[4] ?? 0 );
$qevm_ticket  = (int) ( $argv[5] ?? 0 );

if ( ! file_exists( $qevm_abspath . 'wp-load.php' ) || $qevm_event <= 0 ) {
	fwrite( STDERR, "usage: book-one-place.php <abspath> <event-id> <email> <start-time>\n" );

	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

define( 'WP_USE_THEMES', false );

require_once $qevm_abspath . 'wp-load.php';

/*
 * There is no REMOTE_ADDR in a CLI process, so the rate limiter has nothing to
 * key on and steps aside by itself. The mailer does need stopping: wp_mail()
 * has nowhere to deliver inside a container and would add seconds of timeout
 * to a test measuring a race.
 */
add_filter( 'qevm_attendee_email', '__return_empty_array' );
add_filter( 'qevm_organizer_email', '__return_empty_array' );

// Everything above is setup; from here the processes must move together.
$qevm_wait = $qevm_start - microtime( true );

if ( $qevm_wait > 0 ) {
	usleep( (int) ( $qevm_wait * 1000000 ) );
}

$qevm_result = ( new QuickEventsManager\Registration\RegistrationService() )->create(
	$qevm_event,
	array(
		'name'           => 'Racer ' . $qevm_email,
		'email'          => $qevm_email,
		'quantity'       => 1,
		'consent'        => true,
		'ticket_type_id' => $qevm_ticket,
	)
);

if ( is_wp_error( $qevm_result ) ) {
	echo 'error:' . $qevm_result->get_error_code() . "\n";

	exit( 0 );
}

echo $qevm_result->status_value() . "\n";

exit( 0 );
