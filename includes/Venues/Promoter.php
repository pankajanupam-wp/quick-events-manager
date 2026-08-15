<?php
/**
 * Turning addresses already on events into venue records.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Venues;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * The one-off sweep that gives an existing site its venues.
 *
 * Somebody switching this module on has a backlog: forty monthly meetups, each
 * carrying its own copy of the same address, typed forty times. Promotion is
 * what makes the feature worth switching on for them rather than something that
 * only helps with events they have not created yet.
 *
 * Three things about it are deliberate and easy to get wrong.
 *
 * **It is not a schema migration.** `Install\Migrations\Runner` is version-driven
 * and runs on every site on upgrade. Promotion belongs to a module a site may
 * never enable, and running it there would create venue posts on sites where the
 * post type is not registered at all — rows reachable from nowhere but the
 * database.
 *
 * **It deduplicates, or it is pointless.** Forty events becoming forty records
 * has reorganised nothing. Events are matched on a fingerprint of the whole
 * normalised address, not on the name: two "Town Hall"s in different towns are
 * different buildings, and a merge is not something the site owner can undo.
 *
 * **It creates, and never destroys.** The address stays on the event afterwards.
 * That is what lets the module be switched off again — see
 * [ADR-0014](../../docs/adr/0014-venue-records-with-flat-fallback.md).
 *
 * @since 26.0
 */
final class Promoter {

	/**
	 * Option holding the sweep's progress.
	 */
	const STATE_OPTION = 'qevm_venue_promotion';

