<?php
/**
 * A recurrence rule, and what makes one valid.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Domain\Frequency;
use QuickEventsManager\Domain\Weekday;

defined( 'ABSPATH' ) || exit;

/**
 * How a series repeats, stored as an RFC 5545 RRULE.
 *
 * **Stored as an RRULE string rather than a serialised array**, for three
 * reasons that outlive this release. It is legible in the database, so a support
 * question can be answered by looking; it is what an `.ics` export has to emit,
 * so the export becomes a copy rather than a translation; and it is what every
 * other calendar in the world already speaks, so importing a series from
 * somewhere else is parsing rather than mapping.
 *
 * The cost is that a stored value can be malformed in ways a serialised array
 * cannot, which is why parsing returns null rather than a partly-filled object
 * and why validate() exists separately.
 *
 * This class does not generate dates. Expanding a rule into occurrences is C6.3
 * and lives in its own class, because a value object that also runs a date loop
 * is a value object nobody can test cheaply.
 *
 * @since 26.0
 */
final class Rule {

	/**
	 * The most occurrences one series may ever hold.
	 *
	 * Documented in docs/recurrence.md §5.1 as the backstop behind the 24-month
	 * horizon: a daily rule reaches roughly 730 dates in two years, so the
	 * horizon and this ceiling meet by design rather than by coincidence.
	 */
	const MAX_OCCURRENCES = 730;

	/**
	 * The largest interval a rule may state.
	 *
	 * RFC 5545 sets no limit. This one exists because `INTERVAL=100000` is never
	 * a real answer and is always either a typo or somebody testing what
	 * happens.
	 */
	const MAX_INTERVAL = 999;

	/**
	 * How often it repeats.
	 *
	 * @var Frequency
	 */
	private $frequency;

	/**
	 * Every how many of those.
	 *
	 * @var int
	 */
	private $interval;

	/**
	 * Weekdays, with or without positions.
	 *
	 * @var Byday[]
	 */
	private $byday;

	/**
	 * Days of the month: 1..31 and -31..-1.
	 *
	 * @var int[]
	 */
	private $monthdays;

	/**
	 * Months: 1..12.
	 *
	 * @var int[]
	 */
	private $months;

	/**
	 * Last date the rule may produce, as `Y-m-d H:i:s` UTC, or ''.
	 *
	 * @var string
	 */
	private $until;

	/**
	 * How many occurrences in total, or 0 for unlimited.
	 *
	 * @var int
	 */
	private $count;

	/**
	 * Build a rule.
	 *
	 * @since 26.0
	 *
	 * `$byday` is typed as loosely as a public constructor deserves. Declaring
	 * `Byday[]` would let static analysis call the filter below redundant and
	 * invite somebody to delete it — and this constructor is reachable from a
	 * site's own code, where an array of strings is the obvious mistake to make.
	 * The filter is what stops that becoming a fatal inside to_string().
	 *
	 * @param Frequency         $frequency How often.
	 * @param int               $interval  Every how many.
	 * @param array<int, mixed> $byday     Weekdays; anything that is not a Byday is dropped.
	 * @param array<int, mixed> $monthdays Days of the month.
	 * @param array<int, mixed> $months    Months.
	 * @param string            $until     Last date, `Y-m-d H:i:s` UTC, or ''.
	 * @param int               $count     Total occurrences, or 0.
	 */
	public function __construct(
		Frequency $frequency,
		int $interval = 1,
		array $byday = array(),
		array $monthdays = array(),
		array $months = array(),
		string $until = '',
		int $count = 0
	) {
		$this->frequency = $frequency;
		$this->interval  = max( 1, $interval );
		$this->byday     = array_values( array_filter( $byday, static fn ( $day ) => $day instanceof Byday ) );
		$this->monthdays = array_values( array_unique( array_map( 'intval', $monthdays ) ) );
		$this->months    = array_values( array_unique( array_map( 'intval', $months ) ) );
		$this->until     = trim( $until );
		$this->count     = max( 0, $count );
	}

