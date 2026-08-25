<?php
/**
 * One month, as weeks of days with the events on them.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Calendar;

use QuickEventsManager\Events\Occurrence;
use QuickEventsManager\Events\OccurrenceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The shape a month grid needs, worked out once and away from any markup.
 *
 * Every date decision lives here rather than in the template, because a
 * template that computes dates is a template nobody can test and every theme
 * override has to reimplement.
 *
 * **Days are the organiser's days.** An occurrence belongs to the date its own
 * `start_local` names, not to a date derived by converting its UTC instant into
 * the visitor's zone. An event at 23:00 in Kolkata is not on the previous day
 * because somebody is reading from London — the grid would be lying to both of
 * them. This is the same principle as the rest of the plugin, where UTC is what
 * is sorted on and local is what is shown.
 *
 * An event spanning several days appears on each of them. It is one event on
 * three days, not three events.
 *
 * @since 26.0
 */
final class Month {

	/**
	 * Year.
	 *
	 * @var int
	 */
	private $year;

	/**
	 * Month, 1–12.
	 *
	 * @var int
	 */
	private $month;

	/**
	 * Occurrences by `Y-m-d`.
	 *
	 * @var array<string, Occurrence[]>|null
	 */
	private $days = null;

	/**
	 * Build a month.
	 *
	 * @since 26.0
	 *
	 * @param int $year  Four-digit year.
	 * @param int $month Month, 1–12.
	 */
	public function __construct( $year, $month ) {
		$month = (int) $month;
		$year  = (int) $year;

		/*
		 * Normalised rather than rejected. Month 13 is what "next" produces in
		 * December, and answering it with January of the following year is more
		 * useful than an error somebody has to handle at every call site.
		 */
		$this->year  = $year + intdiv( $month - 1, 12 );
		$this->month = ( ( $month - 1 ) % 12 + 12 ) % 12 + 1;

		if ( $month < 1 ) {
			--$this->year;
		}
	}

