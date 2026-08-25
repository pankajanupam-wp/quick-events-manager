<?php
/**
 * What a deleted event takes with it.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\Repository;

/**
 * Deleting an event used to leave every attendee behind.
 *
 * Found by the stage 6 gate: `deleted_post` removed the occurrence rows and
 * nothing else, so the names, email addresses and phone numbers on that event's
 * bookings stayed in the database with nothing pointing at them — unreachable
 * from every screen, unreachable by the privacy exporter, and still personal
 * data somebody is responsible for.
 *
 * The distinction that matters here is delete against trash. A trashed event can
 * be restored, and restoring one whose attendee list was thrown away is worse
 * than not restoring it.
 */
final class EventDeletionTest extends TestCase {

	/**
	 * The module's own hooks, as a real request would have them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->quieten_registration();

		( new RegistrationModule() )->register();
	}

	/**
	 * Deleting an event removes its bookings and its attendees.
	 *
	 * @return void
	 */
	public function test_deleting_an_event_removes_its_bookings() {
		$event_id = $this->make_event();

		$booking = $this->book( $event_id, array( 'quantity' => 2 ) );

		$this->assertSame( 1, $this->count_rows( 'registrations', 'event_id = ' . (int) $event_id ) );
		$this->assertCount( 2, AttendeeRepository::for_registration( $booking->id() ) );

		wp_delete_post( $event_id, true );

		$this->assertSame( 0, $this->count_rows( 'registrations', 'event_id = ' . (int) $event_id ), 'the bookings went' );
		$this->assertSame( array(), AttendeeRepository::for_registration( $booking->id() ), 'and so did the people on them' );
	}

	/**
	 * Trashing an event keeps them.
	 *
	 * @return void
	 */
	public function test_trashing_an_event_keeps_its_bookings() {
		$event_id = $this->make_event();

		$booking = $this->book( $event_id );

		wp_trash_post( $event_id );

		$this->assertSame(
			1,
			$this->count_rows( 'registrations', 'event_id = ' . (int) $event_id ),
			'a trashed event can be restored, and it should come back with its attendees'
		);

		$this->assertSame( RegistrationStatus::Confirmed, Repository::find( $booking->id() )->status() );
	}

	/**
	 * Deleting somebody else's post costs nothing.
	 *
	 * `deleted_post` fires for every post type on the site, and the post-type
	 * guard is about not doing pointless work on all of them: two DELETE
	 * statements on every post, page, attachment and revision a site ever
	 * removes.
	 *
	 * Asserted by counting queries rather than rows, deliberately. Removing the
	 * guard cannot delete the wrong bookings — a post's id is never an event's
	 * id — so a row count cannot see it, and a sabotage of the guard passed a
	 * row-count version of this test.
	 *
	 * @return void
	 */
	public function test_deleting_an_ordinary_post_costs_nothing() {
		$event_id = $this->make_event();

		$this->book( $event_id );

		$ours = 0;

		/*
		 * Only the queries that touch this plugin's own tables are counted.
		 * Comparing two whole deletions was the first version and it is not
		 * stable: another active plugin — WooCommerce, in the run that caught
		 * this — does its own work on `deleted_post` and warms its own caches,
		 * so the second deletion costs less than the first for reasons that
		 * have nothing to do with us.
		 */
		add_filter(
			'query',
			static function ( $sql ) use ( &$ours ) {
				if ( false !== strpos( (string) $sql, 'qevm_registrations' ) || false !== strpos( (string) $sql, 'qevm_attendees' ) ) {
					++$ours;
				}

				return $sql;
			}
		);

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'An ordinary post',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$ours = 0;

		wp_delete_post( (int) $post_id, true );

		$this->assertSame( 0, $ours, 'deleting an ordinary post should not go near the bookings tables' );
		$this->assertSame( 1, $this->count_rows( 'registrations', 'event_id = ' . (int) $event_id ) );
	}

	/**
	 * The bookings for one event are not the bookings for another.
	 *
	 * @return void
	 */
	public function test_only_that_events_bookings_go() {
		$doomed   = $this->make_event( array( 'title' => 'Doomed' ) );
		$survivor = $this->make_event( array( 'title' => 'Survivor' ) );

		$this->book( $doomed, array( 'email' => 'first@example.com' ) );
		$this->book( $survivor, array( 'email' => 'second@example.com' ) );

		wp_delete_post( $doomed, true );

		$this->assertSame( 0, $this->count_rows( 'registrations', 'event_id = ' . (int) $doomed ) );
		$this->assertSame( 1, $this->count_rows( 'registrations', 'event_id = ' . (int) $survivor ) );
	}
}
