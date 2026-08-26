<?php
/**
 * One question on a registration form.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CustomFields;

use QuickEventsManager\Domain\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * A question, its type, and whether an answer is required.
 *
 * **The key is generated once and never derived from the label.** That is the
 * decision worth reading here, because deriving it is the obvious thing to do
 * and it destroys data silently. Answers are stored against the key. Derive the
 * key from "Dietary requirements" and the day somebody edits the label to
 * "Dietary requirements or allergies" — a Tuesday-afternoon tidy-up, no warning,
 * no confirmation — every answer already collected is orphaned. The form keeps
 * working, the export comes back empty for that column, and the only trace is
 * rows in a table nobody is looking at.
 *
 * So a key is random, assigned when the question is created, and carried
 * through every edit. It is never shown to the person filling the form in and
 * never needs to be readable.
 *
 * @since 26.0
 */
final class Field {

	/**
	 * Stable identifier. Answers are stored against this.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * The question, as the visitor reads it.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * What kind of answer it takes.
	 *
	 * @var FieldType
	 */
	private $type;

	/**
	 * Whether the form refuses to submit without an answer.
	 *
	 * @var bool
	 */
	private $required;

	/**
	 * Choices, for the types that have them.
	 *
	 * @var string[]
	 */
	private $options;

	/**
	 * Help text shown under the field.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Whether the answer is health-adjacent or otherwise sensitive.
	 *
	 * Carried in the definition from the start, though nothing acts on it until
	 * the export work. Dietary requirements and access needs are the two
	 * questions every event asks and both reveal something about health, so the
	 * flag has to exist before anybody has defined a hundred fields without it.
	 * Reserving it now costs a key in a JSON object; adding it later means
	 * walking every event on every site.
	 *
	 * @var bool
	 */
	private $sensitive;

	/**
	 * Build a field.
	 *
	 * @since 26.0
	 *
	 * @param string    $key         Stable identifier.
	 * @param string    $label       The question.
	 * @param FieldType $type        Answer type.
	 * @param bool      $required    Whether an answer is required.
	 * @param string[]  $options     Choices, for the types that have them.
	 * @param string    $description Help text.
	 * @param bool      $sensitive   Whether the answer is sensitive.
	 */
	public function __construct( $key, $label, FieldType $type, $required = false, array $options = array(), $description = '', $sensitive = false ) {
		$this->key         = (string) $key;
		$this->label       = (string) $label;
		$this->type        = $type;
		$this->required    = (bool) $required;
		$this->options     = array_values( array_filter( array_map( 'strval', $options ), static fn ( $option ) => '' !== trim( $option ) ) );
		$this->description = (string) $description;
		$this->sensitive   = (bool) $sensitive;
	}

	/**
	 * Mint a key for a new question.
	 *
	 * Random rather than derived, for the reason in the class docblock. Twelve
	 * lowercase alphanumerics, which is enough that a collision within one
	 * event's handful of questions is not worth guarding against, and short
	 * enough to read in a database when something has gone wrong.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function mint_key() {
		return 'f' . strtolower( wp_generate_password( 11, false, false ) );
	}

	/**
	 * Rebuild a field from its stored array.
	 *
	 * Tolerant by design. Definitions are JSON written by an editor screen, an
	 * import, or a future version of this plugin, and a malformed entry must
	 * degrade to a usable question rather than break the event.
	 *
	 * @since 26.0
	 *
	 * @param array<string, mixed> $stored Stored definition.
	 * @return self|null Null when there is not enough to make a question from.
	 */
	public static function from_array( array $stored ) {
		$key   = isset( $stored['key'] ) ? (string) $stored['key'] : '';
		$label = isset( $stored['label'] ) ? (string) $stored['label'] : '';

		if ( '' === trim( $key ) || '' === trim( $label ) ) {
			return null;
		}

		return new self(
			$key,
			$label,
			FieldType::from_stored( isset( $stored['type'] ) ? (string) $stored['type'] : 'text' ),
			! empty( $stored['required'] ),
			isset( $stored['options'] ) && is_array( $stored['options'] ) ? $stored['options'] : array(),
			isset( $stored['description'] ) ? (string) $stored['description'] : '',
			! empty( $stored['sensitive'] )
		);
	}

	/**
	 * The field as it is stored.
	 *
	 * @since 26.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'key'         => $this->key,
			'label'       => $this->label,
			'type'        => $this->type->value,
			'required'    => $this->required,
			'options'     => $this->options,
			'description' => $this->description,
			'sensitive'   => $this->sensitive,
		);
	}

	/**
	 * Whether this field is usable on a form.
	 *
	 * A choice question with nothing to choose from is not a hard question, it
	 * is a broken form.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_valid() {
		if ( '' === trim( $this->label ) ) {
			return false;
		}

		return ! $this->type->needs_options() || array() !== $this->options;
	}

	/**
	 * Stable identifier.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function key() {
		return $this->key;
	}

	/**
	 * The question.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function label() {
		return $this->label;
	}

	/**
	 * Answer type.
	 *
	 * @since 26.0
	 *
	 * @return FieldType
	 */
	public function type() {
		return $this->type;
	}

	/**
	 * Whether an answer is required.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required() {
		return $this->required;
	}

	/**
	 * Choices, for the types that have them.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public function options() {
		return $this->options;
	}

	/**
	 * Help text.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return $this->description;
	}

	/**
	 * Whether the answer is health-adjacent or otherwise sensitive.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_sensitive() {
		return $this->sensitive;
	}
}
