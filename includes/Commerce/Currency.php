<?php
/**
 * What a currency is made of.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce;

use QuickEventsManager\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The facts about a currency that formatting needs, and nothing else.
 *
 * Three of them: the symbol, how many decimal places it has, and which side of
 * the number the symbol goes. That is enough to print money correctly and small
 * enough to hold in one file.
 *
 * **The number of decimal places is not always two.** The yen and the won have
 * none — ¥500 is 500 minor units, not 50,000 — and the dinar has three.
 * Assuming two is the bug that makes a Japanese site's prices a hundred times
 * too small, and it is invisible to anybody testing in dollars.
 *
 * **`intl` is not assumed.** It is a common PHP extension and it is not a
 * universal one, and a plugin that prints prices wrong on the hosts that lack it
 * is worse than one that formats a shorter list of currencies itself. Anything
 * unlisted still works: it falls back to the ISO code and two decimals, which is
 * correct for most of the world and legible for the rest.
 *
 * @since 26.0
 */
final class Currency {

	/**
	 * What the site charges in when nothing says otherwise.
	 *
	 * WordPress has no currency of its own to read, and a locale does not imply
	 * one — plenty of English-speaking sites charge in euros. So there is a
	 * setting, and this is what it starts at until somebody changes it.
	 */
	const FALLBACK = 'USD';

	/**
	 * Symbol, decimals and symbol position, by ISO-4217 code.
	 *
	 * Typed loosely on purpose: the list goes through a filter, so what comes
	 * back is whatever a site returned from it. `facts()` is the thing that
	 * turns that into something the formatter can rely on, and it reads every
	 * key defensively for exactly this reason.
	 *
	 * @since 26.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$currencies = array(
			'USD' => array(
				'symbol'   => '$',
				'decimals' => 2,
				'before'   => true,
			),
			'EUR' => array(
				'symbol'   => '€',
				'decimals' => 2,
				'before'   => true,
			),
			'GBP' => array(
				'symbol'   => '£',
				'decimals' => 2,
				'before'   => true,
			),
			'INR' => array(
				'symbol'   => '₹',
				'decimals' => 2,
				'before'   => true,
			),
			'AUD' => array(
				'symbol'   => 'A$',
				'decimals' => 2,
				'before'   => true,
			),
			'CAD' => array(
				'symbol'   => 'C$',
				'decimals' => 2,
				'before'   => true,
			),
			'NZD' => array(
				'symbol'   => 'NZ$',
				'decimals' => 2,
				'before'   => true,
			),
			'SGD' => array(
				'symbol'   => 'S$',
				'decimals' => 2,
				'before'   => true,
			),
			'ZAR' => array(
				'symbol'   => 'R',
				'decimals' => 2,
				'before'   => true,
			),
			'BRL' => array(
				'symbol'   => 'R$',
				'decimals' => 2,
				'before'   => true,
			),
			'MXN' => array(
				'symbol'   => 'MX$',
				'decimals' => 2,
				'before'   => true,
			),
			'CHF' => array(
				'symbol'   => 'CHF',
				'decimals' => 2,
				'before'   => true,
			),
			'SEK' => array(
				'symbol'   => 'kr',
				'decimals' => 2,
				'before'   => false,
			),
			'NOK' => array(
				'symbol'   => 'kr',
				'decimals' => 2,
				'before'   => false,
			),
			'DKK' => array(
				'symbol'   => 'kr',
				'decimals' => 2,
				'before'   => false,
			),
			'PLN' => array(
				'symbol'   => 'zł',
				'decimals' => 2,
				'before'   => false,
			),
			'JPY' => array(
				'symbol'   => '¥',
				'decimals' => 0,
				'before'   => true,
			),
			'KRW' => array(
				'symbol'   => '₩',
				'decimals' => 0,
				'before'   => true,
			),
			'AED' => array(
				'symbol'   => 'AED',
				'decimals' => 2,
				'before'   => true,
			),
			'KWD' => array(
				'symbol'   => 'KD',
				'decimals' => 3,
				'before'   => true,
			),
		);

		/**
		 * Filter the currencies the plugin knows how to print.
		 *
		 * Each entry is `symbol`, `decimals` and `before`. Adding one is how a
		 * site charges in something not listed without editing the plugin.
		 *
		 * @since 26.0
		 *
		 * @param array<string, array<string, mixed>> $currencies Currencies by ISO-4217 code.
		 */
		$currencies = (array) apply_filters( 'qevm_currencies', $currencies );

		return $currencies;
	}

	/**
	 * What the site charges in.
	 *
	 * @since 26.0
	 */
	public static function site(): string {
		$code = strtoupper( (string) Settings::get( 'currency', self::FALLBACK ) );

		return 3 === strlen( $code ) ? $code : self::FALLBACK;
	}

	/**
	 * The facts about one currency, falling back to something printable.
	 *
	 * An unknown code is not an error. A site charging in something this list
	 * has never heard of should see `XYZ 12.00`, which is unambiguous and
	 * correct, rather than a warning or a blank.
	 *
	 * @since 26.0
	 *
	 * @param string $code ISO-4217 code.
	 * @return array{symbol: string, decimals: int, before: bool}
	 */
	public static function facts( string $code ): array {
		$code = strtoupper( $code );
		$all  = self::all();

		if ( isset( $all[ $code ] ) ) {
			return array(
				'symbol'   => (string) ( $all[ $code ]['symbol'] ?? $code ),
				'decimals' => (int) ( $all[ $code ]['decimals'] ?? 2 ),
				'before'   => (bool) ( $all[ $code ]['before'] ?? true ),
			);
		}

		return array(
			'symbol'   => '' !== $code ? $code : self::FALLBACK,
			'decimals' => 2,
			'before'   => true,
		);
	}

	/**
	 * How many minor units make one of a currency.
	 *
	 * 100 for pence and cents, 1 for the yen, 1000 for the dinar.
	 *
	 * @since 26.0
	 *
	 * @param string $code ISO-4217 code.
	 */
	public static function units( string $code ): int {
		return (int) pow( 10, self::facts( $code )['decimals'] );
	}

	/**
	 * Codes and labels, for a settings dropdown.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$choices = array();

		foreach ( self::all() as $code => $facts ) {
			$symbol = (string) ( $facts['symbol'] ?? $code );

			$choices[ $code ] = $code === $symbol
				? $code
				: sprintf( '%1$s (%2$s)', $code, $symbol );
		}

		ksort( $choices );

		return $choices;
	}
}
