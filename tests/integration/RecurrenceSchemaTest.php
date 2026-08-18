<?php
/**
 * The column that identifies a generated occurrence.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Recurrence\Series;

/**
 * `recurrence_id` is nullable, and that is the whole difficulty.
 *
 * An empty string is not a datetime. MySQL in strict mode rejects it and without
 * strict mode stores `0000-00-00 00:00:00` — a value that is not NULL, compares
 * equal to nothing, and cannot be read back as a date. Either way the row stops
 * being matchable, which is the one thing the column exists to make possible.
 *
 * None of that is visible from the unit suite, which has no MySQL to be strict.
 */
final class RecurrenceSchemaTest extends TestCase {

	/**
	 * The column exists after the schema has been brought up to date.
	 *
	 * @return void
	 */
	public function test_the_column_exists() {
		$this->assertContains( 'recurrence_id', $this->columns() );
	}

	/**
	 * The column is added to a table that predates it.
	 *
	 * The upgrade path rather than the fresh-install one. Both suites build their
	 * schema from scratch, so the fresh path is the only one they see by default —
	 * and C5.1 shipped a table that no existing site ever got for exactly that
	 * reason.
	 *
	 * @return void
	 */
	public function test_dbdelta_adds_it_to_an_older_table() {
		global $wpdb;

		$table = Installer::table( 'occurrences' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture standing in for a site created before C6.2.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN recurrence_id" );

		$this->assertNotContains( 'recurrence_id', $this->columns(), 'the fixture did not remove the column' );

		Installer::upgrade_schema();

		$this->assertContains( 'recurrence_id', $this->columns(), 'dbDelta did not add the column back' );
	}

	/**
	 * A row written without one holds NULL, not a zero date.
	 *
	 * @return void
	 */
	public function test_a_row_with_no_slot_holds_null() {
		$event_id = $this->make_event();

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $occurrences, 'the event fixture produced no occurrence' );
		$this->assertSame( '', $occurrences[0]->recurrence_id() );
		$this->assertFalse( $occurrences[0]->is_generated() );
		$this->assertNull( $this->raw_recurrence_id( $occurrences[0]->id() ), 'the column holds a zero date rather than NULL' );
	}

	/**
	 * An empty string is stored as NULL rather than as a zero date.
	 *
	 * The case the normaliser exists for. Written through insert() with an empty
	 * value, which is what a caller passing `''` for "no slot" would do.
	 *
	 * @return void
	 */
	public function test_an_empty_slot_is_stored_as_null() {
		$event_id = $this->make_event();

		$id = OccurrenceRepository::insert(
			array(
				'event_id'      => $event_id,
				'recurrence_id' => '',
				'start_utc'     => '2026-03-12 18:00:00',
				'end_utc'       => '2026-03-12 19:00:00',
				'start_local'   => '2026-03-12 18:00:00',
				'end_local'     => '2026-03-12 19:00:00',
				'timezone'      => 'UTC',
			)
		);

		$this->assertGreaterThan( 0, $id, 'the row was refused' );
		$this->assertNull( $this->raw_recurrence_id( $id ) );
	}

	/**
	 * A slot is written and read back unchanged.
	 *
	 * @return void
	 */
	public function test_a_slot_survives_a_round_trip() {
		$event_id = $this->make_event();
		$slot     = '2026-03-12 18:00:00';

		$id = OccurrenceRepository::insert(
			array(
				'event_id'      => $event_id,
				'recurrence_id' => $slot,
				'start_utc'     => $slot,
				'end_utc'       => '2026-03-12 19:00:00',
				'start_local'   => $slot,
				'end_local'     => '2026-03-12 19:00:00',
				'timezone'      => 'UTC',
			)
		);

		$occurrence = OccurrenceRepository::find( $id );

		$this->assertNotNull( $occurrence );
		$this->assertSame( $slot, $occurrence->recurrence_id() );
		$this->assertTrue( $occurrence->is_generated() );
	}

