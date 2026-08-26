<?php
/**
 * The one-off pass that turns fields already on events into records.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Batched, resumable, deduplicating promotion, for any kind of record.
 *
 * Shared rather than written once per record type, because the part that is
 * easy to get wrong is not the batching — it is the matching, and a second copy
 * of the matching rule is a second copy that can drift.
 *
 * **The asymmetry that decides how matching works.** Two records that should
 * have been one is a tidying job the site owner can do in a minute. One record
 * that should have been two is data they cannot get back, merged silently, with
 * nothing on any screen to say it happened. So the fingerprint folds only what
 * is certainly noise — case, and runs of whitespace — and nothing else. No
 * abbreviation expansion, no "St" to "Street", no fuzzy distance. Whole tuple,
 * never one identifying-looking field on its own.
 *
 * @since 26.0
 */
abstract class Sweep {

	/**
	 * Events examined per batch.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Seconds of work to attempt in one request.
	 *
	 * The same reasoning as the migration runner: this runs on `admin_init`, so
	 * the budget is time somebody spends watching a page load, not time PHP is
	 * willing to allow.
	 */
	const TIME_BUDGET = 10;

	/**
	 * Meta key holding a record's fingerprint.
	 */
	const FINGERPRINT_META = '_qevm_record_fingerprint';

	/**
	 * The record class this sweep promotes to.
	 *
	 * @since 26.0
	 *
	 * @return class-string<EventRecord>
	 */
	abstract public static function record();

	/**
	 * The option holding this sweep's progress.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	abstract public static function state_option();

	/**
	 * Whether an event should be skipped for reasons beyond the shared ones.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return bool
	 */
	public static function skip( $event_id ) {
		unset( $event_id );

		return false;
	}

	/**
	 * The notice shown once the sweep has finished, or '' for none.
	 *
	 * @since 26.0
	 *
	 * @param int $records Records created.
	 * @param int $events  Events linked to one.
	 * @return string
	 */
	abstract public static function summary( $records, $events );

	/**
	 * The capability needed to be told what the sweep did.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	abstract public static function notice_capability();

	/**
	 * Queue the sweep, the first time the module is ever switched on.
	 *
	 * Only ever once. `Installer::upgrade_schema()` re-runs `activate()` on
	 * every enabled module whenever the schema version moves, and a site owner
	 * can switch a module off and on again, so this is called far more often
	 * than it should act.
	 *
	 * Once is also right on its own terms. Promotion exists for the backlog
	 * that predates the module. Afterwards the site owner is choosing per event
	 * whether to use a record, and a sweep that kept reaching back to make that
	 * choice for them would be undoing their work.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	final public static function schedule() {
		if ( false !== get_option( static::state_option(), false ) ) {
			return;
		}

		update_option(
			static::state_option(),
			array(
				'status'  => 'pending',
				'cursor'  => 0,
				'records' => 0,
				'events'  => 0,
			),
			false
		);
	}

	/**
	 * Work through a batch of events, if there is a sweep outstanding.
	 *
	 * @since 26.0
	 *
	 * @return int Events promoted in this pass.
	 */
	final public static function run() {
		$state = static::state();

		if ( 'pending' !== $state['status'] ) {
			return 0;
		}

		$deadline  = microtime( true ) + static::TIME_BUDGET;
		$promoted  = 0;
		$exhausted = false;

		while ( microtime( true ) < $deadline ) {
			$events = self::candidates( $state['cursor'], static::BATCH_SIZE );

			if ( array() === $events ) {
				$exhausted = true;
				break;
			}

			foreach ( $events as $event_id ) {
				$state['cursor'] = $event_id;

				if ( self::promote( $event_id, $state ) ) {
					++$promoted;
					++$state['events'];
				}
			}

			self::save( $state );
		}

		if ( $exhausted ) {
			$state['status'] = 'done';

			self::save( $state );

			/**
			 * Fires once a promotion sweep has been through every event.
			 *
			 * @since 26.0
			 *
			 * @param string $record  Record class that was promoted to.
			 * @param int    $events  Events given a record.
			 * @param int    $records Records created.
			 */
			do_action( 'qevm_record_promotion_finished', static::record(), (int) $state['events'], (int) $state['records'] );
		}

		return $promoted;
	}

	/**
	 * Promote one event, if it qualifies.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string, mixed> $state    Sweep state, updated in place.
	 * @return bool Whether a record was assigned.
	 */
	private static function promote( $event_id, array &$state ) {
		$record = static::record();

		if ( (int) get_post_meta( $event_id, $record::id_key(), true ) > 0 ) {
			return false;
		}

		if ( static::skip( $event_id ) ) {
			return false;
		}

		$parts = array();

		foreach ( $record::keys() as $key ) {
			$parts[ $key ] = (string) get_post_meta( $event_id, $key, true );
		}

		/*
		 * No name, no record. A record's title is its name, and there is
		 * nothing sensible to call one built from the remaining fields — a
		 * venue listed as "12 Bank Street" is worse to pick out of a dropdown
		 * than the flat address it replaced. Those events keep their fields
		 * exactly as they are, which costs them nothing.
		 */
		if ( '' === trim( $parts[ $record::name_key() ] ) ) {
			return false;
		}

		/**
		 * Filters whether an event's fields are promoted to a reusable record.
		 *
		 * @since 26.0
		 *
		 * @param bool                  $promote  Whether to promote.
		 * @param int                   $event_id Event id.
		 * @param array<string, string> $parts    Values, keyed by meta key.
		 * @param string                $record   Record class being promoted to.
		 */
		if ( ! apply_filters( 'qevm_promote_event_record', true, $event_id, $parts, $record ) ) {
			return false;
		}

		$fingerprint = self::fingerprint( $record::keys(), $parts );
		$record_id   = self::find( $fingerprint );

		if ( 0 === $record_id ) {
			$record_id = self::create( $parts, $fingerprint );

			if ( 0 === $record_id ) {
				return false;
			}

			++$state['records'];
		}

		update_post_meta( $event_id, $record::id_key(), $record_id );

		return true;
	}