	/**
	 * Meta key holding a venue's address fingerprint.
	 */
	const FINGERPRINT_META = '_qevm_venue_fingerprint';

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
	 * Run the sweep from a hook, discarding the count.
	 *
	 * `run()` returns how much it did, which is what a test or a WP-CLI command
	 * wants and what an action callback must not do.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function run_on_hook() {
		self::run();
	}

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
	public static function schedule() {
		if ( false !== get_option( self::STATE_OPTION, false ) ) {
			return;
		}

		update_option(
			self::STATE_OPTION,
			array(
				'status' => 'pending',
				'cursor' => 0,
				'venues' => 0,
				'events' => 0,
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
	public static function run() {
		$state = self::state();

		if ( 'pending' !== $state['status'] ) {
			return 0;
		}

		$deadline  = microtime( true ) + self::TIME_BUDGET;
		$promoted  = 0;
		$exhausted = false;

		while ( microtime( true ) < $deadline ) {
			$events = self::candidates( $state['cursor'], self::BATCH_SIZE );

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
			 * Fires once the venue promotion sweep has finished.
			 *
			 * @since 26.0
			 *
			 * @param int $events Events given a venue record.
			 * @param int $venues Venue records created.
			 */
			do_action( 'qevm_venue_promotion_finished', (int) $state['events'], (int) $state['venues'] );
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
	 * @return bool Whether a venue was assigned.
	 */
	private static function promote( $event_id, array &$state ) {
		if ( (int) get_post_meta( $event_id, Meta::VENUE_ID, true ) > 0 ) {
			return false;
		}

		if ( '' !== (string) get_post_meta( $event_id, Meta::IS_ONLINE, true ) ) {
			return false;
		}

		$parts = array();

		foreach ( Venue::address_keys() as $key ) {
			$parts[ $key ] = (string) get_post_meta( $event_id, $key, true );
		}

		/*
		 * No name, no record. The venue's title is its name, and there is
		 * nothing to call a record built from a street and a postcode — a venue
		 * listed as "12 Bank Street" is worse to pick from a dropdown than the
		 * flat address it replaced. Those events keep their address exactly as
		 * it is, which costs them nothing.
		 */
		if ( '' === trim( $parts[ Meta::VENUE_NAME ] ) ) {
			return false;
		}

		/**
		 * Filters whether an event's address is promoted to a venue record.
		 *
		 * @since 26.0
		 *
		 * @param bool                  $promote  Whether to promote.
		 * @param int                   $event_id Event id.
		 * @param array<string, string> $parts    Address parts, keyed by meta key.
		 */
		if ( ! apply_filters( 'qevm_promote_event_venue', true, $event_id, $parts ) ) {
			return false;
		}

		$fingerprint = self::fingerprint( $parts );
		$venue_id    = self::find( $fingerprint );

		if ( 0 === $venue_id ) {
			$venue_id = self::create( $parts, $fingerprint );

			if ( 0 === $venue_id ) {
				return false;
			}

			++$state['venues'];
		}

		update_post_meta( $event_id, Meta::VENUE_ID, $venue_id );

		return true;
	}

	/**
	 * Events that might need promoting, after a cursor, oldest id first.
	 *
	 * Read straight from the meta table rather than through `WP_Query`. The
	 * alternative is a `meta_query` for "has a venue name and has no venue id",
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off admin sweep over a cursor; caching a page of ids that is consumed once would be waste.
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
				Meta::VENUE_NAME,
				QEVM_POST_TYPE,
				(int) $after,
				(int) $limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A venue with this exact address, if one already exists.
	 *
	 * @since 26.0
	 *
	 * @param string $fingerprint Address fingerprint.
	 * @return int Venue id, or 0.
	 */
	private static function find( $fingerprint ) {
		$existing = get_posts(
			array(
				'post_type'              => QEVM_POST_TYPE_VENUE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:disable WordPress.DB.SlowDBQuery -- An exact match on meta_key and a 32-character hash, run once per distinct address during a one-off sweep. The alternative is holding every fingerprint in memory for the length of the sweep.
				'meta_key'               => self::FINGERPRINT_META,
				'meta_value'             => $fingerprint,
				// phpcs:enable WordPress.DB.SlowDBQuery
			)
		);

		return array() === $existing ? 0 : (int) $existing[0];
	}

	/**
	 * Create a venue record from an address.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $parts       Address parts, keyed by meta key.
	 * @param string                $fingerprint Address fingerprint.
	 * @return int Venue id, or 0 if it could not be created.
	 */
	private static function create( array $parts, $fingerprint ) {
		$meta = array( self::FINGERPRINT_META => $fingerprint );

		foreach ( VenueMeta::keys() as $key ) {
			$meta[ $key ] = isset( $parts[ $key ] ) ? $parts[ $key ] : '';
		}

		$venue_id = wp_insert_post(
			array(
				'post_type'   => QEVM_POST_TYPE_VENUE,
				'post_title'  => wp_slash( trim( $parts[ Meta::VENUE_NAME ] ) ),
				'post_status' => 'publish',
				'meta_input'  => wp_slash( $meta ),
			),
			true
		);

		if ( is_wp_error( $venue_id ) ) {
			return 0;
		}

		/**
		 * Fires after an address on an event has become a venue record.
		 *
		 * @since 26.0
		 *
		 * @param int                   $venue_id The new venue.
		 * @param array<string, string> $parts    Address it was built from.
		 */
		do_action( 'qevm_venue_created_from_event', (int) $venue_id, $parts );

		return (int) $venue_id;
	}

	/**
	 * A stable key for an address, ignoring how it was typed.
	 *
	 * Case and spacing are normalised because the same hall entered on twelve
	 * events over two years is entered twelve slightly different ways. Nothing
	 * beyond that: no abbreviation expansion, no "St" to "Street", no fuzzy
	 * matching. A near-match that guesses wrong merges two real places into one
	 * record, and the site owner has no way to tell it happened or to take it
	 * back. Two records that should have been one is a tidying job; one record
	 * that should have been two is lost data.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $parts Address parts, keyed by meta key.
	 * @return string
	 */
	public static function fingerprint( array $parts ) {
		$normalised = array();

		foreach ( Venue::address_keys() as $key ) {
			$value = isset( $parts[ $key ] ) ? (string) $parts[ $key ] : '';

			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
			$value = preg_replace( '/\s+/u', ' ', $value );

			$normalised[] = trim( (string) $value );
		}

		return md5( implode( "\n", $normalised ) );
	}

	/**
	 * Tell the site owner what the sweep did, once.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function notice() {
		$state = self::state();

		if ( 'done' !== $state['status'] || 0 === (int) $state['venues'] ) {
			return;
		}

		if ( ! current_user_can( 'edit_qevm_venues' ) ) {
			return;
		}

		$state['status'] = 'reported';

		self::save( $state );

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: number of venues created, 2: number of events updated. */
					_n(
						'Quick Events Manager created %1$d venue from the addresses already on your events, and linked %2$d event to it.',
						'Quick Events Manager created %1$d venues from the addresses already on your events, and linked %2$d events to them.',
						(int) $state['venues'],
						'quick-events-manager'
					),
					(int) $state['venues'],
					(int) $state['events']
				)
			)
		);
	}

	/**
	 * The sweep's state, with every key present.
	 *
	 * @since 26.0
	 *
	 * @return array{status: string, cursor: int, venues: int, events: int}
	 */
	public static function state() {
		$stored = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'status' => isset( $stored['status'] ) ? (string) $stored['status'] : 'none',
			'cursor' => isset( $stored['cursor'] ) ? (int) $stored['cursor'] : 0,
			'venues' => isset( $stored['venues'] ) ? (int) $stored['venues'] : 0,
			'events' => isset( $stored['events'] ) ? (int) $stored['events'] : 0,
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
		update_option( self::STATE_OPTION, $state, false );
	}
}
