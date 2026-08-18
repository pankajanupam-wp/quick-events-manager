<?php
/**
 * One entry in a rule's BYDAY list.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Domain\Weekday;

defined( 'ABSPATH' ) || exit;

/**
 * A weekday, optionally with a position within the month.
 *
 * RFC 5545 writes both forms in the same list: `TU` is "Tuesday", and `2TU` is
 * "the second Tuesday". Negative positions count back from the end, so `-1FR`
 * is "the last Friday" — which is the form worth having, because "the last
 * Friday of the month" is a real way people schedule things and cannot be
 * expressed as a date.
 *
 * Both forms live in one class rather than two because they arrive in one
 * comma-separated list and a parser that splits them into different types has
 * to decide which it is looking at before it has finished reading it.
 *
 * @since 26.0
 */
final class Byday {

	/**
	 * The furthest position a month can hold.
	 *
	 * Five, not four: a month can contain five Tuesdays, and `5TU` is a rule
	 * that simply produces nothing in most months. RFC 5545 allows 1–53 for
	 * yearly rules; this plugin's monthly and yearly forms never need beyond
	 * five, and allowing 53 would let somebody write a rule that looks accepted
	 * and generates nothing at all.
	 */
	const MAX_POSITION = 5;

	/**
	 * Position in the month, or 0 for "every one of these".
	 *
	 * @var int
	 */
	private $position;

	/**
	 * The weekday.
	 *
	 * @var Weekday
	 */
	private $weekday;

	/**
	 * Build one.
	 *
	 * @since 26.0
	 *
	 * @param Weekday $weekday  The day.
	 * @param int     $position -5..-1, 1..5, or 0 for none.
	 */
	public function __construct( Weekday $weekday, int $position = 0 ) {
		$this->weekday  = $weekday;
		$this->position = $position;
	}

	/**
	 * Read one entry, e.g. `TU`, `2TU`, `-1FR`.
	 *
	 * @since 26.0
	 *
	 * @param string $value One BYDAY entry.
	 * @return self|null Null when it is not one.
	 */
	public static function from_string( string $value ): ?self {
		$value = strtoupper( trim( $value ) );

		if ( 1 !== preg_match( '/^(?<position>[+-]?\d{1,2})?(?<day>[A-Z]{2})$/', $value, $matches ) ) {
			return null;
		}

		$weekday = Weekday::coerce( $matches['day'] );

		if ( null === $weekday ) {
			return null;
		}

		/*
		 * Read as a string first, because "absent" and "zero" have to stay
		 * distinguishable and `(int) ''` is also 0.
		 *
		 * The key is always set: `position` is an optional group followed by a
		 * required one, and PHP only omits unmatched groups that trail the last
		 * match. An unmatched intermediate group arrives as ''.
		 */
		$stated   = $matches['position'];
		$position = '' !== $stated ? (int) $stated : 0;

		/*
		 * `0TU` is not a thing. RFC 5545 has no zeroth Tuesday, and reading it
		 * as "every Tuesday" would silently turn an obviously broken rule into a
		 * working one that says something else.
		 */
		if ( '' !== $stated && 0 === $position ) {
			return null;
		}

		if ( abs( $position ) > self::MAX_POSITION ) {
			return null;
		}

		return new self( $weekday, $position );
	}

	/**
	 * Back to the stored form.
	 *
	 * @since 26.0
	 */
	public function to_string(): string {
		return ( 0 !== $this->position ? (string) $this->position : '' ) . $this->weekday->value;
	}

	/**
	 * The weekday.
	 *
	 * @since 26.0
	 */
	public function weekday(): Weekday {
		return $this->weekday;
	}

	/**
	 * Position in the month, or 0 for every one.
	 *
	 * @since 26.0
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * Whether this entry names a position.
	 *
	 * @since 26.0
	 */
	public function has_position(): bool {
		return 0 !== $this->position;
	}

	/**
	 * Human-readable description, e.g. "the second Tuesday".
	 *
	 * @since 26.0
	 */
	public function describe(): string {
		if ( ! $this->has_position() ) {
			return $this->weekday->label();
		}

		/*
		 * The ordinal is translated as a word on its own and dropped into one
		 * pattern, rather than ten patterns each carrying "%s". Ten near-identical
		 * strings is ten chances for a translator to lose the placeholder, and
		 * whichever one they lose is the one nobody notices until a site shows
		 * "the" followed by nothing.
		 */
		$ordinal = match ( $this->position ) {
			1       => __( 'first', 'quick-events-manager' ),
			2       => __( 'second', 'quick-events-manager' ),
			3       => __( 'third', 'quick-events-manager' ),
			4       => __( 'fourth', 'quick-events-manager' ),
			5       => __( 'fifth', 'quick-events-manager' ),
			-1      => __( 'last', 'quick-events-manager' ),
			-2      => __( 'second-to-last', 'quick-events-manager' ),
			-3      => __( 'third-to-last', 'quick-events-manager' ),
			-4      => __( 'fourth-to-last', 'quick-events-manager' ),
			-5      => __( 'fifth-to-last', 'quick-events-manager' ),
			default => '',
		};

		if ( '' === $ordinal ) {
			return $this->weekday->label();
		}

		return sprintf(
			/* translators: 1: An ordinal such as "second" or "last", 2: A weekday name such as "Tuesday". */
			__( 'the %1$s %2$s', 'quick-events-manager' ),
			$ordinal,
			$this->weekday->label()
		);
	}
}
