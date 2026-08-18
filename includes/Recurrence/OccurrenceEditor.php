<?php
/**
 * Changing one date of a series without touching the rest.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Domain\OccurrenceStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Occurrence;
use QuickEventsManager\Events\OccurrenceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "This occurrence" — move one date, call one off, hand one back to the rule.
 *
 * Every operation here sets `is_exception = 1`, which means exactly one thing:
 * **the rule no longer owns this row's times or status.** The reconciler in
 * `OccurrenceRepository` reads that flag and leaves those columns alone on every
 * subsequent regeneration, so an edit made here survives the next save of the
 * event — which is the property the whole of stage 6 rests on, and the one that
 * was broken before C6.4a.
 *
 * A per-occurrence edit changes **when a date happens or whether it happens, and
 * nothing else**. There is nowhere to put a per-occurrence title: the title lives
 * on the post that every occurrence in the series shares. See
 * [ADR-0015](../../docs/adr/0015-recurrence-identity-and-overrides.md) for why
 * detaching an instance into its own post is out of scope rather than merely
 * unbuilt.
 *
 * **Nothing here emails anybody.** Moving a date that 200 people have booked onto
 * is exactly when they need telling, and exactly why it must not happen as a side
 * effect of a save — an organiser correcting one date should not discover they
 * have sent 200 emails. The hooks below exist so a site can, and the admin screen
 * offers it as a next step.
 *
 * @since 26.0
 */
final class OccurrenceEditor {

	/**
	 * Move one date, leaving every other date in the series alone.
	 *
	 * Times are given as wall-clock in the event's own timezone, because that is
	 * what an organiser types and what the rest of the plugin stores. A time that
	 * does not exist — 02:30 on the night the clocks go forward — resolves the way
	 * `LocalTime` resolves it rather than being refused, since refusing would mean
	 * an organiser unable to schedule anything on that one night a year.
	 *
	 * With no end given the date keeps the length it had. Somebody moving a
	 * meeting from Tuesday to Wednesday is not usually also changing how long it
	 * runs for.
	 *
	 * @since 26.0
	 *
	 * @param int    $occurrence_id Occurrence id.
	 * @param string $start_local   New local start, `Y-m-d H:i:s`.
	 * @param string $end_local     New local end, or '' to keep the current length.
	 * @return true|\WP_Error
	 */
	public static function move( $occurrence_id, $start_local, $end_local = '' ) {
		$occurrence = self::editable( $occurrence_id );

		if ( is_wp_error( $occurrence ) ) {
			return $occurrence;
		}

		$zone = self::zone( $occurrence );

		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		$start = LocalTime::resolve( (string) $start_local, $zone );

		if ( null === $start ) {
			return new \WP_Error(
				'qevm_bad_start',
				__( 'That start time could not be read.', 'quick-events-manager' )
			);
		}

		$end = '' !== trim( (string) $end_local )
			? LocalTime::resolve( (string) $end_local, $zone )
			: $start->setTimestamp( $start->getTimestamp() + self::length( $occurrence ) );

		if ( null === $end ) {
			return new \WP_Error(
				'qevm_bad_end',
				__( 'That end time could not be read.', 'quick-events-manager' )
			);
		}

		if ( $end->getTimestamp() < $start->getTimestamp() ) {
			return new \WP_Error(
				'qevm_end_before_start',
				__( 'An event cannot end before it starts.', 'quick-events-manager' )
			);
		}

		$utc  = new \DateTimeZone( 'UTC' );
		$from = $occurrence->start_utc();

		$updated = OccurrenceRepository::update(
			$occurrence->id(),
			array(
				'start_utc'    => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'end_utc'      => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'start_local'  => $start->format( 'Y-m-d H:i:s' ),
				'end_local'    => $end->format( 'Y-m-d H:i:s' ),
				'is_exception' => 1,
				'status'       => OccurrenceStatus::Moved->value,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'qevm_not_moved', __( 'That date could not be moved.', 'quick-events-manager' ) );
		}

		/**
		 * Fires after one date of a series has been moved.
		 *
		 * Nobody is emailed by this plugin as a result. See the class docblock.
		 *
		 * @since 26.0
		 *
		 * @param int    $occurrence_id The date that moved.
		 * @param string $from          Where it was, `Y-m-d H:i:s` UTC.
		 * @param string $to            Where it is now, `Y-m-d H:i:s` UTC.
		 */
		do_action(
			'qevm_occurrence_moved',
			$occurrence->id(),
			$from,
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
		);

		return true;
	}

	/**
	 * Call off one date, keeping the row.
	 *
	 * The row stays because a date that vanishes from a calendar is
	 * indistinguishable from one that was never scheduled, and somebody who has it
	 * in their diary deserves to see it crossed out rather than to find nothing.
	 *
	 * Registrations are **not** cancelled. Calling off a date and refunding twelve
	 * people are two decisions, and collapsing them means one click does both.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id Occurrence id.
	 * @return true|\WP_Error
	 */
	public static function cancel( $occurrence_id ) {
		$occurrence = self::editable( $occurrence_id );

		if ( is_wp_error( $occurrence ) ) {
			return $occurrence;
		}

		if ( OccurrenceStatus::Cancelled === $occurrence->status() ) {
			return true;
		}

		$updated = OccurrenceRepository::update(
			$occurrence->id(),
			array(
				'status'       => OccurrenceStatus::Cancelled->value,
				'is_exception' => 1,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'qevm_not_cancelled', __( 'That date could not be called off.', 'quick-events-manager' ) );
		}

		/**
		 * Fires after one date of a series has been called off.
		 *
		 * @since 26.0
		 *
		 * @param int $occurrence_id The date that was cancelled.
		 * @param int $event_id      The event it belongs to.
		 */
		do_action( 'qevm_occurrence_cancelled', $occurrence->id(), $occurrence->event_id() );

		return true;
	}

