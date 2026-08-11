<?php
/**
 * Registration emails.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Events\Event;

use QuickEventsManager\Domain\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the attendee their confirmation and the organiser their notification.
 *
 * Plain text through wp_mail(), with every subject and body behind a filter.
 * Templated HTML email is a later part of this release; getting a reliable plain message out
 * of a shared host is the thing that actually matters first.
 *
 * @since 26.0
 */
final class Emails {

	/**
	 * Hook into registration.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'qevm_registration_created', array( $this, 'send_attendee_confirmation' ), 10, 2 );
		add_action( 'qevm_registration_created', array( $this, 'send_organizer_notification' ), 20, 2 );
	}

	/**
	 * Email the attendee.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Stored registration.
	 * @param Event        $event        Event registered for.
	 * @return void
	 */
	public function send_attendee_confirmation( Registration $registration, Event $event ) {
		$waitlisted = RegistrationStatus::Waitlisted === $registration->status();

		$subject = $waitlisted
			/* translators: %s: Event title. */
			? sprintf( __( 'You are on the waiting list for %s', 'quick-events-manager' ), get_the_title( $event->id() ) )
			/* translators: %s: Event title. */
			: sprintf( __( 'You are registered for %s', 'quick-events-manager' ), get_the_title( $event->id() ) );

		$lines = array();

		/* translators: %s: Attendee name. */
		$lines[] = sprintf( __( 'Hi %s,', 'quick-events-manager' ), $registration->name() );
		$lines[] = '';

		if ( $waitlisted ) {
			/* translators: %s: Event title. */
			$lines[] = sprintf( __( '%s is currently full, so you have been added to the waiting list. We will be in touch if a place becomes available.', 'quick-events-manager' ), get_the_title( $event->id() ) );
		} else {
			/* translators: %s: Event title. */
			$lines[] = sprintf( __( 'Your place at %s is confirmed.', 'quick-events-manager' ), get_the_title( $event->id() ) );
		}

		$lines[] = '';
		$lines   = array_merge( $lines, self::event_summary_lines( $event ) );
		$lines[] = '';
		/* translators: %s: Registration reference code. */
		$lines[] = sprintf( __( 'Your reference: %s', 'quick-events-manager' ), $registration->code() );
		$lines[] = '';
		$lines[] = get_permalink( $event->id() );

		$body = implode( "\n", $lines );

		/**
		 * Filter the confirmation email sent to the attendee.
		 *
		 * @since 26.0
		 *
		 * @param array        $email        Keys: to, subject, body, headers.
		 * @param Registration $registration The registration.
		 * @param Event        $event        The event.
		 */
		$email = apply_filters(
			'qevm_attendee_email',
			array(
				'to'      => $registration->email(),
				'subject' => $subject,
				'body'    => $body,
				'headers' => array(),
			),
			$registration,
			$event
		);

		if ( empty( $email['to'] ) ) {
			return;
		}

		wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );
	}

	/**
	 * Email whoever runs the site.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Stored registration.
	 * @param Event        $event        Event registered for.
	 * @return void
	 */
	public function send_organizer_notification( Registration $registration, Event $event ) {
		$lines = array();

		/* translators: 1: Attendee name, 2: Event title. */
		$lines[] = sprintf( __( '%1$s has registered for %2$s.', 'quick-events-manager' ), $registration->name(), get_the_title( $event->id() ) );
		$lines[] = '';
		/* translators: %s: Attendee email address. */
		$lines[] = sprintf( __( 'Email: %s', 'quick-events-manager' ), $registration->email() );

		if ( '' !== $registration->phone() ) {
			/* translators: %s: Attendee phone number. */
			$lines[] = sprintf( __( 'Phone: %s', 'quick-events-manager' ), $registration->phone() );
		}

		/* translators: %s: Registration status. */
		$lines[] = sprintf( __( 'Status: %s', 'quick-events-manager' ), $registration->status()->label() );
		/* translators: %s: Registration reference code. */
		$lines[] = sprintf( __( 'Reference: %s', 'quick-events-manager' ), $registration->code() );
		$lines[] = '';
		$lines[] = admin_url( 'edit.php?post_type=' . QEVM_POST_TYPE . '&page=qevm-attendees&event_id=' . $event->id() );

		/**
		 * Filter the notification email sent to the site.
		 *
		 * Return an empty `to` to stop the notification being sent.
		 *
		 * @since 26.0
		 *
		 * @param array        $email        Keys: to, subject, body, headers.
		 * @param Registration $registration The registration.
		 * @param Event        $event        The event.
		 */
		$email = apply_filters(
			'qevm_organizer_email',
			array(
				'to'      => Settings::notification_email(),
				/* translators: %s: Event title. */
				'subject' => sprintf( __( 'New registration for %s', 'quick-events-manager' ), get_the_title( $event->id() ) ),
				'body'    => implode( "\n", $lines ),
				'headers' => array(),
			),
			$registration,
			$event
		);

		if ( empty( $email['to'] ) ) {
			return;
		}

		wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );
	}

	/**
	 * When and where the event is, as plain text lines.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return string[]
	 */
	private static function event_summary_lines( Event $event ) {
		$lines = array();
		$start = $event->format_start();

		if ( '' !== $start ) {
			$label = $event->timezone_label();
			/* translators: %s: Formatted start date and time. */
			$lines[] = sprintf( __( 'When: %s', 'quick-events-manager' ), trim( $start . ( '' !== $label ? ' ' . $label : '' ) ) );
		}

		if ( $event->is_online() ) {
			$lines[] = __( 'Where: Online', 'quick-events-manager' );

			if ( '' !== $event->online_url() ) {
				/* translators: %s: Joining URL. */
				$lines[] = sprintf( __( 'Joining link: %s', 'quick-events-manager' ), $event->online_url() );
			}
		} else {
			$venue = $event->venue_summary();

			if ( '' !== $venue ) {
				/* translators: %s: Venue address. */
				$lines[] = sprintf( __( 'Where: %s', 'quick-events-manager' ), $venue );
			}
		}

		return $lines;
	}
}
