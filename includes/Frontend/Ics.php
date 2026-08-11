<?php
/**
 * Add to calendar: .ics download and calendar service links.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Frontend;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Produces an iCalendar file for an event, plus Google and Outlook links.
 *
 * No library: RFC 5545 for one VEVENT is a short, well-specified format, and a
 * dependency would have to be vendored into the wordpress.org package.
 *
 * @since 26.0
 */
final class Ics {

	/**
	 * Query variable that triggers the download.
	 */
	const QUERY_VAR = 'qevm_ics';

	/**
	 * Hook into request handling.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_download' ) );
	}

	/**
	 * Register the query variable.
	 *
	 * @since 26.0
	 *
	 * @param array $vars Public query variables.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Nothing to rewrite: the download hangs off a query argument.
	 *
	 * Kept as a hook so a pretty URL can be added later without moving
	 * the rest of the wiring.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_rewrite() {}

	/**
	 * The URL that downloads an event's .ics file.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	public static function url( $event_id ) {
		return add_query_arg( self::QUERY_VAR, '1', get_permalink( $event_id ) );
	}

	/**
	 * Serve the file when the query variable is present.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function maybe_download() {
		if ( ! is_singular( QEVM_POST_TYPE ) || ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$event = new Event( get_queried_object_id() );

		if ( ! $event->is_valid() || '' === $event->start_utc() ) {
			return;
		}

		$slug = get_post_field( 'post_name', $event->id() );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $slug . '.ics' ) . '"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML; every value is escaped for iCalendar by escape_text().
		echo self::build( $event );

		exit;
	}

	/**
	 * Build the iCalendar document for an event.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return string
	 */
	public static function build( Event $event ) {
		$all_day = $event->is_all_day();

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Quick Events Manager//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . self::uid( $event ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			self::datetime_line( 'DTSTART', $event->start_utc(), $event->timezone(), $all_day ),
			'SUMMARY:' . self::escape_text( wp_strip_all_tags( get_the_title( $event->id() ) ) ),
			'URL:' . self::escape_text( get_permalink( $event->id() ) ),
		);

		$end = '' !== $event->end_utc() ? $event->end_utc() : '';

		if ( '' !== $end ) {
			$lines[] = self::datetime_line( 'DTEND', $end, $event->timezone(), $all_day );
		}

		$description = wp_strip_all_tags( get_the_excerpt( $event->id() ) );

		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . self::escape_text( $description );
		}

		$location = $event->is_online()
			? ( '' !== $event->online_url() ? $event->online_url() : __( 'Online', 'quick-events-manager' ) )
			: $event->venue_summary();

		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::escape_text( $location );
		}

		$organizer = (string) $event->meta( Meta::ORGANIZER_EMAIL );

		if ( '' !== $organizer && is_email( $organizer ) ) {
			$lines[] = 'ORGANIZER:mailto:' . $organizer;
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * A stable unique identifier for the event.
	 *
	 * Calendar clients use the UID to recognise an update to an event they
	 * already hold, so it must not change between downloads.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return string
	 */
	private static function uid( Event $event ) {
		return sprintf( 'qevm-%d@%s', $event->id(), wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Build a DTSTART or DTEND line.
	 *
	 * All-day events use a VALUE=DATE line with no time, which is what stops a
	 * calendar client showing them as a midnight-to-midnight appointment.
	 * Timed events are written as UTC instants, which every client converts to
	 * the viewer's own zone without needing a VTIMEZONE block.
	 *
	 * @since 26.0
	 *
	 * @param string $property DTSTART or DTEND.
	 * @param string $utc      Stored UTC datetime.
	 * @param string $timezone Event timezone.
	 * @param bool   $all_day  Whether the event is all day.
	 * @return string
	 */
	private static function datetime_line( $property, $utc, $timezone, $all_day ) {
		try {
			$date = new \DateTime( $utc, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $property . ':';
		}

		if ( ! $all_day ) {
			return $property . ':' . $date->format( 'Ymd\THis\Z' );
		}

		$date->setTimezone( new \DateTimeZone( $timezone ) );

		return $property . ';VALUE=DATE:' . $date->format( 'Ymd' );
	}

	/**
	 * Escape a value for an iCalendar text field.
	 *
	 * Backslash, semicolon, comma and newline are all structural in RFC 5545,
	 * so an event whose title contains a comma corrupts the file otherwise.
	 *
	 * @since 26.0
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function escape_text( $value ) {
		$value = str_replace( '\\', '\\\\', (string) $value );
		$value = str_replace( array( ';', ',' ), array( '\;', '\,' ), $value );
		$value = str_replace( array( "\r\n", "\n", "\r" ), '\n', $value );

		return $value;
	}

	/**
	 * Fold a line to 75 octets, as the specification requires.
	 *
	 * Split on bytes rather than characters, then rejoined with CRLF and a
	 * leading space. Multi-byte characters can be split across a fold — that is
	 * legal, because a client unfolds before decoding UTF-8.
	 *
	 * @since 26.0
	 *
	 * @param string $line Line to fold.
	 * @return string
	 */
	public static function fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$folded = substr( $line, 0, 75 );
		$rest   = substr( $line, 75 );

		foreach ( str_split( $rest, 74 ) as $chunk ) {
			$folded .= "\r\n " . $chunk;
		}

		return $folded;
	}

	/**
	 * A Google Calendar "add event" link.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return string
	 */
	public static function google_url( Event $event ) {
		if ( '' === $event->start_utc() ) {
			return '';
		}

		$end = '' !== $event->end_utc() ? $event->end_utc() : $event->start_utc();

		try {
			$start_date = new \DateTime( $event->start_utc(), new \DateTimeZone( 'UTC' ) );
			$end_date   = new \DateTime( $end, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return '';
		}

		$format = $event->is_all_day() ? 'Ymd' : 'Ymd\THis\Z';

		return add_query_arg(
			rawurlencode_deep(
				array(
					'action'   => 'TEMPLATE',
					'text'     => wp_strip_all_tags( get_the_title( $event->id() ) ),
					'dates'    => $start_date->format( $format ) . '/' . $end_date->format( $format ),
					'details'  => wp_strip_all_tags( get_the_excerpt( $event->id() ) ),
					'location' => $event->is_online() ? $event->online_url() : $event->venue_summary(),
				)
			),
			'https://calendar.google.com/calendar/render'
		);
	}
}
