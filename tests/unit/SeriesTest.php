<?php
/**
 * The identifier that survives a series being split, and the meta it lives in.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Recurrence\Rule;
use QuickEventsManager\Recurrence\Series;

/**
 * A uuid that gets reassigned separates a series from its other half.
 */
#[CoversClass( Series::class )]
#[CoversClass( Meta::class )]
final class SeriesTest extends TestCase {

	/**
	 * Reset the stubbed WordPress between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		\WP_Stub_State::reset();
	}

	/**
	 * An event with no recurrence has no series identifier.
	 *
	 * A non-recurring event is not a series of one. Giving it a uuid would put
	 * every ordinary event through the recurrence code paths.
	 *
	 * @return void
	 */
	public function test_an_ordinary_event_has_no_series() {
		$this->assertSame( '', Series::for_event( 7 ) );
		$this->assertFalse( Series::is_recurring( 7 ) );
		$this->assertNull( Series::rule_for_event( 7 ) );
	}

	/**
	 * Minting gives an event a uuid, and twice gives the same one.
	 *
	 * @return void
	 */
	public function test_ensure_is_idempotent() {
		$first = Series::ensure( 7 );

		$this->assertNotSame( '', $first );
		$this->assertTrue( wp_is_uuid( $first, 4 ), $first . ' is not a v4 uuid' );

		$this->assertSame( $first, Series::ensure( 7 ), 'a second call minted a new identifier' );
		$this->assertSame( $first, Series::for_event( 7 ) );
	}

	/**
	 * Two events get two different identifiers.
	 *
	 * @return void
	 */
	public function test_two_events_get_different_identifiers() {
		$this->assertNotSame( Series::ensure( 7 ), Series::ensure( 8 ) );
	}

	/**
	 * A split copies the identifier so both halves share it.
	 *
	 * The property the Stage 6 gate checks.
	 *
	 * @return void
	 */
	public function test_a_split_shares_the_identifier() {
		$original = Series::ensure( 7 );

		$this->assertSame( $original, Series::copy( 7, 8 ) );
		$this->assertSame( $original, Series::for_event( 8 ) );
		$this->assertSame( Series::for_event( 7 ), Series::for_event( 8 ) );
	}

	/**
	 * Copying from an event with no series does nothing.
	 *
	 * @return void
	 */
	public function test_copying_nothing_gives_nothing() {
		$this->assertSame( '', Series::copy( 7, 8 ) );
		$this->assertSame( '', Series::for_event( 8 ) );
	}

	/**
	 * Ensuring never overwrites, even after a split.
	 *
	 * Reassigning would silently separate the two halves of a split series with
	 * nothing on screen to say why "all in this series" had stopped reaching both.
	 *
	 * @return void
	 */
	public function test_ensure_never_reassigns_after_a_split() {
		$shared = Series::ensure( 7 );

		Series::copy( 7, 8 );

		$this->assertSame( $shared, Series::ensure( 8 ), 'the second half was given a new identifier' );
		$this->assertSame( Series::for_event( 7 ), Series::for_event( 8 ) );
	}

	/**
	 * Switching recurrence off takes the identifier away.
	 *
	 * @return void
	 */
	public function test_forgetting_removes_the_identifier() {
		Series::ensure( 7 );
		Series::forget( 7 );

		$this->assertSame( '', Series::for_event( 7 ) );
	}

