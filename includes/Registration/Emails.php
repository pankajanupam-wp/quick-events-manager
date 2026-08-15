<?php
/**
 * Registration emails.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Frontend\Ics;

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
		add_action( 'qevm_registration_promoted', array( $this, 'send_promotion_notice' ), 10, 2 );
	}

	/**
	 * Tell somebody they have come off the waiting list.
	 *
	 * The whole reason promotion is not silent. Somebody who joined a waiting
	 * list has almost certainly made other plans by now, and a place they are
	 * not told about is a seat that stays empty while the organiser counts on
	 * them being in it. The message therefore leads with what changed, not
	 * with the event details, and carries the cancellation link so the answer
	 * "I can't come after all" takes one click.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Booking, now confirmed.
	 * @param Event        $event        Event.
	 * @return void
	 */
	public function send_promotion_notice( Registration $registration, Event $event ) {
		$lines = array();

		/* translators: %s: Attendee name. */
		$lines[] = sprintf( __( 'Hi %s,', 'quick-events-manager' ), $registration->booker_name() );
		$lines[] = '';
		/* translators: %s: Event title. */
		$lines[] = sprintf( __( 'Good news — a place has become available at %s, and yours is now confirmed.', 'quick-events-manager' ), get_the_title( $event->id() ) );
		$lines[] = '';
		$lines[] = __( 'You were on the waiting list, so you do not need to book again.', 'quick-events-manager' );
		$lines[] = '';
		$lines   = array_merge( $lines, self::event_summary_lines( $event ) );
		$lines[] = '';
		/* translators: %s: Registration reference code. */
		$lines[] = sprintf( __( 'Your reference: %s', 'quick-events-manager' ), $registration->code() );
		$lines[] = '';
		$lines[] = get_permalink( $event->id() );
		$lines[] = '';
		$lines[] = __( 'If you can no longer come, please cancel so somebody else can take your place:', 'quick-events-manager' );
		$lines[] = CancellationLink::url( $registration, $event );

		/**
		 * Filter the email telling somebody they are off the waiting list.
		 *
		 * @since 26.0
		 *
		 * @param array        $email        Keys: to, subject, body, headers.
		 * @param Registration $registration The registration.
		 * @param Event        $event        The event.
		 */
		$email = apply_filters(
			'qevm_promotion_email',
			array(
				'to'      => $registration->booker_email(),
				/* translators: %s: Event title. */
				'subject' => sprintf( __( 'Your place at %s is confirmed', 'quick-events-manager' ), get_the_title( $event->id() ) ),
				'body'    => implode( "\n", $lines ),
				'headers' => array(),
				'ics'     => self::calendar( $event ),
			),
			$registration,
			$event
		);

		self::dispatch( $email, $event );
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
		$lines[] = sprintf( __( 'Hi %s,', 'quick-events-manager' ), $registration->booker_name() );
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

		/*
		 * The cancellation link goes in every confirmation, including the
		 * waitlist one. Somebody who has queued for a place and then finds
		 * they cannot come is the single most valuable person to hear from —
		 * they are holding a position in front of people who could take it.
		 */
		$lines[] = '';
		$lines[] = __( 'If you can no longer come, please cancel so somebody else can take your place:', 'quick-events-manager' );
		$lines[] = CancellationLink::url( $registration, $event );

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
				'to'      => $registration->booker_email(),
				'subject' => $subject,
				'body'    => $body,
				'headers' => array(),

				/*
				 * No calendar file for a waiting list place. An .ics is a
				 * statement that this is happening and you are going to it;
				 * putting a provisional place straight into somebody's calendar
				 * is how a person turns up to an event they were never
				 * confirmed for. They get one when they are promoted.
				 */
				'ics'     => $waitlisted ? '' : self::calendar( $event ),
			),
			$registration,
			$event
		);

		self::dispatch( $email, $event );
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
		$lines[] = sprintf( __( '%1$s has registered for %2$s.', 'quick-events-manager' ), $registration->booker_name(), get_the_title( $event->id() ) );
		$lines[] = '';
		/* translators: %s: Attendee email address. */
		$lines[] = sprintf( __( 'Email: %s', 'quick-events-manager' ), $registration->booker_email() );

		if ( '' !== $registration->booker_phone() ) {
			/* translators: %s: Attendee phone number. */
			$lines[] = sprintf( __( 'Phone: %s', 'quick-events-manager' ), $registration->booker_phone() );
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

		self::dispatch( $email );
	}

	/**
	 * The event as an iCalendar document, or an empty string if it has no date.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return string
	 */
	private static function calendar( Event $event ) {
		if ( '' === $event->start_utc() ) {
			return '';
		}

		return Ics::build( $event );
	}

	/**
	 * Send one of these emails, with its calendar file if it has one.
	 *
	 * `wp_mail()` takes attachments as **file paths**, and the calendar exists
	 * only as a string. Writing it to a temporary file to hand back a path
	 * means finding a writable directory on a host that may not have one,
	 * guessing at a unique name, and deleting it afterwards on a code path
	 * that can exit early — three ways to leave rubbish in the uploads folder
	 * for the sake of a 400-byte text file.
	 *
	 * So it goes on through PHPMailer directly. The listener is added
	 * immediately before the send and removed immediately after, in a finally,
	 * because `phpmailer_init` fires for **every** email the site sends: left
	 * attached, it would staple this event's calendar file to password resets,
	 * comment notifications and every other plugin's mail.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $email Keys: to, subject, body, headers, ics.
	 * @param Event|null           $event Event the calendar file describes.
	 * @return void
	 */
	private static function dispatch( array $email, ?Event $event = null ) {
		if ( empty( $email['to'] ) ) {
			return;
		}

		$ics = isset( $email['ics'] ) ? (string) $email['ics'] : '';

		if ( '' === $ics || null === $event ) {
			wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );

			return;
		}

		$filename = sanitize_file_name( get_post_field( 'post_name', $event->id() ) . '.ics' );

		$attach = static function ( $phpmailer ) use ( $ics, $filename ) {
			$phpmailer->addStringAttachment(
				$ics,
				$filename,
				'base64',
				'text/calendar; charset=utf-8; method=PUBLISH'
			);
		};

		add_action( 'phpmailer_init', $attach );

		try {
			wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );
		} finally {
			remove_action( 'phpmailer_init', $attach );
		}
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
