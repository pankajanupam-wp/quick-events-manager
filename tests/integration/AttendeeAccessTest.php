<?php
/**
 * Whose attendees an organiser may see.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Install\Installer;
use QuickEventsManager\Registration\Access;

/**
 * Found by the stage 10 security pass, by asking the question a linter cannot.
 *
 * Every screen checked `manage_qevm_registrations`, so every screen was
 * "protected". But that capability is site-wide: it says somebody manages guest
 * lists, not whose. The Event Organizer role is documented as covering their own
 * events, and any organiser could open any other organiser's attendee list, read
 * every name and email on it, export it and change people's statuses.
 *
 * Personal data, in a plugin whose privacy section says the names and addresses
 * it stores are only visible to people who manage the event.
 */
final class AttendeeAccessTest extends TestCase {

	/**
	 * The roles have to exist before anybody can be given one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Installer::add_roles();
		Installer::add_capabilities();
	}

	/**
	 * An organiser may manage the bookings for their own event.
	 *
	 * @return void
	 */
	public function test_an_organiser_manages_their_own_event() {
		$organiser = $this->an_organiser();

		wp_set_current_user( $organiser );

		$event_id = $this->make_event( array( 'author' => $organiser ) );

		$this->assertTrue( Access::may_manage( $event_id ) );
	}

	/**
	 * And not somebody else's.
	 *
	 * @return void
	 */
	public function test_an_organiser_cannot_read_another_organisers_list() {
		$mine   = $this->an_organiser();
		$theirs = $this->an_organiser();

		$their_event = $this->make_event( array( 'author' => $theirs ) );

		wp_set_current_user( $mine );

		$this->assertFalse(
			Access::may_manage( $their_event ),
			'an organiser is not everybody\'s organiser'
		);
	}

	/**
	 * An administrator still sees everything.
	 *
	 * A fix that locks the site owner out of their own attendee list is not a
	 * fix.
	 *
	 * @return void
	 */
	public function test_an_administrator_sees_every_event() {
		$organiser = $this->an_organiser();
		$event_id  = $this->make_event( array( 'author' => $organiser ) );

		wp_set_current_user( $this->an_administrator() );

		$this->assertTrue( Access::may_manage( $event_id ) );
	}

	/**
	 * The picker offers only the events they may open.
	 *
	 * Filtered when the list is built rather than when a row is clicked: a
	 * picker listing other people's events tells an organiser what else is
	 * running even when it refuses to open them.
	 *
	 * @return void
	 */
	public function test_the_picker_lists_only_their_events() {
		$mine   = $this->an_organiser();
		$theirs = $this->an_organiser();

		$my_event    = $this->make_event( array( 'author' => $mine ) );
		$their_event = $this->make_event( array( 'author' => $theirs ) );

		wp_set_current_user( $mine );

		$offered = Access::only_theirs( array( get_post( $my_event ), get_post( $their_event ) ) );

		$this->assertCount( 1, $offered );
		$this->assertSame( $my_event, $offered[0]->ID );
	}

	/**
	 * Somebody with no capability at all gets nothing.
	 *
	 * @return void
	 */
	public function test_a_subscriber_manages_nothing() {
		$event_id = $this->make_event();

		wp_set_current_user( self::factory_user( 'subscriber' ) );

		$this->assertFalse( Access::may_manage( $event_id ) );
		$this->assertFalse( Access::may_manage( 0 ), 'not even the screen before an event is chosen' );
	}

	/**
	 * An organiser may edit their own event after publishing it.
	 *
	 * Asserted against the capability list rather than against a user, and
	 * deliberately. `add_roles()` only ever adds capabilities — it never
	 * removes them, so that a site's own customisation survives an update —
	 * which means a role already fixed in the database keeps working even if
	 * the list regresses. The behavioural test above would pass; this one is
	 * what actually fails.
	 *
	 * `edit_published_*` and `delete_published_*` used to sit in the
	 * manage-others group. They are not about other people's posts: WordPress
	 * maps `edit_post` on any published post through them, so an organiser
	 * could create an event, publish it, and never edit it again.
	 *
	 * @return void
	 */
	public function test_publishing_does_not_lock_an_organiser_out_of_their_own_event() {
		$own = Installer::post_type_capabilities( false );

		$this->assertContains( 'edit_published_qevm_events', $own );
		$this->assertContains( 'delete_published_qevm_events', $own );

		$this->assertNotContains(
			'edit_others_qevm_events',
			$own,
			'and still nothing about anybody else\'s'
		);
	}

	/**
	 * An Event Organizer.
	 *
	 * @return int
	 */
	private function an_organiser(): int {
		return self::factory_user( 'qevm_event_organizer' );
	}

	/**
	 * An administrator.
	 *
	 * @return int
	 */
	private function an_administrator(): int {
		return self::factory_user( 'administrator' );
	}

	/**
	 * Somebody with a role.
	 *
	 * @param string $role Role name.
	 * @return int
	 */
	private static function factory_user( string $role ): int {
		static $count = 0;

		++$count;

		return (int) wp_insert_user(
			array(
				'user_login' => 'qevm-access-' . $role . '-' . $count . '-' . wp_rand( 1000, 9999 ),
				'user_email' => 'qevm-access-' . $count . '-' . wp_rand( 1000, 9999 ) . '@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
	}
}
