<?php
/**
 * Unit test bootstrap.
 *
 * Stubs the slice of WordPress the pure-logic classes touch, so the suite runs
 * in about a second with no database, no WordPress install and no Docker.
 *
 * What it deliberately does NOT cover: anything involving $wpdb. SQL cannot be
 * meaningfully faked — a stub that returns what you tell it proves only that
 * you can write a stub. The custom table, the capacity ranking, the REST routes
 * and the post-type migration are covered by tests/integration/ against a real
 * MySQL instead.
 *
 * @package QuickEventsManager
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

/**
 * Mutable state shared by the stubs.
 */
final class WP_Stub_State {

	/**
	 * Registered hooks, as array( type, hook, callback, priority, accepted_args ).
	 *
	 * @var list<array{string, string, callable, int, int}>
	 */
	public static array $hooks = array();

	/**
	 * Actions that were fired, as hook => list of argument lists.
	 *
	 * Recorded so do_action() has an observable effect. A stub with an empty
	 * body is indistinguishable from a function that does nothing, which is
	 * both untrue of do_action() and something static analysis will point out.
	 *
	 * @var array<string, list<array<int, mixed>>>
	 */
	public static array $actions = array();

	/**
	 * Registered shortcodes, as tag => callback.
	 *
	 * @var array<string, callable>
	 */
	public static array $shortcodes = array();

	/**
	 * Stored options.
	 *
	 * @var array<string, mixed>
	 */
	public static array $options = array();

	/**
	 * Post meta, as post_id => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $meta = array();

	/**
	 * Posts, as id => object.
	 *
	 * @var array<int, WP_Post>
	 */
	public static array $posts = array();

	/**
	 * Reset everything between tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$hooks      = array();
		self::$actions    = array();
		self::$shortcodes = array();
		self::$options    = array();
		self::$meta       = array();
		self::$posts      = array();
	}
}

/*
 * Stubs of the WordPress functions the pure-logic classes reach for.
 *
 * Signatures mirror core's, including variadics and defaults, because a stub
 * that is narrower than the real function hides bugs rather than catching them:
 * a call that works here would fail against WordPress, and the suite would stay
 * green. That is not theoretical — do_action() and apply_filters() were declared
 * here with fixed arity, and every call passing extra arguments was wrong in a
 * way only static analysis could see.
 *
 * Types are given in PHPDoc rather than as native declarations, deliberately.
 * Core's own functions are untyped and coerce freely; a stub that threw a
 * TypeError where WordPress would quietly cast would be a different lie.
 */

/**
 * Record an action or filter registration.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Arguments the callback accepts.
 * @return true
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	WP_Stub_State::$hooks[] = array( 'action', $hook_name, $callback, $priority, $accepted_args );

	return true;
}

/**
 * Record a filter registration.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Arguments the callback accepts.
 * @return true
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	WP_Stub_State::$hooks[] = array( 'filter', $hook_name, $callback, $priority, $accepted_args );

	return true;
}

/**
 * Return the value unchanged; nothing is subscribed in a unit test.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Value being filtered.
 * @param mixed  ...$args   Extra arguments passed to subscribers.
 * @return mixed
 */
function apply_filters( $hook_name, $value, ...$args ) {
	return $value;
}

/**
 * Record that an action fired, with its arguments.
 *
 * @param string $hook_name Hook name.
 * @param mixed  ...$arg    Arguments passed to subscribers.
 * @return void
 */
function do_action( $hook_name, ...$arg ) {
	WP_Stub_State::$actions[ $hook_name ][] = $arg;
}

/**
 * Record a shortcode registration.
 *
 * @param string   $tag      Shortcode tag.
 * @param callable $callback Handler.
 * @return void
 */
function add_shortcode( $tag, $callback ) {
	WP_Stub_State::$shortcodes[ $tag ] = $callback;
}

/**
 * Read a stored option.
 *
 * @param string $option        Option name.
 * @param mixed  $default_value Returned when the option is absent.
 * @return mixed
 */
function get_option( $option, $default_value = false ) {
	return array_key_exists( $option, WP_Stub_State::$options ) ? WP_Stub_State::$options[ $option ] : $default_value;
}

/**
 * Store an option.
 *
 * @param string    $option   Option name.
 * @param mixed     $value    Value.
 * @param bool|null $autoload Ignored; present to match core.
 * @return bool
 */
function update_option( $option, $value, $autoload = null ) {
	WP_Stub_State::$options[ $option ] = $value;

	return true;
}

/**
 * Remove an option.
 *
 * @param string $option Option name.
 * @return bool
 */
function delete_option( $option ) {
	unset( WP_Stub_State::$options[ $option ] );

	return true;
}

/**
 * Read post meta.
 *
 * @param int    $post_id Post id.
 * @param string $key     Meta key, or '' for every key.
 * @param bool   $single  Return the value itself rather than an array.
 * @return mixed
 */
function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		$out = array();

