<?php
/**
 * Reading, writing and refusing recurrence rules.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Domain\Frequency;
use QuickEventsManager\Domain\Weekday;
use QuickEventsManager\Recurrence\Byday;
use QuickEventsManager\Recurrence\Rule;

/**
 * A rule is stored as text, so it can arrive malformed.
 *
 * Most of these are about refusing rather than accepting. A rule that is read
 * generously — an unknown FREQ becoming daily, a bad weekday being dropped from
 * the list — produces a series of dates nobody asked for, and the organiser has
 * to delete them one at a time.
 */
#[CoversClass( Rule::class )]
#[CoversClass( Byday::class )]
#[CoversClass( Frequency::class )]
#[CoversClass( Weekday::class )]
final class RecurrenceRuleTest extends TestCase {

	/**
	 * Reset the stubbed WordPress between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		\WP_Stub_State::reset();
	}

	/**
	 * A rule survives a round trip through storage unchanged.
	 *
	 * @param string $rrule Rule as stored.
	 * @return void
	 */
	#[DataProvider( 'round_trip_rules' )]
	public function test_a_rule_survives_a_round_trip( $rrule ) {
		$rule = Rule::from_string( $rrule );

		$this->assertNotNull( $rule, $rrule . ' did not parse' );
		$this->assertTrue( true === $rule->validate(), $rrule . ' did not validate' );
		$this->assertSame( $rrule, $rule->to_string() );
	}

