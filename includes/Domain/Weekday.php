<?php
/**
 * A day of the week, as a recurrence rule names it.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The two-letter weekday codes from RFC 5545.
 *
 * Backed by those codes because they are what goes into the stored RRULE.
 *
 * **PHP has two weekday numberings and they disagree about Sunday.** `date( 'w' )`
 * is 0–6 starting Sunday; `date( 'N' )` is 1–7 starting Monday. Mixing them is a
 * one-day error that only shows up at the weekend, which is exactly when a
 * weekly event is most likely to be scheduled. Both conversions are written out
 * here so no caller has to remember which one it holds.
 *
 * @since 26.0
 */
enum Weekday: string {

	/**
	 * Monday.
	 */
	case Monday = 'MO';

	/**
	 * Tuesday.
	 */
	case Tuesday = 'TU';

	/**
	 * Wednesday.
	 */
	case Wednesday = 'WE';

	/**
	 * Thursday.
	 */
	case Thursday = 'TH';

	/**
	 * Friday.
	 */
	case Friday = 'FR';

	/**
	 * Saturday.
	 */
	case Saturday = 'SA';

	/**
	 * Sunday.
	 */
	case Sunday = 'SU';

	/**
	 * Every weekday, Monday first.
	 *
	 * Monday first because RFC 5545's default week start is Monday, and this
	 * order is what gets written into a rule. The **display** order on a form is
	 * a different question with a different answer — `start_of_week` is a site
	 * setting and half the world starts on Sunday — so a UI asks
	 * self::in_display_order() instead.
	 *
	 * @since 26.0
	 *
	 * @return self[]
	 */
	public static function all(): array {
		return array(
			self::Monday,
			self::Tuesday,
			self::Wednesday,
			self::Thursday,
			self::Friday,
			self::Saturday,
			self::Sunday,
		);
	}

	/**
	 * Every weekday as its stored code.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function values(): array {
		return array_map( static fn( self $day ): string => $day->value, self::all() );
	}

	/**
	 * Every weekday, starting where the site says the week starts.
	 *
	 * @since 26.0
	 *
	 * @return self[]
	 */
	public static function in_display_order(): array {
		$start = (int) get_option( 'start_of_week', 1 );
		$days  = array();

		for ( $offset = 0; $offset < 7; $offset++ ) {
			$day = self::from_php_w( ( $start + $offset ) % 7 );

			if ( null !== $day ) {
				$days[] = $day;
			}
		}

		return array() === $days ? self::all() : $days;
	}

	/**
	 * Read a stored code, or null if it is not one.
	 *
	 * @since 26.0
	 *
	 * @param string $value Two-letter code, any case.
	 * @return self|null
	 */
	public static function coerce( string $value ): ?self {
		return self::tryFrom( strtoupper( trim( $value ) ) );
	}

	/**
	 * From PHP's `w` numbering: 0 = Sunday through 6 = Saturday.
	 *
	 * @since 26.0
	 *
	 * @param int $number Day number.
	 * @return self|null Null when the number is outside 0–6.
	 */
	public static function from_php_w( int $number ): ?self {
		return match ( $number ) {
			0       => self::Sunday,
			1       => self::Monday,
			2       => self::Tuesday,
			3       => self::Wednesday,
			4       => self::Thursday,
			5       => self::Friday,
			6       => self::Saturday,
			default => null,
		};
	}

	/**
	 * To PHP's `w` numbering: 0 = Sunday through 6 = Saturday.
	 *
	 * @since 26.0
	 */
	public function to_php_w(): int {
		return match ( $this ) {
			self::Sunday    => 0,
			self::Monday    => 1,
			self::Tuesday   => 2,
			self::Wednesday => 3,
			self::Thursday  => 4,
			self::Friday    => 5,
			self::Saturday  => 6,
		};
	}

	/**
	 * To PHP's `N` numbering: 1 = Monday through 7 = Sunday.
	 *
	 * @since 26.0
	 */
	public function to_php_n(): int {
		return match ( $this ) {
			self::Monday    => 1,
			self::Tuesday   => 2,
			self::Wednesday => 3,
			self::Thursday  => 4,
			self::Friday    => 5,
			self::Saturday  => 6,
			self::Sunday    => 7,
		};
	}

	/**
	 * Human-readable name, translated and localised.
	 *
	 * Taken from WordPress rather than hard-coded, so it matches the weekday
	 * names shown everywhere else in the admin and needs no translation of its
	 * own.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		global $wp_locale;

		if ( $wp_locale instanceof \WP_Locale ) {
			return $wp_locale->get_weekday( $this->to_php_w() );
		}

		return match ( $this ) {
			self::Monday    => __( 'Monday', 'quick-events-manager' ),
			self::Tuesday   => __( 'Tuesday', 'quick-events-manager' ),
			self::Wednesday => __( 'Wednesday', 'quick-events-manager' ),
			self::Thursday  => __( 'Thursday', 'quick-events-manager' ),
			self::Friday    => __( 'Friday', 'quick-events-manager' ),
			self::Saturday  => __( 'Saturday', 'quick-events-manager' ),
			self::Sunday    => __( 'Sunday', 'quick-events-manager' ),
		};
	}
}
