<?php
/**
 * Covers the date, time and timezone rules.
 *
 * These are the tests that matter most in the plugin. Storing an event's local
 * time and nothing else is the classic failure in this category of software:
 * every event silently moves the day somebody changes the site timezone, and
 * nobody notices until attendees turn up an hour late.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\TestCase;
use QuickEventsManager\Events\Meta;

/**
 * @covers \QuickEventsManager\Events\Meta
 */
final class MetaTest extends TestCase {

	/**
	 * Reset stub state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub_State::reset();
	}

	/**
	 * A wall-clock time in a named zone converts to the right UTC instant.
	 *
	 * @return void
	 */
	public function test_local_time_converts_to_utc() {
		$this->assertSame(
			'2026-09-01 12:30:00',
			Meta::to_utc( '2026-09-01 18:00:00', 'Asia/Kolkata' )
		);
	}

	/**
	 * And back again, losing nothing.
	 *
	 * @return void
	 */
	public function test_utc_converts_back_to_local() {
		$this->assertSame(
			'2026-09-01 18:00:00',
			Meta::to_local( '2026-09-01 12:30:00', 'Asia/Kolkata' )
		);
	}

	/**
	 * A round trip through UTC returns the original wall-clock time for every
	 * zone, including the half-hour and three-quarter-hour offsets that catch
	 * out anything storing a plain integer offset.
	 *
	 * @dataProvider timezone_provider
	 *
	 * @param string $timezone Timezone identifier.
	 * @return void
	 */
	public function test_round_trip_is_lossless( $timezone ) {
		$local = '2026-03-15 09:45:00';

		$this->assertSame(
			$local,
			Meta::to_local( Meta::to_utc( $local, $timezone ), $timezone )
		);
	}

	/**
	 * Zones worth checking, including the awkward ones.
	 *
	 * @return array<string, array{string}>
	 */
	public static function timezone_provider() {
		return array(
			'UTC'              => array( 'UTC' ),
			'half-hour offset' => array( 'Asia/Kolkata' ),
			'45-minute offset' => array( 'Asia/Kathmandu' ),
			'negative offset'  => array( 'America/New_York' ),
			'southern DST'     => array( 'Australia/Sydney' ),
			'no DST'           => array( 'Asia/Tokyo' ),
		);
	}

	/**
	 * The stored UTC value does not depend on the site's timezone, which is
	 * the entire point of storing UTC.
	 *
	 * @return void
	 */
	public function test_utc_is_independent_of_the_site_timezone() {
		WP_Stub_State::$options['timezone_string'] = 'America/New_York';
		$first = Meta::to_utc( '2026-09-01 18:00:00', 'Asia/Kolkata' );

		WP_Stub_State::$options['timezone_string'] = 'Australia/Sydney';
		$second = Meta::to_utc( '2026-09-01 18:00:00', 'Asia/Kolkata' );

		$this->assertSame( $first, $second, 'Changing the site timezone must not move an event.' );
	}

	/**
	 * A time on the far side of a DST transition converts using the offset in
	 * force on that date, not today's.
	 *
	 * @return void
	 */
	public function test_dst_is_applied_per_date() {
		// New York is UTC-5 in January and UTC-4 in July.
		$this->assertSame( '2026-01-15 17:00:00', Meta::to_utc( '2026-01-15 12:00:00', 'America/New_York' ) );
		$this->assertSame( '2026-07-15 16:00:00', Meta::to_utc( '2026-07-15 12:00:00', 'America/New_York' ) );
	}

	/**
	 * Anything that is not exactly the stored format is rejected rather than
	 * coerced. A half-understood date sorts into the wrong place in silence,
	 * which is worse than having no date at all.
	 *
	 * @dataProvider bad_datetime_provider
	 *
	 * @param mixed $value Value that must not be accepted.
	 * @return void
	 */
	public function test_unusable_datetimes_are_rejected( $value ) {
		$this->assertSame( '', Meta::sanitize_datetime( $value ) );
	}

	/**
	 * Values that are not a stored datetime.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function bad_datetime_provider() {
		return array(
			'empty string'     => array( '' ),
			'whitespace'       => array( '   ' ),
			'date only'        => array( '2026-09-01' ),
			'slashes'          => array( '2026/09/01 18:00:00' ),
			'impossible month' => array( '2026-13-01 18:00:00' ),
			'impossible day'   => array( '2026-02-30 18:00:00' ),
			'text'             => array( 'next Tuesday' ),
			'null'             => array( null ),
			'array'            => array( array( '2026-09-01 18:00:00' ) ),
			'integer'          => array( 20260901 ),
		);
	}

	/**
	 * A well-formed datetime survives untouched.
	 *
	 * @return void
	 */
	public function test_valid_datetime_is_kept() {
		$this->assertSame( '2026-09-01 18:00:00', Meta::sanitize_datetime( '2026-09-01 18:00:00' ) );
	}

