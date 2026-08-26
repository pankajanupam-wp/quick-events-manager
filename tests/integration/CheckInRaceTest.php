<?php
/**
 * Two doors, one person, at the same instant.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CheckIn\CheckInModule;
use QuickEventsManager\CheckIn\CheckInRepository;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\Repository;

/**
 * The stage 8 gate's first criterion, run for real.
 *
 * A large event has two doors and two people on phones, and the same guest
 * walks up to one of them twice, or up to both of them in the confusion. The
 * unique key is what makes that safe, and single-threaded tests cannot show it:
 * they never have two inserts in flight at once.
 */
final class CheckInRaceTest extends TestCase {

	/**
	 * How many processes scan the same ticket.
	 */
	const RACERS = 8;

	/**
	 * Seconds allowed for each process to boot WordPress before the off.
	 */
	const RUN_UP = 3;

	/**
	 * Event created for the race, removed by hand afterwards.
	 *
	 * @var int
	 */
	private $event_id = 0;

	/**
	 * Switch the modules on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enable_registration();

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CheckInModule::ID ) );

		if ( ! CheckInRepository::table_exists() ) {
			( new CheckInModule() )->activate();

			$this->restore_schema();
		}

		( new CheckInModule() )->register();
	}

	/**
	 * Undo what the committed fixtures left behind.
	 *
	 * The child processes are separate connections and can only see committed
	 * rows, so this test commits and owns its own cleanup.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;

		if ( $this->event_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleaning up after a test that had to commit.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE attendee_id > 0', CheckInRepository::table() ) );

			Repository::delete_for_event( $this->event_id );
			wp_delete_post( $this->event_id, true );

			$this->event_id = 0;
		}

		parent::tearDown();
	}

	/**
	 * Eight processes scanning one ticket admit that person exactly once.
	 *
	 * @return void
	 */
	public function test_two_hundred_scans_never_admit_anybody_twice() {
		global $wpdb;

		$this->event_id = $this->make_event();

		$booking   = $this->book(
			$this->event_id,
			array(
				'email' => 'raced@example.test',
				'name'  => 'Raced Person',
			)
		);
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		$this->assertNotEmpty( $attendees, 'the fixture booked nobody' );

		// The children are other connections; they can only see committed rows.
		$this->commit_fixtures();

		$results = $this->race( $attendees[0]->ticket_code() );

		$this->assertCount( self::RACERS, $results, 'every process should have reported' );

		$admitted = array_keys( $results, 'admitted', true );
		$already  = array_keys( $results, 'already', true );

		$this->assertCount(
			1,
			$admitted,
			'one person arrived once, so exactly one process may say so: ' . wp_json_encode( $results )
		);
		$this->assertCount(
			self::RACERS - 1,
			$already,
			'every other process must say "already", not fail: ' . wp_json_encode( $results )
		);

		// And the table agrees with what the processes were told.
		$rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE attendee_id = %d',
				CheckInRepository::table(),
				$attendees[0]->id()
			)
		);

		$this->assertSame( 1, $rows, 'one person has more than one arrival recorded' );
	}

	/**
	 * Start the processes and collect what each reported.
	 *
	 * @param string $code Ticket code every process scans.
	 * @return array<int, string>
	 */
	private function race( $code ) {
		$script = __DIR__ . '/fixtures/check-in-one.php';
		$start  = microtime( true ) + self::RUN_UP;

		$processes = array();
		$pipes     = array();

		for ( $i = 0; $i < self::RACERS; $i++ ) {
			$command = sprintf(
				'%s %s %s %s %s',
				escapeshellarg( PHP_BINARY ),
				escapeshellarg( $script ),
				escapeshellarg( ABSPATH ),
				escapeshellarg( $code ),
				escapeshellarg( (string) $start )
			);

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- A test that needs eight processes; nothing in the plugin calls this.
			$handle = proc_open(
				$command,
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$process_pipes
			);

			$this->assertIsResource( $handle, 'could not start scanner ' . $i );

			$processes[ $i ] = $handle;
			$pipes[ $i ]     = $process_pipes;
		}

		$results = array();

		foreach ( $processes as $i => $handle ) {
			$stdout = (string) stream_get_contents( $pipes[ $i ][1] );
			$stderr = (string) stream_get_contents( $pipes[ $i ][2] );

			fclose( $pipes[ $i ][1] );
			fclose( $pipes[ $i ][2] );

			$status = proc_close( $handle );

			$this->assertSame( 0, $status, 'scanner ' . $i . ' failed: ' . $stderr );

			$results[ $i ] = trim( $stdout );
		}

		return $results;
	}
}
