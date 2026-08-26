<?php
/**
 * Turning organiser details already on events into records.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Organizers;

use QuickEventsManager\Records\Sweep;

defined( 'ABSPATH' ) || exit;

/**
 * The one-off sweep that gives an existing site its organisers.
 *
 * Everything that decides *what* gets merged is in `Records\Sweep`, shared with
 * venues — including the rule that matching folds case and spacing and nothing
 * else. That rule is worth restating here for the case it prevents, because the
 * tempting shortcut is more obviously tempting for people than for places:
 * matching on the email address alone.
 *
 * It looks right. An address identifies a person, and "Priya Raman" and "Priya"
 * with the same address are plainly the same organiser. But shared mailboxes are
 * ordinary — `info@`, `events@`, a committee address three different people
 * send from — and matching on the address alone silently merges those three into
 * one record. Editing one then changes the contact details of the other two, on
 * events they own, with nothing to say it happened. So the whole tuple decides,
 * the same as everywhere else, and two records that should have been one stays
 * the failure this leans towards.
 *
 * @since 26.0
 */
final class Promoter extends Sweep {

	/**
	 * Option holding the sweep's progress.
	 */
	const STATE_OPTION = 'qevm_organizer_promotion';

	/**
	 * Hook the sweep.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( __CLASS__, 'run_on_hook' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * This sweep promotes to organisers.
	 *
	 * @since 26.0
	 *
	 * @return class-string<\QuickEventsManager\Records\EventRecord>
	 */
	public static function record() {
		return Organizer::class;
	}

	/**
	 * Where progress is stored.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function state_option() {
		return self::STATE_OPTION;
	}

	/**
	 * What to tell the site owner once it has finished.
	 *
	 * @since 26.0
	 *
	 * @param int $records Organisers created.
	 * @param int $events  Events linked to one.
	 * @return string
	 */
	public static function summary( $records, $events ) {
		return sprintf(
			/* translators: 1: number of organisers created, 2: number of events updated. */
			_n(
				'Quick Events Manager created %1$d organiser from the details already on your events, and linked %2$d event to it.',
				'Quick Events Manager created %1$d organisers from the details already on your events, and linked %2$d events to them.',
				(int) $records,
				'quick-events-manager'
			),
			(int) $records,
			(int) $events
		);
	}

	/**
	 * Who is told.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function notice_capability() {
		return 'edit_qevm_organizers';
	}
}
