<?php
/**
 * The one place a date question becomes SQL.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

use QuickEventsManager\Domain\OccurrenceStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Joins WP_Query to the occurrences table instead of to post meta.
 *
 * Every date filter and every date sort in the plugin goes through here. That
 * is the point: before this, four separate places each built their own
 * `meta_query`, and each one was four `postmeta` self-joins, a `CAST` in the
 * WHERE and a filesort over an unindexed `longtext`. See
 * docs/adr/0003-occurrence-table.md.
 *
 * Callers do not write SQL. They add one query variable —
 *
 *     new \WP_Query( OccurrenceQuery::upcoming_args( array( 'posts_per_page' => 10 ) ) );
 *
 * — and a single `posts_clauses` filter turns it into a join, a range condition
 * and an ordering. Everything WP_Query already does well (pagination, taxonomy
 * filters, search, capability checks) keeps working, because this modifies the
 * query rather than replacing it.
 *
 * @since 26.0
 */
final class OccurrenceQuery {

	/**
	 * The query variable that switches this on.
	 */
	const QUERY_VAR = 'qevm_occurrence';

	/**
	 * Table alias used in the generated SQL.
	 *
	 * Prefixed like everything else: a bare `o` could collide with an alias
	 * another plugin adds through the same filter.
	 */
	const ALIAS = 'qevm_occ';

	/**
	 * Hook the clause filter.
	 *
	 * Registered globally rather than per-context, because admin list tables,
	 * REST controllers and front-end archives all need it and all pass through
	 * `posts_clauses`. Queries without the variable are returned untouched
	 * after one `isset()`.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'posts_clauses', array( $this, 'clauses' ), 10, 2 );
	}

	/**
	 * Arguments for events that have not finished yet.
	 *
	 * An event counts as upcoming until it *ends*, so a three-day conference on
	 * its second day is still listed rather than disappearing the moment it
	 * starts. Events with no stated end have `end_utc = start_utc`, so they are
	 * covered by the same comparison — which is the whole reason that column is
	 * NOT NULL.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $args Additional WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public static function upcoming_args( array $args = array() ) {
		return self::args(
			array(
				'when'  => 'upcoming',
				'order' => 'ASC',
			),
			$args
		);
	}

	/**
	 * Arguments for events that have finished.
	 *
	 * Newest first, which is the useful direction for an archive of things that
	 * already happened.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $args Additional WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public static function past_args( array $args = array() ) {
		return self::args(
			array(
				'when'  => 'past',
				'order' => 'DESC',
			),
			$args
		);
	}

	/**
	 * Arguments for every event, in date order, regardless of when it happens.
	 *
	 * Joined LEFT by default so an event nobody has dated yet still appears.
	 * Dropping undated events out of an admin list the moment someone sorts by
	 * date is how a post becomes unreachable.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $args  Additional WP_Query arguments.
	 * @param string               $order ASC or DESC.
	 * @return array<string, mixed>
	 */
	public static function all_args( array $args = array(), $order = 'ASC' ) {
		return self::args(
			array(
				'when'     => 'any',
				'order'    => $order,
				'required' => false,
			),
			$args
		);
	}

	/**
	 * Merge an occurrence specification into WP_Query arguments.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $spec Occurrence specification; see defaults().
	 * @param array<string, mixed> $args Additional WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public static function args( array $spec, array $args = array() ) {
		/*
		 * A spec passed in $args refines the one the helper chose rather than
		 * being thrown away. upcoming_args() decides "when" and "order"; a
		 * caller may still want to fix "now" for a test, widen the statuses, or
		 * ask for one row per date. Overwriting the key here instead would
		 * discard those silently, which is the worst of the three options —
		 * the caller has no way to tell it happened.
		 */
		$caller_spec = isset( $args[ self::QUERY_VAR ] ) && is_array( $args[ self::QUERY_VAR ] )
			? $args[ self::QUERY_VAR ]
			: array();

		unset( $args[ self::QUERY_VAR ] );

