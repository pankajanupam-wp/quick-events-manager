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
	 * Registered hooks, as array( type, hook, callback, priority ).
	 *
	 * @var array
	 */
	public static $hooks = array();

	/**
	 * Stored options.
	 *
	 * @var array
	 */
	public static $options = array();

	/**
	 * Post meta, as post_id => key => value.
	 *
	 * @var array
	 */
	public static $meta = array();

	/**
	 * Posts, as id => object.
	 *
	 * @var array
	 */
	public static $posts = array();

	/**
	 * Reset everything between tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$hooks   = array();
		self::$options = array();
		self::$meta    = array();
		self::$posts   = array();
	}
}

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Stubs mirror core signatures.

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	WP_Stub_State::$hooks[] = array( 'action', $hook, $callback, $priority );

	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	WP_Stub_State::$hooks[] = array( 'filter', $hook, $callback, $priority );

	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function do_action( $hook ) {}

function add_shortcode( $tag, $callback ) {}

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, WP_Stub_State::$options ) ? WP_Stub_State::$options[ $name ] : $default_value;
}

function update_option( $name, $value ) {
	WP_Stub_State::$options[ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( WP_Stub_State::$options[ $name ] );

	return true;
}

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

function update_post_meta( $post_id, $key, $value ) {
	WP_Stub_State::$meta[ $post_id ][ $key ] = $value;

	return true;
}

function get_post( $post ) {
	if ( is_object( $post ) ) {
		return $post;
	}

	return WP_Stub_State::$posts[ $post ] ?? null;
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/quick-events-manager/';
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}

function __( $text, $domain = 'default' ) {
	return $text;
}

function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_email( $value ) {
	return filter_var( (string) $value, FILTER_SANITIZE_EMAIL );
}

function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max );
}

function current_user_can( $cap, ...$args ) {
	return true;
}

function get_current_user_id() {
	return 0;
}

function date_i18n( $format, $timestamp = null, $gmt = false ) {
	return gmdate( $format, null === $timestamp ? time() : $timestamp );
}

function number_format_i18n( $number ) {
	return number_format( (float) $number );
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}

	return $out;
}

// phpcs:enable Squiz.Commenting.FunctionComment.Missing

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
	 * Constructor.
	 *
	 * @param int    $id        Post id.
	 * @param string $post_type Post type.
	 */
	public function __construct( $id = 0, $post_type = '' ) {
		$this->ID        = $id;
		$this->post_type = $post_type;
	}
}

/**
 * A minimal WP_Error stand-in.
 */
class WP_Error {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Error data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $data    Error data.
	 */
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * Error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * Error message.
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->message;
	}
}

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Stub of a core function.

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// phpcs:enable Squiz.Commenting.FunctionComment.Missing

/*
 * The constants the main plugin file would define. Defined here rather than by
 * loading that file, because loading it also calls Plugin::boot() and registers
 * the whole plugin, which a unit test of a date helper has no business doing.
 */
define( 'QEVM_VERSION', '26.0' );
define( 'QEVM_DB_VERSION', '1' );
define( 'QEVM_FILE', ABSPATH . 'quick-events-manager.php' );
define( 'QEVM_PATH', ABSPATH );
define( 'QEVM_URL', 'https://example.test/wp-content/plugins/quick-events-manager/' );
define( 'QEVM_BASENAME', 'quick-events-manager/quick-events-manager.php' );
define( 'QEVM_POST_TYPE', 'qevm_event' );
define( 'QEVM_TAX_CATEGORY', 'qevm_event_category' );
define( 'QEVM_TAX_TAG', 'qevm_event_tag' );
define( 'QEVM_OPTION_MODULES', 'qevm_enabled_modules' );
define( 'QEVM_OPTION_SETTINGS', 'qevm_settings' );
define( 'QEVM_OPTION_DB_VERSION', 'qevm_db_version' );

require_once ABSPATH . 'includes/Autoloader.php';

QuickEventsManager\Autoloader::register();
