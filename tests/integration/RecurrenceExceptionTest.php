<?php
/**
 * What happens to a date somebody has changed by hand.
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
use QuickEventsManager\Registration\Repository;

/**
 * The bug C6.1 found, and the rules that stop it coming back.
 *
 * Every one of these acts on an occurrence and then does something *else* before
 * looking at it. That is the whole point: the original defect was invisible to
 * any test that acted and then looked immediately, because in that window the
 * table and the rule still agreed.
 */
final class RecurrenceExceptionTest extends TestCase {

	/**
	 * Switch recurrence on.
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
	 * A moved date survives an unrelated save of the event.
	 *
	 * **The Stage 6 gate check C6.1 added.** Before the reconciler matched on
	 * `recurrence_id`, editing the event's title deleted the moved row and
	 * inserted a fresh one at the original time — and the sync afterwards
	 * truthfully reported `unchanged`, because by then the table and the rule did
	 * agree.
	 *
	 * @return void
	 */
	public function test_a_moved_date_survives_an_unrelated_save() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );

		$occurrences = OccurrenceRepository::for_event( $event_id );
		$target      = $occurrences[1];
		$slot        = $target->recurrence_id();

		$this->assertNotSame( '', $slot, 'the fixture row has no slot to be recognised by' );

		$moved = $this->move( $target->id(), '+1 day' );

		// The unrelated edit: the title, nothing to do with dates.
		wp_update_post(
			array(
				'ID'         => $event_id,
				'post_title' => 'Renamed after the move',
			)
		);

		$survivor = OccurrenceRepository::find( $target->id() );

		$this->assertNotNull( $survivor, 'the moved row was deleted by an unrelated save' );
		$this->assertSame( $moved, $survivor->start_utc(), 'the moved row lost its new time' );
		$this->assertSame( $slot, $survivor->recurrence_id(), 'the moved row lost its slot' );
		$this->assertTrue( $survivor->is_exception() );
		$this->assertSame( OccurrenceStatus::Moved, $survivor->status() );

		// And the rest of the series is untouched: four dates, not five.
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * Moving one date leaves the other fifty-one alone.
	 *
	 * The gate's wording, with the numbers it names.
	 *
	 * @return void
	 */
	public function test_moving_one_date_leaves_the_rest_untouched() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=52' );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 52, $occurrences );

		$before = array();

		foreach ( $occurrences as $occurrence ) {
			$before[ $occurrence->id() ] = $occurrence->start_utc();
		}

		$target = $occurrences[10];

		$this->move( $target->id(), '+2 days' );

		OccurrenceSync::sync( $event_id );

		$after      = OccurrenceRepository::for_event( $event_id );
		$exceptions = 0;
		$unchanged  = 0;

		foreach ( $after as $occurrence ) {
			if ( $occurrence->id() === $target->id() ) {
				++$exceptions;

				continue;
			}

			$this->assertArrayHasKey( $occurrence->id(), $before, 'a row was replaced' );
			$this->assertSame( $before[ $occurrence->id() ], $occurrence->start_utc() );
			$this->assertFalse( $occurrence->is_exception() );

			++$unchanged;
		}

		$this->assertCount( 52, $after );
		$this->assertSame( 1, $exceptions );
		$this->assertSame( 51, $unchanged, 'the gate asks for 51 untouched' );
	}

	/**
	 * A cancelled date stays cancelled through a regeneration.
	 *
	 * @return void
	 */
	public function test_a_cancelled_date_stays_cancelled() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceRepository::update(
			$occurrences[2]->id(),
			array(
				'status'       => OccurrenceStatus::Cancelled->value,
				'is_exception' => 1,
			)
		);

		OccurrenceSync::sync( $event_id );

		$after = OccurrenceRepository::find( $occurrences[2]->id() );

		$this->assertNotNull( $after );
		$this->assertSame( OccurrenceStatus::Cancelled, $after->status(), 'the rule reinstated a cancelled date' );

		// The date is still there rather than gone: somebody has it in their diary.
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * An ordinary date the rule stops producing is removed.
	 *
	 * The control for the two tests below. Without it, a reconciler that kept
	 * everything would pass both of them.
	 *
	 * @return void
	 */
	public function test_an_ordinary_dropped_date_is_deleted() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=6' );

		$this->assertCount( 6, OccurrenceRepository::for_event( $event_id ) );

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=3' );

		$result = OccurrenceSync::sync( $event_id );

		$this->assertSame( 3, $result['deleted'] );
		$this->assertSame( 0, $result['cancelled'] );
		$this->assertCount( 3, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * An exception the rule stops producing is cancelled, not deleted.
	 *
	 * It is a decision somebody made by hand. Removing it because the rule changed
	 * throws that away with nothing on screen to say so.
	 *
	 * @return void
	 */
	public function test_a_dropped_exception_is_cancelled_not_deleted() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=6' );

		$occurrences = OccurrenceRepository::for_event( $event_id );
		$target      = $occurrences[5];

		$this->move( $target->id(), '+1 day' );

		// Shorten the rule so the moved date's slot is no longer produced.
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=3' );

		$result = OccurrenceSync::sync( $event_id );

		$survivor = OccurrenceRepository::find( $target->id() );

		$this->assertNotNull( $survivor, 'the exception was deleted by a rule change' );
		$this->assertSame( OccurrenceStatus::Cancelled, $survivor->status() );
		$this->assertSame( 1, $result['cancelled'] );
	}

	/**
	 * A date with bookings on it is cancelled, not deleted.
	 *
	 * **Also from the gate C6.1 added.** Deleting it destroys the only link
	 * between a booking and what it was for.
	 *
	 * @return void
	 */
	public function test_a_booked_date_is_cancelled_not_deleted() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=6' );

		$occurrences = OccurrenceRepository::for_event( $event_id );
		$booked      = $occurrences[5];

		$registration_id = $this->book_onto( $event_id, $booked->id() );

		$this->assertGreaterThan( 0, Repository::count_for_occurrence( $booked->id() ), 'the fixture booked nothing' );

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=3' );

		$result = OccurrenceSync::sync( $event_id );

		$survivor = OccurrenceRepository::find( $booked->id() );

		$this->assertNotNull( $survivor, 'a date with bookings on it was deleted' );
		$this->assertSame( OccurrenceStatus::Cancelled, $survivor->status() );
		$this->assertSame( 1, $result['cancelled'] );

		// And the booking still points at something that exists.
		$this->assertNotNull( Repository::find( $registration_id ) );
		$this->assertSame( 1, Repository::count_for_occurrence( $booked->id() ) );
	}

	/**
	 * With registration off, nothing claims to protect a date.
	 *
	 * The filter is answered by the registration module, so with it switched off
	 * there are no bookings to protect and an ordinary dropped date is simply
	 * removed. This is the dependency direction ADR-0009 requires, checked rather
	 * than assumed.
	 *
	 * @return void
	 */
	public function test_with_registration_off_nothing_is_protected() {
		update_option( QEVM_OPTION_MODULES, array( RecurrenceModule::ID ) );

		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=6' );

		$this->assertFalse(
			(bool) apply_filters( 'qevm_occurrence_is_protected', false, OccurrenceRepository::for_event( $event_id )[0] ),
			'something protected an occurrence with registration switched off'
		);

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=3' );

		$result = OccurrenceSync::sync( $event_id );

		$this->assertSame( 3, $result['deleted'] );
		$this->assertSame( 0, $result['cancelled'] );
	}

	/**
	 * A one-off event still reconciles on its start time.
	 *
	 * The regression that matters most: a second matching mode must not disturb
	 * the path every non-recurring event uses.
	 *
	 * @return void
	 */
	public function test_a_one_off_event_is_unaffected() {
		update_option( QEVM_OPTION_MODULES, array( \QuickEventsManager\Registration\RegistrationModule::ID ) );

		$event_id = $this->make_event();

		$before = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $before );

		$start = gmdate( 'Y-m-d H:i:s', time() + ( 5 * WEEK_IN_SECONDS ) );

		update_post_meta( $event_id, Meta::START_UTC, $start );
		update_post_meta( $event_id, Meta::END_UTC, gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) + HOUR_IN_SECONDS ) );

		$result = OccurrenceSync::sync( $event_id );

		$after = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $after );
		$this->assertSame( $start, $after[0]->start_utc() );
		$this->assertSame( 0, $result['cancelled'] );
	}

	/**
	 * Two rows cannot collide between a slot and a start time.
	 *
	 * Both are `Y-m-d H:i:s`, so an unprefixed identity map would let a generated
	 * row's slot match a one-off row's start.
	 *
	 * @return void
	 */
	public function test_a_slot_and_a_start_do_not_collide() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=3' );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		// A hand-added row with no slot, starting exactly when a generated one does.
		$extra = OccurrenceRepository::insert(
			array(
				'event_id'      => $event_id,
				'recurrence_id' => '',
				'start_utc'     => $occurrences[0]->start_utc(),
				'end_utc'       => $occurrences[0]->end_utc(),
				'start_local'   => $occurrences[0]->start_local(),
				'end_local'     => $occurrences[0]->end_local(),
				'timezone'      => $occurrences[0]->timezone(),
				'is_exception'  => 1,
			)
		);

		$this->assertGreaterThan( 0, $extra );

		OccurrenceSync::sync( $event_id );

		// The generated row keeps its id; the hand-added one is cancelled, not merged.
		$this->assertNotNull( OccurrenceRepository::find( $occurrences[0]->id() ) );
		$this->assertNotNull( OccurrenceRepository::find( $extra ) );
		$this->assertNotSame( $occurrences[0]->id(), $extra );
	}

	/**
	 * A recurring event with its dates generated.
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
	 * Move one occurrence, the way C6.4b will.
	 *
	 * @param int    $id       Occurrence id.
	 * @param string $modifier A strtotime modifier, e.g. '+1 day'.
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
	 * Insert a booking attached to one occurrence.
	 *
	 * Inserted rather than booked through the service, because nothing yet writes
	 * `occurrence_id` on a booking — picking which date of a series you are
	 * booking is a form that does not exist. That gap is recorded in
	 * docs/development-plan.md under C6.4a; the reconciler's protection is real
	 * and testable regardless of how the row got there.
	 *
	 * @param int $event_id      Event id.
	 * @param int $occurrence_id Occurrence id.
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
}
