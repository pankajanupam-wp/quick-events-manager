<?php
/**
 * Editable email wording, and what filling it in is allowed to produce.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Email\Queue;
use QuickEventsManager\Email\Template;
use QuickEventsManager\Email\Templates;
use QuickEventsManager\Email\TemplatesModule;
use QuickEventsManager\Registration\RegistrationModule;

/**
 * Most of these are about escaping.
 *
 * A template is written by somebody who may put HTML in it on purpose. The
 * values dropped into it come from a public form. Getting that distinction the
 * wrong way round produces an email that looks perfect in every test written
 * with an ordinary name in it, and a hole in every one written with a real one.
 */
final class EmailTemplateTest extends TestCase {

	/**
	 * Put the templates back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( Templates::OPTION );

		parent::tearDown();
	}

	/**
	 * A value with markup in it cannot inject into an HTML template.
	 *
	 * @return void
	 */
	public function test_a_value_cannot_inject_markup_into_an_html_template() {
		$template = new Template(
			'attendee_confirmation',
			'Hello {attendee_name}',
			'<p>Hello {attendee_name}, see you at {event_title}.</p>',
			Template::FORMAT_HTML
		);

		$rendered = $template->render(
			array(
				'attendee_name' => '<script>alert(1)</script>Priya',
				'event_title'   => 'Autumn <b>meetup</b>',
			)
		);

		$this->assertStringNotContainsString( '<script>', $rendered['body'] );
		$this->assertStringContainsString( '&lt;script&gt;', $rendered['body'] );

		// The template's own markup survives; only the values are escaped.
		$this->assertStringContainsString( '<p>Hello', $rendered['body'] );
		$this->assertStringNotContainsString( '<b>meetup</b>', $rendered['body'] );
	}

	/**
	 * A plain-text template strips tags from its values rather than escaping.
	 *
	 * "&lt;b&gt;Priya&lt;/b&gt;" in a text email is worse than "Priya", and is
	 * what escaping instead of stripping would produce.
	 *
	 * @return void
	 */
	public function test_a_text_template_strips_tags_from_values() {
		$template = new Template(
			'attendee_confirmation',
			'Hello {attendee_name}',
			"Hello {attendee_name}.\nSee you at {event_title}.",
			Template::FORMAT_TEXT
		);

		$rendered = $template->render(
			array(
				'attendee_name' => '<b>Priya</b>',
				'event_title'   => 'Autumn meetup',
			)
		);

		$this->assertStringContainsString( 'Hello Priya.', $rendered['body'] );
		$this->assertStringNotContainsString( '&lt;', $rendered['body'] );
		$this->assertStringNotContainsString( '<b>', $rendered['body'] );
	}

	/**
	 * The subject is always plain, whatever the body is.
	 *
	 * Mail clients do not render markup in a subject line, so escaping one
	 * puts the entities themselves in somebody's inbox.
	 *
	 * @return void
	 */
	public function test_the_subject_is_never_escaped_as_html() {
		$template = new Template(
			'attendee_confirmation',
			'Booking for {event_title}',
			'<p>Body</p>',
			Template::FORMAT_HTML
		);

		$rendered = $template->render( array( 'event_title' => 'Tea & cake' ) );

		$this->assertSame( 'Booking for Tea & cake', $rendered['subject'] );
	}