	/**
	 * The month containing a `Y-m` string, or this month if it is not one.
	 *
	 * @since 26.0
	 *
	 * @param string $value Requested month.
	 * @return self
	 */
	public static function from_string( $value ) {
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})$/', (string) $value, $parts ) ) {
			$month = (int) $parts[2];

			if ( $month >= 1 && $month <= 12 ) {
				return new self( (int) $parts[1], $month );
			}
		}

		return self::current();
	}

	/**
	 * The month the site is in now.
	 *
	 * @since 26.0
	 *
	 * @return self
	 */
	public static function current() {
		$now = current_datetime();

		return new self( (int) $now->format( 'Y' ), (int) $now->format( 'n' ) );
	}

	/**
	 * Year.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function year() {
		return $this->year;
	}

	/**
	 * Month, 1–12.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function month() {
		return $this->month;
	}

	/**
	 * `Y-m`, for URLs.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function key() {
		return sprintf( '%04d-%02d', $this->year, $this->month );
	}

	/**
	 * The month before this one.
	 *
	 * @since 26.0
	 *
	 * @return self
	 */
	public function previous() {
		return new self( $this->year, $this->month - 1 );
	}

	/**
	 * The month after this one.
	 *
	 * @since 26.0
	 *
	 * @return self
	 */
	public function next() {
		return new self( $this->year, $this->month + 1 );
	}

	/**
	 * The month's name and year, as the site writes dates.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function label() {
		return wp_date( 'F Y', $this->first_day()->getTimestamp() );
	}

	/**
	 * First day of the month.
	 *
	 * @since 26.0
	 *
	 * @return \DateTimeImmutable
	 */
	public function first_day() {
		return new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $this->year, $this->month ), wp_timezone() );
	}

	/**
	 * How many days the month has.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function length() {
		return (int) $this->first_day()->format( 't' );
	}

	/**
	 * The weekday names, starting on the day the site starts its week.
	 *
	 * @since 26.0
	 *
	 * @return array<int, array{short: string, full: string}>
	 */
	public static function weekdays() {
		global $wp_locale;

		$start = (int) get_option( 'start_of_week', 1 );
		$names = array();

		for ( $offset = 0; $offset < 7; $offset++ ) {
			$index = ( $start + $offset ) % 7;

			$names[] = array(
				'short' => $wp_locale instanceof \WP_Locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $index ) ) : '',
				'full'  => $wp_locale instanceof \WP_Locale ? $wp_locale->get_weekday( $index ) : '',
			);
		}

		return $names;
	}

	/**
	 * The grid, as rows of seven cells.
	 *
	 * Cells before the first and after the last of the month are `null`. They
	 * are rendered empty rather than filled with the neighbouring month's
	 * dates: a screen reader reading "31" in the row above the 1st, with no way
	 * to know it belongs to a different month, is worse than reading nothing.
	 *
	 * @since 26.0
	 *
	 * @return array<int, array<int, array{date: string, day: int, events: Occurrence[], is_today: bool}|null>>
	 */
	public function weeks() {
		$length   = $this->length();
		$start    = (int) get_option( 'start_of_week', 1 );
		$first    = (int) $this->first_day()->format( 'w' );
		$leading  = ( $first - $start + 7 ) % 7;
		$today    = wp_date( 'Y-m-d' );
		$occurred = $this->occurrences_by_day();

		$cells = array_fill( 0, $leading, null );

		for ( $day = 1; $day <= $length; $day++ ) {
			$date = sprintf( '%04d-%02d-%02d', $this->year, $this->month, $day );

			$cells[] = array(
				'date'     => $date,
				'day'      => $day,
				'events'   => isset( $occurred[ $date ] ) ? $occurred[ $date ] : array(),
				'is_today' => $date === $today,
			);
		}

		$trailing = ( 7 - count( $cells ) % 7 ) % 7;

		for ( $pad = 0; $pad < $trailing; $pad++ ) {
			$cells[] = null;
		}

		return array_chunk( $cells, 7 );
	}

	/**
	 * How many events fall in this month.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function event_count() {
		$total = 0;

		foreach ( $this->occurrences_by_day() as $events ) {
			$total += count( $events );
		}

		return $total;
	}

	/**
	 * Every occurrence in the month, bucketed by the date it falls on.
	 *
	 * One query for the whole month, then bucketed in PHP. A query per day
	 * would be thirty-one round trips to draw one grid.
	 *
	 * @since 26.0
	 *
	 * @return array<string, Occurrence[]>
	 */
	public function occurrences_by_day() {
		if ( null !== $this->days ) {
			return $this->days;
		}

		$first = $this->first_day()->format( 'Y-m-d' );
		$last  = sprintf( '%04d-%02d-%02d', $this->year, $this->month, $this->length() );

		$this->days = array();

		$events = array();

		foreach ( OccurrenceRepository::for_local_range( $first, $last ) as $occurrence ) {
			$events[] = $occurrence->event_id();

			foreach ( self::dates_covered( $occurrence, $first, $last ) as $date ) {
				$this->days[ $date ][] = $occurrence;
			}
		}

		/*
		 * Every event in the month, fetched together.
		 *
		 * The grid builds an `Event` for each occupied day, which reads a post
		 * and its meta. One at a time that is two queries per event, and a busy
		 * month is two hundred events: the performance benchmark measured 471
		 * queries and 70ms for a single month view, which is the classic N+1 and
		 * invisible on the ten-event site anybody develops against.
		 *
		 * Priming here rather than in the template because the template is
		 * overridable — a theme that copies it must not have to know this, and a
		 * theme that has already copied the old one gets the fix anyway.
		 */
		if ( array() !== $events ) {
			_prime_post_caches( array_values( array_unique( $events ) ), false, true );
		}

		return $this->days;
	}

	/**
	 * Every date within the month that one occurrence runs on.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @param string     $first      First date of the month, `Y-m-d`.
	 * @param string     $last       Last date of the month, `Y-m-d`.
	 * @return string[]
	 */
	private static function dates_covered( Occurrence $occurrence, $first, $last ) {
		$starts = substr( $occurrence->start_local(), 0, 10 );
		$ends   = '' !== $occurrence->end_local() ? substr( $occurrence->end_local(), 0, 10 ) : $starts;

		if ( '' === $starts ) {
			return array();
		}

		$from = max( $starts, $first );
		$to   = min( $ends >= $starts ? $ends : $starts, $last );

		if ( $from > $to ) {
			return array();
		}

		$dates   = array();
		$cursor  = new \DateTimeImmutable( $from . ' 00:00:00', wp_timezone() );
		$stop    = new \DateTimeImmutable( $to . ' 00:00:00', wp_timezone() );
		$one_day = new \DateInterval( 'P1D' );

		/*
		 * A hard stop as well as the date comparison. A malformed end date that
		 * somehow sorts after the start but never reaches it would otherwise
		 * spin here for ever, and this runs on a public page.
		 */
		for ( $guard = 0; $guard < 400 && $cursor <= $stop; $guard++ ) {
			$dates[] = $cursor->format( 'Y-m-d' );
			$cursor  = $cursor->add( $one_day );
		}

		return $dates;
	}
}
