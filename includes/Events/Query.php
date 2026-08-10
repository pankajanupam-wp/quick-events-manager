<?php
/**
 * Event querying: chronological ordering, upcoming and past.
 *
 * @package QuickEventsManager
 */

namespace QEM\Events;

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

		$is_event_archive = $query->is_post_type_archive( QEM_POST_TYPE )
			|| $query->is_tax( QEM_TAX_CATEGORY )
			|| $query->is_tax( QEM_TAX_TAG );

		if ( ! $is_event_archive ) {
			return;
		}

		$query->set( 'meta_key', Meta::START_UTC );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' );

		/*
		 * `meta_value` compares as a string, which is exactly right here:
		 * `Y-m-d H:i:s` is zero-padded and big-endian, so lexical order is
		 * chronological order. Using meta_value_num would silently truncate
		 * at the first non-digit and sort everything by year alone.
		 */
	}

	/**
	 * Query arguments for upcoming events.
	 *
	 * An event counts as upcoming until it *ends*, so a three-day conference
	 * on its second day is still listed rather than disappearing the moment it
	 * starts. Events with no end date fall back to their start.
	 *
	 * @since 26.0
	 *
	 * @param array $args Additional WP_Query arguments to merge in.
	 * @return array
	 */
	public static function upcoming_args( array $args = array() ) {
		$now = Meta::now_utc();

		return array_merge(
			array(
				'post_type'      => QEM_POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => Meta::START_UTC,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'posts_per_page' => 10,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => Meta::END_UTC,
						'value'   => $now,
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => Meta::END_UTC,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => Meta::START_UTC,
							'value'   => $now,
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
				),
			),
			$args
		);
	}

	/**
	 * Query arguments for events that have finished.
	 *
	 * Ordered newest-first, which is the useful direction for an archive of
	 * things that already happened.
	 *
	 * @since 26.0
	 *
	 * @param array $args Additional WP_Query arguments to merge in.
	 * @return array
	 */
	public static function past_args( array $args = array() ) {
		$now = Meta::now_utc();

		return array_merge(
			array(
				'post_type'      => QEM_POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => Meta::START_UTC,
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'posts_per_page' => 10,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => Meta::END_UTC,
						'value'   => $now,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => Meta::END_UTC,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => Meta::START_UTC,
							'value'   => $now,
							'compare' => '<',
							'type'    => 'DATETIME',
						),
					),
				),
			),
			$args
		);
	}
}
