<?php
/**
 * Generation reaching the occurrence table, and the module gate in front of it.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\Horizon;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Recurrence\Series;

/**
 * A rule on an event, and what the table holds afterwards.
 *
 * The unit suite covers what the generator produces. This covers whether it is
 * reached at all — the module gate, the save hook, the reconciler, and the
 * nullable column that only MySQL can judge.
 */
final class RecurrenceGenerationTest extends TestCase {

	/**
	 * Switch recurrence on for the tests that need it.
	 *
	 * @return void
	 */
	private function enable_recurrence() {
		update_option(
			QEVM_OPTION_MODULES,
			array( \QuickEventsManager\Registration\RegistrationModule::ID, RecurrenceModule::ID )
		);
	}

	/**
	 * A weekly rule fills the occurrence table on save.
	 *
	 * @return void
	 */
	public function test_a_rule_generates_occurrences_on_save() {
		$this->enable_recurrence();

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=10' );

		OccurrenceSync::sync( $event_id );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 10, $occurrences );

		foreach ( $occurrences as $occurrence ) {
			$this->assertTrue( $occurrence->is_generated(), 'a generated row has no slot' );
			$this->assertSame( $occurrence->start_utc(), $occurrence->recurrence_id() );
			$this->assertNotSame( '', $occurrence->series_uuid(), 'a generated row has no series' );
		}

