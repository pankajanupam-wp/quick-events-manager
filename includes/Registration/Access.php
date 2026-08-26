<?php
/**
 * Who may see whose attendees.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * One answer to "may this person manage the bookings for that event".
 *
 * **`manage_qevm_registrations` is site-wide, and that is not enough on its own.**
 * It says somebody manages guest lists; it does not say whose. The Event
 * Organizer role is documented — in its own docblock, in the user guide and on
 * the Features screen — as covering *their own* events, and until the stage 10
 * security pass the code did not say so anywhere: any organiser could open any
 * other organiser's attendee list, read every name and email address on it,
 * export it, and change people's statuses.
 *
 * Found by asking a question a linter cannot: not "is this checked" but "is the
 * right thing checked".
 *
 * The answer is both capabilities together. `edit_post` on an event resolves
 * through the post type's own capability map, so somebody with
 * `edit_others_qevm_events` — an administrator, an Event Manager — keeps seeing
 * everything, and an organiser sees the events they own. No new capability, no
 * new list to keep in step.
 *
 * The door is deliberately *not* behind this. Event Staff hold
 * `manage_qevm_checkins` and no editing capability at all, and a volunteer
 * handed a phone for the evening has to be able to work whichever door they
 * were given.
 *
 * @since 26.0
 */
final class Access {

	/**
	 * Whether the current user may manage the bookings for one event.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return bool
	 */
	public static function may_manage( $event_id ): bool {
		$event_id = (int) $event_id;

		if ( ! current_user_can( 'manage_qevm_registrations' ) ) {
			return false;
		}

		/*
		 * No event yet — the screen before one is chosen. Holding the
		 * capability is enough to get that far; what it lists is filtered
		 * below.
		 */
		if ( $event_id <= 0 ) {
			return true;
		}

		return current_user_can( 'edit_post', $event_id );
	}

	/**
	 * Stop, if they may not.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return void
	 */
	public static function require_manage( $event_id ) {
		if ( self::may_manage( $event_id ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to manage attendees for this event.', 'quick-events-manager' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * The events this user may choose between.
	 *
	 * A list built from what they may edit rather than filtered afterwards:
	 * an event that cannot be opened should not be offered, and a picker that
	 * lists other people's events tells an organiser what else is running even
	 * if it refuses to open them.
	 *
	 * @since 26.0
	 *
	 * @param array<int, \WP_Post> $events Events to choose from.
	 * @return array<int, \WP_Post>
	 */
	public static function only_theirs( array $events ): array {
		if ( current_user_can( 'edit_others_qevm_events' ) ) {
			return $events;
		}

		return array_values(
			array_filter(
				$events,
				static fn( $event ) => current_user_can( 'edit_post', $event->ID )
			)
		);
	}
}
