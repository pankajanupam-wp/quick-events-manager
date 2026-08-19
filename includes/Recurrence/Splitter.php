<?php
/**
 * Splitting a series in two, from one date onward.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Events\Duplicator;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\Occurrence;
use QuickEventsManager\Events\OccurrenceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "This and following" — the series becomes two events that share a uuid.
 *
 * The organiser's mental model is one series with a change part way through.
 * The storage model is two events, because a single event carries a single rule
 * and "Tuesdays until March, then Wednesdays" is two rules. Both halves keep the
 * same `series_uuid`, which is what makes them one series again to anything that
 * asks — the export, the admin list, and whatever later wants to show a person
 * every date they have booked.
 *
 * **Dates from the split point on are moved, not regenerated.** Deleting them
 * and generating a fresh set for the new event would give every one of them a
 * new row id, and every booking pointing at those ids would be orphaned in a
 * single statement — the rows still there, still counted, attached to dates that
 * no longer exist. Re-pointing preserves the ids, so bookings survive a split
 * without the registration table being touched by this class at all.
 *
 * What the registration table does need is its `event_id` corrected: it names
 * the original post, and the occurrence has moved to the new one. Neither table
 * can show that disagreement on its own — each is internally consistent — so it
 * is corrected through `qevm_series_split` rather than by reaching into a module
 * that may not be loaded. The attendees table needs nothing: it reaches its
 * event through its registration and carries no `event_id` of its own.
 *
 * Nothing here applies the organiser's edit. Splitting is the structural half of
 * "this and following"; the change they actually asked for is made against the
 * new event afterwards, by the screen that offered the choice. Keeping the two
 * apart means the split can be tested for what it is — an operation that must
 * lose nothing — rather than only ever through an edit that hides it.
 *
 * @since 26.0
 */
final class Splitter {

	/**
	 * Split a series at one of its dates.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id The first date of the new half.
	 * @return int|\WP_Error New event id.
	 */
	public static function split( $occurrence_id ) {
		if ( ! RecurrenceModule::is_enabled() ) {
			return new \WP_Error(
				'qevm_split_no_module',
				__( 'Recurring events are switched off, so there is no series to split.', 'quick-events-manager' )
			);
		}

		$occurrence = OccurrenceRepository::find( (int) $occurrence_id );

		if ( null === $occurrence ) {
			return new \WP_Error( 'qevm_split_missing', __( 'That date could not be found.', 'quick-events-manager' ) );
		}

		if ( ! $occurrence->is_generated() ) {
			return new \WP_Error(
				'qevm_split_not_a_series_date',
				__( 'This event does not repeat, so there is nothing to split.', 'quick-events-manager' )
			);
		}

		$event_id = $occurrence->event_id();
		$event    = new Event( $event_id );
		$rule     = Series::rule_for_event( $event_id );

		if ( ! $event->is_valid() || null === $rule ) {
			return new \WP_Error(
				'qevm_split_no_rule',
				__( 'That date belongs to an event with no repeat rule.', 'quick-events-manager' )
			);
		}

		$slot   = self::slot( $occurrence );
		$moving = array();
		$before = 0;

		foreach ( OccurrenceRepository::for_event( $event_id ) as $row ) {
			if ( self::slot( $row ) < $slot ) {
				++$before;

				continue;
			}

			$moving[] = $row->id();
		}

		/*
		 * Splitting at the first date is not a split. It would leave the
		 * original event with no dates at all and the new one carrying the
		 * whole series — an empty husk in the admin list holding the bookings
		 * of every date that used to be its own. What the organiser means by
		 * "this and following" on the first date is "all of them", which is a
		 * different scope with its own screen.
		 */
		if ( 0 === $before ) {
			return new \WP_Error(
				'qevm_split_at_start',
				__( 'This is the first date of the series, so change the series itself rather than splitting it.', 'quick-events-manager' )
			);
		}

		$zone = self::zone( $occurrence, $event );

		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		$copy_id = self::duplicate( $event_id, $event );

		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		self::describe_new_half( $copy_id, $event_id, $rule, $slot, $zone, self::duration( $event ), $before );
		self::end_original( $event_id, $rule, $slot, $zone );

		/*
		 * The copy generated a date of its own from the meta it inherited,
		 * before it was given the rule and the start it is going to keep. It is
		 * seconds old, nobody can have booked onto it, and leaving it would put
		 * a stray date in the middle of the new half.
		 */
		OccurrenceRepository::delete_for_event( $copy_id );

		$moved = OccurrenceRepository::move_to_event( $moving, $copy_id );

		/**
		 * Fires after a series has been split in two.
		 *
		 * Anything storing an event id alongside an occurrence id has to correct
		 * it here, or its rows disagree with the occurrence table from this
		 * moment on. `$moved` is every occurrence that changed hands.
		 *
		 * @since 26.0
		 *
		 * @param int   $copy_id  The new event, holding the dates from the split point on.
		 * @param int   $event_id The original event, holding the dates before it.
		 * @param int[] $moving   Occurrence ids that moved to the new event.
		 */
		do_action( 'qevm_series_split', $copy_id, $event_id, $moving );

		if ( count( $moving ) !== $moved ) {
			return new \WP_Error(
				'qevm_split_incomplete',
				__( 'Some dates could not be moved to the new event, so the split is incomplete.', 'quick-events-manager' ),
				array(
					'expected' => count( $moving ),
					'moved'    => $moved,
				)
			);
		}

		return $copy_id;
	}

