<?php
/**
 * Defining the questions a registration form asks.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CustomFields\CustomFieldsModule;
use QuickEventsManager\CustomFields\Definitions;
use QuickEventsManager\CustomFields\Field;
use QuickEventsManager\CustomFields\FieldsMetaBox;
use QuickEventsManager\Domain\FieldType;
use QuickEventsManager\Registration\RegistrationModule;

/**
 * What a question is, and what must survive editing one.
 *
 * The property most worth protecting is that a field's key never changes.
 * Answers are stored against it, so a key that follows the label would orphan
 * every answer already collected the first time somebody tidied their wording.
 */
final class CustomFieldsTest extends TestCase {

	/**
	 * Editing a question's wording does not change its key.
	 *
	 * The whole reason keys are minted rather than derived. This is written as
	 * a round trip through the editor's own save routine, because deriving the
	 * key is a mistake that would be made there.
	 *
	 * @return void
	 */
	public function test_renaming_a_question_keeps_its_key() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'key'   => '',
					'label' => 'Dietary requirements',
					'type'  => 'text',
					'order' => 1,
				),
			)
		);

		$fields = Definitions::for_event( $event_id );

		$this->assertCount( 1, $fields );

		$key = $fields[0]->key();

		$this->assertNotSame( '', $key );

		$this->submit(
			$event_id,
			array(
				array(
					'key'   => $key,
					'label' => 'Dietary requirements or allergies',
					'type'  => 'text',
					'order' => 1,
				),
			)
		);

		$renamed = Definitions::for_event( $event_id );

		$this->assertCount( 1, $renamed );
		$this->assertSame( 'Dietary requirements or allergies', $renamed[0]->label() );
		$this->assertSame( $key, $renamed[0]->key(), 'renaming a question changed its key, orphaning every answer' );
	}

	/**
	 * A key is not derived from the label.
	 *
	 * The test above would still pass if the key were derived and the label had
	 * not changed enough to matter. This one says the derivation never happens.
	 *
	 * @return void
	 */
	public function test_a_key_is_not_derived_from_the_label() {
		$key = Field::mint_key();

		$this->assertStringNotContainsString( 'dietary', strtolower( $key ) );
		$this->assertNotSame( $key, Field::mint_key(), 'keys are not unique' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $key );
	}

	/**
	 * Questions come back in the order their positions ask for.
	 *
	 * @return void
	 */
	public function test_questions_are_ordered_by_position() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'label' => 'Third',
					'type'  => 'text',
					'order' => 3,
				),
				array(
					'label' => 'First',
					'type'  => 'text',
					'order' => 1,
				),
				array(
					'label' => 'Second',
					'type'  => 'text',
					'order' => 2,
				),
			)
		);

		$labels = array_map(
			static fn ( $field ) => $field->label(),
			Definitions::for_event( $event_id )
		);

		$this->assertSame( array( 'First', 'Second', 'Third' ), $labels );
	}

	/**
	 * Two questions given the same position keep their screen order.
	 *
	 * @return void
	 */
	public function test_a_tie_keeps_the_order_on_screen() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'label' => 'Alpha',
					'type'  => 'text',
					'order' => 1,
				),
				array(
					'label' => 'Beta',
					'type'  => 'text',
					'order' => 1,
				),
			)
		);

		$labels = array_map(
			static fn ( $field ) => $field->label(),
			Definitions::for_event( $event_id )
		);

		$this->assertSame( array( 'Alpha', 'Beta' ), $labels );
	}

	/**
	 * A blank question is how a row is removed.
	 *
	 * @return void
	 */
	public function test_a_blank_question_is_dropped() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'label' => 'Kept',
					'type'  => 'text',
					'order' => 1,
				),
				array(
					'label' => '   ',
					'type'  => 'text',
					'order' => 2,
				),
			)
		);

		$fields = Definitions::for_event( $event_id );

		$this->assertCount( 1, $fields );
		$this->assertSame( 'Kept', $fields[0]->label() );
	}

	/**
	 * A choice question with nothing to choose from is not saved.
	 *
	 * @return void
	 */
	public function test_a_choice_question_without_options_is_rejected() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'label'   => 'Which session?',
					'type'    => 'select',
					'options' => '',
					'order'   => 1,
				),
			)
		);

		$this->assertSame( array(), Definitions::for_event( $event_id ) );
	}

	/**
	 * Choices are read one per line.
	 *
	 * @return void
	 */
	public function test_choices_are_read_one_per_line() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'label'   => 'Which session?',
					'type'    => 'select',
					'options' => "Morning\r\nAfternoon\n\n  Evening  ",
					'order'   => 1,
				),
			)
		);

		$fields = Definitions::for_event( $event_id );

		$this->assertCount( 1, $fields );
		$this->assertSame( array( 'Morning', 'Afternoon', 'Evening' ), $fields[0]->options() );
	}

	/**
	 * The required and sensitive flags round-trip.
	 *
	 * @return void
	 */
	public function test_the_flags_survive_a_save() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'label'     => 'Access requirements',
					'type'      => 'textarea',
					'required'  => '1',
					'sensitive' => '1',
					'order'     => 1,
				),
			)
		);

		$fields = Definitions::for_event( $event_id );

		$this->assertCount( 1, $fields );
		$this->assertTrue( $fields[0]->is_required() );
		$this->assertTrue( $fields[0]->is_sensitive() );
		$this->assertSame( FieldType::Textarea, $fields[0]->type() );
	}

	/**
	 * A question whose text needs escaping survives the JSON round trip.
	 *
	 * Definitions are JSON in post meta, and update_post_meta() unslashes what
	 * it is given. The event duplicator lost data to exactly this.
	 *
	 * @return void
	 */
	public function test_awkward_text_survives_the_round_trip() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();
		$label    = 'O\'Brien\'s "special" \\ requirement';

		$this->submit(
			$event_id,
			array(
				array(
					'label' => $label,
					'type'  => 'text',
					'order' => 1,
				),
			)
		);

		$fields = Definitions::for_event( $event_id );

		$this->assertCount( 1, $fields );
		$this->assertSame( $label, $fields[0]->label() );
	}

	/**
	 * An unknown type degrades to a short answer rather than fatalling.
	 *
	 * @return void
	 */
	public function test_an_unknown_type_falls_back() {
		$this->assertSame( FieldType::Text, FieldType::from_stored( 'colour_picker' ) );
	}

	/**
	 * More questions than the limit are not stored.
	 *
	 * @return void
	 */
	public function test_the_number_of_questions_is_capped() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();
		$rows     = array();

		$over_the_limit = Definitions::limit() + 5;

		for ( $i = 0; $i < $over_the_limit; $i++ ) {
			$rows[] = array(
				'label' => 'Question ' . $i,
				'type'  => 'text',
				'order' => $i + 1,
			);
		}

		$this->submit( $event_id, $rows );

		$this->assertCount( Definitions::limit(), Definitions::for_event( $event_id ) );
	}

	/**
	 * A filter returning nonsense cannot break an event page.
	 *
	 * @return void
	 */
	public function test_a_misbehaving_filter_is_ignored() {
		$this->enable_custom_fields();

		$event_id = $this->make_event();

		$this->submit(
			$event_id,
			array(
				array(
					'label' => 'Kept',
					'type'  => 'text',
					'order' => 1,
				),
			)
		);

		add_filter( 'qevm_registration_fields', static fn () => 'not an array' );

		$this->assertCount( 1, Definitions::for_event( $event_id ), 'a string from the filter should be ignored' );

		remove_all_filters( 'qevm_registration_fields' );

		add_filter( 'qevm_registration_fields', static fn ( $fields ) => array_merge( (array) $fields, array( 'rubbish' ) ) );

		$this->assertCount( 1, Definitions::for_event( $event_id ), 'a stray value from the filter should be dropped' );
	}

	/**
	 * Switch the custom fields module on.
	 *
	 * @return void
	 */
	private function enable_custom_fields() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, CustomFieldsModule::ID ) );
	}

	/**
	 * Save a set of rows the way the editor screen does.
	 *
	 * @param int                      $event_id Event.
	 * @param array<int, array<mixed>> $rows   Submitted rows.
	 * @return void
	 */
	private function submit( $event_id, array $rows ) {
		wp_set_current_user( $this->an_administrator() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Building the request the code under test verifies for itself.
		$previous = $_POST;

		$_POST = wp_slash(
			array(
				'qevm_fields_nonce'  => wp_create_nonce( FieldsMetaBox::NONCE ),
				FieldsMetaBox::FIELD => $rows,
			)
		);

		try {
			( new FieldsMetaBox() )->save( $event_id );
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