	/**
	 * Put a called-off date back on.
	 *
	 * A date that was only ever cancelled goes back to the rule completely — there
	 * is nothing left to preserve once it is uncancelled, and leaving it flagged as
	 * an exception would freeze it against every future rule change for no reason.
	 *
	 * A date that was **moved and then cancelled** keeps its moved time and stays
	 * an exception, because that time is still what the organiser asked for.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id Occurrence id.
	 * @return true|\WP_Error
	 */
	public static function reinstate( $occurrence_id ) {
		$occurrence = self::editable( $occurrence_id );

		if ( is_wp_error( $occurrence ) ) {
			return $occurrence;
		}

		if ( OccurrenceStatus::Cancelled !== $occurrence->status() ) {
			return true;
		}

		$was_moved = $occurrence->start_utc() !== $occurrence->recurrence_id();

		if ( ! $was_moved ) {
			return self::restore( $occurrence_id );
		}

		$updated = OccurrenceRepository::update(
			$occurrence->id(),
			array(
				'status'       => OccurrenceStatus::Moved->value,
				'is_exception' => 1,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'qevm_not_reinstated', __( 'That date could not be put back.', 'quick-events-manager' ) );
		}

		/**
		 * Fires after a called-off date has been put back on.
		 *
		 * @since 26.0
		 *
		 * @param int $occurrence_id The date.
		 * @param int $event_id      The event it belongs to.
		 */
		do_action( 'qevm_occurrence_reinstated', $occurrence->id(), $occurrence->event_id() );

		return true;
	}

	/**
	 * Hand one date back to the rule.
	 *
	 * The undo for everything above: clearing `is_exception` gives the rule
	 * ownership of the row again, and the regeneration that follows rewrites its
	 * times and status from the rule. That is the whole mechanism — no stored
	 * "original" to restore from, because the slot the row came from has been on
	 * the row all along.
	 *
	 * If the rule no longer produces this date at all, restoring removes it — or
	 * cancels it, if somebody has booked onto it. That is the same rule every
	 * dropped date follows, and it is the honest answer: a date the series does
	 * not have is not a date the series can hold on the organiser's behalf.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id Occurrence id.
	 * @return true|\WP_Error
	 */
	public static function restore( $occurrence_id ) {
		$occurrence = self::editable( $occurrence_id );

		if ( is_wp_error( $occurrence ) ) {
			return $occurrence;
		}

		$event_id = $occurrence->event_id();

		$updated = OccurrenceRepository::update(
			$occurrence->id(),
			array(
				'is_exception' => 0,
				'status'       => OccurrenceStatus::Scheduled->value,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'qevm_not_restored', __( 'That date could not be reset.', 'quick-events-manager' ) );
		}

		\QuickEventsManager\Events\OccurrenceSync::sync( $event_id );

		/**
		 * Fires after one date has been handed back to its recurrence rule.
		 *
		 * @since 26.0
		 *
		 * @param int $occurrence_id The date.
		 * @param int $event_id      The event it belongs to.
		 */
		do_action( 'qevm_occurrence_restored', $occurrence_id, $event_id );

		return true;
	}

	/**
	 * The occurrence, if it is one these operations apply to.
	 *
	 * Only a generated date can be edited this way. A one-off event has a single
	 * occurrence derived from its own meta, and "move this occurrence" there means
	 * "change the event's date" — a different operation with a different screen,
	 * which would be silently undone on the next save if it were done here.
	 *
	 * @since 26.0
	 *
	 * @param int $occurrence_id Occurrence id.
	 * @return Occurrence|\WP_Error
	 */
	private static function editable( $occurrence_id ) {
		$occurrence = OccurrenceRepository::find( (int) $occurrence_id );

		if ( null === $occurrence ) {
			return new \WP_Error( 'qevm_no_occurrence', __( 'That date could not be found.', 'quick-events-manager' ) );
		}

		if ( ! $occurrence->is_generated() ) {
			return new \WP_Error(
				'qevm_not_a_series_date',
				__( 'This event does not repeat, so change its date on the event itself.', 'quick-events-manager' )
			);
		}

		return $occurrence;
	}

	/**
	 * The zone an occurrence's wall-clock times are written in.
	 *
	 * Read from the row rather than from the event, because the row records the
	 * zone it was generated in and an event whose timezone has since been changed
	 * must not silently reinterpret dates that already exist.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The date.
	 * @return \DateTimeZone|\WP_Error
	 */
	private static function zone( Occurrence $occurrence ) {
		$name = $occurrence->timezone();

		if ( '' === $name ) {
			$event = new Event( $occurrence->event_id() );
			$name  = $event->is_valid() ? $event->timezone() : 'UTC';
		}

		try {
			return new \DateTimeZone( '' !== $name ? $name : 'UTC' );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'qevm_bad_timezone',
				__( 'This date is stored against a timezone that is no longer recognised.', 'quick-events-manager' )
			);
		}
	}

	/**
	 * How long an occurrence currently runs for, in seconds.
	 *
	 * @since 26.0
	 *
	 * @param Occurrence $occurrence The date.
	 * @return int
	 */
	private static function length( Occurrence $occurrence ) {
		$start = strtotime( $occurrence->start_utc() . ' UTC' );
		$end   = strtotime( $occurrence->end_utc() . ' UTC' );

		if ( false === $start || false === $end ) {
			return 0;
		}

		return max( 0, $end - $start );
	}
}
