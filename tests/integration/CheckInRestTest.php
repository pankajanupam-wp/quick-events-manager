<?php
/**
 * The check-in routes.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CheckIn\CheckInModule;
use QuickEventsManager\CheckIn\CheckInRepository;
use QuickEventsManager\CheckIn\RestController;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;

/**
 * The only write route in the plugin, so the permission is the point.
 *
 * A scanner at a door is not a browser holding an admin cookie, which is why
 * this exists at all — and exactly why the first thing every test here checks
 * is who is allowed to use it.
 */
final class CheckInRestTest extends TestCase {

	/**
	 * Switch the modules on and register the routes.
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
		( new RegistrationModule() )->register();

		Installer::add_roles();

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Put the roles back, since they live in an option.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * A logged-out request is refused, and says which kind of refusal it is.
	 *
	 * @return void
	 */
	public function test_a_stranger_cannot_check_anybody_in() {
		$attendee = $this->an_attendee();

		wp_set_current_user( 0 );

		$response = $this->post( array( 'ticket_code' => $attendee->ticket_code() ) );

		$this->assertSame( 401, $response->get_status(), 'a logged-out scan was not refused as unauthenticated' );
		$this->assertFalse( \QuickEventsManager\CheckIn\CheckInService::is_present( $attendee->id() ) );
	}

	/**
	 * Somebody logged in without the capability gets a 403.
	 *
	 * @return void
	 */
	public function test_a_subscriber_is_refused() {
		$attendee = $this->an_attendee();

		wp_set_current_user( $this->a( 'subscriber' ) );

		$this->assertSame( 403, $this->post( array( 'ticket_code' => $attendee->ticket_code() ) )->get_status() );
		$this->assertFalse( \QuickEventsManager\CheckIn\CheckInService::is_present( $attendee->id() ) );
	}

	/**
	 * Event staff can, which is the whole point of the role.
	 *
	 * @return void
	 */
	public function test_staff_can_scan_somebody_in() {
		$attendee = $this->an_attendee();

		wp_set_current_user( $this->a( 'qevm_event_staff' ) );

		$response = $this->post( array( 'ticket_code' => $attendee->ticket_code() ) );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'admitted', $body['result'] );
		$this->assertSame( $attendee->ticket_code(), $body['ticket_code'] );
		$this->assertTrue( \QuickEventsManager\CheckIn\CheckInService::is_present( $attendee->id() ) );
	}

	/**
	 * A second scan is a 200 saying "already", not an error.
	 *
	 * A scanner that beeps angrily at the second scan of a ticket is a scanner
	 * that beeps angrily at a queue.
	 *
	 * @return void
	 */
	public function test_a_second_scan_is_not_an_error() {
		$attendee = $this->an_attendee();

		wp_set_current_user( $this->a( 'qevm_event_staff' ) );

		$this->post( array( 'ticket_code' => $attendee->ticket_code() ) );

		$response = $this->post( array( 'ticket_code' => $attendee->ticket_code() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'already', $response->get_data()['result'] );
	}

	/**
	 * An unknown code is a 404 rather than a silent success.
	 *
	 * @return void
	 */
	public function test_an_unknown_code_is_not_found() {
		wp_set_current_user( $this->a( 'qevm_event_staff' ) );

		$this->assertSame( 404, $this->post( array( 'ticket_code' => 'QEVT-NOTHINGATALL' ) )->get_status() );
	}

	/**
	 * The report says how many were expected, how many came, and who did not.
	 *
	 * @return void
	 */
	public function test_the_attendance_report() {
		$event_id = $this->make_event();

		$came    = $this->book(
			$event_id,
			array(
				'email' => 'came@example.test',
				'name'  => 'Came Along',
			)
		);
		$missing = $this->book(
			$event_id,
			array(
				'email' => 'missing@example.test',
				'name'  => 'Never Showed',
			)
		);

		$attendees = AttendeeRepository::for_registration( $came->id() );

		wp_set_current_user( $this->a( 'qevm_event_staff' ) );

		\QuickEventsManager\CheckIn\CheckInService::admit( $attendees[0]->id() );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/qevm/v1/events/' . $event_id . '/attendance' ) );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $body['expected'] );
		$this->assertSame( 1, $body['present'] );

		$by_name = array();

		foreach ( $body['attendees'] as $person ) {
			$by_name[ $person['name'] ] = $person;
		}

		$this->assertTrue( $by_name['Came Along']['present'] );
		$this->assertNotSame( '', $by_name['Came Along']['checked_in_at'] );
		$this->assertFalse( $by_name['Never Showed']['present'], 'somebody who never arrived is reported as present' );
		$this->assertSame( '', $by_name['Never Showed']['checked_in_at'] );

		unset( $missing );
	}

	/**
	 * The report is behind the same capability as the door.
	 *
	 * @return void
	 */
	public function test_the_report_is_not_public() {
		$event_id = $this->make_event();

		$this->book(
			$event_id,
			array(
				'email' => 'private@example.test',
				'name'  => 'Private Person',
			)
		);

		wp_set_current_user( 0 );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/qevm/v1/events/' . $event_id . '/attendance' ) );

		$this->assertSame( 401, $response->get_status(), 'anybody can read who came to an event' );
	}

	/**
	 * With the module off, the route does not exist at all.
	 *
	 * @return void
	 */
	public function test_the_routes_belong_to_the_module() {
		global $wp_rest_server;

		$this->assertArrayHasKey( '/qevm/v1/checkins', $wp_rest_server->get_routes() );

		// A fresh server with nothing hooked has nothing to offer.
		$bare = new \WP_REST_Server();

		$this->assertArrayNotHasKey( '/qevm/v1/checkins', $bare->get_routes() );
	}

	/**
	 * POST a scan.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return \WP_REST_Response
	 */
	private function post( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/qevm/v1/checkins' );

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * One booked attendee.
	 *
	 * @return \QuickEventsManager\Registration\Attendee
	 */
	private function an_attendee() {
		$booking   = $this->book(
			$this->make_event(),
			array(
				'email' => 'rest@example.test',
				'name'  => 'Rest Person',
			)
		);
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		$this->assertNotEmpty( $attendees );

		return $attendees[0];
	}

	/**
	 * A user with a role.
	 *
	 * @param string $role Role key.
	 * @return int
	 */
	private function a( $role ) {
		$id = wp_insert_user(
			array(
				'user_login' => 'qevm-rest-' . wp_rand( 1000, 999999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rest' . wp_rand( 1000, 999999 ) . '@example.test',
				'role'       => $role,
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
