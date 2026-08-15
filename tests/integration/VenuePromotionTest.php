<?php
/**
 * The one-off sweep that turns existing addresses into venue records.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Venues\PostType;
use QuickEventsManager\Venues\Promoter;
use QuickEventsManager\Venues\VenuesModule;

/**
 * What promotion is for, and what it must not do.
 *
 * The feature is only worth switching on if the backlog collapses: forty
 * meetups at one address should end up sharing one record, not owning forty.
 * Most of these tests are about the ways that could go wrong.
 */
final class VenuePromotionTest extends TestCase {

	/**
	 * An address used across several tests.
	 *
	 * @var array<string, string>
	 */
	private const ADDRESS = array(
		Meta::VENUE_NAME    => 'The Old Library',
		Meta::VENUE_ADDRESS => '12 Bank Street',
		Meta::VENUE_CITY    => 'Leeds',
		Meta::VENUE_POSTAL  => 'LS1 5AA',
	);

	/**
	 * Reset the module, the post type and the sweep's own state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( Promoter::STATE_OPTION );
		unregister_post_type( QEVM_POST_TYPE_VENUE );

		parent::tearDown();
	}

	/**
	 * Enabling the module queues the sweep rather than running it.
	 *
	 * Enabling happens inside the request that pressed the button. A site with
	 * ten thousand events would spend that request timing out.
	 *
	 * @return void
	 */
	public function test_enabling_the_module_queues_the_sweep() {
		$event_id = $this->make_event();
		$this->set_address( $event_id, self::ADDRESS );

		$this->enable_venues();

		( new VenuesModule() )->activate();

		$this->assertSame( 'pending', Promoter::state()['status'] );
		$this->assertSame( 0, $this->count_venues(), 'activate() did the work instead of queueing it' );
	}

	/**
	 * Events sharing an address end up sharing one record.
	 *
	 * The whole point. Twelve events, one venue.
	 *
	 * @return void
	 */
	public function test_events_at_the_same_address_share_one_venue() {
		$this->enable_venues();

		$event_ids = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$event_id = $this->make_event( array( 'title' => 'Meetup ' . $i ) );

			$this->set_address( $event_id, self::ADDRESS );

			$event_ids[] = $event_id;
		}

		$this->sweep();

		$this->assertSame( 1, $this->count_venues() );

		$venue_ids = array();

		foreach ( $event_ids as $event_id ) {
			$venue = ( new Event( $event_id ) )->venue();

			$this->assertTrue( $venue->is_record(), 'an event was left unpromoted' );

			$venue_ids[ $venue->id() ] = true;
		}

