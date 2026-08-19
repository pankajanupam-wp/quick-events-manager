<?php
/**
 * The screen that makes the per-date operations reachable.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

/*
 * This file builds the requests the screen verifies for itself: every $_POST
 * and $_GET below is written by the test a line earlier, restored a line later,
 * and never read from a browser. The nonce checks being exercised are the ones
 * in DatesScreen, and three tests here exist precisely to prove they refuse.
 */
// phpcs:disable WordPress.Security.NonceVerification.Missing -- See the note above.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- See the note above.

use QuickEventsManager\Domain\OccurrenceStatus;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\OccurrenceSync;
use QuickEventsManager\Recurrence\DatesScreen;
use QuickEventsManager\Recurrence\RecurrenceModule;
use QuickEventsManager\Recurrence\Series;

/**
 * Thrown in place of the redirect a handler ends with.
 */
final class Redirected extends \RuntimeException {}

/**
 * Thrown in place of the `wp_die()` a refused request ends with.
 */
final class Died extends \RuntimeException {}

/**
 * What the buttons do, and what they refuse to do.
 *
 * The handlers redirect and exit, so each one is called through a wrapper that
 * catches the exit and hands back where it was going. That is the part worth
 * checking as well as the row it changed: a handler that acts and then sends
 * somebody to the wrong screen is a handler that looks broken to the person who
 * pressed the button.
 */
final class DatesScreenTest extends TestCase {

	/**
	 * Where the last redirect went.
	 *
	 * @var string
	 */
	private $redirected_to = '';

	/**
	 * Switch recurrence on and become somebody who may edit.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option( QEVM_OPTION_MODULES, array( RecurrenceModule::ID ) );
		wp_set_current_user( $this->an_administrator() );

		$this->redirected_to = '';

		/*
		 * The callback never returns: it throws, which is the point — see
		 * catch_redirect(). A filter that always throws is exactly what
		 * PHPStan's rule is written to catch, and exactly what a test double
		 * for "the request ends here" has to be.
		 */
		// @phpstan-ignore return.missing
		add_filter( 'wp_redirect', array( $this, 'catch_redirect' ), 10, 1 );

