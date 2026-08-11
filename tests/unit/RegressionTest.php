<?php
/**
 * Locks down bugs found by running against a real WordPress install.
 *
 * Every test here corresponds to something that was actually broken and that
 * the stubbed suite could not have caught. They are kept together so the
 * reason each one exists stays visible: these are not hypotheticals.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\TestCase;
use QuickEventsManager\Frontend\Ics;
use QuickEventsManager\Frontend\SingleEvent;
use QuickEventsManager\Registration\Exporter;
use QuickEventsManager\Registration\RegistrationService;

/**
 * Regression guards.
 */
final class RegressionTest extends TestCase {

	/**
	 * Read a source file from the plugin.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function source( $relative ) {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
	}

	/**
	 * `the_content` injection must guard against re-entry.
	 *
	 * Rendering the event details calls get_the_excerpt(). For a post with no
	 * manual excerpt, core generates one with wp_trim_excerpt(), which applies
	 * `the_content` — landing back in the same filter with every other guard
	 * still true. On a live site this exhausted PHP's memory limit and served
	 * a blank page on every single event.
	 *
	 * @return void
	 */
	public function test_single_event_injection_has_a_reentrancy_guard() {
		$source = $this->source( 'includes/Frontend/SingleEvent.php' );

		$this->assertStringContainsString(
			'self::$rendering',
			$source,
			'the_content injection must not be able to re-enter itself.'
		);

		$reflection = new ReflectionClass( SingleEvent::class );

		$this->assertTrue(
			$reflection->hasProperty( 'rendering' ),
			'SingleEvent needs a re-entrancy flag.'
		);
	}

	/**
	 * The guard has to be released even when rendering throws, or one broken
	 * event would silently strip the details from every other event for the
	 * rest of the request.
	 *
	 * @return void
	 */
	public function test_reentrancy_guard_is_released_on_failure() {
		$this->assertStringContainsString(
			'finally',
			$this->source( 'includes/Frontend/SingleEvent.php' ),
			'The re-entrancy flag must be cleared in a finally block.'
		);
	}

	/**
	 * A bare sanitize_title() must never be used as a sanitize_callback.
	 *
	 * WordPress calls a sanitize_callback as ( $value, $request, $param ).
	 * sanitize_title()'s second parameter is $fallback_title, which it returns
	 * when the title sanitises to empty — so an empty value comes back as the
	 * WP_REST_Request object, and the next thing to treat it as a string
	 * fatals. This took down the events list endpoint on every unfiltered
	 * request.
	 *
	 * @return void
	 */
	public function test_sanitize_title_is_never_passed_as_a_bare_callback() {
		$this->assertStringNotContainsString(
			"'sanitize_callback' => 'sanitize_title'",
			$this->source( 'includes/Rest/EventsController.php' ),
			'Wrap sanitize_title() — as a bare callback it returns the request object for empty values.'
		);
	}

	/**
	 * Only callbacks that take a single argument are safe to pass by name.
	 *
	 * @dataProvider single_argument_callback_provider
	 *
	 * @param string $callback Core function used as a sanitize_callback.
	 * @return void
	 */
	public function test_only_single_argument_callbacks_are_passed_by_name( $callback ) {
		$reflection = new ReflectionFunction( $callback );

		$this->assertSame(
			1,
			$reflection->getNumberOfParameters(),
			sprintf(
				'%s() takes more than one parameter, so WordPress passing ( $value, $request, $param ) changes its behaviour. Wrap it.',
				$callback
			)
		);
	}

	/**
	 * Functions the REST controller passes by name.
	 *
	 * @return array<string, array{string}>
	 */
	public static function single_argument_callback_provider() {
		return array(
			'absint' => array( 'absint' ),
		);
	}

	/**
	 * The registration form must be gated on the module, not only on the
	 * per-event setting.
	 *
	 * Three separate things render the form — the shortcode, the block and the
	 * automatic injection on single event pages. With the check missing, a
	 * site that had switched registration off still showed a working form on
	 * every event, which is the opposite of what the Features screen promises.
	 *
	 * @return void
	 */
	public function test_registration_form_is_gated_on_the_module() {
		$source = $this->source( 'includes/Frontend/Renderer.php' );

		$this->assertStringContainsString(
			'is_enabled',
			$source,
			'Renderer::registration_form() must check the module is enabled.'
		);
	}

