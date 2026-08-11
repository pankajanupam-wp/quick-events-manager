<?php
/**
 * JSON-LD markup describing an event as a schema.org Event.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Frontend;

use QuickEventsManager\Events\Event;
use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Emits JSON-LD so search engines can show event rich results.
 *
 * One of the few things an event plugin can do that a site owner cannot
 * reasonably do themselves, and the reason events show up in Google with a
 * date and a venue attached.
 *
 * @since 26.0
 */
final class Schema {

	/**
	 * Hook into the footer.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_footer', array( $this, 'output' ) );
	}

	/**
	 * Print the JSON-LD block on single event pages.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function output() {
		if ( ! is_singular( QEVM_POST_TYPE ) ) {
			return;
		}

		$event = new Event( get_queried_object_id() );

		if ( ! $event->is_valid() || '' === $event->start_utc() ) {
			return;
		}

		$data = $this->build( $event );

		if ( empty( $data ) ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>',
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Build the schema.org data for an event.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return array<string, mixed>
	 */
	public function build( Event $event ) {
		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => wp_strip_all_tags( get_the_title( $event->id() ) ),
			'url'                 => get_permalink( $event->id() ),
			'startDate'           => $this->iso8601( $event->start_utc(), $event->timezone(), $event->is_all_day() ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => $event->is_online()
				? 'https://schema.org/OnlineEventAttendanceMode'
				: 'https://schema.org/OfflineEventAttendanceMode',
		);

		if ( '' !== $event->end_utc() ) {
			$data['endDate'] = $this->iso8601( $event->end_utc(), $event->timezone(), $event->is_all_day() );
		}

		$description = wp_strip_all_tags( get_the_excerpt( $event->id() ) );

		if ( '' !== $description ) {
			$data['description'] = $description;
		}

		$image = get_the_post_thumbnail_url( $event->id(), 'large' );

		if ( $image ) {
			$data['image'] = array( $image );
		}

		$data['location'] = $event->is_online()
			? array(
				'@type' => 'VirtualLocation',
				'url'   => '' !== $event->online_url() ? $event->online_url() : get_permalink( $event->id() ),
			)
			: $this->place( $event );

		$organizer = (string) $event->meta( Meta::ORGANIZER_NAME );

		if ( '' !== $organizer ) {
			$data['organizer'] = array_filter(
				array(
					'@type' => 'Organization',
					'name'  => $organizer,
					'url'   => (string) $event->meta( Meta::ORGANIZER_URL ),
				)
			);
		}

		/**
		 * Filter the schema.org data emitted for an event.
		 *
		 * @since 26.0
		 *
		 * @param array $data  JSON-LD data.
		 * @param Event $event The event.
		 */
		return apply_filters( 'qevm_schema_data', $data, $event );
	}

	/**
	 * The venue as a schema.org Place.
	 *
	 * @since 26.0
	 *
	 * @param Event $event Event.
	 * @return array<string, mixed>
	 */
	private function place( Event $event ) {
		$address = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => (string) $event->meta( Meta::VENUE_ADDRESS ),
				'addressLocality' => (string) $event->meta( Meta::VENUE_CITY ),
				'addressRegion'   => (string) $event->meta( Meta::VENUE_REGION ),
				'postalCode'      => (string) $event->meta( Meta::VENUE_POSTAL ),
				'addressCountry'  => (string) $event->meta( Meta::VENUE_COUNTRY ),
			)
		);

		$name = (string) $event->meta( Meta::VENUE_NAME );

		return array_filter(
			array(
				'@type'   => 'Place',
				'name'    => '' !== $name ? $name : wp_strip_all_tags( get_the_title( $event->id() ) ),
				'address' => count( $address ) > 1 ? $address : '',
			)
		);
	}

	/**
	 * Format a stored UTC time as ISO 8601 in the event's own timezone.
	 *
	 * Google wants the local time with its offset — "2026-09-01T18:00:00+05:30"
	 * — rather than a UTC instant, because that is what it displays. All-day
	 * events use a bare date, which is how schema.org signals that there is no
	 * meaningful time of day.
	 *
	 * @since 26.0
	 *
	 * @param string $utc      Stored UTC datetime.
	 * @param string $timezone Event timezone.
	 * @param bool   $all_day  Whether the event is all day.
	 * @return string
	 */
	private function iso8601( $utc, $timezone, $all_day ) {
		try {
			$date = new \DateTime( $utc, new \DateTimeZone( 'UTC' ) );
			$date->setTimezone( new \DateTimeZone( $timezone ) );
		} catch ( \Exception $e ) {
			return '';
		}

		return $all_day ? $date->format( 'Y-m-d' ) : $date->format( 'c' );
	}
}
