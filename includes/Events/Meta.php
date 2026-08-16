<?php
/**
 * Event meta: what is stored, how it is sanitised, and the timezone rules.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the event meta keys and converts between local and UTC time.
 *
 * The rule this class exists to enforce: **UTC is the only thing ever sorted
 * or queried on**. An event also stores the wall-clock time the organiser
 * typed and the timezone they meant it in, and those two are what gets
 * displayed. Storing only local time is the classic event-plugin bug — every
 * event shifts the day someone changes the site timezone.
 *
 * @since 26.0
 */
final class Meta {

	/**
	 * The canonical sortable start time, `Y-m-d H:i:s` in UTC.
	 */
	const START_UTC = '_qevm_start_utc';

	/**
	 * The canonical sortable end time, `Y-m-d H:i:s` in UTC.
	 */
	const END_UTC = '_qevm_end_utc';

	/**
	 * Start time as the organiser typed it, `Y-m-d H:i:s`.
	 */
	const START_LOCAL = '_qevm_start_local';

	/**
	 * End time as the organiser typed it, `Y-m-d H:i:s`.
	 */
	const END_LOCAL = '_qevm_end_local';

	/**
	 * PHP timezone identifier the local times are expressed in.
	 */
	const TIMEZONE = '_qevm_timezone';

	/**
	 * Whether the event has no meaningful time of day.
	 */
	const ALL_DAY = '_qevm_all_day';

	/**
	 * Whether the event happens online.
	 */
	const IS_ONLINE = '_qevm_is_online';

	/**
	 * Joining URL for an online event.
	 */
	const ONLINE_URL = '_qevm_online_url';

	/**
	 * Venue fields.
	 */
	const VENUE_NAME    = '_qevm_venue_name';
	const VENUE_ADDRESS = '_qevm_venue_address';
	const VENUE_CITY    = '_qevm_venue_city';
	const VENUE_REGION  = '_qevm_venue_region';
	const VENUE_POSTAL  = '_qevm_venue_postal_code';
	const VENUE_COUNTRY = '_qevm_venue_country';

	/**
	 * Reserved for the reusable-venues module.
	 *
	 * 26.0 stores venues as flat fields on the event, which is all a single
	 * event needs. Registering the id now means the module that turns
	 * venues into reusable records can populate it without a schema change or
	 * a second migration.
	 */
	const VENUE_ID = '_qevm_venue_id';

	/**
	 * The reusable organiser this event points at, or 0.
	 *
	 * The counterpart to VENUE_ID, and read only while the organisers module
	 * is enabled. The event's own organiser fields below are kept whatever the
	 * module is doing, so an event never loses its contact details.
	 */
	const ORGANIZER_ID = '_qevm_organizer_id';

	/**
	 * Organizer fields.
	 */
	const ORGANIZER_NAME  = '_qevm_organizer_name';
	const ORGANIZER_EMAIL = '_qevm_organizer_email';
	const ORGANIZER_PHONE = '_qevm_organizer_phone';
	const ORGANIZER_URL   = '_qevm_organizer_url';

	/**
	 * Registration fields, written by the Registration module.
	 */
	const CAPACITY             = '_qevm_capacity';
	const REGISTRATION_CLOSES  = '_qevm_registration_closes_utc';
	const REGISTRATION_ENABLED = '_qevm_registration_enabled';

