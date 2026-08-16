<?php
/**
 * Where answers go, and where the sensitive ones do not.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CustomFields\AnswerRepository;
use QuickEventsManager\CustomFields\Answers;
use QuickEventsManager\CustomFields\CustomFieldsModule;
use QuickEventsManager\CustomFields\Definitions;
use QuickEventsManager\CustomFields\Field;
use QuickEventsManager\Domain\FieldType;
use QuickEventsManager\Privacy\Privacy;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\Exporter;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Registration\RegistrationService;

/**
 * The three destinations an answer can reach, and the rules for each.
 *
 * A CSV leaves the building — emailed to a caterer, copied to a laptop, left in
 * a downloads folder — so a question marked sensitive stays out of it unless
 * somebody asks for it by name. A subject access request is the opposite case:
 * it is the person's own data and withholding it would be the flag doing the
 * exact opposite of its job.
 */
final class SensitiveAnswersTest extends TestCase {

	/**
	 * The ordinary export leaves a sensitive answer out.
	 *
	 * AC-5.6, and the Stage 3 gate.
	 *
	 * @return void
	 */
	public function test_a_sensitive_answer_is_absent_from_the_export() {
		$booking = $this->booking_with_answers();

		$csv = $this->export( $booking['event_id'], false );

		$this->assertStringContainsString( 'Vegetarian food', $csv, 'the ordinary question is missing from the export' );
		$this->assertStringNotContainsString( 'Step-free access', $csv, 'a sensitive answer reached an ordinary export' );
		$this->assertStringNotContainsString( 'Access requirements', $csv, 'a sensitive question was named in an ordinary export' );
	}

	/**
	 * Asking for it explicitly includes it.
	 *
	 * The counterweight: without this the test above would pass just as well if
	 * answers never reached the export at all.
	 *
	 * @return void
	 */
	public function test_asking_explicitly_includes_the_sensitive_answer() {
		$booking = $this->booking_with_answers();

		$csv = $this->export( $booking['event_id'], true );

		$this->assertStringContainsString( 'Vegetarian food', $csv );
		$this->assertStringContainsString( 'Step-free access', $csv );
		$this->assertStringContainsString( 'Access requirements', $csv );
	}

	/**
	 * The subject access request carries every answer, sensitive included.
	 *
	 * @return void
	 */
	public function test_a_privacy_export_carries_the_sensitive_answer() {
		$booking = $this->booking_with_answers();

		$export = ( new Privacy() )->export( 'booker@example.com' );

		$found = array();

		foreach ( $export['data'] as $group ) {
			foreach ( $group['data'] as $item ) {
				$found[ $item['name'] ] = $item['value'];
			}
		}

		$this->assertArrayHasKey( 'Dietary requirements', $found );
		$this->assertSame( 'Vegetarian food', $found['Dietary requirements'] );

		$this->assertArrayHasKey( 'Access requirements', $found );
		$this->assertSame( 'Step-free access', $found['Access requirements'] );
	}

	/**
	 * Erasing a registration takes the answers with it.
	 *
	 * Attendee rows were deleted without touching what hung off them, so an
	 * erasure left somebody's access requirements in a table with nothing
	 * pointing at them — unreachable, unexportable, and undeletable by any
	 * later request. "We removed everything" has to be true.
	 *
	 * @return void
	 */
	public function test_erasing_a_registration_removes_the_answers() {
		$this->booking_with_answers();

		$this->assertSame( 2, $this->count_rows( 'attendee_meta' ) );

		$erased = ( new Privacy() )->erase( 'booker@example.com' );

		$this->assertSame( 1, $erased['items_removed'] );
		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ), 'an erasure left the answers behind' );
	}

	/**
	 * Deleting every registration for an event takes the answers too.
	 *
	 * @return void
	 */
	public function test_deleting_an_events_registrations_removes_the_answers() {
		$booking = $this->booking_with_answers();

		$this->assertSame( 2, $this->count_rows( 'attendee_meta' ) );

		Repository::delete_for_event( $booking['event_id'] );

		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ) );
	}

	/**
	 * Answers are removed even with the module switched off.
	 *
	 * The rows exist whatever the Features screen says, so deletion cannot be
	 * conditional on it.
	 *
	 * @return void
	 */
	public function test_answers_are_removed_with_the_module_off() {
		$this->booking_with_answers();

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		$this->assertSame( 2, $this->count_rows( 'attendee_meta' ) );

		( new Privacy() )->erase( 'booker@example.com' );

		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ), 'answers survived an erasure because the module was off' );
	}

	/**
	 * Reading a page of bookings' answers costs one query.
	 *
	 * @return void
	 */
	public function test_reading_a_page_of_answers_is_one_query() {
		$booking = $this->booking_with_answers();

		$before = get_num_queries();

		AnswerRepository::for_registrations( array( $booking['registration_id'] ) );

		$this->assertLessThanOrEqual( 2, get_num_queries() - $before );
	}

	/**
	 * An event asking one ordinary question and one sensitive one, with a booking.
	 *
	 * @return array{event_id: int, registration_id: int}
	 */
	private function booking_with_answers() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CustomFieldsModule::ID ) );

		$event_id = $this->make_event();

		Definitions::save(
			$event_id,
			array(
				new Field( Field::mint_key(), 'Dietary requirements', FieldType::Text ),
				new Field( Field::mint_key(), 'Access requirements', FieldType::Text, false, array(), '', true ),
			)
		);

		$fields = Definitions::for_event( $event_id );

		$this->assertCount( 2, $fields );
		$this->assertFalse( $fields[0]->is_sensitive() );
		$this->assertTrue( $fields[1]->is_sensitive() );

		$registration = $this->book(
			$event_id,
			array(
				'email'               => 'booker@example.com',
				Answers::FIELD_PREFIX => array(
					RegistrationService::ANSWER_POSITION => array(
						$fields[0]->key() => 'Vegetarian food',
						$fields[1]->key() => 'Step-free access',
					),
				),
			)
		);

		$this->assertNotWPError( $registration );

		$attendees = AttendeeRepository::for_registration( $registration->id() );

		$this->assertNotEmpty( $attendees );
		$this->assertCount( 2, AnswerRepository::for_attendee( $attendees[0]->id() ) );

		return array(
			'event_id'        => $event_id,
			'registration_id' => $registration->id(),
		);
	}

	/**
	 * Run the export and return the CSV it wrote.
	 *
	 * Through write() rather than handle(), because handle() ends in exit() and
	 * exit() cannot be caught — which is exactly why the file-building half was
	 * split out of it.
	 *
	 * @param int  $event_id          Event.
	 * @param bool $include_sensitive Whether to ask for sensitive answers.
	 * @return string
	 */
	private function export( $event_id, $include_sensitive ) {
		$stream = fopen( 'php://memory', 'w+' );

		$this->assertNotFalse( $stream, 'could not open a stream to export into' );

		Exporter::write( $stream, $event_id, $include_sensitive );

		rewind( $stream );

		$csv = (string) stream_get_contents( $stream );

		fclose( $stream );

		return $csv;
	}
}