		$this->assertCount( 1, $venue_ids, 'the events were given different records for one address' );
	}

	/**
	 * The same address typed carelessly still matches.
	 *
	 * Twelve entries of one hall over two years are twelve slightly different
	 * strings. Case and spacing are normalised for exactly this.
	 *
	 * @return void
	 */
	public function test_casing_and_spacing_do_not_split_a_venue() {
		$this->enable_venues();

		$first  = $this->make_event( array( 'title' => 'Tidy' ) );
		$second = $this->make_event( array( 'title' => 'Untidy' ) );

		$this->set_address( $first, self::ADDRESS );
		$this->set_address(
			$second,
			array(
				Meta::VENUE_NAME    => 'the old  library ',
				Meta::VENUE_ADDRESS => '12 BANK STREET',
				Meta::VENUE_CITY    => ' Leeds',
				Meta::VENUE_POSTAL  => 'LS1 5AA',
			)
		);

		$this->sweep();

		$this->assertSame( 1, $this->count_venues() );
	}

	/**
	 * Two halls with the same name in different towns stay separate.
	 *
	 * The counterweight to the test above, and the more important of the pair.
	 * Two records that should have been one is a tidying job; one record that
	 * should have been two is lost data the site owner cannot get back.
	 *
	 * @return void
	 */
	public function test_the_same_name_in_two_towns_makes_two_venues() {
		$this->enable_venues();

		$sheffield = $this->make_event( array( 'title' => 'North' ) );
		$brighton  = $this->make_event( array( 'title' => 'South' ) );

		$this->set_address(
			$sheffield,
			array(
				Meta::VENUE_NAME => 'Town Hall',
				Meta::VENUE_CITY => 'Sheffield',
			)
		);
		$this->set_address(
			$brighton,
			array(
				Meta::VENUE_NAME => 'Town Hall',
				Meta::VENUE_CITY => 'Brighton',
			)
		);

		$this->sweep();

		$this->assertSame( 2, $this->count_venues() );
		$this->assertNotSame(
			( new Event( $sheffield ) )->venue()->id(),
			( new Event( $brighton ) )->venue()->id()
		);
	}

	/**
	 * The address stays on the event afterwards.
	 *
	 * This is what lets the module be switched off again, so it is asserted
	 * rather than assumed.
	 *
	 * @return void
	 */
	public function test_promotion_leaves_the_address_on_the_event() {
		$this->enable_venues();

		$event_id = $this->make_event();
		$this->set_address( $event_id, self::ADDRESS );

		$this->sweep();

		$this->assertSame( 'The Old Library', get_post_meta( $event_id, Meta::VENUE_NAME, true ) );
		$this->assertSame( 'Leeds', get_post_meta( $event_id, Meta::VENUE_CITY, true ) );

		$this->disable_venues();

		$this->assertSame(
			'The Old Library, 12 Bank Street, Leeds, LS1 5AA',
			( new Event( $event_id ) )->venue()->summary()
		);
	}

	/**
	 * An address with no name is left alone.
	 *
	 * A venue's title is its name, and a record called "12 Bank Street" is
	 * worse to pick from a dropdown than the flat address it replaced.
	 *
	 * @return void
	 */
	public function test_an_address_with_no_name_is_not_promoted() {
		$this->enable_venues();

		$event_id = $this->make_event();
		$this->set_address(
			$event_id,
			array(
				Meta::VENUE_ADDRESS => '12 Bank Street',
				Meta::VENUE_CITY    => 'Leeds',
			)
		);

		$this->sweep();

		$this->assertSame( 0, $this->count_venues() );
		$this->assertFalse( ( new Event( $event_id ) )->venue()->is_record() );
		$this->assertSame( '12 Bank Street, Leeds', ( new Event( $event_id ) )->venue()->summary() );
	}

	/**
	 * Online events get no venue.
	 *
	 * @return void
	 */
	public function test_an_online_event_is_not_promoted() {
		$this->enable_venues();

		$event_id = $this->make_event();

		$this->set_address( $event_id, self::ADDRESS );
		update_post_meta( $event_id, Meta::IS_ONLINE, '1' );

		$this->sweep();

		$this->assertSame( 0, $this->count_venues() );
	}

	/**
	 * An event that already names a venue is not touched.
	 *
	 * @return void
	 */
	public function test_an_event_with_a_venue_already_is_left_alone() {
		$this->enable_venues();

		$event_id = $this->make_event();

		$this->set_address( $event_id, self::ADDRESS );
		update_post_meta( $event_id, Meta::VENUE_ID, 4242 );

		$this->sweep();

		$this->assertSame( 0, $this->count_venues() );
		$this->assertSame( '4242', (string) get_post_meta( $event_id, Meta::VENUE_ID, true ) );
	}

	/**
	 * Running the sweep twice changes nothing the second time.
	 *
	 * @return void
	 */
	public function test_the_sweep_is_a_no_op_on_a_second_run() {
		$this->enable_venues();

		$event_id = $this->make_event();
		$this->set_address( $event_id, self::ADDRESS );

		$this->sweep();

		$venue_id = ( new Event( $event_id ) )->venue()->id();

		$this->assertSame( 1, $this->count_venues() );

		Promoter::schedule();
		Promoter::run();

		$this->assertSame( 1, $this->count_venues(), 'the second run created a duplicate venue' );
		$this->assertSame( $venue_id, ( new Event( $event_id ) )->venue()->id() );
	}

	/**
	 * Switching the module off and on again does not sweep a second time.
	 *
	 * Promotion is for the backlog that predates the module. Afterwards the
	 * site owner is choosing per event whether to use a record, and a sweep
	 * that reached back to decide for them would be undoing their work.
	 *
	 * @return void
	 */
	public function test_re_enabling_the_module_does_not_sweep_again() {
		$this->enable_venues();

		$event_id = $this->make_event();
		$this->set_address( $event_id, self::ADDRESS );

		$this->sweep();

		$one_off = $this->make_event( array( 'title' => 'Later event' ) );
		$this->set_address(
			$one_off,
			array(
				Meta::VENUE_NAME => 'Somewhere Else',
				Meta::VENUE_CITY => 'York',
			)
		);

		( new VenuesModule() )->activate();
		Promoter::run();

		$this->assertSame( 'done', Promoter::state()['status'] );
		$this->assertSame( 1, $this->count_venues(), 'the sweep ran a second time' );
		$this->assertFalse( ( new Event( $one_off ) )->venue()->is_record() );
	}

	/**
	 * A filter can keep an event out of the sweep.
	 *
	 * @return void
	 */
	public function test_a_filter_can_exclude_an_event() {
		$this->enable_venues();

		$event_id = $this->make_event();
		$this->set_address( $event_id, self::ADDRESS );

		add_filter( 'qevm_promote_event_venue', '__return_false' );

		$this->sweep();

		$this->assertSame( 0, $this->count_venues() );
	}

	/**
	 * The sweep resumes where it stopped rather than starting over.
	 *
	 * @return void
	 */
	public function test_the_sweep_resumes_from_its_cursor() {
		$this->enable_venues();

		$first = $this->make_event( array( 'title' => 'First' ) );
		$this->set_address( $first, self::ADDRESS );

		$second = $this->make_event( array( 'title' => 'Second' ) );
		$this->set_address(
			$second,
			array(
				Meta::VENUE_NAME => 'Another Place',
				Meta::VENUE_CITY => 'Hull',
			)
		);

		$this->sweep();

		$this->assertGreaterThanOrEqual( $second, Promoter::state()['cursor'] );
		$this->assertSame( 2, $this->count_venues() );
	}

	/**
	 * Queue and run the sweep to completion.
	 *
	 * @return void
	 */
	private function sweep() {
		Promoter::schedule();
		Promoter::run();

		$this->assertSame( 'done', Promoter::state()['status'], 'the sweep did not finish' );
	}

	/**
	 * Switch the venues module on, and register its post type.
	 *
	 * @return void
	 */
	private function enable_venues() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, VenuesModule::ID ) );

		PostType::register_post_type();
	}

	/**
	 * Switch the venues module off again.
	 *
	 * @return void
	 */
	private function disable_venues() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );
	}

	/**
	 * Put an address on an event.
	 *
	 * @param int                   $event_id Event.
	 * @param array<string, string> $parts    Address parts.
	 * @return void
	 */
	private function set_address( $event_id, array $parts ) {
		foreach ( $parts as $key => $value ) {
			update_post_meta( $event_id, $key, $value );
		}
	}

	/**
	 * How many venue records exist.
	 *
	 * @return int
	 */
	private function count_venues() {
		$venues = get_posts(
			array(
				'post_type'              => QEVM_POST_TYPE_VENUE,
				'post_status'            => 'any',
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return count( $venues );
	}
}
