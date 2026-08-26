<?php
/**
 * The stylesheets, read as text.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Direction-independence, checked rather than intended.
 *
 * The plan called for an `rtl.css`. There isn't one, deliberately.
 *
 * WordPress ships two ways to do right-to-left. `wp_style_add_data( $handle,
 * 'rtl', 'replace' )` swaps the whole file for a `-rtl` twin, which means
 * maintaining a second complete copy of 350 lines so that four of them can
 * differ. The additive form loads an override file, which is smaller but still
 * a second file that has to be remembered every time anybody adds a margin.
 * Both are a standing invitation to drift, and drift in a file nobody on the
 * team reads in the language it exists for.
 *
 * CSS logical properties do the same job with no second file: `border-inline-start`
 * is the left border in English and the right border in Arabic, decided by the
 * `dir` attribute the theme already sets. Grid and flex flip on their own.
 *
 * What that trades away is the ability to make RTL *look different* rather than
 * mirrored — different imagery, a different type scale. This plugin has no such
 * need; if one ever appears, the file can be added then.
 *
 * The risk it introduces is that the next person writes `margin-left` out of
 * habit and nothing complains, because in English it looks right. That is what
 * this test is for.
 */
final class StylesheetTest extends TestCase {

	/**
	 * Properties whose meaning changes with reading direction.
	 *
	 * Each has an `-inline-` counterpart that does the same job in both.
	 */
	private const PHYSICAL = array(
		'margin-left',
		'margin-right',
		'padding-left',
		'padding-right',
		'border-left',
		'border-right',
		'border-left-color',
		'border-right-color',
		'border-left-width',
		'border-right-width',
		'border-left-style',
		'border-right-style',
		'float',
		'clear',
	);

	/**
	 * No shipped stylesheet assumes text runs left to right.
	 *
	 * @param string $file Stylesheet, relative to the plugin root.
	 * @return void
	 */
	#[DataProvider( 'stylesheets' )]
	public function test_no_stylesheet_hard_codes_a_direction( $file ) {
		$css = (string) file_get_contents( ABSPATH . $file );

		$this->assertNotSame( '', $css, $file . ' is empty or unreadable' );

		foreach ( self::PHYSICAL as $property ) {
			$this->assertDoesNotMatchRegularExpression(
				'/(^|[;{\s])' . preg_quote( $property, '/' ) . '\s*:/m',
				$css,
				sprintf(
					'%s uses `%s`, which points the wrong way in Arabic, Hebrew and Persian. Use the -inline- equivalent.',
					$file,
					$property
				)
			);
		}
	}

	/**
	 * `text-align: left|right` is a direction too.
	 *
	 * `start` and `end` are the direction-independent values.
	 *
	 * @param string $file Stylesheet, relative to the plugin root.
	 * @return void
	 */
	#[DataProvider( 'stylesheets' )]
	public function test_no_stylesheet_aligns_text_to_a_side( $file ) {
		$css = (string) file_get_contents( ABSPATH . $file );

		$this->assertDoesNotMatchRegularExpression(
			'/text-align\s*:\s*(left|right)\b/',
			$css,
			$file . ' should align to start or end, not to left or right'
		);
	}

	/**
	 * The one physical offset that stays, stays paired with its logical twin.
	 *
	 * The honeypot is pushed off-screen, and if that ever failed to apply it
	 * would become a visible, labelled "Website" field in the middle of the
	 * form — which people fill in, and are then refused. So it keeps `left` as
	 * a fallback for anything that does not understand `inset-inline-start`,
	 * and the logical property has to follow it to win in Arabic.
	 *
	 * @return void
	 */
	public function test_the_honeypot_offset_has_both_forms_in_the_right_order() {
		$css = (string) file_get_contents( ABSPATH . 'assets/css/frontend.css' );

		$physical = strpos( $css, 'left: -9999px' );
		$logical  = strpos( $css, 'inset-inline-start: -9999px' );

		$this->assertNotFalse( $physical, 'the honeypot lost its fallback offset' );
		$this->assertNotFalse( $logical, 'the honeypot has no direction-independent offset' );
		$this->assertLessThan(
			$logical,
			$physical,
			'the logical property must come second, or it cannot override the fallback'
		);
	}

	/**
	 * Every stylesheet that ships.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function stylesheets() {
		return array(
			'frontend' => array( 'assets/css/frontend.css' ),
			'admin'    => array( 'assets/css/admin.css' ),
		);
	}
}
