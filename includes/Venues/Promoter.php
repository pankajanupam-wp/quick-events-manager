<?php
/**
 * Turning addresses already on events into venue records.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Records\Sweep;

defined( 'ABSPATH' ) || exit;

/**
 * The one-off sweep that gives an existing site its venues.
 *
 * Somebody switching this module on has a backlog: forty monthly meetups, each
 * carrying its own copy of the same address, typed forty times. Promotion is
 * what makes the feature worth switching on for them rather than something that
 * only helps with events they have not created yet.
 *
 * The batching, the cursor, the once-only guarantee and — most importantly —
 * the matching rule all live in `Records\Sweep`, shared with organisers. What
 * is here is the venue-specific part: an online event has nowhere to be, so it
 * is skipped whatever address happens to be left on it.
 *
 * @since 26.0
 */
final class Promoter extends Sweep {

	/**
	 * Option holding the sweep's progress.
	 */
	const STATE_OPTION = 'qevm_venue_promotion';

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
	 * This sweep promotes to venues.
	 *
	 * @since 26.0
	 *
	 * @return class-string<\QuickEventsManager\Records\EventRecord>
	 */
	public static function record() {
		return Venue::class;
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
	 * Online events are skipped.
	 *
	 * An event held on a video call has nowhere to be, and a venue built from
	 * whatever address was typed before the online box was ticked would be a
	 * place nobody is going.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return bool
	 */
	public static function skip( $event_id ) {
		return '' !== (string) get_post_meta( $event_id, Meta::IS_ONLINE, true );
	}

	/**
	 * What to tell the site owner once it has finished.
	 *
	 * @since 26.0
	 *
	 * @param int $records Venues created.
	 * @param int $events  Events linked to one.
	 * @return string
	 */
	public static function summary( $records, $events ) {
		return sprintf(
			/* translators: 1: number of venues created, 2: number of events updated. */
			_n(
				'Quick Events Manager created %1$d venue from the addresses already on your events, and linked %2$d event to it.',
				'Quick Events Manager created %1$d venues from the addresses already on your events, and linked %2$d events to them.',
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
		return 'edit_qevm_venues';
	}
}
