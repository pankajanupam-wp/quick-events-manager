<?php
/**
 * Admitting somebody at the door.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CheckIn;

use QuickEventsManager\Domain\AttendeeStatus;
use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Registration\Attendee;
use QuickEventsManager\Registration\AttendeeRepository;
use QuickEventsManager\Registration\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * One person, one door, one answer.
 *
 * Everything here is written for somebody holding a phone in one hand and a
 * queue in front of them. There are exactly three outcomes and each has to be
 * readable at a glance: **in**, **already in**, or **no**. "Already in" is not
 * an error — it is the second scan of the same ticket, which happens constantly,
 * and it carries the time of the first so the person on the door can decide
 * whether it was ten seconds ago or an hour.
 *
 * **Which date somebody is arriving at is resolved here**, and the chunk
 * definition did not say what to do about a booking that names none. Every
 * booking on an event with a single date carries `occurrence_id = 0`, because
 * attaching it to a row identified by its start time would orphan it the moment
 * the organiser moved the event. So the door resolves it: an attendee with no
 * date of their own is arriving at the event's next date, which for a
 * single-date event is its only one. The check-in row then always names a real
 * occurrence, and the door report for a date is one indexed lookup rather than a
 * special case for events that never repeat.
 *
 * @since 26.0
 */
final class CheckInService {

	/**
	 * They are in.
	 */
	const ADMITTED = 'admitted';

	/**
	 * They were already in, and this says when.
	 */
	const ALREADY = 'already';

	/**
	 * They are not in, and this says why.
	 */
	const REFUSED = 'refused';

	/**
	 * Admit an attendee by their ticket code.
	 *
	 * What a QR scan resolves to, and what somebody typing a code into a box is
	 * doing. The code rather than the id, because the id is not on the ticket.
	 *
	 * @since 26.0
	 *
	 * @param string $ticket_code The code on the ticket.
	 * @param int    $by          User recording it, or 0.
	 * @param string $method      'qr' or 'manual'.
	 * @return array{result: string, message: string, attendee: Attendee|null, checkin: CheckIn|null}
	 */
	public static function admit_by_code( string $ticket_code, int $by = 0, string $method = 'manual' ): array {
		$attendee = AttendeeRepository::find_by_ticket_code( trim( $ticket_code ) );

		if ( null === $attendee ) {
			return self::refusal(
				__( 'That ticket is not one of this event\'s.', 'quick-events-manager' ),
				null
			);
		}

		return self::admit( $attendee->id(), $by, $method );
	}

	/**
	 * Admit an attendee.
	 *
	 * @since 26.0
	 *
	 * @param int    $attendee_id Attendee id.
	 * @param int    $by          User recording it, or 0.
	 * @param string $method      'qr' or 'manual'.
	 * @return array{result: string, message: string, attendee: Attendee|null, checkin: CheckIn|null}
	 */
	public static function admit( int $attendee_id, int $by = 0, string $method = 'manual' ): array {
		$attendee = AttendeeRepository::find( $attendee_id );

		if ( null === $attendee ) {
			return self::refusal( __( 'That ticket could not be found.', 'quick-events-manager' ), null );
		}

		$refusal = self::why_not( $attendee );

		if ( '' !== $refusal ) {
			return self::refusal( $refusal, $attendee );
		}

		$occurrence = self::arriving_at( $attendee );
		$recorded   = CheckInRepository::record( $attendee->id(), $occurrence, $by, $method );

		if ( 0 === $recorded ) {
			/*
			 * The insert was refused, which on this table means the unique key
			 * held: somebody else got there first, possibly a second ago at
			 * another door. Read what is there and report it as the fact it is.
			 */
			$existing = CheckInRepository::find( $attendee->id(), $occurrence );

			if ( null !== $existing && ! $existing->is_reversed() ) {
				return array(
					'result'   => self::ALREADY,
					'message'  => sprintf(
						/* translators: %s: The time they were checked in, e.g. "18:42". */
						__( 'Already checked in at %s.', 'quick-events-manager' ),
						$existing->format_time()
					),
					'attendee' => $attendee,
					'checkin'  => $existing,
				);
			}

			/*
			 * A reversed row is in the way. Putting it back is what the person
			 * on the door means by scanning again — they sent somebody out by
			 * mistake and are letting them in.
			 */
			if ( null !== $existing ) {
				CheckInRepository::unreverse( $existing->id() );

				return array(
					'result'   => self::ADMITTED,
					'message'  => __( 'Checked in.', 'quick-events-manager' ),
					'attendee' => $attendee,
					'checkin'  => CheckInRepository::find( $attendee->id(), $occurrence ),
				);
			}

			return self::refusal( __( 'That check-in could not be recorded.', 'quick-events-manager' ), $attendee );
		}

		return array(
			'result'   => self::ADMITTED,
			'message'  => __( 'Checked in.', 'quick-events-manager' ),
			'attendee' => $attendee,
			'checkin'  => CheckInRepository::find( $attendee->id(), $occurrence ),
		);
	}