		return array_merge(
			array(
				'post_type'   => QEVM_POST_TYPE,
				'post_status' => 'publish',
			),
			$args,
			array(
				self::QUERY_VAR    => array_merge( self::defaults(), $spec, $caller_spec ),

				/*
				 * Not negotiable, and it goes after $args so a caller cannot
				 * turn it back on.
				 *
				 * Every one of these arguments is delivered by a posts_clauses
				 * filter, and get_posts() defaults suppress_filters to true
				 * (wp-includes/post.php). So the same arguments that filter and
				 * order correctly through WP_Query do absolutely nothing
				 * through get_posts(): no join, no date range, no ordering —
				 * just every event, newest first, silently. Found by the
				 * integration suite; the admin's event picker was doing exactly
				 * that.
				 */
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * The specification a caller starts from.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			// upcoming | past | any.
			'when'     => 'any',
			// ASC | DESC.
			'order'    => 'ASC',
			// Comparison point, `Y-m-d H:i:s` UTC. Empty means now.
			'now'      => '',
			// One row per event rather than one per date.
			'group'    => true,
			// INNER join. False keeps events that have no occurrence at all.
			'required' => true,
			// Occurrence statuses to include.
			'statuses' => null,
		);
	}

	/**
	 * Rewrite a query's clauses to join the occurrences table.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param \WP_Query             $query   Query being built.
	 * @return array<string, string>
	 */
	public function clauses( $clauses, $query ) {
		global $wpdb;

		$spec = $query->get( self::QUERY_VAR );

		if ( empty( $spec ) || ! is_array( $spec ) ) {
			return $clauses;
		}

		if ( ! OccurrenceRepository::table_exists() ) {
			return $clauses;
		}

		$spec     = array_merge( self::defaults(), $spec );
		$table    = OccurrenceRepository::table();
		$alias    = self::ALIAS;
		$statuses = is_array( $spec['statuses'] ) && ! empty( $spec['statuses'] )
			? array_values( $spec['statuses'] )
			: OccurrenceStatus::listable_values();

		$now = '' !== (string) $spec['now'] ? (string) $spec['now'] : gmdate( 'Y-m-d H:i:s' );

		/*
		 * The status test belongs in the ON clause, not the WHERE. On a LEFT
		 * join a WHERE condition against the joined table discards the rows
		 * with no match, which silently turns it back into an INNER join and
		 * loses exactly the undated events the LEFT join existed to keep.
		 */
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$join_type    = $spec['required'] ? 'INNER JOIN' : 'LEFT JOIN';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias and $join_type are internal constants; $placeholders is a generated list of %s. Every value is bound below.
		$clauses['join'] .= ' ' . $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Values follow in the array.
			"{$join_type} %i {$alias} ON {$alias}.event_id = {$wpdb->posts}.ID AND {$alias}.status IN ( {$placeholders} )",
			array_merge( array( $table ), $statuses )
		);

		if ( 'upcoming' === $spec['when'] ) {
			$clauses['where'] .= $wpdb->prepare( " AND {$alias}.end_utc >= %s", $now );
		} elseif ( 'past' === $spec['when'] ) {
			$clauses['where'] .= $wpdb->prepare( " AND {$alias}.end_utc < %s", $now );
		}

		$order = 'DESC' === strtoupper( (string) $spec['order'] ) ? 'DESC' : 'ASC';

		if ( $spec['group'] ) {
			$clauses['groupby'] = "{$wpdb->posts}.ID";

			/*
			 * Aggregated because the group may hold several dates once
			 * recurrence lands. An upcoming list sorts on the soonest date
			 * still to come; a past list on the most recent one that has been.
			 * Picking a bare column here would let MySQL choose any row in the
			 * group, and the order would be stable only by accident.
			 */
			$aggregate          = 'DESC' === $order ? 'MAX' : 'MIN';
			$clauses['orderby'] = "{$aggregate}({$alias}.start_utc) {$order}, {$wpdb->posts}.ID {$order}";
		} else {
			$clauses['orderby'] = "{$alias}.start_utc {$order}, {$wpdb->posts}.ID {$order}";
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $clauses;
	}
}