	/**
	 * Copy the event, without giving the copy a rule to generate from.
	 *
	 * The duplicator syncs occurrences for what it creates, which is right for
	 * an ordinary "Duplicate" and wrong here: with the rule copied it would
	 * generate the entire series a second time — up to 730 rows — for the sake
	 * of deleting them again a few lines later. Skipping the rule for the length
	 * of the call leaves it generating the single date its start meta describes.
	 *
	 * The title and status are put back afterwards. A split produces two halves
	 * of one event, not an event and a draft called "(copy)".
	 *
	 * @since 26.0
	 *
	 * @param int   $event_id Original event id.
	 * @param Event $event    The original.
	 * @return int|\WP_Error
	 */
	private static function duplicate( $event_id, Event $event ) {
		$skip = static function ( $skipped ) {
			$skipped   = is_array( $skipped ) ? $skipped : array();
			$skipped[] = Meta::RECURRENCE_RULE;
			$skipped[] = Meta::SERIES_UUID;

			return $skipped;
		};

		add_filter( 'qevm_duplicate_skipped_meta', $skip );

		$copy_id = Duplicator::duplicate( $event_id );

		remove_filter( 'qevm_duplicate_skipped_meta', $skip );

		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		$original = $event->post();

		wp_update_post(
			wp_slash(
				array(
					'ID'          => (int) $copy_id,
					'post_title'  => $original instanceof \WP_Post ? $original->post_title : '',
					'post_status' => $original instanceof \WP_Post ? $original->post_status : 'draft',
				)
			)
		);

		return (int) $copy_id;
	}

	/**
	 * Give the new half its start, its rule and its share of the series.
	 *
	 * Its start is the *slot* the split date came from rather than where that
	 * date currently sits. A date that was moved to the Thursday is still the
	 * Tuesday the rule produced, and generating the new half from the Thursday
	 * would shift every date after it by two days.
	 *
	 * @since 26.0
	 *
	 * @param int           $copy_id  New event id.
	 * @param int           $event_id Original event id.
	 * @param Rule          $rule     The rule both halves come from.
	 * @param string        $slot     Split point, `Y-m-d H:i:s` UTC.
	 * @param \DateTimeZone $zone     The zone the series is written in.
	 * @param int           $duration How long one date runs for, in seconds.
	 * @param int           $before   How many dates stay with the original.
	 * @return void
	 */
	private static function describe_new_half( $copy_id, $event_id, Rule $rule, $slot, \DateTimeZone $zone, $duration, $before ) {
		$start_utc = new \DateTimeImmutable( $slot, new \DateTimeZone( 'UTC' ) );
		$end_utc   = $start_utc->setTimestamp( $start_utc->getTimestamp() + $duration );

		update_post_meta( $copy_id, Meta::START_UTC, $start_utc->format( Meta::FORMAT ) );
		update_post_meta( $copy_id, Meta::END_UTC, $end_utc->format( Meta::FORMAT ) );
		update_post_meta( $copy_id, Meta::START_LOCAL, $start_utc->setTimezone( $zone )->format( Meta::FORMAT ) );
		update_post_meta( $copy_id, Meta::END_LOCAL, $end_utc->setTimezone( $zone )->format( Meta::FORMAT ) );

		/*
		 * A COUNT is a promise about how many dates the series holds in total,
		 * so it has to be divided rather than copied. Leaving it whole on both
		 * halves turns a ten-week course into thirteen weeks, and the organiser
		 * finds out from whoever turns up to the eleventh.
		 */
		$count = $rule->count() > 0 ? max( 1, $rule->count() - $before ) : 0;

		update_post_meta( $copy_id, Meta::RECURRENCE_RULE, $rule->with_ending( $rule->until(), $count )->to_string() );

		Series::copy( $event_id, $copy_id );

		$split_date = $start_utc->setTimezone( $zone )->format( 'Y-m-d' );
		$keep       = array_values(
			array_filter(
				Exclusions::for_event( $event_id ),
				static fn( string $date ): bool => $date >= $split_date
			)
		);

		update_post_meta( $copy_id, Meta::RECURRENCE_EXCLUSIONS, Exclusions::to_string( $keep ) );
	}

