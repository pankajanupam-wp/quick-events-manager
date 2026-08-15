<?php
/**
 * Eight people clicking Register at the same instant for the last place.
 *
 * This is the test the capacity design exists for, and it is the reason the
 * plugin inserts first and decides afterwards. Counting the existing bookings,
 * comparing against the capacity and then inserting has a window between the
 * count and the insert: two submissions read the same count, both decide there
 * is room, and the event oversells. Wrapping that in a transaction does not
 * close it either — under REPEATABLE READ, which is MySQL's default, both
 * transactions read from the same snapshot and still agree.
 *
 * Nothing single-threaded can demonstrate any of that. So this spawns real
 * processes, each with its own database connection, and has them wait for a
 * shared instant before submitting.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\Repository;

/**
 * Capacity under genuine concurrency.
 */
final class CapacityRaceTest extends TestCase {

	/**
	 * How many processes go for the place.
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
	 * Undo what the committed fixtures left behind.
	 *
	 * The transaction the rest of the suite relies on cannot help here: the
	 * child processes are separate connections, and they can only see rows that
	 * have been committed. So this test commits, and owns its own cleanup.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( $this->event_id > 0 ) {
			Repository::delete_for_event( $this->event_id );
			wp_delete_post( $this->event_id, true );

			$this->event_id = 0;
		}

		parent::tearDown();
	}

	/**
	 * Eight simultaneous bookings for one place produce exactly one confirmed.
	 *
	 * @return void
	 */
	public function test_eight_simultaneous_bookings_oversell_nothing() {
		global $wpdb;

		$this->event_id = $this->make_event( array( 'capacity' => 1 ) );

		// The children are other connections; they can only see committed rows.
		$this->commit_fixtures();

		$results = $this->race( $this->event_id );

		$this->assertCount( self::RACERS, $results, 'every process should have reported' );

		$confirmed  = array_keys( $results, RegistrationStatus::Confirmed->value, true );
		$waitlisted = array_keys( $results, RegistrationStatus::Waitlisted->value, true );

		$this->assertCount(
			1,
			$confirmed,
			'exactly one place existed, so exactly one booking may hold it: ' . wp_json_encode( $results )
		);
		$this->assertCount(
			self::RACERS - 1,
			$waitlisted,
			'everybody else belongs on the waiting list, not in an error: ' . wp_json_encode( $results )
		);

		// And the database agrees with what the processes were told.
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE event_id = %d AND status = %s',
					Repository::table(),
					$this->event_id,
					RegistrationStatus::Confirmed->value
				)
			)
		);

		$this->assertSame(
			1,
			Repository::count_taken( $this->event_id ),
			'a capacity of one must never end up with two places taken'
		);
	}

	/**
	 * Every racer still gets a booking, and every booking still gets its person.
	 *
	 * Losing the race is a waiting list entry, not an error page — and the
	 * attendee rows are created for a waitlisted booking too, so promoting
	 * somebody later is a status change rather than a rebuild.
	 *
	 * @return void
	 */
	public function test_losing_the_race_is_a_waiting_list_place() {
		global $wpdb;

		$this->event_id = $this->make_event( array( 'capacity' => 1 ) );

		$this->commit_fixtures();

		$results = $this->race( $this->event_id );

		$this->assertNotContains(
			'error:qevm_registration_failed',
			$results,
			'a full event must not turn anybody away with an error'
		);

		$registrations = Repository::for_event( $this->event_id, array( 'per_page' => 100 ) );

		$this->assertCount( self::RACERS, $registrations );

		foreach ( $registrations as $registration ) {
			$this->assertSame(
				1,
				AttendeeRepository::count_for_registration( $registration->id() ),
				'booking ' . $registration->code() . ' has no attendee row'
			);
		}

		$this->assertSame( self::RACERS, $this->count_rows( 'attendees' ) );
	}

	/**
	 * Start every process, hold them to one instant, and collect what they say.
	 *
	 * @param int $event_id Event to book.
	 * @return array<int, string> One status per process, in start order.
	 */
	private function race( $event_id ) {
		$script = __DIR__ . '/fixtures/book-one-place.php';
		$start  = microtime( true ) + self::RUN_UP;

		$processes = array();
		$pipes     = array();

		for ( $i = 0; $i < self::RACERS; $i++ ) {
			$command = sprintf(
				'%s %s %s %d %s %s',
				escapeshellarg( PHP_BINARY ),
				escapeshellarg( $script ),
				escapeshellarg( ABSPATH ),
				$event_id,
				escapeshellarg( 'racer' . $i . '@example.com' ),
				escapeshellarg( (string) $start )
			);

			/*
			 * The sniff is right about plugin code — hosts disable this — and
			 * beside the point here. Nothing in the plugin calls it; this is a
			 * test that needs eight processes, and no amount of single-threaded
			 * cleverness can stand in for them.
			 */
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- See above.
			$handle = proc_open(
				$command,
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$process_pipes
			);

			$this->assertIsResource( $handle, 'could not start racer ' . $i );

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

			$this->assertSame( 0, $status, 'racer ' . $i . ' failed: ' . $stderr );

			$results[ $i ] = trim( $stdout );
		}

		return $results;
	}
}
