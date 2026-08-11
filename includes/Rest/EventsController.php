<?php
/**
 * Read endpoints for events.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Rest;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;
use QuickEventsManager\Events\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `qevm/v1/events` — a shaped, public read API.
 *
 * Write endpoints are deliberately absent. The post type is registered with
 * `show_in_rest`, so core already serves authenticated CRUD at
 * `/wp/v2/qevm_event` with permission handling the block editor relies on.
 * Hand-rolling a second write path would mean a second permission surface to
 * audit for no benefit.
 *
 * What core does not give is an event-shaped read: dates resolved into the
 * event's own timezone, venue flattened, upcoming/past filtering. That is what
 * lives here.
 *
 * @since 26.0
 */
final class EventsController {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'qevm/v1';

	/**
	 * Hook into REST initialisation.
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
			'/events',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'show'     => array(
							'type'    => 'string',
							'enum'    => array( 'upcoming', 'past', 'all' ),
							'default' => 'upcoming',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 10,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),

						/*
						 * Wrapped rather than passed as the bare function name.
						 * WordPress invokes a sanitize_callback as
						 * ( $value, $request, $param ), and sanitize_title()'s
						 * second parameter is $fallback_title — which it
						 * returns when the title sanitises to empty. Passing
						 * 'sanitize_title' directly therefore hands back the
						 * WP_REST_Request object for any empty category, and
						 * the next thing to treat it as a string fatals.
						 */
						'category' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => static function ( $value ) {
								return sanitize_title( (string) $value );
							},
						),
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => static function ( $value ) {
								return sanitize_text_field( (string) $value );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * List events.
	 *
	 * @since 26.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = array(
			'posts_per_page' => (int) $request->get_param( 'per_page' ),
			'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
		);

		/*
		 * Read through get_param() and cast, rather than trusting the array
		 * accessor to hand back the registered default. An absent parameter
		 * comes back as null, and `'' !== null` is true — which was enough to
		 * build a tax_query with empty terms and fatal inside WP_Tax_Query on
		 * every unfiltered request.
		 */
		$search   = (string) $request->get_param( 'search' );
		$category = (string) $request->get_param( 'category' );
		$show     = (string) $request->get_param( 'show' );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( '' !== $category ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- A single-term slug lookup against wp_term_relationships, which is indexed on both columns it joins.
			$args['tax_query'] = array(
				array(
					'taxonomy' => QEVM_TAX_CATEGORY,
					'field'    => 'slug',
					'terms'    => array( $category ),
				),
			);
		}

		if ( 'past' === $show ) {
			$args = Query::past_args( $args );
		} elseif ( 'upcoming' === $show ) {
			$args = Query::upcoming_args( $args );
		} else {
			$args = array_merge(
				array(
					'post_type'   => QEVM_POST_TYPE,
					'post_status' => 'publish',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by a meta value; the occurrence table replaces this. See the note in Events/Query.php.
					'meta_key'    => Meta::START_UTC,
					'orderby'     => 'meta_value',
					'order'       => 'ASC',
				),
				$args
			);
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare( new Event( $post ) );
		}

		$response = rest_ensure_response( $items );

		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * Fetch one event.
	 *
	 * @since 26.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$event = new Event( (int) $request->get_param( 'id' ) );

		if ( ! $event->is_valid() || 'publish' !== get_post_status( $event->id() ) ) {
			return new \WP_Error(
				'qevm_event_not_found',
				__( 'That event could not be found.', 'quick-events-manager' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare( $event ) );
	}

	/**
	 * Shape an event for the API.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return array
	 */
	private function prepare( Event $event ) {
		$data = array(
			'id'         => $event->id(),
			'title'      => get_the_title( $event->id() ),
			'excerpt'    => wp_strip_all_tags( get_the_excerpt( $event->id() ) ),
			'url'        => get_permalink( $event->id() ),
			'image'      => (string) get_the_post_thumbnail_url( $event->id(), 'large' ),
			'start_utc'  => $event->start_utc(),
			'end_utc'    => $event->end_utc(),
			'timezone'   => $event->timezone(),
			'all_day'    => $event->is_all_day(),
			'is_online'  => $event->is_online(),
			'has_ended'  => $event->has_ended(),
			'venue'      => array(
				'name'    => (string) $event->meta( Meta::VENUE_NAME ),
				'address' => (string) $event->meta( Meta::VENUE_ADDRESS ),
				'city'    => (string) $event->meta( Meta::VENUE_CITY ),
				'region'  => (string) $event->meta( Meta::VENUE_REGION ),
				'postal'  => (string) $event->meta( Meta::VENUE_POSTAL ),
				'country' => (string) $event->meta( Meta::VENUE_COUNTRY ),
			),
			'organizer'  => array(
				'name' => (string) $event->meta( Meta::ORGANIZER_NAME ),
				'url'  => (string) $event->meta( Meta::ORGANIZER_URL ),
			),
			'categories' => wp_get_post_terms( $event->id(), QEVM_TAX_CATEGORY, array( 'fields' => 'names' ) ),
		);

		/*
		 * The joining link is only exposed to someone who can edit the event.
		 * A public meeting URL is an open door into the meeting itself, and
		 * an API that hands it out to anonymous callers is worse than one that
		 * makes them open the page.
		 */
		if ( current_user_can( 'edit_post', $event->id() ) ) {
			$data['online_url'] = $event->online_url();
		}

		/**
		 * Filter an event's REST representation.
		 *
		 * @since 26.0
		 *
		 * @param array $data  Prepared data.
		 * @param Event $event The event.
		 */
		return apply_filters( 'qevm_rest_prepare_event', $data, $event );
	}
}