	/**
	 * Undo an arrival.
	 *
	 * @since 26.0
	 *
	 * @param int $attendee_id Attendee id.
	 * @param int $by          User reversing it, or 0.
	 * @return bool
	 */
	public static function reverse( int $attendee_id, int $by = 0 ): bool {
		$attendee = AttendeeRepository::find( $attendee_id );

		if ( null === $attendee ) {
			return false;
		}

		$existing = CheckInRepository::find( $attendee->id(), self::arriving_at( $attendee ) );

		if ( null === $existing || $existing->is_reversed() ) {
			return false;
		}

		return CheckInRepository::reverse( $existing->id(), $by );
	}

	/**
	 * Whether somebody is recorded as present at their date.
	 *
	 * @since 26.0
	 *
	 * @param int $attendee_id Attendee id.
	 */
	public static function is_present( int $attendee_id ): bool {
		$attendee = AttendeeRepository::find( $attendee_id );

		if ( null === $attendee ) {
			return false;
		}

		$existing = CheckInRepository::find( $attendee->id(), self::arriving_at( $attendee ) );

		return null !== $existing && ! $existing->is_reversed();
	}

	/**
	 * The date an attendee is arriving at.
	 *
	 * Their own, when the booking named one. Otherwise the event's next date,
	 * which on an event that does not repeat is its only one — see the class
	 * docblock for why a booking on such an event names none.
	 *
	 * @since 26.0
	 *
	 * @param Attendee $attendee The person.
	 */
	private static function arriving_at( Attendee $attendee ): int {
		if ( $attendee->occurrence_id() > 0 ) {
			return $attendee->occurrence_id();
		}

		$registration = Repository::find( $attendee->registration_id() );

		if ( null === $registration ) {
			return 0;
		}

		$next = OccurrenceRepository::next_for_event( $registration->event_id() );

		return null !== $next ? $next->id() : 0;
	}

	/**
	 * Why this person is not being admitted, or '' if they are.
	 *
	 * Cancelled and waitlisted are refused for the same reason and told apart
	 * anyway: the person on the door needs to say something true to somebody
	 * standing in front of them, and "you cancelled this" and "you are on the
	 * waiting list" lead to completely different conversations.
	 *
	 * @since 26.0
	 *
	 * @param Attendee $attendee The person.
	 */
	private static function why_not( Attendee $attendee ): string {
		if ( AttendeeStatus::Cancelled === $attendee->status() ) {
			return __( 'This ticket was cancelled.', 'quick-events-manager' );
		}

		$registration = Repository::find( $attendee->registration_id() );

		if ( null === $registration ) {
			return __( 'That ticket could not be found.', 'quick-events-manager' );
		}

		if ( RegistrationStatus::Cancelled === $registration->status() ) {
			return __( 'This booking was cancelled.', 'quick-events-manager' );
		}

		if ( RegistrationStatus::Waitlisted === $registration->status() ) {
			return __( 'This booking is on the waiting list, so it has no place yet.', 'quick-events-manager' );
		}

		return '';
	}

	/**
	 * A refusal, in the shape every outcome takes.
	 *
	 * @since 26.0
	 *
	 * @param string        $message  What to say.
	 * @param Attendee|null $attendee The person, when there is one.
	 * @return array{result: string, message: string, attendee: Attendee|null, checkin: CheckIn|null}
	 */
	private static function refusal( string $message, ?Attendee $attendee ): array {
		return array(
			'result'   => self::REFUSED,
			'message'  => $message,
			'attendee' => $attendee,
			'checkin'  => null,
		);
	}
}
