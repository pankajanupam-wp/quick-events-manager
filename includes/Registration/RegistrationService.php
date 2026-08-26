<?php
/**
 * The single path a registration takes, whatever submitted it.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Registration;

use QuickEventsManager\Domain\OccurrenceStatus;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\CustomFields\AnswerRepository;
use QuickEventsManager\CustomFields\Answers;
use QuickEventsManager\CustomFields\CustomFieldsModule;
use QuickEventsManager\CustomFields\Definitions;
use QuickEventsManager\Plugin;
use QuickEventsManager\Privacy\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and creates registrations.
 *
 * Both transports — the public form posting to admin-post.php and the REST
 * endpoint — call create(). That is deliberate: validation, capacity, duplicate
 * detection and rate limiting are security-relevant, and having two copies of
 * them is how one copy quietly falls behind the other.
 *
 * A booking is one registration and one attendee row per place, always,
 * including when a single place was booked. The registration is the booking —
 * who arranged it, who pays, one status, one reference. The attendee is the
 * person a ticket admits. See docs/adr/0004-registration-attendee-split.md.
 *
 * @since 26.0
 */
final class RegistrationService {

	/**
	 * How many submissions one address may make in the window.
	 *
	 * Set high enough not to catch real people. An office, a university or a
	 * conference venue puts every visitor behind one NAT gateway, so they all
	 * share an address — and those are exactly the places that run events.
	 * A limit tight enough to be interesting to an attacker would lock out a
	 * whole building, so this is aimed only at crude flooding.
	 */
	const RATE_LIMIT = 30;

	/**
	 * Rate-limit window, in seconds.
	 */
	const RATE_WINDOW = 300;

	/**
	 * The largest booking one submission may make.
	 *
	 * Read by the form as well as by validation, so the number of options a
	 * visitor is offered and the number the service will accept cannot drift
	 * apart. A cap exists at all because every place becomes an attendee row,
	 * and an unbounded quantity is an unbounded write.
	 */
	const MAX_PLACES = 20;

	/**
	 * A booking somebody made themselves, through the form.
	 */
	/**
	 * The place whose attendee row carries the custom answers.
	 *
	 * One, the person booking. Named rather than written as a literal because
	 * the storage is per attendee and could carry a set for every guest; the
	 * form asking once is the current limit, not the shape of the data.
	 */
	const ANSWER_POSITION = 1;

	const CONTEXT_PUBLIC = 'public';

	/**
	 * A booking an organiser entered on somebody's behalf.
	 *
	 * Phone calls, walk-ins and paper sign-up sheets are real, and every
	 * assumption the public path makes is wrong for them: there is no rate
	 * limit to apply to a person typing, no closing date that should stop the
	 * organiser, no logged-in user who is the attendee, and no consent screen
	 * the attendee ever saw. Capacity still applies — a manual entry into a
	 * full event joins the waiting list like anybody else, because the room is
	 * the same size whichever way somebody got into it.
	 */
	const CONTEXT_MANUAL = 'manual';

