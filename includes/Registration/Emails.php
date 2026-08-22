<?php
/**
 * Registration emails.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Email\Queue;
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

		/*
		 * The calendar file is produced at send time rather than stored on the
		 * queue row, so this has to be hooked whenever registration is on and
		 * not only when a message is being built.
		 */
		add_filter( 'qevm_email_attachments', array( __CLASS__, 'attach_calendar' ), 10, 2 );
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
				'values'  => \QuickEventsManager\Email\Templates::values_for( $registration, $event ),
			),
			$registration,
			$event
		);

		self::dispatch( $email, $event, 'waitlist_promotion', $registration );
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
				'values'  => \QuickEventsManager\Email\Templates::values_for( $registration, $event ),
			),
			$registration,
			$event
		);

		/*
		 * No calendar file for a waiting list place, which is why the two cases
		 * are different templates rather than one with a flag. An .ics is a
		 * statement that this is happening and you are going to it; putting a
		 * provisional place straight into somebody's calendar is how a person
		 * turns up to an event they were never confirmed for. They get one when
		 * they are promoted.
		 *
		 * This was carried by an `ics` key on the message until the queue
		 * arrived, and moving attachments to send time lost it — the template
		 * was the same either way, so a waitlisted place started receiving a
		 * calendar entry for a seat it did not have. The test that says so is
		 * the reason it did not ship.
		 */
		self::dispatch( $email, $event, $waitlisted ? 'attendee_waitlisted' : 'attendee_confirmation', $registration );
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
				'values'  => \QuickEventsManager\Email\Templates::values_for( $registration, $event ),
			),
			$registration,
			$event
		);

		self::dispatch( $email, null, 'organiser_notification' );
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
	 * Put one of these emails on the queue.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $email        Keys: to, subject, body, headers.
	 * @param Event|null           $event        Event the message is about, if any.
	 * @param string               $template     Which message this is, so the right
	 *                                           attachment can be produced at send
	 *                                           time.
	 * @param Registration|null    $registration Booking the message is about, if any.
	 * @return void
	 */
	private static function dispatch( array $email, ?Event $event = null, $template = '', ?Registration $registration = null ) {
		if ( empty( $email['to'] ) ) {
			return;
		}

		/*
		 * The site's own wording, if it has written any and the module that
		 * lets it is on. The built-in wording is what everybody else gets, and
		 * a template missing a subject or a body counts as not written rather
		 * than being sent half-empty.
		 */
		$rendered = self::apply_template( $template, $email );

		if ( array() !== $rendered ) {
			$email = array_merge( $email, $rendered );
		}

		/*
		 * Queued, not sent. Registration used to hand each message straight to
		 * wp_mail() inside the request that triggered it, which is fine for the
		 * one or two a booking produces and is the thing that makes emailing
		 * five hundred attendees impossible. Everything goes through the queue
		 * so there is one path, one record of what was attempted, and one place
		 * a failure is visible.
		 *
		 * The calendar file is not carried on the row. It is regenerated when
		 * the message is actually sent, from the event the row points at — see
		 * the attachment filter in Email\Worker. A copy stored now would put a
		 * time in somebody's diary that the event may have moved away from by
		 * the time the message goes.
		 */
		$queued = Queue::add(
			array(
				'template'     => $template,
				'recipient'    => (string) $email['to'],
				'subject'      => (string) $email['subject'],
				'body'         => (string) $email['body'],
				'headers'      => isset( $email['headers'] ) ? (array) $email['headers'] : array(),
				'context_type' => null !== $event ? 'event' : '',
				'context_id'   => null !== $event ? $event->id() : 0,

				/*
				 * Which booking, when there is one. The context pair says which
				 * event and is what a withdrawal searches on; this is detail for
				 * whoever is listening when the message is finally sent. Nothing
				 * here knows what will use it — the check-in module attaches
				 * that booking's tickets, and registration is not told.
				 */
				'meta'         => null !== $registration ? array( 'registration_id' => $registration->id() ) : array(),
			)
		);

		if ( 0 === $queued ) {
			/*
			 * The queue could not take it — most likely the table does not
			 * exist, on a site upgrading from before it did. Sending directly
			 * is worse than queueing and better than losing the message.
			 */
			wp_mail( $email['to'], $email['subject'], $email['body'], isset( $email['headers'] ) ? $email['headers'] : array() );
		}
	}

	/**
	 * Rewrite a message from the site's own template, if there is one.
	 *
	 * @since 26.0
	 *
	 * @param string               $template Template id.
	 * @param array<string, mixed> $email    Message as built.
	 * @return array<string, mixed> Empty when the built-in wording stands.
	 */
	private static function apply_template( $template, array $email ) {
		if ( '' === (string) $template ) {
			return array();
		}

		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\Email\TemplatesModule::ID ) ) {
			return array();
		}

		$written = \QuickEventsManager\Email\Templates::get( $template );

		if ( null === $written ) {
			return array();
		}

		$values = isset( $email['values'] ) && is_array( $email['values'] ) ? $email['values'] : array();

		return $written->render( $values );
	}

	/**
	 * Attach the calendar file to a confirmation, at the moment it is sent.
	 *
	 * @since 26.0
	 *
	 * @param array<int, array<string, string>> $files Files to attach.
	 * @param array<string, mixed>              $row   Queue row.
	 * @return array<int, array<string, string>>
	 */
	public static function attach_calendar( $files, $row ) {
		$files = (array) $files;

		$wants = array( 'attendee_confirmation', 'waitlist_promotion' );

		if ( ! in_array( (string) ( $row['template'] ?? '' ), $wants, true ) ) {
			return $files;
		}

		if ( 'event' !== (string) ( $row['context_type'] ?? '' ) ) {
			return $files;
		}

		$event = new Event( (int) ( $row['context_id'] ?? 0 ) );

		if ( ! $event->is_valid() ) {
			return $files;
		}

		$ics = self::calendar( $event );

		if ( '' === $ics ) {
			return $files;
		}

		$files[] = array(
			'name'    => sanitize_file_name( get_post_field( 'post_name', $event->id() ) . '.ics' ),
			'content' => $ics,
			'type'    => 'text/calendar; charset=utf-8; method=PUBLISH',
		);

		return $files;
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
