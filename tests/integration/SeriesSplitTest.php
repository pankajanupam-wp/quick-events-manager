<?php
/**
 * "This and following" — the series becomes two events.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\OccurrenceStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Recurrence\Series;
use QuickEventsManager\Recurrence\Splitter;
use QuickEventsManager\Registration\Repository;

/**
 * A split has to lose nothing, and two tables have to agree about it afterwards.
 *
 * The failure this guards against is not visible from either table on its own.
 * Occurrences move to the new event and stay internally consistent; bookings
 * keep naming the old one and stay internally consistent too. Nothing reports an
 * error — the attendee screen simply stops listing people who are still coming.
 * So every check here that matters compares the two.
 */
final class SeriesSplitTest extends TestCase {

	/**
	 * Switch recurrence and registration on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option(
			QEVM_OPTION_MODULES,
			array( \QuickEventsManager\Registration\RegistrationModule::ID, RecurrenceModule::ID )
		);
	}

	/**
	 * The dates end up in the right halves, and both halves are one series.
	 *
	 * @return void
	 */
	public function test_a_split_divides_the_dates_at_the_chosen_one() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 10, $dates, 'the fixture did not produce ten dates to split' );

		$copy_id = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );
		$this->assertCount( 6, OccurrenceRepository::for_event( $copy_id ) );

		$uuid = Series::for_event( $event_id );

		$this->assertNotSame( '', $uuid, 'the original lost its series identifier' );
		$this->assertSame( $uuid, Series::for_event( $copy_id ), 'the halves are not the same series' );
	}

	/**
	 * The moved dates are the same rows, not new ones that look like them.
	 *
	 * The whole reason a split re-points rather than regenerates. Regenerating
	 * gives every date after the split a new id, which orphans everything
	 * pointing at them in one statement — and a test counting dates would still
	 * pass, because the count is right either way.
	 *
	 * @return void
	 */
	public function test_the_moved_dates_keep_their_ids() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$expected = array_map( static fn( $date ) => $date->id(), array_slice( $dates, 4 ) );

		$copy_id = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );

		$moved = array_map(
			static fn( $date ) => $date->id(),
			OccurrenceRepository::for_event( $copy_id )
		);

		$this->assertSame( $expected, $moved, 'the dates after the split point were regenerated rather than moved' );
	}

	/**
	 * A booking follows its date to the new event.
	 *
	 * The cross-table check, and the one the Stage 6 gate names: after a split,
	 * every booking attached to a date names the event that date now belongs to.
	 *
	 * @return void
	 */
	public function test_a_booking_follows_its_date_and_both_tables_agree() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$stays   = $this->book_onto( $event_id, $dates[1]->id() );
		$moves   = $this->book_onto( $event_id, $dates[6]->id() );
		$copy_id = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );

		$this->assertSame( $event_id, $this->booked_event( $stays ), 'a booking before the split point changed event' );
		$this->assertSame( $copy_id, $this->booked_event( $moves ), 'a booking after the split point was left on the old event' );

		// It is still attached to the same date, which is what kept it alive.
		$this->assertSame( $dates[6]->id(), $this->booked_occurrence( $moves ) );

		$this->assertSame( array(), $this->disagreements(), 'a booking names a different event than its own date does' );
		$this->assertSame( array(), $this->orphans(), 'a booking points at a date that no longer exists' );
	}

	/**
	 * A booking not attached to any date stays where it was.
	 *
	 * Every booking made today is one of these, because picking a date on the
	 * form is C6.6 and does not exist yet. Moving them would hand somebody's
	 * place to a half of the series they never chose.
	 *
	 * @return void
	 */
	public function test_a_booking_with_no_date_stays_with_the_original() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$series_wide = $this->book_onto( $event_id, 0 );

		$copy_id = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( $event_id, $this->booked_event( $series_wide ) );
	}

	/**
	 * Saving either half afterwards changes nothing.
	 *
	 * The check that the two rules actually describe the dates each half now
	 * holds. If the new event's rule started from the wrong date, or the
	 * original's ending landed on the wrong side of the split, the next save of
	 * either one would delete rows and generate replacements — and every count
	 * above would still be right at the moment it was taken.
	 *
	 * @return void
	 */
	public function test_saving_either_half_afterwards_changes_nothing() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$copy_id  = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );

		$before_original = $this->ids_and_times( $event_id );
		$before_copy     = $this->ids_and_times( $copy_id );

		$first  = OccurrenceSync::sync( $event_id );
		$second = OccurrenceSync::sync( $copy_id );

		$this->assertSame( 0, $first['inserted'] + $first['deleted'], 'saving the first half rewrote its dates' );
		$this->assertSame( 0, $second['inserted'] + $second['deleted'], 'saving the second half rewrote its dates' );

		$this->assertSame( $before_original, $this->ids_and_times( $event_id ) );
		$this->assertSame( $before_copy, $this->ids_and_times( $copy_id ) );
	}

	/**
	 * A COUNT is divided between the halves rather than copied to both.
	 *
	 * Ten weeks split at the fifth is four weeks and six weeks. Copying the
	 * count whole gives thirteen, and the organiser finds out from whoever turns
	 * up to the eleventh.
	 *
	 * @return void
	 */
	public function test_a_count_is_divided_rather_than_copied() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$copy_id  = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );

		OccurrenceSync::sync( $event_id );
		OccurrenceSync::sync( $copy_id );

		$total = count( OccurrenceRepository::for_event( $event_id ) )
			+ count( OccurrenceRepository::for_event( $copy_id ) );

		$this->assertSame( 10, $total, 'the series gained or lost dates across the split' );
	}

	/**
	 * An edited date inside the moved range keeps its edit.
	 *
	 * It was an exception to a rule that now belongs to a different post, and
	 * the override is still what the organiser asked for.
	 *
	 * @return void
	 */
	public function test_an_exception_survives_the_split_and_the_save_after_it() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[6];
		$slot     = $target->recurrence_id();

		$moved_to = $this->move( $target->id(), '+2 days' );
		$copy_id  = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );

		OccurrenceSync::sync( $copy_id );

		$survivor = OccurrenceRepository::find( $target->id() );

		$this->assertNotNull( $survivor, 'the edited date was lost in the split' );
		$this->assertSame( $copy_id, $survivor->event_id() );
		$this->assertSame( $moved_to, $survivor->start_utc(), 'the edited date lost its time' );
		$this->assertSame( $slot, $survivor->recurrence_id(), 'the edited date lost the slot it came from' );
		$this->assertTrue( $survivor->is_exception() );
		$this->assertSame( OccurrenceStatus::Moved, $survivor->status() );
	}

	/**
	 * Splitting at a date that was moved uses the slot, not where it was moved to.
	 *
	 * A date moved to the Thursday is still the Tuesday the rule produced.
	 * Starting the new half from the Thursday would shift every date after it.
	 *
	 * @return void
	 */
	public function test_the_new_half_starts_from_the_slot_not_the_moved_time() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[4];
		$slot     = $target->recurrence_id();

		$this->move( $target->id(), '+2 days' );

		$copy_id = Splitter::split( $target->id() );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( $slot, get_post_meta( $copy_id, Meta::START_UTC, true ) );
	}

	/**
	 * A date moved back past the split point still belongs to the new half.
	 *
	 * The reason the halves are divided by slot rather than by time. The sixth
	 * week moved back three weeks sits, as a time, before the fifth — so
	 * partitioning on `start_utc` leaves it with the original event while the
	 * rule that produces it belongs to the new one. The next save of either half
	 * then deletes it, and this is the only test here that would notice.
	 *
	 * @return void
	 */
	public function test_a_date_moved_before_the_split_still_belongs_to_the_new_half() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[5];

		$moved_to = $this->move( $target->id(), '-3 weeks' );

		$this->assertLessThan(
			$dates[4]->start_utc(),
			$moved_to,
			'the fixture did not move the date before the split point, so it proves nothing'
		);

		$copy_id = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );

		$survivor = OccurrenceRepository::find( $target->id() );

		$this->assertNotNull( $survivor );
		$this->assertSame( $copy_id, $survivor->event_id(), 'the date went to the half its time suggested, not the half its slot did' );

		// And it survives both halves being saved.
		OccurrenceSync::sync( $event_id );
		OccurrenceSync::sync( $copy_id );

		$this->assertNotNull( OccurrenceRepository::find( $target->id() ), 'the misplaced date was deleted by the next save' );
	}

	/**
	 * The new half is the same event, not a draft called "(copy)".
	 *
	 * @return void
	 */
	public function test_the_new_half_keeps_the_title_and_the_status() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$copy_id = Splitter::split( $dates[4]->id() );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( get_post_field( 'post_title', $event_id ), get_post_field( 'post_title', $copy_id ) );
		$this->assertSame( 'publish', get_post_status( $copy_id ) );
	}

	/**
	 * Splitting at the first date is refused.
	 *
	 * It would leave an empty event holding the bookings of every date that used
	 * to be its own.
	 *
	 * @return void
	 */
	public function test_splitting_at_the_first_date_is_refused() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$result = Splitter::split( $dates[0]->id() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_split_at_start', $result->get_error_code() );
		$this->assertCount( 10, OccurrenceRepository::for_event( $event_id ), 'a refused split still moved dates' );
	}

	/**
	 * An event that does not repeat has nothing to split.
	 *
	 * @return void
	 */
	public function test_a_one_off_event_cannot_be_split() {
		$event_id    = $this->make_event();
		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $occurrences, 'the fixture has no date to try this on' );

		$result = Splitter::split( $occurrences[0]->id() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_split_not_a_series_date', $result->get_error_code() );
	}

	/**
	 * With recurrence switched off there is no series to split.
	 *
	 * @return void
	 */
	public function test_the_module_gate_refuses_a_split() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=10' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		update_option( QEVM_OPTION_MODULES, array() );

		$result = Splitter::split( $dates[4]->id() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_split_no_module', $result->get_error_code() );
	}

	/**
	 * A recurring event with dates on it.
	 *
	 * @param string $rrule The rule.
	 * @return int Event id.
	 */
	private function make_series( $rrule ) {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, Meta::START_LOCAL, '2026-06-02 18:00:00' );
		update_post_meta( $event_id, Meta::END_LOCAL, '2026-06-02 19:00:00' );
		update_post_meta( $event_id, Meta::START_UTC, '2026-06-02 18:00:00' );
		update_post_meta( $event_id, Meta::END_UTC, '2026-06-02 19:00:00' );
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, $rrule );

		OccurrenceSync::sync( $event_id );

		return $event_id;
	}

	/**
	 * Move one date, the way C6.4b does.
	 *
	 * @param int    $id       Occurrence id.
	 * @param string $modifier A strtotime modifier.
	 * @return string The new UTC start.
	 */
	private function move( $id, $modifier ) {
		$occurrence = OccurrenceRepository::find( $id );

		$this->assertNotNull( $occurrence );

		$start = gmdate( 'Y-m-d H:i:s', strtotime( $occurrence->start_utc() . ' UTC ' . $modifier ) );
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $occurrence->end_utc() . ' UTC ' . $modifier ) );

		OccurrenceRepository::update(
			$id,
			array(
				'start_utc'    => $start,
				'end_utc'      => $end,
				'start_local'  => $start,
				'end_local'    => $end,
				'is_exception' => 1,
				'status'       => OccurrenceStatus::Moved->value,
			)
		);

		return $start;
	}

	/**
	 * A booking, written straight to the table.
	 *
	 * Inserted rather than booked through the service because nothing yet writes
	 * `occurrence_id` on a booking — picking a date on the form is C6.6. What a
	 * split does with a booking that has one is real and testable regardless of
	 * how the row got there.
	 *
	 * @param int $event_id      Event id.
	 * @param int $occurrence_id Occurrence id, or 0 for a booking with no date.
	 * @return int Registration id.
	 */
	private function book_onto( $event_id, $occurrence_id ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture for a booking attached to a date.
		$wpdb->insert(
			Repository::table(),
			array(
				'event_id'      => (int) $event_id,
				'occurrence_id' => (int) $occurrence_id,
				'code'          => Repository::generate_code(),
				'status'        => RegistrationStatus::Confirmed->value,
				'quantity'      => 1,
				'booker_name'   => 'Attendee',
				'booker_email'  => 'attendee@example.com',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The event a booking names.
	 *
	 * @param int $registration_id Registration id.
	 * @return int
	 */
	private function booked_event( $registration_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading one fixture row back.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT event_id FROM %i WHERE id = %d', Repository::table(), (int) $registration_id )
		);
	}

	/**
	 * The date a booking names.
	 *
	 * @param int $registration_id Registration id.
	 * @return int
	 */
	private function booked_occurrence( $registration_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading one fixture row back.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE id = %d', Repository::table(), (int) $registration_id )
		);
	}

	/**
	 * Every booking whose event disagrees with its own date's event.
	 *
	 * The invariant a split has to leave standing. Empty is the only acceptable
	 * answer, and it is checked by asking both tables at once rather than by
	 * trusting either.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function disagreements() {
		global $wpdb;

		$registrations = Repository::table();
		$occurrences   = OccurrenceRepository::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An integrity check across two tables, which is the point of the test.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.event_id AS booked_event, o.event_id AS date_event
				 FROM %i r
				 INNER JOIN %i o ON o.id = r.occurrence_id
				 WHERE r.occurrence_id > 0 AND r.event_id <> o.event_id',
				$registrations,
				$occurrences
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Every booking whose date has stopped existing.
	 *
	 * The check `disagreements()` cannot make. Its join only sees bookings whose
	 * occurrence is still there, so a split that deleted the dates and generated
	 * replacements — the exact failure re-pointing prevents — leaves it with
	 * nothing to compare and reporting no disagreement at all. Sabotaging the
	 * re-point is what showed that; without this, three tests noticed and the
	 * one named after cross-table integrity did not.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function orphans() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An integrity check across two tables, which is the point of the test.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.occurrence_id
				 FROM %i r
				 LEFT JOIN %i o ON o.id = r.occurrence_id
				 WHERE r.occurrence_id > 0 AND o.id IS NULL',
				Repository::table(),
				OccurrenceRepository::table()
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * An event's dates, as id => start, for comparing before and after.
	 *
	 * @param int $event_id Event id.
	 * @return array<int, string>
	 */
	private function ids_and_times( $event_id ) {
		$map = array();

		foreach ( OccurrenceRepository::for_event( $event_id ) as $occurrence ) {
			$map[ $occurrence->id() ] = $occurrence->start_utc();
		}

		return $map;
	}
}
