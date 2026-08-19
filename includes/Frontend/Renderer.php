<?php
/**
 * The render functions blocks and shortcodes share.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Frontend;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\OccurrenceRepository;
use QuickEventsManager\Events\Query;
use QuickEventsManager\Privacy\Consent;
use QuickEventsManager\Registration\CancellationHandler;
use QuickEventsManager\Registration\FormHandler;
use QuickEventsManager\Registration\RegistrationService;

defined( 'ABSPATH' ) || exit;

/**
 * One implementation per piece of front-end output.
 *
 * The `qevm/event-list` block and the `[qevm_event_list]` shortcode both call
 * event_list(). Two entry points, one renderer — so markup, escaping and
 * capability decisions are made in exactly one place per feature.
 *
 * @since 26.0
 */
final class Renderer {

	/**
	 * Render a list of events.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $atts Display attributes.
	 * @return string
	 */
	public static function event_list( array $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'limit'    => (int) Settings::get( 'archive_per_page' ),
				'show'     => 'upcoming',
				'category' => '',
				'columns'  => 2,
			),
			$atts,
			'qevm_event_list'
		);

		$args = array(
			'posts_per_page' => max( 1, min( 100, (int) $atts['limit'] ) ),
		);

		if ( '' !== $atts['category'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- A slug lookup against wp_term_relationships, which is indexed on both columns it joins.
			$args['tax_query'] = array(
				array(
					'taxonomy' => QEVM_TAX_CATEGORY,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', explode( ',', $atts['category'] ) ),
				),
			);
		}

		$query = new \WP_Query(
			'past' === $atts['show']
				? Query::past_args( $args )
				: Query::upcoming_args( $args )
		);

		Assets::enqueue_frontend();

		$markup = Templates::render(
			'event-list.php',
			array(
				'query'   => $query,
				'columns' => max( 1, min( 4, (int) $atts['columns'] ) ),
				'show'    => $atts['show'],
			)
		);

		wp_reset_postdata();

		return $markup;
	}

	/**
	 * Render one event's date, time and location.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $atts Display attributes.
	 * @return string
	 */
	public static function event_details( array $atts = array() ) {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			$atts,
			'qevm_event_details'
		);

		$event = new Event( (int) $atts['id'] > 0 ? (int) $atts['id'] : get_the_ID() );

		if ( ! $event->is_valid() ) {
			return '';
		}

		Assets::enqueue_frontend();

		return Templates::render( 'event-details.php', array( 'event' => $event ) );
	}

	/**
	 * Render the registration form, or whatever should stand in for it.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $atts Display attributes.
	 * @return string
	 */
	public static function registration_form( array $atts = array() ) {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			$atts,
			'qevm_event_registration'
		);

		/*
		 * The module gate belongs here rather than in the caller, because
		 * three separate things render this form — the shortcode, the block,
		 * and the automatic injection on single event pages. Checking at the
		 * render boundary is the only way all three stay honest, and the
		 * absence of this check meant a site with registration switched off
		 * still showed a working form on every event.
		 */
		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\Registration\RegistrationModule::ID ) ) {
			return '';
		}

		$event = new Event( (int) $atts['id'] > 0 ? (int) $atts['id'] : get_the_ID() );

		if ( ! $event->is_valid() ) {
			return '';
		}

		/*
		 * Cancellation comes before the "is registration open" gate, and that
		 * order is the whole point. Registration closes as an event approaches
		 * and an event fills up — which is exactly when somebody who cannot
		 * come needs to say so, and exactly when the place they free is worth
		 * the most. Gating the panel behind is_open() would hide the
		 * cancellation link at the only time it matters.
		 */
		if ( CancellationHandler::is_cancellation_request() ) {
			$state = CancellationHandler::current_state();

			if ( null !== $state ) {
				Assets::enqueue_frontend();

				return Templates::render(
					'cancellation.php',
					array(
						'state' => $state,
						'event' => CancellationHandler::event_for( $state ) ?? $event,
					)
				);
			}
		}

		$closed = RegistrationService::closed_reason( $event );

		if ( '' !== $closed ) {
			return self::closed_notice( $event, $closed );
		}

		Assets::enqueue_frontend();
		Assets::enqueue_registration_form();

		$result = FormHandler::current_result();

		$dates = self::bookable_dates( $event );

		return Templates::render(
			'registration-form.php',
			array(
				'event'        => $event,
				'dates'        => $dates,

				/*
				 * On a series these two are questions about a date, and no date
				 * has been chosen yet — so the form says nothing about how full
				 * anything is, and each option carries its own state instead.
				 * Answering for the event as a whole would put "3 places left"
				 * above a list of twelve weeks with twenty places each.
				 */
				'is_full'      => array() === $dates && RegistrationService::is_full( $event ),
				'remaining'    => array() === $dates ? RegistrationService::places_remaining( $event ) : null,
				'result'       => $result,
				'max_places'   => RegistrationService::MAX_PLACES,
				'consent_text' => Consent::text(),
				'fields'       => self::custom_fields( $event ),
				'field_values' => self::submitted_answers( $result ),
				'field_errors' => self::answer_errors( $result, $event ),
			)
		);
	}

	/**
	 * The dates somebody may choose between, or none when there is no choice.
	 *
	 * Empty for an event with a single date, which is the signal the form uses
	 * to leave the picker out altogether — one date is not a choice, and asking
	 * somebody to pick from a list of one is a question with a wrong answer
	 * available.
	 *
	 * Full dates stay on the list. A full date takes waiting-list bookings, and
	 * removing it would leave somebody staring at a gap in the weeks with no
	 * way to ask for the place if one comes free.
	 *
	 * @since 26.0
	 *
	 * @param Event $event The event.
	 * @return array<int, array{id: int, label: string, full: bool}>
	 */
	private static function bookable_dates( Event $event ) {
		$occurrences = OccurrenceRepository::for_event( $event->id() );

		if ( count( $occurrences ) <= 1 ) {
			return array();
		}

		$now   = Meta::now_utc();
		$dates = array();

		foreach ( $occurrences as $occurrence ) {
			if ( ! $occurrence->is_listable() || $occurrence->has_ended( $now ) ) {
				continue;
			}

			$dates[] = array(
				'id'    => $occurrence->id(),
				'label' => $occurrence->format_start(),
				'full'  => RegistrationService::is_full( $event, $occurrence->id() ),
			);
		}

		return $dates;
	}

	/**
	 * Render a month of events, as a grid or as a list.
	 *
	 * The two views are equals rather than a feature and its fallback. They read
	 * the same month object, so the same events are in both by construction and
	 * not by two queries that have to be kept in step, and each links to the
	 * other. Which one somebody is looking at travels in the URL, so choosing
	 * the list and then stepping to the next month does not throw them back into
	 * a grid — a "first-class equivalent" that forgets itself on every click is
	 * a fallback with better wording.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $atts Display attributes.
	 * @return string
	 */
	public static function calendar( array $atts = array() ) {
		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\Calendar\CalendarModule::ID ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'month' => '',
				'view'  => '',
			),
			$atts,
			'qevm_event_calendar'
		);

		/*
		 * The month and the view both come from the URL as well as from the
		 * attributes, so a link to a particular month in a particular view
		 * works and the navigation built on top of it does too. Anything
		 * unparseable falls back rather than erroring — a mistyped URL should
		 * show this month, not a stack trace.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which month and view to display changes nothing.
		$requested_month = isset( $_GET['qevm_month'] ) ? sanitize_text_field( wp_unslash( $_GET['qevm_month'] ) ) : (string) $atts['month'];
		$requested_view  = isset( $_GET['qevm_view'] ) ? sanitize_key( wp_unslash( $_GET['qevm_view'] ) ) : (string) $atts['view'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$month = \QuickEventsManager\Calendar\Month::from_string( $requested_month );
		$view  = self::calendar_view( $requested_view );

		Assets::enqueue_frontend();
		Assets::enqueue_calendar();

		return Templates::render(
			'list' === $view ? 'calendar-list.php' : 'calendar-month.php',
			array(
				'month'    => $month,
				'view'     => $view,
				'list_url' => self::calendar_url( $month, 'list' ),
				'grid_url' => self::calendar_url( $month, 'grid' ),
			)
		);
	}

	/**
	 * Which of the two views to render.
	 *
	 * @since 26.0
	 *
	 * @param string $requested Requested view, from the URL or an attribute.
	 * @return string grid | list
	 */
	private static function calendar_view( $requested ) {
		if ( in_array( $requested, array( 'grid', 'list' ), true ) ) {
			return $requested;
		}

		/**
		 * Filters which calendar view a visitor sees when they have not chosen.
		 *
		 * Return 'list' to make the list the default. Some audiences are better
		 * served by it, and a site that knows that should be able to say so
		 * without asking every visitor to switch every time.
		 *
		 * @since 26.0
		 *
		 * @param string $view grid | list.
		 */
		$default = apply_filters( 'qevm_calendar_default_view', 'grid' );

		return 'list' === $default ? 'list' : 'grid';
	}

	/**
	 * A link to one month in one view, keeping the rest of the URL intact.
	 *
	 * @since 26.0
	 *
	 * @param \QuickEventsManager\Calendar\Month $month Month to link to.
	 * @param string                             $view  grid | list.
	 * @return string
	 */
	public static function calendar_url( \QuickEventsManager\Calendar\Month $month, $view ) {
		return (string) add_query_arg(
			array(
				'qevm_month' => $month->key(),
				'qevm_view'  => 'list' === $view ? 'list' : 'grid',
			)
		);
	}

	/**
	 * The custom questions an event asks, or none.
	 *
	 * Gated on the module here, at the render boundary, for the same reason the
	 * registration form itself is: three things reach this template and a check
	 * repeated in each of them is a check that will be missed in one.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return \QuickEventsManager\CustomFields\Field[]
	 */
	private static function custom_fields( Event $event ) {
		if ( ! \QuickEventsManager\Plugin::instance()->registry()->is_enabled( \QuickEventsManager\CustomFields\CustomFieldsModule::ID ) ) {
			return array();
		}

		return \QuickEventsManager\CustomFields\Definitions::for_event( $event->id() );
	}

	/**
	 * Answers from a rejected submission, so the form comes back filled in.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed>|null $result Previous outcome.
	 * @return array<string, string|string[]>
	 */
	private static function submitted_answers( $result ) {
		$position  = RegistrationService::ANSWER_POSITION;
		$submitted = FormHandler::value( $result, \QuickEventsManager\CustomFields\Answers::FIELD_PREFIX, array() );

		if ( ! is_array( $submitted ) || ! isset( $submitted[ $position ] ) || ! is_array( $submitted[ $position ] ) ) {
			return array();
		}

		return $submitted[ $position ];
	}

	/**
	 * Messages for the questions a rejected submission got wrong.
	 *
	 * Errors are carried against the input's id, which is unique per guest.
	 * The template asks by field key, so they are turned back here rather than
	 * in the template, where the mapping would be repeated by every theme that
	 * overrode it.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed>|null $result Previous outcome.
	 * @param Event                     $event  Event.
	 * @return array<string, string>
	 */
	private static function answer_errors( $result, Event $event ) {
		$errors = array();

		foreach ( self::custom_fields( $event ) as $field ) {
			$id      = \QuickEventsManager\CustomFields\Answers::input_id( RegistrationService::ANSWER_POSITION, $field->key() );
			$message = FormHandler::error( $result, $id );

			if ( '' !== $message ) {
				$errors[ $field->key() ] = $message;
			}
		}

		return $errors;
	}

	/**
	 * The explanation that stands in for a form which is no longer open.
	 *
	 * Only for the two reasons a visitor can act on. Registration that was
	 * never switched on gets nothing, because nothing was ever offered and an
	 * "unavailable" notice on every event that does not take bookings is
	 * noise. A reason supplied by somebody else's filter gets nothing either —
	 * that code closed registration for a reason only it knows, and inventing
	 * an explanation on its behalf would be worse than silence.
	 *
	 * @since 26.0
	 *
	 * @param Event  $event  Event.
	 * @param string $reason From RegistrationService::closed_reason().
	 * @return string
	 */
	private static function closed_notice( Event $event, $reason ) {
		$messages = array(
			'ended'   => __( 'This event has already taken place, so registration is closed.', 'quick-events-manager' ),
			'expired' => __( 'Registration for this event has closed.', 'quick-events-manager' ),
		);

		/**
		 * Filters the explanation shown in place of a closed registration form.
		 *
		 * Return an empty string for a reason to show nothing for it, or add a
		 * key to explain a reason of your own.
		 *
		 * @since 26.0
		 *
		 * @param array<string, string> $messages Message by reason.
		 * @param Event                 $event    The event.
		 * @param string                $reason   The reason registration is closed.
		 */
		$messages = (array) apply_filters( 'qevm_registration_closed_notice', $messages, $event, $reason );

		if ( empty( $messages[ $reason ] ) ) {
			return '';
		}

		Assets::enqueue_frontend();

		return Templates::render(
			'registration-closed.php',
			array(
				'event'   => $event,
				'reason'  => $reason,
				'message' => (string) $messages[ $reason ],
			)
		);
	}
}
