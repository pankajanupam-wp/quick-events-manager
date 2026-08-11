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
	 * @param array $atts Display attributes.
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
	 * @param array $atts Display attributes.
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
	 * @param array $atts Display attributes.
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

		if ( ! RegistrationService::is_open( $event ) ) {
			return '';
		}

		Assets::enqueue_frontend();

		return Templates::render(
			'registration-form.php',
			array(
				'event'     => $event,
				'is_full'   => RegistrationService::is_full( $event ),
				'remaining' => RegistrationService::places_remaining( $event ),
				'result'    => FormHandler::current_result(),
			)
		);
	}
}
