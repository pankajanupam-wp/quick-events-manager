<?php
/**
 * What actually reaches the page.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\CustomFields\CustomFieldsModule;
use QuickEventsManager\CustomFields\Definitions;
use QuickEventsManager\CustomFields\Field;
use QuickEventsManager\Domain\FieldType;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Frontend\Renderer;
use QuickEventsManager\Organizers\OrganizersModule;
use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Venues\PostType as VenuePostType;
use QuickEventsManager\Venues\VenuesModule;

/**
 * The Stage 3 gate criteria that say "renders" and "appears".
 *
 * Every other test in this stage stops one layer short: it checks that
 * resolution returns the right address, or that the service stores the right
 * answer. Both were true while the templates were never asked to prove they
 * print any of it. The two gate criteria are worded about the page, so these
 * assert on the markup.
 */
final class RenderedFieldsTest extends TestCase {

	/**
	 * Reset the post types this switches on.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unregister_post_type( QEVM_POST_TYPE_VENUE );
		unregister_post_type( QEVM_POST_TYPE_ORGANIZER );

		parent::tearDown();
	}

	/**
	 * An event from before the venues module still prints its address.
	 *
	 * The Stage 3 gate, word for word. The event carries flat address meta and
	 * names no venue, exactly as one created before the module existed does,
	 * and the module is then switched on around it.
	 *
	 * @return void
	 */
	public function test_an_older_event_still_renders_its_address() {
		$event_id = $this->event_with_address();

		$before = $this->render_details( $event_id );

		$this->assertStringContainsString( 'Sheffield Town Hall', $before );
		$this->assertStringContainsString( 'Pinstone Street', $before );

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, VenuesModule::ID ) );
		VenuePostType::register_post_type();

		$after = $this->render_details( $event_id );

		$this->assertStringContainsString( 'Sheffield Town Hall', $after, 'switching venues on hid an existing address' );
		$this->assertStringContainsString( 'Pinstone Street', $after );
	}

	/**
	 * A venue record's address is what prints once one is chosen.
	 *
	 * @return void
	 */
	public function test_a_chosen_venue_is_what_renders() {
		$event_id = $this->event_with_address();

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, VenuesModule::ID ) );
		VenuePostType::register_post_type();

		$venue_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE_VENUE,
				'post_title'  => 'The Old Library',
				'post_status' => 'publish',
				'meta_input'  => array( Meta::VENUE_CITY => 'Leeds' ),
			)
		);

		update_post_meta( $event_id, Meta::VENUE_ID, $venue_id );

		$markup = $this->render_details( $event_id );

		$this->assertStringContainsString( 'The Old Library', $markup );
		$this->assertStringContainsString( 'Leeds', $markup );
		$this->assertStringNotContainsString( 'Sheffield Town Hall', $markup );
	}

	/**
	 * An organiser record's name is what prints once one is chosen.
	 *
	 * @return void
	 */
	public function test_a_chosen_organizer_is_what_renders() {
		$event_id = $this->event_with_address();

		update_post_meta( $event_id, Meta::ORGANIZER_NAME, 'Priya Raman' );

		$this->assertStringContainsString( 'Priya Raman', $this->render_details( $event_id ) );

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, OrganizersModule::ID ) );
		\QuickEventsManager\Organizers\PostType::register_post_type();

		$organizer_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE_ORGANIZER,
				'post_title'  => 'Sheffield Makers',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $event_id, Meta::ORGANIZER_ID, $organizer_id );

		$markup = $this->render_details( $event_id );

		$this->assertStringContainsString( 'Sheffield Makers', $markup );
		$this->assertStringNotContainsString( 'Priya Raman', $markup );
	}

	/**
	 * A custom question appears on the registration form.
	 *
	 * The other half of the gate. Everything else about custom fields was
	 * tested through the service, which would go on passing with the template
	 * printing nothing at all.
	 *
	 * @return void
	 */
	public function test_a_custom_question_appears_on_the_form() {
		$event_id = $this->event_asking(
			array(
				new Field( Field::mint_key(), 'Dietary requirements', FieldType::Text, false, array(), 'Tell us about allergies too.' ),
			)
		);

		$field  = Definitions::for_event( $event_id )[0];
		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'Dietary requirements', $markup, 'the question is not on the form' );
		$this->assertStringContainsString( $field->key(), $markup, 'the input is not named after the question' );
		$this->assertStringContainsString( 'Tell us about allergies too.', $markup, 'the help text is not shown' );
	}

	/**
	 * A required question renders as required.
	 *
	 * @return void
	 */
	public function test_a_required_question_renders_as_required() {
		$event_id = $this->event_asking(
			array(
				new Field( Field::mint_key(), 'Access requirements', FieldType::Text, true ),
			)
		);

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'Access requirements', $markup );
		$this->assertMatchesRegularExpression( '/<input[^>]*required/', $markup, 'a required question rendered without the attribute' );
	}

	/**
	 * A choice question renders every choice it offers.
	 *
	 * @return void
	 */
	public function test_a_choice_question_renders_its_options() {
		$event_id = $this->event_asking(
			array(
				new Field( Field::mint_key(), 'Which session?', FieldType::Select, false, array( 'Morning', 'Afternoon' ) ),
			)
		);

		$markup = $this->render_form( $event_id );

		$this->assertStringContainsString( 'Morning', $markup );
		$this->assertStringContainsString( 'Afternoon', $markup );
	}

	/**
	 * A sensitive question is still asked on the form.
	 *
	 * The flag governs what leaves in a CSV. A question nobody is asked collects
	 * nothing, which would make the whole feature pointless — so this is the
	 * counterweight to the export test.
	 *
	 * @return void
	 */
	public function test_a_sensitive_question_is_still_asked() {
		$event_id = $this->event_asking(
			array(
				new Field( Field::mint_key(), 'Access requirements', FieldType::Text, false, array(), '', true ),
			)
		);

		$this->assertStringContainsString( 'Access requirements', $this->render_form( $event_id ) );
	}

	/**
	 * No questions are asked while the module is off.
	 *
	 * @return void
	 */
	public function test_no_questions_are_asked_with_the_module_off() {
		$event_id = $this->event_asking(
			array(
				new Field( Field::mint_key(), 'Dietary requirements', FieldType::Text ),
			)
		);

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		$this->assertStringNotContainsString( 'Dietary requirements', $this->render_form( $event_id ) );
	}

	/**
	 * An event carrying a flat address, with only registration enabled.
	 *
	 * @return int
	 */
	private function event_with_address() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::VENUE_NAME, 'Sheffield Town Hall' );
		update_post_meta( $event_id, Meta::VENUE_ADDRESS, 'Pinstone Street' );

		return $event_id;
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
	 * The rendered event details block.
	 *
	 * @param int $event_id Event.
	 * @return string
	 */
	private function render_details( $event_id ) {
		return (string) Renderer::event_details( array( 'id' => $event_id ) );
	}

	/**
	 * The rendered registration form.
	 *
	 * @param int $event_id Event.
	 * @return string
	 */
	private function render_form( $event_id ) {
		return (string) Renderer::registration_form( array( 'id' => $event_id ) );
	}
}
