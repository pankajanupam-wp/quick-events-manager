<?php
/**
 * Reading and writing an event's custom questions.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CustomFields;

defined( 'ABSPATH' ) || exit;

/**
 * The list of questions attached to an event.
 *
 * Stored as JSON in one post meta key rather than as rows. Definitions are
 * configuration: they are read whole, written whole, and never queried across
 * events — nothing ever asks "which events have a dietary question". That is
 * exactly the shape post meta is for, and it is the opposite of the *answers*,
 * which are reported on and filtered and therefore get a table of their own.
 *
 * @since 26.0
 */
final class Definitions {

	/**
	 * Post meta key holding the JSON.
	 */
	const META_KEY = '_qevm_registration_fields';

	/**
	 * How many questions one event may ask.
	 *
	 * Not a technical limit. A registration form with fifty questions on it is
	 * a form nobody finishes, and the organiser who built it will conclude the
	 * plugin is at fault for their sign-up rate. Raise it with the filter if
	 * you genuinely mean it.
	 */
	const MAX_FIELDS = 20;

	/**
	 * The questions on an event, in the order they are asked.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return Field[]
	 */
	public static function for_event( $event_id ) {
		$stored = get_post_meta( (int) $event_id, self::META_KEY, true );

		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$fields = array();

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$field = Field::from_array( $entry );

			if ( null !== $field && $field->is_valid() ) {
				$fields[] = $field;
			}
		}

		/**
		 * Filters the custom questions asked on an event's registration form.
		 *
		 * @since 26.0
		 *
		 * Return a `Field[]`. The tag below says `mixed` because static analysis
		 * reads it as this hook's return type, and declaring the type we hope
		 * for would make the checks underneath look redundant and invite
		 * somebody to delete them.
		 *
		 * @param mixed $fields   Questions, in order. A `Field[]` unless an
		 *                        earlier filter returned something else.
		 * @param int   $event_id Event id.
		 */
		$filtered = apply_filters( 'qevm_registration_fields', $fields, (int) $event_id );

		/*
		 * The docblock above is the contract this hook publishes. It describes
		 * what a well-behaved filter returns and cannot compel anybody to
		 * honour it, so what comes back is checked rather than trusted — a
		 * filter that returns a string, or an array with one stray value in it,
		 * must not be able to fatal every event page on the site.
		 */
		if ( ! is_array( $filtered ) ) {
			return $fields;
		}

		$checked = array();

		foreach ( $filtered as $field ) {
			if ( $field instanceof Field ) {
				$checked[] = $field;
			}
		}

		return $checked;
	}

	/**
	 * One question by its key.
	 *
	 * @since 26.0
	 *
	 * @param int    $event_id Event id.
	 * @param string $key      Field key.
	 * @return Field|null
	 */
	public static function find( $event_id, $key ) {
		foreach ( self::for_event( $event_id ) as $field ) {
			if ( $field->key() === $key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Replace an event's questions.
	 *
	 * @since 26.0
	 *
	 * @param int               $event_id Event id.
	 * @param array<int, mixed> $fields   Questions, in the order they should be
	 *                                    asked. Typed loosely because this is
	 *                                    public and anything that is not a
	 *                                    usable Field is dropped rather than
	 *                                    trusted.
	 * @return void
	 */
	public static function save( $event_id, array $fields ) {
		$valid = array();

		foreach ( $fields as $field ) {
			if ( ! $field instanceof Field || ! $field->is_valid() ) {
				continue;
			}

			$valid[] = $field->to_array();

			if ( count( $valid ) >= self::limit() ) {
				break;
			}
		}

		if ( array() === $valid ) {
			delete_post_meta( (int) $event_id, self::META_KEY );

			return;
		}

		/*
		 * wp_slash() because update_post_meta() unslashes what it is given, and
		 * the JSON is full of quotes and backslashes that must survive. The
		 * event duplicator learned this the expensive way.
		 */
		update_post_meta(
			(int) $event_id,
			self::META_KEY,
			wp_slash( (string) wp_json_encode( $valid ) )
		);
	}

	/**
	 * How many questions an event may ask.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public static function limit() {
		/**
		 * Filters the maximum number of custom questions on one event.
		 *
		 * @since 26.0
		 *
		 * @param int $limit Maximum number of questions.
		 */
		return max( 1, (int) apply_filters( 'qevm_registration_fields_limit', self::MAX_FIELDS ) );
	}
}