	/**
	 * Events that might need promoting, after a cursor, oldest id first.
	 *
	 * Read straight from the meta table rather than through `WP_Query`. The
	 * alternative is a `meta_query` for "has a name and has no record id",
	 * which is two joins against `postmeta` and the kind of query this plugin
	 * spent a stage removing. This one is a single indexed lookup, and the
	 * cursor means it never re-reads a row it has already passed.
	 *
	 * @since 26.0
	 *
	 * @param int $after Highest event id already examined.
	 * @param int $limit Rows to return.
	 * @return int[]
	 */
	private static function candidates( $after, $limit ) {
		global $wpdb;

		$record = static::record();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off admin sweep over a cursor; caching a page of ids consumed once would be waste.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_status != 'trash'
				   AND p.ID > %d
				   AND m.meta_value != ''
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$record::name_key(),
				QEVM_POST_TYPE,
				(int) $after,
				(int) $limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A record with this exact fingerprint, if one already exists.
	 *
	 * @since 26.0
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return int Post id, or 0.
	 */
	private static function find( $fingerprint ) {
		$record = static::record();

		$existing = get_posts(
			array(
				'post_type'              => $record::post_type(),
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:disable WordPress.DB.SlowDBQuery -- An exact match on meta_key and a 32-character hash, run once per distinct value during a one-off sweep. The alternative is holding every fingerprint in memory for the length of it.
				'meta_key'               => self::FINGERPRINT_META,
				'meta_value'             => $fingerprint,
				// phpcs:enable WordPress.DB.SlowDBQuery
			)
		);

		return array() === $existing ? 0 : (int) $existing[0];
	}

	/**
	 * Create a record from a set of values.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $parts       Values, keyed by meta key.
	 * @param string                $fingerprint Fingerprint.
	 * @return int Post id, or 0 if it could not be created.
	 */
	private static function create( array $parts, $fingerprint ) {
		$record = static::record();
		$meta   = array( self::FINGERPRINT_META => $fingerprint );

		foreach ( $record::keys() as $key ) {
			if ( $record::name_key() === $key ) {
				continue;
			}

			$meta[ $key ] = isset( $parts[ $key ] ) ? $parts[ $key ] : '';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $record::post_type(),
				'post_title'  => wp_slash( trim( $parts[ $record::name_key() ] ) ),
				'post_status' => 'publish',
				'meta_input'  => wp_slash( $meta ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		/**
		 * Fires after fields on an event have become a reusable record.
		 *
		 * @since 26.0
		 *
		 * @param int                   $post_id The new record.
		 * @param array<string, string> $parts   Values it was built from.
		 * @param string                $record  Record class.
		 */
		do_action( 'qevm_record_created_from_event', (int) $post_id, $parts, $record );

		return (int) $post_id;
	}

	/**
	 * A stable key for a set of values, ignoring how they were typed.
	 *
	 * Case and spacing are normalised because the same hall or the same person
	 * entered on twelve events over two years is entered twelve slightly
	 * different ways. Nothing beyond that — see the class docblock for why the
	 * line is drawn exactly there.
	 *
	 * @since 26.0
	 *
	 * @param string[]              $keys  Keys to include, in order.
	 * @param array<string, string> $parts Values, keyed by meta key.
	 * @return string
	 */
	public static function fingerprint( array $keys, array $parts ) {
		$normalised = array();

		foreach ( $keys as $key ) {
			$value = isset( $parts[ $key ] ) ? (string) $parts[ $key ] : '';

			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
			$value = preg_replace( '/\s+/u', ' ', $value );

			$normalised[] = trim( (string) $value );
		}

		return md5( implode( "\n", $normalised ) );
	}

	/**
	 * Run the sweep from a hook, discarding the count.
	 *
	 * `run()` returns how much it did, which is what a test or a WP-CLI command
	 * wants and what an action callback must not do.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	final public static function run_on_hook() {
		static::run();
	}

	/**
	 * Tell the site owner what the sweep did, once.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	final public static function notice() {
		$state = static::state();

		if ( 'done' !== $state['status'] || 0 === (int) $state['records'] ) {
			return;
		}

		if ( ! current_user_can( static::notice_capability() ) ) {
			return;
		}

		$message = static::summary( (int) $state['records'], (int) $state['events'] );

		if ( '' === $message ) {
			return;
		}

		$state['status'] = 'reported';

		self::save( $state );

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * The sweep's state, with every key present.
	 *
	 * @since 26.0
	 *
	 * @return array{status: string, cursor: int, records: int, events: int}
	 */
	final public static function state() {
		$stored = get_option( static::state_option(), array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'status'  => isset( $stored['status'] ) ? (string) $stored['status'] : 'none',
			'cursor'  => isset( $stored['cursor'] ) ? (int) $stored['cursor'] : 0,
			'records' => isset( $stored['records'] ) ? (int) $stored['records'] : 0,
			'events'  => isset( $stored['events'] ) ? (int) $stored['events'] : 0,
		);
	}

	/**
	 * Write the sweep's state back.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function save( array $state ) {
		update_option( static::state_option(), $state, false );
	}
}
