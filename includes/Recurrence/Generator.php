<?php
/**
 * Turning a rule into dates.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Domain\Frequency;
use QuickEventsManager\Domain\OccurrenceStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Expands a recurrence rule into occurrence rows.
 *
 * **Generation happens in the event's own timezone, in wall-clock time.** An
 * 18:00 Tuesday meeting is 18:00 every Tuesday, and its UTC value moves by an
 * hour across a daylight saving boundary. Generating in UTC instead would hold
 * the UTC value still and move the meeting to 17:00 or 19:00 for half the year —
 * the bug that makes an event plugin feel broken to everybody outside a
 * fixed-offset zone.
 *
 * **Bounded three ways**, whichever comes first: the rule's own `UNTIL` or
 * `COUNT`, a horizon of two years from now, and a hard ceiling of
 * `Rule::MAX_OCCURRENCES` rows. The horizon and the ceiling meet by design — a
 * daily rule reaches roughly 730 dates in two years — rather than one being a
 * second guess at the other.
 *
 * Dates before the horizon are generated as well as after it. A series that
 * started last year keeps every date it has already held, because those dates
 * have registrations and an attendee list attached and a plugin that forgets
 * them the following January is a plugin that has lost the records.
 *
 * @since 26.0
 */
final class Generator {

	/**
	 * How far ahead to generate, in months.
	 */
	const HORIZON_MONTHS = 24;

	/**
	 * The most candidate dates to consider before giving up.
	 *
	 * A safety net, not a limit anybody should reach. A monthly rule asking for
	 * the 31st skips seven months a year, so candidates outnumber results; a rule
	 * that matches nothing at all would otherwise loop until the request died.
	 */
	const MAX_CANDIDATES = 20000;

