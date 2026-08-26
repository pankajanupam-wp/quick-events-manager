<?php
/**
 * The check-in module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CheckIn;

use QuickEventsManager\Domain\ModuleLevel;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Modules\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Checking people in at the door, off until somebody asks for it.
 *
 * Stage 8's chunk definitions say "table + service" and never say module — the
 * third time that has happened, after recurrence and ticketing. It has to be
 * one: `Installer::upgrade_schema()` only creates tables for enabled modules,
 * every screen lives behind `register()`, and an install that lists a few
 * meetups should not grow a door screen.
 *
 * Switching it off leaves every record in place. Nobody's attendance is deleted
 * by a site owner tidying their admin menu.
 *
 * @since 26.0
 */
final class CheckInModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'checkin';

	/**
	 * Module id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * Module title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Check-in', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Mark people off as they arrive, from a phone at the door. Two people can work the same door without checking anybody in twice.', 'quick-events-manager' );
	}

	/**
	 * Which disclosure level this module belongs to.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Advanced;
	}

	/**
	 * Can be switched off.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required() {
		return false;
	}

	/**
	 * Add the module's hooks.
	 *
	 * The door screen and the QR code arrive in later chunks. What is here now
	 * is the cascade: a booking's attendees take their check-ins with them when
	 * they go, which nothing else can do without knowing this table exists.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'qevm_attendees_deleted', array( __CLASS__, 'forget_attendees' ), 10, 1 );

		/*
		 * The QR goes on the confirmation from here, not from the module that
		 * sends it. Registration writes the message and knows nothing about
		 * codes at a door; this listens for the message going out and staples
		 * the tickets to it. Switch check-in off and confirmations simply stop
		 * carrying them — no setting, no branch in somebody else's code.
		 */
		add_filter( 'qevm_email_attachments', array( __CLASS__, 'attach_tickets' ), 10, 2 );

		/*
		 * And the same codes in words. A QR is unreadable to the person holding
		 * it: if the camera on the door will not start — an older phone, a work
		 * phone with the camera locked down, a site not on HTTPS — there has to
		 * be something a human can read out and somebody can type in. The
		 * booking reference is not it; the door admits attendees, and a booking
		 * for three is three of them.
		 */
		add_filter( 'qevm_attendee_email', array( __CLASS__, 'add_ticket_codes' ), 10, 2 );
		add_filter( 'qevm_promotion_email', array( __CLASS__, 'add_ticket_codes' ), 10, 2 );
		add_filter( 'qevm_email_placeholders', array( __CLASS__, 'offer_placeholder' ), 10, 1 );

		( new RestController() )->register();

		if ( is_admin() ) {
			( new DoorScreen() )->register();
		}
	}

	/**
	 * Print each person's ticket code in the message.
	 *
	 * Answers `qevm_attendee_email` and `qevm_promotion_email`. The lines are
	 * added to the built-in wording, and the same list is offered as
	 * `{ticket_codes}` so a site that has written its own template can place it
	 * wherever it likes — a template replaces the body outright, so appending
	 * to it would reach nobody who had edited one.
	 *
	 * @since 26.0
	 *
	 * @param mixed $email        Message being built. Keys: to, subject, body, headers, values.
	 * @param mixed $registration The booking.
	 * @return array<string, mixed>
	 */
	public static function add_ticket_codes( $email, $registration ) {
		$email = is_array( $email ) ? $email : array();

		if ( ! is_object( $registration ) || ! method_exists( $registration, 'id' ) ) {
			return $email;
		}

		$attendees = \QuickEventsManager\Registration\AttendeeRepository::for_registration( (int) $registration->id() );

		if ( array() === $attendees ) {
			return $email;
		}

		$lines = array();

		foreach ( $attendees as $attendee ) {
			$code = $attendee->ticket_code();

			if ( '' === $code ) {
				continue;
			}

			$lines[] = 1 === count( $attendees )
				? $code
				: sprintf(
					/* translators: 1: Attendee name. 2: Their ticket code. */
					__( '%1$s — %2$s', 'quick-events-manager' ),
					$attendee->name(),
					$code
				);
		}

		if ( array() === $lines ) {
			return $email;
		}

		$codes = implode( "\n", $lines );

		$email['values']                 = isset( $email['values'] ) && is_array( $email['values'] ) ? $email['values'] : array();
		$email['values']['ticket_codes'] = $codes;

		$email['body'] = (string) ( $email['body'] ?? '' )
			. "\n\n"
			. _n(
				'Your ticket is attached. If the code will not scan, this is the code on it:',
				'A ticket for each person is attached. If a code will not scan, these are the codes on them:',
				count( $lines ),
				'quick-events-manager'
			)
			. "\n"
			. $codes;

		return $email;
	}

	/**
	 * Offer `{ticket_codes}` to the template editor.
	 *
	 * Answers `qevm_email_placeholders`, so the list somebody is shown while
	 * writing a template is the list that will actually substitute.
	 *
	 * @since 26.0
	 *
	 * @param mixed $placeholders Placeholders offered so far.
	 * @return array<string, string>
	 */
	public static function offer_placeholder( $placeholders ) {
		$placeholders = is_array( $placeholders ) ? $placeholders : array();

		$placeholders['ticket_codes'] = __( 'The ticket code for each person on the booking', 'quick-events-manager' );

		return $placeholders;
	}

	/**
	 * Put a QR code for each ticket on a confirmation.
	 *
	 * Answers `qevm_email_attachments`. One file per person, named by the
	 * ticket code, because a booking for three is three people arriving
	 * separately and holding up three different phones.
	 *
	 * SVG rather than PNG: a QR code is a grid of squares, an SVG of it is
	 * under a kilobyte and scales to whatever the screen or the printer is,
	 * and rendering a PNG would mean depending on GD or Imagick being present
	 * on the host — which is exactly the kind of assumption that fails on
	 * somebody else's server rather than on ours.
	 *
	 * @since 26.0
	 *
	 * @param mixed $files Files attached so far.
	 * @param mixed $row   Queue row.
	 * @return array<int, array<string, string>>
	 */
	public static function attach_tickets( $files, $row ) {
		$files = is_array( $files ) ? $files : array();
		$row   = is_array( $row ) ? $row : array();

		/*
		 * Only the messages that mean somebody has a place. A waitlist notice
		 * carries no ticket because there is nothing to admit yet, and the
		 * organiser's notification is not somebody's ticket at all.
		 */
		$wants = array( 'attendee_confirmation', 'waitlist_promotion' );

		if ( ! in_array( (string) ( $row['template'] ?? '' ), $wants, true ) ) {
			return $files;
		}

		$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );

		if ( ! is_array( $meta ) || empty( $meta['registration_id'] ) ) {
			return $files;
		}

		foreach ( \QuickEventsManager\Registration\AttendeeRepository::for_registration( (int) $meta['registration_id'] ) as $attendee ) {
			$svg = Qr::svg( $attendee->ticket_code() );

			if ( '' === $svg ) {
				continue;
			}

			$files[] = array(
				'name'    => sanitize_file_name( $attendee->ticket_code() . '.svg' ),
				'content' => $svg,
				'type'    => 'image/svg+xml',
			);
		}

		return $files;
	}

	/**
	 * Remove the check-ins of attendees that have been deleted.
	 *
	 * Answers `qevm_attendees_deleted`. The registration module owns those rows
	 * and cannot reach this table — modules depend on the domain, not on each
	 * other — so it says who went and this listens.
	 *
	 * @since 26.0
	 *
	 * @param mixed $attendee_ids Ids that were removed.
	 * @return void
	 */
	public static function forget_attendees( $attendee_ids ) {
		if ( ! is_array( $attendee_ids ) ) {
			return;
		}

		CheckInRepository::delete_for_attendees( $attendee_ids );
	}

	/**
	 * Create the check-ins table.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Installer::run_schema( CheckInRepository::schema() );
	}

	/**
	 * Switching off records nothing further and deletes nothing.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {
	}

	/**
	 * Whether the site has switched check-in on.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return \QuickEventsManager\Plugin::instance()->registry()->is_enabled( self::ID );
	}
}
