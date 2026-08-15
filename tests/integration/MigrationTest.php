<?php
/**
 * Migrations, against a database that can actually be migrated.
 *
 * Nothing here can be faked. A migration's whole job is to change a schema and
 * move rows through it, and the only honest question to ask of one is whether a
 * real database looks right afterwards — twice.
 *
 * Every test in this file commits, because MySQL commits implicitly on DDL. The
 * transaction the rest of the suite relies on is gone the moment dbDelta runs,
 * so each test puts the schema back itself.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Install\Migrations\Runner;
use QuickEventsManager\Registration\Repository;

/**
 * The upgrade path, run more than once.
 */
final class MigrationTest extends TestCase {

	/**
	 * Put the schema and the version back after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->restore_schema();

		parent::tearDown();
	}

	/**
	 * A site already up to date has nothing to do.
	 *
	 * @return void
	 */
	public function test_a_current_site_needs_no_upgrade() {
		$this->assertFalse( Runner::needs_upgrade() );
		$this->assertSame( 0, Runner::run(), 'a current site should run no migrations' );
	}

	/**
	 * The stored version is the larger of the schema and the migrations.
	 *
	 * They move for different reasons: a release can add a table without
	 * needing any data transformed. Taking the maximum is what stops a
	 * schema-only change from never triggering the upgrade path — and stops a
	 * migration above the constant from being skipped.
	 *
	 * @return void
	 */
	public function test_the_target_version_covers_both_reasons_to_upgrade() {
		$this->assertSame(
			max( (int) QEVM_DB_VERSION, Runner::highest_migration_version() ),
			Runner::target_version()
		);
	}

	/**
	 * Every migration runs, and then does nothing on a second pass.
	 *
	 * The stage gate. A migration that is not idempotent is one that cannot
	 * survive a request timing out halfway, which is exactly what happens on
	 * the shared hosting this plugin is aimed at.
	 *
	 * @return void
	 */
	public function test_every_migration_is_a_no_op_on_a_second_run() {
		foreach ( Runner::migrations() as $migration ) {
			$first = $migration->run( Runner::BATCH_SIZE );

			// Run to completion: a batched migration reports rows until done.
			$guard = 0;

			while ( $first > 0 && $guard < 50 ) {
				$first = $migration->run( Runner::BATCH_SIZE );
				++$guard;
			}

			$this->assertSame(
				0,
				$migration->run( Runner::BATCH_SIZE ),
				$migration->description() . ' still reported work on a second run'
			);
		}
	}

	/**
	 * A site behind on the version upgrades, and lands exactly on target.
	 *
	 * @return void
	 */
	public function test_a_site_behind_the_version_catches_up() {
		update_option( QEVM_OPTION_DB_VERSION, 1 );

		$this->assertTrue( Runner::needs_upgrade() );

		Installer::upgrade_schema();
		Runner::run();

		$this->assertSame( Runner::target_version(), Runner::current_version() );
		$this->assertFalse( Runner::needs_upgrade() );
	}

