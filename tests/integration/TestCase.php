<?php
/**
 * The base every integration test extends.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;

/**
 * Real WordPress, real MySQL, and the isolation that makes them safe to test in.
 *
 * Each test runs inside a database transaction that is rolled back afterwards,
 * which is how the WordPress test library does it and for the same reason: it
 * is the only cleanup that cannot be forgotten. Everything a test writes —
 * posts, meta, options, and the plugin's own tables — disappears when it ends,
 * whether it passed, failed or threw.
 *
 * Two things the transaction cannot undo, both handled here:
 *
 * - **Caches.** WordPress remembers what it read, and after a rollback those
 *   rows are gone. The cache is flushed after every test.
 * - **Hooks.** A filter added by a test would otherwise still be attached
 *   during the next one. They are snapshotted and put back.
 *
 * And one it genuinely cannot: **DDL.** MySQL commits implicitly on CREATE,
 * ALTER, DROP and TRUNCATE, which ends the transaction and takes the rollback
 * with it. A test that runs dbDelta or a migration has to put the schema back
 * itself; restore_schema() is here for exactly that, and those tests say so.
 *
 * @since 26.0
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Hooks as they were before this test ran.
	 *
	 * @var array<string, \WP_Hook>|null
	 */
	private $hooks_before = null;

	/**
	 * Open a transaction and start from a known state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;

		parent::setUp();

		$this->backup_hooks();

		/*
		 * Autocommit has to go off first. With it on, MySQL commits each
		 * statement as it runs and START TRANSACTION would be the only thing
		 * this rolled back.
		 */
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );

		$this->enable_registration();
		$this->empty_plugin_tables();
	}

	/**
	 * Undo everything the test did.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
		$wpdb->query( 'SET autocommit = 1' );

		/*
		 * The rows the cache is holding no longer exist. Flushing after the
		 * rollback rather than before it is the order that matters: the other
		 * way round leaves everything the rollback removed sitting in memory
		 * for the next test to read.
		 */
		wp_cache_flush();

		$this->restore_hooks();

		parent::tearDown();
	}

	/**
	 * Remember every hook currently registered.
	 *
	 * The WP_Hook objects are cloned rather than referenced, because a shallow
	 * copy of the array hands back the same objects a test is about to add
	 * callbacks to — and restoring that restores nothing.
	 *
	 * @return void
	 */
	private function backup_hooks() {
		global $wp_filter;

		$this->hooks_before = array();

		foreach ( $wp_filter as $hook_name => $hook ) {
			$this->hooks_before[ $hook_name ] = clone $hook;
		}
	}

	/**
	 * Put the hooks back as they were.
	 *
	 * @return void
	 */
	private function restore_hooks() {
		global $wp_filter;

		if ( null === $this->hooks_before ) {
			return;
		}

		$wp_filter = $this->hooks_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the global this test borrowed; see above.

		$this->hooks_before = null;
	}

	/**
	 * Switch the registration module on and make sure its tables exist.
	 *
	 * A fresh install enables events only, so anything about bookings has to
	 * ask for the module first — the same call the Features screen makes, not a
	 * shortcut around it.
	 *
	 * @return void
	 */
	protected function enable_registration() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		if ( ! Repository::table_exists() || ! AttendeeRepository::table_exists() ) {
			( new RegistrationModule() )->activate();
		}
	}

	/**
	 * Empty every table the plugin owns.
	 *
	 * The transaction would roll a test's own writes back anyway. This is for
	 * rows left behind by a test that committed by touching the schema, and for
	 * making a failure about row counts mean what it says.
	 *
	 * DELETE rather than TRUNCATE, deliberately: TRUNCATE is DDL in MySQL and
	 * would commit the transaction this suite depends on.
	 *
	 * @return void
	 */
	protected function empty_plugin_tables() {
		global $wpdb;

		foreach ( array( 'registrations', 'attendees', 'attendee_meta', 'email_queue', 'occurrences' ) as $table ) {
			$name = Installer::table( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture; the name comes from a fixed list in this method.
			$wpdb->query( "DELETE FROM {$name}" );
		}
	}

	/**
	 * Commit what has been written, and stop rolling back.
	 *
	 * For the two situations where the transaction is not an option: another
	 * process has to see the fixtures (it has its own connection, and can only
	 * read committed rows), or the test has already committed by running DDL.
	 *
	 * Switching autocommit back on is the part that is easy to miss. A bare
	 * COMMIT ends the transaction but leaves autocommit off, so the very next
	 * statement silently opens another one — and tearDown()'s rollback then
	 * undoes the test's own cleanup. That is not hypothetical: it is why the
	 * capacity race left an event behind on every run until the tests database
	 * was looked at directly.
	 *
	 * A test that calls this owns its cleanup.
	 *
	 * @return void
	 */
	protected function commit_fixtures() {
		global $wpdb;

		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
	}

	/**
	 * Rebuild the plugin's schema after a test has changed it.
	 *
	 * Anything that runs dbDelta or a migration has already committed, so the
	 * rollback in tearDown() will not put the tables back. Call this from the
	 * test that did it.
	 *
	 * @return void
	 */
	protected function restore_schema() {
		global $wpdb;

		foreach ( array( 'registrations', 'attendees', 'attendee_meta', 'email_queue', 'occurrences' ) as $table ) {
			$name = Installer::table( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture rebuilding its own tables.
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
		}

		/*
		 * The real upgrade path rather than a hand-written CREATE: it activates
		 * every enabled module, which is the one place that knows which tables
		 * exist and what shape they are.
		 */
		Installer::upgrade_schema();

		/*
		 * And then the modules that are not enabled right now, because the DROP
		 * above took their tables too. upgrade_schema() only reaches the modules
		 * the option currently lists, and setUp() rewrites that option for every
		 * test — so without this, the first test to call restore_schema() leaves
		 * every later test running against a table that is not there. It shows
		 * up as a wall of "table doesn't exist" from the row counters rather
		 * than as a failure, which is worse.
		 */
		( new \QuickEventsManager\CustomFields\CustomFieldsModule() )->activate();

		update_option( QEVM_OPTION_DB_VERSION, QEVM_DB_VERSION );

		/*
		 * The DROP above already committed, so this option write is sitting in
		 * a fresh transaction that tearDown() is about to roll back — which
		 * would leave the next test looking at whatever version the migration
		 * test happened to set.
		 */
		$this->commit_fixtures();
	}

	/**
	 * Create a published event that is open for registration.
	 *
	 * Times are offsets from now rather than literals, because a fixture with a
	 * hard-coded year is a test that starts failing on a date nobody chose.
	 *
	 * @param array<string, mixed> $args Overrides: title, status, capacity, starts_in, duration, registration.
	 * @return int Post id.
	 */
	protected function make_event( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'        => 'Integration event',
				'status'       => 'publish',
				'capacity'     => 0,
				'starts_in'    => WEEK_IN_SECONDS,
				'duration'     => HOUR_IN_SECONDS,
				'registration' => true,
			)
		);

		$start = time() + (int) $args['starts_in'];

		/*
		 * meta_input rather than update_post_meta() afterwards, because core
		 * writes it before `save_post` fires (wp-includes/post.php: meta at
		 * 5018, the action at 5182). That is the order a real editor save has,
		 * and it is the order the occurrence sync depends on — meta set after
		 * the fact fires no hook at all, so the fixture would have dates and no
		 * occurrence row, and every date query would be tested against nothing.
		 */
		$meta = array(
			Meta::START_UTC => gmdate( 'Y-m-d H:i:s', $start ),
			Meta::END_UTC   => gmdate( 'Y-m-d H:i:s', $start + (int) $args['duration'] ),
			Meta::CAPACITY  => (string) (int) $args['capacity'],
		);

		if ( $args['registration'] ) {
			$meta[ Meta::REGISTRATION_ENABLED ] = '1';
		}

		$event_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE,
				'post_title'  => (string) $args['title'],
				'post_status' => (string) $args['status'],
				'meta_input'  => $meta,
			)
		);

		$this->assertGreaterThan( 0, $event_id, 'the event fixture could not be created' );

		return (int) $event_id;
	}

	/**
	 * Register somebody for an event, through the service the form uses.
	 *
	 * @param int                  $event_id Event.
	 * @param array<string, mixed> $input    Overrides for the submitted fields.
	 * @return \QuickEventsManager\Registration\Registration|\WP_Error
	 */
	protected function book( $event_id, array $input = array() ) {
		return ( new RegistrationService() )->create(
			$event_id,
			wp_parse_args(
				$input,
				array(
					'name'     => 'Ada Lovelace',
					'email'    => 'ada@example.com',
					'phone'    => '',
					'quantity' => 1,
					'guests'   => array(),
					'consent'  => true,
				)
			)
		);
	}

	/**
	 * Rows in one of the plugin's tables.
	 *
	 * @param string $table Unprefixed name, e.g. 'attendees'.
	 * @param string $where Optional WHERE clause, without the keyword.
	 * @return int
	 */
	protected function count_rows( $table, $where = '1=1' ) {
		global $wpdb;

		$name = Installer::table( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture; both fragments are written by the test, never by input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$name} WHERE {$where}" );
	}

	/**
	 * Stop the rate limiter and the mailer interfering with a test.
	 *
	 * Neither is what is under test anywhere in this suite, and both make a
	 * failure harder to read: the limiter turns the thirty-first booking into a
	 * refusal, and wp_mail() has nowhere to deliver inside a container.
	 *
	 * @return void
	 */
	protected function quieten_registration() {
		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_attendee_email', '__return_empty_array' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );
	}

	/**
	 * Fail if a value is a WP_Error, reporting what it said.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $message Optional context.
	 * @return void
	 */
	protected function assertNotWPError( $value, $message = '' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches PHPUnit's own assertion naming.
		if ( is_wp_error( $value ) ) {
			$this->fail(
				trim( $message . ' ' . $value->get_error_code() . ': ' . $value->get_error_message() )
			);
		}

		$this->assertNotInstanceOf( \WP_Error::class, $value );
	}
}
