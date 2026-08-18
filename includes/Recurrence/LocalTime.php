<?php
/**
 * Turning a wall-clock time into an instant, including the two that are not one.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a local date and time in a timezone to a single instant.
 *
 * Twice a year, in most of the world, a wall-clock time is not a moment.
 *
 * - **It may not exist.** When clocks go forward, 02:30 is skipped entirely.
 * - **It may happen twice.** When clocks go back, 01:30 comes round again an
 *   hour later.
 *
 * PHP answers both without complaint, and one of its two answers is not the one
 * this plugin wants:
 *
 * | Case | PHP | Here |
 * | --- | --- | --- |
 * | 02:30 on a spring-forward night | 03:30 — the requested time plus the shift | **03:00** — the instant the clock jumped to |
 * | 01:30 on a fall-back night | the first, before the change | the first, before the change |
 *
 * The second agrees, so it is inherited rather than reimplemented — and pinned
 * by a test, because inheriting behaviour means noticing if it ever changes.
 *
 * The first does not, so it is implemented here. Both answers are defensible and
 * neither is obviously right; what matters is that the choice is deterministic,
 * written down, and not whatever PHP happened to do. "As soon as it becomes
 * possible" is a rule that can be stated in one sentence to somebody asking why
 * their 02:30 event moved.
 *
 * Ambiguity resolves **earlier** rather than later for a reason worth keeping:
 * attendees arriving to a room that is still open is a better failure than
 * arriving to one that has closed.
 *
 * @since 26.0
 */
final class LocalTime {

	/**
	 * Resolve a wall-clock time to the instant it means.
	 *
	 * @since 26.0
	 *
	 * @param string        $local Local time, `Y-m-d H:i:s`.
	 * @param \DateTimeZone $zone  Zone it is written in.
	 * @return \DateTimeImmutable|null Null when the string is unreadable.
	 */
	public static function resolve( string $local, \DateTimeZone $zone ): ?\DateTimeImmutable {
		$local = trim( $local );

		try {
			$moment = new \DateTimeImmutable( $local, $zone );
		} catch ( \Exception $e ) {
			return null;
		}

		/*
		 * A wall-clock time that reads back as itself is a time that exists —
		 * whether it exists once or twice. PHP resolves the twice case to the
		 * first of the two, which is what is wanted, so there is nothing to do.
		 */
		if ( $moment->format( 'Y-m-d H:i:s' ) === $local ) {
			return $moment;
		}

		$transition = self::transition_before( $moment, $zone );

		return null !== $transition ? $transition : $moment;
	}

	/**
	 * Whether a wall-clock time exists at all in a zone.
	 *
	 * @since 26.0
	 *
	 * @param string        $local Local time, `Y-m-d H:i:s`.
	 * @param \DateTimeZone $zone  Zone.
	 * @return bool
	 */
	public static function exists( string $local, \DateTimeZone $zone ): bool {
		$local = trim( $local );

		try {
			$moment = new \DateTimeImmutable( $local, $zone );
		} catch ( \Exception $e ) {
			return false;
		}

		return $moment->format( 'Y-m-d H:i:s' ) === $local;
	}

	/**
	 * Whether a wall-clock time happens twice in a zone.
	 *
	 * Not used by generation, which takes the first either way, but a UI that
	 * wants to say "this time happens twice that night" needs to be able to ask.
	 *
	 * @since 26.0
	 *
	 * @param string        $local Local time, `Y-m-d H:i:s`.
	 * @param \DateTimeZone $zone  Zone.
	 * @return bool
	 */
	public static function is_ambiguous( string $local, \DateTimeZone $zone ): bool {
		$first = self::resolve( $local, $zone );

		if ( null === $first ) {
			return false;
		}

		/*
		 * An hour later in elapsed time. If it still reads as the same wall-clock
		 * time, the clock has repeated itself and both instants are called this.
		 */
		$later = $first->setTimestamp( $first->getTimestamp() + HOUR_IN_SECONDS );

		return $later->format( 'Y-m-d H:i:s' ) === $first->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The instant a zone last changed offset, at or before a given moment.
	 *
	 * Used only for a wall-clock time inside a spring-forward gap: PHP has
	 * already shifted it past the gap, and the transition immediately before that
	 * shifted value is the moment the clock jumped to.
	 *
	 * @since 26.0
	 *
	 * @param \DateTimeImmutable $moment Shifted moment.
	 * @param \DateTimeZone      $zone   Zone.
	 * @return \DateTimeImmutable|null
	 */
	private static function transition_before( \DateTimeImmutable $moment, \DateTimeZone $zone ): ?\DateTimeImmutable {
		$timestamp = $moment->getTimestamp();

		/*
		 * Two days either side. A gap is at most a few hours, so the transition
		 * is certainly inside this window, and a narrow window keeps the list
		 * short — getTransitions() over a wide range builds every transition in
		 * it, which for a zone with a long history is thousands of entries.
		 */
		$transitions = $zone->getTransitions( $timestamp - ( 2 * DAY_IN_SECONDS ), $timestamp + DAY_IN_SECONDS );

		/*
		 * Checked for emptiness rather than for type. getTransitions() is
		 * documented as returning `array|false`, and a zone with no transitions in
		 * the window returns an empty array — so one test covers both the
		 * documented failure and the ordinary "nothing here" answer, and neither
		 * leaves the loop below iterating something that is not a list.
		 */
		if ( empty( $transitions ) ) {
			return null;
		}

		$found = null;

		foreach ( $transitions as $transition ) {
			$ts = (int) $transition['ts'];

			if ( $ts <= $timestamp && ( null === $found || $ts > $found ) ) {
				$found = $ts;
			}
		}

		if ( null === $found ) {
			return null;
		}

		return $moment->setTimestamp( $found );
	}
}
