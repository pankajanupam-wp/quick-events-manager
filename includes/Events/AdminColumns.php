<?php
/**
 * The event list table in the admin.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a start-date column and makes the list sort by it.
 *
 * The default post list sorts by publish date, which for events is close to
 * meaningless — what an organiser wants is "what is coming up next".
 *
 * @since 26.0
 */
final class AdminColumns {

	/**
	 * Hook into the list table.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'manage_' . QEVM_POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . QEVM_POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . QEVM_POST_TYPE . '_sortable_columns', array( $this, 'sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'sort' ) );
	}

	/**
	 * Insert the event columns before the date column.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function columns( $columns ) {
		$reordered = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$reordered['qevm_start']    = __( 'Starts', 'quick-events-manager' );
				$reordered['qevm_location'] = __( 'Location', 'quick-events-manager' );
			}

			$reordered[ $key ] = $label;
		}

		return $reordered;
	}

	/**
	 * Render one of our columns.
	 *
	 * @since 26.0
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Event id.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		$event = new Event( $post_id );

		if ( 'qevm_start' === $column ) {
			$start = $event->format_start();

			if ( '' === $start ) {
				echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">'
					. esc_html__( 'No date set', 'quick-events-manager' ) . '</span>';

				return;
			}

			echo esc_html( $start );

			$label = $event->timezone_label();

			if ( '' !== $label ) {
				echo ' <span class="qevm-tz">' . esc_html( $label ) . '</span>';
			}

			if ( $event->is_happening_now() ) {
				echo '<br /><strong class="qevm-now">' . esc_html__( 'Happening now', 'quick-events-manager' ) . '</strong>';
			} elseif ( $event->has_ended() ) {
				echo '<br /><span class="qevm-past">' . esc_html__( 'Finished', 'quick-events-manager' ) . '</span>';
			}

			return;
		}

		if ( 'qevm_location' === $column ) {
			if ( $event->is_online() ) {
				echo esc_html__( 'Online', 'quick-events-manager' );

				return;
			}

			$venue = $event->venue_summary();

			echo '' !== $venue
				? esc_html( $venue )
				: '<span aria-hidden="true">&mdash;</span>';
		}
	}

	/**
	 * Declare the start column sortable.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function sortable( $columns ) {
		$columns['qevm_start'] = 'qevm_start';

		return $columns;
	}

	/**
	 * Apply the sort when the start column is clicked.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Query $query Query being prepared.
	 * @return void
	 */
	public function sort( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'qevm_start' !== $query->get( 'orderby' ) ) {
			return;
		}

		/*
		 * A LEFT join, so events with no date set still appear in the list
		 * instead of vanishing the moment someone sorts by date. An event
		 * nobody can reach because they sorted a column is a lost post.
		 */
		$query->set(
			OccurrenceQuery::QUERY_VAR,
			array(
				'when'     => 'any',
				'order'    => 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC',
				'required' => false,
			)
		);
	}
}