	/**
	 * Moving an occurrence leaves its slot alone.
	 *
	 * The property everything in stage 6 rests on: `start_utc` says when it
	 * happens, `recurrence_id` says which date in the series it is, and moving it
	 * changes only the first.
	 *
	 * @return void
	 */
	public function test_moving_an_occurrence_does_not_change_its_slot() {
		$event_id = $this->make_event();
		$slot     = '2026-03-12 18:00:00';

		$id = OccurrenceRepository::insert(
			array(
				'event_id'      => $event_id,
				'recurrence_id' => $slot,
				'start_utc'     => $slot,
				'end_utc'       => '2026-03-12 19:00:00',
				'start_local'   => $slot,
				'end_local'     => '2026-03-12 19:00:00',
				'timezone'      => 'UTC',
			)
		);

		OccurrenceRepository::update(
			$id,
			array(
				'start_utc'    => '2026-03-13 18:00:00',
				'end_utc'      => '2026-03-13 19:00:00',
				'is_exception' => 1,
				'status'       => 'moved',
			)
		);

		$moved = OccurrenceRepository::find( $id );

		$this->assertSame( '2026-03-13 18:00:00', $moved->start_utc(), 'the move did not happen' );
		$this->assertSame( $slot, $moved->recurrence_id(), 'moving an occurrence changed which slot it belongs to' );
		$this->assertTrue( $moved->is_exception() );
	}

	/**
	 * A one-off event still reconciles by start time.
	 *
	 * The regression that matters: adding a second matching mode must not disturb
	 * the path every existing event uses.
	 *
	 * @return void
	 */
	public function test_a_one_off_event_still_reconciles_on_its_date() {
		$event_id = $this->make_event();

		$before = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $before );

		$start = gmdate( 'Y-m-d H:i:s', time() + ( 3 * WEEK_IN_SECONDS ) );

		update_post_meta( $event_id, Meta::START_UTC, $start );
		update_post_meta( $event_id, Meta::END_UTC, gmdate( 'Y-m-d H:i:s', time() + ( 3 * WEEK_IN_SECONDS ) + HOUR_IN_SECONDS ) );

		\QuickEventsManager\Events\OccurrenceSync::sync( $event_id );

		$after = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $after, 'changing the date left the event with the wrong number of dates' );
		$this->assertSame( $start, $after[0]->start_utc() );
	}

	/**
	 * A series identifier is stored and read through post meta.
	 *
	 * @return void
	 */
	public function test_a_series_identifier_persists() {
		$event_id = $this->make_event();

		$uuid = Series::ensure( $event_id );

		$this->assertTrue( wp_is_uuid( $uuid, 4 ) );
		$this->assertSame( $uuid, Series::for_event( $event_id ) );

		$second = $this->make_event();

		$this->assertSame( $uuid, Series::copy( $event_id, $second ) );
		$this->assertSame( Series::for_event( $event_id ), Series::for_event( $second ) );
	}

	/**
	 * A rule survives the registered meta sanitiser.
	 *
	 * Registered meta is sanitised by WordPress on the way in, which the unit
	 * suite cannot exercise because it has no meta registry. A sanitiser that
	 * mangles a rule would leave every recurring event non-recurring.
	 *
	 * @return void
	 */
	public function test_a_rule_survives_being_stored_through_registered_meta() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, ' freq=weekly ; byday= mo , th ' );

		$this->assertSame(
			'FREQ=WEEKLY;BYDAY=MO,TH',
			get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ),
			'the registered sanitiser did not canonicalise the rule'
		);

		$rule = Series::rule_for_event( $event_id );

		$this->assertNotNull( $rule );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO,TH', $rule->to_string() );
	}

	/**
	 * An unusable rule is not stored at all.
	 *
	 * @return void
	 */
	public function test_an_unusable_rule_is_not_stored() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;BYDAY=2TU' );

		$this->assertSame( '', get_post_meta( $event_id, Meta::RECURRENCE_RULE, true ) );
		$this->assertFalse( Series::is_recurring( $event_id ) );
	}

	/**
	 * The occurrences table's columns.
	 *
	 * @return string[]
	 */
	private function columns() {
		global $wpdb;

		$table = Installer::table( 'occurrences' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading the schema of a custom table.
		$rows = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );

		return array_map( static fn ( $row ) => (string) $row['Field'], $rows );
	}

	/**
	 * One row's `recurrence_id` exactly as MySQL holds it.
	 *
	 * Read raw rather than through the value object, because the object casts a
	 * NULL to '' and the difference between NULL and a zero date is the point.
	 *
	 * @param int $id Occurrence id.
	 * @return string|null
	 */
	private function raw_recurrence_id( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the column under test.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT recurrence_id FROM %i WHERE id = %d',
				OccurrenceRepository::table(),
				(int) $id
			)
		);

		return null === $value ? null : (string) $value;
	}
}