	/**
	 * Register a person for an event.
	 *
	 * @since 26.0
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string, mixed> $input    Raw, untrusted input.
	 * @param string               $context  self::CONTEXT_PUBLIC or self::CONTEXT_MANUAL.
	 * @return Registration|\WP_Error
	 */
	public function create( $event_id, array $input, $context = self::CONTEXT_PUBLIC ) {
		$manual = self::CONTEXT_MANUAL === $context;
		$event  = new Event( $event_id );

		if ( ! $event->is_valid() ) {
			return new \WP_Error(
				'qevm_event_not_found',
				__( 'That event could not be found.', 'quick-events-manager' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * A draft event takes no public bookings, but an organiser entering the
		 * people who signed up on paper is working on an event that may not be
		 * published yet — often precisely because they are still assembling it.
		 */
		if ( ! $manual && 'publish' !== get_post_status( $event->id() ) ) {
			return new \WP_Error(
				'qevm_event_not_found',
				__( 'That event could not be found.', 'quick-events-manager' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * Closed registration stops the public form, not the organiser. The
		 * phone call that starts "I know it says it's closed, but" is the
		 * entire reason manual entry exists, and it arrives after the closing
		 * date by definition.
		 */
		if ( ! $manual && ! self::is_open( $event ) ) {
			return new \WP_Error(
				'qevm_registration_closed',
				__( 'Registration for this event is closed.', 'quick-events-manager' ),
				array( 'status' => 403 )
			);
		}

		/*
		 * The rate limit is a defence against a script, and an organiser typing
		 * in a paper sign-up sheet looks exactly like one: thirty entries in a
		 * few minutes from a single address. Applying it here would lock the
		 * administrator out of their own attendee list part-way through.
		 */
		if ( ! $manual ) {
			$limited = $this->check_rate_limit();

			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		}

		$occurrence_id = self::resolve_occurrence( $event, $input, $manual );

		if ( is_wp_error( $occurrence_id ) ) {
			return $occurrence_id;
		}

		$ticket_type = self::resolve_ticket_type( $event, $input );

		if ( is_wp_error( $ticket_type ) ) {
			return $ticket_type;
		}

		$fields = $this->validate( $input, $manual, $event_id );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( Repository::email_is_registered( $event->id(), $fields['email'], $occurrence_id ) ) {
			return new \WP_Error(
				'qevm_already_registered',
				__( 'That email address is already registered for this event.', 'quick-events-manager' ),
				array(
					'status' => 409,
					'field'  => 'email',
				)
			);
		}

		$registration = Repository::insert_with_capacity(
			array(
				'event_id'        => $event->id(),
				'occurrence_id'   => $occurrence_id,
				'ticket_type_id'  => null !== $ticket_type ? $ticket_type->id() : 0,

				/*
				 * The type's own capacity travels with the booking rather than
				 * being looked up inside the ranking. The repository's job is to
				 * count rows; deciding which limits apply is this service's, and
				 * keeping that split is what stopped the ranking query growing a
				 * join to a table another module owns.
				 */
				'ticket_capacity' => null !== $ticket_type ? $ticket_type->capacity() : 0,

				/*
				 * Nobody, on a manual entry. The logged-in user is the
				 * organiser doing the typing, not the person being booked in —
				 * recording them would give the administrator a booking they
				 * never made, on every event they ever helped somebody with.
				 */
				'user_id'         => $manual ? 0 : get_current_user_id(),
				'name'            => $fields['name'],
				'email'           => $fields['email'],
				'phone'           => $fields['phone'],
				'quantity'        => $fields['quantity'],

				/*
				 * Read here rather than taken from the submission. What was
				 * agreed to is whatever the site was showing, not whatever the
				 * request claims it was showing.
				 *
				 * Empty on a manual entry, and that is the honest value. The
				 * person was never shown the wording, so recording that they
				 * accepted version 9f2c3a1b of it would be a fabricated record
				 * — and a fabricated consent record is worse than none, because
				 * it survives an audit. What actually happened is that an
				 * organiser took their details another way; the export shows a
				 * blank, which is the truth.
				 */
				'consent_version' => $manual ? '' : Consent::version(),
			),
			(int) $event->meta( Meta::CAPACITY, 0 )
		);

		if ( is_wp_error( $registration ) ) {
			return $registration;
		}

		/*
		 * One row per place, created after the booking has resolved.
		 *
		 * The order matters. Capacity is counted in places on the registration
		 * by the insert-then-rank routine in Repository, and that routine's
		 * guarantee comes from a row's own auto-increment id fixing its
		 * position in the queue. Creating attendee rows afterwards leaves it
		 * exactly as it was: nothing here is counted, and nothing here can
		 * change whether the booking got its places.
		 *
		 * A booking whose attendee rows fail to write is still a booking: the
		 * places are held and the confirmation goes out. Refusing the whole
		 * registration because the roster could not be written would be the
		 * worse of the two failures by a wide margin.
		 */
		$attendees = AttendeeRepository::create_for_registration(
			$registration->id(),
			$registration->quantity(),
			self::people( $fields ),
			array(
				'occurrence_id'  => $occurrence_id,
				'ticket_type_id' => null !== $ticket_type ? $ticket_type->id() : 0,
			)
		);

		/*
		 * Answers are written after the attendee rows, because each set belongs
		 * to a person and there is nothing to attach them to until the people
		 * exist. Like the rows themselves, a failure here does not undo the
		 * booking: the places are held and the confirmation goes out.
		 */
		if ( isset( $fields['answers'] ) && array() !== $fields['answers'] ) {
			self::store_answers( $attendees, $fields['answers'] );
		}

		if ( ! $manual ) {
			$this->record_attempt();
		}

		/**
		 * Fires once a registration has been stored.
		 *
		 * The confirmation email is sent on this hook, so removing it stops
		 * the email without touching the rest of the flow.
		 *
		 * Fires after the attendee rows exist, so a listener can read the whole
		 * booking — everybody on it, and every ticket code.
		 *
		 * @since 26.0
		 *
		 * @param Registration $registration The stored registration.
		 * @param Event        $event        The event registered for.
		 */
		do_action( 'qevm_registration_created', $registration, $event );

		return $registration;
	}

	/**
	 * The ticket types an event offers, or none.
	 *
	 * The single place this module asks. Nothing here names a class from the
	 * ticketing module: with that module off the filter is unhooked, the answer
	 * is an empty list, and every question about types answers itself.
	 *
	 * @since 26.0
	 *
	 * The return is typed `mixed[]` rather than `object[]` on purpose. Whatever
	 * a filter hands back is whatever a filter hands back — declaring the type
	 * this module hopes for would let static analysis call the checks below
	 * redundant and invite somebody to delete them, at which point one badly
	 * behaved plugin turns a booking into a fatal error.
	 *
	 * @param int  $event_id      Event id.
	 * @param bool $sellable_only Leave out types the organiser has withdrawn.
	 * @return array<int, mixed>
	 */
	private static function ticket_types_for( $event_id, $sellable_only = false ) {
		$types = apply_filters( 'qevm_event_ticket_types', array(), (int) $event_id );
		$types = is_array( $types ) ? $types : array();

		if ( ! $sellable_only ) {
			return $types;
		}

		return array_values(
			array_filter(
				$types,
				static fn( $type ) => is_object( $type ) && method_exists( $type, 'is_sellable' ) && $type->is_sellable()
			)
		);
	}

	/**
	 * One of an event's ticket types, by id.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id       Event id.
	 * @param int $ticket_type_id Type id.
	 * @return mixed The type, or null.
	 */
	private static function ticket_type( $event_id, $ticket_type_id ) {
		if ( $ticket_type_id <= 0 ) {
			return null;
		}

		foreach ( self::ticket_types_for( $event_id ) as $type ) {
			if ( is_object( $type ) && method_exists( $type, 'id' ) && $type->id() === $ticket_type_id ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Which kind of place this booking is for.
	 *
	 * Null when the event offers no types, which is every event until somebody
	 * switches ticketing on and creates one. A type is required as soon as one
	 * exists, for the same reason a date is required as soon as there are
	 * several: the choice is real, and guessing it on somebody's behalf is how a
	 * concession ticket becomes a full-price one.
	 *
	 * A type outside its sale window is refused here rather than filtered out of
	 * the list above, because the two failures need different words. The form
	 * shows what is on sale; this is the check that a submission arriving a
	 * minute after the window shut does not slip through.
	 *
	 * Asked through `qevm_event_ticket_types`, which the ticketing module answers
	 * only while it is switched on. Reading its table directly was the earlier
	 * shape and it meant an organiser who tried ticket types and switched them
	 * off was left with a form still demanding a choice and an editor with no
	 * box to remove them in.
	 *
	 * @since 26.0
	 *
	 * @param Event                $event The event.
	 * @param array<string, mixed> $input Submitted fields.
	 * @return \QuickEventsManager\Tickets\TicketType|null|\WP_Error
	 */
	private static function resolve_ticket_type( Event $event, array $input ) {
		$offered = self::ticket_types_for( $event->id(), true );

		if ( array() === $offered ) {
			return null;
		}

		$chosen = isset( $input['ticket_type_id'] ) ? absint( $input['ticket_type_id'] ) : 0;

		if ( 0 === $chosen ) {
			return new \WP_Error(
				'qevm_ticket_type_required',
				__( 'Please choose which kind of place you are booking.', 'quick-events-manager' ),
				array(
					'status' => 400,
					'field'  => 'ticket_type_id',
				)
			);
		}

		foreach ( $offered as $type ) {
			if ( $type->id() !== $chosen ) {
				continue;
			}

			/*
			 * On offer, but not yet or not any more. Said separately from "not
			 * available", because somebody who chose a type a minute before its
			 * window closed deserves to be told which of those happened — and
			 * the two need different things from them: come back later, or pick
			 * something else.
			 */
			if ( $type->opens_after() ) {
				return new \WP_Error(
					'qevm_ticket_type_not_yet',
					__( 'That kind of place is not on sale yet.', 'quick-events-manager' ),
					array(
						'status' => 409,
						'field'  => 'ticket_type_id',
					)
				);
			}

			if ( $type->closed_by() ) {
				return new \WP_Error(
					'qevm_ticket_type_closed',
					__( 'That kind of place is no longer on sale.', 'quick-events-manager' ),
					array(
						'status' => 409,
						'field'  => 'ticket_type_id',
					)
				);
			}

			return $type;
		}

		/*
		 * Not found among what is on offer, which covers three different
		 * mistakes — another event's type, one that has been archived since the
		 * page was loaded, and one that never existed. They get one message:
		 * telling somebody which of those it was tells an attacker as well.
		 */
		return new \WP_Error(
			'qevm_ticket_type_unavailable',
			__( 'That kind of place is not available for this event.', 'quick-events-manager' ),
			array(
				'status' => 409,
				'field'  => 'ticket_type_id',
			)
		);
	}

	/**
	 * Whether an event has no date left to book.
	 *
	 * Asked of the occurrence table rather than of the event's own end time,
	 * because on a series that end time is the **first** date's. Reading it
	 * closed a weekly class to new bookings the moment week one finished, with
	 * eleven weeks still to run and the form replaced by "this event has
	 * already happened".
	 *
	 * `next_for_event()` answers the real question — is there a date still to
	 * come — and answers it identically for an event that has only one.
	 *
	 * @since 26.0
	 *
	 * @param Event $event The event.
	 * @return bool
	 */
	private static function everything_has_happened( Event $event ) {
		if ( OccurrenceRepository::count_for_event( $event->id() ) > 0 ) {
			return null === OccurrenceRepository::next_for_event( $event->id() );
		}

		return $event->has_ended();
	}

	/**
	 * Which date this booking is for.
	 *
	 * **Asked of the occurrence table, not of the recurrence module.** Whether a
	 * booking has a date to choose is answered by "does this event have more
	 * than one" — which is true of a series and false of everything else,
	 * however the dates got there and whether or not recurrence is switched on
	 * today. Registration and recurrence are both modules, and ADR-0009 is that
	 * modules depend on the domain rather than on each other.
	 *
	 * An event with a single date keeps `0`, deliberately. Its one occurrence is
	 * identified by its start time, so moving the event by an hour replaces that
	 * row with a different one — and a booking pointing at it would be left on a
	 * date the organiser cancelled by rescheduling. A generated date carries the
	 * slot it came from and survives being moved, which is exactly what makes it
	 * safe to attach a person to.
	 *
	 * @since 26.0
	 *
	 * @param Event                $event  The event.
	 * @param array<string, mixed> $input  Submitted fields.
	 * @param bool                 $manual Whether an organiser is entering this.
	 * @return int|\WP_Error
	 */
	private static function resolve_occurrence( Event $event, array $input, $manual ) {
		$chosen = isset( $input['occurrence_id'] ) ? absint( $input['occurrence_id'] ) : 0;

		if ( OccurrenceRepository::count_for_event( $event->id() ) <= 1 ) {
			return 0;
		}

		if ( 0 === $chosen ) {
			return new \WP_Error(
				'qevm_occurrence_required',
				__( 'Please choose which date you are booking.', 'quick-events-manager' ),
				array(
					'status' => 400,
					'field'  => 'occurrence_id',
				)
			);
		}

		$occurrence = OccurrenceRepository::find( $chosen );

		if ( null === $occurrence || $occurrence->event_id() !== $event->id() ) {
			return new \WP_Error(
				'qevm_occurrence_not_found',
				__( 'That date is not one of this event\'s dates.', 'quick-events-manager' ),
				array(
					'status' => 404,
					'field'  => 'occurrence_id',
				)
			);
		}

		if ( OccurrenceStatus::Cancelled === $occurrence->status() ) {
			return new \WP_Error(
				'qevm_occurrence_cancelled',
				__( 'That date has been called off.', 'quick-events-manager' ),
				array(
					'status' => 409,
					'field'  => 'occurrence_id',
				)
			);
		}

		/*
		 * A date that has already happened takes no public bookings, and does
		 * take them from an organiser — somebody entering last Tuesday's paper
		 * sign-up sheet on Wednesday is the ordinary case, not an error.
		 */
		if ( ! $manual && $occurrence->has_ended() ) {
			return new \WP_Error(
				'qevm_occurrence_past',
				__( 'That date has already happened.', 'quick-events-manager' ),
				array(
					'status' => 409,
					'field'  => 'occurrence_id',
				)
			);
		}

		return $occurrence->id();
	}

	/**
	 * Whether an event is currently accepting registrations.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event to check.
	 * @return bool
	 */
	public static function is_open( Event $event ) {
		return '' === self::closed_reason( $event );
	}

	/**
	 * Why registration is not open, or an empty string if it is.
	 *
	 * "Closed" is four different situations wearing one boolean, and a visitor
	 * is owed a different answer to each:
	 *
	 * - `disabled` — the organiser never switched registration on for this
	 *   event. There is nothing to explain, because nothing was ever offered.
	 * - `ended`    — the event has already happened.
	 * - `expired`  — the closing date has passed.
	 * - `filtered` — a site's own code closed it, for a reason only that code
	 *   knows. Explaining it here would put words in somebody else's mouth.
	 *
	 * Before this existed the form simply vanished, which is the one answer
	 * that is wrong in every case: somebody who followed a link to register
	 * arrives at a page that looks like an oversight and emails the organiser
	 * to ask whether the site is broken.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event to check.
	 * @return string One of '', 'disabled', 'ended', 'expired', 'filtered'.
	 */
	public static function closed_reason( Event $event ) {
		if ( ! $event->meta( Meta::REGISTRATION_ENABLED, false ) ) {
			return 'disabled';
		}

		if ( self::everything_has_happened( $event ) ) {
			return 'ended';
		}

		$closes = (string) $event->meta( Meta::REGISTRATION_CLOSES );

		if ( '' !== $closes && $closes < Meta::now_utc() ) {
			return 'expired';
		}

		/**
		 * Filter whether registration is open for an event.
		 *
		 * @since 26.0
		 *
		 * @param bool  $is_open Whether registration is open.
		 * @param Event $event   The event.
		 */
		return apply_filters( 'qevm_registration_is_open', true, $event ) ? '' : 'filtered';
	}

	/**
	 * Places still available, or null when the event is uncapped.
	 *
	 * @since 26.0
	 *
	 * Capacity is per date on an event that has several: twenty places on a
	 * weekly class means twenty each week, not twenty for the term.
	 *
	 * @param Event $event          Event to check.
	 * @param int   $occurrence_id  Date to count, or 0 for the event as a whole.
	 * @param int   $ticket_type_id Kind of place to count, or 0 for all of them.
	 * @return int|null
	 */
	public static function places_remaining( Event $event, $occurrence_id = 0, $ticket_type_id = 0 ) {
		$capacity = (int) $event->meta( Meta::CAPACITY, 0 );
		$ticket   = self::ticket_type( $event->id(), (int) $ticket_type_id );

		$remaining = null;

		if ( $capacity > 0 ) {
			$remaining = max( 0, $capacity - Repository::count_taken( $event->id(), (int) $occurrence_id ) );
		}

		if ( null !== $ticket && $ticket->capacity() > 0 ) {
			$of_this_kind = max(
				0,
				$ticket->capacity() - Repository::count_taken( $event->id(), (int) $occurrence_id, $ticket->id() )
			);

			/*
			 * The tighter of the two, because a booking has to fit both. Four
			 * member places left in a room with two seats free is two.
			 */
			$remaining = null === $remaining ? $of_this_kind : min( $remaining, $of_this_kind );
		}

		return $remaining;
	}

	/**
	 * Whether an event has run out of places.
	 *
	 * @since 26.0
	 *
	 * @param Event $event          Event to check.
	 * @param int   $occurrence_id  Date to check, or 0 for the event as a whole.
	 * @param int   $ticket_type_id Kind of place to check, or 0 for all of them.
	 * @return bool
	 */
	public static function is_full( Event $event, $occurrence_id = 0, $ticket_type_id = 0 ) {
		$remaining = self::places_remaining( $event, (int) $occurrence_id, (int) $ticket_type_id );

		return null !== $remaining && $remaining <= 0;
	}

	/**
	 * Validate and normalise the submitted fields.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $input    Raw input.
	 * @param bool                 $manual   Whether an organiser is entering this on somebody's behalf.
	 * @param int                  $event_id Event the booking is for, so its own questions can be checked.
	 * @return array{name: string, email: string, phone: string, quantity: int, guests: array<int, string>, answers: array<int, array<string, string|string[]>>}|\WP_Error
	 */
	private function validate( array $input, $manual = false, $event_id = 0 ) {
		/*
		 * Every problem, not the first one.
		 *
		 * Returning on the first failure means somebody with a missing name and
		 * a mistyped address fixes the name, submits, and is told about the
		 * address — two round trips through a form that does not remember what
		 * they typed. It is worst for exactly the people the accessibility
		 * standard is about: each attempt is a full re-read of the form with a
		 * screen reader, and the error is at the top while the field is
		 * somewhere below it.
		 *
		 * Each error carries the field it belongs to, so the form can put the
		 * message beside the input, mark it with aria-invalid, and move focus
		 * to the first one.
		 */
		$errors = new \WP_Error();

		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		$name = trim( $name );

		if ( '' === $name ) {
			$errors->add(
				'qevm_name_required',
				__( 'Please enter your name.', 'quick-events-manager' ),
				array(
					'status' => 400,
					'field'  => 'name',
				)
			);
		}

		if ( mb_strlen( $name ) > 190 ) {
			$name = mb_substr( $name, 0, 190 );
		}

		$email = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			$errors->add(
				'qevm_email_invalid',
				__( 'Please enter a valid email address.', 'quick-events-manager' ),
				array(
					'status' => 400,
					'field'  => 'email',
				)
			);
		}

		$phone = isset( $input['phone'] ) ? sanitize_text_field( $input['phone'] ) : '';

		if ( mb_strlen( $phone ) > 50 ) {
			$phone = mb_substr( $phone, 0, 50 );
		}

		/*
		 * Consent is checked here with the rest of the input rather than beside
		 * the capacity and closing-date checks above, because it is a fact
		 * about the submission and not about the event. Refused before anything
		 * is written: a row that exists without the agreement that permitted it
		 * is the state this whole chunk is about not having.
		 */
		if ( ! $manual && Consent::is_required() && empty( $input['consent'] ) ) {
			$errors->add(
				'qevm_consent_required',
				__( 'Please tick the box to confirm you agree before registering.', 'quick-events-manager' ),
				array(
					'status' => 400,
					'field'  => 'consent',
				)
			);
		}

		/*
		 * Quantity is settled before the custom questions are checked, because
		 * the questions are asked once per place and there is no way to know
		 * how many sets to expect without it.
		 */
		$quantity = isset( $input['quantity'] ) ? absint( $input['quantity'] ) : 1;
		$quantity = max( 1, min( self::MAX_PLACES, $quantity ) );

		$answers = $this->validate_answers( $errors, $input, $quantity, (int) $event_id );

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return array(
			'name'     => $name,
			'email'    => $email,
			'phone'    => $phone,
			'quantity' => $quantity,
			'guests'   => self::guest_names( isset( $input['guests'] ) ? $input['guests'] : array(), $quantity ),
			'answers'  => $answers,
		);
	}

	/**
	 * Check the custom questions.
	 *
	 * **Asked once, of the person booking.** The answers are stored against
	 * their attendee row, so the storage is per person and can carry a set for
	 * every guest later without a schema change — but the form asks once.
	 *
	 * Asking every guest is the right end state for dietary needs and access
	 * requirements, and it is not this chunk. Places run to twenty and questions
	 * to twenty, and the guest rows are already all rendered and hidden, so a
	 * set per guest is four hundred inputs in the markup of a form that usually
	 * books one place. Doing it properly means building the rows with script or
	 * paginating them, and both are larger than what is being changed here.
	 *
	 * The important part is that validation matches what is rendered. Checking
	 * positions the form never asked about would make a required question
	 * unanswerable for guests two and up, and refuse the booking outright.
	 *
	 * Errors are added to the same WP_Error the rest of validation uses, so a
	 * missing answer and a missing name come back together and the form can put
	 * every message beside its own input.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Error            $errors   Errors so far, added to in place.
	 * @param array<string, mixed> $input    Submitted input.
	 * @param int                  $quantity Places booked.
	 * @param int                  $event_id Event id.
	 * @return array<int, array<string, string|string[]>> Answers by position.
	 */
	private function validate_answers( \WP_Error $errors, array $input, $quantity, $event_id ) {
		if ( $event_id <= 0 || ! Plugin::instance()->registry()->is_enabled( CustomFieldsModule::ID ) ) {
			return array();
		}

		$fields = Definitions::for_event( $event_id );

		if ( array() === $fields ) {
			return array();
		}

		$submitted = isset( $input[ Answers::FIELD_PREFIX ] ) && is_array( $input[ Answers::FIELD_PREFIX ] )
			? $input[ Answers::FIELD_PREFIX ]
			: array();

		unset( $quantity );

		$position = self::ANSWER_POSITION;
		$given    = isset( $submitted[ $position ] ) && is_array( $submitted[ $position ] )
			? $submitted[ $position ]
			: array();

		$checked = Answers::check( $fields, $given, $position );

		foreach ( $checked['errors'] as $key => $message ) {
			$errors->add(
				'qevm_field_required',
				$message,
				array(
					'status' => 400,
					'field'  => Answers::input_id( $position, $key ),
				)
			);
		}

		return array() !== $checked['answers'] ? array( $position => $checked['answers'] ) : array();
	}

	/**
	 * The names submitted for the other places on a booking.
	 *
	 * Keyed by position, counting from 2 — position 1 is the booker, whose name
	 * is a required field of its own and is never read from here.
	 *
	 * **Names beyond the quantity are dropped, not honoured.** The number of
	 * places is what capacity was counted against, so a submission carrying
	 * twenty names and a quantity of one has to produce one place. Trusting the
	 * names instead would let anybody write as many attendee rows as they liked
	 * into an event with a capacity of one.
	 *
	 * An empty name is dropped rather than stored, because an unnamed place and
	 * a place named with a blank string are the same fact and only one of them
	 * should be in the database. Somebody booking three places for their team
	 * may not know yet who is coming, and refusing the booking over it would be
	 * worse than an unnamed ticket. See
	 * docs/adr/0004-registration-attendee-split.md.
	 *
	 * @since 26.0
	 *
	 * @param mixed $raw      Raw guest input, expected to be an array keyed by position.
	 * @param int   $quantity Places booked.
	 * @return array<int, string> Name by position; positions with no name are absent.
	 */
	public static function guest_names( $raw, $quantity ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$names    = array();
		$quantity = min( (int) $quantity, self::MAX_PLACES );

		for ( $position = 2; $position <= $quantity; $position++ ) {
			$value = isset( $raw[ $position ] ) ? $raw[ $position ] : '';

			// A richer per-guest shape lands with custom fields; accept it now so the form need not change again.
			if ( is_array( $value ) ) {
				$value = isset( $value['name'] ) ? $value['name'] : '';
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$name = trim( sanitize_text_field( (string) $value ) );

			if ( '' === $name ) {
				continue;
			}

			$names[ $position ] = mb_strlen( $name ) > 190 ? mb_substr( $name, 0, 190 ) : $name;
		}

		return $names;
	}

	/**
	 * One entry per place, in position order, for the attendee rows.
	 *
	 * The booker takes position 1 and is the only place with an email address
	 * on it: the form asks for one address, and inventing a guest's is worse
	 * than leaving it empty. A guest who wants their own ticket gains a way to
	 * say so when per-guest fields arrive.
	 *
	 * @since 26.0
	 *
	 * @param array{name: string, email: string, quantity: int, guests: array<int, string>} $fields Validated input.
	 * @return array<int, array<string, string>>
	 */
	public static function people( array $fields ) {
		$people = array(
			array(
				'name'  => (string) $fields['name'],
				'email' => (string) $fields['email'],
			),
		);

		$quantity = max( 1, (int) $fields['quantity'] );
		$guests   = $fields['guests'];

		for ( $position = 2; $position <= $quantity; $position++ ) {
			$people[] = array(
				'name'  => isset( $guests[ $position ] ) ? (string) $guests[ $position ] : '',
				'email' => '',
			);
		}

		return $people;
	}

	/**
	 * Attach each set of answers to the person who gave it.
	 *
	 * Matched on the attendee's position rather than on its place in the array,
	 * because a row that failed to write leaves a gap and answers must not slide
	 * onto the next person along. A guest's dietary requirement landing against
	 * somebody else is worse than it going missing.
	 *
	 * @since 26.0
	 *
	 * @param array<int, \QuickEventsManager\Registration\Attendee> $attendees Attendee rows created.
	 * @param array<int, array<string, string|string[]>>            $answers   Answers by position.
	 * @return void
	 */
	private static function store_answers( array $attendees, array $answers ) {
		if ( ! AnswerRepository::table_exists() ) {
			return;
		}

		foreach ( $attendees as $attendee ) {
			$position = (int) $attendee->position();

			if ( isset( $answers[ $position ] ) ) {
				AnswerRepository::save( $attendee->id(), $answers[ $position ] );
			}
		}
	}

	/**
	 * Refuse a visitor who is submitting too often.
	 *
	 * Keyed on a hash of the address rather than the address itself, so the
	 * plugin never stores an IP anywhere — including in the options table,
	 * where a transient would otherwise leave one sitting in plain text.
	 *
	 * @since 26.0
	 *
	 * @return true|\WP_Error
	 */
	private function check_rate_limit() {
		$key = $this->rate_limit_key();

		if ( '' === $key ) {
			return true;
		}

		/**
		 * Filter how many registrations one address may submit per window.
		 *
		 * Return 0 to switch rate limiting off entirely — reasonable on a
		 * site where registration happens at a staffed desk on one connection.
		 *
		 * @since 26.0
		 *
		 * @param int $limit Submissions allowed per RATE_WINDOW seconds.
		 */
		$limit = (int) apply_filters( 'qevm_registration_rate_limit', self::RATE_LIMIT );

		if ( $limit <= 0 ) {
			return true;
		}

		if ( (int) get_transient( $key ) >= $limit ) {
			return new \WP_Error(
				'qevm_rate_limited',
				__( 'Too many registration attempts. Please wait a few minutes and try again.', 'quick-events-manager' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Count one submission against the rate limit.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private function record_attempt() {
		$key = $this->rate_limit_key();

		if ( '' === $key ) {
			return;
		}

		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	/**
	 * The transient key identifying this visitor.
	 *
	 * @since 26.0
	 *
	 * @return string Empty when the address is unavailable.
	 */
	private function rate_limit_key() {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $address ) {
			return '';
		}

		return 'qevm_rl_' . substr( hash( 'sha256', $address . wp_salt() ), 0, 24 );
	}
}
