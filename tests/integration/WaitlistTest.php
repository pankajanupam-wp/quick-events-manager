<?php
/**
 * The waiting list, moving.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Registration\Waitlist;

/**
 * Promotion when a place is given back.
 */
final class WaitlistTest extends TestCase {

	/**
	 * Every test here books repeatedly and does not care about mail.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->quieten_registration();
	}

	/**
	 * Cancelling a confirmed booking promotes the next person waiting.
	 *
	 * @return void
	 */
	public function test_a_cancellation_promotes_the_next_in_line() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first  = $this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );

		$this->assertSame( RegistrationStatus::Confirmed, $first->status() );
		$this->assertSame( RegistrationStatus::Waitlisted, $second->status() );

		Repository::update_status( $first->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $second->id() )->status(),
			'the freed place should have gone to the person waiting for it'
		);
		$this->assertSame( 1, Repository::count_taken( $event_id ) );
	}

	/**
	 * The promoted person is told.
	 *
	 * A confirmed place nobody knows about is a seat that stays empty while
	 * the organiser counts on it being filled.
	 *
	 * @return void
	 */
	public function test_promotion_sends_an_email() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first  = $this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );

		$sent = array();

		add_filter(
			'qevm_promotion_email',
			static function ( $email ) use ( &$sent ) {
				$sent[] = $email;

				$email['to'] = '';

				return $email;
			}
		);

		Repository::update_status( $first->id(), RegistrationStatus::Cancelled );

		$this->assertCount( 1, $sent, 'exactly one promotion email' );
		$this->assertSame( 'second@example.com', $sent[0]['to'] );
		$this->assertStringContainsString( $second->code(), $sent[0]['body'] );
		$this->assertStringContainsString(
			'qevm_cancel=',
			$sent[0]['body'],
			'somebody promoted must be able to decline in one click'
		);
	}

	/**
	 * A waitlisted booking withdrawing promotes nobody.
	 *
	 * @return void
	 */
	public function test_a_waitlisted_withdrawal_frees_nothing() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );
		$third  = $this->book( $event_id, array( 'email' => 'third@example.com' ) );

		$this->assertSame( RegistrationStatus::Waitlisted, $second->status() );

		Repository::update_status( $second->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $third->id() )->status(),
			'nothing was freed, so nobody may be promoted'
		);
		$this->assertSame( 1, Repository::count_taken( $event_id ) );
	}

	/**
	 * A waitlisted withdrawal does not even look at the queue.
	 *
	 * Separate from the test above, and it has to be. That one passes whether
	 * or not the transition is checked, because the capacity re-read inside the
	 * promotion loop finds nothing free and stops — which was discovered by
	 * deleting the transition check and watching every waitlist test stay
	 * green. Correctness was never in doubt; the wasted query was invisible.
	 *
	 * `qevm_waitlist_candidates` only runs inside a promotion pass, so it is
	 * the observable difference between "decided not to promote" and "never
	 * started".
	 *
	 * @return void
	 */
	public function test_a_waitlisted_withdrawal_starts_no_promotion_pass() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );

		$passes = 0;

		add_filter(
			'qevm_waitlist_candidates',
			static function ( $candidates ) use ( &$passes ) {
				++$passes;

				return $candidates;
			}
		);

		Repository::update_status( $second->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			0,
			$passes,
			'a booking that held no place freed none, so the queue should not have been read at all'
		);
	}

	/**
	 * Cancelling a three-place booking promotes up to three places' worth.
	 *
	 * @return void
	 */
	public function test_three_freed_places_fill_three_single_bookings() {
		$event_id = $this->make_event( array( 'capacity' => 3 ) );

		$big = $this->book( $event_id, array( 'quantity' => 3 ) );

		$this->assertSame( RegistrationStatus::Confirmed, $big->status() );

		$waiting = array();

		foreach ( array( 'a', 'b', 'c', 'd' ) as $letter ) {
			$waiting[ $letter ] = $this->book( $event_id, array( 'email' => $letter . '@example.com' ) );

			$this->assertSame( RegistrationStatus::Waitlisted, $waiting[ $letter ]->status() );
		}

		Repository::update_status( $big->id(), RegistrationStatus::Cancelled );

		foreach ( array( 'a', 'b', 'c' ) as $letter ) {
			$this->assertSame(
				RegistrationStatus::Confirmed,
				Repository::find( $waiting[ $letter ]->id() )->status(),
				$letter . ' should have been promoted'
			);
		}

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $waiting['d']->id() )->status(),
			'only three places were freed'
		);
		$this->assertSame( 3, Repository::count_taken( $event_id ) );
	}

	/**
	 * The queue does not step over a booking that will not fit.
	 *
	 * Skipping the head of the queue fills more seats and is the wrong
	 * behaviour: somebody who asked for three places would watch every later
	 * single booking go in ahead of them, for ever.
	 *
	 * @return void
	 */
	public function test_the_queue_does_not_jump_over_a_larger_booking() {
		$event_id = $this->make_event( array( 'capacity' => 2 ) );

		// Both places taken, by two separate people.
		$holder = $this->book(
			$event_id,
			array(
				'quantity' => 1,
				'email'    => 'holder@example.com',
			)
		);
		$this->book(
			$event_id,
			array(
				'quantity' => 1,
				'email'    => 'stayer@example.com',
			)
		);

		// Then a two-place booking waiting, and a single place behind it.
		$big   = $this->book(
			$event_id,
			array(
				'quantity' => 2,
				'email'    => 'big@example.com',
			)
		);
		$small = $this->book(
			$event_id,
			array(
				'quantity' => 1,
				'email'    => 'small@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Waitlisted, $big->status() );
		$this->assertSame( RegistrationStatus::Waitlisted, $small->status() );

		// One place comes back. The head of the queue needs two.
		Repository::update_status( $holder->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $big->id() )->status(),
			'two places were asked for and one is free'
		);
		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $small->id() )->status(),
			'the single place behind it must not be let through first'
		);
		$this->assertSame(
			1,
			Repository::count_taken( $event_id ),
			'the freed place stays empty rather than being given out of turn'
		);
	}

	/**
	 * One free place does not promote a booking that needs two.
	 *
	 * @return void
	 */
	public function test_a_booking_too_large_for_the_gap_waits() {
		$event_id = $this->make_event( array( 'capacity' => 2 ) );

		$one = $this->book(
			$event_id,
			array(
				'quantity' => 1,
				'email'    => 'one@example.com',
			)
		);
		$two = $this->book(
			$event_id,
			array(
				'quantity' => 1,
				'email'    => 'two@example.com',
			)
		);
		$big = $this->book(
			$event_id,
			array(
				'quantity' => 2,
				'email'    => 'big@example.com',
			)
		);

		$this->assertSame( RegistrationStatus::Waitlisted, $big->status() );

		Repository::update_status( $one->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Waitlisted,
			Repository::find( $big->id() )->status(),
			'one place is not two'
		);
		$this->assertSame( 1, Repository::count_taken( $event_id ) );

		// The second place coming back is enough.
		Repository::update_status( $two->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $big->id() )->status()
		);
		$this->assertSame( 2, Repository::count_taken( $event_id ) );
	}

	/**
	 * An uncapped event promotes nobody, because nobody is ever waiting.
	 *
	 * @return void
	 */
	public function test_an_uncapped_event_promotes_nobody() {
		$event_id = $this->make_event( array( 'capacity' => 0 ) );

		$first = $this->book( $event_id, array( 'email' => 'first@example.com' ) );

		$this->assertSame( RegistrationStatus::Confirmed, $first->status() );

		Repository::update_status( $first->id(), RegistrationStatus::Cancelled );

		$this->assertSame( array(), Waitlist::promote_for_event( $event_id ) );
	}

	/**
	 * Promotion never runs inside itself.
	 *
	 * Confirming a booking fires the same action that triggers promotion. The
	 * inner pass frees no place so it would stop anyway, but the guard is what
	 * stops a future change from turning that into a double promotion.
	 *
	 * @return void
	 */
	public function test_promotion_does_not_re_enter() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first  = $this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );
		$third  = $this->book( $event_id, array( 'email' => 'third@example.com' ) );

		$promotions = 0;

		add_action(
			'qevm_registration_promoted',
			static function () use ( &$promotions ) {
				++$promotions;
			}
		);

		Repository::update_status( $first->id(), RegistrationStatus::Cancelled );

		$this->assertSame( 1, $promotions, 'one place freed is one promotion' );
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $second->id() )->status() );
		$this->assertSame( RegistrationStatus::Waitlisted, Repository::find( $third->id() )->status() );
	}

	/**
	 * The queue can be reordered without touching the promotion loop.
	 *
	 * @return void
	 */
	public function test_the_candidate_order_is_filterable() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first  = $this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );
		$third  = $this->book( $event_id, array( 'email' => 'third@example.com' ) );

		add_filter( 'qevm_waitlist_candidates', static fn( $candidates ) => array_reverse( $candidates ) );

		Repository::update_status( $first->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $third->id() )->status(),
			'the filter should have put the last booking first'
		);
		$this->assertSame( RegistrationStatus::Waitlisted, Repository::find( $second->id() )->status() );
	}

	/**
	 * The Stage 2 gate, in one test.
	 *
	 * "Cancelling a 3-place booking frees 3 places and promotes and emails the
	 * next waitlisted person." The individual parts are covered above; a gate
	 * criterion deserves a test that reads like the criterion, because the
	 * parts passing separately is not the same claim as the whole thing
	 * working in sequence.
	 *
	 * @return void
	 */
	public function test_the_stage_gate_cancelling_three_places_promotes_and_emails() {
		$event_id = $this->make_event( array( 'capacity' => 3 ) );

		$group = $this->book(
			$event_id,
			array(
				'quantity' => 3,
				'email'    => 'group@example.com',
			)
		);

		$next = $this->book( $event_id, array( 'email' => 'next@example.com' ) );
		$last = $this->book( $event_id, array( 'email' => 'last@example.com' ) );

		$this->assertSame( RegistrationStatus::Confirmed, $group->status() );
		$this->assertSame( RegistrationStatus::Waitlisted, $next->status() );
		$this->assertSame( 3, Repository::count_taken( $event_id ) );

		$emailed = array();

		add_filter(
			'qevm_promotion_email',
			static function ( $email ) use ( &$emailed ) {
				$emailed[] = $email['to'];

				$email['to'] = '';

				return $email;
			}
		);

		Repository::update_status( $group->id(), RegistrationStatus::Cancelled );

		// Three places freed.
		$this->assertSame(
			array( 'next@example.com', 'last@example.com' ),
			$emailed,
			'everybody who fitted into the freed places should have been told'
		);
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $next->id() )->status() );
		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $last->id() )->status() );

		// Two of the three went to single bookings; the third is genuinely free.
		$this->assertSame( 2, Repository::count_taken( $event_id ) );
		$this->assertSame( 1, RegistrationService::places_remaining( new Event( $event_id ) ) );
	}

	/**
	 * Promotion reaches the attendee rows, so the tickets work again.
	 *
	 * @return void
	 */
	public function test_a_promoted_booking_has_admissible_people() {
		$event_id = $this->make_event( array( 'capacity' => 1 ) );

		$first  = $this->book( $event_id, array( 'email' => 'first@example.com' ) );
		$second = $this->book( $event_id, array( 'email' => 'second@example.com' ) );

		Repository::update_status( $first->id(), RegistrationStatus::Cancelled );

		$this->assertSame(
			1,
			\QuickEventsManager\Registration\AttendeeRepository::count_for_registration( $second->id() )
		);

		foreach ( \QuickEventsManager\Registration\AttendeeRepository::for_registration( $second->id() ) as $attendee ) {
			$this->assertTrue( $attendee->is_active() );
		}
	}
}