	/**
	 * Rules that must come out exactly as they went in.
	 *
	 * @return array<string, array{string}>
	 */
	public static function round_trip_rules() {
		return array(
			'plain weekly'         => array( 'FREQ=WEEKLY' ),
			'weekly on two days'   => array( 'FREQ=WEEKLY;BYDAY=MO,TH' ),
			'fortnightly'          => array( 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU' ),
			'monthly by date'      => array( 'FREQ=MONTHLY;BYMONTHDAY=15' ),
			'monthly last friday'  => array( 'FREQ=MONTHLY;BYDAY=-1FR' ),
			'monthly second tue'   => array( 'FREQ=MONTHLY;BYDAY=2TU' ),
			'daily with a count'   => array( 'FREQ=DAILY;COUNT=10' ),
			'yearly in one month'  => array( 'FREQ=YEARLY;BYMONTH=6' ),
			'weekly until a date'  => array( 'FREQ=WEEKLY;UNTIL=20261231T180000Z;BYDAY=WE' ),
			'day counted from end' => array( 'FREQ=MONTHLY;BYMONTHDAY=-1' ),
		);
	}

	/**
	 * An unreadable rule is null, never a rule with defaults filled in.
	 *
	 * @param string $rrule Rule as stored.
	 * @return void
	 */
	#[DataProvider( 'unreadable_rules' )]
	public function test_an_unreadable_rule_is_refused( $rrule ) {
		$this->assertNull( Rule::from_string( $rrule ), $rrule . ' was read as a rule' );
	}

	/**
	 * Rules that must not parse at all.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unreadable_rules() {
		return array(
			'empty'                 => array( '' ),
			'whitespace'            => array( '   ' ),
			'no frequency'          => array( 'INTERVAL=2;BYDAY=MO' ),
			'misspelled frequency'  => array( 'FREQ=WEEKLYY' ),
			'frequency we refuse'   => array( 'FREQ=HOURLY' ),
			'nonsense'              => array( 'every tuesday please' ),
			'a bad weekday'         => array( 'FREQ=WEEKLY;BYDAY=MO,XX,FR' ),
			'a zeroth weekday'      => array( 'FREQ=MONTHLY;BYDAY=0TU' ),
			'a weekday too far out' => array( 'FREQ=MONTHLY;BYDAY=6TU' ),
		);
	}

	/**
	 * One bad weekday fails the whole rule rather than being dropped.
	 *
	 * The case worth its own test. Dropping the unreadable entry leaves a rule
	 * that parses, validates and generates the wrong dates — Mondays and Fridays
	 * for a rule that also said something the parser could not read — and nothing
	 * anywhere says so.
	 *
	 * @return void
	 */
	public function test_one_bad_weekday_fails_the_whole_rule() {
		$this->assertNull( Rule::from_string( 'FREQ=WEEKLY;BYDAY=MO,XX,FR' ) );

		$good = Rule::from_string( 'FREQ=WEEKLY;BYDAY=MO,FR' );

		$this->assertNotNull( $good, 'the same rule without the bad entry should parse' );
		$this->assertCount( 2, $good->byday() );
	}

	/**
	 * A rule may be readable and still not allowed.
	 *
	 * @param string $rrule    Rule as stored.
	 * @param string $expected Expected error code.
	 * @return void
	 */
	#[DataProvider( 'invalid_rules' )]
	public function test_a_readable_but_wrong_rule_is_refused( $rrule, $expected ) {
		$rule = Rule::from_string( $rrule );

		$this->assertNotNull( $rule, $rrule . ' should parse before it is judged' );

		$result = $rule->validate();

		$this->assertInstanceOf( \WP_Error::class, $result, $rrule . ' was accepted' );
		$this->assertSame( $expected, $result->get_error_code() );
		$this->assertNotSame( '', $result->get_error_message(), 'the refusal said nothing about why' );
	}

	/**
	 * Rules that parse and must not be acted on.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_rules() {
		return array(
			'a position on a weekly rule' => array( 'FREQ=WEEKLY;BYDAY=2TU', 'qevm_ordinal_not_allowed' ),
			'two different endings'       => array( 'FREQ=WEEKLY;COUNT=5;UNTIL=20261231T180000Z', 'qevm_two_endings' ),
			'more dates than allowed'     => array( 'FREQ=DAILY;COUNT=5000', 'qevm_count_too_large' ),
			'an impossible interval'      => array( 'FREQ=DAILY;INTERVAL=100000', 'qevm_interval_too_large' ),
			'day zero of the month'       => array( 'FREQ=MONTHLY;BYMONTHDAY=0', 'qevm_bad_monthday' ),
			'day 32 of the month'         => array( 'FREQ=MONTHLY;BYMONTHDAY=32', 'qevm_bad_monthday' ),
			'a thirteenth month'          => array( 'FREQ=YEARLY;BYMONTH=13', 'qevm_bad_month' ),
			'a date and a weekday'        => array( 'FREQ=MONTHLY;BYMONTHDAY=15;BYDAY=TU', 'qevm_day_conflict' ),
		);
	}

	/**
	 * "The 15th" and "the second Tuesday" cannot both be asked for.
	 *
	 * RFC 5545 does define this combination — it keeps only the dates satisfying
	 * both — and nobody setting up a monthly meetup means it. A series with
	 * unexplained gaps in it looks like a bug in the plugin.
	 *
	 * @return void
	 */
	public function test_a_date_and_a_weekday_cannot_both_be_asked_for() {
		$conflict = Rule::from_string( 'FREQ=MONTHLY;BYMONTHDAY=15;BYDAY=TU' );

		$this->assertInstanceOf( \WP_Error::class, $conflict->validate() );

		// Either alone is fine.
		$this->assertTrue( true === Rule::from_string( 'FREQ=MONTHLY;BYMONTHDAY=15' )->validate() );
		$this->assertTrue( true === Rule::from_string( 'FREQ=MONTHLY;BYDAY=2TU' )->validate() );

		/*
		 * And a weekly rule with both is untouched by the check, because a weekly
		 * rule has no BYMONTHDAY to conflict with in the first place.
		 */
		$this->assertTrue( true === Rule::from_string( 'FREQ=WEEKLY;BYDAY=TU' )->validate() );
	}

	/**
	 * An UNTIL with no time covers the whole of that day.
	 *
	 * Read as midnight it would exclude the rule's own last occurrence, so a
	 * weekly series "until 31 December" whose last date is the 31st would quietly
	 * lose it.
	 *
	 * @return void
	 */
	public function test_a_date_only_until_covers_the_whole_day() {
		$rule = Rule::from_string( 'FREQ=WEEKLY;UNTIL=20261231' );

		$this->assertNotNull( $rule );
		$this->assertSame( '2026-12-31 23:59:59', $rule->until() );
	}

	/**
	 * A full UNTIL timestamp is read as the instant it names.
	 *
	 * @return void
	 */
	public function test_a_full_until_keeps_its_time() {
		$rule = Rule::from_string( 'FREQ=WEEKLY;UNTIL=20261231T180000Z' );

		$this->assertNotNull( $rule );
		$this->assertSame( '2026-12-31 18:00:00', $rule->until() );
	}

	/**
	 * An RRULE arriving with its property name is still read.
	 *
	 * @return void
	 */
	public function test_an_rrule_prefix_is_tolerated() {
		$rule = Rule::from_string( 'RRULE:FREQ=WEEKLY;BYDAY=MO' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO', $rule->to_string() );
	}

	/**
	 * Lower case and stray spacing come back canonical.
	 *
	 * What makes "did the rule change" answerable by comparing two strings.
	 *
	 * @return void
	 */
	public function test_a_rule_is_normalised_on_the_way_out() {
		$rule = Rule::from_string( ' freq=weekly ; interval=2 ; byday= mo , th ' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH', $rule->to_string() );
	}

	/**
	 * An interval of 1 is left out, because it is the default.
	 *
	 * @return void
	 */
	public function test_a_default_interval_is_not_written() {
		$this->assertSame( 'FREQ=WEEKLY', Rule::from_string( 'FREQ=WEEKLY;INTERVAL=1' )->to_string() );
	}

	/**
	 * A rule knows whether it says for itself when to stop.
	 *
	 * @return void
	 */
	public function test_a_rule_reports_whether_it_ends() {
		$this->assertFalse( Rule::from_string( 'FREQ=WEEKLY' )->has_own_ending() );
		$this->assertTrue( Rule::from_string( 'FREQ=WEEKLY;COUNT=5' )->has_own_ending() );
		$this->assertTrue( Rule::from_string( 'FREQ=WEEKLY;UNTIL=20261231' )->has_own_ending() );
	}

	/**
	 * The ceiling is stated once and read from there.
	 *
	 * @return void
	 */
	public function test_the_ceiling_is_the_documented_one() {
		$this->assertSame( 730, Rule::MAX_OCCURRENCES, 'docs/recurrence.md §5.1 says 730' );

		$at_ceiling = Rule::from_string( 'FREQ=DAILY;COUNT=' . Rule::MAX_OCCURRENCES );

		$this->assertTrue( true === $at_ceiling->validate(), 'the ceiling itself must be allowed' );

		$over = Rule::from_string( 'FREQ=DAILY;COUNT=' . ( Rule::MAX_OCCURRENCES + 1 ) );

		$this->assertInstanceOf( \WP_Error::class, $over->validate() );
	}

	/**
	 * A rough yearly count is offered for warning before a save.
	 *
	 * @return void
	 */
	public function test_the_approximate_yearly_count_tracks_the_rule() {
		$this->assertSame( 365, Rule::from_string( 'FREQ=DAILY' )->approximate_yearly_count() );
		$this->assertSame( 52, Rule::from_string( 'FREQ=WEEKLY' )->approximate_yearly_count() );
		$this->assertSame( 26, Rule::from_string( 'FREQ=WEEKLY;INTERVAL=2' )->approximate_yearly_count() );
		$this->assertSame( 12, Rule::from_string( 'FREQ=MONTHLY' )->approximate_yearly_count() );
		$this->assertSame( 1, Rule::from_string( 'FREQ=YEARLY' )->approximate_yearly_count() );

		// Two days a week is twice a week, not once.
		$this->assertSame( 104, Rule::from_string( 'FREQ=WEEKLY;BYDAY=MO,TH' )->approximate_yearly_count() );
	}

	/**
	 * A rule describes itself in a sentence.
	 *
	 * @return void
	 */
	public function test_a_rule_describes_itself() {
		$this->assertSame( 'Every week', Rule::from_string( 'FREQ=WEEKLY' )->describe() );
		$this->assertSame( 'Every 2 weeks', Rule::from_string( 'FREQ=WEEKLY;INTERVAL=2' )->describe() );
		$this->assertSame( 'Every day, 10 times', Rule::from_string( 'FREQ=DAILY;COUNT=10' )->describe() );
		$this->assertSame(
			'Every month on the last Friday',
			Rule::from_string( 'FREQ=MONTHLY;BYDAY=-1FR' )->describe()
		);
		$this->assertSame(
			'Every week on Monday and Thursday',
			Rule::from_string( 'FREQ=WEEKLY;BYDAY=MO,TH' )->describe()
		);
	}

	/**
	 * A single weekday reads as one name, with no stray conjunction.
	 *
	 * @return void
	 */
	public function test_one_weekday_is_not_joined_to_anything() {
		$described = Rule::from_string( 'FREQ=WEEKLY;BYDAY=WE' )->describe();

		$this->assertSame( 'Every week on Wednesday', $described );
		$this->assertStringNotContainsString( 'and', $described );
	}

	/**
	 * Which frequencies allow "the second Tuesday".
	 *
	 * @return void
	 */
	public function test_only_monthly_and_yearly_allow_a_weekday_position() {
		$this->assertFalse( Frequency::Daily->allows_weekday_ordinals() );
		$this->assertFalse( Frequency::Weekly->allows_weekday_ordinals() );
		$this->assertTrue( Frequency::Monthly->allows_weekday_ordinals() );
		$this->assertTrue( Frequency::Yearly->allows_weekday_ordinals() );
	}

	/**
	 * An unknown frequency is null, not a default.
	 *
	 * @return void
	 */
	public function test_an_unknown_frequency_has_no_fallback() {
		$this->assertNull( Frequency::coerce( 'FORTNIGHTLY' ) );
		$this->assertNull( Frequency::coerce( '' ) );
		$this->assertSame( Frequency::Weekly, Frequency::coerce( 'weekly' ) );
	}

	/**
	 * PHP's two weekday numberings disagree about Sunday, and both are covered.
	 *
	 * A one-day error here only appears at the weekend, which is when a weekly
	 * event is most likely to be scheduled.
	 *
	 * @return void
	 */
	public function test_both_php_weekday_numberings_round_trip() {
		foreach ( Weekday::all() as $day ) {
			$this->assertSame( $day, Weekday::from_php_w( $day->to_php_w() ), $day->value . ' lost its w number' );
		}

		$this->assertSame( 0, Weekday::Sunday->to_php_w(), "PHP's w counts Sunday as 0" );
		$this->assertSame( 7, Weekday::Sunday->to_php_n(), "PHP's N counts Sunday as 7" );
		$this->assertSame( 1, Weekday::Monday->to_php_w() );
		$this->assertSame( 1, Weekday::Monday->to_php_n() );

		$this->assertNull( Weekday::from_php_w( 7 ) );
		$this->assertNull( Weekday::from_php_w( -1 ) );
	}

	/**
	 * A weekday numbering agrees with PHP itself, not just with its own inverse.
	 *
	 * The round trip above passes even if every number is wrong by the same
	 * amount. This checks against a date whose weekday is a matter of record.
	 *
	 * @return void
	 */
	public function test_the_weekday_numbering_agrees_with_php() {
		// 2026-08-17 is a Monday.
		$monday = strtotime( '2026-08-17 12:00:00 UTC' );

		$this->assertSame( 'Mon', gmdate( 'D', $monday ), 'the fixture date is not the weekday it claims' );
		$this->assertSame( (int) gmdate( 'w', $monday ), Weekday::Monday->to_php_w() );
		$this->assertSame( (int) gmdate( 'N', $monday ), Weekday::Monday->to_php_n() );

		$sunday = strtotime( '2026-08-16 12:00:00 UTC' );

		$this->assertSame( 'Sun', gmdate( 'D', $sunday ) );
		$this->assertSame( (int) gmdate( 'w', $sunday ), Weekday::Sunday->to_php_w() );
		$this->assertSame( (int) gmdate( 'N', $sunday ), Weekday::Sunday->to_php_n() );
	}

	/**
	 * The display order follows the site's week start.
	 *
	 * @return void
	 */
	public function test_the_display_order_follows_the_site_setting() {
		update_option( 'start_of_week', 1 );

		$this->assertSame( Weekday::Monday, Weekday::in_display_order()[0] );

		update_option( 'start_of_week', 0 );

		$this->assertSame( Weekday::Sunday, Weekday::in_display_order()[0] );
		$this->assertCount( 7, Weekday::in_display_order() );

		update_option( 'start_of_week', 6 );

		$this->assertSame( Weekday::Saturday, Weekday::in_display_order()[0] );
		$this->assertCount( 7, Weekday::in_display_order() );
	}

	/**
	 * The rule order is unaffected by the display order.
	 *
	 * A stored rule must not change because somebody changed a display setting.
	 *
	 * @return void
	 */
	public function test_the_stored_order_ignores_the_site_setting() {
		update_option( 'start_of_week', 0 );

		$this->assertSame( Weekday::Monday, Weekday::all()[0] );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,TH', Rule::from_string( 'FREQ=WEEKLY;BYDAY=MO,TH' )->to_string() );
	}

	/**
	 * A BYDAY entry reads and writes both forms.
	 *
	 * @return void
	 */
	public function test_a_byday_entry_round_trips() {
		$plain = Byday::from_string( 'TU' );

		$this->assertNotNull( $plain );
		$this->assertFalse( $plain->has_position() );
		$this->assertSame( 'TU', $plain->to_string() );
		$this->assertSame( Weekday::Tuesday, $plain->weekday() );

		$second = Byday::from_string( '2TU' );

		$this->assertNotNull( $second );
		$this->assertTrue( $second->has_position() );
		$this->assertSame( 2, $second->position() );
		$this->assertSame( '2TU', $second->to_string() );

		$last = Byday::from_string( '-1FR' );

		$this->assertNotNull( $last );
		$this->assertSame( -1, $last->position() );
		$this->assertSame( '-1FR', $last->to_string() );

		// A written-out plus sign is accepted and dropped on the way out.
		$plus = Byday::from_string( '+2WE' );

		$this->assertNotNull( $plus );
		$this->assertSame( '2WE', $plus->to_string() );
	}

	/**
	 * A position describes itself in words.
	 *
	 * @return void
	 */
	public function test_a_byday_position_describes_itself() {
		$this->assertSame( 'the second Tuesday', Byday::from_string( '2TU' )->describe() );
		$this->assertSame( 'the last Friday', Byday::from_string( '-1FR' )->describe() );
		$this->assertSame( 'Wednesday', Byday::from_string( 'WE' )->describe() );
	}
}
