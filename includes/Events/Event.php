<?php
/**
 * Read model for a single event.
 *
 * @package QuickEventsManager
 */

namespace QEM\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a WP_Post so callers ask questions instead of reading meta keys.
 *
 * Every template, block, shortcode and REST response goes through this, which
 * keeps the meta key names in one place and means date formatting is decided
 * once rather than in eight templates.
 *
 * @since 26.0
 */
final class Event {

	/**
	 * The underlying post.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Meta values, read once per event.
	 *
	 * @var array<string, mixed>|null
	 */
	private $meta = null;

	/**
	 * Wrap a post or post id.
	 *
	 * @since 26.0
	 *
	 * @param \WP_Post|int $post Event post or id.
	 */
	public function __construct( $post ) {
		$this->post = get_post( $post );
	}

	/**
	 * Whether this wraps a real event.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_valid() {
		return $this->post instanceof \WP_Post && QEM_POST_TYPE === $this->post->post_type;
	}

	/**
	 * Event id.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function id() {
		return $this->is_valid() ? (int) $this->post->ID : 0;
	}

	/**
	 * The underlying post.
	 *
	 * @since 26.0
	 *
	 * @return \WP_Post|null
	 */
	public function post() {
		return $this->post;
	}

	/**
	 * A single meta value.
	 *
	 * All the event's meta is fetched in one call the first time any key is
	 * asked for, so rendering a list of fifty events does not issue fifty
	 * queries per field.
	 *
	 * @since 26.0
	 *
	 * @param string $key     Meta key.
	 * @param mixed  $default Value to return when the key is absent.
	 * @return mixed
	 */
	public function meta( $key, $default = '' ) {
		if ( ! $this->is_valid() ) {
			return $default;
		}

		if ( null === $this->meta ) {
			$this->meta = get_post_meta( $this->id() );
		}

		if ( ! isset( $this->meta[ $key ][0] ) ) {
			return $default;
		}

		return $this->meta[ $key ][0];
	}

	/**
	 * The event's timezone, falling back to the site's.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function timezone() {
		$timezone = (string) $this->meta( Meta::TIMEZONE );

		return '' !== $timezone ? $timezone : Meta::site_timezone();
	}

	/**
	 * Start time in UTC.
	 *
	 * @since 26.0
	 *
	 * @return string `Y-m-d H:i:s`, or ''.
	 */
	public function start_utc() {
		return (string) $this->meta( Meta::START_UTC );
	}

	/**
	 * End time in UTC.
	 *
	 * @since 26.0
	 *
	 * @return string `Y-m-d H:i:s`, or ''.
	 */
	public function end_utc() {
		return (string) $this->meta( Meta::END_UTC );
	}

	/**
	 * Start time as the organiser typed it.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function start_local() {
		return (string) $this->meta( Meta::START_LOCAL );
	}

	/**
	 * End time as the organiser typed it.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function end_local() {
		return (string) $this->meta( Meta::END_LOCAL );
	}

	/**
	 * Whether the event runs all day.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_all_day() {
		return (bool) $this->meta( Meta::ALL_DAY, false );
	}

	/**
	 * Whether the event happens online.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_online() {
		return (bool) $this->meta( Meta::IS_ONLINE, false );
	}

	/**
	 * Joining URL for an online event.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function online_url() {
		return (string) $this->meta( Meta::ONLINE_URL );
	}

	/**
	 * Whether any location or organiser field has been filled in.
	 *
	 * Used to decide whether the editor's "Location and organiser" section
	 * starts open.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function has_location_details() {
		$keys = array(
			Meta::VENUE_NAME,
			Meta::VENUE_ADDRESS,
			Meta::VENUE_CITY,
			Meta::VENUE_REGION,
			Meta::VENUE_POSTAL,
			Meta::VENUE_COUNTRY,
			Meta::ORGANIZER_NAME,
			Meta::ORGANIZER_EMAIL,
		);

		foreach ( $keys as $key ) {
			if ( '' !== (string) $this->meta( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The venue as a single readable line.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function venue_summary() {
		$parts = array_filter(
			array(
				(string) $this->meta( Meta::VENUE_NAME ),
				(string) $this->meta( Meta::VENUE_ADDRESS ),
				(string) $this->meta( Meta::VENUE_CITY ),
				(string) $this->meta( Meta::VENUE_REGION ),
				(string) $this->meta( Meta::VENUE_POSTAL ),
				(string) $this->meta( Meta::VENUE_COUNTRY ),
			),
			static function ( $part ) {
				return '' !== $part;
			}
		);

		return implode( ', ', $parts );
	}

	/**
	 * Whether the event has already finished.
	 *
	 * Falls back to the start time for events with no end.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function has_ended() {
		$end = '' !== $this->end_utc() ? $this->end_utc() : $this->start_utc();

		if ( '' === $end ) {
			return false;
		}

		return $end < Meta::now_utc();
	}

	/**
	 * Whether the event is currently running.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_happening_now() {
		$start = $this->start_utc();

		if ( '' === $start ) {
			return false;
		}

		$end = '' !== $this->end_utc() ? $this->end_utc() : $start;
		$now = Meta::now_utc();

		return $start <= $now && $end >= $now;
	}

	/**
	 * The start time rendered for display, in the event's own timezone.
	 *
	 * @since 26.0
	 *
	 * @param string $format PHP date format. Defaults to the site's date and time format.
	 * @return string
	 */
	public function format_start( $format = '' ) {
		return $this->format( $this->start_utc(), $format );
	}

	/**
	 * The end time rendered for display, in the event's own timezone.
	 *
	 * @since 26.0
	 *
	 * @param string $format PHP date format. Defaults to the site's date and time format.
	 * @return string
	 */
	public function format_end( $format = '' ) {
		return $this->format( $this->end_utc(), $format );
	}

	/**
	 * The event's timezone abbreviation, for display next to a time.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function timezone_label() {
		try {
			$zone = new \DateTimeZone( $this->timezone() );
			$date = new \DateTime( '' !== $this->start_utc() ? $this->start_utc() : 'now', new \DateTimeZone( 'UTC' ) );
			$date->setTimezone( $zone );

			return $date->format( 'T' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Format a stored UTC datetime in the event's timezone.
	 *
	 * Uses date_i18n() so month and day names are translated, and passes it a
	 * timestamp already shifted into the event's zone — date_i18n() has no
	 * timezone argument and would otherwise use the site's.
	 *
	 * @since 26.0
	 *
	 * @param string $utc    Stored UTC datetime.
	 * @param string $format PHP date format, or '' for the site default.
	 * @return string
	 */
	private function format( $utc, $format = '' ) {
		if ( '' === $utc ) {
			return '';
		}

		if ( '' === $format ) {
			$format = $this->is_all_day()
				? get_option( 'date_format' )
				: get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		try {
			$date = new \DateTime( $utc, new \DateTimeZone( 'UTC' ) );
			$zone = new \DateTimeZone( $this->timezone() );
		} catch ( \Exception $e ) {
			return '';
		}

		$offset = $zone->getOffset( $date );

		return date_i18n( $format, $date->getTimestamp() + $offset, true );
	}
}
