<?php
/**
 * Occurrences, against the database that stores them.
 *
 * The occurrence table is derived data: the post meta is the truth, and these
 * rows exist so that a date query can be an indexed range scan instead of a
 * join against `wp_postmeta`. Derived data is only worth having if it cannot
 * drift, so most of what follows is about it staying in step — on save, on
 * delete, and after a rebuild.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceQuery;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;

/**
 * Sync, rebuild, and the queries that read the result.
 */
final class OccurrenceTest extends TestCase {

	/**
	 * Saving an event writes its dates to the occurrence table.
	 *
	 * @return void
	 */
	public function test_saving_an_event_creates_its_occurrence() {
		$event_id = $this->make_event( array( 'starts_in' => DAY_IN_SECONDS ) );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $occurrences );
		$this->assertSame(
			get_post_meta( $event_id, Meta::START_UTC, true ),
			$occurrences[0]->start_utc()
		);
		$this->assertSame(
			get_post_meta( $event_id, Meta::END_UTC, true ),
			$occurrences[0]->end_utc()
		);
	}

	/**
	 * Moving an event moves its occurrence rather than adding one.
	 *
	 * The row id is deliberately not asserted. Reconciliation is keyed on
	 * `start_utc` — a date within an event is identified by when it starts — so
	 * changing the start is a delete and an insert, not an update. That is the
	 * right reading for a recurring event and a question worth revisiting in
	 * stage 6, when tickets and check-ins begin referencing `occurrence_id` and
	 * a one-off event moving to Thursday would otherwise orphan them.
	 *
	 * @return void
	 */
	public function test_moving_an_event_moves_its_occurrence() {
		$event_id = $this->make_event();
		$before   = OccurrenceRepository::for_event( $event_id );

		$moved = gmdate( 'Y-m-d H:i:s', time() + ( 3 * WEEK_IN_SECONDS ) );

		wp_update_post(
			array(
				'ID'         => $event_id,
				'meta_input' => array(
					Meta::START_UTC => $moved,
					Meta::END_UTC   => gmdate( 'Y-m-d H:i:s', time() + ( 3 * WEEK_IN_SECONDS ) + HOUR_IN_SECONDS ),
				),
			)
		);

		$after = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $after, 'a moved event must not leave its old date behind' );
		$this->assertSame( $moved, $after[0]->start_utc() );
		$this->assertNotSame( $before[0]->start_utc(), $after[0]->start_utc() );
	}

	/**
	 * These arguments work through get_posts(), not only through WP_Query.
	 *
	 * Every one of them is delivered by a `posts_clauses` filter, and
	 * get_posts() suppresses filters unless told not to. Without
	 * `suppress_filters => false` in the arguments themselves, the same call
	 * returns every event in post order with no join, no date range and no
	 * complaint — which is what the admin's event picker was doing.
	 *
	 * @return void
	 */
	public function test_the_arguments_survive_get_posts() {
		$upcoming = $this->make_event( array( 'starts_in' => DAY_IN_SECONDS ) );
		$finished = $this->make_event( array( 'starts_in' => -2 * WEEK_IN_SECONDS ) );

		$found = get_posts(
			OccurrenceQuery::upcoming_args(
				array(
					'posts_per_page' => 10,
					'fields'         => 'ids',
				)
			)
		);

		$this->assertSame( array( $upcoming ), $found );
		$this->assertNotContains( $finished, $found );
	}

	/**
	 * Deleting an event takes its occurrences with it.
	 *
	 * @return void
	 */
	public function test_deleting_an_event_removes_its_occurrences() {
		$event_id = $this->make_event();

		$this->assertSame( 1, OccurrenceRepository::count_for_event( $event_id ) );

		wp_delete_post( $event_id, true );

		$this->assertSame( 0, OccurrenceRepository::count_for_event( $event_id ) );
	}

	/**
	 * An event with no dates yet produces no occurrence.
	 *
	 * A draft somebody has started but not filled in is the ordinary case here,
	 * and it must not appear in a date query as a row with an empty date.
	 *
	 * @return void
	 */
	public function test_an_event_without_dates_has_no_occurrence() {
		$event_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE,
				'post_title'  => 'Undated',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 0, OccurrenceRepository::count_for_event( (int) $event_id ) );
	}

	/**
	 * An end before the start is corrected rather than stored.
	 *
	 * A range query against `end_utc >= now` silently loses an event whose end
	 * is before its start, so the wrong data must not reach the table.
	 *
	 * @return void
	 */
	public function test_an_end_before_the_start_is_corrected() {
		$start = gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS );

		$event_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE,
				'post_title'  => 'Backwards',
				'post_status' => 'publish',
				'meta_input'  => array(
					Meta::START_UTC => $start,
					Meta::END_UTC   => gmdate( 'Y-m-d H:i:s', time() ),
				),
			)
		);

		$occurrence = OccurrenceRepository::for_event( (int) $event_id )[0];

		$this->assertGreaterThanOrEqual( $occurrence->start_utc(), $occurrence->end_utc() );
	}

	/**
	 * The rebuild reproduces exactly what syncing produced.
	 *
	 * This is the command a site owner runs when they suspect the table has
	 * drifted, so "it rebuilds to the same answer" is the whole of its promise.
	 *
	 * @return void
	 */
	public function test_rebuilding_reproduces_the_same_rows() {
		global $wpdb;

		$first  = $this->make_event( array( 'starts_in' => DAY_IN_SECONDS ) );
		$second = $this->make_event( array( 'starts_in' => 2 * DAY_IN_SECONDS ) );

		$before = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event_id, start_utc, end_utc, status FROM %i ORDER BY event_id ASC',
				OccurrenceRepository::table()
			),
			ARRAY_A
		);

		$this->assertCount( 2, $before );

		// Wipe the derived data completely, the way a botched import would.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', OccurrenceRepository::table() ) );
		$this->assertSame( 0, OccurrenceRepository::count_all() );

		foreach ( array( $first, $second ) as $event_id ) {
			OccurrenceSync::sync( $event_id );
		}

		$after = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event_id, start_utc, end_utc, status FROM %i ORDER BY event_id ASC',
				OccurrenceRepository::table()
			),
			ARRAY_A
		);

		$this->assertSame( $before, $after );
	}

	/**
	 * A second rebuild changes nothing at all.
	 *
	 * @return void
	 */
	public function test_rebuilding_twice_is_a_no_op() {
		$event_id = $this->make_event();

		OccurrenceSync::sync( $event_id );
		$once = OccurrenceRepository::for_event( $event_id );

		OccurrenceSync::sync( $event_id );
		$twice = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $twice );
		$this->assertSame( $once[0]->id(), $twice[0]->id() );
		$this->assertSame( $once[0]->start_utc(), $twice[0]->start_utc() );
	}

	/**
	 * Upcoming events come back in date order, and past ones stay out.
	 *
	 * @return void
	 */
	public function test_upcoming_events_are_ordered_by_when_they_start() {
		$later   = $this->make_event( array( 'starts_in' => 3 * DAY_IN_SECONDS ) );
		$sooner  = $this->make_event( array( 'starts_in' => DAY_IN_SECONDS ) );
		$expired = $this->make_event( array( 'starts_in' => -2 * WEEK_IN_SECONDS ) );

		$found = get_posts(
			OccurrenceQuery::upcoming_args(
				array(
					'posts_per_page' => 10,
					'fields'         => 'ids',
				)
			)
		);

		$this->assertSame( array( $sooner, $later ), $found );
		$this->assertNotContains( $expired, $found );
	}

	/**
	 * The date query is a range scan on the occurrence table, with no CAST.
	 *
	 * This is the stage gate in one test. The whole reason the table exists is
	 * that `meta_query` with `'type' => 'DATETIME'` emits `CAST( meta_value AS
	 * DATETIME )`, and a cast around a column is a promise not to use its
	 * index — on `wp_postmeta`, where `meta_value` is an unindexed longtext,
	 * that means reading every meta row on the site to answer "what is on next
	 * week".
	 *
	 * @return void
	 */
	public function test_the_upcoming_query_is_an_indexed_range_scan() {
		global $wpdb;

		$this->make_event( array( 'starts_in' => DAY_IN_SECONDS ) );

		$query = new \WP_Query(
			OccurrenceQuery::upcoming_args(
				array(
					'posts_per_page' => 10,
					'fields'         => 'ids',
				)
			)
		);

		$sql = $query->request;

		$this->assertStringNotContainsString( 'CAST(', $sql, 'a cast around a column cannot use its index' );
		$this->assertStringNotContainsString( 'meta_value', $sql, 'dates must not be read from postmeta' );
		$this->assertStringContainsString( OccurrenceRepository::table(), $sql );

		$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Explaining the query WP_Query just built.

		$occurrence_row = null;

		foreach ( $plan as $row ) {
			if ( str_contains( (string) $row['table'], 'qevm_occ' ) ) {
				$occurrence_row = $row;
			}
		}

		$this->assertNotNull( $occurrence_row, 'the occurrence table should be in the plan' );
		$this->assertContains(
			$occurrence_row['type'],
			array( 'range', 'ref', 'eq_ref', 'const' ),
			'the occurrence lookup fell back to a scan: ' . wp_json_encode( $occurrence_row )
		);
		$this->assertNotEmpty( $occurrence_row['key'], 'no index was used' );
	}
}
