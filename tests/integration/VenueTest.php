<?php
/**
 * Venue records, and the flat address that outlives them.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Plugin;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Venues\EventVenueBox;
use QuickEventsManager\Venues\PostType;
use QuickEventsManager\Venues\Venue;
use QuickEventsManager\Venues\VenuesModule;

/**
 * Where an event is, whichever of the two places it is stored.
 *
 * The rule under test throughout: a venue record is used when the module is on
 * and everything about the reference is sound, and the event's own address meta
 * is used in every other case. There is no fifth state where an event has no
 * address to show.
 */
final class VenueTest extends TestCase {

	/**
	 * The flat address used by most of these tests.
	 *
	 * @var array<string, string>
	 */
	private const FLAT = array(
		Meta::VENUE_NAME    => 'Sheffield Town Hall',
		Meta::VENUE_ADDRESS => 'Pinstone Street',
		Meta::VENUE_CITY    => 'Sheffield',
		Meta::VENUE_POSTAL  => 'S1 2HH',
	);

	/**
	 * Put the modules option back and drop the post type registration.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unregister_post_type( QEVM_POST_TYPE_VENUE );

		parent::tearDown();
	}

	/**
	 * With the module off, the event's own address is what renders.
	 *
	 * @return void
	 */
	public function test_an_event_renders_its_own_address_with_the_module_off() {
		$event_id = $this->make_event();
		$this->set_flat_address( $event_id );

		$this->assertFalse(
			Plugin::instance()->registry()->is_enabled( VenuesModule::ID ),
			'venues should be off unless a test switches it on'
		);

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertFalse( $venue->is_record() );
		$this->assertSame( 'Sheffield Town Hall, Pinstone Street, Sheffield, S1 2HH', $venue->summary() );
	}

	/**
	 * With the module off, the post type is not registered at all.
	 *
	 * The module boundary is meant to be real rather than cosmetic, so this
	 * asserts the absence rather than trusting the option.
	 *
	 * @return void
	 */
	public function test_the_post_type_is_absent_with_the_module_off() {
		$this->assertFalse( post_type_exists( QEVM_POST_TYPE_VENUE ) );
	}

	/**
	 * With the module on but no venue chosen, the event's address still wins.
	 *
	 * Choosing a record is optional even with venues switched on, so this is a
	 * permanent state rather than a half-finished migration.
	 *
	 * @return void
	 */
	public function test_an_event_with_no_venue_chosen_uses_its_own_address() {
		$this->enable_venues();

		$event_id = $this->make_event();
		$this->set_flat_address( $event_id );

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertFalse( $venue->is_record() );
		$this->assertSame( 'Sheffield', $venue->part( Meta::VENUE_CITY ) );
	}

	/**
	 * A chosen venue record supplies the address.
	 *
	 * The record and the flat meta deliberately disagree, so the assertion
	 * says which one won rather than passing whichever way round it is read.
	 *
	 * @return void
	 */
	public function test_a_chosen_venue_supplies_the_address() {
		$this->enable_venues();

		$venue_id = $this->make_venue();
		$event_id = $this->make_event();

		$this->set_flat_address( $event_id );
		update_post_meta( $event_id, Meta::VENUE_ID, $venue_id );

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertTrue( $venue->is_record() );
		$this->assertSame( $venue_id, $venue->id() );
		$this->assertSame( 'The Old Library', $venue->name() );
		$this->assertSame( 'Leeds', $venue->part( Meta::VENUE_CITY ) );
		$this->assertStringNotContainsString( 'Sheffield', $venue->summary() );
	}

	/**
	 * A venue in the trash falls back rather than rendering nothing.
	 *
	 * @return void
	 */
	public function test_a_trashed_venue_falls_back_to_the_events_own_address() {
		$this->enable_venues();

		$venue_id = $this->make_venue();
		$event_id = $this->make_event();

		$this->set_flat_address( $event_id );
		update_post_meta( $event_id, Meta::VENUE_ID, $venue_id );

		wp_trash_post( $venue_id );

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertFalse( $venue->is_record() );
		$this->assertSame( 'Sheffield', $venue->part( Meta::VENUE_CITY ) );
	}

	/**
	 * A deleted venue falls back too.
	 *
	 * @return void
	 */
	public function test_a_deleted_venue_falls_back_to_the_events_own_address() {
		$this->enable_venues();

		$venue_id = $this->make_venue();
		$event_id = $this->make_event();

		$this->set_flat_address( $event_id );
		update_post_meta( $event_id, Meta::VENUE_ID, $venue_id );

		wp_delete_post( $venue_id, true );

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertFalse( $venue->is_record() );
		$this->assertSame( 'Sheffield Town Hall, Pinstone Street, Sheffield, S1 2HH', $venue->summary() );
	}