		foreach ( (array) ( WP_Stub_State::$meta[ $post_id ] ?? array() ) as $meta_key => $meta_value ) {
			$out[ $meta_key ] = array( $meta_value );
		}

		return $out;
	}

	$value = WP_Stub_State::$meta[ $post_id ][ $key ] ?? '';

	return $single ? $value : array( $value );
}

/**
 * Write post meta.
 *
 * @param int    $post_id    Post id.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Value.
 * @param mixed  $prev_value Ignored; present to match core.
 * @return bool
 */
function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
	WP_Stub_State::$meta[ $post_id ][ $meta_key ] = $meta_value;

	return true;
}

/**
 * Resolve a post or post id.
 *
 * @param WP_Post|int|null $post Post or id.
 * @return WP_Post|null
 */
function get_post( $post = null ) {
	if ( $post instanceof WP_Post ) {
		return $post;
	}

	return WP_Stub_State::$posts[ $post ] ?? null;
}

/**
 * Directory of a plugin file, with a trailing slash.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

/**
 * URL of a plugin directory.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/quick-events-manager/';
}

/**
 * Plugin identifier, as directory/file.php.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

/**
 * Record an activation hook.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_activation_hook( $file, $callback ) {
	WP_Stub_State::$hooks[] = array( 'activate', $file, $callback, 10, 1 );
}

/**
 * Record a deactivation hook.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_deactivation_hook( $file, $callback ) {
	WP_Stub_State::$hooks[] = array( 'deactivate', $file, $callback, 10, 1 );
}

/**
 * Translate a string. Untranslated here.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	return $text;
}

/**
 * Translate a string with context. Untranslated here.
 *
 * @param string $text    Text.
 * @param string $context Disambiguating context.
 * @param string $domain  Text domain.
 * @return string
 */
function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

/**
 * Choose a singular or plural string.
 *
 * @param string $single Singular form.
 * @param string $plural Plural form.
 * @param int    $number Count.
 * @param string $domain Text domain.
 * @return string
 */
function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

/**
 * Escape for HTML output.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escape for an attribute.
 *
 * @param string $text Text.
 * @return string
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Sanitise a URL for storage.
 *
 * @param string             $url       URL.
 * @param array<int, string> $protocols Ignored; present to match core.
 * @return string
 */
function esc_url_raw( $url, $protocols = null ) {
	/*
	 * Core's allowlist, copied from esc_url() in wp-includes/formatting.php.
	 * Anything outside the set is removed, which is what strips angle brackets.
	 *
	 * This used to be filter_var( ..., FILTER_SANITIZE_URL ), which keeps `<`
	 * and `>` — so the stub was weaker than the function it stands in for, and
	 * a test could have passed on markup real WordPress would have stripped.
	 */
	$url = str_replace( ' ', '%20', ltrim( (string) $url ) );

	return (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );
}

/**
 * Strip tags and trim.
 *
 * @param string $str Text.
 * @return string
 */
function sanitize_text_field( $str ) {
	return trim( wp_strip_all_tags( (string) $str ) );
}

/**
 * Remove characters an address cannot contain.
 *
 * @param string $email Address.
 * @return string
 */
function sanitize_email( $email ) {
	return (string) filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}

/**
 * Whether an address is valid.
 *
 * Returns the address rather than true, which is what core does.
 *
 * @param string $email Address.
 * @return string|false
 */
function is_email( $email ) {
	return filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

/**
 * Non-negative integer.
 *
 * @param mixed $maybeint Value.
 * @return int
 */
function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

/**
 * Random integer.
 *
 * @param int $min Lower bound.
 * @param int $max Upper bound.
 * @return int
 */
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max );
}

/**
 * Site secret for a hashing scheme.
 *
 * Fixed rather than random, because a stub that returned something different
 * on each call would make signing look broken; and distinct per scheme, so a
 * test can still tell that two schemes do not share a key.
 *
 * @param string $scheme Salting scheme.
 * @return string
 */
function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-for-' . $scheme;
}

