<?php
/**
 * The performance benchmark, at the sizes stage 10 asks for.
 *
 * Ten thousand events and ten thousand registrations on one event. Both numbers
 * are far past what this plugin's audience has — a village hall does not run ten
 * thousand events — and that is the point: the question is not "is it fast
 * enough for a meetup", it is "does anything here grow with the size of the
 * table". A query that scans is invisible at fifty rows and fatal at fifty
 * thousand, and the only way to tell them apart is to look.
 *
 * Run against the **tests** site, which is the throwaway one:
 *
 *     npx @wordpress/env run tests-cli wp eval-file \
 *         wp-content/plugins/quick-events-manager/tests/bench/benchmark.php
 *
 * It seeds by direct insert rather than through wp_insert_post(): ten thousand
 * posts through the API is twenty minutes of hooks firing, and what is being
 * measured is reading, not writing.
 *
 * Never loaded at runtime and never shipped — tests/ is excluded by .distignore.
 *
 * @package QuickEventsManager
 */

use QuickEventsManager\Events\Query;
use QuickEventsManager\Registration\Repository;

defined( 'ABSPATH' ) || exit;

/*
 * The seeding inserts are batched: each row is built with $wpdb->prepare() and
 * the prepared fragments are then imploded into one INSERT. That is the standard
 * way to write ten thousand rows without ten thousand round trips, and it is
 * also a shape these two sniffs cannot see through — they look for a prepare()
 * wrapping the whole statement. Every value that reaches the database here has
 * been through prepare() individually.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Seeding a benchmark fixture; no caching applies.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- See above.

/**
 * How many of each to make.
 */
const QEVM_BENCH_EVENTS        = 10000;
const QEVM_BENCH_REGISTRATIONS = 10000;

/**
 * Time one thing, with the queries it took.
 *
 * @param string   $name What is being measured.
 * @param callable $work The thing.
 * @return array<string, mixed>
 */
function qevm_bench_time( $name, $work ) {
	global $wpdb;

	$queries_before = $wpdb->num_queries;
	$started        = microtime( true );

	$rows = $work();

	$elapsed = ( microtime( true ) - $started ) * 1000;

	return array(
		'name'    => $name,
		'ms'      => round( $elapsed, 1 ),
		'queries' => $wpdb->num_queries - $queries_before,
		'rows'    => is_countable( $rows ) ? count( $rows ) : (int) $rows,
	);
}

/**
 * Make the events, if they are not already there.
 *
 * @return int The id of an event to hang registrations off.
 */
function qevm_bench_seed_events() {
	global $wpdb;

	$existing = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", QEVM_POST_TYPE )
	);

	if ( $existing < QEVM_BENCH_EVENTS ) {
		$now      = current_time( 'mysql' );
		$now_gmt  = current_time( 'mysql', true );
		$to_make  = QEVM_BENCH_EVENTS - $existing;
		$per_pass = 500;

		WP_CLI::log( sprintf( 'Seeding %d events…', $to_make ) );

		for ( $made = 0; $made < $to_make; $made += $per_pass ) {
			$values = array();

			for ( $i = 0; $i < $per_pass && ( $made + $i ) < $to_make; $i++ ) {
				$number   = $existing + $made + $i;
				$title    = 'Benchmark event ' . $number;
				$slug     = 'benchmark-event-' . $number;
				$values[] = $wpdb->prepare(
					'( %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )',
					1,
					$now,
					$now_gmt,
					'Seeded for the performance benchmark.',
					$title,
					'',
					'publish',
					'closed',
					'closed',
					$slug,
					$now,
					$now_gmt,
					QEVM_POST_TYPE
				);
			}

			$wpdb->query(
				"INSERT INTO {$wpdb->posts}
					( post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
					  post_status, comment_status, ping_status, post_name, post_modified,
					  post_modified_gmt, post_type )
				VALUES " . implode( ', ', $values )
			);
		}

		/*
		 * The start dates, spread over two years either side of today, because
		 * an archive query that only ever sees future events is not the query
		 * a real site runs.
		 */
		WP_CLI::log( 'Adding start dates…' );

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", QEVM_POST_TYPE )
		);

		$meta = array();

		foreach ( $ids as $index => $id ) {
			$offset = ( $index % 1460 ) - 730;
			$start  = gmdate( 'Y-m-d H:i:s', time() + ( $offset * DAY_IN_SECONDS ) );

			$meta[] = $wpdb->prepare( '( %d, %s, %s )', $id, '_qevm_start_utc', $start );
			$meta[] = $wpdb->prepare( '( %d, %s, %s )', $id, '_qevm_end_utc', gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS ) );

			if ( count( $meta ) >= 1000 ) {
				$wpdb->query( "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ', ', $meta ) );

				$meta = array();
			}
		}

		if ( array() !== $meta ) {
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ', ', $meta ) );
		}
	}

	qevm_bench_seed_occurrences();

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC LIMIT 1", QEVM_POST_TYPE )
	);
}