	/**
	 * An id pointing at something that is not a venue is ignored.
	 *
	 * @return void
	 */
	public function test_an_id_that_is_not_a_venue_is_ignored() {
		$this->enable_venues();

		$event_id = $this->make_event();
		$other_id = $this->make_event();

		$this->set_flat_address( $event_id );
		update_post_meta( $event_id, Meta::VENUE_ID, $other_id );

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertFalse( $venue->is_record() );
		$this->assertNull( Venue::from_post( $other_id ) );
	}

	/**
	 * Switching the module off leaves the address on the event.
	 *
	 * This is the stage gate, phrased the way the plan phrases it: an event
	 * that was set up with a venue record still renders its address once the
	 * module the record belonged to is gone.
	 *
	 * @return void
	 */
	public function test_switching_the_module_off_leaves_the_address_in_place() {
		$this->enable_venues();

		$venue_id = $this->make_venue();
		$event_id = $this->make_event();

		$this->assign_venue_through_the_editor( $event_id, $venue_id );

		$this->assertSame(
			'The Old Library, 12 Bank Street, Leeds, LS1 5AA',
			( new Event( $event_id ) )->venue()->summary()
		);

		$this->disable_venues();

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertFalse( $venue->is_record() );
		$this->assertSame( 'The Old Library, 12 Bank Street, Leeds, LS1 5AA', $venue->summary() );
	}

	/**
	 * Saving the event with a venue chosen copies the address onto the event.
	 *
	 * The copy is what the fallback above reads, so this is the write half of
	 * the same guarantee.
	 *
	 * @return void
	 */
	public function test_choosing_a_venue_copies_its_address_onto_the_event() {
		$this->enable_venues();

		$venue_id = $this->make_venue();
		$event_id = $this->make_event();

		$this->set_flat_address( $event_id );
		$this->assign_venue_through_the_editor( $event_id, $venue_id );

		$this->assertSame( 'The Old Library', get_post_meta( $event_id, Meta::VENUE_NAME, true ) );
		$this->assertSame( 'Leeds', get_post_meta( $event_id, Meta::VENUE_CITY, true ) );
		$this->assertSame( 'LS1 5AA', get_post_meta( $event_id, Meta::VENUE_POSTAL, true ) );
	}

	/**
	 * Clearing the venue leaves the address that was copied in.
	 *
	 * Removing the link is not the same as removing the address, and an event
	 * that loses both in one click is an event with no location on its page.
	 *
	 * @return void
	 */
	public function test_clearing_the_venue_keeps_the_address() {
		$this->enable_venues();

		$venue_id = $this->make_venue();
		$event_id = $this->make_event();

		$this->assign_venue_through_the_editor( $event_id, $venue_id );
		$this->assign_venue_through_the_editor( $event_id, 0 );

		$this->assertSame( '0', (string) get_post_meta( $event_id, Meta::VENUE_ID, true ) );
		$this->assertSame( 'The Old Library', get_post_meta( $event_id, Meta::VENUE_NAME, true ) );
	}

	/**
	 * The JSON-LD reads the record, not the stale copy underneath it.
	 *
	 * Schema is the one render path that reads the address parts one by one
	 * rather than through the summary, so it gets its own assertion.
	 *
	 * @return void
	 */
	public function test_the_structured_data_uses_the_venue_record() {
		$this->enable_venues();

		$venue_id = $this->make_venue();
		$event_id = $this->make_event();

		$this->set_flat_address( $event_id );
		update_post_meta( $event_id, Meta::VENUE_ID, $venue_id );

		$venue = ( new Event( $event_id ) )->venue();

		$this->assertSame( '12 Bank Street', $venue->part( Meta::VENUE_ADDRESS ) );
		$this->assertSame( 'LS1 5AA', $venue->part( Meta::VENUE_POSTAL ) );
	}