	/**
	 * Stored datetimes sort chronologically as plain strings, which is what
	 * lets the archive query order by meta_value with no CAST.
	 *
	 * @return void
	 */
	public function test_stored_format_sorts_chronologically_as_a_string() {
		$dates = array(
			'2026-10-01 09:00:00',
			'2026-02-01 09:00:00',
			'2026-02-01 08:59:59',
			'2027-01-01 00:00:00',
			'2026-02-10 09:00:00',
		);

		$sorted = $dates;
		sort( $sorted, SORT_STRING );

		$expected = $dates;
		usort(
			$expected,
			static function ( $a, $b ) {
				return strtotime( $a ) <=> strtotime( $b );
			}
		);

		$this->assertSame( $expected, $sorted );
	}

	/**
	 * Only real timezones are accepted.
	 *
	 * @return void
	 */
	public function test_unknown_timezones_are_rejected() {
		$this->assertSame( '', Meta::sanitize_timezone( 'Mars/Olympus_Mons' ) );
		$this->assertSame( '', Meta::sanitize_timezone( '' ) );
		$this->assertSame( 'Asia/Kolkata', Meta::sanitize_timezone( 'Asia/Kolkata' ) );
	}

	/**
	 * Sites configured with a raw offset rather than a city still have a
	 * usable timezone. Those have an empty timezone_string, so rejecting the
	 * offset form would leave them with no zone at all.
	 *
	 * @return void
	 */
	public function test_raw_utc_offsets_are_accepted() {
		$this->assertSame( '+05:30', Meta::sanitize_timezone( '+05:30' ) );
		$this->assertSame( '-08:00', Meta::sanitize_timezone( '-08:00' ) );
	}

	/**
	 * The site timezone falls back to the GMT offset when no city is set.
	 *
	 * @return void
	 */
	public function test_site_timezone_falls_back_to_the_gmt_offset() {
		WP_Stub_State::$options['timezone_string'] = '';
		WP_Stub_State::$options['gmt_offset']      = 5.5;

		$this->assertSame( '+05:30', Meta::site_timezone() );
	}

	/**
	 * A negative fractional offset keeps its sign on both parts.
	 *
	 * @return void
	 */
	public function test_negative_fractional_offset_is_formatted_correctly() {
		WP_Stub_State::$options['timezone_string'] = '';
		WP_Stub_State::$options['gmt_offset']      = -3.5;

		$this->assertSame( '-03:30', Meta::site_timezone() );
	}

	/**
	 * A named site timezone wins over the offset.
	 *
	 * @return void
	 */
	public function test_site_timezone_prefers_the_named_zone() {
		WP_Stub_State::$options['timezone_string'] = 'Europe/Berlin';
		WP_Stub_State::$options['gmt_offset']      = 0;

		$this->assertSame( 'Europe/Berlin', Meta::site_timezone() );
	}

	/**
	 * Converting an unusable value returns '' rather than the epoch, so a
	 * broken input never becomes an event in January 1970.
	 *
	 * @return void
	 */
	public function test_unusable_input_does_not_become_a_date() {
		$this->assertSame( '', Meta::to_utc( 'not a date', 'UTC' ) );
		$this->assertSame( '', Meta::to_local( '', 'UTC' ) );
	}

	/**
	 * Every meta key the plugin registers is prefixed, so nothing collides
	 * with another plugin's meta and everything is hidden from the custom
	 * fields box.
	 *
	 * @return void
	 */
	public function test_all_meta_keys_are_prefixed_and_protected() {
		foreach ( array_keys( Meta::definitions() ) as $key ) {
			$this->assertStringStartsWith( '_qevm_', $key, "Meta key {$key} is not prefixed." );
		}
	}

	/**
	 * Every definition has a callable sanitiser. An unsanitised meta key is
	 * how untrusted input reaches the database.
	 *
	 * @return void
	 */
	public function test_every_meta_key_has_a_sanitiser() {
		foreach ( Meta::definitions() as $key => $definition ) {
			$this->assertTrue(
				is_callable( $definition['sanitize'] ),
				"Meta key {$key} has no callable sanitize_callback."
			);
		}
	}

	/**
	 * Booleans coerce the way an HTML checkbox behaves.
	 *
	 * @return void
	 */
	public function test_boolean_sanitisation() {
		$this->assertTrue( Meta::sanitize_boolean( '1' ) );
		$this->assertTrue( Meta::sanitize_boolean( 1 ) );
		$this->assertTrue( Meta::sanitize_boolean( true ) );
		$this->assertFalse( Meta::sanitize_boolean( '0' ) );
		$this->assertFalse( Meta::sanitize_boolean( '' ) );
		$this->assertFalse( Meta::sanitize_boolean( null ) );
	}
}