/**
 * Give every event a date row.
 *
 * **Not optional, and the first version of this file left it out.** The archive
 * query joins the occurrences table through a `posts_clauses` filter, so an
 * event with a start-time meta value and no occurrence row is an event the
 * archive cannot see. The benchmark duly reported the upcoming-events query at
 * 1.2ms — over nothing at all. That is the same mistake as scanning an empty
 * calendar for accessibility, which is why the results table prints row counts
 * now.
 *
 * @return void
 */
function qevm_bench_seed_occurrences() {
	global $wpdb;

	$table = $wpdb->prefix . 'qevm_occurrences';

	$missing = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, m.meta_value AS start_utc
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_qevm_start_utc'
			LEFT JOIN {$table} o ON o.event_id = p.ID
			WHERE p.post_type = %s AND p.post_status = 'publish' AND o.id IS NULL
			GROUP BY p.ID",
			QEVM_POST_TYPE
		)
	);

	if ( array() === $missing ) {
		return;
	}

	WP_CLI::log( sprintf( 'Adding %d date rows…', count( $missing ) ) );

	$now    = gmdate( 'Y-m-d H:i:s' );
	$values = array();

	foreach ( $missing as $row ) {
		$end = gmdate( 'Y-m-d H:i:s', strtotime( $row->start_utc ) + HOUR_IN_SECONDS );

		$values[] = $wpdb->prepare(
			'( %d, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s )',
			$row->ID,
			'',
			$row->start_utc,
			$end,
			$row->start_utc,
			$end,
			'UTC',
			0,
			0,
			'scheduled',
			$now,
			$now
		);

		if ( count( $values ) >= 500 ) {
			$wpdb->query(
				"INSERT INTO {$table}
					( event_id, series_uuid, start_utc, end_utc, start_local, end_local, timezone,
					  all_day, is_exception, status, created_at, updated_at )
				VALUES " . implode( ', ', $values )
			);

			$values = array();
		}
	}

	if ( array() !== $values ) {
		$wpdb->query(
			"INSERT INTO {$table}
				( event_id, series_uuid, start_utc, end_utc, start_local, end_local, timezone,
				  all_day, is_exception, status, created_at, updated_at )
			VALUES " . implode( ', ', $values )
		);
	}
}

/**
 * Make the registrations, if they are not already there.
 *
 * @param int $event_id Event to book onto.
 * @return void
 */
function qevm_bench_seed_registrations( $event_id ) {
	global $wpdb;

	$table    = Repository::table();
	$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event_id ) );

	if ( $existing >= QEVM_BENCH_REGISTRATIONS ) {
		return;
	}

	$to_make = QEVM_BENCH_REGISTRATIONS - $existing;

	WP_CLI::log( sprintf( 'Seeding %d registrations…', $to_make ) );

	$now = gmdate( 'Y-m-d H:i:s' );

	for ( $made = 0; $made < $to_make; $made += 500 ) {
		$values = array();

		for ( $i = 0; $i < 500 && ( $made + $i ) < $to_make; $i++ ) {
			$number   = $existing + $made + $i;
			$values[] = $wpdb->prepare(
				'( %d, %d, %d, %d, %s, %s, %d, %s, %s, %s, %s )',
				$event_id,
				0,
				0,
				0,
				'QEVB-' . str_pad( (string) $number, 10, '0', STR_PAD_LEFT ),
				'confirmed',
				1,
				'Benchmark person ' . $number,
				'bench' . $number . '@example.com',
				$now,
				$now
			);
		}

		$wpdb->query(
			"INSERT INTO {$table}
				( event_id, occurrence_id, ticket_type_id, order_id, code, status, quantity,
				  booker_name, booker_email, created_at, updated_at )
			VALUES " . implode( ', ', $values )
		);
	}
}

