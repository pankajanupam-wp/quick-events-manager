<?php
/**
 * Checks one person in, in its own process, at a moment agreed with its siblings.
 *
 * Run by CheckInRaceTest. Each copy boots WordPress and then waits for a shared
 * wall-clock instant before scanning, which is what makes two doors a race
 * rather than two doors in a queue.
 *
 * Usage: php check-in-one.php <abspath> <ticket-code> <start-unix-time>
 *
 * Prints one line: the result, or `error:<code>`.
 *
 * @package QuickEventsManager
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- A CLI fixture writing to a pipe, not a page.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$qevm_abspath = rtrim( (string) ( $argv[1] ?? '' ), '/\\' ) . '/';
$qevm_code    = (string) ( $argv[2] ?? '' );
$qevm_start   = (float) ( $argv[3] ?? 0 );

if ( ! file_exists( $qevm_abspath . 'wp-load.php' ) || '' === $qevm_code ) {
	fwrite( STDERR, "usage: check-in-one.php <abspath> <ticket-code> <start-time>\n" );

	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

define( 'WP_USE_THEMES', false );

require_once $qevm_abspath . 'wp-load.php';

// Everything above is setup; from here the processes must move together.
$qevm_wait = $qevm_start - microtime( true );

if ( $qevm_wait > 0 ) {
	usleep( (int) ( $qevm_wait * 1000000 ) );
}

$qevm_outcome = QuickEventsManager\CheckIn\CheckInService::admit_by_code( $qevm_code, 0, 'qr' );

echo $qevm_outcome['result'] . "\n";

exit( 0 );
