<?php
/**
 * Reading, checking and cleaning submitted answers.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CustomFields;

use QuickEventsManager\Domain\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * What came back from the form, checked against what was asked.
 *
 * One path for the public form and the REST route, the same as
 * `RegistrationService` is for the rest of a booking. Two validation paths for
 * one set of rules is how a required field ends up enforced in the browser and
 * nowhere else.
 *
 * Every value is checked against the question's own definition rather than
 * against its type alone: a choice question accepts only the choices it offers,
 * because the alternative is a form that stores whatever anybody posts to it
 * and an export full of values that were never on the screen.
 *
 * @since 26.0
 */
final class Answers {

	/**
	 * Request key prefix for one attendee's answers.
	 */
	const FIELD_PREFIX = 'qevm_field';

	/**
	 * The request key for one question against one guest.
	 *
	 * @since 26.0
	 *
	 * @param int    $position Guest position, from 1.
	 * @param string $key      Field key.
	 * @return string
	 */
	public static function input_name( $position, $key ) {
		return self::FIELD_PREFIX . '[' . (int) $position . '][' . $key . ']';
	}

	/**
	 * The id for one question's input, unique per guest.
	 *
	 * @since 26.0
	 *
	 * @param int    $position Guest position, from 1.
	 * @param string $key      Field key.
	 * @return string
	 */
	public static function input_id( $position, $key ) {
		return self::FIELD_PREFIX . '-' . (int) $position . '-' . $key;
	}

	/**
	 * Check and clean one guest's answers.
	 *
	 * @since 26.0
	 *
	 * @param array<int, mixed>    $fields   Questions asked. Typed loosely
	 *                                       because they can arrive from the
	 *                                       `qevm_registration_fields` filter,
	 *                                       and anything that is not a Field is
	 *                                       skipped rather than trusted.
	 * @param array<string, mixed> $given    Raw answers for this guest.
	 * @param int                  $position Guest position, for error messages.
	 * @return array{answers: array<string, string|string[]>, errors: array<string, string>}
	 */
	public static function check( array $fields, array $given, $position = 1 ) {
		$answers = array();
		$errors  = array();

		foreach ( $fields as $field ) {
			if ( ! $field instanceof Field ) {
				continue;
			}

			$raw   = isset( $given[ $field->key() ] ) ? $given[ $field->key() ] : '';
			$value = self::clean( $field, $raw );

			if ( self::is_blank( $value ) ) {
				if ( $field->is_required() ) {
					$errors[ $field->key() ] = self::required_message( $field, $position );
				}

				continue;
			}

			$answers[ $field->key() ] = $value;
		}

		return array(
			'answers' => $answers,
			'errors'  => $errors,
		);
	}

	/**
	 * Clean one answer against its question.
	 *
	 * @since 26.0
	 *
	 * @param Field $field Question.
	 * @param mixed $raw   Raw submitted value.
	 * @return string|string[]
	 */
	private static function clean( Field $field, $raw ) {
		$type = $field->type();

		if ( $type->is_multiple() ) {
			$chosen = is_array( $raw ) ? $raw : array( $raw );
			$valid  = array();

			foreach ( $chosen as $one ) {
				$one = sanitize_text_field( (string) $one );

				/*
				 * Only choices that were offered. A `<select>` or a checkbox
				 * group is not a text box, and treating it as one means the
				 * export shows values nobody could have clicked.
				 */
				if ( in_array( $one, $field->options(), true ) ) {
					$valid[] = $one;
				}
			}

			return array_values( array_unique( $valid ) );
		}

		if ( is_array( $raw ) ) {
			$raw = reset( $raw );
		}

		$value = (string) $raw;

		if ( FieldType::Checkbox === $type ) {
			return '' !== trim( $value ) ? '1' : '';
		}

		if ( $type->needs_options() ) {
			$value = sanitize_text_field( $value );

			return in_array( $value, $field->options(), true ) ? $value : '';
		}

		if ( FieldType::Email === $type ) {
			$value = sanitize_email( $value );

			return is_email( $value ) ? $value : '';
		}

		if ( FieldType::Number === $type ) {
			$value = trim( sanitize_text_field( $value ) );

			return is_numeric( $value ) ? $value : '';
		}

		if ( FieldType::Date === $type ) {
			$value = trim( sanitize_text_field( $value ) );

			return self::is_date( $value ) ? $value : '';
		}

		if ( FieldType::Textarea === $type ) {
			return trim( sanitize_textarea_field( $value ) );
		}

		return trim( sanitize_text_field( $value ) );
	}

	/**
	 * Whether a cleaned answer counts as no answer.
	 *
	 * @since 26.0
	 *
	 * @param string|string[] $value Cleaned value.
	 * @return bool
	 */
	private static function is_blank( $value ) {
		return is_array( $value ) ? array() === $value : '' === $value;
	}

	/**
	 * Whether a string is a real calendar date.
	 *
	 * `checkdate()` rather than a regular expression, so 2026-02-30 is refused
	 * rather than stored and rendered as a date that does not exist.
	 *
	 * @since 26.0
	 *
	 * @param string $value Submitted value.
	 * @return bool
	 */
	private static function is_date( $value ) {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * The message shown when a required answer is missing.
	 *
	 * Names the guest as well as the question once there is more than one, so
	 * "Dietary requirements is required" on a form with five identical sections
	 * says which section to look at.
	 *
	 * @since 26.0
	 *
	 * @param Field $field    Question.
	 * @param int   $position Guest position, from 1.
	 * @return string
	 */
	private static function required_message( Field $field, $position ) {
		if ( $position <= 1 ) {
			return sprintf(
				/* translators: %s: the question that was not answered. */
				__( 'Please answer "%s".', 'quick-events-manager' ),
				$field->label()
			);
		}

		return sprintf(
			/* translators: 1: the question that was not answered, 2: which guest it belongs to. */
			__( 'Please answer "%1$s" for guest %2$d.', 'quick-events-manager' ),
			$field->label(),
			(int) $position
		);
	}
}