$qevm_bench_event = qevm_bench_seed_events();

qevm_bench_seed_registrations( $qevm_bench_event );

$qevm_bench_results = array();

/*
 * The archive, which is the query every visitor runs. Ordered by a meta value,
 * which is the thing most likely to be slow — and the reason the start time is
 * stored as a sortable string rather than needing a CAST.
 */
$qevm_bench_results[] = qevm_bench_time(
	'Upcoming events, first page of 10',
	static function () {
		$query = new WP_Query( Query::upcoming_args( array( 'posts_per_page' => 10 ) ) );

		return $query->posts;
	}
);

$qevm_bench_results[] = qevm_bench_time(
	'Upcoming events, page 50',
	static function () {
		$query = new WP_Query(
			Query::upcoming_args(
				array(
					'posts_per_page' => 10,
					'paged'          => 50,
				)
			)
		);

		return $query->posts;
	}
);

/*
 * Capacity. Asked on every booking, and the one number that must never be
 * stale — so it is counted rather than cached, and how it grows matters.
 */
$qevm_bench_results[] = qevm_bench_time(
	'Places taken on an event with 10,000 bookings',
	static function () use ( $qevm_bench_event ) {
		return Repository::count_taken( $qevm_bench_event );
	}
);

$qevm_bench_results[] = qevm_bench_time(
	'Attendee screen, first page of 25',
	static function () use ( $qevm_bench_event ) {
		return Repository::for_event( $qevm_bench_event, array( 'limit' => 25 ) );
	}
);

$qevm_bench_results[] = qevm_bench_time(
	'Attendee screen, searching 10,000 bookings',
	static function () use ( $qevm_bench_event ) {
		return Repository::for_event(
			$qevm_bench_event,
			array(
				'limit'  => 25,
				'search' => 'Benchmark person 9999',
			)
		);
	}
);

$qevm_bench_results[] = qevm_bench_time(
	'One booking by its reference',
	static function () {
		return Repository::find_by_code( 'QEVB-0000009999' ) ? 1 : 0;
	}
);

$qevm_bench_results[] = qevm_bench_time(
	'A month of the calendar',
	static function () {
		return strlen( (string) \QuickEventsManager\Frontend\Renderer::calendar( array() ) );
	}
);

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Events: %s · Registrations on one event: %s', number_format_i18n( QEVM_BENCH_EVENTS ), number_format_i18n( QEVM_BENCH_REGISTRATIONS ) ) );
WP_CLI::log( '' );
WP_CLI::log( sprintf( '| %-52s | %8s | %7s | %6s |', 'What', 'ms', 'queries', 'rows' ) );
WP_CLI::log( sprintf( '| %s | %s | %s | %s |', str_repeat( '-', 52 ), str_repeat( '-', 8 ), str_repeat( '-', 7 ), str_repeat( '-', 6 ) ) );

foreach ( $qevm_bench_results as $qevm_bench_row ) {
	/*
	 * The row count is printed for a reason: a measurement of a query that
	 * returned nothing is a measurement of nothing. The first run of this
	 * reported a calendar month in 0.7ms and no queries at all, which was the
	 * calendar module being switched off on the site being measured.
	 */
	WP_CLI::log(
		sprintf(
			'| %-52s | %8s | %7d | %6d |',
			$qevm_bench_row['name'],
			$qevm_bench_row['ms'],
			$qevm_bench_row['queries'],
			$qevm_bench_row['rows']
		)
	);
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Peak memory: %s', size_format( memory_get_peak_usage( true ) ) ) );
