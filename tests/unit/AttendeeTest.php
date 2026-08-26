<?php
/**
 * The attendee value object and its status enum.
 *
 * The repository is entirely $wpdb and is verified against real MySQL.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Domain\AttendeeStatus;
use QuickEventsManager\Registration\Attendee;

/**
 * Attendee reading and status behaviour.
 */
#[CoversClass( Attendee::class )]
#[CoversClass( AttendeeStatus::class )]
final class AttendeeTest extends TestCase {

	/**
	 * A representative stored row.
	 *
	 * @param array<string, mixed> $overrides Columns to change.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => '12',
				'registration_id' => '5',
				'occurrence_id'   => '0',
				'ticket_type_id'  => '0',
				'ticket_code'     => 'QEVT-ABCD2345',
				'position'        => '1',
				'name'            => 'Ada Lovelace',
				'email'           => 'ada@example.com',
				'status'          => 'active',
			),
			$overrides
		);
	}

	/**
	 * Columns come back as the types the rest of the code expects.
	 *
	 * @return void
	 */
	public function test_columns_are_cast_out_of_their_string_form() {
		$attendee = new Attendee( $this->row() );

		$this->assertSame( 12, $attendee->id() );
		$this->assertSame( 5, $attendee->registration_id() );
		$this->assertSame( 1, $attendee->position() );
		$this->assertSame( 'QEVT-ABCD2345', $attendee->ticket_code() );
		$this->assertTrue( $attendee->is_active() );
	}

	/**
	 * An empty row is safe to read.
	 *
	 * @return void
	 */
	public function test_an_empty_row_is_safe_to_read() {
		$attendee = new Attendee();

		$this->assertSame( 0, $attendee->id() );
		$this->assertSame( '', $attendee->name() );
		$this->assertSame( 1, $attendee->position() );
		$this->assertSame( AttendeeStatus::Active, $attendee->status() );
	}

	/**
	 * An unnamed guest is a known state, not an error.
	 *
	 * Somebody booking three places for their team may not know who is coming.
	 * See docs/adr/0004-registration-attendee-split.md.
	 *
	 * @return void
	 */
	public function test_a_guest_may_have_no_name() {
		$attendee = new Attendee( $this->row( array( 'name' => '' ) ) );

		$this->assertFalse( $attendee->is_named() );
		$this->assertSame( '', $attendee->name() );
	}

	/**
	 * Whitespace is not a name.
	 *
	 * @return void
	 */
	public function test_whitespace_does_not_count_as_a_name() {
		$this->assertFalse( ( new Attendee( $this->row( array( 'name' => '   ' ) ) ) )->is_named() );
	}

	/**
	 * An unnamed guest still has something to show on a door list.
	 *
	 * A check-in list of blanks is unusable; the position at least says which
	 * place the person on the door is looking at.
	 *
	 * @return void
	 */
	public function test_an_unnamed_guest_falls_back_to_their_position() {
		$named = new Attendee( $this->row() );
		$this->assertSame( 'Ada Lovelace', $named->display_name() );

		$guest = new Attendee(
			$this->row(
				array(
					'name'     => '',
					'position' => '3',
				)
			)
		);
		$this->assertStringContainsString( '3', $guest->display_name() );
		$this->assertNotSame( '', trim( $guest->display_name() ) );
	}

	/**
	 * The reserved columns are readable and default to zero.
	 *
	 * They exist so stages 6 and 7 need not alter a table holding a row for
	 * every place ever booked.
	 *
	 * @return void
	 */
	public function test_reserved_columns_default_to_zero() {
		$attendee = new Attendee( $this->row() );

		$this->assertSame( 0, $attendee->occurrence_id() );
		$this->assertSame( 0, $attendee->ticket_type_id() );
	}

	/**
	 * A corrupt status falls back rather than throwing.
	 *
	 * @return void
	 */
	public function test_a_corrupt_status_falls_back_to_active() {
		$attendee = new Attendee( $this->row( array( 'status' => 'nonsense' ) ) );

		$this->assertSame( AttendeeStatus::Active, $attendee->status() );
		$this->assertSame( 'active', $attendee->status_value() );
	}

	/**
	 * The stored values are a contract.
	 *
	 * @return void
	 */
	public function test_status_values_are_the_stored_contract() {
		$this->assertSame( 'active', AttendeeStatus::Active->value );
		$this->assertSame( 'cancelled', AttendeeStatus::Cancelled->value );
		$this->assertSame( array( 'active', 'cancelled' ), AttendeeStatus::values() );
	}

	/**
	 * Only an active attendee may be admitted.
	 *
	 * @return void
	 */
	public function test_only_an_active_attendee_is_admissible() {
		$this->assertTrue( AttendeeStatus::Active->is_admissible() );
		$this->assertFalse( AttendeeStatus::Cancelled->is_admissible() );

		$cancelled = new Attendee( $this->row( array( 'status' => 'cancelled' ) ) );
		$this->assertFalse( $cancelled->is_active() );
	}

	/**
	 * Attendee status is not registration status.
	 *
	 * They share the word "cancelled" and nothing else. A registration being
	 * withdrawn and one person dropping out of a booking that still stands are
	 * different facts.
	 *
	 * @return void
	 */
	public function test_attendee_status_is_a_separate_state_machine() {
		$this->assertNull( AttendeeStatus::coerce( 'waitlisted' ) );
		$this->assertNull( AttendeeStatus::coerce( 'scheduled' ) );
		$this->assertNull( \QuickEventsManager\Domain\RegistrationStatus::coerce( 'active' ) );

		$this->assertNotSame(
			\QuickEventsManager\Domain\RegistrationStatus::values(),
			AttendeeStatus::values()
		);
	}

	/**
	 * There is no per-person waitlist.
	 *
	 * Capacity is counted in places on the booking, and it is the booking that
	 * queues. A waitlisted person on a confirmed booking would be a state
	 * nothing knows how to resolve.
	 *
	 * @return void
	 */
	public function test_there_is_no_per_person_waitlist() {
		$this->assertCount( 2, AttendeeStatus::all() );
		$this->assertNotContains( 'waitlisted', AttendeeStatus::values() );
		$this->assertNotContains( 'pending', AttendeeStatus::values() );
	}
}
