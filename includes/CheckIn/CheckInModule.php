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
