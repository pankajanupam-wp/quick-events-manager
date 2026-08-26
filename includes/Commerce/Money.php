<?php
/**
 * An amount of money.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * An integer number of minor units and the currency they are in.
 *
 * **This is the only place in the plugin that turns money into words**, which is
 * the point of it existing. Every column is an integer, every calculation is
 * integer arithmetic, and the one boundary where an amount becomes something a
 * person reads is `format()`. A raw `49950` reaching a template is a visible bug
 * and is meant to be — see
 * [ADR-0006](../../docs/adr/0006-money-and-immutability.md).
 *
 * **No float is used, even to print.** The obvious way to format 49950 is to
 * divide by 100 and hand the result to a number formatter, and it is wrong for
 * the same reason floats were rejected for storage: the division is where the
 * error enters. The whole part and the fractional part are separated with
 * `intdiv()` and `%`, and the thousands are grouped as a string, which is exact
 * for every value a `bigint` can hold. A first version used
 * `number_format_i18n()` for the grouping and printed the largest amounts wrong
 * by two — the float had been let back in at the last step.
 *
 * `readonly` because an amount that can be edited in place is an amount that can
 * be edited by accident. Arithmetic returns a new object.
 *
 * @since 26.0
 */
final class Money {

	/**
	 * Minor units — pence, cents, paise.
	 *
	 * @var int
	 */
	private readonly int $minor;

	/**
	 * ISO-4217 code.
	 *
	 * @var string
	 */
	private readonly string $currency;

	/**
	 * Build from minor units.
	 *
	 * @since 26.0
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO-4217 code, defaulting to the site's.
	 */
	public function __construct( int $minor, string $currency = '' ) {
		$this->minor    = $minor;
		$this->currency = '' !== $currency ? strtoupper( $currency ) : Currency::site();
	}

	/**
	 * Build from minor units.
	 *
	 * @since 26.0
	 *
	 * @param mixed  $minor    Minor units.
	 * @param string $currency ISO-4217 code, defaulting to the site's.
	 */
	public static function from_minor( $minor, string $currency = '' ): self {
		return new self( (int) $minor, $currency );
	}

	/**
	 * Build from whole units, the way a price is typed in.
	 *
	 * Rounded rather than truncated: `(int) ( 12.10 * 100 )` is 1209 in binary
	 * floating point, and a penny a ticket is how money quietly goes wrong.
	 * This is the only place a float is allowed anywhere near an amount, and it
	 * exists because a human typed one.
	 *
	 * @since 26.0
	 *
	 * @param mixed  $major    Whole units, e.g. 12.10.
	 * @param string $currency ISO-4217 code, defaulting to the site's.
	 */
	public static function from_major( $major, string $currency = '' ): self {
		$currency = '' !== $currency ? strtoupper( $currency ) : Currency::site();

		return new self( (int) round( ( (float) $major ) * Currency::units( $currency ) ), $currency );
	}

	/**
	 * Nothing, in the site's currency.
	 *
	 * @since 26.0
	 *
	 * @param string $currency ISO-4217 code, defaulting to the site's.
	 */
	public static function zero( string $currency = '' ): self {
		return new self( 0, $currency );
	}

	/**
	 * Minor units.
	 *
	 * @since 26.0
	 */
	public function minor(): int {
		return $this->minor;
	}

	/**
	 * ISO-4217 code.
	 *
	 * @since 26.0
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Whether this is nothing at all.
	 *
	 * @since 26.0
	 */
	public function is_zero(): bool {
		return 0 === $this->minor;
	}

	/**
	 * Whether money is owed the other way.
	 *
	 * @since 26.0
	 */
	public function is_negative(): bool {
		return $this->minor < 0;
	}

	/**
	 * Add, in the same currency.
	 *
	 * Mixing currencies is refused rather than converted: a rate belongs to a
	 * moment and a provider, and silently picking one would put a made-up
	 * number in somebody's accounts.
	 *
	 * @since 26.0
	 *
	 * @param Money $other Amount to add.
	 * @throws \InvalidArgumentException When the currencies differ.
	 */
	public function plus( Money $other ): self {
		$this->must_match( $other );

		return new self( $this->minor + $other->minor(), $this->currency );
	}

	/**
	 * Subtract, in the same currency.
	 *
	 * @since 26.0
	 *
	 * @param Money $other Amount to take away.
	 * @throws \InvalidArgumentException When the currencies differ.
	 */
	public function minus( Money $other ): self {
		$this->must_match( $other );

		return new self( $this->minor - $other->minor(), $this->currency );
	}

	/**
	 * Multiply by a whole number, which is what a quantity is.
	 *
	 * Deliberately integer-only. A percentage — tax, a discount — has to decide
	 * how it rounds, and that decision belongs to whatever is applying it
	 * rather than being hidden in a multiplication here.
	 *
	 * @since 26.0
	 *
	 * @param int $times How many.
	 */
	public function times( int $times ): self {
		return new self( $this->minor * $times, $this->currency );
	}

	/**
	 * The same amount without its sign.
	 *
	 * @since 26.0
	 */
	public function absolute(): self {
		return new self( abs( $this->minor ), $this->currency );
	}

