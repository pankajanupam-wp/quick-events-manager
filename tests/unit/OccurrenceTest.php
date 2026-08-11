<?php
/**
 * The occurrence value object and its status enum.
 *
 * The repository is not covered here — it is entirely $wpdb, which this suite
 * deliberately does not fake. Its behaviour is verified against real MySQL.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Domain\OccurrenceStatus;
use QuickEventsManager\Events\Occurrence;

/**
 * Occurrence reading and status behaviour.
 */
#[CoversClass( Occurrence::class )]
#[CoversClass( OccurrenceStatus::class )]
final class OccurrenceTest extends TestCase {

	/**
	 * A representative stored row.
	 *
	 * @param array<string, mixed> $overrides Columns to change.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => '7',
				'event_id'     => '42',
				'series_uuid'  => '',
				'start_utc'    => '2030-06-01 09:00:00',
				'end_utc'      => '2030-06-01 17:00:00',
				'start_local'  => '2030-06-01 14:30:00',
				'end_local'    => '2030-06-01 22:30:00',
				'timezone'     => 'Asia/Kolkata',
				'all_day'      => '0',
				'is_exception' => '0',
				'status'       => 'scheduled',
			),
			$overrides
		);
	}

	/**
	 * Columns come back as the types the rest of the code expects.
	 *
	 * Every value $wpdb returns is a string, so a caller doing `=== 0` or
	 * `if ( $all_day )` on the raw row would be wrong in a way that only shows
	 * up with real data.
	 *
	 * @return void
	 */
	public function test_columns_are_cast_out_of_their_string_form() {
		$occurrence = new Occurrence( $this->row() );

		$this->assertSame( 7, $occurrence->id() );
		$this->assertSame( 42, $occurrence->event_id() );
		$this->assertSame( '2030-06-01 09:00:00', $occurrence->start_utc() );
		$this->assertSame( 'Asia/Kolkata', $occurrence->timezone() );
		$this->assertFalse( $occurrence->is_all_day() );
		$this->assertFalse( $occurrence->is_exception() );
	}

	/**
	 * The string '0' is false and the string '1' is true.
	 *
	 * @return void
	 */
	public function test_flags_read_as_booleans() {
		$this->assertTrue( ( new Occurrence( $this->row( array( 'all_day' => '1' ) ) ) )->is_all_day() );
		$this->assertFalse( ( new Occurrence( $this->row( array( 'all_day' => '0' ) ) ) )->is_all_day() );
		$this->assertTrue( ( new Occurrence( $this->row( array( 'is_exception' => '1' ) ) ) )->is_exception() );
	}

	/**
	 * An empty row does not throw; every accessor has a fallback.
	 *
	 * @return void
	 */
	public function test_an_empty_row_is_safe_to_read() {
		$occurrence = new Occurrence();

		$this->assertSame( 0, $occurrence->id() );
		$this->assertSame( 0, $occurrence->event_id() );
		$this->assertSame( '', $occurrence->start_utc() );
		$this->assertFalse( $occurrence->is_all_day() );
		$this->assertSame( OccurrenceStatus::Scheduled, $occurrence->status() );
	}

	/**
	 * Whether a date has finished is decided by its end, not its start.
	 *
	 * An event running all day is still on at lunchtime. Comparing the start
	 * would drop it from "upcoming" the moment it began, which is the bug the
	 * three-branch meta_query existed to avoid.
	 *
	 * @return void
	 */
	public function test_has_ended_uses_the_end_not_the_start() {
		$occurrence = new Occurrence( $this->row() );

		// After the start, before the end.
		$this->assertFalse( $occurrence->has_ended( '2030-06-01 12:00:00' ) );

		// After the end.
		$this->assertTrue( $occurrence->has_ended( '2030-06-01 17:00:01' ) );

		// Exactly at the end is not over.
		$this->assertFalse( $occurrence->has_ended( '2030-06-01 17:00:00' ) );
	}

	/**
	 * An unrecognised status falls back rather than throwing.
	 *
	 * @return void
	 */
	public function test_a_corrupt_status_falls_back_to_scheduled() {
		$occurrence = new Occurrence( $this->row( array( 'status' => 'nonsense' ) ) );

		$this->assertSame( OccurrenceStatus::Scheduled, $occurrence->status() );
		$this->assertSame( 'scheduled', $occurrence->status_value() );
	}

	/**
	 * The stored values are a contract.
	 *
	 * Pinned literally: changing one is a schema migration, not a rename.
	 *
	 * @return void
	 */
	public function test_status_values_are_the_stored_contract() {
		$this->assertSame( 'scheduled', OccurrenceStatus::Scheduled->value );
		$this->assertSame( 'cancelled', OccurrenceStatus::Cancelled->value );
		$this->assertSame( 'moved', OccurrenceStatus::Moved->value );
		$this->assertSame( array( 'scheduled', 'cancelled', 'moved' ), OccurrenceStatus::values() );
	}

	/**
	 * Only a cancelled date drops out of listings.
	 *
	 * A moved one still appears — that is the point of recording the move
	 * rather than deleting the row.
	 *
	 * @return void
	 */
	public function test_only_cancelled_is_hidden_from_listings() {
		$this->assertTrue( OccurrenceStatus::Scheduled->is_listable() );
		$this->assertTrue( OccurrenceStatus::Moved->is_listable() );
		$this->assertFalse( OccurrenceStatus::Cancelled->is_listable() );

		$this->assertSame( array( 'scheduled', 'moved' ), OccurrenceStatus::listable_values() );
	}

	/**
	 * The listable list and the per-case flag cannot disagree.
	 *
	 * @return void
	 */
	public function test_listable_list_is_derived_not_duplicated() {
		foreach ( OccurrenceStatus::all() as $status ) {
			$this->assertSame(
				$status->is_listable(),
				in_array( $status->value, OccurrenceStatus::listable_values(), true ),
				$status->value . ' disagrees with listable_values()'
			);
		}
	}

	/**
	 * Every case has a distinct, non-empty label.
	 *
	 * @return void
	 */
	public function test_every_status_has_a_distinct_label() {
		$labels = array_map(
			static fn( OccurrenceStatus $status ): string => $status->label(),
			OccurrenceStatus::all()
		);

		foreach ( $labels as $label ) {
			$this->assertNotSame( '', trim( $label ) );
		}

		$this->assertCount( count( $labels ), array_unique( $labels ) );
	}

	/**
	 * Occurrence status is not registration status.
	 *
	 * They share the word "cancelled" and nothing else. If these ever become
	 * one enum, capacity counting and calendar rendering start reading each
	 * other's vocabulary.
	 *
	 * @return void
	 */
	public function test_occurrence_status_is_a_separate_state_machine() {
		$this->assertNotSame(
			\QuickEventsManager\Domain\RegistrationStatus::values(),
			OccurrenceStatus::values()
		);

		$this->assertNull( OccurrenceStatus::coerce( 'waitlisted' ) );
		$this->assertNull( \QuickEventsManager\Domain\RegistrationStatus::coerce( 'scheduled' ) );
	}
}
