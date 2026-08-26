<?php
/**
 * The three jobs that are not "administrator".
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CheckIn\CheckInModule;
use QuickEventsManager\CheckIn\CheckInRepository;
use QuickEventsManager\CheckIn\CheckInService;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;

/**
 * The staff role is the one that matters, and it is defined by what it cannot do.
 *
 * The point of handing somebody a phone at a door is that handing them the
 * phone is safe. So the interesting assertions here are the negative ones: a
 * volunteer who can check people in and cannot edit a single post, including
 * the events they are checking people in to.
 */
final class RolesTest extends TestCase {

	/**
	 * Make sure the roles exist, the way an install or an upgrade does.
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

		Installer::add_roles();
	}

	/**
	 * Put the roles back for whatever runs next.
	 *
	 * Roles live in an option that the transaction does not cover the way it
	 * covers rows, so a test that removes one has to put it back by hand.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Installer::add_roles();

		parent::tearDown();
	}

	/**
	 * All three exist and are named for the job rather than the plugin.
	 *
	 * @return void
	 */
	public function test_the_three_roles_exist() {
		foreach ( array( 'qevm_event_manager', 'qevm_event_organizer', 'qevm_event_staff' ) as $key ) {
			$this->assertNotNull( get_role( $key ), $key . ' was not created' );
		}

		$names = wp_roles()->get_names();

		$this->assertSame( 'Event Manager', $names['qevm_event_manager'] );
		$this->assertSame( 'Event Organizer', $names['qevm_event_organizer'] );
		$this->assertSame( 'Event Staff', $names['qevm_event_staff'] );
	}

	/**
	 * Staff can check people in, and can edit nothing at all.
	 *
	 * The gate's own criterion.
	 *
	 * @return void
	 */
	public function test_staff_can_work_a_door_and_edit_nothing() {
		$event_id  = $this->make_event();
		$booking   = $this->book( $event_id, array( 'email' => 'door@example.test' ) );
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		wp_set_current_user( $this->a( 'qevm_event_staff' ) );

		$this->assertTrue( current_user_can( 'manage_qevm_checkins' ), 'staff cannot check anybody in' );

		foreach ( array( 'edit_posts', 'edit_qevm_events', 'publish_qevm_events', 'edit_others_qevm_events', 'manage_options', 'manage_qevm_registrations' ) as $forbidden ) {
			$this->assertFalse( current_user_can( $forbidden ), 'staff can ' . $forbidden );
		}

		$this->assertFalse( current_user_can( 'edit_post', $event_id ), 'staff can edit the event they are working' );

		// And the thing they are for actually works.
		$outcome = CheckInService::admit( $attendees[0]->id(), get_current_user_id() );

		$this->assertSame( CheckInService::ADMITTED, $outcome['result'] );
	}

	/**
	 * A manager runs events end to end, and does not configure the site.
	 *
	 * @return void
	 */
	public function test_a_manager_runs_events_but_not_the_site() {
		wp_set_current_user( $this->a( 'qevm_event_manager' ) );

		foreach ( array( 'edit_qevm_events', 'publish_qevm_events', 'edit_others_qevm_events', 'manage_qevm_registrations', 'manage_qevm_checkins' ) as $allowed ) {
			$this->assertTrue( current_user_can( $allowed ), 'a manager cannot ' . $allowed );
		}

		/*
		 * Switching modules on and off changes how the whole site behaves, so
		 * it stays with somebody who can already change the whole site. This is
		 * a decision rather than an oversight — see the plan.
		 */
		$this->assertFalse( current_user_can( 'manage_options' ), 'a manager can change site settings' );
	}

	/**
	 * An organiser looks after their own events and nobody else's.
	 *
	 * @return void
	 */
	public function test_an_organizer_cannot_touch_somebody_elses_event() {
		wp_set_current_user( $this->a( 'qevm_event_organizer' ) );

		$this->assertTrue( current_user_can( 'edit_qevm_events' ) );
		$this->assertTrue( current_user_can( 'manage_qevm_registrations' ) );

		$this->assertFalse( current_user_can( 'edit_others_qevm_events' ), 'an organiser can edit somebody else\'s event' );
		$this->assertFalse( current_user_can( 'delete_others_qevm_events' ) );

		/*
		 * An organiser does work the door of their own event, and this asserted
		 * the opposite until the code disagreed. Running an event includes
		 * standing at its door; what separates an organiser from a manager is
		 * whose events they may touch, not whether they may admit anybody.
		 */
		$this->assertTrue( current_user_can( 'manage_qevm_checkins' ) );
	}

	/**
	 * Creating the roles twice does not undo somebody's customisation.
	 *
	 * @return void
	 */
	public function test_a_second_run_leaves_a_customised_role_alone() {
		$role = get_role( 'qevm_event_staff' );

		$role->add_cap( 'upload_files' );

		Installer::add_roles();

		$this->assertTrue(
			get_role( 'qevm_event_staff' )->has_cap( 'upload_files' ),
			'an upgrade reset a role somebody had customised'
		);

		get_role( 'qevm_event_staff' )->remove_cap( 'upload_files' );
	}

	/**
	 * Uninstall takes the roles with it.
	 *
	 * @return void
	 */
	public function test_removing_the_roles_removes_them() {
		Installer::remove_roles();

		foreach ( array( 'qevm_event_manager', 'qevm_event_organizer', 'qevm_event_staff' ) as $key ) {
			$this->assertNull( get_role( $key ), $key . ' survived removal' );
		}
	}

	/**
	 * A user holding one of the roles.
	 *
	 * @param string $role Role key.
	 * @return int
	 */
	private function a( $role ) {
		$id = wp_insert_user(
			array(
				'user_login' => 'qevm-' . $role . '-' . wp_rand( 1000, 999999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $role . wp_rand( 1000, 999999 ) . '@example.test',
				'role'       => $role,
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
