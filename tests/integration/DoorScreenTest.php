<?php
/**
 * The screen somebody holds at the door.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CheckIn\CheckInModule;
use QuickEventsManager\CheckIn\CheckInRepository;
use QuickEventsManager\CheckIn\CheckInService;
use QuickEventsManager\CheckIn\DoorScreen;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\Repository;

/**
 * Thrown in place of the redirect a handler ends with.
 */
final class DoorRedirected extends \RuntimeException {}

/**
 * Thrown in place of the `wp_die()` a refused request ends with.
 */
final class DoorDied extends \RuntimeException {}

/**
 * Who is expected, who is in, and who may say so.
 *
 * The list is the part that has to be right: somebody standing at a door with a
 * queue needs the people who are coming tonight, not everybody who ever booked
 * the series, and not the person who cancelled last week.
 */
final class DoorScreenTest extends TestCase {

	/**
	 * Where the last redirect went.
	 *
	 * @var string
	 */
	private $redirected_to = '';

	/**
	 * Switch registration and check-in on, and become somebody who may work a door.
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

		wp_set_current_user( $this->an_administrator() );

		$this->redirected_to = '';

		/*
		 * The callback never returns: it throws, which is the point. A filter
		 * that always throws is exactly what this rule is written to catch, and
		 * exactly what a stand-in for "the request ends here" has to be.
		 */
		// @phpstan-ignore return.missing
		add_filter( 'wp_redirect', array( $this, 'catch_redirect' ), 10, 1 );

		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new DoorDied( esc_html( is_string( $message ) ? $message : 'refused' ) );
				};
			}
		);
	}

	/**
	 * Record a redirect and stop the request there.
	 *
	 * @param string $location Where the code wanted to go.
	 * @return never
	 * @throws DoorRedirected Always.
	 */
	public function catch_redirect( $location ) {
		$this->redirected_to = (string) $location;

		throw new DoorRedirected( esc_html( (string) $location ) );
	}

	/**
	 * The list holds everybody with a place, and nobody else.
	 *
	 * @return void
	 */
	public function test_the_list_is_who_is_actually_coming() {
		/*
		 * Capacity one, and the cancelled booking is one that was already
		 * waiting. The first version of this fixture had a confirmed booking
		 * cancelled while somebody waited — which promoted the waiting person,
		 * correctly, and left the test asserting that a confirmed attendee was
		 * missing from the door list.
		 */
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$coming    = $this->book(
			$event_id,
			array(
				'email' => 'coming@example.test',
				'name'  => 'Ada Coming',
			)
		);
		$waiting   = $this->book(
			$event_id,
			array(
				'email' => 'waiting@example.test',
				'name'  => 'Cy Waiting',
			)
		);
		$cancelled = $this->book(
			$event_id,
			array(
				'email' => 'gone@example.test',
				'name'  => 'Bob Gone',
			)
		);

		Repository::update_status( $cancelled->id(), RegistrationStatus::Cancelled );

		$this->assertSame( RegistrationStatus::Confirmed, $coming->status(), 'the fixture nobody has a place' );
		$this->assertSame( RegistrationStatus::Waitlisted, $waiting->status(), 'the fixture nobody is waiting' );
		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $waiting->id() )->status(),
			'cancelling a waiting booking promoted somebody, so this proves nothing about the waiting list'
		);

		$markup = $this->render( $event_id );

		$this->assertStringContainsString( 'Ada Coming', $markup );
		$this->assertStringNotContainsString( 'Bob Gone', $markup, 'a cancelled booking is on the door list' );
		$this->assertStringNotContainsString( 'Cy Waiting', $markup, 'somebody with no place is on the door list' );

		unset( $coming );
	}

	/**
	 * The count says how many are in, out of how many are coming.
	 *
	 * @return void
	 */
	public function test_the_count_is_the_question_being_asked() {
		$event_id = $this->make_event();

		$first  = $this->book( $event_id, array( 'email' => 'one@example.test' ) );
		$second = $this->book( $event_id, array( 'email' => 'two@example.test' ) );

		$this->assertMatchesRegularExpression( '/0 of 2 in/', $this->render( $event_id ) );

		$attendees = AttendeeRepository::for_registration( $first->id() );

		CheckInService::admit( $attendees[0]->id() );

		$this->assertMatchesRegularExpression( '/1 of 2 in/', $this->render( $event_id ) );

		unset( $second );
	}

	/**
	 * Pressing the button checks somebody in and comes back to the same door.
	 *
	 * @return void
	 */
	public function test_the_button_admits_and_returns_to_the_same_list() {
		$event_id  = $this->make_event();
		$booking   = $this->book( $event_id, array( 'email' => 'button@example.test' ) );
		$attendees = AttendeeRepository::for_registration( $booking->id() );
		$next      = OccurrenceRepository::next_for_event( $event_id );

		$this->press( 'handle_admit', $attendees[0]->id(), $event_id, $next->id(), 'ada' );

		$this->assertTrue( CheckInService::is_present( $attendees[0]->id() ) );
		$this->assertStringContainsString( 'qevm_done=admitted', $this->redirected_to );
		$this->assertStringContainsString( 'event_id=' . $event_id, $this->redirected_to );
		$this->assertStringContainsString( 's=ada', $this->redirected_to, 'the search was lost on the way back' );
	}

	/**
	 * The row for somebody already in offers to undo it.
	 *
	 * @return void
	 */
	public function test_a_person_who_is_in_can_be_taken_back_out() {
		$event_id  = $this->make_event();
		$booking   = $this->book( $event_id, array( 'email' => 'undo@example.test' ) );
		$attendees = AttendeeRepository::for_registration( $booking->id() );
		$next      = OccurrenceRepository::next_for_event( $event_id );

		CheckInService::admit( $attendees[0]->id() );

		$markup = $this->render( $event_id );

		$this->assertStringContainsString( 'qevm_reverse', $markup, 'no way to undo a mistaken check-in' );
		$this->assertMatchesRegularExpression( '/In at /', $markup );

		$this->press( 'handle_reverse', $attendees[0]->id(), $event_id, $next->id() );

		$this->assertFalse( CheckInService::is_present( $attendees[0]->id() ) );
		$this->assertStringContainsString( 'qevm_done=undone', $this->redirected_to );
	}

	/**
	 * Searching narrows the list to the person in front of you.
	 *
	 * @return void
	 */
	public function test_searching_finds_one_person() {
		$event_id = $this->make_event();

		$this->book(
			$event_id,
			array(
				'email' => 'zephyr@example.test',
				'name'  => 'Zephyr Quist',
			)
		);
		$this->book(
			$event_id,
			array(
				'email' => 'other@example.test',
				'name'  => 'Morgan Other',
			)
		);

		$markup = $this->render( $event_id, 0, 'Zephyr' );

		$this->assertStringContainsString( 'Zephyr Quist', $markup );
		$this->assertStringNotContainsString( 'Morgan Other', $markup );

		// And by ticket code, which is what a scanner will send.
		$attendees = AttendeeRepository::find_by_email( 'zephyr@example.test' );

		$this->assertNotEmpty( $attendees );
		$this->assertStringContainsString( 'Zephyr Quist', $this->render( $event_id, 0, $attendees[0]->ticket_code() ) );
	}

	/**
	 * A series shows the date being run, and only its people.
	 *
	 * @return void
	 */
	public function test_a_series_door_shows_one_date() {
		$event_id = $this->make_series();
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->book(
			$event_id,
			array(
				'occurrence_id' => $dates[0]->id(),
				'email'         => 'week1@example.test',
				'name'          => 'Week One',
			)
		);
		$this->book(
			$event_id,
			array(
				'occurrence_id' => $dates[2]->id(),
				'email'         => 'week3@example.test',
				'name'          => 'Week Three',
			)
		);

		$markup = $this->render( $event_id, $dates[2]->id() );

		$this->assertStringContainsString( 'Week Three', $markup );
		$this->assertStringNotContainsString( 'Week One', $markup, 'the door for one date lists another date\'s people' );
		$this->assertStringContainsString( 'qevm-door-date', $markup, 'a series door offers no way to change date' );
	}

	/**
	 * Somebody without the capability cannot work a door.
	 *
	 * @return void
	 */
	public function test_a_subscriber_cannot_check_anybody_in() {
		$event_id  = $this->make_event();
		$booking   = $this->book( $event_id, array( 'email' => 'guarded@example.test' ) );
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		wp_set_current_user( $this->a_subscriber() );

		$refused = false;

		try {
			$this->press( 'handle_admit', $attendees[0]->id(), $event_id, 0 );
		} catch ( DoorDied $died ) {
			$refused = true;

			unset( $died );
		}

		$this->assertTrue( $refused, 'a subscriber checked somebody in' );
		$this->assertFalse( CheckInService::is_present( $attendees[0]->id() ) );
	}

	/**
	 * A form for one person cannot be replayed against another.
	 *
	 * @return void
	 */
	public function test_a_nonce_for_one_person_does_not_admit_another() {
		$event_id  = $this->make_event();
		$one       = $this->book( $event_id, array( 'email' => 'one@example.test' ) );
		$two       = $this->book( $event_id, array( 'email' => 'two@example.test' ) );
		$attendees = array(
			AttendeeRepository::for_registration( $one->id() )[0],
			AttendeeRepository::for_registration( $two->id() )[0],
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Building the request the screen verifies for itself.
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;

		$_POST = wp_slash(
			array(
				'attendee' => (string) $attendees[1]->id(),
				'_wpnonce' => wp_create_nonce( DoorScreen::NONCE . '_' . $attendees[0]->id() ),
			)
		);

		$_REQUEST = $_POST;
		$refused  = false;

		try {
			( new DoorScreen() )->handle_admit();
		} catch ( DoorDied $died ) {
			$refused = true;

			unset( $died );
		} catch ( DoorRedirected $went ) {
			unset( $went );
		} finally {
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		$this->assertTrue( $refused, 'a form for one person admitted another' );
		$this->assertFalse( CheckInService::is_present( $attendees[1]->id() ) );
	}

	/**
	 * With registration off, the door lists nobody rather than breaking.
	 *
	 * @return void
	 */
	public function test_the_door_asks_registration_for_its_list() {
		$event_id = $this->make_event();

		$this->book(
			$event_id,
			array(
				'email' => 'listed@example.test',
				'name'  => 'Listed Person',
			)
		);

		$this->assertStringContainsString( 'Listed Person', $this->render( $event_id ) );

		remove_filter( 'qevm_expected_attendees', array( RegistrationModule::class, 'supply_expected' ), 10 );

		$this->assertStringNotContainsString(
			'Listed Person',
			$this->render( $event_id ),
			'the door reads the attendee table itself instead of asking'
		);
	}

	/**
	 * A ticket code checks somebody in, in one step.
	 *
	 * @return void
	 */
	public function test_a_ticket_code_admits_its_holder() {
		$event_id  = $this->make_event();
		$booking   = $this->book(
			$event_id,
			array(
				'email' => 'scanned@example.test',
				'name'  => 'Scanned Person',
			)
		);
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		$this->submit_code( $attendees[0]->ticket_code(), $event_id );

		$this->assertTrue( CheckInService::is_present( $attendees[0]->id() ) );
		$this->assertStringContainsString( 'qevm_done=admitted', $this->redirected_to );
		// rawurlencode(), so a space is %20 rather than a plus.
		$this->assertStringContainsString( 'Scanned%20Person', $this->redirected_to, 'the door does not say whose ticket that was' );
	}

	/**
	 * Scanning the same ticket twice says when the first one was.
	 *
	 * @return void
	 */
	public function test_scanning_twice_reports_the_first_time() {
		$event_id  = $this->make_event();
		$booking   = $this->book( $event_id, array( 'email' => 'twice@example.test' ) );
		$attendees = AttendeeRepository::for_registration( $booking->id() );

		$this->submit_code( $attendees[0]->ticket_code(), $event_id );
		$this->submit_code( $attendees[0]->ticket_code(), $event_id );

		$this->assertStringContainsString( 'qevm_done=already', $this->redirected_to );
	}

	/**
	 * An unknown code is refused, and says so.
	 *
	 * @return void
	 */
	public function test_an_unknown_code_is_refused() {
		$event_id = $this->make_event();

		$this->submit_code( 'QEVT-NOTHINGHERE', $event_id );

		$this->assertStringContainsString( 'qevm_done=refused', $this->redirected_to );
	}

	/**
	 * The code box is on the screen whether or not any script runs.
	 *
	 * @return void
	 */
	public function test_the_code_box_needs_no_script() {
		$markup = $this->render( $this->make_event() );

		$this->assertStringContainsString( 'name="ticket_code"', $markup );
		$this->assertStringContainsString( 'qevm_admit_code', $markup );
		$this->assertStringContainsString( 'data-qevm-scanner', $markup, 'the script has nowhere to put the camera' );
	}

	/**
	 * The scanner script loads on this screen and nowhere else.
	 *
	 * @return void
	 */
	public function test_the_scanner_loads_only_here() {
		$screen = new DoorScreen();

		$screen->register();
		$screen->add_page();

		$screen->enqueue( 'index.php' );

		$this->assertFalse( wp_script_is( 'qevm-checkin', 'enqueued' ), 'the scanner loaded on somebody else\'s screen' );

		$screen->enqueue( get_plugin_page_hookname( DoorScreen::SLUG, 'edit.php?post_type=' . QEVM_POST_TYPE ) );

		$this->assertTrue( wp_script_is( 'qevm-checkin', 'enqueued' ), 'the door has no scanner' );

		wp_dequeue_script( 'qevm-checkin' );
	}

	/**
	 * The code box refuses a request with no nonce, and a subscriber.
	 *
	 * @return void
	 */
	public function test_the_code_box_is_guarded() {
		$event_id  = $this->make_event();
		$booking   = $this->book( $event_id, array( 'email' => 'guard@example.test' ) );
		$attendees = AttendeeRepository::for_registration( $booking->id() );
		$code      = $attendees[0]->ticket_code();

		$this->assertTrue( $this->code_refused( $code, $event_id, false ), 'a request with no nonce was accepted' );
		$this->assertFalse( CheckInService::is_present( $attendees[0]->id() ) );

		wp_set_current_user( $this->a_subscriber() );

		$this->assertTrue( $this->code_refused( $code, $event_id, true ), 'a subscriber scanned somebody in' );
		$this->assertFalse( CheckInService::is_present( $attendees[0]->id() ) );
	}

	/**
	 * Whether the code box refuses a request.
	 *
	 * @param string $code      Ticket code.
	 * @param int    $event_id  Event id.
	 * @param bool   $with_nonce Whether to send a valid nonce.
	 * @return bool
	 */
	private function code_refused( $code, $event_id, $with_nonce ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Building the request the screen verifies for itself.
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;

		$request = array(
			'ticket_code'   => $code,
			'event_id'      => (string) $event_id,
			'occurrence_id' => '0',
		);

		if ( $with_nonce ) {
			$request['_wpnonce'] = wp_create_nonce( DoorScreen::NONCE . '_code' );
		}

		$_POST    = wp_slash( $request );
		$_REQUEST = $_POST;
		$refused  = false;

		try {
			( new DoorScreen() )->handle_admit_code();
		} catch ( DoorDied $died ) {
			$refused = true;

			unset( $died );
		} catch ( DoorRedirected $went ) {
			unset( $went );
		} finally {
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		return $refused;
	}

	/**
	 * Submit a ticket code the way the box does.
	 *
	 * @param string $code     Ticket code.
	 * @param int    $event_id Event id.
	 * @return void
	 */
	private function submit_code( $code, $event_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Building the request the screen verifies for itself.
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;

		$_POST = wp_slash(
			array(
				'ticket_code'   => $code,
				'event_id'      => (string) $event_id,
				'occurrence_id' => '0',
				'_wpnonce'      => wp_create_nonce( DoorScreen::NONCE . '_code' ),
			)
		);

		$_REQUEST = $_POST;

		try {
			( new DoorScreen() )->handle_admit_code();
		} catch ( DoorRedirected $went ) {
			unset( $went );
		} finally {
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Press one of the row buttons.
	 *
	 * @param string $handler       Method on the screen.
	 * @param int    $attendee_id   Attendee id.
	 * @param int    $event_id      Event id.
	 * @param int    $occurrence_id Date being run.
	 * @param string $search        Search to carry back.
	 * @return void
	 */
	private function press( $handler, $attendee_id, $event_id, $occurrence_id, $search = '' ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Building the request the screen verifies for itself.
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;

		$_POST = wp_slash(
			array(
				'attendee'      => (string) $attendee_id,
				'event_id'      => (string) $event_id,
				'occurrence_id' => (string) $occurrence_id,
				's'             => $search,
				'_wpnonce'      => wp_create_nonce( DoorScreen::NONCE . '_' . $attendee_id ),
			)
		);

		$_REQUEST = $_POST;

		try {
			( new DoorScreen() )->$handler();
		} catch ( DoorRedirected $went ) {
			unset( $went );
		} finally {
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The screen's markup.
	 *
	 * @param int    $event_id      Event id.
	 * @param int    $occurrence_id Date, or 0 for the next one.
	 * @param string $search        Search term.
	 * @return string
	 */
	private function render( $event_id, $occurrence_id = 0, $search = '' ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Writing the request the screen reads.
		$previous = $_GET;
		$_GET     = array( 'event_id' => (string) $event_id );

		if ( $occurrence_id > 0 ) {
			$_GET['occurrence_id'] = (string) $occurrence_id;
		}

		if ( '' !== $search ) {
			$_GET['s'] = $search;
		}

		ob_start();

		try {
			( new DoorScreen() )->render();
		} finally {
			$markup = (string) ob_get_clean();
			$_GET   = $previous;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		return $markup;
	}

	/**
	 * A weekly series with dates on it.
	 *
	 * @return int
	 */
	private function make_series() {
		$event_id = $this->make_event();

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CheckInModule::ID, RecurrenceModule::ID ) );

		update_post_meta( $event_id, Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, Meta::START_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::END_LOCAL, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::START_UTC, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::END_UTC, gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) );
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, 'FREQ=WEEKLY;COUNT=4' );

		OccurrenceSync::sync( $event_id );

		return $event_id;
	}

	/**
	 * An administrator id.
	 *
	 * @return int
	 */
	private function an_administrator() {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1,
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 1;
	}

	/**
	 * Somebody with no business at a door.
	 *
	 * @return int
	 */
	private function a_subscriber() {
		$id = wp_insert_user(
			array(
				'user_login' => 'qevm-door-' . wp_rand( 1000, 999999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'door-' . wp_rand( 1000, 999999 ) . '@example.test',
				'role'       => 'subscriber',
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
