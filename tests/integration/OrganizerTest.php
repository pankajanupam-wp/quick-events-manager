<?php
/**
 * Organiser records, and the contact details that outlive them.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Organizers\EventOrganizerBox;
use QuickEventsManager\Organizers\Organizer;
use QuickEventsManager\Organizers\OrganizersModule;
use QuickEventsManager\Organizers\PostType;
use QuickEventsManager\Organizers\Promoter;
use QuickEventsManager\Registration\RegistrationModule;

/**
 * Who to contact, whichever of the two places it is stored.
 *
 * The venue tests cover the shared resolution rules in detail. What is worth
 * testing again here is that organisers really do inherit them rather than
 * looking as though they do — and the one merge case that is specific to
 * people rather than places.
 */
final class OrganizerTest extends TestCase {

	/**
	 * Contact details used across several tests.
	 *
	 * @var array<string, string>
	 */
	private const CONTACT = array(
		Meta::ORGANIZER_NAME  => 'Priya Raman',
		Meta::ORGANIZER_EMAIL => 'priya@example.com',
		Meta::ORGANIZER_PHONE => '+44 114 496 0000',
	);

	/**
	 * Reset the post type and the sweep's state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( Promoter::STATE_OPTION );
		unregister_post_type( QEVM_POST_TYPE_ORGANIZER );

		parent::tearDown();
	}

	/**
	 * With the module off, the event's own details are what render.
	 *
	 * @return void
	 */
	public function test_an_event_uses_its_own_details_with_the_module_off() {
		$event_id = $this->make_event();
		$this->set_contact( $event_id, self::CONTACT );

		$organizer = ( new Event( $event_id ) )->organizer();

		$this->assertFalse( $organizer->is_record() );
		$this->assertSame( 'Priya Raman', $organizer->name() );
		$this->assertSame( 'priya@example.com', $organizer->email() );
	}

	/**
	 * With the module off, the post type is not registered at all.
	 *
	 * @return void
	 */
	public function test_the_post_type_is_absent_with_the_module_off() {
		$this->assertFalse( post_type_exists( QEVM_POST_TYPE_ORGANIZER ) );
	}

	/**
	 * A chosen record supplies the details.
	 *
	 * The record and the flat meta disagree on purpose, so the assertion says
	 * which won rather than passing whichever way round it is read.
	 *
	 * @return void
	 */
	public function test_a_chosen_organizer_supplies_the_details() {
		$this->enable_organizers();

		$organizer_id = $this->make_organizer();
		$event_id     = $this->make_event();

		$this->set_contact( $event_id, self::CONTACT );
		update_post_meta( $event_id, Meta::ORGANIZER_ID, $organizer_id );

		$organizer = ( new Event( $event_id ) )->organizer();

		$this->assertTrue( $organizer->is_record() );
		$this->assertSame( 'Sheffield Makers', $organizer->name() );
		$this->assertSame( 'hello@makers.example', $organizer->email() );
		$this->assertNotSame( 'Priya Raman', $organizer->name() );
	}

	/**
	 * A trashed record falls back rather than rendering nothing.
	 *
	 * @return void
	 */
	public function test_a_trashed_organizer_falls_back() {
		$this->enable_organizers();

		$organizer_id = $this->make_organizer();
		$event_id     = $this->make_event();

		$this->set_contact( $event_id, self::CONTACT );
		update_post_meta( $event_id, Meta::ORGANIZER_ID, $organizer_id );

		wp_trash_post( $organizer_id );

		$organizer = ( new Event( $event_id ) )->organizer();

		$this->assertFalse( $organizer->is_record() );
		$this->assertSame( 'Priya Raman', $organizer->name() );
	}

	/**
	 * Switching the module off leaves the contact on the event.
	 *
	 * The stage gate for organisers, phrased as the plan phrases it for venues.
	 *
	 * @return void
	 */
	public function test_switching_the_module_off_leaves_the_contact_in_place() {
		$this->enable_organizers();

		$organizer_id = $this->make_organizer();
		$event_id     = $this->make_event();

		$this->assign_through_the_editor( $event_id, $organizer_id );

		$this->assertSame( 'Sheffield Makers', ( new Event( $event_id ) )->organizer()->name() );

		$this->disable_organizers();

		$organizer = ( new Event( $event_id ) )->organizer();

		$this->assertFalse( $organizer->is_record() );
		$this->assertSame( 'Sheffield Makers', $organizer->name() );
		$this->assertSame( 'hello@makers.example', $organizer->email() );
	}

	/**
	 * Two people behind one shared mailbox stay two records.
	 *
	 * The mistake this guards against is specific to organisers and looks
	 * reasonable right up until it loses data: matching on the email address
	 * alone, because an address identifies a person. Shared mailboxes are
	 * ordinary — a committee address, info@, events@ — and merging on one would
	 * fold several people into a single record, so that editing one changes the
	 * contact details on the others' events with nothing to say it happened.
	 *
	 * @return void
	 */
	public function test_two_people_sharing_a_mailbox_stay_two_records() {
		$this->enable_organizers();

		$first  = $this->make_event( array( 'title' => 'Spring' ) );
		$second = $this->make_event( array( 'title' => 'Autumn' ) );

		$this->set_contact(
			$first,
			array(
				Meta::ORGANIZER_NAME  => 'Priya Raman',
				Meta::ORGANIZER_EMAIL => 'info@makers.example',
			)
		);
		$this->set_contact(
			$second,
			array(
				Meta::ORGANIZER_NAME  => 'Tom Wills',
				Meta::ORGANIZER_EMAIL => 'info@makers.example',
			)
		);

		$this->sweep();

		$this->assertSame( 2, $this->count_organizers(), 'a shared mailbox merged two people into one record' );
		$this->assertNotSame(
			( new Event( $first ) )->organizer()->id(),
			( new Event( $second ) )->organizer()->id()
		);
	}

