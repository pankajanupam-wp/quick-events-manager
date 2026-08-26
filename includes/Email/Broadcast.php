<?php
/**
 * Emailing everybody who registered for an event.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Registration\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * One message, rendered per person, put on the queue.
 *
 * The feature an organiser reaches for the day before an event: the venue has
 * moved, bring a coat, here is the joining link. It is also the most dangerous
 * thing in the admin, because it is the only action here that cannot be undone
 * from inside WordPress — a message that has left the building has left.
 *
 * Three things follow from that, and they are the design rather than polish on
 * top of it:
 *
 * 1. **The audience is chosen, never inherited.** The attendee screen above
 *    this form has a search box and a status filter. Taking the audience from
 *    those would mean an organiser who searched for "smith" and then wrote a
 *    message emailed only the Smiths, with nothing on screen saying so. The
 *    audience is a field in this form and nothing else feeds it.
 * 2. **The count is not a guess.** What the button says and what the send does
 *    come from the same pair of queries over the same clause, so "Email 137
 *    people" is a fact about the database rather than an estimate.
 * 3. **Broadcasts get their own context.** Confirmations are queued against
 *    `event`; these are queued against `broadcast` with the same id. Withdraw
 *    therefore takes back an unsent broadcast without taking back the
 *    confirmations sitting behind it in the queue — which sharing a context
 *    would have done silently.
 *
 * Nobody gets two copies. Recipients are distinct addresses, not bookings, so
 * somebody who booked twice hears once.
 *
 * @since 26.0
 */
final class Broadcast {

	/**
	 * Context type for queue rows this puts on.
	 */
	const CONTEXT = 'broadcast';

	/**
	 * Context type for a test send.
	 *
	 * Separate so a test neither shows up in the delivery counts for the real
	 * thing nor is swept up by withdraw().
	 */
	const TEST_CONTEXT = 'broadcast_test';

	/**
	 * Template id recorded on the queue row.
	 */
	const TEMPLATE = 'broadcast';

	/**
	 * Template id recorded on a test send.
	 */
	const TEST_TEMPLATE = 'broadcast_test';

	/**
	 * Recipients read from the database at a time.
	 *
	 * Only so a five-thousand-attendee event does not become five thousand
	 * Registration objects at once. The queue rows themselves are inserted one
	 * at a time whatever this is.
	 */
	const BATCH = 200;

	/**
	 * Who a broadcast can be addressed to, and what each means in statuses.
	 *
	 * Named audiences rather than raw statuses, because "pending" is a word
	 * about this plugin's state machine and not about anybody's event. The
	 * default is confirmed places alone: "see you tomorrow" reaching somebody
	 * on the waiting list tells them they have a seat they do not have.
	 *
	 * Cancelled bookings are in no audience. Somebody who withdrew has said
	 * they are not coming, and the address was given to arrange a place that no
	 * longer exists.
	 *
	 * @since 26.0
	 *
	 * @return array<string, array{label: string, statuses: string[]}>
	 */
	public static function audiences() {
		return array(
			'confirmed' => array(
				'label'    => __( 'Confirmed places only', 'quick-events-manager' ),
				'statuses' => array( RegistrationStatus::Confirmed->value ),
			),
			'waiting'   => array(
				'label'    => __( 'Confirmed places and the waiting list', 'quick-events-manager' ),
				'statuses' => array( RegistrationStatus::Confirmed->value, RegistrationStatus::Waitlisted->value ),
			),
			'everyone'  => array(
				'label'    => __( 'Everybody still registered, including unconfirmed bookings', 'quick-events-manager' ),
				'statuses' => array(
					RegistrationStatus::Confirmed->value,
					RegistrationStatus::Pending->value,
					RegistrationStatus::Waitlisted->value,
				),
			),
		);
	}

	/**
	 * The statuses one audience covers.
	 *
	 * An unrecognised audience is empty rather than everybody. Every caller
	 * treats an empty status list as "no recipients", so a typo in a URL sends
	 * nothing instead of sending to the largest possible group.
	 *
	 * @since 26.0
	 *
	 * @param string $audience Audience key.
	 * @return string[]
	 */
	public static function statuses_for( $audience ) {
		$audiences = self::audiences();
		$key       = (string) $audience;

		return isset( $audiences[ $key ] ) ? $audiences[ $key ]['statuses'] : array();
	}

