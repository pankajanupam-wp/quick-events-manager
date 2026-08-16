<?php
/**
 * GDPR export and erasure for attendee data.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Privacy;

use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\Registration;
use QuickEventsManager\Registration\Repository;

use QuickEventsManager\Domain\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs registrations into WordPress's own privacy tools.
 *
 * The plugin stores names, email addresses and phone numbers, so a site owner
 * handed a subject access request has to be able to answer it. Registering
 * here means the existing Tools > Export/Erase Personal Data screens cover
 * event registrations with no extra work and no separate interface.
 *
 * @since 26.0
 */
final class Privacy {

	/**
	 * Registrations handled per page of a request.
	 */
	const PER_PAGE = 100;

	/**
	 * Hook into the privacy tools.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @since 26.0
	 *
	 * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters Registered exporters.
	 * @return array<string, array{exporter_friendly_name: string, callback: callable}>
	 */
	public function add_exporter( $exporters ) {
		$exporters['quick-events-manager'] = array(
			'exporter_friendly_name' => __( 'Event registrations', 'quick-events-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @since 26.0
	 *
	 * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers Registered erasers.
	 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
	 */
	public function add_eraser( $erasers ) {
		$erasers['quick-events-manager'] = array(
			'eraser_friendly_name' => __( 'Event registrations', 'quick-events-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * What was agreed to, and when.
	 *
	 * The point of recording consent is being able to produce it, so it belongs
	 * in the export more than anywhere else. Absent on registrations taken
	 * before the site asked for consent, or while it was asking for nothing —
	 * and an empty row would misrepresent that as a refusal.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Booking being exported.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function consent_data( Registration $registration ) {
		if ( ! $registration->has_consent() ) {
			return array();
		}

		return array(
			array(
				'name'  => __( 'Consent given', 'quick-events-manager' ),
				'value' => $registration->consent_at(),
			),
			array(
				'name'  => __( 'Consent wording version', 'quick-events-manager' ),
				'value' => $registration->consent_version(),
			),
		);
	}

	/**
	 * Export every registration made with an email address.
	 *
	 * @since 26.0
	 *
	 * @param string $email_address Address being exported.
	 * @param int    $page          One-based page number.
	 * @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool}
	 */
	public function export( $email_address, $page = 1 ) {
		$registrations = Repository::find_by_email( $email_address );
		$page          = max( 1, (int) $page );
		$slice         = array_slice( $registrations, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$items         = array();

		foreach ( $slice as $registration ) {
			$items[] = array(
				'group_id'    => 'qevm_registrations',
				'group_label' => __( 'Event registrations', 'quick-events-manager' ),
				'item_id'     => 'qevm-registration-' . $registration->id(),
				'data'        => array_merge(
					self::booking_data( $registration ),
					self::guest_data( $registration ),
					self::answer_data( $registration ),
					self::consent_data( $registration )
				),
			);
		}

		return array(
			'data' => $items,
			'done' => ( $page * self::PER_PAGE ) >= count( $registrations ),
		);
	}

	/**
	 * The booking itself, as name/value pairs.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Booking being exported.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function booking_data( Registration $registration ) {
		return array(
			array(
				'name'  => __( 'Event', 'quick-events-manager' ),
				'value' => get_the_title( $registration->event_id() ),
			),
			array(
				'name'  => __( 'Name', 'quick-events-manager' ),
				'value' => $registration->booker_name(),
			),
			array(
				'name'  => __( 'Email', 'quick-events-manager' ),
				'value' => $registration->booker_email(),
			),
			array(
				'name'  => __( 'Phone', 'quick-events-manager' ),
				'value' => $registration->booker_phone(),
			),
			array(
				// Cast, because every other value in this report is a
				// string and the exporter renders them all the same way.
				'name'  => __( 'Places', 'quick-events-manager' ),
				'value' => (string) $registration->quantity(),
			),
			array(
				'name'  => __( 'Reference', 'quick-events-manager' ),
				'value' => $registration->code(),
			),
			array(
				'name'  => __( 'Status', 'quick-events-manager' ),
				'value' => $registration->status()->label(),
			),
			array(
				'name'  => __( 'Registered', 'quick-events-manager' ),
				'value' => $registration->created_at(),
			),
		);
	}

	/**
	 * The answers given to the event's own questions.
	 *
	 * Every answer, including the ones marked sensitive. That flag governs what
	 * leaves in a CSV an organiser downloads; it has nothing to do with a person
	 * asking what this site holds about them. Withholding somebody's own dietary
	 * requirements from their own subject access request would be the flag doing
	 * the exact opposite of its job.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Booking being exported.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function answer_data( Registration $registration ) {
		$fields = \QuickEventsManager\CustomFields\Definitions::for_event( $registration->event_id() );

		if ( array() === $fields ) {
			return array();
		}

		$given = \QuickEventsManager\CustomFields\AnswerRepository::for_registrations( array( $registration->id() ) );
		$given = isset( $given[ $registration->id() ] ) ? $given[ $registration->id() ] : array();
		$data  = array();

		foreach ( $fields as $field ) {
			if ( ! isset( $given[ $field->key() ] ) ) {
				continue;
			}

			$value = $given[ $field->key() ];

			$data[] = array(
				'name'  => $field->label(),
				'value' => is_array( $value ) ? implode( ', ', $value ) : (string) $value,
			);
		}

		return $data;
	}

	/**
	 * The names given for the other people on a booking.
	 *
	 * Somebody asking what this site holds about them is owed the names they
	 * submitted for their colleagues as well as their own — it is their booking
	 * and they entered them. Places nobody was named for are left out rather
	 * than exported as blanks, because an empty row answers no question.
	 *
	 * @since 26.0
	 *
	 * @param Registration $registration Booking being exported.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function guest_data( Registration $registration ) {
		$data = array();

		foreach ( AttendeeRepository::for_registration( $registration->id() ) as $attendee ) {
			if ( 1 === $attendee->position() || ! $attendee->is_named() ) {
				continue;
			}

			$data[] = array(
				'name'  => sprintf(
					/* translators: %s: Position of the place within the booking. */
					__( 'Guest %s', 'quick-events-manager' ),
					number_format_i18n( $attendee->position() )
				),
				'value' => $attendee->name(),
			);
		}

		return $data;
	}

	/**
	 * Erase every registration made with an email address.
	 *
	 * Rows are deleted outright rather than anonymised. A registration with
	 * the personal details stripped is just a row nobody can act on, and
	 * keeping it would mean the erasure was not really an erasure.
	 *
	 * The people on each booking go too. Repository::delete() owns that cascade
	 * so that it cannot be left out of one call site — the guests' names are
	 * personal data on a second table, and an erasure that missed them would
	 * still be reported as an erasure.
	 *
	 * $page is part of the eraser callback signature and is deliberately not
	 * used. Unlike the exporter, which reads rows and needs an offset to walk
	 * past what it has already seen, this deletes them: the next call re-queries
	 * and finds only what is left, so offsetting into the result would skip
	 * exactly the rows the previous call was supposed to have removed.
	 *
	 * @since 26.0
	 *
	 * @param string $email_address Address being erased.
	 * @param int    $page          One-based page number. Unused; see above.
	 * @return array{items_removed: int, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( $email_address, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the wp_privacy_personal_data_erasers signature; see above.
		$registrations = Repository::find_by_email( $email_address );
		$removed       = 0;

		foreach ( array_slice( $registrations, 0, self::PER_PAGE ) as $registration ) {
			if ( Repository::delete( $registration->id() ) ) {
				++$removed;
			}
		}

		$messages = array();

		if ( $removed > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: Number of registrations removed. */
				_n(
					'Removed %d event registration.',
					'Removed %d event registrations.',
					$removed,
					'quick-events-manager'
				),
				$removed
			);
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => count( $registrations ) <= self::PER_PAGE,
		);
	}

	/**
	 * Suggest privacy policy wording.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'When you register for an event on this site, we store the name, email address, phone number and number of places you enter, so we know who is attending. If you book more than one place and give the names of the people taking them, we store those names too.', 'quick-events-manager' ) . '</p>'
			. '<p>' . __( 'We record that you agreed to this when you registered, and the time you did, so we can show what you were asked to agree to.', 'quick-events-manager' ) . '</p>'
			. '<p>' . __( 'We do not store your IP address, and we do not share this information with any external service.', 'quick-events-manager' ) . '</p>'
			. '<p>' . __( 'If the event asks questions of its own, we store your answers to them alongside your registration.', 'quick-events-manager' ) . '</p>'
			. '<p>' . __( 'Registrations are kept until the site owner deletes them.', 'quick-events-manager' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Quick Events Manager', 'quick-events-manager' ), wp_kses_post( $content ) );
	}
}