	/**
	 * Read a stored RRULE.
	 *
	 * Returns null for anything it cannot read, rather than a rule with defaults
	 * filled in. A rule that silently becomes "daily" because its FREQ was
	 * misspelled generates a year of dates nobody asked for.
	 *
	 * @since 26.0
	 *
	 * @param string $rrule Stored rule, with or without an `RRULE:` prefix.
	 * @return self|null
	 */
	public static function from_string( string $rrule ): ?self {
		$rrule = trim( $rrule );

		if ( '' === $rrule ) {
			return null;
		}

		// An RRULE arriving from an .ics file carries its property name.
		if ( 0 === stripos( $rrule, 'RRULE:' ) ) {
			$rrule = substr( $rrule, 6 );
		}

		$parts = array();

		foreach ( explode( ';', $rrule ) as $piece ) {
			if ( ! str_contains( $piece, '=' ) ) {
				continue;
			}

			list( $name, $value ) = explode( '=', $piece, 2 );

			$parts[ strtoupper( trim( $name ) ) ] = trim( $value );
		}

		$frequency = Frequency::coerce( $parts['FREQ'] ?? '' );

		if ( null === $frequency ) {
			return null;
		}

		$byday = array();

		foreach ( self::split_list( $parts['BYDAY'] ?? '' ) as $entry ) {
			$parsed = Byday::from_string( $entry );

			/*
			 * One unreadable entry fails the whole rule. Dropping it would leave
			 * a rule that parses, validates and generates the wrong dates —
			 * `BYDAY=MO,XX,FR` quietly becoming Mondays and Fridays only.
			 */
			if ( null === $parsed ) {
				return null;
			}

			$byday[] = $parsed;
		}

		return new self(
			$frequency,
			isset( $parts['INTERVAL'] ) ? (int) $parts['INTERVAL'] : 1,
			$byday,
			array_map( 'intval', self::split_list( $parts['BYMONTHDAY'] ?? '' ) ),
			array_map( 'intval', self::split_list( $parts['BYMONTH'] ?? '' ) ),
			self::parse_until( $parts['UNTIL'] ?? '' ),
			isset( $parts['COUNT'] ) ? (int) $parts['COUNT'] : 0
		);
	}

	/**
	 * Back to a stored RRULE.
	 *
	 * Parts appear in the order RFC 5545 lists them, so a rule that goes in and
	 * comes out again is byte-identical and a diff of two rules is readable.
	 *
	 * @since 26.0
	 */
	public function to_string(): string {
		$parts = array( 'FREQ=' . $this->frequency->value );

		if ( 1 !== $this->interval ) {
			$parts[] = 'INTERVAL=' . $this->interval;
		}

		if ( $this->count > 0 ) {
			$parts[] = 'COUNT=' . $this->count;
		} elseif ( '' !== $this->until ) {
			$parts[] = 'UNTIL=' . gmdate( 'Ymd\THis\Z', (int) strtotime( $this->until . ' UTC' ) );
		}

		if ( array() !== $this->byday ) {
			$parts[] = 'BYDAY=' . implode( ',', array_map( static fn ( Byday $day ) => $day->to_string(), $this->byday ) );
		}

		if ( array() !== $this->monthdays ) {
			$parts[] = 'BYMONTHDAY=' . implode( ',', $this->monthdays );
		}

		if ( array() !== $this->months ) {
			$parts[] = 'BYMONTH=' . implode( ',', $this->months );
		}

		return implode( ';', $parts );
	}

