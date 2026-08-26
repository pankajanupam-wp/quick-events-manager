<?php
/**
 * Moving and calling off one date of a series.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\OccurrenceStatus;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\OccurrenceEditor;
use QuickEventsManager\Recurrence\RecurrenceModule;

/**
 * "This occurrence" scope.
 *
 * Each of these makes an edit and then does something else — a rule change, an
 * unrelated save — before looking at the result, because an edit that survives
 * only until the next save is not an edit.
 */
final class OccurrenceEditorTest extends TestCase {

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
	 * Moving one date changes that date and no other.
	 *
	 * @return void
	 */
	public function test_moving_one_date_moves_only_that_date() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=5' );
		$occurrences = OccurrenceRepository::for_event( $event_id );
		$target      = $occurrences[2];

		$this->assertSame( '2026-06-16 18:00:00', $target->start_utc(), 'the fixture is not where it was expected' );

		$this->assertTrue( true === OccurrenceEditor::move( $target->id(), '2026-06-17 19:30:00' ) );

		$moved = OccurrenceRepository::find( $target->id() );

		$this->assertSame( '2026-06-17 19:30:00', $moved->start_utc() );
		$this->assertSame( '2026-06-17 19:30:00', $moved->start_local() );
		$this->assertTrue( $moved->is_exception() );
		$this->assertSame( OccurrenceStatus::Moved, $moved->status() );

		// The slot it belongs to is unchanged: it is still the third date.
		$this->assertSame( '2026-06-16 18:00:00', $moved->recurrence_id() );

