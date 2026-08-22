<?php
/**
 * The QR encoder, against a reference implementation.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\CheckIn\Qr;

/**
 * A QR code that does not scan is worth less than no QR code at all.
 *
 * Which makes this the one piece of the plugin where "it looks right" proves
 * nothing: a symbol with one wrong module still looks like a QR code, prints
 * like a QR code, and fails at the door. So every matrix here is compared
 * against one produced by an established implementation, module for module,
 * rather than against anything this codebase believes about itself.
 */
#[CoversClass( Qr::class )]
final class QrTest extends TestCase {

	/**
	 * Every module of every known-good code.
	 *
	 * @param string $code    The ticket code.
	 * @param int    $size    Expected side, in modules.
	 * @param string $modules Expected grid, row by row, 1 for dark.
	 * @return void
	 */
	#[DataProvider( 'golden' )]
	public function test_a_code_matches_the_reference( string $code, int $size, string $modules ): void {
		$matrix = Qr::matrix( $code );

		$this->assertCount( $size, $matrix, 'the symbol is the wrong size' );

		$actual = '';

		foreach ( $matrix as $row ) {
			$this->assertCount( $size, $row );

			foreach ( $row as $dark ) {
				$actual .= $dark ? '1' : '0';
			}
		}

		$this->assertSame( $modules, $actual, 'the symbol differs from the reference implementation' );
	}

	/**
	 * The fixtures are real codes, not a row of zeros.
	 *
	 * A guard on the guard. A fixture file of empty strings would make every
	 * assertion above pass against an encoder that returned nothing.
	 *
	 * @return void
	 */
	public function test_the_fixtures_describe_actual_symbols(): void {
		foreach ( self::golden() as $case ) {
			list( $code, $size, $modules ) = $case;

			$this->assertSame( 21, $size, $code . ' is not a version 1 symbol' );
			$this->assertSame( 441, strlen( $modules ) );

			$dark = substr_count( $modules, '1' );

			$this->assertGreaterThan( 100, $dark, $code . ' has implausibly few dark modules' );
			$this->assertLessThan( 341, $dark, $code . ' has implausibly many dark modules' );
		}
	}

	/**
	 * The three fixed patterns a scanner looks for are where they must be.
	 *
	 * @return void
	 */
	public function test_the_finder_patterns_are_in_all_three_corners(): void {
		$matrix = Qr::matrix( 'QEVT-ABC123DEF456' );

		foreach ( array( array( 0, 0 ), array( 14, 0 ), array( 0, 14 ) ) as $corner ) {
			list( $ox, $oy ) = $corner;

			$this->assertTrue( $matrix[ $oy ][ $ox ], 'a finder pattern is missing a corner' );
			$this->assertTrue( $matrix[ $oy + 3 ][ $ox + 3 ], 'a finder pattern is missing its centre' );
			$this->assertFalse( $matrix[ $oy + 1 ][ $ox + 1 ], 'a finder pattern has no ring' );
		}

		// The module that is always dark, whatever the data or the mask.
		$this->assertTrue( $matrix[13][8], 'the dark module is not dark' );
	}

	/**
	 * Anything it cannot carry is refused rather than mangled.
	 *
	 * @return void
	 */
	public function test_what_it_refuses(): void {
		foreach ( array( '', 'lowercase', 'QEVT-ABC!', 'QEVT-ÉTÉ', str_repeat( 'A', 21 ) ) as $bad ) {
			$this->assertFalse( Qr::can_encode( $bad ), $bad . ' should not be encodable' );
			$this->assertSame( array(), Qr::matrix( $bad ) );
			$this->assertSame( '', Qr::svg( $bad ) );
		}

		// And the shape it exists for is accepted.
		$this->assertTrue( Qr::can_encode( 'QEVT-ABC123DEF456' ) );
	}

	/**
	 * The SVG carries the quiet zone, which a scanner needs to find the edges.
	 *
	 * @return void
	 */
	public function test_the_svg_has_a_quiet_zone_and_a_light_background(): void {
		$svg = Qr::svg( 'QEVT-ABC123DEF456', 4, 4 );

		// 21 modules plus four each side, at four pixels a module.
		$this->assertStringContainsString( 'width="116"', $svg );
		$this->assertStringContainsString( 'height="116"', $svg );
		$this->assertStringContainsString( 'fill="#fff"', $svg, 'a QR code on a dark background cannot be read' );
		$this->assertStringContainsString( '<path', $svg );
		$this->assertStringContainsString( 'role="img"', $svg );
	}

	/**
	 * The known-good matrices.
	 *
	 * @return array<int, array{0: string, 1: int, 2: string}>
	 */
	public static function golden(): array {
		$fixtures = require __DIR__ . '/fixtures/qr-golden.php';
		$cases    = array();

		foreach ( $fixtures as $code => $data ) {
			$cases[] = array( (string) $code, (int) $data['size'], (string) $data['modules'] );
		}

		return $cases;
	}
}