	/**
	 * Whether this rule is one the plugin will act on.
	 *
	 * Separate from parsing because the two answer different questions. Parsing
	 * asks "can I read this"; validation asks "should this be allowed to
	 * generate dates". A rule can be perfectly readable and still be
	 * `FREQ=WEEKLY;BYDAY=2TU`, which means nothing.
	 *
	 * @since 26.0
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		if ( $this->interval > self::MAX_INTERVAL ) {
			return new \WP_Error(
				'qevm_interval_too_large',
				sprintf(
					/* translators: %s: The largest allowed interval. */
					__( 'Repeating every %s is the most this supports.', 'quick-events-manager' ),
					number_format_i18n( self::MAX_INTERVAL )
				)
			);
		}

		/*
		 * RFC 5545 says UNTIL and COUNT MUST NOT both appear, and it is right:
		 * they are two different answers to "when does this stop" and nothing
		 * says which wins. Refused rather than resolved by precedence, because a
		 * precedence rule is invisible to whoever set both.
		 */
		if ( $this->count > 0 && '' !== $this->until ) {
			return new \WP_Error(
				'qevm_two_endings',
				__( 'A repeat can end on a date or after a number of times, not both.', 'quick-events-manager' )
			);
		}

		if ( $this->count > self::MAX_OCCURRENCES ) {
			return new \WP_Error(
				'qevm_count_too_large',
				sprintf(
					/* translators: %s: The largest allowed number of dates. */
					__( 'A series can hold at most %s dates.', 'quick-events-manager' ),
					number_format_i18n( self::MAX_OCCURRENCES )
				)
			);
		}

		if ( '' !== $this->until && false === strtotime( $this->until . ' UTC' ) ) {
			return new \WP_Error(
				'qevm_bad_until',
				__( 'That end date could not be read.', 'quick-events-manager' )
			);
		}

		foreach ( $this->byday as $day ) {
			if ( $day->has_position() && ! $this->frequency->allows_weekday_ordinals() ) {
				return new \WP_Error(
					'qevm_ordinal_not_allowed',
					sprintf(
						/* translators: 1: Description such as "the second Tuesday", 2: Frequency label such as "Weekly". */
						__( '"%1$s" cannot be used with a %2$s repeat.', 'quick-events-manager' ),
						$day->describe(),
						$this->frequency->label()
					)
				);
			}
		}

		foreach ( $this->monthdays as $day ) {
			if ( 0 === $day || $day > 31 || $day < -31 ) {
				return new \WP_Error(
					'qevm_bad_monthday',
					__( 'A day of the month must be between 1 and 31, or counted back from the end as -1 to -31.', 'quick-events-manager' )
				);
			}
		}

		foreach ( $this->months as $month ) {
			if ( $month < 1 || $month > 12 ) {
				return new \WP_Error(
					'qevm_bad_month',
					__( 'A month must be between 1 and 12.', 'quick-events-manager' )
				);
			}
		}

		if ( Frequency::Weekly !== $this->frequency && Frequency::Daily !== $this->frequency ) {
			return $this->validate_monthly_shape();
		}

		return true;
	}

	/**
	 * A monthly or yearly rule must not ask for a date and a weekday at once.
	 *
	 * "The 15th" and "the second Tuesday" are two different ways to name a day
	 * in a month. RFC 5545 does define what combining them means, and nobody
	 * setting up a monthly meetup means it: the standard reading of
	 * `BYMONTHDAY=15;BYDAY=TU` is "the 15th, but only if it is a Tuesday",
	 * which produces a series with gaps in it that look like a bug.
	 *
	 * @since 26.0
	 *
	 * @return true|\WP_Error
	 */
	private function validate_monthly_shape() {
		if ( array() !== $this->monthdays && array() !== $this->byday ) {
			return new \WP_Error(
				'qevm_day_conflict',
				__( 'Choose either a day of the month or a weekday, not both — combining them only keeps the dates that satisfy each other.', 'quick-events-manager' )
			);
		}

		return true;
	}

	/**
	 * How often it repeats.
	 *
	 * @since 26.0
	 */
	public function frequency(): Frequency {
		return $this->frequency;
	}

	/**
	 * Every how many of those.
	 *
	 * @since 26.0
	 */
	public function interval(): int {
		return $this->interval;
	}

	/**
	 * The weekdays it names.
	 *
	 * @since 26.0
	 *
	 * @return Byday[]
	 */
	public function byday(): array {
		return $this->byday;
	}

	/**
	 * The weekdays it names, as plain weekdays with positions dropped.
	 *
	 * @since 26.0
	 *
	 * @return Weekday[]
	 */
	public function weekdays(): array {
		return array_map( static fn ( Byday $day ) => $day->weekday(), $this->byday );
	}

	/**
	 * Days of the month it names.
	 *
	 * @since 26.0
	 *
	 * @return int[]
	 */
	public function monthdays(): array {
		return $this->monthdays;
	}

	/**
	 * Months it names.
	 *
	 * @since 26.0
	 *
	 * @return int[]
	 */
	public function months(): array {
		return $this->months;
	}

	/**
	 * Last date the rule may produce, or ''.
	 *
	 * @since 26.0
	 */
	public function until(): string {
		return $this->until;
	}

	/**
	 * Total occurrences, or 0 for unlimited.
	 *
	 * @since 26.0
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * The same rule, ending somewhere else.
	 *
	 * A new rule rather than a changed one, because a rule is a value: the
	 * generator holds one while it walks, and a setter would let a caller change
	 * the ending underneath a walk in progress.
	 *
	 * What a split needs, and the only edit to a rule it makes — the first half
	 * gains an `UNTIL` at the split point, the second keeps whatever ending the
	 * series had and, if that ending was a `COUNT`, gets its share of it.
	 *
	 * @since 26.0
	 *
	 * @param string $until Last date, `Y-m-d H:i:s` UTC, or '' for none.
	 * @param int    $count Total occurrences, or 0 for none.
	 * @return self
	 */
	public function with_ending( string $until, int $count ): self {
		return new self(
			$this->frequency,
			$this->interval,
			$this->byday,
			$this->monthdays,
			$this->months,
			$until,
			$count
		);
	}

	/**
	 * Whether the rule says for itself when to stop.
	 *
	 * A rule that does not is still bounded, by the horizon in C6.3. This
	 * reports what the *rule* says, which is what a UI has to show — "repeats
	 * forever" and "repeats until we stop generating" are different promises.
	 *
	 * @since 26.0
	 */
	public function has_own_ending(): bool {
		return $this->count > 0 || '' !== $this->until;
	}

	/**
	 * Roughly how many dates a year of this rule holds.
	 *
	 * Approximate, and only used to warn before a rule is saved. The exact
	 * number comes from the generator, which is the only thing that can know.
	 *
	 * @since 26.0
	 */
	public function approximate_yearly_count(): int {
		$per_year = (int) ceil( $this->frequency->approximate_per_year() / $this->interval );

		if ( Frequency::Weekly === $this->frequency && array() !== $this->byday ) {
			$per_year *= count( $this->byday );
		}

		return max( 1, $per_year );
	}

	/**
	 * Human-readable description, e.g. "Every 2 weeks on Monday and Thursday".
	 *
	 * Built here rather than in the UI because the same sentence belongs in the
	 * editor, in the confirmation before a destructive rule change, and in the
	 * admin list — and three copies of it would disagree.
	 *
	 * @since 26.0
	 */
	public function describe(): string {
		$every = $this->describe_frequency();
		$on    = $this->describe_days();
		$until = $this->describe_ending();

		return trim( $every . ( '' !== $on ? ' ' . $on : '' ) . ( '' !== $until ? ', ' . $until : '' ) );
	}

	/**
	 * The "every N somethings" part.
	 *
	 * @since 26.0
	 */
	private function describe_frequency(): string {
		if ( 1 === $this->interval ) {
			return match ( $this->frequency ) {
				Frequency::Daily   => __( 'Every day', 'quick-events-manager' ),
				Frequency::Weekly  => __( 'Every week', 'quick-events-manager' ),
				Frequency::Monthly => __( 'Every month', 'quick-events-manager' ),
				Frequency::Yearly  => __( 'Every year', 'quick-events-manager' ),
			};
		}

		$number = number_format_i18n( $this->interval );

		return match ( $this->frequency ) {
			/* translators: %s: Number of days. */
			Frequency::Daily   => sprintf( _n( 'Every %s day', 'Every %s days', $this->interval, 'quick-events-manager' ), $number ),
			/* translators: %s: Number of weeks. */
			Frequency::Weekly  => sprintf( _n( 'Every %s week', 'Every %s weeks', $this->interval, 'quick-events-manager' ), $number ),
			/* translators: %s: Number of months. */
			Frequency::Monthly => sprintf( _n( 'Every %s month', 'Every %s months', $this->interval, 'quick-events-manager' ), $number ),
			/* translators: %s: Number of years. */
			Frequency::Yearly  => sprintf( _n( 'Every %s year', 'Every %s years', $this->interval, 'quick-events-manager' ), $number ),
		};
	}

	/**
	 * The "on Monday and Thursday" part.
	 *
	 * @since 26.0
	 */
	private function describe_days(): string {
		if ( array() !== $this->byday ) {
			$names = array_map( static fn ( Byday $day ) => $day->describe(), $this->byday );

			/* translators: %s: List of weekdays. */
			return sprintf( __( 'on %s', 'quick-events-manager' ), self::join( $names ) );
		}

		if ( array() !== $this->monthdays ) {
			$days = array_map(
				static function ( $day ) {
					return $day < 0
						/* translators: %s: A number of days counted back from the end of the month. */
						? sprintf( __( '%s from the end', 'quick-events-manager' ), number_format_i18n( abs( $day ) ) )
						: number_format_i18n( $day );
				},
				$this->monthdays
			);

			/* translators: %s: List of days of the month. */
			return sprintf( __( 'on day %s', 'quick-events-manager' ), self::join( $days ) );
		}

		return '';
	}

	/**
	 * The "until ..." or "10 times" part.
	 *
	 * @since 26.0
	 */
	private function describe_ending(): string {
		if ( $this->count > 0 ) {
			/* translators: %s: Number of occurrences. */
			return sprintf( _n( '%s time', '%s times', $this->count, 'quick-events-manager' ), number_format_i18n( $this->count ) );
		}

		if ( '' !== $this->until ) {
			$timestamp = strtotime( $this->until . ' UTC' );

			if ( false !== $timestamp ) {
				/* translators: %s: A date. */
				return sprintf( __( 'until %s', 'quick-events-manager' ), date_i18n( (string) get_option( 'date_format' ), $timestamp ) );
			}
		}

		return '';
	}

	/**
	 * Join a list the way the site's language does.
	 *
	 * @since 26.0
	 *
	 * @param string[] $items Items.
	 * @return string
	 */
	private static function join( array $items ): string {
		$items = array_values( array_filter( $items, static fn ( $item ) => '' !== (string) $item ) );
		$last  = array_pop( $items );

		if ( null === $last ) {
			return '';
		}

		if ( array() === $items ) {
			return (string) $last;
		}

		return sprintf(
			/* translators: 1: A comma-separated list, 2: The final item. */
			__( '%1$s and %2$s', 'quick-events-manager' ),
			implode( __( ', ', 'quick-events-manager' ), $items ),
			$last
		);
	}

	/**
	 * Split a comma-separated rule part into trimmed, non-empty pieces.
	 *
	 * @since 26.0
	 *
	 * @param string $value Raw part value.
	 * @return string[]
	 */
	private static function split_list( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', $value ) ),
				static fn ( $piece ) => '' !== $piece
			)
		);
	}

	/**
	 * Read an RRULE `UNTIL` into the plugin's own datetime format.
	 *
	 * RFC 5545 writes it as `20261231T180000Z`, or as a bare date. Both are
	 * accepted; both come back as `Y-m-d H:i:s` in UTC, which is the one format
	 * everything else in this plugin stores ([ADR-0008](../../docs/adr/0008-datetime-storage.md)).
	 *
	 * @since 26.0
	 *
	 * @param string $value Raw UNTIL value.
	 * @return string `Y-m-d H:i:s` UTC, or '' when unreadable.
	 */
	private static function parse_until( string $value ): string {
		$value = strtoupper( trim( $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $value, $parts ) ) {
			return sprintf( '%s-%s-%s %s:%s:%s', $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $parts[6] );
		}

		if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $parts ) ) {
			/*
			 * A date with no time means the whole of that day is included. Read
			 * as midnight it would exclude a rule's own last occurrence, so an
			 * "until 31 December" weekly series ending on the 31st would lose
			 * its final date.
			 */
			return sprintf( '%s-%s-%s 23:59:59', $parts[1], $parts[2], $parts[3] );
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false !== $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
	}
}
