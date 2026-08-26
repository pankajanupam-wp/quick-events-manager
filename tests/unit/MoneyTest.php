<?php
/**
 * Money.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Commerce\Currency;
use QuickEventsManager\Commerce\Money;

/**
 * The one boundary where an amount becomes something a person reads.
 *
 * Everything here runs without a database, because none of it needs one: this
 * is arithmetic and string building, and if a test in this file needed a table
 * then money handling had leaked somewhere it should not be.
 */
#[CoversClass( Money::class )]
#[CoversClass( Currency::class )]
class MoneyTest extends TestCase {

	/**
	 * Start every test from a known currency.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		WP_Stub_State::$options[ QEVM_OPTION_SETTINGS ] = array( 'currency' => 'GBP' );
	}

	/**
	 * Minor units in, words out.
	 *
	 * @return void
	 */
	public function test_it_prints_what_a_person_expects() {
		$this->assertSame( '£499.50', Money::from_minor( 49950 )->format() );
		$this->assertSame( '£0.05', Money::from_minor( 5 )->format() );
		$this->assertSame( '£0.00', Money::zero()->format() );
		$this->assertSame( '£1,234.56', Money::from_minor( 123456 )->format(), 'thousands are grouped' );
	}

	/**
	 * A currency with no decimal places has none.
	 *
	 * ¥500 is five hundred minor units, not fifty thousand. Assuming two
	 * decimal places everywhere is the bug that makes a Japanese site's prices
	 * a hundred times too small, and nobody testing in pounds ever sees it.
	 *
	 * @return void
	 */
	public function test_a_currency_without_decimals_has_none() {
		$this->assertSame( '¥500', Money::from_minor( 500, 'JPY' )->format() );
		$this->assertSame( '500', Money::from_minor( 500, 'JPY' )->to_decimal_string() );
		$this->assertSame( 1, Currency::units( 'JPY' ) );
	}

	/**
	 * And one with three has three.
	 *
	 * @return void
	 */
	public function test_a_currency_with_three_decimals_has_three() {
		$this->assertSame( 'KD1.500', Money::from_minor( 1500, 'KWD' )->format() );
		$this->assertSame( '1.500', Money::from_minor( 1500, 'KWD' )->to_decimal_string() );
	}

	/**
	 * A symbol that belongs after the number goes after it.
	 *
	 * @return void
	 */
	public function test_the_symbol_goes_where_the_currency_puts_it() {
		$this->assertSame( '99.00 kr', Money::from_minor( 9900, 'SEK' )->format() );
	}

	/**
	 * An unknown currency still prints something unambiguous.
	 *
	 * @return void
	 */
	public function test_an_unknown_currency_prints_its_code() {
		$this->assertSame( 'XYZ12.00', Money::from_minor( 1200, 'XYZ' )->format() );
	}

	/**
	 * A price typed in whole units is rounded, not truncated.
	 *
	 * `(int) ( 12.10 * 100 )` is 1209 in binary floating point. A penny a
	 * ticket is how money quietly goes wrong.
	 *
	 * @return void
	 */
	public function test_a_typed_price_is_rounded() {
		$this->assertSame( 1210, Money::from_major( 12.10 )->minor() );
		$this->assertSame( 1999, Money::from_major( 19.99 )->minor() );
		$this->assertSame( 500, Money::from_major( 500, 'JPY' )->minor(), 'and a yen price is not multiplied by a hundred' );
	}

	/**
	 * Nothing is lost across the whole range a column can hold.
	 *
	 * The formatter separates the whole part from the fraction with integer
	 * division rather than dividing by a hundred, which is the same reason
	 * floats were rejected for storage: the division is where the error gets
	 * in.
	 *
	 * @return void
	 */
	public function test_large_amounts_survive_formatting() {
		$this->assertSame( '£92,233,720,368,547,758.07', Money::from_minor( PHP_INT_MAX )->format() );
		$this->assertSame( '92233720368547758.07', Money::from_minor( PHP_INT_MAX )->to_decimal_string() );
	}

	/**
	 * Money the other way round reads as a negative.
	 *
	 * @return void
	 */
	public function test_a_negative_amount_says_so() {
		$refund = Money::from_minor( -2500 );

		$this->assertTrue( $refund->is_negative() );
		$this->assertSame( '-£25.00', $refund->format() );
		$this->assertSame( '£25.00', $refund->absolute()->format() );
	}

	/**
	 * Arithmetic is exact and gives back a new amount.
	 *
	 * @return void
	 */
	public function test_arithmetic() {
		$ten = Money::from_minor( 1000 );

		$this->assertSame( 3000, $ten->times( 3 )->minor() );
		$this->assertSame( 1500, $ten->plus( Money::from_minor( 500 ) )->minor() );
		$this->assertSame( 750, $ten->minus( Money::from_minor( 250 ) )->minor() );
		$this->assertSame( 1000, $ten->minor(), 'the original is untouched' );
	}

	/**
	 * Adding two currencies together is refused rather than guessed at.
	 *
	 * A conversion needs a rate, a rate belongs to a moment and a provider, and
	 * picking one silently puts a made-up number into somebody's accounts.
	 *
	 * @return void
	 */
	public function test_currencies_cannot_be_mixed() {
		$this->expectException( InvalidArgumentException::class );

		Money::from_minor( 100, 'GBP' )->plus( Money::from_minor( 100, 'EUR' ) );
	}

	/**
	 * The machine-readable form is never localised.
	 *
	 * A decimal comma in a schema.org price or a gateway request is a rejected
	 * request or, worse, an accepted one for the wrong amount.
	 *
	 * @return void
	 */
	public function test_the_machine_readable_form_is_plain() {
		$this->assertSame( '499.50', Money::from_minor( 49950 )->to_decimal_string() );
		$this->assertSame( '1234.56', Money::from_minor( 123456 )->to_decimal_string(), 'no grouping either' );
	}

	/**
	 * An amount takes the site's currency when it is not told one.
	 *
	 * @return void
	 */
	public function test_the_site_currency_is_the_default() {
		$this->assertSame( 'GBP', Money::from_minor( 1 )->currency() );

		WP_Stub_State::$options[ QEVM_OPTION_SETTINGS ] = array( 'currency' => 'INR' );

		$this->assertSame( 'INR', Money::from_minor( 1 )->currency() );
		$this->assertSame( '₹12.00', Money::from_minor( 1200 )->format() );
	}

	/**
	 * A nonsense setting falls back rather than printing nonsense.
	 *
	 * @return void
	 */
	public function test_a_broken_setting_falls_back() {
		WP_Stub_State::$options[ QEVM_OPTION_SETTINGS ] = array( 'currency' => 'nonsense' );

		$this->assertSame( Currency::FALLBACK, Currency::site() );
	}

	/**
	 * Two amounts are the same only if the currency matches too.
	 *
	 * @return void
	 */
	public function test_equality_includes_the_currency() {
		$this->assertTrue( Money::from_minor( 100, 'GBP' )->equals( Money::from_minor( 100, 'GBP' ) ) );
		$this->assertFalse( Money::from_minor( 100, 'GBP' )->equals( Money::from_minor( 100, 'USD' ) ) );
	}
}