	/**
	 * How many people one audience is.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $audience Audience key.
	 * @return int
	 */
	public static function count( $event_id, $audience ) {
		return Repository::count_recipients( (int) $event_id, self::statuses_for( $audience ) );
	}

	/**
	 * Queue one message for every person in an audience.
	 *
	 * Nothing is sent here. Five hundred rows are inserted and the request
	 * returns; the worker drains them over the following ticks. That is the
	 * whole point of the queue and the reason this can be a button rather than
	 * a warning about timeouts.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param string               $audience Audience key.
	 * @param array<string, mixed> $message  Keys: subject, body, format.
	 * @return array{queued: int, recipients: int}|\WP_Error
	 */
	public static function send( $event_id, $audience, array $message ) {
		$event = new Event( (int) $event_id );

		if ( ! $event->is_valid() ) {
			return new \WP_Error( 'qevm_no_event', __( 'That event could not be found.', 'quick-events-manager' ) );
		}

		$template = self::compose( $message, self::TEMPLATE );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$statuses = self::statuses_for( $audience );

		if ( array() === $statuses ) {
			return new \WP_Error( 'qevm_no_audience', __( 'Choose who the message is for.', 'quick-events-manager' ) );
		}

		$total  = Repository::count_recipients( $event->id(), $statuses );
		$queued = 0;
		$offset = 0;

		while ( true ) {
			$batch = Repository::recipients_for_event( $event->id(), $statuses, self::BATCH, $offset );

			if ( array() === $batch ) {
				break;
			}

			foreach ( $batch as $registration ) {
				$rendered = $template->render( Templates::values_for( $registration, $event ) );

				$id = Queue::add(
					array(
						'template'     => self::TEMPLATE,
						'recipient'    => $registration->booker_email(),
						'subject'      => $rendered['subject'],
						'body'         => $rendered['body'],
						'headers'      => self::headers( $rendered['headers'] ),
						'context_type' => self::CONTEXT,
						'context_id'   => $event->id(),
					)
				);

				if ( $id > 0 ) {
					++$queued;
				}
			}

			$offset += self::BATCH;
		}

		/*
		 * Recipients but nothing queued means the queue itself could not take
		 * them — in practice its table is missing, on a site that enabled
		 * registration before the queue existed and has not upgraded since.
		 *
		 * Reported as an error rather than as a successful send of nothing. The
		 * registration path falls back to a direct wp_mail() when the queue
		 * refuses a message, and that is right for one message; doing it for
		 * four hundred is precisely the synchronous send the queue exists to
		 * prevent, and it would die part-way through having sent an unknowable
		 * number of them. Sending none and saying so is the better failure.
		 */
		if ( 0 === $queued && $total > 0 ) {
			return new \WP_Error(
				'qevm_queue_unavailable',
				__( 'The message could not be queued, so nothing was sent. Check that Quick Events Manager has finished upgrading — its email queue table is missing.', 'quick-events-manager' )
			);
		}

		/**
		 * Fires after a broadcast has been put on the queue.
		 *
		 * Queued, not sent — nothing has left the site yet when this runs.
		 *
		 * @since 26.0
		 *
		 * @param int    $event_id Event the broadcast is about.
		 * @param int    $queued   Rows the queue accepted.
		 * @param string $audience Audience key it was addressed to.
		 */
		do_action( 'qevm_broadcast_queued', $event->id(), $queued, (string) $audience );

		return array(
			'queued'     => $queued,
			'recipients' => $total,
		);
	}

	/**
	 * Queue the same message to one address, as a test.
	 *
	 * Not in the chunk as written, and here anyway: this is the only way to
	 * find out that `{attendee_nmae}` is a typo before four hundred people read
	 * it. Placeholders are filled from a real booking where the event has one,
	 * because a test that renders every placeholder empty proves nothing about
	 * the placeholders.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param string               $address  Where to send it.
	 * @param array<string, mixed> $message  Keys: subject, body, format.
	 * @return true|\WP_Error
	 */
	public static function send_test( $event_id, $address, array $message ) {
		$event = new Event( (int) $event_id );

		if ( ! $event->is_valid() ) {
			return new \WP_Error( 'qevm_no_event', __( 'That event could not be found.', 'quick-events-manager' ) );
		}

		$address = sanitize_email( (string) $address );

		if ( '' === $address || ! is_email( $address ) ) {
			return new \WP_Error( 'qevm_no_address', __( 'Your account has no email address to send a test to.', 'quick-events-manager' ) );
		}

		$template = self::compose( $message, self::TEST_TEMPLATE );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$sample = Repository::recipients_for_event( $event->id(), RegistrationStatus::values(), 1 );

		$values = array() !== $sample
			? Templates::values_for( $sample[0], $event )
			: self::sample_values( $event );

		$rendered = $template->render( $values );

		$queued = Queue::add(
			array(
				'template'     => self::TEST_TEMPLATE,
				'recipient'    => $address,
				/* translators: %s: Subject line of the message being tested. */
				'subject'      => sprintf( __( '[Test] %s', 'quick-events-manager' ), $rendered['subject'] ),
				'body'         => $rendered['body'],
				'headers'      => self::headers( $rendered['headers'] ),
				'context_type' => self::TEST_CONTEXT,
				'context_id'   => $event->id(),
			)
		);

		if ( 0 === $queued ) {
			return new \WP_Error( 'qevm_not_queued', __( 'The test could not be queued.', 'quick-events-manager' ) );
		}

		return true;
	}