	/**
	 * Whether two amounts are the same money.
	 *
	 * @since 26.0
	 *
	 * @param Money $other Amount to compare.
	 */
	public function equals( Money $other ): bool {
		return $this->minor === $other->minor() && $this->currency === $other->currency();
	}

	/**
	 * As a person reads it, e.g. `£499.50`, `¥500`, `XYZ 12.00`.
	 *
	 * @since 26.0
	 *
	 * @param bool $with_symbol Print the symbol, or just the number.
	 */
	public function format( bool $with_symbol = true ): string {
		$facts = Currency::facts( $this->currency );

		$negative = $this->minor < 0;
		$units    = Currency::units( $this->currency );
		$absolute = abs( $this->minor );

		$whole = intdiv( $absolute, $units );
		$part  = $absolute % $units;

		$number = self::group( (string) $whole );

		if ( $facts['decimals'] > 0 ) {
			$number .= self::decimal_separator() . str_pad( (string) $part, $facts['decimals'], '0', STR_PAD_LEFT );
		}

		if ( $with_symbol ) {
			$number = $facts['before']
				? $facts['symbol'] . $number
				: $number . ' ' . $facts['symbol'];
		}

		$formatted = $negative ? '-' . $number : $number;

		/**
		 * Filter one formatted amount.
		 *
		 * Where a site fixes a symbol, a position or a separator this does not
		 * get right for it, without every template having to know.
		 *
		 * @since 26.0
		 *
		 * @param string $formatted What will be printed.
		 * @param int    $minor     Minor units.
		 * @param string $currency  ISO-4217 code.
		 */
		return (string) apply_filters( 'qevm_money_format', $formatted, $this->minor, $this->currency );
	}

	/**
	 * As a machine reads it, e.g. `499.50` — for `.ics`, JSON-LD and gateways.
	 *
	 * Never localised: a decimal comma in a schema.org price or a Stripe
	 * request is a rejected request or, worse, an accepted one for the wrong
	 * amount.
	 *
	 * @since 26.0
	 */
	public function to_decimal_string(): string {
		$facts    = Currency::facts( $this->currency );
		$units    = Currency::units( $this->currency );
		$negative = $this->minor < 0;
		$absolute = abs( $this->minor );

		$string = (string) intdiv( $absolute, $units );

		if ( $facts['decimals'] > 0 ) {
			$string .= '.' . str_pad( (string) ( $absolute % $units ), $facts['decimals'], '0', STR_PAD_LEFT );
		}

		return $negative ? '-' . $string : $string;
	}

	/**
	 * Printed by default.
	 *
	 * @since 26.0
	 */
	public function __toString(): string {
		return $this->format();
	}

	/**
	 * Refuse to mix currencies.
	 *
	 * @since 26.0
	 *
	 * @param Money $other The other amount.
	 * @throws \InvalidArgumentException When the currencies differ.
	 * @return void
	 */
	private function must_match( Money $other ) {
		if ( $this->currency !== $other->currency() ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: 1: First currency code. 2: Second currency code. */
						__( 'Cannot combine %1$s with %2$s.', 'quick-events-manager' ),
						$this->currency,
						$other->currency()
					)
				)
			);
		}
	}

	/**
	 * Group the whole part into thousands, without going through a float.
	 *
	 * `number_format_i18n()` was the obvious thing to reach for and is wrong
	 * here: it takes a float, and the largest amounts a `bigint` column can
	 * hold do not survive the conversion — `92233720368547758` comes back as
	 * `92233720368547760`. That is the same failure floats were rejected for in
	 * storage, arriving through the back door at the moment of printing. The
	 * digits are grouped as a string instead, which is exact for anything the
	 * column can hold.
	 *
	 * @since 26.0
	 *
	 * @param string $digits The whole part, digits only.
	 * @return string
	 */
	private static function group( string $digits ) {
		$separator = self::thousands_separator();

		if ( '' === $separator ) {
			return $digits;
		}

		$grouped = '';
		$length  = strlen( $digits );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( $i > 0 && 0 === ( $length - $i ) % 3 ) {
				$grouped .= $separator;
			}

			$grouped .= $digits[ $i ];
		}

		return $grouped;
	}

	/**
	 * The site's thousands separator.
	 *
	 * @since 26.0
	 */
	private static function thousands_separator(): string {
		global $wp_locale;

		if ( isset( $wp_locale ) && is_object( $wp_locale ) && isset( $wp_locale->number_format['thousands_sep'] ) ) {
			return (string) $wp_locale->number_format['thousands_sep'];
		}

		return ',';
	}

	/**
	 * The site's decimal separator.
	 *
	 * WordPress keeps one for its own number formatting, and money should read
	 * the way every other number on the site reads.
	 *
	 * @since 26.0
	 */
	private static function decimal_separator(): string {
		global $wp_locale;

		if ( isset( $wp_locale ) && is_object( $wp_locale ) && isset( $wp_locale->number_format['decimal_point'] ) ) {
			return (string) $wp_locale->number_format['decimal_point'];
		}

		return '.';
	}
}