		foreach ( OccurrenceRepository::for_event( $event_id ) as $occurrence ) {
			if ( $occurrence->id() === $target->id() ) {
				continue;
			}

			$this->assertFalse( $occurrence->is_exception(), 'another date became an exception' );
			$this->assertSame( '18:00:00', substr( $occurrence->start_utc(), 11 ) );
		}
	}

	/**
	 * A moved date keeps the length it had.
	 *
	 * @return void
	 */
	public function test_a_moved_date_keeps_its_length() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=3' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceEditor::move( $occurrences[1]->id(), '2026-06-11 09:00:00' );

		$moved = OccurrenceRepository::find( $occurrences[1]->id() );

		$this->assertSame(
			HOUR_IN_SECONDS,
			strtotime( $moved->end_utc() . ' UTC' ) - strtotime( $moved->start_utc() . ' UTC' ),
			'the date changed length when it was only asked to move'
		);
	}

	/**
	 * A move survives an unrelated save of the event.
	 *
	 * @return void
	 */
	public function test_a_move_survives_a_later_save() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=5' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceEditor::move( $occurrences[2]->id(), '2026-06-17 19:30:00' );

		wp_update_post(
			array(
				'ID'         => $event_id,
				'post_title' => 'Edited afterwards',
			)
		);

		$moved = OccurrenceRepository::find( $occurrences[2]->id() );

		$this->assertNotNull( $moved );
		$this->assertSame( '2026-06-17 19:30:00', $moved->start_utc() );
	}

	/**
	 * An explicit end is used when one is given.
	 *
	 * @return void
	 */
	public function test_an_explicit_end_is_honoured() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=3' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceEditor::move( $occurrences[0]->id(), '2026-06-02 09:00:00', '2026-06-02 17:00:00' );

		$moved = OccurrenceRepository::find( $occurrences[0]->id() );

		$this->assertSame( '2026-06-02 17:00:00', $moved->end_utc() );
	}

	/**
	 * An end before the start is refused.
	 *
	 * @return void
	 */
	public function test_an_end_before_the_start_is_refused() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=3' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		$result = OccurrenceEditor::move( $occurrences[0]->id(), '2026-06-02 17:00:00', '2026-06-02 09:00:00' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_end_before_start', $result->get_error_code() );

		$unchanged = OccurrenceRepository::find( $occurrences[0]->id() );

		$this->assertFalse( $unchanged->is_exception(), 'a refused move still marked the date as an exception' );
	}

	/**
	 * Moving is refused on an event that does not repeat.
	 *
	 * There, "move this occurrence" means "change the event's date", which is a
	 * different operation — and doing it here would be undone on the next save.
	 *
	 * @return void
	 */
	public function test_a_one_off_date_cannot_be_moved_this_way() {
		$event_id = $this->make_event();

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $occurrences );
		$this->assertFalse( $occurrences[0]->is_generated() );

		$result = OccurrenceEditor::move( $occurrences[0]->id(), '2026-06-02 09:00:00' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_not_a_series_date', $result->get_error_code() );
	}

	/**
	 * Calling off a date keeps the row and marks it cancelled.
	 *
	 * @return void
	 */
	public function test_cancelling_keeps_the_date_and_marks_it() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertTrue( true === OccurrenceEditor::cancel( $occurrences[1]->id() ) );

		$cancelled = OccurrenceRepository::find( $occurrences[1]->id() );

		$this->assertNotNull( $cancelled, 'cancelling deleted the date' );
		$this->assertSame( OccurrenceStatus::Cancelled, $cancelled->status() );
		$this->assertTrue( $cancelled->is_exception() );
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * A cancelled date stays cancelled through a rule change.
	 *
	 * @return void
	 */
	public function test_a_cancellation_survives_a_rule_change() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=6' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceEditor::cancel( $occurrences[1]->id() );

		// A rule change that still produces this slot.
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=8' );
		OccurrenceSync::sync( $event_id );

		$cancelled = OccurrenceRepository::find( $occurrences[1]->id() );

		$this->assertNotNull( $cancelled );
		$this->assertSame( OccurrenceStatus::Cancelled, $cancelled->status(), 'the rule reinstated a cancelled date' );
		$this->assertCount( 8, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * Putting a never-moved date back hands it to the rule completely.
	 *
	 * There is nothing left to preserve once it is uncancelled, and leaving it
	 * flagged as an exception would freeze it against every future rule change for
	 * no reason.
	 *
	 * @return void
	 */
	public function test_reinstating_an_unmoved_date_hands_it_back_to_the_rule() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceEditor::cancel( $occurrences[1]->id() );

		$this->assertTrue( true === OccurrenceEditor::reinstate( $occurrences[1]->id() ) );

		$back = OccurrenceRepository::find( $occurrences[1]->id() );

		$this->assertSame( OccurrenceStatus::Scheduled, $back->status() );
		$this->assertFalse( $back->is_exception(), 'an unmoved date stayed frozen against the rule' );
	}

	/**
	 * Putting a moved-then-cancelled date back keeps its moved time.
	 *
	 * That time is still what the organiser asked for.
	 *
	 * @return void
	 */
	public function test_reinstating_a_moved_date_keeps_the_move() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceEditor::move( $occurrences[1]->id(), '2026-06-10 20:00:00' );
		OccurrenceEditor::cancel( $occurrences[1]->id() );
		OccurrenceEditor::reinstate( $occurrences[1]->id() );

		$back = OccurrenceRepository::find( $occurrences[1]->id() );

		$this->assertSame( OccurrenceStatus::Moved, $back->status() );
		$this->assertTrue( $back->is_exception() );
		$this->assertSame( '2026-06-10 20:00:00', $back->start_utc(), 'reinstating threw away the move' );
	}

	/**
	 * Restoring puts a moved date back where the rule says it goes.
	 *
	 * The undo, and the mechanism worth understanding: nothing stores an
	 * "original" to restore from. Clearing the flag gives the rule ownership again
	 * and the regeneration rewrites the row from the slot that was on it all along.
	 *
	 * @return void
	 */
	public function test_restoring_puts_a_moved_date_back() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$occurrences = OccurrenceRepository::for_event( $event_id );
		$original    = $occurrences[1]->start_utc();

		OccurrenceEditor::move( $occurrences[1]->id(), '2026-06-11 20:00:00' );

		$this->assertSame( '2026-06-11 20:00:00', OccurrenceRepository::find( $occurrences[1]->id() )->start_utc() );

		$this->assertTrue( true === OccurrenceEditor::restore( $occurrences[1]->id() ) );

		$restored = OccurrenceRepository::find( $occurrences[1]->id() );

		$this->assertNotNull( $restored, 'restoring replaced the row instead of resetting it' );
		$this->assertSame( $original, $restored->start_utc() );
		$this->assertFalse( $restored->is_exception() );
		$this->assertSame( OccurrenceStatus::Scheduled, $restored->status() );
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * Restoring a date the rule no longer produces removes it.
	 *
	 * The honest answer: a date the series does not have is not one the series can
	 * hold on the organiser's behalf.
	 *
	 * @return void
	 */
	public function test_restoring_a_date_the_rule_dropped_removes_it() {
		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=6' );
		$occurrences = OccurrenceRepository::for_event( $event_id );
		$target      = $occurrences[5];

		OccurrenceEditor::move( $target->id(), '2026-07-08 20:00:00' );

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=3' );
		OccurrenceSync::sync( $event_id );

		// Kept as cancelled, because it is an exception.
		$this->assertNotNull( OccurrenceRepository::find( $target->id() ) );

		OccurrenceEditor::restore( $target->id() );

		$this->assertNull( OccurrenceRepository::find( $target->id() ), 'a date the rule dropped survived being restored' );
		$this->assertCount( 3, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * Each operation announces itself, and none of them sends mail.
	 *
	 * The queue from stage 5 makes telling 200 people trivially easy, which is
	 * exactly why it must not happen as a side effect of a save.
	 *
	 * @return void
	 */
	public function test_the_operations_fire_hooks_and_send_nothing() {
		$sent = 0;

		add_filter(
			'pre_wp_mail',
			static function ( $short ) use ( &$sent ) {
				unset( $short );

				++$sent;

				return true;
			}
		);

		$fired = array();

		foreach ( array( 'moved', 'cancelled', 'reinstated', 'restored' ) as $name ) {
			add_action(
				'qevm_occurrence_' . $name,
				static function () use ( $name, &$fired ) {
					$fired[] = $name;
				}
			);
		}

		$event_id    = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$occurrences = OccurrenceRepository::for_event( $event_id );

		OccurrenceEditor::move( $occurrences[1]->id(), '2026-06-10 20:00:00' );
		OccurrenceEditor::cancel( $occurrences[1]->id() );
		OccurrenceEditor::reinstate( $occurrences[1]->id() );
		OccurrenceEditor::restore( $occurrences[1]->id() );

		$this->assertSame( array( 'moved', 'cancelled', 'reinstated', 'restored' ), $fired );
		$this->assertSame( 0, $sent, 'editing a date sent email' );

		// Nothing was queued either — the queue is how mail leaves since C5.2.
		$this->assertSame( array(), \QuickEventsManager\Email\Queue::for_context( 'event', $event_id ) );
	}

	/**
	 * A date that does not exist is refused rather than silently ignored.
	 *
	 * @return void
	 */
	public function test_an_unknown_date_is_refused() {
		$result = OccurrenceEditor::cancel( 999999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_no_occurrence', $result->get_error_code() );
	}

	/**
	 * A recurring event with its dates generated, in UTC for readable fixtures.
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
}
