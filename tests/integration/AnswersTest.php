<?php
/**
 * Answers to custom questions, from the form to the table.
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
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\FormHandler;
use QuickEventsManager\Registration\RegistrationService;
use QuickEventsManager\Registration\RegistrationModule;

/**
 * What is stored, and what must never be.
 *
 * The rule running through all of it is that a form stores what it asked for
 * and nothing else. A choice question that accepts a value nobody could have
 * clicked produces an export full of answers that were never on the screen, and
 * the organiser has no way to tell which are real.
 */
final class AnswersTest extends TestCase {

	/**
	 * An answer given on the form reaches the attendee's row.
	 *
	 * @return void
	 */
	public function test_an_answer_is_stored_against_the_attendee() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Dietary requirements', FieldType::Text ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];

		$registration = $this->book(
			$event_id,
			$this->with_answers( array( $field->key() => 'Vegetarian' ) )
		);

		$this->assertNotWPError( $registration );

		$attendees = AttendeeRepository::for_registration( $registration->id() );

		$this->assertNotEmpty( $attendees );

		$answers = AnswerRepository::for_attendee( $attendees[0]->id() );

		$this->assertSame( 'Vegetarian', $answers[ $field->key() ] );
	}

	/**
	 * A required question with no answer refuses the booking.
	 *
	 * @return void
	 */
	public function test_a_missing_required_answer_refuses_the_booking() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Dietary requirements', FieldType::Text, true ),
			)
		);

		$result = $this->book( $event_id, $this->with_answers( array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Dietary requirements', $result->get_error_message() );
		$this->assertSame( 0, $this->count_rows( 'registrations' ), 'a refused booking left a row behind' );
	}

	/**
	 * An optional question left blank stores nothing at all.
	 *
	 * An empty row is not the same as no row: it would show up in an export as
	 * an answer that was given and happened to be empty.
	 *
	 * @return void
	 */
	public function test_an_unanswered_optional_question_stores_no_row() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Dietary requirements', FieldType::Text ),
			)
		);

		$registration = $this->book( $event_id, $this->with_answers( array() ) );

		$this->assertNotWPError( $registration );
		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ) );
	}

	/**
	 * A choice question refuses a value that was never offered.
	 *
	 * @return void
	 */
	public function test_a_choice_that_was_not_offered_is_refused() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Which session?', FieldType::Select, false, array( 'Morning', 'Afternoon' ) ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];

		$registration = $this->book(
			$event_id,
			$this->with_answers( array( $field->key() => 'Midnight' ) )
		);

		$this->assertNotWPError( $registration );
		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ), 'a value nobody could have chosen was stored' );
	}

	/**
	 * A choose-any question stores one row per choice.
	 *
	 * One row per choice is what makes "how many people need step-free access"
	 * a COUNT rather than a search through serialised text.
	 *
	 * @return void
	 */
	public function test_choosing_several_options_stores_a_row_each() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Access needs', FieldType::Checkboxes, false, array( 'Step-free', 'Hearing loop', 'Large print' ) ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];

		$registration = $this->book(
			$event_id,
			$this->with_answers( array( $field->key() => array( 'Step-free', 'Large print', 'Invented' ) ) )
		);

		$this->assertNotWPError( $registration );
		$this->assertSame( 2, $this->count_rows( 'attendee_meta' ) );

		$attendees = AttendeeRepository::for_registration( $registration->id() );
		$answers   = AnswerRepository::for_attendee( $attendees[0]->id() );

		$this->assertSame( array( 'Step-free', 'Large print' ), $answers[ $field->key() ] );
	}

	/**
	 * A date that is not a real date is refused.
	 *
	 * @return void
	 */
	public function test_an_impossible_date_is_refused() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Arrival date', FieldType::Date ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];

		$registration = $this->book(
			$event_id,
			$this->with_answers( array( $field->key() => '2026-02-30' ) )
		);

		$this->assertNotWPError( $registration );
		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ), '30 February was accepted' );
	}

	/**
	 * A real date is kept.
	 *
	 * The counterweight, so the test above is not passing because dates never
	 * store at all.
	 *
	 * @return void
	 */
	public function test_a_real_date_is_kept() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Arrival date', FieldType::Date ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];

		$registration = $this->book(
			$event_id,
			$this->with_answers( array( $field->key() => '2026-02-28' ) )
		);

		$this->assertNotWPError( $registration );

		$attendees = AttendeeRepository::for_registration( $registration->id() );

		$this->assertSame( '2026-02-28', AnswerRepository::for_attendee( $attendees[0]->id() )[ $field->key() ] );
	}

	/**
	 * Nothing is asked or stored while the module is off.
	 *
	 * @return void
	 */
	public function test_no_answers_are_stored_with_the_module_off() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Dietary requirements', FieldType::Text, true ),
			)
		);

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		$registration = $this->book( $event_id, $this->with_answers( array() ) );

		$this->assertNotWPError( $registration, 'a required question blocked a booking with its module switched off' );
		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ) );
	}

	/**
	 * Answers are removed with the attendees they belong to.
	 *
	 * @return void
	 */
	public function test_answers_can_be_removed_for_a_set_of_attendees() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Dietary requirements', FieldType::Text ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];

		$registration = $this->book(
			$event_id,
			$this->with_answers( array( $field->key() => 'Vegan' ) )
		);

		$this->assertNotWPError( $registration );

		$attendees = AttendeeRepository::for_registration( $registration->id() );
		$ids       = array_map( static fn ( $attendee ) => $attendee->id(), $attendees );

		$this->assertSame( 1, $this->count_rows( 'attendee_meta' ) );

		AnswerRepository::delete_for_attendees( $ids );

		$this->assertSame( 0, $this->count_rows( 'attendee_meta' ) );
	}

	/**
	 * Reading answers for many attendees costs one query, not one each.
	 *
	 * @return void
	 */
	public function test_reading_many_attendees_answers_is_one_query() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Dietary requirements', FieldType::Text ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];
		$ids   = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$registration = $this->book(
				$event_id,
				$this->with_answers(
					array( $field->key() => 'Answer ' . $i ),
					array( 'email' => 'guest' . $i . '@example.com' )
				)
			);

			$this->assertNotWPError( $registration );

			foreach ( AttendeeRepository::for_registration( $registration->id() ) as $attendee ) {
				$ids[] = $attendee->id();
			}
		}

		$before = get_num_queries();

		$answers = AnswerRepository::for_attendees( $ids );

		$this->assertSame( 1, get_num_queries() - $before );
		$this->assertCount( 5, $answers );
	}

	/**
	 * The form handler carries the answers to the service.
	 *
	 * The gate found that it did not. The form rendered the questions and the
	 * service checked them, and the transport between the two built its input
	 * array by hand and never read them — so every answer typed into the public
	 * form was dropped, silently, while every test here passed because they all
	 * call the service directly.
	 *
	 * This asserts the boundary rather than the endpoints: whatever keys the
	 * handler collects, the ones the form posts have to be among them.
	 *
	 * @return void
	 */
	public function test_the_form_handler_passes_answers_through() {
		$event_id = $this->event_asking(
			array(
				$this->field( 'Dietary requirements', FieldType::Text ),
			)
		);

		$field = Definitions::for_event( $event_id )[0];

		wp_set_current_user( 0 );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Building the request the handler verifies for itself.
		$previous = $_POST;

		$_POST = wp_slash(
			array(
				'qevm_event_id'       => (string) $event_id,
				'qevm_name'           => 'Priya Raman',
				'qevm_email'          => 'form-path@example.com',
				'qevm_quantity'       => '1',
				'qevm_consent'        => '1',
				Answers::FIELD_PREFIX => array(
					RegistrationService::ANSWER_POSITION => array( $field->key() => 'Vegetarian' ),
				),
			)
		);

		$collected = FormHandler::collect_input();

		$_POST = $previous;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->assertArrayHasKey(
			Answers::FIELD_PREFIX,
			$collected,
			'the form handler drops the answers before the service ever sees them'
		);

		$this->assertSame(
			'Vegetarian',
			$collected[ Answers::FIELD_PREFIX ][ RegistrationService::ANSWER_POSITION ][ $field->key() ]
		);
	}

	/**
	 * Build a field definition.
	 *
	 * @param string    $label    Question.
	 * @param FieldType $type     Answer type.
	 * @param bool      $required Whether an answer is required.
	 * @param string[]  $options  Choices.
	 * @return Field
	 */
	private function field( $label, FieldType $type, $required = false, array $options = array() ) {
		return new Field( Field::mint_key(), $label, $type, $required, $options );
	}

	/**
	 * An event asking a set of questions, with both modules on.
	 *
	 * @param Field[] $fields Questions.
	 * @return int
	 */
	private function event_asking( array $fields ) {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CustomFieldsModule::ID ) );

		$event_id = $this->make_event();

		Definitions::save( $event_id, $fields );

		return $event_id;
	}

	/**
	 * Submitted input carrying a set of answers for the person booking.
	 *
	 * @param array<string, mixed> $answers Answers, keyed by field key.
	 * @param array<string, mixed> $extra   Other input to merge in.
	 * @return array<string, mixed>
	 */
	private function with_answers( array $answers, array $extra = array() ) {
		return array_merge(
			array(
				Answers::FIELD_PREFIX => array(
					\QuickEventsManager\Registration\RegistrationService::ANSWER_POSITION => $answers,
				),
			),
			$extra
		);
	}
}