	/**
	 * The same organiser typed carelessly still matches.
	 *
	 * The counterweight to the test above.
	 *
	 * @return void
	 */
	public function test_casing_and_spacing_do_not_split_an_organizer() {
		$this->enable_organizers();

		$first  = $this->make_event( array( 'title' => 'Tidy' ) );
		$second = $this->make_event( array( 'title' => 'Untidy' ) );

		$this->set_contact( $first, self::CONTACT );
		$this->set_contact(
			$second,
			array(
				Meta::ORGANIZER_NAME  => 'priya  raman ',
				Meta::ORGANIZER_EMAIL => 'PRIYA@example.com',
				Meta::ORGANIZER_PHONE => '+44 114 496 0000',
			)
		);

		$this->sweep();

		$this->assertSame( 1, $this->count_organizers() );
	}

	/**
	 * An event with contact details but no name is left alone.
	 *
	 * More likely for organisers than for venues — an email address with
	 * nobody's name against it is a normal thing to have typed — and there is
	 * still nothing to call the record.
	 *
	 * @return void
	 */
	public function test_an_email_with_no_name_is_not_promoted() {
		$this->enable_organizers();

		$event_id = $this->make_event();
		$this->set_contact( $event_id, array( Meta::ORGANIZER_EMAIL => 'info@makers.example' ) );

		$this->sweep();

		$this->assertSame( 0, $this->count_organizers() );
		$this->assertSame( 'info@makers.example', ( new Event( $event_id ) )->organizer()->email() );
	}

	/**
	 * Promotion leaves the details on the event.
	 *
	 * @return void
	 */
	public function test_promotion_leaves_the_details_on_the_event() {
		$this->enable_organizers();

		$event_id = $this->make_event();
		$this->set_contact( $event_id, self::CONTACT );

		$this->sweep();

		$this->assertSame( 'Priya Raman', get_post_meta( $event_id, Meta::ORGANIZER_NAME, true ) );
		$this->assertSame( 'priya@example.com', get_post_meta( $event_id, Meta::ORGANIZER_EMAIL, true ) );
	}

	/**
	 * The two sweeps keep their own progress.
	 *
	 * Venues and organisers are independent modules, so one having finished
	 * must not tell the other it has.
	 *
	 * @return void
	 */
	public function test_the_organizer_sweep_is_independent_of_the_venue_sweep() {
		$this->enable_organizers();

		$this->assertNotSame(
			Promoter::STATE_OPTION,
			\QuickEventsManager\Venues\Promoter::STATE_OPTION
		);

		$event_id = $this->make_event();
		$this->set_contact( $event_id, self::CONTACT );

		$this->sweep();

		$this->assertSame( 'done', Promoter::state()['status'] );
		$this->assertSame( 'none', \QuickEventsManager\Venues\Promoter::state()['status'] );
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
	 * Switch the organisers module on, and register its post type.
	 *
	 * @return void
	 */
	private function enable_organizers() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, OrganizersModule::ID ) );

		PostType::register_post_type();
	}

	/**
	 * Switch the organisers module off again.
	 *
	 * @return void
	 */
	private function disable_organizers() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );
	}

	/**
	 * Create an organiser record.
	 *
	 * @param string $title Organiser name.
	 * @return int
	 */
	private function make_organizer( $title = 'Sheffield Makers' ) {
		$organizer_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE_ORGANIZER,
				'post_title'  => (string) $title,
				'post_status' => 'publish',
				'meta_input'  => array(
					Meta::ORGANIZER_EMAIL => 'hello@makers.example',
					Meta::ORGANIZER_URL   => 'https://makers.example',
				),
			)
		);

		$this->assertGreaterThan( 0, $organizer_id, 'the organiser fixture could not be created' );

		return (int) $organizer_id;
	}

	/**
	 * Put contact details on an event.
	 *
	 * @param int                   $event_id Event.
	 * @param array<string, string> $parts    Values.
	 * @return void
	 */
	private function set_contact( $event_id, array $parts ) {
		foreach ( $parts as $key => $value ) {
			update_post_meta( $event_id, $key, $value );
		}
	}

	/**
	 * How many organiser records exist.
	 *
	 * @return int
	 */
	private function count_organizers() {
		return count(
			get_posts(
				array(
					'post_type'              => QEVM_POST_TYPE_ORGANIZER,
					'post_status'            => 'any',
					'posts_per_page'         => 100,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);
	}

	/**
	 * Choose an organiser the way the editor screen does.
	 *
	 * @param int $event_id     Event.
	 * @param int $organizer_id Organiser, or 0 to clear.
	 * @return void
	 */
	private function assign_through_the_editor( $event_id, $organizer_id ) {
		wp_set_current_user( $this->an_administrator() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Building the request the code under test verifies for itself.
		$previous = $_POST;

		$_POST = array(
			'qevm_event_organizer_nonce' => wp_create_nonce( EventOrganizerBox::NONCE ),
			EventOrganizerBox::FIELD     => (string) $organizer_id,
		);

		try {
			( new EventOrganizerBox() )->save( $event_id );
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
