<?php
/**
 * Whether the indexes a schema declares are really there.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CheckIn\CheckInModule;
use QuickEventsManager\CheckIn\CheckInRepository;
use QuickEventsManager\Install\Installer;

/**
 * What dbDelta reports is what it decided to do, not what the database did.
 *
 * It compares the statement against the table, prints "Added index" for anything
 * missing, and never looks again — so an `ALTER` that MySQL refuses is reported
 * as a success. That is not a hypothetical: it is how C8.1 nearly shipped a
 * check-in table whose uniqueness guarantee had not been created, because rows
 * already violated it.
 *
 * A missing index is not a visible failure. It is a query that scans, or a
 * guarantee that quietly is not one.
 *
 * **DDL commits the transaction this suite runs in**, so everything here works
 * on the check-in table and puts it back afterwards.
 */
final class IndexVerificationTest extends TestCase {

	/**
	 * The check-in table, present.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->switch_module_on( CheckInModule::ID );

		if ( ! CheckInRepository::table_exists() ) {
			( new CheckInModule() )->activate();
		}

		$this->restore_schema();
	}

	/**
	 * A healthy table reports nothing missing.
	 *
	 * @return void
	 */
	public function test_a_healthy_table_is_quiet() {
		$this->assertSame( array(), Installer::verify_indexes( CheckInRepository::schema() ) );
	}

	/**
	 * An index that is gone is reported.
	 *
	 * @return void
	 */
	public function test_a_missing_index_is_found() {
		global $wpdb;

		$table = CheckInRepository::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removing an index on purpose, to prove it is noticed.
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `attendee_occurrence`" );

		$missing = Installer::verify_indexes( CheckInRepository::schema() );

		$this->assertSame( array( 'attendee_occurrence' ), $missing );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Putting it back.
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `attendee_occurrence` (`attendee_id`, `occurrence_id`)" );
	}

	/**
	 * And something listens.
	 *
	 * A verification nobody hears about is a verification that changes nothing.
	 *
	 * @return void
	 */
	public function test_a_missing_index_is_announced() {
		global $wpdb;

		$table = CheckInRepository::table();
		$heard = array();

		add_action(
			'qevm_indexes_missing',
			static function ( $named, $missing ) use ( &$heard ) {
				$heard[] = array( $named, $missing );
			},
			10,
			2
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removing an index on purpose.
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `occurrence_time`" );

		Installer::verify_indexes( CheckInRepository::schema() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Putting it back.
		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `occurrence_time` (`occurrence_id`, `checked_in_at`)" );

		$this->assertCount( 1, $heard );
		$this->assertSame( $table, $heard[0][0] );
		$this->assertSame( array( 'occurrence_time' ), $heard[0][1] );
	}

	/**
	 * Running the schema again puts a dropped index back.
	 *
	 * The ordinary case, and the reason verification is worth having: dbDelta
	 * does restore an index when the data allows it, and now something checks.
	 *
	 * @return void
	 */
	public function test_running_the_schema_restores_a_dropped_index() {
		global $wpdb;

		$table = CheckInRepository::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removing an index on purpose.
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `occurrence_time`" );

		Installer::run_schema( CheckInRepository::schema() );

		$this->assertSame(
			array(),
			Installer::verify_indexes( CheckInRepository::schema() ),
			'the table should be whole again'
		);
	}

	/**
	 * An index that serves no query is removed rather than maintained.
	 *
	 * `occ_status` on the ticket types table was written on every insert and
	 * read by nothing: every query filters on `event_id`, and `occurrence_id`
	 * is stored and never looked at. Stage 7 recorded it as worth revisiting in
	 * stage 10's performance pass instead of pretending it earned its place.
	 *
	 * The column stays — it is the key a per-date ticket type will use, and
	 * unlike the per-order limits that went in C9.1 it promises nobody that
	 * anything is enforced.
	 *
	 * @return void
	 */
	public function test_an_index_nothing_reads_is_dropped() {
		global $wpdb;

		if ( ! \QuickEventsManager\Tickets\TicketTypeRepository::table_exists() ) {
			( new \QuickEventsManager\Tickets\TicketsModule() )->activate();
		}

		$table = \QuickEventsManager\Tickets\TicketTypeRepository::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Putting the old index back so the drop has something to remove.
		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `occ_status` (`occurrence_id`, `status`)" );

		Installer::drop_retired_columns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the schema back.
		$named = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), 2 );

		$this->assertNotContains( 'occ_status', (array) $named, 'the index should be gone' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the schema back.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.

		$this->assertContains( 'occurrence_id', (array) $columns, 'and the column should not be' );

		$this->restore_schema();
	}

	/**
	 * Every index in the statement is checked, including the primary key.
	 *
	 * @return void
	 */
	public function test_the_whole_declaration_is_read() {
		$found = new \ReflectionMethod( Installer::class, 'indexes_in' );

		$found->setAccessible( true );

		$this->assertSame(
			array( 'PRIMARY', 'attendee_occurrence', 'occurrence_time' ),
			$found->invoke( null, CheckInRepository::schema() )
		);
	}
}