	/**
	 * Rendering a list of events does not cost one query per venue.
	 *
	 * Resolution reads a second post per event, which is an N+1 on the archive
	 * the previous stage spent a chunk making fast. `Venue::prime()` collapses
	 * it, and this counts the queries rather than trusting that it does.
	 *
	 * @return void
	 */
	public function test_resolving_a_list_of_events_does_not_query_per_venue() {
		$this->enable_venues();

		/*
		 * A venue each, deliberately. Eight events sharing one venue would pass
		 * this test with no priming at all — the first lookup caches it and the
		 * other seven are free — so it would prove nothing. Distinct venues are
		 * the shape that actually costs one query per event.
		 */
		$event_ids = array();

		for ( $i = 0; $i < 8; $i++ ) {
			$event_id = $this->make_event( array( 'title' => 'Listed event ' . $i ) );

			update_post_meta( $event_id, Meta::VENUE_ID, $this->make_venue( 'Venue ' . $i ) );

			$event_ids[] = $event_id;
		}

		$unprimed = $this->queries_to_resolve( $event_ids, false );
		$primed   = $this->queries_to_resolve( $event_ids, true );

		$this->assertGreaterThanOrEqual(
			count( $event_ids ),
			$unprimed,
			'the unprimed case is meant to be an N+1; if it is not, this test proves nothing'
		);

		$this->assertLessThan(
			$unprimed,
			$primed,
			'priming did not reduce the number of queries at all'
		);

		$this->assertLessThanOrEqual(
			2,
			$primed,
			'priming should collapse the venue lookups into a couple of queries'
		);
	}

	/**
	 * Count the queries resolving a set of events costs, with and without priming.
	 *
	 * The event posts and their meta are primed either way, because that is what
	 * `WP_Query` has already done by the time anything renders them — leaving
	 * them cold would measure the cost of loading the events rather than the
	 * cost of resolving their venues.
	 *
	 * @param int[] $event_ids Events to resolve.
	 * @param bool  $prime     Whether to prime the venues first.
	 * @return int
	 */
	private function queries_to_resolve( array $event_ids, $prime ) {
		wp_cache_flush();

		_prime_post_caches( $event_ids, false, true );

		if ( $prime ) {
			Venue::prime( $event_ids );
		}

		$before = get_num_queries();

		foreach ( $event_ids as $event_id ) {
			$this->assertTrue( ( new Event( $event_id ) )->venue()->is_record() );
		}

		return get_num_queries() - $before;
	}

	/**
	 * Switch the venues module on for this test.
	 *
	 * `init` fired long before any test ran, so enabling the module does not
	 * register its post type — the same reason `Plugin::activate()` registers
	 * the event post type by hand before flushing.
	 *
	 * @return void
	 */
	private function enable_venues() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, VenuesModule::ID ) );

		PostType::register_post_type();
	}

	/**
	 * Switch the venues module off again, mid-test.
	 *
	 * @return void
	 */
	private function disable_venues() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );
	}

	/**
	 * Create a venue record.
	 *
	 * @param string $title Venue name.
	 * @return int
	 */
	private function make_venue( $title = 'The Old Library' ) {
		$venue_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE_VENUE,
				'post_title'  => (string) $title,
				'post_status' => 'publish',
				'meta_input'  => array(
					Meta::VENUE_ADDRESS => '12 Bank Street',
					Meta::VENUE_CITY    => 'Leeds',
					Meta::VENUE_POSTAL  => 'LS1 5AA',
				),
			)
		);

		$this->assertGreaterThan( 0, $venue_id, 'the venue fixture could not be created' );

		return (int) $venue_id;
	}

	/**
	 * Put the shared flat address on an event.
	 *
	 * @param int $event_id Event.
	 * @return void
	 */
	private function set_flat_address( $event_id ) {
		foreach ( self::FLAT as $key => $value ) {
			update_post_meta( $event_id, $key, $value );
		}
	}

	/**
	 * Choose a venue the way the editor screen does.
	 *
	 * Through the box's own save routine rather than by writing the meta,
	 * because the copy onto the event is what that routine is for.
	 *
	 * @param int $event_id Event.
	 * @param int $venue_id Venue, or 0 to clear.
	 * @return void
	 */
	private function assign_venue_through_the_editor( $event_id, $venue_id ) {
		wp_set_current_user( $this->an_administrator() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Building the request the code under test verifies for itself.
		$previous = $_POST;

		$_POST = array(
			'qevm_event_venue_nonce' => wp_create_nonce( EventVenueBox::NONCE ),
			EventVenueBox::FIELD     => (string) $venue_id,
		);

		try {
			( new EventVenueBox() )->save( $event_id );
		} finally {
			$_POST = $previous;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * An administrator id, so the capability check in save() passes.
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

		$this->assertNotEmpty( $ids, 'the test site has no administrator to act as' );

		return (int) $ids[0];
	}
}