	/**
	 * Running the whole upgrade twice changes nothing the second time.
	 *
	 * @return void
	 */
	public function test_the_upgrade_path_is_idempotent() {
		global $wpdb;

		update_option( QEVM_OPTION_DB_VERSION, 0 );

		Installer::upgrade_schema();
		Runner::run();

		$columns_after_first = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Repository::table() ) );
		$version_after_first = Runner::current_version();

		Installer::upgrade_schema();
		$second = Runner::run();

		$this->assertSame( 0, $second, 'nothing should be left to migrate' );
		$this->assertSame(
			$columns_after_first,
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Repository::table() ) )
		);
		$this->assertSame( $version_after_first, Runner::current_version() );
	}

	/**
	 * A fresh install ends up with the schema the docs describe.
	 *
	 * @return void
	 */
	public function test_a_fresh_install_has_the_documented_columns() {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Repository::table() ) );

		$this->assertSame(
			array(
				'id',
				'event_id',
				'occurrence_id',
				'order_id',
				'user_id',
				'code',
				'status',
				'quantity',
				'booker_name',
				'booker_email',
				'booker_phone',
				'consent_version',
				'consent_at',
				'fields',
				'created_at',
				'updated_at',
				'cancelled_at',
			),
			$columns
		);

		// The renamed columns are gone, not merely shadowed by the new ones.
		$this->assertNotContains( 'name', $columns );
		$this->assertNotContains( 'email', $columns );
		$this->assertNotContains( 'phone', $columns );
	}

	/**
	 * The occurrences table carries an index the archive's date filter can seek.
	 *
	 * The Stage 1 gate failed on exactly this. Four indexes existed and all four
	 * led with `start_utc`, while the query every visitor triggers filters on
	 * `end_utc >= now` — so there was nothing to seek and MySQL read all 10,000
	 * rows at every selectivity. The plan has the measurements.
	 *
	 * Read from the live table rather than from the CREATE TABLE string, because
	 * the string is not the thing that answers queries. dbDelta compares indexes
	 * **by name only**: change the columns of an existing key and it sees a name
	 * it already has and does nothing at all, leaving a schema and a database
	 * that disagree with no error anywhere.
	 *
	 * @return void
	 */
	public function test_the_occurrence_table_can_seek_on_end_utc() {
		$indexes = $this->indexes( OccurrenceRepository::table() );

		$this->assertArrayHasKey(
			'status_end',
			$indexes,
			'the archive filters on end_utc and nothing indexes it: ' . wp_json_encode( array_keys( $indexes ) )
		);

		$this->assertSame(
			array( 'status', 'end_utc' ),
			$indexes['status_end'],
			'status must lead so the status test stays inside the index, with end_utc as the range column'
		);

		// The date indexes as a set, so a rename or a dropped key is visible.
		$this->assertSame(
			array( 'PRIMARY', 'event_start', 'series_start', 'start_utc', 'status_end', 'status_start' ),
			array_keys( $indexes )
		);
	}

	/**
	 * A site on the previous schema version gains the index on upgrade.
	 *
	 * Adding an index to the CREATE TABLE only helps installs that never
	 * existed. This is the half that matters for one that does: the version
	 * moved with no migration behind it, so the whole upgrade rests on
	 * target_version() taking the maximum and the schema pass running.
	 *
	 * @return void
	 */
	public function test_an_older_install_gains_the_index_on_upgrade() {
		global $wpdb;

		$table = OccurrenceRepository::table();

		/*
		 * Conditional, so that a run against a table that never had the index
		 * fails on the assertion below rather than on a database error nobody
		 * asked for.
		 */
		if ( array_key_exists( 'status_end', $this->indexes( $table ) ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP KEY status_end', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreating the state a version-4 install is in.
		}

		update_option( QEVM_OPTION_DB_VERSION, 4 );

		$this->assertArrayNotHasKey( 'status_end', $this->indexes( $table ), 'the fixture did not take' );
		$this->assertTrue( Runner::needs_upgrade(), 'a version below the target must ask for an upgrade' );

		Installer::upgrade_schema();
		Runner::run();

		$this->assertArrayHasKey( 'status_end', $this->indexes( $table ) );
		$this->assertSame( Runner::target_version(), Runner::current_version() );
		$this->assertFalse( Runner::needs_upgrade(), 'the schema pass must not run again on every request' );
	}

	/**
	 * Index names to their columns, in order.
	 *
	 * @param string $table Table name.
	 * @return array<string, array<int, string>>
	 */
	private function indexes( $table ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the schema is the point of the test.

		$indexes = array();

		foreach ( (array) $rows as $row ) {
			$indexes[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}

		foreach ( $indexes as $name => $columns ) {
			ksort( $columns );

			$indexes[ $name ] = array_values( $columns );
		}

		ksort( $indexes );

		return $indexes;
	}

	/**
	 * The lock stops two requests migrating at once.
	 *
	 * Two administrators loading the dashboard together would otherwise both
	 * start. Every migration is idempotent, so the result would still be right
	 * — but right after doing the work twice, which on a large table is the
	 * difference between a slow page and a timeout.
	 *
	 * @return void
	 */
	public function test_a_held_lock_stops_a_second_run() {
		update_option( QEVM_OPTION_DB_VERSION, 1 );
		update_option( Runner::LOCK_OPTION, (string) ( time() + Runner::LOCK_TTL ) );

		$this->assertSame( 0, Runner::run(), 'the lock should have been refused' );
		$this->assertSame( 1, Runner::current_version(), 'a refused run must not stamp a version' );

		delete_option( Runner::LOCK_OPTION );

		$this->assertGreaterThan( 0, Runner::run() );
	}

	/**
	 * A lock left behind by a dead process does not block the site forever.
	 *
	 * @return void
	 */
	public function test_an_expired_lock_is_cleared() {
		update_option( QEVM_OPTION_DB_VERSION, 1 );
		update_option( Runner::LOCK_OPTION, (string) ( time() - 1 ) );

		$this->assertGreaterThan( 0, Runner::run() );
		$this->assertFalse( get_option( Runner::LOCK_OPTION ) );
	}

	/**
	 * The legacy post type migration moves 1.0's events and stops.
	 *
	 * @return void
	 */
	public function test_legacy_events_are_migrated_once() {
		global $wpdb;

		$legacy_id = wp_insert_post(
			array(
				'post_type'   => 'events',
				'post_title'  => 'A 2012 event',
				'post_status' => 'publish',
			)
		);

		$migration = null;

		foreach ( Runner::migrations() as $candidate ) {
			if ( 1 === $candidate->version() ) {
				$migration = $candidate;
			}
		}

		$this->assertNotNull( $migration, 'the legacy post type migration should be registered' );

		$moved = $migration->run( Runner::BATCH_SIZE );

		$this->assertSame( 1, $moved );
		$this->assertSame(
			QEVM_POST_TYPE,
			$wpdb->get_var( $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $legacy_id ) )
		);

		$this->assertSame( 0, $migration->run( Runner::BATCH_SIZE ), 'a second pass has nothing to move' );

		wp_delete_post( (int) $legacy_id, true );
	}
}
