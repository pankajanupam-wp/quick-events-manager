<?php
/**
 * One editable email, and what filling it in means.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

defined( 'ABSPATH' ) || exit;

/**
 * A subject and a body with placeholders in them.
 *
 * **The escaping rule is the whole of this class.** A template is written by
 * somebody with the capability to edit the site's settings, so its own markup is
 * trusted — that is the point of offering an HTML option at all. The values
 * substituted into it are not: an attendee's name arrives from a public form,
 * and `{attendee_name}` in an HTML template is a hole straight through to
 * whatever they typed.
 *
 * So the template is left alone and every value is escaped on its way in,
 * according to the format the template is in. A plain-text template strips tags
 * from its values, because an address book full of `<b>` is nobody's idea of a
 * readable email; an HTML one runs them through `esc_html()`.
 *
 * Getting this backwards is silent: the email looks right in every test written
 * with a normal name in it.
 *
 * @since 26.0
 */
final class Template {

	/**
	 * Plain text.
	 */
	const FORMAT_TEXT = 'text';

	/**
	 * HTML.
	 */
	const FORMAT_HTML = 'html';

	/**
	 * Template id, e.g. `attendee_confirmation`.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Subject line, with placeholders.
	 *
	 * @var string
	 */
	private $subject;

	/**
	 * Body, with placeholders.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Whether the body is HTML.
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Build a template.
	 *
	 * @since 26.0
	 *
	 * @param string $id      Template id.
	 * @param string $subject Subject, with placeholders.
	 * @param string $body    Body, with placeholders.
	 * @param string $format  text | html.
	 */
	public function __construct( $id, $subject, $body, $format = self::FORMAT_TEXT ) {
		$this->id      = (string) $id;
		$this->subject = (string) $subject;
		$this->body    = (string) $body;
		$this->format  = self::FORMAT_HTML === $format ? self::FORMAT_HTML : self::FORMAT_TEXT;
	}

	/**
	 * Template id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Whether this template is HTML.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_html() {
		return self::FORMAT_HTML === $this->format;
	}

	/**
	 * The format, as stored.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function format() {
		return $this->format;
	}

	/**
	 * The raw subject, placeholders intact.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function raw_subject() {
		return $this->subject;
	}

	/**
	 * The raw body, placeholders intact.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function raw_body() {
		return $this->body;
	}

	/**
	 * Whether there is anything here to send.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_usable() {
		return '' !== trim( $this->subject ) && '' !== trim( $this->body );
	}

	/**
	 * Fill the template in.
	 *
	 * A subject is always plain text, whatever the body is — mail clients do
	 * not render markup in it, and a subject line with an escaped tag in it is
	 * how `&lt;b&gt;` ends up in somebody's inbox.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $values Values, keyed by placeholder name.
	 * @return array{subject: string, body: string, headers: string[]}
	 */
	public function render( array $values ) {
		$subject = self::substitute( $this->subject, $values, self::FORMAT_TEXT );
		$body    = self::substitute( $this->body, $values, $this->format );

		return array(
			'subject' => $subject,
			'body'    => $body,
			'headers' => $this->is_html() ? array( 'Content-Type: text/html; charset=UTF-8' ) : array(),
		);
	}

	/**
	 * Replace every placeholder in a string.
	 *
	 * Unknown placeholders are removed rather than left in place. A confirmation
	 * that says "Hello {atendee_name}" because somebody mistyped it is worse
	 * than one that says "Hello" — the first looks like the site is broken, and
	 * broken is the impression that reaches everyone who registers.
	 *
	 * @since 26.0
	 *
	 * @param string                $text   Text with placeholders.
	 * @param array<string, string> $values Values, keyed by placeholder name.
	 * @param string                $format Format the result is going into.
	 * @return string
	 */
	private static function substitute( $text, array $values, $format ) {
		$escaped = array();

		foreach ( $values as $name => $value ) {
			$escaped[ '{' . $name . '}' ] = self::escape( (string) $value, $format );
		}

		$text = strtr( (string) $text, $escaped );

		return (string) preg_replace( '/\{[a-z0-9_]+\}/i', '', $text );
	}

	/**
	 * Make one value safe to drop into a given format.
	 *
	 * @since 26.0
	 *
	 * @param string $value  Raw value.
	 * @param string $format text | html.
	 * @return string
	 */
	private static function escape( $value, $format ) {
		if ( self::FORMAT_HTML === $format ) {
			return esc_html( $value );
		}

		/*
		 * Tags out of plain text rather than escaped. A name entered as
		 * `<b>Priya</b>` should read as "Priya" in a text email, not as
		 * "&lt;b&gt;Priya&lt;/b&gt;", which is what escaping it would produce.
		 */
		return wp_strip_all_tags( $value );
	}
}
