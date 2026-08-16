<?php
/**
 * Which emails can be edited, and what they say by default.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Storage and defaults for the editable emails.
 *
 * One option holding every template, because they are read together on the
 * screen that edits them and one at a time when a message is queued — both of
 * which an autoloaded option serves better than a row each.
 *
 * A template that has never been edited is not stored at all. That is what lets
 * the wording improve in a later release for everybody who never touched it,
 * while leaving alone every site that did.
 *
 * @since 26.0
 */
final class Templates {

	/**
	 * Option holding the edited templates.
	 */
	const OPTION = 'qevm_email_templates';

	/**
	 * Every template that can be edited, in the order they are shown.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string> Id => human name.
	 */
	public static function names() {
		return array(
			'attendee_confirmation'  => __( 'Registration confirmation', 'quick-events-manager' ),
			'attendee_waitlisted'    => __( 'Waiting list confirmation', 'quick-events-manager' ),
			'waitlist_promotion'     => __( 'Off the waiting list', 'quick-events-manager' ),
			'organiser_notification' => __( 'New registration, to you', 'quick-events-manager' ),
		);
	}

	/**
	 * The placeholders a template may use, with what each one means.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string>
	 */
	public static function placeholders() {
		return array(
			'attendee_name'  => __( 'The name on the booking', 'quick-events-manager' ),
			'attendee_email' => __( 'The email address on the booking', 'quick-events-manager' ),
			'event_title'    => __( 'The event', 'quick-events-manager' ),
			'event_url'      => __( 'A link to the event', 'quick-events-manager' ),
			'event_when'     => __( 'When it starts, in the event timezone', 'quick-events-manager' ),
			'event_where'    => __( 'The venue, or the joining link for an online event', 'quick-events-manager' ),
			'places'         => __( 'How many places were booked', 'quick-events-manager' ),
			'reference'      => __( 'The booking reference', 'quick-events-manager' ),
			'cancel_url'     => __( 'A link the attendee can cancel with', 'quick-events-manager' ),
			'site_name'      => __( 'The name of this site', 'quick-events-manager' ),
		);
	}

	/**
	 * One template, edited if it has been and built in if not.
	 *
	 * @since 26.0
	 *
	 * @param string $id Template id.
	 * @return Template|null Null when there is no such template.
	 */
	public static function get( $id ) {
		$id = (string) $id;

		if ( ! isset( self::names()[ $id ] ) ) {
			return null;
		}

		$stored = self::stored();

		if ( ! isset( $stored[ $id ] ) || ! is_array( $stored[ $id ] ) ) {
			return null;
		}

		$template = new Template(
			$id,
			isset( $stored[ $id ]['subject'] ) ? (string) $stored[ $id ]['subject'] : '',
			isset( $stored[ $id ]['body'] ) ? (string) $stored[ $id ]['body'] : '',
			isset( $stored[ $id ]['format'] ) ? (string) $stored[ $id ]['format'] : Template::FORMAT_TEXT
		);

		return $template->is_usable() ? $template : null;
	}

	/**
	 * Every stored template, raw.
	 *
	 * Typed as loosely as an option deserves. This reads whatever is in the
	 * database, which is whatever the last thing to write it put there — a
	 * hand-edited option, a half-finished import, a value from a version of this
	 * plugin that stored a different shape. Promising the shape here would make
	 * the checks in get() look redundant and invite somebody to delete them.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Replace one template, or remove it to fall back to the built-in wording.
	 *
	 * @since 26.0
	 *
	 * @param string                $id     Template id.
	 * @param array<string, string> $values Keys: subject, body, format.
	 * @return void
	 */
	public static function save( $id, array $values ) {
		$id = (string) $id;

		if ( ! isset( self::names()[ $id ] ) ) {
			return;
		}

		$stored  = self::stored();
		$subject = isset( $values['subject'] ) ? sanitize_text_field( $values['subject'] ) : '';
		$body    = isset( $values['body'] ) ? (string) $values['body'] : '';
		$format  = isset( $values['format'] ) && Template::FORMAT_HTML === $values['format']
			? Template::FORMAT_HTML
			: Template::FORMAT_TEXT;

		/*
		 * An HTML body goes through wp_kses_post() rather than being trusted
		 * whole. The person editing it has the capability to be here, but a
		 * capability is not a reason to store a <script> tag and mail it to
		 * every attendee — and an editor role having this screen is exactly the
		 * situation where "they could already do worse" stops being true.
		 */
		$body = Template::FORMAT_HTML === $format
			? wp_kses_post( $body )
			: wp_strip_all_tags( $body );

		if ( '' === trim( $subject ) && '' === trim( $body ) ) {
			unset( $stored[ $id ] );
		} else {
			$stored[ $id ] = array(
				'subject' => $subject,
				'body'    => $body,
				'format'  => $format,
			);
		}

		update_option( self::OPTION, $stored );
	}

	/**
	 * Turn a built message into the values a template can use.
	 *
	 * @since 26.0
	 *
	 * @param \QuickEventsManager\Registration\Registration $registration Booking.
	 * @param \QuickEventsManager\Events\Event              $event        Event.
	 * @return array<string, string>
	 */
	public static function values_for( $registration, $event ) {
		$where = $event->is_online()
			? $event->online_url()
			: $event->venue_summary();

		return array(
			'attendee_name'  => $registration->booker_name(),
			'attendee_email' => $registration->booker_email(),
			'event_title'    => wp_strip_all_tags( get_the_title( $event->id() ) ),
			'event_url'      => (string) get_permalink( $event->id() ),
			'event_when'     => trim( $event->format_start() . ' ' . $event->timezone_label() ),
			'event_where'    => (string) $where,
			'places'         => (string) number_format_i18n( $registration->quantity() ),
			'reference'      => $registration->code(),
			'cancel_url'     => \QuickEventsManager\Registration\CancellationLink::url( $registration, $event ),
			'site_name'      => (string) get_bloginfo( 'name' ),
		);
	}
}
