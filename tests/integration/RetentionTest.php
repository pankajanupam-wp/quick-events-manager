<?php
/**
 * The sweep that deletes old registrations.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Privacy\Retention;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\Repository;

/**
 * A feature whose job is destroying data, held to the ways it must not.
 *
 * The first test is the important one and everything else is detail: a site
 * that has not asked for this must lose nothing, ever. A retention sweep that
 * turned itself on would take an organisation's entire attendance history with
 * no warning and no copy.
 */
final class RetentionTest extends TestCase {

	/**
	 * Put the setting back however the test left it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Retention::unschedule();

		parent::tearDown();
	}

	/**
	 * A site that has not set a retention period loses nothing.
	 *
	 * @return void
	 */
	public function test_nothing_is_deleted_by_default() {
		$this->assertSame( 0, Retention::days(), 'retention is on by default' );

		$this->old_booking();

		$this->assertSame( 0, Retention::sweep() );
		$this->assertSame( 1, $this->count_rows( 'registrations' ) );
	}

	/**
	 * The sweep is not on cron until somebody sets a period.
	 *
	 * @return void
	 */
	public function test_the_sweep_is_not_scheduled_by_default() {
		Retention::schedule();

		$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );
	}

	/**
	 * Setting a period puts it on cron, and clearing it takes it off again.
	 *
	 * @return void
	 */
	public function test_setting_a_period_schedules_the_sweep() {
		$this->set_days( 30 );

		Retention::schedule();

		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ) );

		$this->set_days( 0 );

		Retention::schedule();

		$this->assertFalse( wp_next_scheduled( Retention::HOOK ), 'clearing the period left the sweep on cron' );
	}

	/**
	 * An event that finished longer ago than the period is cleared.
	 *
	 * @return void
	 */
	public function test_an_old_event_is_swept() {
		$event_id = $this->old_booking( 90 );

		$this->set_days( 30 );

		$this->assertSame( 1, Retention::sweep() );
		$this->assertSame( 0, $this->count_rows( 'registrations' ) );
		$this->assertSame( 0, $this->count_rows( 'attendees' ), 'the attendees were left behind' );

		unset( $event_id );
	}

	/**
	 * An event inside the period is left alone.
	 *
	 * @return void
	 */
	public function test_a_recent_event_is_left_alone() {
		$this->old_booking( 10 );

		$this->set_days( 30 );

		$this->assertSame( 0, Retention::sweep() );
		$this->assertSame( 1, $this->count_rows( 'registrations' ) );
	}

	/**
	 * A future event is never swept, however old its bookings are.
	 *
	 * The reason the cutoff is measured from the event and not from the
	 * registration: a conference booked eleven months ahead would otherwise
	 * have its earliest bookings deleted before anybody arrived.
	 *
	 * @return void
	 */
	public function test_a_future_event_is_never_swept() {
		$this->make_booking( WEEK_IN_SECONDS );

		$this->set_days( Retention::MINIMUM_DAYS );

		$this->assertSame( 0, Retention::sweep() );
		$this->assertSame( 1, $this->count_rows( 'registrations' ) );
	}

	/**
	 * A filter can keep one event's records.
	 *
	 * @return void
	 */
	public function test_a_filter_can_keep_an_events_records() {
		$this->old_booking( 90 );

		$this->set_days( 30 );

		add_filter( 'qevm_retention_delete_event', '__return_false' );

		$this->assertSame( 0, Retention::sweep() );
		$this->assertSame( 1, $this->count_rows( 'registrations' ) );
	}

	/**
	 * A period below the floor is raised to it rather than accepted.
	 *
	 * Somebody typing 1 while thinking in months should not clear every event
	 * that finished yesterday.
	 *
	 * @return void
	 */
	public function test_a_period_below_the_floor_is_raised() {
		$this->set_days( 1 );

		$this->assertSame( Retention::MINIMUM_DAYS, Retention::days() );
	}

	/**
	 * Sweeping fires an action naming what it removed.
	 *
	 * The only record that it happened, since writing a log of who was deleted
	 * would keep the personal data this exists to be rid of.
	 *
	 * @return void
	 */
	public function test_sweeping_announces_what_it_removed() {
		$event_id = $this->old_booking( 90 );

		$this->set_days( 30 );

		$seen = array();

		add_action(
			'qevm_retention_swept_event',
			static function ( $swept_event, $removed ) use ( &$seen ) {
				$seen[] = array( (int) $swept_event, (int) $removed );
			},
			10,
			2
		);

		Retention::sweep();

		$this->assertSame( array( array( $event_id, 1 ) ), $seen );
	}

	/**
	 * A booking on an event that finished a given number of days ago.
	 *
	 * @param int $days_ago Days since the event ended.
	 * @return int Event id.
	 */
	private function old_booking( $days_ago = 60 ) {
		return $this->make_booking( -( $days_ago * DAY_IN_SECONDS ) );
	}

	/**
	 * A booking on an event starting a given offset from now.
	 *
	 * @param int $starts_in Seconds from now; negative for the past.
	 * @return int Event id.
	 */
	private function make_booking( $starts_in ) {
		$event_id = $this->make_event(
			array(
				'starts_in' => $starts_in,
				'duration'  => HOUR_IN_SECONDS,
			)
		);

		/*
		 * Straight through the repository rather than through the service,
		 * because most of these events finished weeks ago and the service is
		 * quite right to refuse a booking for one.
		 */
		$registration = Repository::insert_with_capacity(
			array(
				'event_id' => $event_id,
				'name'     => 'Priya Raman',
				'email'    => 'priya@example.com',
				'quantity' => 1,
			),
			0
		);

		$this->assertNotWPError( $registration );

		AttendeeRepository::create_for_registration( $registration->id(), 1 );

		return $event_id;
	}

	/**
	 * Set the retention period.
	 *
	 * @param int $days Days, or 0 for keep forever.
	 * @return void
	 */
	private function set_days( $days ) {
		$settings = (array) get_option( QEVM_OPTION_SETTINGS, array() );

		$settings[ Retention::SETTING ] = (int) $days;

		update_option( QEVM_OPTION_SETTINGS, $settings );
	}
}
