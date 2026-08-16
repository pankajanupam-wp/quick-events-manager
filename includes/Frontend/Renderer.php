<?php
/**
 * The render functions blocks and shortcodes share.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Frontend;

use QuickEventsManager\Admin\Settings;
use QuickEventsManager\Events\Event;
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

		return Templates::render(
			'registration-form.php',
			array(
				'event'        => $event,
				'is_full'      => RegistrationService::is_full( $event ),
				'remaining'    => RegistrationService::places_remaining( $event ),
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
	 * Render a month of events as a grid.
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
			array( 'month' => '' ),
			$atts,
			'qevm_event_calendar'
		);

		/*
		 * The requested month comes from the URL as well as the attribute, so a
		 * link to a particular month works and so does the navigation built on
		 * top of it. Anything unparseable falls back to now rather than erroring
		 * — a mistyped URL should show this month, not a stack trace.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which month to display changes nothing.
		$requested = isset( $_GET['qevm_month'] ) ? sanitize_text_field( wp_unslash( $_GET['qevm_month'] ) ) : (string) $atts['month'];

		$month = \QuickEventsManager\Calendar\Month::from_string( $requested );

		Assets::enqueue_frontend();

		return Templates::render(
			'calendar-month.php',
			array(
				'month'    => $month,
				'list_url' => add_query_arg(
					array(
						'qevm_month' => $month->key(),
						'qevm_view'  => 'list',
					)
				),
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
