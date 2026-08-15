<?php
/**
 * The editor box and its save routine, held to each other.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\MetaBox;

/**
 * A field the form saves but never renders quietly destroys data.
 *
 * `MetaBox::save()` writes every key in `TEXT_FIELDS`, using `''` when the
 * request does not carry it. That is correct for a text input, which arrives
 * empty when the user clears it and never arrives at all when it is not on the
 * page — the two cases are indistinguishable in `$_POST`, so the save routine
 * has to assume the form rendered everything it saves.
 *
 * It did not. `qevm_organizer_phone` sat in the save map with no input on the
 * screen, so every save of every event wrote an empty string over any phone
 * number set through the REST API or by code. No error, no notice, and no test
 * — the value was simply gone the next time somebody pressed Update.
 *
 * These tests are written against the *rendered form*, not against a list of
 * field names kept in step by hand. A second field added to the save map and
 * forgotten on the screen fails here without anybody remembering to add it.
 */
final class MetaBoxFieldsTest extends TestCase {

	/**
	 * Every field the save routine writes is on the screen.
	 *
	 * @return void
	 */
	public function test_every_saved_field_has_an_input_on_the_form() {
		$markup = $this->render_box( $this->make_event() );

		foreach ( MetaBox::TEXT_FIELDS as $field => $meta_key ) {
			$this->assertStringContainsString(
				'name="' . $field . '"',
				$markup,
				sprintf(
					'%s is saved into %s but has no input, so every save clears it.',
					$field,
					$meta_key
				)
			);
		}
	}

	/**
	 * Submitting the form exactly as a browser would preserves every value.
	 *
	 * The point of building the request from the rendered markup rather than
	 * from a literal array is that a field missing from the form is missing
	 * from the request too — which is precisely the shape of the bug.
	 *
	 * @return void
	 */
	public function test_submitting_the_form_unchanged_preserves_every_value() {
		$event_id = $this->make_event();

		$stored = array(
			Meta::VENUE_NAME      => 'The Old Library',
			Meta::VENUE_ADDRESS   => '12 Bank Street',
			Meta::VENUE_CITY      => 'Sheffield',
			Meta::VENUE_REGION    => 'South Yorkshire',
			Meta::VENUE_POSTAL    => 'S1 2GU',
			Meta::VENUE_COUNTRY   => 'United Kingdom',
			Meta::ORGANIZER_NAME  => 'Priya Raman',
			Meta::ORGANIZER_PHONE => '+44 114 496 0000',
			Meta::ORGANIZER_EMAIL => 'organiser@example.com',
		);

		foreach ( $stored as $key => $value ) {
			update_post_meta( $event_id, $key, $value );
		}

		$this->submit_rendered_form( $event_id );

		foreach ( $stored as $key => $value ) {
			$this->assertSame(
				$value,
				get_post_meta( $event_id, $key, true ),
				$key . ' did not survive a save that changed nothing.'
			);
		}
	}

	/**
	 * Clearing a field on the form still clears the stored value.
	 *
	 * The counterpart to the test above, and the reason the fix was to render
	 * the missing input rather than to stop writing absent keys. Emptying a
	 * field has to mean emptying it.
	 *
	 * @return void
	 */
	public function test_emptying_a_field_on_the_form_clears_it() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, Meta::ORGANIZER_PHONE, '+44 114 496 0000' );
		update_post_meta( $event_id, Meta::VENUE_NAME, 'The Old Library' );

		$this->submit_rendered_form(
			$event_id,
			array(
				'qevm_organizer_phone' => '',
				'qevm_venue_name'      => '',
			)
		);

		$this->assertSame( '', get_post_meta( $event_id, Meta::ORGANIZER_PHONE, true ) );
		$this->assertSame( '', get_post_meta( $event_id, Meta::VENUE_NAME, true ) );
	}

	/**
	 * Render the meta box for an event and return its markup.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private function render_box( $event_id ) {
		wp_set_current_user( $this->an_administrator() );

		ob_start();
		( new MetaBox() )->render( get_post( $event_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Post the rendered form back, as a browser would, and save it.
	 *
	 * Every `qevm_`-prefixed input in the rendered markup is submitted with the
	 * value it was rendered with, so the request carries exactly what the
	 * screen offered and nothing else.
	 *
	 * @param int                   $event_id Event id.
	 * @param array<string, string> $changes  Values to override before posting.
	 * @return void
	 */
	private function submit_rendered_form( $event_id, array $changes = array() ) {
		$markup = $this->render_box( $event_id );

		$matched = preg_match_all(
			'/<(?:input|select|textarea)\b[^>]*\bname="(qevm_[a-z0-9_]+)"[^>]*/i',
			$markup,
			$fields,
			PREG_SET_ORDER
		);

		$this->assertNotFalse( $matched, 'The meta box markup could not be parsed.' );
		$this->assertGreaterThan( 0, $matched, 'The meta box rendered no fields at all.' );

		$request = array();

		foreach ( $fields as $field ) {
			$name = $field[1];

			/*
			 * An unchecked checkbox is not submitted, which is why the online
			 * flag is read from presence rather than value in save().
			 */
			if ( preg_match( '/type="checkbox"/i', $field[0] ) && ! preg_match( '/\bchecked\b/i', $field[0] ) ) {
				continue;
			}

			$request[ $name ] = preg_match( '/\bvalue="([^"]*)"/i', $field[0], $value ) ? $value[1] : '';
		}

		$request = array_merge( $request, $changes );

		$request['qevm_event_details_nonce'] = wp_create_nonce( MetaBox::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Building and restoring the request the code under test verifies for itself.
		$previous = $_POST;
		$_POST    = wp_slash( $request );

		try {
			( new MetaBox() )->save( $event_id, get_post( $event_id ) );
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

		$this->assertNotEmpty( $ids, 'The test site has no administrator to act as.' );

		return (int) $ids[0];
	}
}
