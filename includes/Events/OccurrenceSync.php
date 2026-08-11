<?php
/**
 * Keeps the occurrences table in step with the post meta it derives from.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

use QuickEventsManager\Domain\OccurrenceStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Regenerates an event's occurrence rows whenever its dates could have changed.
 *
 * Post meta stays the authoring surface and the human-readable record; the
 * occurrence rows are derived from it and can always be thrown away and rebuilt.
 * That is what makes `wp qevm occurrence rebuild` a complete recovery path
 * rather than a partial one.
 *
 * Occurrences are written for events in **every** post status, not just
 * published ones. The table mirrors what exists; deciding what a visitor may see
 * is the query layer's job, and it has the post row to hand for that. Syncing
 * only published events would mean a draft has no occurrence to preview and a
 * scheduled post grows one at an unpredictable moment.
 *
 * @since 26.0
 */
final class OccurrenceSync {

	/**
	 * Hook in.
	 *
	 * Two entry points, because the two editors write meta at different times.
	 *
	 * The classic editor writes it inside `save_post`, so this runs at priority
	 * 20 — after MetaBox::save(), which is registered at 10.
	 *
	 * The block editor writes meta through the REST controller *after*
	 * `save_post` has already fired, so hooking `save_post` alone would read the
	 * previous values and write occurrences one save behind. Core provides
	 * `rest_after_insert_{$post_type}` for exactly this, and it runs after
	 * `$this->meta->update_value()` in WP_REST_Posts_Controller.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'save_post_' . QEVM_POST_TYPE, array( $this, 'on_save' ), 20, 2 );
		add_action( 'rest_after_insert_' . QEVM_POST_TYPE, array( $this, 'on_rest_save' ), 20 );
		add_action( 'deleted_post', array( $this, 'on_delete' ), 10, 2 );
	}

	/**
	 * Sync after a classic-editor save.
	 *
	 * @since 26.0
	 *
	 * @param int      $post_id Event id.
	 * @param \WP_Post $post    Event.
	 * @return void
	 */
	public function on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		/*
		 * An auto-draft is the empty shell WordPress creates the moment
		 * "Add New" is clicked. It has no dates and may never be saved by
		 * anyone, so giving it a row would leave litter behind for every
		 * abandoned draft.
		 */
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		self::sync( (int) $post_id );
	}

	/**
	 * Sync after the block editor has written meta.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Post $post Event.
	 * @return void
	 */
	public function on_rest_save( $post ) {
		self::sync( (int) $post->ID );
	}

	/**
	 * Remove an event's occurrences when the event itself is deleted.
	 *
	 * Trashing is deliberately not handled here. Trash sets `post_status`, and
	 * the row still describes a date that exists; the query layer filters on
	 * status. Deleting the occurrences on trash and rebuilding them on untrash
	 * would mean the restore path had to reconstruct data that never needed to
	 * be lost.
	 *
	 * @since 26.0
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post, as it was before deletion.
	 * @return void
	 */
	public function on_delete( $post_id, $post = null ) {
		if ( $post instanceof \WP_Post && QEVM_POST_TYPE !== $post->post_type ) {
			return;
		}

		OccurrenceRepository::delete_for_event( (int) $post_id );
	}

	/**
	 * Rebuild one event's occurrences from its meta.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return array{inserted: int, updated: int, deleted: int, unchanged: int}
	 */
	public static function sync( $event_id ) {
		$event_id = (int) $event_id;
		$empty    = array(
			'inserted'  => 0,
			'updated'   => 0,
			'deleted'   => 0,
			'unchanged' => 0,
		);

		if ( $event_id <= 0 ) {
			return $empty;
		}

		$occurrences = self::build( $event_id );

		/*
		 * No dates means no occurrences — including removing any that a
		 * previous save left behind. An organiser clearing the start date is
		 * saying the event has no date yet, and it must stop appearing in a
		 * listing ordered by one.
		 */
		$result = OccurrenceRepository::replace_for_event( $event_id, $occurrences );

		/**
		 * Fires after an event's occurrences have been regenerated.
		 *
		 * @since 26.0
		 *
		 * @param int                                                          $event_id Event id.
		 * @param array{inserted: int, updated: int, deleted: int, unchanged: int} $result What changed.
		 */
		do_action( 'qevm_occurrences_synced', $event_id, $result );

		return $result;
	}

	/**
	 * The occurrence rows an event's meta implies.
	 *
	 * One row for a one-off event. Recurrence expands this into many in stage 6,
	 * which is why it is a separate method returning a list rather than a single
	 * row built inline.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( $event_id ) {
		$event = new Event( (int) $event_id );

		if ( ! $event->is_valid() ) {
			return array();
		}

		$start_utc = $event->start_utc();

		if ( '' === $start_utc ) {
			return array();
		}

		$end_utc     = '' !== $event->end_utc() ? $event->end_utc() : $start_utc;
		$start_local = '' !== $event->start_local() ? $event->start_local() : $start_utc;
		$end_local   = '' !== $event->end_local() ? $event->end_local() : $start_local;

		/*
		 * An end before the start is meaningless and would make every range
		 * query answer "already finished". Treat it as an unstated end rather
		 * than storing a row that can never match.
		 */
		if ( $end_utc < $start_utc ) {
			$end_utc   = $start_utc;
			$end_local = $start_local;
		}

		return array(
			array(
				'event_id'     => (int) $event_id,
				'series_uuid'  => '',
				'start_utc'    => $start_utc,
				'end_utc'      => $end_utc,
				'start_local'  => $start_local,
				'end_local'    => $end_local,
				'timezone'     => $event->timezone(),
				'all_day'      => $event->is_all_day() ? 1 : 0,
				'is_exception' => 0,
				'status'       => OccurrenceStatus::Scheduled->value,
			),
		);
	}
}