	/**
	 * A stored rule is read back through one place.
	 *
	 * @return void
	 */
	public function test_a_stored_rule_is_read_back() {
		update_post_meta( 7, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;BYDAY=TU' );

		$rule = Series::rule_for_event( 7 );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=TU', $rule->to_string() );
		$this->assertTrue( Series::is_recurring( 7 ) );
	}

	/**
	 * A stored rule that cannot be read leaves the event non-recurring.
	 *
	 * Rather than throwing, or returning a partly-filled rule. An event whose
	 * rule is unreadable is an event with no working recurrence, and every caller
	 * should see that rather than each deciding for itself.
	 *
	 * @return void
	 */
	public function test_an_unreadable_stored_rule_leaves_the_event_alone() {
		update_post_meta( 7, Meta::RECURRENCE_RULE, 'FREQ=NONSENSE' );

		$this->assertNull( Series::rule_for_event( 7 ) );
		$this->assertFalse( Series::is_recurring( 7 ) );
	}

	/**
	 * A stored rule that parses but is not allowed is treated the same way.
	 *
	 * @return void
	 */
	public function test_a_stored_but_invalid_rule_is_not_used() {
		// Readable, and refused: a weekly rule cannot name the second Tuesday.
		update_post_meta( 7, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;BYDAY=2TU' );

		$this->assertNotNull( Rule::from_string( 'FREQ=WEEKLY;BYDAY=2TU' ), 'the fixture should parse' );
		$this->assertNull( Series::rule_for_event( 7 ), 'an invalid rule was acted on' );
	}

	/**
	 * The sanitiser stores the canonical form of a rule.
	 *
	 * What makes "did the rule change" answerable by comparing two strings.
	 *
	 * @return void
	 */
	public function test_the_sanitiser_canonicalises_a_rule() {
		$this->assertSame(
			'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH',
			Meta::sanitize_rrule( ' freq=weekly ; interval=2 ; byday= mo , th ' )
		);
	}

	/**
	 * The sanitiser stores nothing rather than something unusable.
	 *
	 * A value that sits in the database looking like a rule and generates nothing
	 * gives the organiser an event that repeats in the editor and not on the site.
	 *
	 * @return void
	 */
	public function test_the_sanitiser_refuses_what_cannot_be_used() {
		$this->assertSame( '', Meta::sanitize_rrule( 'FREQ=NONSENSE' ) );
		$this->assertSame( '', Meta::sanitize_rrule( 'FREQ=WEEKLY;BYDAY=2TU' ) );
		$this->assertSame( '', Meta::sanitize_rrule( 'FREQ=WEEKLY;COUNT=5;UNTIL=20261231' ) );
		$this->assertSame( '', Meta::sanitize_rrule( '' ) );
		$this->assertSame( '', Meta::sanitize_rrule( array( 'not', 'a', 'string' ) ) );
	}

	/**
	 * The uuid sanitiser keeps only real v4 uuids.
	 *
	 * @return void
	 */
	public function test_the_uuid_sanitiser_refuses_anything_else() {
		$real = wp_generate_uuid4();

		$this->assertSame( $real, Meta::sanitize_uuid( $real ) );
		$this->assertSame( $real, Meta::sanitize_uuid( strtoupper( $real ) ), 'case was not normalised' );
		$this->assertSame( '', Meta::sanitize_uuid( 'not-a-uuid' ) );
		$this->assertSame( '', Meta::sanitize_uuid( '' ) );
		$this->assertSame( '', Meta::sanitize_uuid( 12345 ) );
	}

	/**
	 * Both new meta keys are registered with a one-argument sanitiser.
	 *
	 * WordPress calls a sanitise callback as `( $value, $meta_key, $meta_type )`.
	 * A callback declaring a second parameter receives the meta key in it, which
	 * is how `'sanitize_callback' => 'sanitize_title'` fatals a REST route — the
	 * bug locked down in RegressionTest. These two are new callbacks and get the
	 * same check.
	 *
	 * @return void
	 */
	public function test_the_new_sanitisers_take_one_argument() {
		$definitions = Meta::definitions();

		foreach ( array( Meta::RECURRENCE_RULE, Meta::SERIES_UUID ) as $key ) {
			$this->assertArrayHasKey( $key, $definitions, $key . ' is not registered' );

			$callback = $definitions[ $key ]['sanitize'];

			$reflection = is_array( $callback )
				? new \ReflectionMethod( $callback[0], $callback[1] )
				: new \ReflectionFunction( $callback );

			$this->assertLessThanOrEqual(
				1,
				$reflection->getNumberOfParameters(),
				$key . "'s sanitiser declares a second parameter, which WordPress fills with the meta key"
			);
		}
	}
}