	/**
	 * An HTML template asks for an HTML content type; a text one does not.
	 *
	 * @return void
	 */
	public function test_an_html_template_sets_its_content_type() {
		$html = new Template( 'x', 'S', '<p>B</p>', Template::FORMAT_HTML );
		$text = new Template( 'x', 'S', 'B', Template::FORMAT_TEXT );

		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $html->render( array() )['headers'] );
		$this->assertSame( array(), $text->render( array() )['headers'] );
	}

	/**
	 * A mistyped placeholder is removed rather than shown.
	 *
	 * @return void
	 */
	public function test_an_unknown_placeholder_is_removed() {
		$template = new Template( 'x', 'S', 'Hello {atendee_name}, welcome.', Template::FORMAT_TEXT );

		$rendered = $template->render( array( 'attendee_name' => 'Priya' ) );

		$this->assertStringNotContainsString( '{', $rendered['body'] );
		$this->assertStringNotContainsString( 'atendee_name', $rendered['body'] );
	}

	/**
	 * A half-written template is treated as not written at all.
	 *
	 * @return void
	 */
	public function test_a_template_missing_a_body_is_not_used() {
		Templates::save(
			'attendee_confirmation',
			array(
				'subject' => 'Only a subject',
				'body'    => '',
				'format'  => Template::FORMAT_TEXT,
			)
		);

		$this->assertNull( Templates::get( 'attendee_confirmation' ) );
	}

	/**
	 * Clearing a template puts the built-in wording back.
	 *
	 * @return void
	 */
	public function test_clearing_a_template_restores_the_built_in_wording() {
		Templates::save(
			'attendee_confirmation',
			array(
				'subject' => 'Mine',
				'body'    => 'My wording.',
				'format'  => Template::FORMAT_TEXT,
			)
		);

		$this->assertNotNull( Templates::get( 'attendee_confirmation' ) );

		Templates::save(
			'attendee_confirmation',
			array(
				'subject' => '',
				'body'    => '',
				'format'  => Template::FORMAT_TEXT,
			)
		);

		$this->assertNull( Templates::get( 'attendee_confirmation' ) );
		$this->assertArrayNotHasKey( 'attendee_confirmation', Templates::stored() );
	}

	/**
	 * A script tag is not stored even by somebody entitled to be on the screen.
	 *
	 * @return void
	 */
	public function test_a_script_tag_is_not_stored_in_an_html_template() {
		Templates::save(
			'attendee_confirmation',
			array(
				'subject' => 'Subject',
				'body'    => '<p>Fine</p><script>alert(1)</script>',
				'format'  => Template::FORMAT_HTML,
			)
		);

		$stored = Templates::get( 'attendee_confirmation' );

		$this->assertNotNull( $stored );
		$this->assertStringContainsString( '<p>Fine</p>', $stored->raw_body() );
		$this->assertStringNotContainsString( '<script>', $stored->raw_body() );
	}

	/**
	 * A written template is what actually gets queued.
	 *
	 * The end to end check: everything above is about the renderer, and this is
	 * the one that says the renderer is reached at all.
	 *
	 * @return void
	 */
	public function test_a_written_template_is_what_reaches_the_queue() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID, TemplatesModule::ID ) );

		Templates::save(
			'attendee_confirmation',
			array(
				'subject' => 'See you at {event_title}',
				'body'    => 'Hello {attendee_name}. Your reference is {reference}.',
				'format'  => Template::FORMAT_TEXT,
			)
		);

		$event_id = $this->make_event( array( 'title' => 'Autumn meetup' ) );

		$registration = $this->book( $event_id, array( 'name' => 'Priya Raman' ) );

		$this->assertNotWPError( $registration );

		$queued = Queue::for_context( 'event', $event_id );
		$found  = null;

		foreach ( $queued as $row ) {
			if ( 'attendee_confirmation' === (string) $row['template'] ) {
				$found = $row;
			}
		}

		$this->assertNotNull( $found, 'the confirmation was never queued' );
		$this->assertSame( 'See you at Autumn meetup', (string) $found['subject'] );
		$this->assertStringContainsString( 'Hello Priya Raman.', (string) $found['body'] );
		$this->assertStringContainsString( $registration->code(), (string) $found['body'] );
	}

	/**
	 * With the module off, the built-in wording is used however much is written.
	 *
	 * @return void
	 */
	public function test_the_built_in_wording_is_used_with_the_module_off() {
		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		Templates::save(
			'attendee_confirmation',
			array(
				'subject' => 'Should not be used',
				'body'    => 'Should not be used either.',
				'format'  => Template::FORMAT_TEXT,
			)
		);

		$event_id = $this->make_event( array( 'title' => 'Autumn meetup' ) );

		$this->assertNotWPError( $this->book( $event_id ) );

		foreach ( Queue::for_context( 'event', $event_id ) as $row ) {
			$this->assertStringNotContainsString( 'Should not be used', (string) $row['subject'] );
			$this->assertStringNotContainsString( 'Should not be used', (string) $row['body'] );
		}
	}
}
