<?php
/**
 * The registration feature module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Install\Installer;
use QuickEventsManager\Modules\Module;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * Free registration: a form, a capacity, and a list of who is coming.
 *
 * Off on a fresh install. A site that only publishes a calendar of events
 * never gets the table, the form, the admin screen or the front-end assets.
 *
 * @since 26.0
 */
final class RegistrationModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'registration';

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
		return __( 'Registration and attendees', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add a sign-up form to your events, set a capacity, and manage the list of attendees. Includes confirmation emails and CSV export.', 'quick-events-manager' );
	}

	/**
	 * Disclosure level.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Standard;
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
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		( new FormHandler() )->register();
		( new CancellationHandler() )->register();
		( new Waitlist() )->register();
		( new Emails() )->register();
		( new \QuickEventsManager\Privacy\Privacy() )->register();
		( new \QuickEventsManager\Privacy\Retention() )->register();
		( new \QuickEventsManager\Email\Worker() )->register();

		add_filter( 'cron_schedules', array( \QuickEventsManager\Email\Worker::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Five minutes, for a queue that must not leave a confirmation sitting for an hour.

		/*
		 * A date somebody has booked onto is never deleted by a rule change. The
		 * occurrence table asks; this answers, because the domain must not reach
		 * into a module that may not be loaded — with registration off there are
		 * no bookings to protect and nothing answers at all.
		 */
		add_filter( 'qevm_occurrence_is_protected', array( __CLASS__, 'protect_booked_occurrence' ), 10, 2 );

		/*
		 * A split moves dates to a new event. The bookings for those dates name
		 * the old one until this puts them right, and nothing anywhere reports
		 * the disagreement — both tables stay internally consistent while the
		 * attendee screen quietly stops listing people who are still coming.
		 */
		add_action( 'qevm_series_split', array( __CLASS__, 'follow_series_split' ), 10, 3 );

		if ( is_admin() ) {
			( new AttendeesScreen() )->register();
			( new \QuickEventsManager\Email\BroadcastForm() )->register();
			( new Exporter() )->register();
			( new EventMetaBox() )->register();
		}
	}

	/**
	 * Keep a date that has bookings on it.
	 *
	 * Answers `qevm_occurrence_is_protected`. Deleting a booked date destroys the
	 * only link between a booking and what it was for, and the organiser finds out
	 * when twelve people arrive; the reconciler cancels it instead, which keeps the
	 * record and the attendee list and leaves telling them to the person who made
	 * the change.
	 *
	 * An earlier answer of `true` is respected rather than overwritten, so a site
	 * or another module can protect a date this one has no opinion about.
	 *
	 * Both parameters are typed as loosely as a filter deserves. By the time this
	 * runs, anything may have filtered before it — declaring the types the domain
	 * passes would let static analysis call the checks below redundant and invite
	 * somebody to delete them, at which point one badly behaved filter turns a
	 * rule change into a fatal error mid-save.
	 *
	 * @since 26.0
	 *
	 * @param mixed $keep       Whether it must survive already.
	 * @param mixed $occurrence The date about to be removed.
	 * @return bool
	 */
	public static function protect_booked_occurrence( $keep, $occurrence ) {
		if ( $keep ) {
			return true;
		}

		if ( ! $occurrence instanceof \QuickEventsManager\Events\Occurrence ) {
			return false;
		}

		return Repository::count_for_occurrence( $occurrence->id() ) > 0;
	}

	/**
	 * Keep bookings with the dates they were made for after a split.
	 *
	 * Answers `qevm_series_split`. The occurrence rows changed hands and kept
	 * their ids, so `occurrence_id` is still right on every booking; only the
	 * `event_id` beside it is stale.
	 *
	 * The attendees table needs nothing. It reaches its event through its
	 * registration and stores no event id of its own, which is the whole
	 * argument for not denormalising twice.
	 *
	 * @since 26.0
	 *
	 * @param mixed $event_id  The event the dates moved to.
	 * @param mixed $from_id   The event they came from. Unused; part of the signature.
	 * @param mixed $moved     Occurrence ids that moved.
	 * @return void
	 */
	public static function follow_series_split( $event_id, $from_id, $moved ) {
		unset( $from_id );

		if ( ! is_array( $moved ) ) {
			return;
		}

		Repository::repoint_to_event( $moved, (int) $event_id );
	}

	/**
	 * Create the registrations table.
	 *
	 * Runs when the module is switched on rather than at plugin activation,
	 * so a site that never takes registrations never grows the table. Safe to
	 * run repeatedly — dbDelta() compares against the existing schema.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Installer::run_schema( self::schema() );
		Installer::run_schema( self::attendees_schema() );

		/*
		 * The email queue is created here because registration is the only
		 * thing that sends mail today. It is shared infrastructure rather than
		 * this module's property — anything else that starts sending must make
		 * the same call, and Queue guards every method on the table existing so
		 * that a sender arriving before the table does fails quietly rather
		 * than fatally.
		 */
		Installer::run_schema( \QuickEventsManager\Email\Queue::schema() );
	}

	/**
	 * Switching off leaves every row untouched.
	 *
	 * A site owner turning registration off to simplify their admin expects to
	 * find their attendees still there when they turn it back on. Data is only
	 * removed by uninstall.php, and only if they ask for it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {
		/*
		 * The retention sweep comes off the schedule with the module. Leaving a
		 * cron event that deletes attendee data behind after somebody switched
		 * registration off would be the plugin still destroying records for a
		 * feature the site is no longer using.
		 *
		 * This removes nothing. Every row stays where it is.
		 */
		\QuickEventsManager\Privacy\Retention::unschedule();
		\QuickEventsManager\Email\Worker::unschedule();
	}

	/**
	 * The registrations table definition.
	 *
	 * Registrations live in their own table rather than as posts because the
	 * question asked most often is "how many confirmed registrations does this
	 * event have", which here is one indexed COUNT. Modelled as a custom post
	 * type it would be a meta_query join, and a 500-person event would add
	 * thousands of rows to wp_postmeta that every unrelated query then walks
	 * past.
	 *
	 * VARCHAR(190) on the indexed text columns keeps them inside the 767-byte
	 * index limit that MySQL below 5.7 enforces on utf8mb4.
	 *
	 * @since 26.0
	 *
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	public static function schema() {
		$table   = Installer::table( 'registrations' );
		$collate = Installer::charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			code varchar(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			quantity smallint(5) unsigned NOT NULL DEFAULT 1,
			booker_name varchar(190) NOT NULL,
			booker_email varchar(190) NOT NULL,
			booker_phone varchar(50) DEFAULT NULL,
			consent_version varchar(64) NOT NULL DEFAULT '',
			consent_at datetime DEFAULT NULL,
			fields longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			cancelled_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY occ_status (occurrence_id, status),
			KEY event_status (event_id, status),
			KEY event_email (event_id, booker_email),
			KEY user_id (user_id),
			KEY order_id (order_id)
		) {$collate};";
	}

	/**
	 * The attendees table definition.
	 *
	 * One row per place, always, including when only one place was booked. A
	 * registration is the booking; an attendee is a person. Storing a quantity
	 * and one name breaks on the first person who books for someone else, which
	 * in practice is the first week of real use — and check-in, QR codes,
	 * per-person ticket types and per-person custom fields all need a person
	 * rather than a booking. See
	 * docs/adr/0004-registration-attendee-split.md.
	 *
	 * `ticket_code` is unique because it is what a QR code resolves to, and one
	 * code admits one person. `occurrence_id` and `ticket_type_id` are reserved
	 * now and used in stages 6 and 7; adding them later would mean altering a
	 * table that by then holds a row for every place ever booked.
	 *
	 * `name` may be empty. Somebody booking three places for their team may not
	 * know who is coming yet, and that is a known state rather than an error.
	 *
	 * @since 26.0
	 *
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	public static function attendees_schema() {
		$table   = Installer::table( 'attendees' );
		$collate = Installer::charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL,
			occurrence_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_code varchar(32) NOT NULL,
			position smallint(5) unsigned NOT NULL DEFAULT 1,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_code (ticket_code),
			KEY registration (registration_id),
			KEY occ_status (occurrence_id, status),
			KEY ticket_type (ticket_type_id),
			KEY email (email)
		) {$collate};";
	}
}