		// Every row shares one series identifier, and it is the event's.
		$this->assertSame(
			array( Series::for_event( $event_id ) ),
			array_values( array_unique( array_map( static fn ( $o ) => $o->series_uuid(), $occurrences ) ) )
		);
	}

	/**
	 * With the module off, a rule generates nothing.
	 *
	 * The gate is at the generator rather than at the event's meta, because an
	 * event can carry a rule from a previous life. C3.4 made this mistake with
	 * the registration form and the form rendered anyway.
	 *
	 * @return void
	 */
	public function test_the_module_gate_stops_generation() {
		update_option( QEVM_OPTION_MODULES, array( \QuickEventsManager\Registration\RegistrationModule::ID ) );

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=10' );

		OccurrenceSync::sync( $event_id );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $occurrences, 'recurrence generated with its module switched off' );
		$this->assertFalse( $occurrences[0]->is_generated() );
		$this->assertSame( '', $occurrences[0]->series_uuid() );
	}

	/**
	 * Switching the module off leaves the dates already generated alone.
	 *
	 * They stop being regenerated, which is not the same as being deleted. An
	 * organiser who wanted a year of dates gone would have said so.
	 *
	 * @return void
	 */
	public function test_switching_the_module_off_keeps_existing_dates() {
		$this->enable_recurrence();

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=10' );

		OccurrenceSync::sync( $event_id );

		$this->assertCount( 10, OccurrenceRepository::for_event( $event_id ) );

		update_option( QEVM_OPTION_MODULES, array( \QuickEventsManager\Registration\RegistrationModule::ID ) );

		$this->assertCount(
			10,
			OccurrenceRepository::for_event( $event_id ),
			'switching the module off deleted dates that already existed'
		);
	}

	/**
	 * Regenerating an unchanged series changes nothing.
	 *
	 * The property the reconciler has to have for a daily cron to be safe: an
	 * extension that touches every row is an extension that bumps every
	 * `updated_at` and rewrites the table once a day for no reason.
	 *
	 * @return void
	 */
	public function test_regenerating_an_unchanged_series_is_a_no_op() {
		$this->enable_recurrence();

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=10' );

		OccurrenceSync::sync( $event_id );

		$ids = array_map( static fn ( $o ) => $o->id(), OccurrenceRepository::for_event( $event_id ) );

		$result = OccurrenceSync::sync( $event_id );

		$this->assertSame( 0, $result['inserted'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 10, $result['unchanged'] );

		$this->assertSame(
			$ids,
			array_map( static fn ( $o ) => $o->id(), OccurrenceRepository::for_event( $event_id ) ),
			'regeneration replaced rows that had not changed'
		);
	}

	/**
	 * Shortening a rule removes the dates it no longer produces.
	 *
	 * @return void
	 */
	public function test_shortening_a_rule_removes_the_extra_dates() {
		$this->enable_recurrence();

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=10' );
		OccurrenceSync::sync( $event_id );

		$this->assertCount( 10, OccurrenceRepository::for_event( $event_id ) );

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=4' );
		OccurrenceSync::sync( $event_id );

		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * Removing the rule puts the event back to one date.
	 *
	 * @return void
	 */
	public function test_removing_the_rule_leaves_one_date() {
		$this->enable_recurrence();

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=10' );
		OccurrenceSync::sync( $event_id );

		$this->assertCount( 10, OccurrenceRepository::for_event( $event_id ) );

		delete_post_meta( $event_id, Meta::RECURRENCE_RULE );
		OccurrenceSync::sync( $event_id );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $occurrences );
		$this->assertFalse( $occurrences[0]->is_generated() );
	}

	/**
	 * An excluded date does not reach the table.
	 *
	 * @return void
	 */
	public function test_an_excluded_date_is_not_stored() {
		$this->enable_recurrence();

		$start    = '2026-06-02 18:00:00';
		$event_id = $this->make_recurring_event( 'FREQ=WEEKLY;COUNT=4', $start );

		$dates = array_map(
			static fn ( $o ) => substr( $o->start_local(), 0, 10 ),
			OccurrenceRepository::for_event( $event_id )
		);

		$this->assertSame( array( '2026-06-02', '2026-06-09', '2026-06-16', '2026-06-23' ), $dates );

		update_post_meta( $event_id, Meta::RECURRENCE_EXCLUSIONS, '2026-06-09' );
		OccurrenceSync::sync( $event_id );

		$after = array_map(
			static fn ( $o ) => substr( $o->start_local(), 0, 10 ),
			OccurrenceRepository::for_event( $event_id )
		);

		$this->assertNotContains( '2026-06-09', $after );
		$this->assertCount( 4, $after, 'the exclusion shortened the series instead of skipping a date' );
		$this->assertContains( '2026-06-30', $after, 'the series did not extend to keep its count' );
	}

	/**
	 * The exclusion list is sanitised by the registered meta key.
	 *
	 * @return void
	 */
	public function test_the_exclusion_list_is_sanitised_on_the_way_in() {
		$this->enable_recurrence();

		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::RECURRENCE_EXCLUSIONS, '2026-06-09, rubbish , 2026-02-30,2026-06-09' );

		$this->assertSame( '2026-06-09', get_post_meta( $event_id, Meta::RECURRENCE_EXCLUSIONS, true ) );
	}

	/**
	 * A local time survives a real daylight saving transition end to end.
	 *
	 * The unit suite proves the generator does this. This proves it survives
	 * being written to MySQL and read back, which is where a datetime column with
	 * the wrong type or a stray timezone conversion would show up.
	 *
	 * @return void
	 */
	public function test_a_local_time_survives_storage_across_a_transition() {
		$this->enable_recurrence();

		$event_id = $this->make_recurring_event(
			'FREQ=WEEKLY;COUNT=3',
			'2026-03-03 18:00:00',
			'America/New_York'
		);

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 3, $occurrences );

		foreach ( $occurrences as $occurrence ) {
			$this->assertSame( '18:00:00', substr( $occurrence->start_local(), 11 ), 'the wall-clock time moved in storage' );
		}

		$this->assertSame( '2026-03-03 23:00:00', $occurrences[0]->start_utc() );
		$this->assertSame( '2026-03-10 22:00:00', $occurrences[1]->start_utc() );
		$this->assertNotSame(
			substr( $occurrences[0]->start_utc(), 11 ),
			substr( $occurrences[1]->start_utc(), 11 ),
			'the fixture does not span a transition, so it proves nothing'
		);
	}

	/**
	 * The horizon task visits recurring events and skips the rest.
	 *
	 * @return void
	 */
	public function test_the_horizon_task_visits_only_recurring_events() {
		$this->enable_recurrence();

		delete_option( Horizon::CURSOR );

		$recurring = $this->make_recurring_event( 'FREQ=WEEKLY;COUNT=5' );
		$plain     = $this->make_event();

		$found = Horizon::recurring_event_ids( 0, 50 );

		$this->assertContains( $recurring, $found );
		$this->assertNotContains( $plain, $found, 'the horizon task picked up an event with no rule' );
	}

	/**
	 * The horizon task extends a series whose dates were cut off by the horizon.
	 *
	 * Without this, a series set up today has two years of dates, has one year of
	 * dates in a year, and eventually stops — silently, because every date that
	 * exists is correct.
	 *
	 * @return void
	 */
	public function test_the_horizon_task_adds_dates_that_have_come_into_range() {
		$this->enable_recurrence();

		delete_option( Horizon::CURSOR );

		// Endless weekly: bounded by the horizon rather than by the rule.
		$event_id = $this->make_recurring_event( 'FREQ=WEEKLY' );

		$before = OccurrenceRepository::count_for_event( $event_id );

		$this->assertGreaterThan( 50, $before, 'the fixture did not reach the horizon' );

		/*
		 * Delete the furthest dates to stand in for a horizon that has moved on
		 * since they were generated. The next run should put them back, which is
		 * exactly what extension does.
		 */
		$occurrences = OccurrenceRepository::for_event( $event_id );
		$removed     = 0;

		foreach ( array_slice( $occurrences, -5 ) as $occurrence ) {
			OccurrenceRepository::delete( $occurrence->id() );

			++$removed;
		}

		$this->assertSame( 5, $removed );
		$this->assertSame( $before - 5, OccurrenceRepository::count_for_event( $event_id ) );

		/*
		 * Walk from this event's own position rather than from zero. The horizon
		 * task walks every recurring event on the site, so a batch starting at
		 * the beginning visits whatever else happens to exist — and this test
		 * then measures that instead. It passed for weeks and failed the moment
		 * a database had other series in it, reporting 47 dates added where it
		 * expected 5.
		 */
		$all      = Horizon::recurring_event_ids( 0, 500 );
		$position = array_search( $event_id, $all, true );

		$this->assertNotFalse( $position, 'the fixture is not in the list the horizon walks' );

		update_option( Horizon::CURSOR, $position );

		$result = Horizon::run( 1 );

		$this->assertSame( 1, $result['visited'] );
		$this->assertSame( 5, $result['added'] );
		$this->assertSame( $before, OccurrenceRepository::count_for_event( $event_id ) );
	}

	/**
	 * The cursor walks the list and wraps rather than running off the end.
	 *
	 * The bug this replaced: an id-based cursor filtered after the query's LIMIT
	 * never advanced past the first batch, so every run did the same twenty events
	 * and nothing else was ever reached.
	 *
	 * @return void
	 */
	public function test_the_cursor_walks_and_wraps() {
		$this->enable_recurrence();

		delete_option( Horizon::CURSOR );

		$first  = $this->make_recurring_event( 'FREQ=WEEKLY;COUNT=3' );
		$second = $this->make_recurring_event( 'FREQ=WEEKLY;COUNT=3' );
		$third  = $this->make_recurring_event( 'FREQ=WEEKLY;COUNT=3' );

		/*
		 * Measured against the list the site actually has, not against three.
		 * The horizon walks every recurring event there is, so asserting a
		 * length here is asserting that nobody else's series exists — true in a
		 * fresh database and false in any real one.
		 */
		$all   = Horizon::recurring_event_ids( 0, 500 );
		$total = count( $all );

		$this->assertGreaterThanOrEqual( 3, $total );
		$this->assertSame( array( $first, $second, $third ), array_slice( $all, -3 ), 'the three fixtures are not the end of the walk' );

		$this->assertSame( array_slice( $all, 1 ), Horizon::recurring_event_ids( 1, 500 ) );
		$this->assertSame( array_slice( $all, $total - 1 ), Horizon::recurring_event_ids( $total - 1, 500 ) );
		$this->assertSame( array(), Horizon::recurring_event_ids( $total, 500 ) );

		/*
		 * A full pass wraps. How many runs that takes depends on how many
		 * recurring events the site has — the argument to run() is a time
		 * budget, not a batch size, and the batch is fixed — so the walk is
		 * driven until it wraps rather than assumed to wrap in one go. The
		 * bound is what makes this a test and not a loop: it must wrap within
		 * one run per batch, plus one.
		 */
		$passes  = (int) ceil( $total / Horizon::BATCH_SIZE );
		$visited = 0;

		for ( $pass = 0; $pass < $passes; $pass++ ) {
			$result  = Horizon::run();
			$visited = (int) $result['visited'];

			if ( 0 === (int) get_option( Horizon::CURSOR, -1 ) ) {
				break;
			}
		}

		/*
		 * Not merely "the cursor is 0". Deleting the wrap entirely leaves the
		 * cursor past the end, and the *next* run finds an empty batch and
		 * resets it — so "it reaches zero eventually" passes with the wrap
		 * removed, which is what the first version of this check did. The
		 * property is that the pass which finishes the list is the one that
		 * wraps: no run that visits nothing.
		 */
		$this->assertSame( 0, (int) get_option( Horizon::CURSOR, -1 ), 'the cursor did not wrap after a full pass over ' . $total . ' events' );
		$this->assertGreaterThan( 0, $visited, 'the cursor only reset on a run that visited nothing' );
	}

	/**
	 * An event whose rule has been made unusable keeps its single date.
	 *
	 * Visible and fixable, rather than an event that quietly disappears from
	 * every listing.
	 *
	 * @return void
	 */
	public function test_an_unusable_rule_leaves_the_event_with_one_date() {
		$this->enable_recurrence();

		$event_id = $this->make_recurring_event( 'FREQ=WEEKLY;COUNT=5' );

		$this->assertCount( 5, OccurrenceRepository::for_event( $event_id ) );

		/*
		 * Written past the registered sanitiser, which would have refused it. A
		 * value can still arrive this way — a hand-edited database, an import, or
		 * a version of this plugin that stored a different shape.
		 */
		$this->write_raw_rule( $event_id, 'FREQ=NONSENSE' );

		OccurrenceSync::sync( $event_id );

		$occurrences = OccurrenceRepository::for_event( $event_id );

		$this->assertCount( 1, $occurrences );
		$this->assertFalse( $occurrences[0]->is_generated() );
	}

	/**
	 * An event with a rule and its dates generated.
	 *
	 * @param string $rrule    The rule.
	 * @param string $start    Local start, `Y-m-d H:i:s`.
	 * @param string $timezone Event timezone.
	 * @return int Event id.
	 */
	private function make_recurring_event( $rrule, $start = '2026-06-02 18:00:00', $timezone = 'UTC' ) {
		$event_id = $this->make_event();

		$offset = ( new \DateTimeImmutable( $start, new \DateTimeZone( $timezone ) ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );

		update_post_meta( $event_id, Meta::TIMEZONE, $timezone );
		update_post_meta( $event_id, Meta::START_LOCAL, $start );
		update_post_meta( $event_id, Meta::END_LOCAL, gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::START_UTC, $offset );
		update_post_meta( $event_id, Meta::END_UTC, gmdate( 'Y-m-d H:i:s', strtotime( $offset . ' UTC' ) + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, $rrule );

		OccurrenceSync::sync( $event_id );

		return $event_id;
	}

	/**
	 * Write a rule straight to the meta table, past the registered sanitiser.
	 *
	 * @param int    $event_id Event id.
	 * @param string $rrule    Raw value.
	 * @return void
	 */
	private function write_raw_rule( $event_id, $rrule ) {
		global $wpdb;

		/*
		 * The slow-query sniffs fire on `meta_key` and `meta_value` because they
		 * are the names of WP_Query arguments. Here they are column names in a
		 * direct UPDATE against wp_postmeta, which is a different thing entirely
		 * and cannot be slow — the row is addressed by post_id and meta_key, both
		 * indexed.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- Standing in for a value that arrived without going through register_post_meta(); see above.
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $rrule ),
			array(
				'post_id'  => (int) $event_id,
				'meta_key' => Meta::RECURRENCE_RULE,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery

		wp_cache_delete( (int) $event_id, 'post_meta' );
	}
}