	/**
	 * Take back a broadcast that has not gone yet.
	 *
	 * Only what is still queued. Anything already handed to `wp_mail()` is
	 * beyond recall, and this does not pretend otherwise — the notice reports
	 * how many were caught, which on a fast queue is honestly zero.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return int Messages withdrawn.
	 */
	public static function withdraw( $event_id ) {
		return Queue::cancel_for_context( self::CONTEXT, (int) $event_id );
	}

	/**
	 * The contexts an event's mail is spread across.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function event_contexts() {
		return array( 'event', self::CONTEXT, self::TEST_CONTEXT );
	}

	/**
	 * Turn submitted text into a template, or say why it cannot be one.
	 *
	 * The escaping rule is the one in Template: the message body is written by
	 * somebody holding a capability, so its markup is theirs to write, and the
	 * values substituted into it come from a public form and are escaped on the
	 * way in. An HTML body is passed through `wp_kses_post()` first — having
	 * the capability to be on this screen is not a reason to be able to mail a
	 * script tag to four hundred people.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $message Keys: subject, body, format.
	 * @param string               $id      Template id to build.
	 * @return Template|\WP_Error
	 */
	private static function compose( array $message, $id ) {
		$subject = isset( $message['subject'] ) ? sanitize_text_field( (string) $message['subject'] ) : '';
		$body    = isset( $message['body'] ) ? (string) $message['body'] : '';
		$format  = isset( $message['format'] ) && Template::FORMAT_HTML === $message['format']
			? Template::FORMAT_HTML
			: Template::FORMAT_TEXT;

		$body = Template::FORMAT_HTML === $format
			? wp_kses_post( $body )
			: wp_strip_all_tags( $body );

		$template = new Template( (string) $id, $subject, $body, $format );

		if ( ! $template->is_usable() ) {
			return new \WP_Error(
				'qevm_empty_message',
				__( 'A message needs both a subject and something to say.', 'quick-events-manager' )
			);
		}

		return $template;
	}

	/**
	 * Headers every broadcast carries.
	 *
	 * `Reply-To` is the one that matters. `wp_mail()` sends from
	 * `wordpress@` the site's domain by default, which on most hosts is not a
	 * mailbox anybody reads — so without this, four hundred people are told
	 * about a venue change and every reply asking about parking disappears.
	 *
	 * @since 26.0
	 *
	 * @param string[] $headers Headers the rendered template asked for.
	 * @return string[]
	 */
	private static function headers( array $headers ) {
		$reply_to = Settings::notification_email();

		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return $headers;
	}

	/**
	 * Stand-in values for a test on an event nobody has registered for yet.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return array<string, string>
	 */
	private static function sample_values( Event $event ) {
		$where = $event->is_online() ? $event->online_url() : $event->venue_summary();

		return array(
			'attendee_name'  => __( 'Sample Attendee', 'quick-events-manager' ),
			'attendee_email' => 'attendee@example.com',
			'event_title'    => wp_strip_all_tags( (string) get_the_title( $event->id() ) ),
			'event_url'      => (string) get_permalink( $event->id() ),
			'event_when'     => trim( $event->format_start() . ' ' . $event->timezone_label() ),
			'event_where'    => (string) $where,
			'places'         => (string) number_format_i18n( 1 ),
			'reference'      => 'QEVM-SAMPLE',
			'cancel_url'     => (string) get_permalink( $event->id() ),
			'site_name'      => (string) get_bloginfo( 'name' ),
		);
	}
}
