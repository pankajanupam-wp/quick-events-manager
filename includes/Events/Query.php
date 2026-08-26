<?php
/**
 * Event querying: chronological ordering, upcoming and past.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Makes WordPress order events by when they happen, not when they were posted.
 *
 * @since 26.0
 */
final class Query {

	/**
	 * Hook into the main query.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'pre_get_posts', array( $this, 'order_archive_by_start_date' ) );
	}

	/**
	 * Order the event archive by start date.
	 *
	 * The default for any post type is `post_date DESC`, which for events
	 * means "most recently created first" — almost never what anyone wants.
	 * Upcoming events read soonest-first.
	 *
	 * Only the main query on a front-end event archive is touched. Admin
	 * screens keep their own sorting, and a secondary WP_Query is the caller's
	 * business.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Query $query Query being prepared.
	 * @return void
	 */
	public function order_archive_by_start_date( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$is_event_archive = $query->is_post_type_archive( QEVM_POST_TYPE )
			|| $query->is_tax( QEVM_TAX_CATEGORY )
			|| $query->is_tax( QEVM_TAX_TAG );

		if ( ! $is_event_archive ) {
			return;
		}

		/*
		 * The archive lists what is still to come, soonest first. Undated
		 * events are excluded rather than sorted to one end: an event with no
		 * date cannot be "upcoming", and showing it in a chronological list
		 * with nothing to place it against helps nobody.
		 */
		$query->set(
			OccurrenceQuery::QUERY_VAR,
			array(
				'when'  => 'upcoming',
				'order' => 'ASC',
			)
		);
	}

	/**
	 * Query arguments for upcoming events.
	 *
	 * Delegates to OccurrenceQuery. Kept as a name callers already use, and as
	 * the single place the default page size lives.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $args Additional WP_Query arguments to merge in.
	 * @return array<string, mixed>
	 */
	public static function upcoming_args( array $args = array() ) {
		return OccurrenceQuery::upcoming_args( array_merge( array( 'posts_per_page' => 10 ), $args ) );
	}

	/**
	 * Query arguments for events that have finished.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $args Additional WP_Query arguments to merge in.
	 * @return array<string, mixed>
	 */
	public static function past_args( array $args = array() ) {
		return OccurrenceQuery::past_args( array_merge( array( 'posts_per_page' => 10 ), $args ) );
	}
}
