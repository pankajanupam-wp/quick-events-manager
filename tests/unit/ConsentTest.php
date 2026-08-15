<?php
/**
 * What people agree to, and how a wording is identified afterwards.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Privacy\Consent;

/**
 * Consent wording and versioning.
 */
#[CoversClass( Consent::class )]
final class ConsentTest extends TestCase {

	/**
	 * Start each test with no stored settings and no filters.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		WP_Stub_State::reset();
	}

	/**
	 * Store a consent wording the way the settings screen would.
	 *
	 * @param string $text Wording.
	 * @return void
	 */
	private function configure( $text ) {
		update_option( QEVM_OPTION_SETTINGS, array( 'consent_text' => $text ) );
	}

	/**
	 * A site that has not been configured still asks for consent.
	 *
	 * The default wording is deliberately non-empty: a plugin that stores names
	 * and email addresses should not have "record nothing about why" as its
	 * out-of-the-box behaviour.
	 *
	 * @return void
	 */
	public function test_consent_is_asked_for_by_default() {
		$this->assertTrue( Consent::is_required() );
		$this->assertNotSame( '', Consent::text() );
		$this->assertNotSame( '', Consent::version() );
	}

	/**
	 * Emptying the wording is how a site turns consent off.
	 *
	 * @return void
	 */
	public function test_empty_wording_switches_consent_off() {
		$this->configure( '' );

		$this->assertFalse( Consent::is_required() );
		$this->assertSame( '', Consent::version() );
	}

	/**
	 * Whitespace is not a wording.
	 *
	 * @return void
	 */
	public function test_whitespace_is_not_a_wording() {
		$this->configure( "  \n\t " );

		$this->assertSame( '', Consent::text() );
		$this->assertFalse( Consent::is_required() );
	}

	/**
	 * The same wording always identifies itself the same way.
	 *
	 * @return void
	 */
	public function test_a_wording_has_a_stable_version() {
		$this->configure( 'I agree to the terms.' );

		$first = Consent::version();

		$this->assertSame( $first, Consent::version() );
	}

	/**
	 * Editing the wording gives it a new identity.
	 *
	 * This is the whole point of deriving the version rather than typing it:
	 * nobody has to remember to bump anything.
	 *
	 * @return void
	 */
	public function test_editing_the_wording_changes_the_version() {
		$this->configure( 'I agree to the terms.' );
		$before = Consent::version();

		$this->configure( 'I agree to the terms and the privacy policy.' );

		$this->assertNotSame( $before, Consent::version() );
	}

	/**
	 * Even a small edit counts as a different wording.
	 *
	 * @return void
	 */
	public function test_a_single_character_is_a_different_wording() {
		$this->configure( 'I agree.' );
		$before = Consent::version();

		$this->configure( 'I agree!' );

		$this->assertNotSame( $before, Consent::version() );
	}

	/**
	 * The version fits the column it is stored in.
	 *
	 * @return void
	 */
	public function test_the_version_fits_the_column() {
		$this->configure( str_repeat( 'wording ', 500 ) );

		$this->assertSame( Consent::VERSION_LENGTH, strlen( Consent::version() ) );
		$this->assertLessThanOrEqual( 64, strlen( Consent::version() ) );
	}

	/*
	 * The qevm_consent_text filter is not exercised here. This suite's
	 * apply_filters() returns the value unchanged by design — nothing is
	 * subscribed in a unit test — so a filter test would assert the stub rather
	 * than the behaviour. It is checked against real WordPress instead.
	 */

	/**
	 * The wording may carry a privacy policy link, and nothing more dangerous.
	 *
	 * @return void
	 */
	public function test_the_markup_allowance_is_a_link_and_emphasis() {
		$allowed = Consent::allowed_html();

		$this->assertArrayHasKey( 'a', $allowed );
		$this->assertArrayHasKey( 'href', $allowed['a'] );
		$this->assertArrayNotHasKey( 'script', $allowed );
		$this->assertArrayNotHasKey( 'iframe', $allowed );
		$this->assertArrayNotHasKey( 'input', $allowed );
	}
}