	/**
	 * The rate limit must stay loose enough not to lock out a building.
	 *
	 * Offices, universities and conference venues put every visitor behind one
	 * NAT gateway, so they all share an address — and those are exactly the
	 * places that run events. A limit tight enough to interest an attacker
	 * would take out a whole venue.
	 *
	 * @return void
	 */
	public function test_rate_limit_does_not_lock_out_shared_addresses() {
		$this->assertGreaterThanOrEqual(
			20,
			RegistrationService::RATE_LIMIT,
			'Too tight: every visitor behind one NAT gateway shares an address.'
		);
	}

	/**
	 * Sites need an escape hatch when even a loose limit is wrong.
	 *
	 * @return void
	 */
	public function test_rate_limit_is_filterable() {
		$this->assertStringContainsString(
			'qevm_registration_rate_limit',
			$this->source( 'includes/Registration/RegistrationService.php' )
		);
	}

	/**
	 * A CSV cell starting with =, +, - or @ is executed as a formula when the
	 * file is opened, so an attendee's name becomes code running on the
	 * organiser's machine.
	 *
	 * @dataProvider formula_provider
	 *
	 * @param string $input Hostile cell value.
	 * @return void
	 */
	public function test_csv_formulas_are_defused( $input ) {
		$this->assertStringStartsWith( "\t", Exporter::defuse( $input ) );
	}

	/**
	 * Values a spreadsheet would execute.
	 *
	 * @return array<string, array{string}>
	 */
	public static function formula_provider() {
		return array(
			'equals'    => array( '=1+1' ),
			'plus'      => array( '+1' ),
			'minus'     => array( '-1' ),
			'at'        => array( '@SUM(A1)' ),
			'command'   => array( '=cmd|\' /C calc\'!A0' ),
			'hyperlink' => array( '=HYPERLINK("http://evil.test","click")' ),
		);
	}

	/**
	 * An ordinary name is left exactly as it was.
	 *
	 * @return void
	 */
	public function test_ordinary_csv_values_are_untouched() {
		$this->assertSame( 'Ada Lovelace', Exporter::defuse( 'Ada Lovelace' ) );
		$this->assertSame( '', Exporter::defuse( '' ) );
	}

	/**
	 * Commas and semicolons are structural in iCalendar, so an event whose
	 * title contains one corrupts the file unless it is escaped.
	 *
	 * @return void
	 */
	public function test_ics_text_is_escaped() {
		$this->assertSame( 'Talks\, food\; and more', Ics::escape_text( 'Talks, food; and more' ) );
		$this->assertSame( 'line one\nline two', Ics::escape_text( "line one\nline two" ) );
		$this->assertSame( 'back\\\\slash', Ics::escape_text( 'back\\slash' ) );
	}

	/**
	 * RFC 5545 caps a line at 75 octets; longer lines must be folded or the
	 * file is rejected by strict clients.
	 *
	 * @return void
	 */
	public function test_ics_long_lines_are_folded() {
		$folded = Ics::fold( 'SUMMARY:' . str_repeat( 'a', 200 ) );
		$lines  = explode( "\r\n", $folded );

		/*
		 * The limit is 75 octets per line excluding the CRLF, and the leading
		 * space on a continuation line counts towards it — so every line is
		 * measured as-is, with nothing trimmed.
		 */
		foreach ( $lines as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ) );
		}

		$this->assertGreaterThan( 1, count( $lines ), 'A 200-character value must be folded.' );

		foreach ( array_slice( $lines, 1 ) as $continuation ) {
			$this->assertStringStartsWith( ' ', $continuation, 'Continuation lines start with a space.' );
		}

		// Unfolding must give back exactly what went in.
		$this->assertSame(
			'SUMMARY:' . str_repeat( 'a', 200 ),
			str_replace( "\r\n ", '', $folded )
		);
	}

	/**
	 * A short line is returned untouched.
	 *
	 * @return void
	 */
	public function test_ics_short_lines_are_not_folded() {
		$this->assertSame( 'SUMMARY:Meetup', Ics::fold( 'SUMMARY:Meetup' ) );
	}

	/**
	 * Every REST route this plugin registers must declare a permission
	 * callback. Omitting one is the single most common way a plugin exposes
	 * private data, and WordPress only warns about it.
	 *
	 * @return void
	 */
	public function test_every_rest_route_declares_a_permission_callback() {
		$source  = $this->source( 'includes/Rest/EventsController.php' );
		$routes  = substr_count( $source, "'callback'" );
		$permits = substr_count( $source, "'permission_callback'" );

		$this->assertSame(
			$routes,
			$permits,
			'Every registered route needs an explicit permission_callback.'
		);
	}
}
