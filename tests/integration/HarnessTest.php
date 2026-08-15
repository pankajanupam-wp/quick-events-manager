<?php
/**
 * Proves the harness is real before anything relies on it.
 *
 * A test suite that boots something other than what it claims to boot is worse
 * than no suite: it reports green about a WordPress nobody ships. These checks
 * exist so that "the integration tests passed" means the plugin ran inside real
 * WordPress, against real MySQL, with its schema in place.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Install\Migrations\Runner;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;

/**
 * The environment the rest of the suite assumes.
 */
final class HarnessTest extends TestCase {

	/**
	 * Title given to the post that proves rollback works.
	 */
	const MARKER = 'qevm-rollback-marker';

	/**
	 * Id of that post, carried to the test that checks it is gone.
	 *
	 * @var int|null
	 */
	private static $marked_event = null;

	/**
	 * This is WordPress, not a stub of it.
	 *
	 * @return void
	 */
	public function test_this_is_real_wordpress() {
		$this->assertDirectoryExists( ABSPATH );
		$this->assertFileExists( ABSPATH . 'wp-includes/version.php' );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+/', get_bloginfo( 'version' ) );
	}

	/**
	 * And a real database, which is the half the unit suite cannot reach.
	 *
	 * @return void
	 */
	public function test_there_is_a_real_database() {
		global $wpdb;

		$this->assertInstanceOf( \wpdb::class, $wpdb );
		$this->assertNotEmpty( $wpdb->get_var( 'SELECT VERSION()' ) );
		$this->assertSame( '', $wpdb->last_error );

		// InnoDB, or the transaction this suite relies on is not one.
		$this->assertSame(
			'InnoDB',
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					Repository::table()
				)
			)
		);
	}

	/**
	 * The plugin is loaded, and loaded the way a site loads it.
	 *
	 * @return void
	 */
	public function test_the_plugin_is_loaded() {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+/', QEVM_VERSION );
		$this->assertInstanceOf( \QuickEventsManager\Plugin::class, \QuickEventsManager\Plugin::instance() );

		// The key that must never change again. See docs/adr/0002.
		$this->assertSame( 'qevm_event', QEVM_POST_TYPE );
	}

	/**
	 * Activation ran, so the post type and its taxonomies are registered.
	 *
	 * @return void
	 */
	public function test_the_post_type_is_registered() {
		$this->assertTrue( post_type_exists( QEVM_POST_TYPE ) );
		$this->assertTrue( taxonomy_exists( QEVM_TAX_CATEGORY ) );
		$this->assertTrue( taxonomy_exists( QEVM_TAX_TAG ) );
	}

	/**
	 * Activation granted the capabilities, including the one nothing reads yet.
	 *
	 * @return void
	 */
	public function test_activation_granted_the_capabilities() {
		$role = get_role( 'administrator' );

		$this->assertNotNull( $role );

		foreach ( Installer::capabilities( true ) as $capability ) {
			$this->assertTrue( $role->has_cap( $capability ), $capability . ' was not granted' );
		}
	}

	/**
	 * The schema is present and stamped as current.
	 *
	 * @return void
	 */
	public function test_the_schema_is_installed_and_current() {
		$this->assertTrue( OccurrenceRepository::table_exists() );
		$this->assertTrue( Repository::table_exists() );
		$this->assertTrue( AttendeeRepository::table_exists() );

		$this->assertFalse( Runner::needs_upgrade() );
		$this->assertSame( Runner::target_version(), Runner::current_version() );
	}

	/**
	 * Module state is real: the base class switched registration on.
	 *
	 * @return void
	 */
	public function test_the_registration_module_is_enabled() {
		$this->assertTrue(
			\QuickEventsManager\Plugin::instance()->registry()->is_enabled( RegistrationModule::ID )
		);
	}

	/**
	 * The helpers produce something the plugin recognises.
	 *
	 * @return void
	 */
	public function test_the_event_helper_makes_a_usable_event() {
		$event_id = $this->make_event( array( 'capacity' => 5 ) );
		$event    = new \QuickEventsManager\Events\Event( $event_id );

		$this->assertTrue( $event->is_valid() );
		$this->assertFalse( $event->has_ended() );
		$this->assertTrue( \QuickEventsManager\Registration\RegistrationService::is_open( $event ) );
		$this->assertSame( 5, (int) $event->meta( \QuickEventsManager\Events\Meta::CAPACITY, 0 ) );
	}

	/**
	 * Writes really reach the database, rather than a cache in front of it.
	 *
	 * @return void
	 */
	public function test_a_booking_reaches_the_database() {
		global $wpdb;

		$this->quieten_registration();

		$event_id     = $this->make_event();
		$registration = $this->book( $event_id, array( 'quantity' => 2 ) );

		$this->assertNotWPError( $registration );

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT booker_email FROM %i WHERE id = %d',
				Repository::table(),
				$registration->id()
			)
		);

		$this->assertSame( 'ada@example.com', $stored );
		$this->assertSame( 2, $this->count_rows( 'attendees' ) );
	}

	/**
	 * One test cannot see another's rows.
	 *
	 * The transaction is what makes this suite safe to write without every test
	 * cleaning up after itself, and a harness whose isolation quietly stops
	 * working does not fail — it starts passing for the wrong reasons. These
	 * two tests are the alarm. The booking made just above is gone by the time
	 * this one runs, and the event with it.
	 *
	 * @return void
	 */
	public function test_the_previous_test_left_nothing_behind() {
		$this->assertSame( 0, $this->count_rows( 'registrations' ) );
		$this->assertSame( 0, $this->count_rows( 'attendees' ) );

		self::$marked_event = $this->make_event( array( 'title' => self::MARKER ) );

		$this->assertNotEmpty( get_post( self::$marked_event ) );
	}

	/**
	 * Posts are rolled back too, not just the plugin's own tables.
	 *
	 * Keyed to the post the test above created rather than to the table being
	 * empty, so this says something true on a site that has events of its own.
	 *
	 * @return void
	 */
	public function test_posts_do_not_survive_between_tests() {
		$this->assertNotNull( self::$marked_event, 'the test that creates the marker must run first' );
		$this->assertNull( get_post( self::$marked_event ) );

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'      => QEVM_POST_TYPE,
					'post_status'    => 'any',
					'title'          => self::MARKER,
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			)
		);
	}

	/**
	 * A filter added by one test is not still attached during the next.
	 *
	 * Three were added by quieten_registration() in the test above. If hooks
	 * leaked, the rate limit would still be filtered to zero here.
	 *
	 * @return void
	 */
	public function test_hooks_do_not_leak_between_tests() {
		$this->assertFalse( has_filter( 'qevm_registration_rate_limit', '__return_zero' ) );
		$this->assertFalse( has_filter( 'qevm_attendee_email', '__return_empty_array' ) );

		$this->assertSame(
			RegistrationService::RATE_LIMIT,
			(int) apply_filters( 'qevm_registration_rate_limit', RegistrationService::RATE_LIMIT )
		);
	}

	/**
	 * The schema helper really does put the tables back.
	 *
	 * DDL commits, so this is the one path the transaction cannot cover — and
	 * the migration tests depend on it working.
	 *
	 * @return void
	 */
	public function test_the_schema_can_be_rebuilt_after_ddl() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', AttendeeRepository::table() ) );

		$this->assertEmpty(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', AttendeeRepository::table() ) )
		);

		$this->restore_schema();

		$this->assertNotEmpty(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', AttendeeRepository::table() ) )
		);
		$this->assertSame( 0, $this->count_rows( 'attendees' ) );
	}
}