/**
 * A version 4 UUID.
 *
 * Genuinely random rather than fixed, unlike wp_salt() above. Two calls to this
 * must not agree: the whole point of a series uuid is that a second series gets
 * a different one, and a stub returning a constant would make a test that checks
 * two series are distinct pass while proving the opposite.
 *
 * @return string
 */
function wp_generate_uuid4() {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0x0fff ) | 0x4000,
		random_int( 0, 0x3fff ) | 0x8000,
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff )
	);
}

/**
 * Whether a string is a UUID, optionally of a given version.
 *
 * @param mixed    $uuid    Value to test.
 * @param int|null $version Version to require.
 * @return bool
 */
function wp_is_uuid( $uuid, $version = null ) {
	if ( ! is_string( $uuid ) ) {
		return false;
	}

	if ( is_numeric( $version ) ) {
		if ( 4 !== (int) $version ) {
			return false;
		}

		$regex = '/^[0-9a-f]{8}\-[0-9a-f]{4}\-4[0-9a-f]{3}\-[89ab][0-9a-f]{3}\-[0-9a-f]{12}$/';
	} else {
		$regex = '/^[0-9a-f]{8}\-[0-9a-f]{4}\-[0-9a-f]{4}\-[0-9a-f]{4}\-[0-9a-f]{12}$/';
	}

	return (bool) preg_match( $regex, $uuid );
}

/**
 * Remove post meta.
 *
 * @param int    $post_id    Post id.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Ignored; present to match core.
 * @return bool
 */
function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
	unset( WP_Stub_State::$meta[ $post_id ][ $meta_key ] );

	return true;
}

/**
 * Capability check. Always granted in unit tests.
 *
 * @param string $capability Capability.
 * @param mixed  ...$args    Extra arguments.
 * @return bool
 */
function current_user_can( $capability, ...$args ) {
	return true;
}

/**
 * Current user id. Always 0 in unit tests.
 *
 * @return int
 */
function get_current_user_id() {
	return 0;
}

/**
 * Format a timestamp.
 *
 * @param string         $format                Date format.
 * @param int|false|null $timestamp_with_offset Timestamp. Core takes int|false; null is listed because callers pass it.
 * @param bool           $gmt                   Ignored; present to match core.
 * @return string
 */
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
	$timestamp = ( false === $timestamp_with_offset || null === $timestamp_with_offset ) ? time() : (int) $timestamp_with_offset;

	return gmdate( $format, $timestamp );
}

/**
 * Format a number.
 *
 * @param float $number   Number.
 * @param int   $decimals Decimal places.
 * @return string
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

/**
 * JSON-encode a value.
 *
 * @param mixed $value Value.
 * @param int   $flags Encoding flags.
 * @param int   $depth Maximum depth.
 * @return string|false
 */
function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

/**
 * Merge arguments over defaults.
 *
 * @param mixed                $args     Arguments.
 * @param array<string, mixed> $defaults Defaults.
 * @return array<string, mixed>
 */
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

/**
 * Remove every tag.
 *
 * @param string $text          Text.
 * @param bool   $remove_breaks Also collapse whitespace.
 * @return string
 */
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );

	if ( $remove_breaks ) {
		$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}

	return trim( $text );
}

/**
 * Site URL.
 *
 * @param string      $path   Path to append.
 * @param string|null $scheme Ignored; present to match core.
 * @return string
 */
function home_url( $path = '', $scheme = null ) {
	return 'https://example.test' . $path;
}

/**
 * Combine shortcode attributes with their defaults.
 *
 * @param array<string, mixed> $pairs     Defaults.
 * @param mixed                $atts      Supplied attributes.
 * @param string               $shortcode Shortcode name.
 * @return array<string, mixed>
 */
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default_value ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default_value;
	}

	return $out;
}

/**
 * A minimal WP_Post stand-in.
 */
class WP_Post {

	/**
	 * Post id.
	 *
	 * @var int
	 */
	public $ID = 0;

	/**
	 * Post type.
	 *
	 * @var string
	 */
	public $post_type = '';

	/**
	 * Post title.
	 *
	 * @var string
	 */
	public $post_title = '';

	/**
	 * Post status.
	 *
	 * A real WP_Post property, and its absence here was a stub narrower than
	 * the class it stands in for — the same gap that made do_action() look
	 * fixed-arity. Code branching on a draft or an auto-draft could not be
	 * analysed, or tested, without it.
	 *
	 * @var string
	 */
	public $post_status = 'publish';

	/**
	 * Post slug.
	 *
	 * @var string
	 */
	public $post_name = '';

	/**
	 * Post body.
	 *
	 * @var string
	 */
	public $post_content = '';

