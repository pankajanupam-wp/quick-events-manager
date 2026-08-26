<?php
/**
 * Copying an event.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The organiser's most-used action, and most of what "event templates" means.
 *
 * A monthly meetup is the same event with a different date. Without this, the
 * options are retyping the venue, the organiser, the capacity and the
 * description every month, or keeping a permanent draft to copy from by hand.
 *
 * What is copied is everything that describes the event: title, content,
 * excerpt, every meta value, every taxonomy term and the featured image.
 *
 * What is **not** copied is everything that records what happened to it —
 * registrations, attendees, the reference codes people were emailed. Those
 * belong to the original and nothing else. Copying them would put the same
 * booking reference on two events, hand an organiser a new event that already
 * claims to be full, and email nobody about any of it.
 *
 * The copy is always a **draft**, even when the original is published. A
 * duplicate arrives with the original's date, so publishing it immediately
 * would put a second identical event on the archive at the same time as the
 * first — which is never what anybody wanted, and is public before they notice.
 *
 * @since 26.0
 */
final class Duplicator {

	/**
	 * Action name for admin-post.php.
	 */
	const ACTION = 'qevm_duplicate_event';

	/**
	 * Nonce action prefix; the event id is appended.
	 */
	const NONCE = 'qevm_duplicate_event';

	/**
	 * Meta keys that describe the editing session rather than the event.
	 *
	 * Copying `_edit_lock` hands the new post a stale lock and tells the next
	 * person to open it that somebody else is already editing.
	 */
	const SKIPPED_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
	);

	/**
	 * Hook the row action and its handler.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Add "Duplicate" to an event's row actions.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post              $post    Post the row is for.
	 * @return array<string, string>
	 */
	public function row_action( $actions, $post ) {
		if ( QEVM_POST_TYPE !== $post->post_type || ! current_user_can( 'edit_qevm_events' ) ) {
			return $actions;
		}

		$actions['qevm_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url( $post->ID ) ),
			esc_html__( 'Duplicate', 'quick-events-manager' )
		);

		return $actions;
	}

	/**
	 * The nonced URL that duplicates an event.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event to copy.
	 * @return string
	 */
	public static function url( $event_id ) {
		$event_id = (int) $event_id;

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'event'  => $event_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE . '_' . $event_id
		);
	}

	/**
	 * Make the copy and open it in the editor.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function handle() {
		$event_id = isset( $_GET['event'] ) ? absint( wp_unslash( $_GET['event'] ) ) : 0;

		check_admin_referer( self::NONCE . '_' . $event_id );

		if ( ! current_user_can( 'edit_qevm_events' ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate events.', 'quick-events-manager' ) );
		}

		$copy_id = self::duplicate( $event_id );

		if ( is_wp_error( $copy_id ) ) {
			wp_die( esc_html( $copy_id->get_error_message() ) );
		}

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $copy_id ) );

		exit;
	}

	/**
	 * Copy an event, returning the new post id.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event to copy.
	 * @return int|\WP_Error
	 */
	public static function duplicate( $event_id ) {
		$original = get_post( (int) $event_id );

		if ( ! $original instanceof \WP_Post || QEVM_POST_TYPE !== $original->post_type ) {
			return new \WP_Error(
				'qevm_duplicate_missing',
				__( 'That event could not be found.', 'quick-events-manager' )
			);
		}

		/*
		 * wp_slash() on the way in, and it is not optional.
		 *
		 * wp_insert_post() expects slashed data and calls wp_unslash() on it,
		 * because it was built for values arriving straight from $_POST. What
		 * comes out of get_post() has already been unslashed. Handing that
		 * back means a title reading O'Brien loses its backslash on the first
		 * copy and the apostrophe on the second — data that erodes a little
		 * more every time somebody duplicates a duplicate.
		 */
		$source = $original->to_array();
		$author = get_current_user_id();

		$copy_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => QEVM_POST_TYPE,
					'post_status'    => 'draft',
					/* translators: %s: Title of the event being copied. */
					'post_title'     => sprintf( __( '%s (copy)', 'quick-events-manager' ), (string) $source['post_title'] ),
					'post_content'   => (string) $source['post_content'],
					'post_excerpt'   => (string) $source['post_excerpt'],
					'post_author'    => $author > 0 ? $author : (int) $source['post_author'],
					'comment_status' => (string) $source['comment_status'],
					'ping_status'    => (string) $source['ping_status'],
					'menu_order'     => (int) $source['menu_order'],
					'post_parent'    => (int) $source['post_parent'],
				)
			),
			true
		);

		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		$copy_id = (int) $copy_id;

		self::copy_taxonomies( $original->ID, $copy_id );
		self::copy_meta( $original->ID, $copy_id );

		/*
		 * The occurrence rows are derived, and the meta above was written
		 * after wp_insert_post() fired `save_post` — so the sync that normally
		 * runs on save has already been and gone, and saw an event with no
		 * dates. Ask for it explicitly rather than leaving the copy invisible
		 * to every date query until somebody opens and re-saves it.
		 */
		OccurrenceSync::sync( $copy_id );

		/**
		 * Fires after an event has been copied.
		 *
		 * @since 26.0
		 *
		 * @param int $copy_id     The new event.
		 * @param int $original_id The event it was copied from.
		 */
		do_action( 'qevm_event_duplicated', $copy_id, $original->ID );

		return $copy_id;
	}

	/**
	 * Give the copy the original's terms, in every taxonomy it has.
	 *
	 * @since 26.0
	 *
	 * @param int $from Original post id.
	 * @param int $to   New post id.
	 * @return void
	 */
	private static function copy_taxonomies( $from, $to ) {
		foreach ( get_object_taxonomies( QEVM_POST_TYPE ) as $taxonomy ) {
			$terms = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			wp_set_object_terms( $to, $terms, $taxonomy );
		}
	}

	/**
	 * Give the copy the original's meta.
	 *
	 * Everything except the editing bookkeeping, rather than only the keys
	 * this plugin owns. An event that carries custom fields from somewhere
	 * else is still that event, and a "Duplicate" that silently drops half the
	 * fields is worse than no Duplicate — the loss is only noticed later, by
	 * which time the original may have moved on.
	 *
	 * @since 26.0
	 *
	 * @param int $from Original post id.
	 * @param int $to   New post id.
	 * @return void
	 */
	private static function copy_meta( $from, $to ) {
		/**
		 * Filters the meta keys a duplicate does not inherit.
		 *
		 * @since 26.0
		 *
		 * @param string[] $skipped Meta keys to leave behind.
		 * @param int      $from    Original post id.
		 */
		$skipped = (array) apply_filters( 'qevm_duplicate_skipped_meta', self::SKIPPED_META, (int) $from );

		$meta = get_post_meta( (int) $from );

		if ( ! is_array( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $skipped, true ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				/*
				 * get_post_meta() without a key returns every value already
				 * serialised. Passing that straight back would store a string
				 * that looks like an array to anything reading it later, so it
				 * goes through maybe_unserialize() first — and add_post_meta()
				 * re-serialises whatever comes out.
				 */
				add_post_meta( (int) $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}
}