	/**
	 * Expand a rule into occurrence rows.
	 *
	 * @since 26.0
	 *
	 * @param Rule                 $rule    The rule.
	 * @param array<string, mixed> $event   Template: start_local, end_local, timezone, all_day, event_id, series_uuid.
	 * @param string[]             $exclude Local dates to skip, `Y-m-d`.
	 * @param string               $now_utc Reference point for the horizon; defaults to now.
	 * @return array<int, array<string, mixed>>
	 */
	public static function expand( Rule $rule, array $event, array $exclude = array(), string $now_utc = '' ): array {
		$timezone    = (string) ( $event['timezone'] ?? 'UTC' );
		$start_local = (string) ( $event['start_local'] ?? '' );

		if ( '' === $start_local ) {
			return array();
		}

		try {
			$zone = new \DateTimeZone( '' !== $timezone ? $timezone : 'UTC' );
		} catch ( \Exception $e ) {
			return array();
		}

		$first = LocalTime::resolve( $start_local, $zone );

		if ( null === $first ) {
			return array();
		}

		$duration = self::duration( $event, $zone, $first );
		$horizon  = self::horizon( $now_utc );
		$until    = $rule->until();
		$wanted   = $rule->count();
		$rows     = array();
		$time     = substr( $start_local, 11 );

		foreach ( self::candidates( $rule, $first, $zone ) as $candidate ) {
			$local_date = $candidate->format( 'Y-m-d' );
			$moment     = LocalTime::resolve( $local_date . ' ' . $time, $zone );

			if ( null === $moment ) {
				continue;
			}

			$start_utc = $moment->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

			/*
			 * UNTIL is compared in UTC because that is what the rule stores, and
			 * COUNT is compared against rows kept rather than candidates tried —
			 * an excluded date does not use up one of the ten times somebody
			 * asked for.
			 */
			if ( '' !== $until && $start_utc > $until ) {
				break;
			}

			if ( $start_utc > $horizon ) {
				break;
			}

			if ( Exclusions::excludes( $moment->format( 'Y-m-d H:i:s' ), $exclude ) ) {
				continue;
			}

			$rows[] = self::row( $event, $moment, $duration, $zone );

			if ( $wanted > 0 && count( $rows ) >= $wanted ) {
				break;
			}

			if ( count( $rows ) >= Rule::MAX_OCCURRENCES ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * One occurrence row.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $event    Template.
	 * @param \DateTimeImmutable   $moment   When it starts, in the event's zone.
	 * @param int                  $duration Seconds it lasts.
	 * @param \DateTimeZone        $zone     Event zone.
	 * @return array<string, mixed>
	 */
	private static function row( array $event, \DateTimeImmutable $moment, int $duration, \DateTimeZone $zone ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$ends  = $moment->setTimestamp( $moment->getTimestamp() + $duration );
		$start = $moment->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		return array(
			'event_id'      => (int) ( $event['event_id'] ?? 0 ),

			/*
			 * series_uuid groups this row with the other half of a split series;
			 * recurrence_id says which date in the series it is. The two are not
			 * the same question, and only the second survives the row being
			 * moved. See docs/adr/0015-recurrence-identity-and-overrides.md.
			 */
			'series_uuid'   => (string) ( $event['series_uuid'] ?? '' ),
			'recurrence_id' => $start,
			'start_utc'     => $start,
			'end_utc'       => $ends->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'start_local'   => $moment->format( 'Y-m-d H:i:s' ),
			'end_local'     => $ends->setTimezone( $zone )->format( 'Y-m-d H:i:s' ),
			'timezone'      => $zone->getName(),
			'all_day'       => ! empty( $event['all_day'] ) ? 1 : 0,
			'is_exception'  => 0,
			'status'        => OccurrenceStatus::Scheduled->value,
		);
	}

	/**
	 * How long the event lasts, in seconds.
	 *
	 * Elapsed seconds rather than wall-clock difference, so an event that spans a
	 * clock change still runs for the hour it says it does. A 23:30 event lasting
	 * an hour ends at 01:30 on the night the clocks go back, which is an hour
	 * later — and is the hour the room was actually booked for.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $event Template.
	 * @param \DateTimeZone        $zone  Event zone.
	 * @param \DateTimeImmutable   $first The first occurrence.
	 * @return int
	 */
	private static function duration( array $event, \DateTimeZone $zone, \DateTimeImmutable $first ): int {
		$end_local = (string) ( $event['end_local'] ?? '' );

		if ( '' === $end_local ) {
			return 0;
		}

		$ends = LocalTime::resolve( $end_local, $zone );

		if ( null === $ends ) {
			return 0;
		}

		return max( 0, $ends->getTimestamp() - $first->getTimestamp() );
	}

	/**
	 * The last instant generation may reach.
	 *
	 * @since 26.0
	 *
	 * @param string $now_utc Reference point, or '' for now.
	 * @return string `Y-m-d H:i:s` UTC.
	 */
	public static function horizon( string $now_utc = '' ): string {
		$now = '' !== $now_utc ? strtotime( $now_utc . ' UTC' ) : time();

		if ( false === $now ) {
			$now = time();
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::HORIZON_MONTHS . ' months', $now ) );
	}

	/**
	 * Candidate dates, in order, without regard to exclusions or bounds.
	 *
	 * A generator rather than an array, so a rule that runs to the horizon costs
	 * one date at a time rather than building the whole list before the caller
	 * has decided how much of it to keep.
	 *
	 * @since 26.0
	 *
	 * @param Rule               $rule  The rule.
	 * @param \DateTimeImmutable $first The first occurrence.
	 * @param \DateTimeZone      $zone  Event zone.
	 * @return \Generator<int, \DateTimeImmutable>
	 */
	private static function candidates( Rule $rule, \DateTimeImmutable $first, \DateTimeZone $zone ): \Generator {
		unset( $zone );

		$interval = $rule->interval();
		$seen     = 0;

		switch ( $rule->frequency() ) {
			case Frequency::Daily:
				$cursor = $first;

				while ( $seen++ < self::MAX_CANDIDATES ) {
					yield $cursor;

					$cursor = $cursor->modify( '+' . $interval . ' days' );
				}

				return;

			case Frequency::Weekly:
				yield from self::weekly( $rule, $first );

				return;

			case Frequency::Monthly:
				yield from self::monthly( $rule, $first );

				return;

			case Frequency::Yearly:
				yield from self::yearly( $rule, $first );

				return;
		}
	}

	/**
	 * Weekly candidates.
	 *
	 * With no BYDAY the rule repeats on the start date's own weekday. With one it
	 * emits each named day in each qualifying week, and the week is anchored to
	 * Monday so that "every 2 weeks on Monday and Thursday" means the same pair
	 * of weeks however the site displays a calendar.
	 *
	 * @since 26.0
	 *
	 * @param Rule               $rule  The rule.
	 * @param \DateTimeImmutable $first The first occurrence.
	 * @return \Generator<int, \DateTimeImmutable>
	 */
	private static function weekly( Rule $rule, \DateTimeImmutable $first ): \Generator {
		$interval = $rule->interval();
		$days     = $rule->byday();
		$seen     = 0;

		if ( array() === $days ) {
			$cursor = $first;

			while ( $seen++ < self::MAX_CANDIDATES ) {
				yield $cursor;

				$cursor = $cursor->modify( '+' . $interval . ' weeks' );
			}

			return;
		}

		$offsets = array();

		foreach ( $days as $day ) {
			$offsets[] = $day->weekday()->to_php_n() - 1;
		}

		sort( $offsets );

		// Monday of the week the series starts in.
		$week = $first->modify( '-' . ( (int) $first->format( 'N' ) - 1 ) . ' days' );

		while ( $seen < self::MAX_CANDIDATES ) {
			foreach ( $offsets as $offset ) {
				++$seen;

				$candidate = $week->modify( '+' . $offset . ' days' );

				/*
				 * A named weekday earlier in the first week than the start date
				 * is not an occurrence — the series begins when it begins, and
				 * "every Monday and Thursday from Wednesday the 3rd" must not
				 * produce the Monday before it.
				 */
				if ( $candidate->format( 'Y-m-d' ) < $first->format( 'Y-m-d' ) ) {
					continue;
				}

				yield $candidate;
			}

			$week = $week->modify( '+' . $interval . ' weeks' );
		}
	}

	/**
	 * Monthly candidates.
	 *
	 * Stepping is anchored to the first of the month, never to the start date.
	 * `'+1 month'` from the 31st of January lands on the 3rd of March, and a
	 * monthly series that silently walks forward through the calendar is the
	 * classic way this goes wrong.
	 *
	 * @since 26.0
	 *
	 * @param Rule               $rule  The rule.
	 * @param \DateTimeImmutable $first The first occurrence.
	 * @return \Generator<int, \DateTimeImmutable>
	 */
	private static function monthly( Rule $rule, \DateTimeImmutable $first ): \Generator {
		$interval = $rule->interval();
		$month    = $first->modify( 'first day of this month' );
		$seen     = 0;

		while ( $seen < self::MAX_CANDIDATES ) {
			foreach ( self::days_in_month( $rule, $month, (int) $first->format( 'j' ) ) as $candidate ) {
				++$seen;

				if ( $candidate->format( 'Y-m-d' ) < $first->format( 'Y-m-d' ) ) {
					continue;
				}

				yield $candidate;
			}

			$month = $month->modify( '+' . $interval . ' months' );
		}
	}

	/**
	 * Yearly candidates.
	 *
	 * @since 26.0
	 *
	 * @param Rule               $rule  The rule.
	 * @param \DateTimeImmutable $first The first occurrence.
	 * @return \Generator<int, \DateTimeImmutable>
	 */
	private static function yearly( Rule $rule, \DateTimeImmutable $first ): \Generator {
		$interval = $rule->interval();
		$months   = $rule->months();
		$months   = array() !== $months ? $months : array( (int) $first->format( 'n' ) );
		$year     = $first->modify( 'first day of January this year' );
		$seen     = 0;

		sort( $months );

		while ( $seen < self::MAX_CANDIDATES ) {
			foreach ( $months as $month_number ) {
				$month = $year->setDate( (int) $year->format( 'Y' ), (int) $month_number, 1 );

				foreach ( self::days_in_month( $rule, $month, (int) $first->format( 'j' ) ) as $candidate ) {
					++$seen;

					if ( $candidate->format( 'Y-m-d' ) < $first->format( 'Y-m-d' ) ) {
						continue;
					}

					yield $candidate;
				}
			}

			$year = $year->modify( '+' . $interval . ' years' );
		}
	}

	/**
	 * The dates one month contributes.
	 *
	 * @since 26.0
	 *
	 * @param Rule               $rule     The rule.
	 * @param \DateTimeImmutable $month    The first of the month.
	 * @param int                $fallback Day of the month to use when the rule names none.
	 * @return \DateTimeImmutable[]
	 */
	private static function days_in_month( Rule $rule, \DateTimeImmutable $month, int $fallback ): array {
		$year   = (int) $month->format( 'Y' );
		$number = (int) $month->format( 'n' );
		$length = (int) $month->format( 't' );
		$dates  = array();

		if ( array() !== $rule->byday() ) {
			foreach ( $rule->byday() as $day ) {
				$found = self::weekday_in_month( $month, $day, $length );

				if ( null !== $found ) {
					$dates[ $found->format( 'Y-m-d' ) ] = $found;
				}
			}
		} else {
			$wanted = $rule->monthdays();
			$wanted = array() !== $wanted ? $wanted : array( $fallback );

			foreach ( $wanted as $day_number ) {
				$day_number = (int) $day_number;
				$day        = $day_number < 0 ? $length + $day_number + 1 : $day_number;

				/*
				 * A month that is too short simply does not contribute. RFC 5545
				 * skips invalid dates rather than clamping them, and it is right
				 * to: "the 31st of every month" produced on the 28th of February
				 * is a date nobody asked for, appearing three days early, seven
				 * times a year.
				 */
				if ( $day < 1 || $day > $length ) {
					continue;
				}

				$dates[ sprintf( '%04d-%02d-%02d', $year, $number, $day ) ] = $month->setDate( $year, $number, $day );
			}
		}

		ksort( $dates );

		return array_values( $dates );
	}

	/**
	 * The nth given weekday of a month, or null if the month has no such day.
	 *
	 * @since 26.0
	 *
	 * @param \DateTimeImmutable $month  The first of the month.
	 * @param Byday              $day    Weekday, with or without a position.
	 * @param int                $length Days in the month.
	 * @return \DateTimeImmutable|null
	 */
	private static function weekday_in_month( \DateTimeImmutable $month, Byday $day, int $length ): ?\DateTimeImmutable {
		$target = $day->weekday()->to_php_n();

		$matching = array();

		for ( $number = 1; $number <= $length; $number++ ) {
			$date = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $number );

			if ( (int) $date->format( 'N' ) === $target ) {
				$matching[] = $date;
			}
		}

		if ( array() === $matching ) {
			return null;
		}

		$position = $day->position();

		if ( 0 === $position ) {
			/*
			 * A weekday with no position inside a monthly rule means the first
			 * one. "Every month on Tuesday" is not a rule with an answer
			 * otherwise, and the alternative — emitting all four or five — turns
			 * a monthly series into a weekly one.
			 */
			return $matching[0];
		}

		$index = $position > 0 ? $position - 1 : count( $matching ) + $position;

		return $matching[ $index ] ?? null;
	}
}