	/**
	 * Hand-written excerpt, if there is one.
	 *
	 * @var string
	 */
	public $post_excerpt = '';

	/**
	 * Author user id.
	 *
	 * @var int
	 */
	public $post_author = 0;

	/**
	 * Whether comments are open.
	 *
	 * @var string
	 */
	public $comment_status = 'closed';

	/**
	 * Whether pingbacks are open.
	 *
	 * @var string
	 */
	public $ping_status = 'closed';

	/**
	 * Manual ordering position.
	 *
	 * @var int
	 */
	public $menu_order = 0;

	/**
	 * Parent post id.
	 *
	 * @var int
	 */
	public $post_parent = 0;

	/**
	 * Constructor.
	 *
	 * @param int    $id          Post id.
	 * @param string $post_type   Post type.
	 * @param string $post_status Post status.
	 */
	public function __construct( $id = 0, $post_type = '', $post_status = 'publish' ) {
		$this->ID          = $id;
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
	}

	/**
	 * Every field as an array, the way core's WP_Post does.
	 *
	 * PHPStan analyses `tests/` as well as `includes/`, so this class — not
	 * core's — is what it checks plugin code against. A stub narrower than the
	 * class it stands in for does not report a missing property; it reports the
	 * *plugin* as wrong for using one that exists. Anything real code touches
	 * has to be here.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return get_object_vars( $this );
	}
}

/**
 * A minimal WP_Error stand-in.
 */
class WP_Error {

	/**
	 * Messages, keyed by error code, in the order they were added.
	 *
	 * Core keeps a list per code, because one code can carry several messages.
	 * The stub does the same: a stub narrower than the class it stands in for
	 * does not report itself as incomplete — it reports the *plugin* as wrong
	 * for using a method that exists. That has now happened twice, here and on
	 * WP_Post.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $errors = array();

	/**
	 * Data, keyed by error code.
	 *
	 * @var array<string, mixed>
	 */
	private $error_data = array();

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Error data.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' === $code ) {
			return;
		}

		$this->add( $code, $message, $data );
	}

	/**
	 * Add an error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Error data.
	 * @return void
	 */
	public function add( $code, $message = '', $data = '' ) {
		$this->errors[ $code ][] = $message;

		if ( '' !== $data && array() !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	/**
	 * Whether anything has been added.
	 *
	 * @return bool
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}

	/**
	 * Every code, in the order it was added.
	 *
	 * @return array<int, string>
	 */
	public function get_error_codes() {
		return array_keys( $this->errors );
	}

	/**
	 * The first code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		$codes = $this->get_error_codes();

		return isset( $codes[0] ) ? $codes[0] : '';
	}

	/**
	 * A message, for a given code or for the first one.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public function get_error_message( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}

		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	/**
	 * Data, for a given code or for the first one.
	 *
	 * @param string $code Error code.
	 * @return mixed
	 */
	public function get_error_data( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}

		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}
}


/**
 * Whether a value is a WP_Error.
 *
 * @param mixed $thing Value to test.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/*
 * Core's time constants, with core's own values (wp-includes/default-constants.php).
 */
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

/*
 * The constants the main plugin file would define. Defined here rather than by
 * loading that file, because loading it also calls Plugin::boot() and registers
 * the whole plugin, which a unit test of a date helper has no business doing.
 */
define( 'QEVM_VERSION', '26.0' );
define( 'QEVM_DB_VERSION', 10 );
define( 'QEVM_FILE', ABSPATH . 'quick-events-manager.php' );
define( 'QEVM_PATH', ABSPATH );
define( 'QEVM_URL', 'https://example.test/wp-content/plugins/quick-events-manager/' );
define( 'QEVM_BASENAME', 'quick-events-manager/quick-events-manager.php' );
define( 'QEVM_POST_TYPE', 'qevm_event' );
define( 'QEVM_POST_TYPE_VENUE', 'qevm_venue' );
define( 'QEVM_POST_TYPE_ORGANIZER', 'qevm_organizer' );
define( 'QEVM_TAX_CATEGORY', 'qevm_event_category' );
define( 'QEVM_TAX_TAG', 'qevm_event_tag' );
define( 'QEVM_OPTION_MODULES', 'qevm_enabled_modules' );
define( 'QEVM_OPTION_SETTINGS', 'qevm_settings' );
define( 'QEVM_OPTION_DB_VERSION', 'qevm_db_version' );

require_once ABSPATH . 'includes/Autoloader.php';

QuickEventsManager\Autoloader::register();
