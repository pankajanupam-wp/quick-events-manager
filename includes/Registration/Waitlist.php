<?php
/**
 * Moving people off the waiting list when a place is given back.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\Event;

defined( 'ABSPATH' ) || exit;

/**
 * A waiting list that actually moves.
 *
 * Before this, `waitlisted` was a status a booking was given at insert and
 * then kept forever. Cancelling a booking freed the place in every count, and
 * nobody was ever moved into it — so an event could finish with an empty seat
 * and six people who had asked for it.
 *
 * **Strict first-in, first-out.** The oldest waiting booking is considered
 * first, and if it does not fit, promotion stops. It would be easy to skip it
 * and promote a smaller booking behind it, and that would fill more seats —
 * but it also means somebody who asked for three places watches people who
 * asked after them go in ahead, every time, and never gets in at all. A
 * waiting list whose order is advisory is not a waiting list. Sites that want
 * best-fit can reorder the queue through `qevm_waitlist_candidates`.
 *
 * **Nobody is promoted silently.** A confirmed place the attendee does not
 * know about is worse than no place: they do not come, the seat is wasted
 * anyway, and the organiser counted on them. Promotion and its email are one
 * operation.
 *
 * @since 26.0
 */
final class Waitlist {

	/**
	 * Most bookings to consider in one pass.
	 *
	 * A cancelled 20-place booking cannot free more than 20 single-place
	 * bookings, so this is generous. It exists so that a corrupted capacity
	 * value cannot turn one cancellation into an unbounded loop.
	 */
	const MAX_PROMOTIONS = 50;

	/**
	 * Guard against re-entering while promoting.
	 *
	 * Promotion changes a status, which fires the same action this listens to.
	 * That inner run frees no place and stops immediately, so the recursion is
	 * shallow and harmless — but it also re-reads capacity mid-promotion,
	 * which is exactly the kind of thing that becomes a double promotion after
	 * somebody adds a feature two years from now.
	 *
	 * @var bool
	 */
	private static $promoting = false;

	/**
	 * Listen for places being given back.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'qevm_registration_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Promote when a status change has freed a place.
	 *
	 * Keyed on the *transition*, not on the new status: a booking going from
	 * confirmed to cancelled frees places, one going from waitlisted to
	 * cancelled frees none, because it never held any.
	 *
	 * What this check is **not** is the thing that stops an oversell. That is
	 * the capacity re-read in the promotion loop, which finds no free place and
	 * stops regardless of how it was entered — verified by removing this
	 * condition and watching every waitlist test still pass. This is an early
	 * return: it keeps a waitlisted withdrawal from doing a pointless query and
	 * a pointless pass over the queue, on the code path that runs every time
	 * anybody withdraws from a full event. Correctness lives one level down,
	 * where it can be enforced rather than predicted.
	 *
	 * @since 26.0
	 *
	 * @param int                $id       Registration id.
	 * @param RegistrationStatus $status   Status it now has.
	 * @param RegistrationStatus $previous Status it had before.
	 * @return void
	 */
	public function on_status_changed( $id, RegistrationStatus $status, RegistrationStatus $previous ) {
		if ( ! $previous->occupies_place() || $status->occupies_place() ) {
			return;
		}

		$registration = Repository::find( (int) $id );

		if ( null === $registration ) {
			return;
		}

		/*
		 * The date the place came free on, not the event. A cancellation on the
		 * 3rd of June frees a place on the 3rd of June, and promoting somebody
		 * waiting for the 10th would give them a place they cannot use and take
		 * it from the person who wanted that week.
		 */
		self::promote_for_event( $registration->event_id(), $registration->occurrence_id() );
	}

	/**
	 * Fill whatever capacity is free from the waiting list.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id      Event to fill.
	 * @param int $occurrence_id Fill this date only, or 0 for the event as a whole.
	 * @return Registration[] Bookings promoted, in the order they were.
	 */
	public static function promote_for_event( $event_id, $occurrence_id = 0 ) {
		if ( self::$promoting ) {
			return array();
		}

		$event = new Event( (int) $event_id );

		if ( ! $event->is_valid() ) {
			return array();
		}

		$capacity = (int) $event->meta( \QuickEventsManager\Events\Meta::CAPACITY, 0 );


		/*
		 * An uncapped event has no waiting list to work through: nothing is
		 * ever waitlisted, because nothing is ever full. Returning early also
		 * means an organiser removing a capacity limit does not trigger a
		 * promotion storm — everybody waiting is promoted by the pass that
		 * runs on the next cancellation, one at a time, each with an email.
		 */
		if ( $capacity <= 0 ) {
			return array();
		}

		$candidates = self::candidates( $event_id, (int) $occurrence_id );

		if ( empty( $candidates ) ) {
			return array();
		}

		self::$promoting = true;
		$promoted        = array();

		try {
			foreach ( $candidates as $candidate ) {
				if ( count( $promoted ) >= self::MAX_PROMOTIONS ) {
					break;
				}

				/*
				 * Re-read on every iteration rather than decrementing a local
				 * count. The authority on how many places are taken is the
				 * table, and a promotion is a write to it.
				 */
				$free = $capacity - Repository::count_taken( $event_id, (int) $occurrence_id );

				if ( $free <= 0 || $candidate->quantity() > $free ) {
					// Strict FIFO: the queue stops here, it does not step over.
					break;
				}

				if ( ! Repository::update_status( $candidate->id(), RegistrationStatus::Confirmed ) ) {
					break;
				}

				$confirmed = Repository::find( $candidate->id() );

				if ( null === $confirmed ) {
					break;
				}

				$promoted[] = $confirmed;

				/**
				 * Fires when a booking is moved off the waiting list.
				 *
				 * The confirmation email is sent on this hook, so removing it
				 * removes the notification and leaves the promotion silent.
				 *
				 * @since 26.0
				 *
				 * @param Registration $confirmed The booking, now confirmed.
				 * @param Event        $event     The event.
				 */
				do_action( 'qevm_registration_promoted', $confirmed, $event );
			}
		} finally {
			self::$promoting = false;
		}

		return $promoted;
	}

	/**
	 * The waiting list for an event, oldest first.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id      Event id.
	 * @param int $occurrence_id Only those waiting for this date, or 0 for all.
	 * @return Registration[]
	 */
	public static function candidates( $event_id, $occurrence_id = 0 ) {
		$candidates = Repository::for_event(
			(int) $event_id,
			array(
				'status'        => RegistrationStatus::Waitlisted->value,
				'occurrence_id' => (int) $occurrence_id,
				'orderby'       => 'id',
				'order'         => 'ASC',
				'per_page'      => self::MAX_PROMOTIONS,
			)
		);

		/**
		 * Filters who is considered for promotion, and in what order.
		 *
		 * The default is the order people joined the list. Reordering here is
		 * how a site implements best-fit, priority tiers or anything else —
		 * the promotion loop itself only ever takes them in the order it is
		 * given and stops at the first that does not fit.
		 *
		 * @since 26.0
		 *
		 * @param Registration[] $candidates    Waitlisted bookings, oldest first.
		 * @param int            $event_id      Event id.
		 * @param int            $occurrence_id Date being filled, or 0 for the event.
		 */
		return apply_filters( 'qevm_waitlist_candidates', $candidates, (int) $event_id, (int) $occurrence_id );
	}
}