		/*
		 * For the whole class, not just the tests that expect a refusal.
		 * wp_die() ends the PHP process, so an unexpected one takes the entire
		 * suite down with a "premature end of process" and no indication of
		 * which assertion would have failed. Throwing turns it into an ordinary
		 * test failure wherever it happens.
		 */
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new Died( esc_html( is_string( $message ) ? $message : 'refused' ) );
				};
			}
		);
	}

	/**
	 * Record a redirect and stop the request there.
	 *
	 * Throwing rather than returning, because every handler calls `exit` on the
	 * line after the redirect — which in a test run ends the PHP process and
	 * takes the whole suite with it. The exception unwinds out of the handler
	 * before that line is reached, which is what a redirect does to a real
	 * request anyway.
	 *
	 * @param string $location Where the code wanted to go.
	 * @return never
	 * @throws Redirected Always.
	 */
	public function catch_redirect( $location ) {
		$this->redirected_to = (string) $location;

		throw new Redirected( esc_html( (string) $location ) );
	}

	/**
	 * Moving a date from the screen moves exactly that date.
	 *
	 * @return void
	 */
	public function test_the_move_button_moves_one_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[1];

		$this->press( 'handle_move', $target->id(), array( 'start_local' => '2026-06-10T20:30' ) );

		$moved = OccurrenceRepository::find( $target->id() );

		$this->assertNotNull( $moved );
		$this->assertSame( '2026-06-10 20:30:00', $moved->start_utc(), 'the event is in UTC, so local and UTC agree here' );
		$this->assertTrue( $moved->is_exception() );
		$this->assertStringContainsString( 'qevm_done=moved', $this->redirected_to );

		// The other three are untouched.
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ) );
	}

	/**
	 * Calling off and putting back are the same button twice.
	 *
	 * @return void
	 */
	public function test_call_off_then_put_back() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[2];

		$this->press( 'handle_cancel', $target->id() );

		$this->assertSame( OccurrenceStatus::Cancelled, OccurrenceRepository::find( $target->id() )->status() );
		$this->assertStringContainsString( 'qevm_done=cancelled', $this->redirected_to );

		$this->press( 'handle_reinstate', $target->id() );

		$back = OccurrenceRepository::find( $target->id() );

		$this->assertNotSame( OccurrenceStatus::Cancelled, $back->status() );
		$this->assertStringContainsString( 'qevm_done=reinstated', $this->redirected_to );
	}

	/**
	 * Resetting hands a date back to the rule.
	 *
	 * @return void
	 */
	public function test_reset_puts_a_moved_date_back_where_the_rule_says() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[1];
		$slot     = $target->recurrence_id();

		$this->press( 'handle_move', $target->id(), array( 'start_local' => '2026-06-13T09:00' ) );
		$this->press( 'handle_restore', $target->id() );

		$restored = OccurrenceRepository::find( $target->id() );

		$this->assertNotNull( $restored );
		$this->assertFalse( $restored->is_exception() );
		$this->assertSame( $slot, $restored->start_utc(), 'the date did not go back to the slot the rule produced' );
		$this->assertStringContainsString( 'qevm_done=restored', $this->redirected_to );
	}

	/**
	 * Splitting sends the organiser to the new half.
	 *
	 * They split the series in order to change what happens from that date on,
	 * and those dates are on the other screen now.
	 *
	 * @return void
	 */
	public function test_split_lands_on_the_new_half() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=8' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->press( 'handle_split', $dates[4]->id() );

		$this->assertStringContainsString( 'qevm_done=split', $this->redirected_to );

		$landed = 0;

		if ( preg_match( '/event_id=(\d+)/', $this->redirected_to, $found ) ) {
			$landed = (int) $found[1];
		}

		$this->assertGreaterThan( 0, $landed, 'the redirect named no event' );
		$this->assertNotSame( $event_id, $landed, 'the split sent the organiser back to the half they were leaving' );
		$this->assertCount( 4, OccurrenceRepository::for_event( $landed ) );
		$this->assertSame( Series::for_event( $event_id ), Series::for_event( $landed ) );
	}

	/**
	 * A failed operation says why, on the screen it came from.
	 *
	 * @return void
	 */
	public function test_a_refused_split_reports_the_reason() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->press( 'handle_split', $dates[0]->id() );

		$this->assertStringContainsString( 'qevm_done=error', $this->redirected_to );
		$this->assertStringContainsString( 'qevm_message=', $this->redirected_to );
		$this->assertStringContainsString( 'event_id=' . $event_id, $this->redirected_to );
		$this->assertCount( 4, OccurrenceRepository::for_event( $event_id ), 'a refused split still changed something' );
	}

	/**
	 * Without the nonce nothing happens at all.
	 *
	 * @return void
	 */
	public function test_a_request_without_a_nonce_is_refused() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[1];

		$this->assertTrue(
			$this->refuses( array( 'occurrence' => (string) $target->id() ) ),
			'a request with no nonce was allowed through'
		);
		$this->assertNotSame( OccurrenceStatus::Cancelled, OccurrenceRepository::find( $target->id() )->status() );
	}

	/**
	 * A nonce for one date does not work on another.
	 *
	 * The nonce carries the occurrence id, so a form for the third of June
	 * cannot be replayed against the tenth.
	 *
	 * @return void
	 */
	public function test_a_nonce_for_one_date_does_not_act_on_another() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->assertTrue(
			$this->refuses(
				array(
					'occurrence' => (string) $dates[2]->id(),
					'_wpnonce'   => wp_create_nonce( DatesScreen::NONCE . '_' . $dates[1]->id() ),
				)
			),
			'a nonce minted for one date was accepted for another'
		);
		$this->assertNotSame( OccurrenceStatus::Cancelled, OccurrenceRepository::find( $dates[2]->id() )->status() );
	}

	/**
	 * Somebody who cannot edit the event cannot change its dates.
	 *
	 * @return void
	 */
	public function test_a_subscriber_cannot_change_a_date() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );
		$target   = $dates[1];

		wp_set_current_user( $this->a_subscriber() );

		$this->assertTrue(
			$this->refuses(
				array(
					'occurrence' => (string) $target->id(),
					'_wpnonce'   => wp_create_nonce( DatesScreen::NONCE . '_' . $target->id() ),
				)
			),
			'a subscriber called off a date'
		);
		$this->assertNotSame( OccurrenceStatus::Cancelled, OccurrenceRepository::find( $target->id() )->status() );
	}

	/**
	 * The list shows every date, its state, and the buttons that apply to it.
	 *
	 * @return void
	 */
	public function test_the_list_shows_the_dates_and_the_right_buttons() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );
		$dates    = OccurrenceRepository::for_event( $event_id );

		$this->press( 'handle_cancel', $dates[3]->id() );

		$markup = $this->render_screen( $event_id );

		$this->assertStringContainsString( 'qevm_move_occurrence', $markup );
		$this->assertStringContainsString( 'qevm_cancel_occurrence', $markup );
		$this->assertStringContainsString( 'qevm_reinstate_occurrence', $markup, 'the called-off date offers no way back' );
		$this->assertStringContainsString( 'qevm_restore_occurrence', $markup, 'the edited date cannot be reset' );

		// Four dates, four move forms.
		$this->assertSame( 4, substr_count( $markup, 'qevm_move_occurrence' ) );

		// The first date offers no split, because splitting there is refused.
		$this->assertSame( 3, substr_count( $markup, 'qevm_split_series' ) );
	}

	/**
	 * With registration off, no date claims to be booked.
	 *
	 * The flag is read through the same filter the reconciler asks, so this is
	 * the dependency direction ADR-0009 requires — the screen learns a date has
	 * bookings without knowing registrations exist.
	 *
	 * @return void
	 */
	public function test_no_booked_flag_when_registration_is_off() {
		$event_id = $this->make_series( 'FREQ=WEEKLY;COUNT=4' );

		$this->assertStringNotContainsString( 'qevm-date-flag', $this->render_screen( $event_id ) );

		add_filter( 'qevm_occurrence_is_protected', '__return_true' );

		$this->assertStringContainsString(
			'qevm-date-flag',
			$this->render_screen( $event_id ),
			'the screen does not show what the filter tells it'
		);

		remove_filter( 'qevm_occurrence_is_protected', '__return_true' );
	}

	/**
	 * Press one of the buttons.
	 *
	 * @param string               $handler Method on the screen.
	 * @param int                  $id      Occurrence id.
	 * @param array<string, mixed> $extra   Other submitted fields.
	 * @return void
	 */
	private function press( $handler, $id, array $extra = array() ) {
		$request = array_merge(
			array(
				'occurrence' => (string) $id,
				'_wpnonce'   => wp_create_nonce( DatesScreen::NONCE . '_' . $id ),
			),
			$extra
		);

		$previous_post    = $_POST;
		$previous_request = $_REQUEST;
		$_POST            = wp_slash( $request );
		$_REQUEST         = $_POST;

		try {
			( new DatesScreen() )->$handler();
		} catch ( Redirected $e ) {
			// Where it went is on $this->redirected_to.
			unset( $e );
		} catch ( Died $e ) {
			$this->fail( 'The handler refused a request it should have accepted: ' . $e->getMessage() );
		} finally {
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}
	}

	/**
	 * The screen's markup for one event.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private function render_screen( $event_id ) {
		$previous = $_GET;
		$_GET     = array( 'event_id' => (string) $event_id );

		ob_start();

		try {
			( new DatesScreen() )->render();
		} finally {
			$markup = (string) ob_get_clean();
			$_GET   = $previous;
		}

		return $markup;
	}

	/**
	 * A weekly series with dates on it.
	 *
	 * @param string $rrule The rule.
	 * @return int
	 */
	private function make_series( $rrule ) {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::TIMEZONE, 'UTC' );
		update_post_meta( $event_id, Meta::START_LOCAL, '2026-06-02 18:00:00' );
		update_post_meta( $event_id, Meta::END_LOCAL, '2026-06-02 19:00:00' );
		update_post_meta( $event_id, Meta::START_UTC, '2026-06-02 18:00:00' );
		update_post_meta( $event_id, Meta::END_UTC, '2026-06-02 19:00:00' );
		update_post_meta( $event_id, Meta::RECURRENCE_RULE, $rrule );

		OccurrenceSync::sync( $event_id );

		return $event_id;
	}

	/**
	 * Whether the screen refuses a request outright.
	 *
	 * The handler that turns `wp_die()` into an exception is installed for the
	 * whole class in setUp(); this only decides what to make of it.
	 *
	 * @param array<string, mixed> $request What was submitted.
	 * @return bool
	 */
	private function refuses( array $request ) {
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;
		$_POST            = wp_slash( $request );
		$_REQUEST         = $_POST;

		$refused = false;

		try {
			( new DatesScreen() )->handle_cancel();
		} catch ( Redirected $e ) {
			unset( $e );
		} catch ( Died $e ) {
			$refused = true;
		} finally {
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}

		return $refused;
	}

	/**
	 * Somebody with no business editing events.
	 *
	 * @return int
	 */
	private function a_subscriber() {
		$id = wp_insert_user(
			array(
				'user_login' => 'qevm-subscriber-' . wp_rand( 1000, 999999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'subscriber-' . wp_rand( 1000, 999999 ) . '@example.test',
				'role'       => 'subscriber',
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
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
}