	/**
	 * The format every stored datetime uses.
	 */
	const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Register every meta key with core.
	 *
	 * Registering rather than writing raw post meta buys sanitisation on the
	 * way in, an authorisation callback, and REST exposure for the block
	 * editor — all of which would otherwise have to be hand-rolled per key.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( self::definitions() as $key => $definition ) {
			register_post_meta(
				QEVM_POST_TYPE,
				$key,
				array(
					'type'              => $definition['type'],
					'single'            => true,
					'default'           => $definition['default'],
					'show_in_rest'      => true,
					'sanitize_callback' => $definition['sanitize'],
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Every meta key with its type, default and sanitiser.
	 *
	 * @since 26.0
	 *
	 * @return array<string, array{type: string, default: mixed, sanitize: callable}>
	 */
	public static function definitions() {
		$text    = 'sanitize_text_field';
		$boolean = array( __CLASS__, 'sanitize_boolean' );

		return array(
			self::START_UTC            => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
			),
			self::END_UTC              => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
			),
			self::START_LOCAL          => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
			),
			self::END_LOCAL            => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
			),
			self::TIMEZONE             => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_timezone' ),
			),
			self::ALL_DAY              => array(
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => $boolean,
			),
			self::IS_ONLINE            => array(
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => $boolean,
			),
			self::ONLINE_URL           => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'esc_url_raw',
			),
			self::VENUE_NAME           => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::VENUE_ADDRESS        => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::VENUE_CITY           => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::VENUE_REGION         => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::VENUE_POSTAL         => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::VENUE_COUNTRY        => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::VENUE_ID             => array(
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => 'absint',
			),
			self::ORGANIZER_ID         => array(
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => 'absint',
			),
			self::ORGANIZER_NAME       => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::ORGANIZER_EMAIL      => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'sanitize_email',
			),
			self::ORGANIZER_PHONE      => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => $text,
			),
			self::ORGANIZER_URL        => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'esc_url_raw',
			),
			self::CAPACITY             => array(
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => 'absint',
			),
			self::REGISTRATION_CLOSES  => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
			),
			self::REGISTRATION_ENABLED => array(
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => $boolean,
			),
		);
	}

	/**
	 * Normalise a datetime string, or return '' if it is not one.
	 *
	 * Anything that is not exactly `Y-m-d H:i:s` is rejected rather than
	 * coerced: a half-understood date is worse than an absent one, because it
	 * sorts into the wrong place silently.
	 *
	 * @since 26.0
	 *
	 * @param mixed $value Raw value.
	 * @return string Normalised datetime, or ''.
	 */
	public static function sanitize_datetime( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$value = trim( $value );
		$date  = \DateTime::createFromFormat( self::FORMAT, $value, new \DateTimeZone( 'UTC' ) );

		if ( false === $date || $date->format( self::FORMAT ) !== $value ) {
			return '';
		}

		return $value;
	}

	/**
	 * Accept a timezone only if PHP recognises it.
	 *
	 * @since 26.0
	 *
	 * @param mixed $value Raw value.
	 * @return string Valid timezone identifier, or ''.
	 */
	public static function sanitize_timezone( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( ! in_array( $value, timezone_identifiers_list(), true ) ) {
			/*
			 * Sites configured with a raw UTC offset rather than a city have
			 * a timezone_string of '' and an offset like "+05:30", which core
			 * accepts as a DateTimeZone but which is not in the identifier
			 * list. Allow that shape through.
			 */
			if ( ! preg_match( '/^[+-]\d{2}:\d{2}$/', $value ) ) {
				return '';
			}
		}

		return $value;
	}

	/**
	 * Coerce a checkbox-ish value to a real boolean.
	 *
	 * @since 26.0
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_boolean( $value ) {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Convert a wall-clock time in a given zone to UTC.
	 *
	 * @since 26.0
	 *
	 * @param string $local    Local datetime, `Y-m-d H:i:s`.
	 * @param string $timezone PHP timezone identifier.
	 * @return string UTC datetime in the same format, or '' if the input was unusable.
	 */
	public static function to_utc( $local, $timezone ) {
		$local = self::sanitize_datetime( $local );

		if ( '' === $local ) {
			return '';
		}

		try {
			$zone = new \DateTimeZone( '' !== $timezone ? $timezone : 'UTC' );
			$date = new \DateTime( $local, $zone );
		} catch ( \Exception $e ) {
			return '';
		}

		$date->setTimezone( new \DateTimeZone( 'UTC' ) );

		return $date->format( self::FORMAT );
	}

	/**
	 * Convert a UTC time to wall-clock time in a given zone.
	 *
	 * @since 26.0
	 *
	 * @param string $utc      UTC datetime, `Y-m-d H:i:s`.
	 * @param string $timezone PHP timezone identifier.
	 * @return string Local datetime in the same format, or '' if the input was unusable.
	 */
	public static function to_local( $utc, $timezone ) {
		$utc = self::sanitize_datetime( $utc );

		if ( '' === $utc ) {
			return '';
		}

		try {
			$date = new \DateTime( $utc, new \DateTimeZone( 'UTC' ) );
			$date->setTimezone( new \DateTimeZone( '' !== $timezone ? $timezone : 'UTC' ) );
		} catch ( \Exception $e ) {
			return '';
		}

		return $date->format( self::FORMAT );
	}

	/**
	 * The timezone an event with no explicit zone should use.
	 *
	 * Falls back through the site's timezone string, then its raw GMT offset,
	 * then UTC. `wp_timezone_string()` only exists from WordPress 5.3, and the
	 * plugin supports 5.0, so the offset branch is reachable in practice.
	 *
	 * @since 26.0
	 *
	 * @return string PHP timezone identifier or an offset like "+05:30".
	 */
	public static function site_timezone() {
		$timezone = get_option( 'timezone_string' );

		if ( is_string( $timezone ) && '' !== $timezone ) {
			return $timezone;
		}

		$offset  = (float) get_option( 'gmt_offset', 0 );
		$hours   = (int) $offset;
		$minutes = abs( ( $offset - $hours ) * 60 );

		return sprintf( '%+03d:%02d', $hours, $minutes );
	}

	/**
	 * The current time in UTC, in the stored format.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function now_utc() {
		return gmdate( self::FORMAT );
	}
}
