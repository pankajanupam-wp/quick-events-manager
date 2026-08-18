<?php
/**
 * How often a series repeats.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The `FREQ` part of a recurrence rule.
 *
 * Backed by the strings RFC 5545 uses, because the rule is stored as an RRULE
 * and these values go into it verbatim. Changing a case value is therefore a
 * change to stored data, not a rename.
 *
 * Deliberately only the four common frequencies. `SECONDLY`, `MINUTELY` and
 * `HOURLY` are in the standard and have no place in an event plugin: a rule
 * that generates something every minute cannot be bounded into anything a
 * calendar can display, and offering it invites somebody to try.
 *
 * @since 26.0
 */
enum Frequency: string {

	/**
	 * Every day, or every N days.
	 */
	case Daily = 'DAILY';

	/**
	 * Every week, optionally on named weekdays.
	 */
	case Weekly = 'WEEKLY';

	/**
	 * Every month, by date or by weekday position.
	 */
	case Monthly = 'MONTHLY';

	/**
	 * Every year.
	 */
	case Yearly = 'YEARLY';

	/**
	 * Every frequency, shortest first.
	 *
	 * @since 26.0
	 *
	 * @return self[]
	 */
	public static function all(): array {
		return array( self::Daily, self::Weekly, self::Monthly, self::Yearly );
	}

	/**
	 * Every frequency as its stored string.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function values(): array {
		return array_map( static fn( self $frequency ): string => $frequency->value, self::all() );
	}

	/**
	 * Read a stored value, or null if it is not one.
	 *
	 * Returns null rather than a default. There is no sensible frequency to
	 * assume for an unrecognised one, and guessing would turn a typo into a
	 * series of dates somebody has to delete by hand.
	 *
	 * @since 26.0
	 *
	 * @param string $value Stored value, any case.
	 * @return self|null
	 */
	public static function coerce( string $value ): ?self {
		return self::tryFrom( strtoupper( trim( $value ) ) );
	}

	/**
	 * Human-readable label.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return match ( $this ) {
			self::Daily   => __( 'Daily', 'quick-events-manager' ),
			self::Weekly  => __( 'Weekly', 'quick-events-manager' ),
			self::Monthly => __( 'Monthly', 'quick-events-manager' ),
			self::Yearly  => __( 'Yearly', 'quick-events-manager' ),
		};
	}

	/**
	 * Whether a weekday may carry an ordinal at this frequency.
	 *
	 * "The second Tuesday" means something monthly and yearly. Weekly it is
	 * either nonsense or a misunderstanding — there is only ever one Tuesday in
	 * a week — so `BYDAY=2TU` with `FREQ=WEEKLY` is refused rather than
	 * silently read as `TU`.
	 *
	 * @since 26.0
	 */
	public function allows_weekday_ordinals(): bool {
		return self::Monthly === $this || self::Yearly === $this;
	}

	/**
	 * Roughly how many of these fit in a year.
	 *
	 * Used to sanity-check a rule against the generation ceiling before any
	 * dates exist. Approximate on purpose: it answers "is this rule going to
	 * produce hundreds of rows", not "exactly how many".
	 *
	 * @since 26.0
	 */
	public function approximate_per_year(): int {
		return match ( $this ) {
			self::Daily   => 365,
			self::Weekly  => 52,
			self::Monthly => 12,
			self::Yearly  => 1,
		};
	}
}
