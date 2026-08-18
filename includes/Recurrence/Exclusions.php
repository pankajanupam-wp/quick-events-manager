<?php
/**
 * Dates a rule generates that the series does not hold.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * The list of dates to skip.
 *
 * Stored as local calendar dates rather than instants, because that is what
 * somebody means: "not the 25th of December", not "not 18:00 UTC on the 25th of
 * December". Matching on the local date also means an exclusion keeps working
 * when the event's time changes, which is the behaviour anyone would assume.
 *
 * **Excluding is not cancelling.** An excluded date never happens and has no row.
 * A cancelled date exists and is called off, keeps its registrations, and shows
 * as cancelled to anybody who already had it in their diary. Excluding a date
 * that has registrations is refused for that reason — see docs/recurrence.md
 * §5.3 — and the caller is offered cancellation instead.
 *
 * @since 26.0
 */
final class Exclusions {

	/**
	 * The most dates one series may exclude.
	 *
	 * Generous, and finite. A list this long is somebody using exclusions to
	 * express a rule they should have written as a rule.
	 */
	const MAX = 200;

	/**
	 * One event's excluded dates.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return string[] Local dates, `Y-m-d`, sorted and unique.
	 */
	public static function for_event( int $event_id ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		return self::parse( (string) get_post_meta( $event_id, Meta::RECURRENCE_EXCLUSIONS, true ) );
	}

	/**
	 * Read a stored list.
	 *
	 * @since 26.0
	 *
	 * @param string $stored Comma-separated dates.
	 * @return string[] Local dates, `Y-m-d`, sorted and unique.
	 */
	public static function parse( string $stored ): array {
		$dates = array();

		foreach ( explode( ',', $stored ) as $piece ) {
			$date = self::normalise( $piece );

			if ( '' !== $date ) {
				$dates[ $date ] = $date;
			}
		}

		$dates = array_values( $dates );

		sort( $dates );

		return array_slice( $dates, 0, self::MAX );
	}

	/**
	 * Back to the stored form.
	 *
	 * @since 26.0
	 *
	 * @param string[] $dates Local dates.
	 * @return string
	 */
	public static function to_string( array $dates ): string {
		return implode( ',', self::parse( implode( ',', array_map( 'strval', $dates ) ) ) );
	}

	/**
	 * Read one date, or '' if it is not one.
	 *
	 * Checked against the calendar rather than only against the pattern, so
	 * `2026-02-30` is refused rather than silently becoming the 2nd of March —
	 * which is what `strtotime()` would make of it, and which would exclude a
	 * date the organiser never named.
	 *
	 * @since 26.0
	 *
	 * @param string $value Raw value.
	 * @return string `Y-m-d`, or ''.
	 */
	public static function normalise( string $value ): string {
		$value = trim( $value );

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return '';
		}

		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Whether a local datetime falls on an excluded date.
	 *
	 * @since 26.0
	 *
	 * @param string   $local_datetime Local datetime, `Y-m-d H:i:s`.
	 * @param string[] $exclusions     Excluded dates.
	 * @return bool
	 */
	public static function excludes( string $local_datetime, array $exclusions ): bool {
		if ( array() === $exclusions ) {
			return false;
		}

		return in_array( substr( trim( $local_datetime ), 0, 10 ), $exclusions, true );
	}

	/**
	 * Sanitiser for the registered meta key.
	 *
	 * One parameter; see Meta::sanitize_rrule() for why that matters.
	 *
	 * @since 26.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize( $value ): string {
		if ( is_array( $value ) ) {
			return self::to_string( $value );
		}

		return is_string( $value ) ? implode( ',', self::parse( $value ) ) : '';
	}
}
