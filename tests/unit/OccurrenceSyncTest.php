<?php
/**
 * Turning an event's meta into the occurrence rows it implies.
 *
 * The build step is the part of the sync that can be reasoned about without a
 * database: it reads meta and returns rows. Writing those rows is the
 * repository's job and is verified against real MySQL.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceSync;

/**
 * Meta to occurrence rows.
 */
#[CoversClass( OccurrenceSync::class )]
final class OccurrenceSyncTest extends TestCase {

	/**
	 * Start from an empty stub world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub_State::reset();
	}

	/**
	 * Register an event post with the given meta.
	 *
	 * @param int                   $id   Post id.
	 * @param array<string, string> $meta Meta values.
	 * @return void
	 */
	private function make_event( int $id, array $meta ): void {
		WP_Stub_State::$posts[ $id ] = new WP_Post( $id, QEVM_POST_TYPE );

		foreach ( $meta as $key => $value ) {
			WP_Stub_State::$meta[ $id ][ $key ] = $value;
		}
	}

	/**
	 * A dated event produces exactly one row.
	 *
	 * @return void
	 */
	public function test_a_one_off_event_produces_one_occurrence() {
		$this->make_event(
			1,
			array(
				Meta::START_UTC   => '2030-06-01 09:00:00',
				Meta::END_UTC     => '2030-06-01 17:00:00',
				Meta::START_LOCAL => '2030-06-01 14:30:00',
				Meta::END_LOCAL   => '2030-06-01 22:30:00',
				Meta::TIMEZONE    => 'Asia/Kolkata',
			)
		);

		$rows = OccurrenceSync::build( 1 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]['event_id'] );
		$this->assertSame( '2030-06-01 09:00:00', $rows[0]['start_utc'] );
		$this->assertSame( '2030-06-01 17:00:00', $rows[0]['end_utc'] );
		$this->assertSame( 'Asia/Kolkata', $rows[0]['timezone'] );
		$this->assertSame( 'scheduled', $rows[0]['status'] );
		$this->assertSame( 0, $rows[0]['all_day'] );
	}

	/**
	 * An event with no start date has no occurrence at all.
	 *
	 * An event nobody has dated yet must not appear in a listing ordered by
	 * date. Returning no rows is also what makes clearing the date remove the
	 * old row, because the reconcile deletes whatever it is not given.
	 *
	 * @return void
	 */
	public function test_an_undated_event_produces_nothing() {
		$this->make_event( 2, array( Meta::TIMEZONE => 'UTC' ) );

		$this->assertSame( array(), OccurrenceSync::build( 2 ) );
	}

	/**
	 * A missing or wrong-typed post produces nothing.
	 *
	 * @return void
	 */
	public function test_a_post_that_is_not_an_event_produces_nothing() {
		WP_Stub_State::$posts[3] = new WP_Post( 3, 'page' );

		$this->assertSame( array(), OccurrenceSync::build( 3 ) );
		$this->assertSame( array(), OccurrenceSync::build( 999 ) );
	}

	/**
	 * An event with no stated end ends when it starts.
	 *
	 * This is the decision the whole indexing strategy rests on: end_utc is
	 * NOT NULL, so "has this finished" is one comparison rather than an OR
	 * across two keys. See docs/adr/0003-occurrence-table.md.
	 *
	 * @return void
	 */
	public function test_a_missing_end_falls_back_to_the_start() {
		$this->make_event(
			4,
			array(
				Meta::START_UTC   => '2030-06-01 09:00:00',
				Meta::START_LOCAL => '2030-06-01 09:00:00',
				Meta::TIMEZONE    => 'UTC',
			)
		);

		$rows = OccurrenceSync::build( 4 );

		$this->assertSame( '2030-06-01 09:00:00', $rows[0]['end_utc'] );
		$this->assertSame( '2030-06-01 09:00:00', $rows[0]['end_local'] );
	}

	/**
	 * An end before the start is treated as no end at all.
	 *
	 * Stored as given, every range query would answer "already finished" and
	 * the event would be invisible from the moment it was created — a support
	 * request nobody can reproduce, caused by a typo in a date field.
	 *
	 * @return void
	 */
	public function test_an_end_before_the_start_is_corrected() {
		$this->make_event(
			5,
			array(
				Meta::START_UTC   => '2030-06-01 09:00:00',
				Meta::END_UTC     => '2029-01-01 09:00:00',
				Meta::START_LOCAL => '2030-06-01 09:00:00',
				Meta::END_LOCAL   => '2029-01-01 09:00:00',
				Meta::TIMEZONE    => 'UTC',
			)
		);

		$rows = OccurrenceSync::build( 5 );

		$this->assertSame( '2030-06-01 09:00:00', $rows[0]['end_utc'] );
		$this->assertSame( '2030-06-01 09:00:00', $rows[0]['end_local'] );
	}

	/**
	 * The all-day flag survives the round trip as an integer.
	 *
	 * @return void
	 */
	public function test_the_all_day_flag_is_stored_as_an_integer() {
		$this->make_event(
			6,
			array(
				Meta::START_UTC => '2030-06-01 00:00:00',
				Meta::ALL_DAY   => '1',
				Meta::TIMEZONE  => 'UTC',
			)
		);

		$this->assertSame( 1, OccurrenceSync::build( 6 )[0]['all_day'] );
	}

	/**
	 * An event with no timezone of its own falls back to the site's.
	 *
	 * @return void
	 */
	public function test_timezone_falls_back_to_the_site() {
		$this->make_event( 7, array( Meta::START_UTC => '2030-06-01 09:00:00' ) );

		$this->assertNotSame( '', OccurrenceSync::build( 7 )[0]['timezone'] );
	}

	/**
	 * The recurrence columns are written empty and stay that way.
	 *
	 * They exist so that stage 6 does not have to alter a large table. If
	 * something starts filling them before then, that is a change worth
	 * noticing.
	 *
	 * @return void
	 */
	public function test_the_reserved_recurrence_columns_stay_empty() {
		$this->make_event(
			8,
			array(
				Meta::START_UTC => '2030-06-01 09:00:00',
				Meta::TIMEZONE  => 'UTC',
			)
		);

		$row = OccurrenceSync::build( 8 )[0];

		$this->assertSame( '', $row['series_uuid'] );
		$this->assertSame( 0, $row['is_exception'] );
	}
}