	/**
	 * Stop the original half at the split point.
	 *
	 * `UNTIL` is set one second before the slot rather than to the previous day,
	 * so a rule producing more than one date a day splits between them rather
	 * than losing the earlier ones. Any `COUNT` is dropped in the same move: the
	 * two cannot both stand, and an ending expressed as a date is the one that
	 * still means the same thing after the series has been cut in half.
	 *
	 * @since 26.0
	 *
	 * @param int           $event_id Original event id.
	 * @param Rule          $rule     The rule as it was.
	 * @param string        $slot     Split point, `Y-m-d H:i:s` UTC.
	 * @param \DateTimeZone $zone     The zone the series is written in.
	 * @return void
	 */
	private static function end_original( $event_id, Rule $rule, $slot, \DateTimeZone $zone ) {
		$utc   = new \DateTimeZone( 'UTC' );
		$point = new \DateTimeImmutable( $slot, $utc );
		$until = $point->setTimestamp( $point->getTimestamp() - 1 );

		update_post_meta( $event_id, Meta::RECURRENCE_RULE, $rule->with_ending( $until->format( Meta::FORMAT ), 0 )->to_string() );

		$split_date = $point->setTimezone( $zone )->format( 'Y-m-d' );
		$keep       = array_values(
			array_filter(
				Exclusions::for_event( $event_id ),
				static fn( string $date ): bool => $date < $split_date
			)
		);

		update_post_meta( $event_id, Meta::RECURRENCE_EXCLUSIONS, Exclusions::to_string( $keep ) );
	}

	/**
	 * The slot a row came from, which is what dates are compared by.
	 *
	 * Falling back to the start time covers a row written before the column
	 * existed. Comparing on start times generally is the bug C6.4a fixed: a
	 * moved date sorts by where it was moved to, so a series split by time would
	 * put a date moved backwards into the wrong half.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The row.
	 * @return string
	 */
	private static function slot( Occurrence $occurrence ) {
		$slot = $occurrence->recurrence_id();

		return '' !== $slot ? $slot : $occurrence->start_utc();
	}

	/**
	 * The zone the series' wall-clock times are written in.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The split date.
	 * @param Event      $event      Its event.
	 * @return \DateTimeZone|\WP_Error
	 */
	private static function zone( Occurrence $occurrence, Event $event ) {
		$name = '' !== $occurrence->timezone() ? $occurrence->timezone() : $event->timezone();

		try {
			return new \DateTimeZone( '' !== $name ? $name : 'UTC' );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'qevm_split_bad_timezone',
				__( 'This series is stored against a timezone that is no longer recognised.', 'quick-events-manager' )
			);
		}
	}

	/**
	 * How long one date of the series runs for, in seconds.
	 *
	 * Taken from the event rather than from the date being split at, because
	 * that date may be an exception whose length was changed for itself alone.
	 * The new half inherits the series' shape, not one instance's edit.
	 *
	 * @since 26.0
	 *
	 * @param Event $event The original.
	 * @return int
	 */
	private static function duration( Event $event ) {
		$start = strtotime( $event->start_utc() . ' UTC' );
		$end   = strtotime( ( '' !== $event->end_utc() ? $event->end_utc() : $event->start_utc() ) . ' UTC' );

		if ( false === $start || false === $end ) {
			return 0;
		}

		return max( 0, $end - $start );
	}
}
