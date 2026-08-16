<?php
/**
 * The kinds of question a registration form can ask.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What a custom registration field is.
 *
 * Backed by string, and the values are stored inside the field definitions on
 * every event. Changing a case value orphans every question already defined
 * with it, so these are a contract in the same way a column name is.
 *
 * Ten cases, which sounds like a lot until you notice they collapse to four
 * rendering shapes — one input, a textarea, a select, and a group of choices —
 * and that the first six differ only in the `type` attribute and the check
 * applied to what comes back. A `date` that renders as a bare text box is the
 * kind of gap people notice immediately on a phone.
 *
 * @since 26.0
 */
enum FieldType: string {

	/**
	 * A short answer.
	 */
	case Text = 'text';

	/**
	 * A long answer.
	 */
	case Textarea = 'textarea';

	/**
	 * An email address.
	 */
	case Email = 'email';

	/**
	 * A phone number.
	 */
	case Tel = 'tel';

	/**
	 * A number.
	 */
	case Number = 'number';

	/**
	 * A date.
	 */
	case Date = 'date';

	/**
	 * Choose one, from a dropdown.
	 */
	case Select = 'select';

	/**
	 * Choose one, with every option visible.
	 */
	case Radio = 'radio';

	/**
	 * A single yes or no.
	 */
	case Checkbox = 'checkbox';

	/**
	 * Choose any number of options.
	 */
	case Checkboxes = 'checkboxes';

	/**
	 * Whether this type needs a list of options to make sense.
	 *
	 * A select with nothing to select from is not a hard question, it is a
	 * broken form, so the editor refuses to save one.
	 *
	 * @since 26.0
	 */
	public function needs_options(): bool {
		return in_array( $this, array( self::Select, self::Radio, self::Checkboxes ), true );
	}

	/**
	 * Whether an answer to this is a list rather than a single value.
	 *
	 * The one distinction that reaches all the way through to storage and to
	 * the CSV export, which is why it is asked here rather than decided again
	 * in each of them.
	 *
	 * @since 26.0
	 */
	public function is_multiple(): bool {
		return self::Checkboxes === $this;
	}

	/**
	 * The `type` attribute for the types that render as one input.
	 *
	 * @since 26.0
	 */
	public function input_type(): string {
		return match ( $this ) {
			self::Email  => 'email',
			self::Tel    => 'tel',
			self::Number => 'number',
			self::Date   => 'date',
			default      => 'text',
		};
	}

	/**
	 * The name shown in the editor's type dropdown.
	 *
	 * @since 26.0
	 */
	public function label(): string {
		return match ( $this ) {
			self::Text       => __( 'Short answer', 'quick-events-manager' ),
			self::Textarea   => __( 'Long answer', 'quick-events-manager' ),
			self::Email      => __( 'Email address', 'quick-events-manager' ),
			self::Tel        => __( 'Phone number', 'quick-events-manager' ),
			self::Number     => __( 'Number', 'quick-events-manager' ),
			self::Date       => __( 'Date', 'quick-events-manager' ),
			self::Select     => __( 'Choose one (dropdown)', 'quick-events-manager' ),
			self::Radio      => __( 'Choose one (list)', 'quick-events-manager' ),
			self::Checkbox   => __( 'Yes or no', 'quick-events-manager' ),
			self::Checkboxes => __( 'Choose any', 'quick-events-manager' ),
		};
	}

	/**
	 * Resolve a stored value, falling back to a short answer.
	 *
	 * Definitions are stored as JSON and can be written by anything with the
	 * capability — including an import, or a version of this plugin that had a
	 * type this one does not. An unknown type falls back rather than fatals:
	 * losing the input shape of one question is recoverable, and a fatal on the
	 * event editor is not.
	 *
	 * @since 26.0
	 *
	 * @param string $value Stored type.
	 */
	public static function from_stored( string $value ): self {
		return self::tryFrom( $value ) ?? self::Text;
	}
}
