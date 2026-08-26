<?php
/**
 * Copying an event.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Duplicator;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Registration\Repository;

/**
 * What a duplicate inherits, and what it must not.
 */
final class DuplicatorTest extends TestCase {

	/**
	 * The copy carries everything that describes the event.
	 *
	 * @return void
	 */
	public function test_a_copy_inherits_the_event_details() {
		$event_id = $this->make_event(
			array(
				'title'    => 'Monthly meetup',
				'capacity' => 25,
			)
		);

		update_post_meta( $event_id, Meta::VENUE_NAME, 'The Old Library' );
		update_post_meta( $event_id, Meta::ORGANIZER_EMAIL, 'organiser@example.com' );

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );

		$this->assertSame( 'The Old Library', get_post_meta( $copy_id, Meta::VENUE_NAME, true ) );
		$this->assertSame( 'organiser@example.com', get_post_meta( $copy_id, Meta::ORGANIZER_EMAIL, true ) );
		$this->assertSame( '25', get_post_meta( $copy_id, Meta::CAPACITY, true ) );
		$this->assertSame(
			get_post_meta( $event_id, Meta::START_UTC, true ),
			get_post_meta( $copy_id, Meta::START_UTC, true )
		);
	}

	/**
	 * The copy is a draft even when the original is published.
	 *
	 * A duplicate arrives with the original's date, so publishing it at once
	 * puts a second identical event on the archive before anybody notices.
	 *
	 * @return void
	 */
	public function test_a_copy_is_always_a_draft() {
		$event_id = $this->make_event( array( 'status' => 'publish' ) );

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( 'publish', get_post_status( $event_id ) );
		$this->assertSame( 'draft', get_post_status( $copy_id ) );
	}

	/**
	 * The copy is marked as one, so the two are told apart in a list.
	 *
	 * @return void
	 */
	public function test_the_copy_is_named_as_a_copy() {
		$event_id = $this->make_event( array( 'title' => 'Monthly meetup' ) );

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( 'Monthly meetup (copy)', get_the_title( $copy_id ) );
	}

	/**
	 * Bookings belong to the original and are not copied.
	 *
	 * Copying them would put one reference code on two events and hand the
	 * organiser a brand new event that already claims to be full.
	 *
	 * @return void
	 */
	public function test_a_copy_inherits_no_bookings() {
		$this->quieten_registration();

		$event_id     = $this->make_event( array( 'capacity' => 5 ) );
		$registration = $this->book( $event_id, array( 'quantity' => 2 ) );

		$this->assertNotWPError( $registration );
		$this->assertSame( 2, Repository::count_taken( $event_id ) );

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( 0, Repository::count_taken( $copy_id ), 'the copy starts empty' );
		$this->assertSame( array(), Repository::for_event( $copy_id ) );

		// And the original still has its booking.
		$this->assertSame( 2, Repository::count_taken( $event_id ) );
		$this->assertSame(
			RegistrationStatus::Confirmed,
			Repository::find( $registration->id() )->status()
		);
	}

	/**
	 * The copy has its own occurrence row, so date queries can see it.
	 *
	 * The meta is written after wp_insert_post() has already fired `save_post`,
	 * so the sync that normally runs on save saw an event with no dates. Left
	 * alone, the copy would be invisible to every date query until somebody
	 * opened and re-saved it.
	 *
	 * @return void
	 */
	public function test_a_copy_gets_its_own_occurrence_row() {
		$event_id = $this->make_event();

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );

		$occurrences = OccurrenceRepository::for_event( $copy_id );

		$this->assertCount( 1, $occurrences, 'the copy should be findable by date' );
		$this->assertSame(
			get_post_meta( $copy_id, Meta::START_UTC, true ),
			$occurrences[0]->get( 'start_utc' )
		);
	}

	/**
	 * Categories and tags come across.
	 *
	 * @return void
	 */
	public function test_a_copy_inherits_its_terms() {
		$event_id = $this->make_event();

		wp_set_object_terms( $event_id, 'Workshops', QEVM_TAX_CATEGORY );
		wp_set_object_terms( $event_id, array( 'free', 'evening' ), QEVM_TAX_TAG );

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );

		$this->assertSame(
			array( 'Workshops' ),
			wp_get_object_terms( $copy_id, QEVM_TAX_CATEGORY, array( 'fields' => 'names' ) )
		);

		$tags = wp_get_object_terms( $copy_id, QEVM_TAX_TAG, array( 'fields' => 'names' ) );

		sort( $tags );

		$this->assertSame( array( 'evening', 'free' ), $tags );
	}

	/**
	 * Text survives being copied, and copied again.
	 *
	 * Both wp_insert_post() and add_post_meta() expect slashed data and unslash
	 * it themselves. Handing back what get_post() returned strips a level every
	 * time, so an apostrophe survives the first duplicate and vanishes from the
	 * duplicate of the duplicate — which is exactly the kind of loss nobody
	 * notices until the original is gone.
	 *
	 * @return void
	 */
	public function test_quotes_and_backslashes_survive_repeated_copying() {
		$event_id = $this->make_event( array( 'title' => 'Session' ) );

		/*
		 * wp_slash() in the fixture as well, for the same reason the copier
		 * needs it: update_post_meta() unslashes what it is given. Writing the
		 * raw string here would store one without the backslash, and the test
		 * would then be comparing the copy against a value the original never
		 * held — passing whether or not the copier was correct.
		 */
		$awkward = "O'Brien's \\ Hall";

		update_post_meta( $event_id, Meta::VENUE_NAME, wp_slash( $awkward ) );

		$this->assertSame(
			$awkward,
			get_post_meta( $event_id, Meta::VENUE_NAME, true ),
			'the fixture did not store what it meant to'
		);

		$first = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $first );

		$second = Duplicator::duplicate( $first );

		$this->assertNotWPError( $second );

		$this->assertSame(
			$awkward,
			get_post_meta( $first, Meta::VENUE_NAME, true ),
			'a slash level was lost on the first copy'
		);
		$this->assertSame(
			$awkward,
			get_post_meta( $second, Meta::VENUE_NAME, true ),
			'a slash level was lost copying the copy'
		);
	}

	/**
	 * An apostrophe in a title survives being copied twice.
	 *
	 * @return void
	 */
	public function test_a_title_survives_repeated_copying() {
		$event_id = $this->make_event();

		wp_update_post(
			array(
				'ID'         => $event_id,
				'post_title' => wp_slash( "O'Brien's Session" ),
			)
		);

		/*
		 * The raw column, not get_the_title(): that runs the `the_title`
		 * filters, and wptexturize() turns the apostrophe into a curly quote
		 * entity. Asserting on it would be testing WordPress's typography
		 * rather than whether the copier preserved the stored value.
		 */
		$this->assertSame( "O'Brien's Session", get_post_field( 'post_title', $event_id, 'raw' ) );

		$second = Duplicator::duplicate( Duplicator::duplicate( $event_id ) );

		$this->assertNotWPError( $second );
		$this->assertStringContainsString(
			"O'Brien's Session",
			get_post_field( 'post_title', $second, 'raw' )
		);
	}

	/**
	 * The editing lock is not inherited.
	 *
	 * @return void
	 */
	public function test_a_copy_does_not_inherit_the_edit_lock() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, '_edit_lock', '1699999999:1' );

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( '', get_post_meta( $copy_id, '_edit_lock', true ) );
	}

	/**
	 * Meta from other plugins comes across too.
	 *
	 * A Duplicate that silently drops half an event's fields is worse than no
	 * Duplicate, because the loss is only noticed later.
	 *
	 * @return void
	 */
	public function test_a_copy_inherits_third_party_meta() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, 'some_other_plugin_field', 'kept' );
		update_post_meta( $event_id, 'structured', array( 'a' => 1 ) );

		$copy_id = Duplicator::duplicate( $event_id );

		$this->assertNotWPError( $copy_id );
		$this->assertSame( 'kept', get_post_meta( $copy_id, 'some_other_plugin_field', true ) );
		$this->assertSame( array( 'a' => 1 ), get_post_meta( $copy_id, 'structured', true ) );
	}

	/**
	 * Duplicating something that is not an event is refused.
	 *
	 * @return void
	 */
	public function test_only_events_can_be_duplicated() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'An ordinary post',
				'post_status' => 'publish',
			)
		);

		$result = Duplicator::duplicate( $post_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'qevm_duplicate_missing', $result->get_error_code() );

		wp_delete_post( $post_id, true );
	}
}
