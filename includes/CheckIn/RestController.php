<?php
/**
 * Checking somebody in from something that is not this admin screen.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CheckIn;

use QuickEventsManager\Events\OccurrenceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The one write route this plugin has, and the reason it exists.
 *
 * The plugin's REST contract says read-only, and means it: events are written through
 * core's own `/wp/v2/qevm_event`, so hand-rolling a second write path would be
 * a second permission surface to audit for no gain.
 *
 * A check-in is different, and the difference is not "we changed our minds".
 * Core has no route for it because the table is this plugin's, the decision is
 * this plugin's, and the thing that needs to make it is a scanner at a door
 * that is not a browser holding an admin cookie — a phone app, a second screen
 * on a laptop, a hardware reader posting from a kiosk. The alternative to a
 * route is that everybody screen-scrapes admin-post.php, which is worse in
 * every direction.
 *
 * Registered by this module, so with check-in switched off the route does not
 * exist rather than returning a permission error.
 *
 * @since 26.0
 */
final class RestController {

	/**
	 * Namespace, shared with the read routes.
	 */
	const NAMESPACE = 'qevm/v1';

	/**
	 * Hook in.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/checkins',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'admit' ),
					'permission_callback' => array( $this, 'may_check_in' ),
					'args'                => array(
						'ticket_code' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => static function ( $value ) {
								return strtoupper( sanitize_text_field( (string) $value ) );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)/attendance',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'attendance' ),
					'permission_callback' => array( $this, 'may_check_in' ),
					'args'                => array(
						'id'            => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'occurrence_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Whether this request may work a door.
	 *
	 * The same capability the screen uses. A door is a door whether the person
	 * holding it is looking at wp-admin or at something they wrote themselves.
	 *
	 * @since 26.0
	 *
	 * @return true|\WP_Error
	 */
	public function may_check_in() {
		if ( current_user_can( 'manage_qevm_checkins' ) ) {
			return true;
		}

		return new \WP_Error(
			'qevm_cannot_check_in',
			__( 'You do not have permission to check people in.', 'quick-events-manager' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Admit whoever holds a ticket code.
	 *
	 * Returns 200 for an arrival **and** for somebody already in, because both
	 * mean "this person is admitted" and a scanner that treats the second scan
	 * as an error is a scanner that beeps angrily at a queue. The `result` field
	 * says which happened.
	 *
	 * @since 26.0
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admit( $request ) {
		$outcome = CheckInService::admit_by_code(
			(string) $request->get_param( 'ticket_code' ),
			get_current_user_id(),
			'qr'
		);

		$attendee = $outcome['attendee'];
		$checkin  = $outcome['checkin'];

		if ( CheckInService::REFUSED === $outcome['result'] ) {
			return new \WP_Error(
				'qevm_not_admitted',
				$outcome['message'],
				array(
					'status' => null === $attendee ? 404 : 409,
					'result' => $outcome['result'],
				)
			);
		}

		return new \WP_REST_Response(
			array(
				'result'        => $outcome['result'],
				'message'       => $outcome['message'],
				'attendee'      => null !== $attendee ? $attendee->id() : 0,
				'name'          => null !== $attendee ? $attendee->name() : '',
				'ticket_code'   => null !== $attendee ? $attendee->ticket_code() : '',
				'occurrence_id' => null !== $checkin ? $checkin->occurrence_id() : 0,
				'checked_in_at' => null !== $checkin ? $checkin->checked_in_at() : '',
			),
			200
		);
	}

	/**
	 * How the door is going.
	 *
	 * The numbers an organiser asks for during an event and the report they
	 * want after it: how many were expected, how many came, and who did not.
	 *
	 * @since 26.0
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function attendance( $request ) {
		$event_id      = (int) $request->get_param( 'id' );
		$occurrence_id = (int) $request->get_param( 'occurrence_id' );

		if ( 0 === $occurrence_id ) {
			$next = OccurrenceRepository::next_for_event( $event_id );

			$occurrence_id = null !== $next ? $next->id() : 0;
		}

		$expected = apply_filters( 'qevm_expected_attendees', array(), $event_id, $occurrence_id, '' );
		$expected = is_array( $expected ) ? $expected : array();
		$people   = array();
		$present  = 0;

		foreach ( $expected as $attendee ) {
			$checkin = CheckInRepository::find( $attendee->id(), $occurrence_id );
			$in      = null !== $checkin && ! $checkin->is_reversed();

			if ( $in ) {
				++$present;
			}

			$people[] = array(
				'attendee'      => $attendee->id(),
				'name'          => $attendee->name(),
				'ticket_code'   => $attendee->ticket_code(),
				'present'       => $in,
				'checked_in_at' => $in ? $checkin->checked_in_at() : '',
			);
		}

		return new \WP_REST_Response(
			array(
				'event_id'      => $event_id,
				'occurrence_id' => $occurrence_id,
				'expected'      => count( $people ),
				'present'       => $present,

				/*
				 * Everybody, with a flag, rather than two lists. The question
				 * after an event is "who did not come", and during one it is
				 * "who is still outside" — both are this list filtered, and
				 * neither is worth a second shape.
				 */
				'attendees'     => $people,
			),
			200
		);
	}
}
