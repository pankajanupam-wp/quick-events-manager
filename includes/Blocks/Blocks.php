<?php
/**
 * Block registration.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Blocks;

use QuickEventsManager\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the three dynamic blocks.
 *
 * Every block renders in PHP through a render_callback, so the front end
 * downloads no block JavaScript at all — the editor script is the only script
 * this plugin ships, and it never loads for visitors.
 *
 * @since 26.0
 */
final class Blocks {

	/**
	 * Blocks this plugin provides, mapped to their renderer.
	 *
	 * The value is a Renderer method name, not a function name.
	 *
	 * @var array<string, string>
	 */
	const BLOCKS = array(
		'event-list'         => 'event_list',
		'event-details'      => 'event_details',
		'event-registration' => 'registration_form',
		'event-calendar'     => 'calendar',
	);

	/**
	 * Blocks that belong to a module, mapped to the module's id.
	 *
	 * A block whose module is switched off is not registered at all, so it does
	 * not appear in the inserter. The renderers already refuse to output
	 * anything without their module, which meant the block could be inserted,
	 * showed nothing in the editor, saved nothing to the page, and gave no
	 * reason for any of it — the site owner is left assuming the block is
	 * broken rather than that a feature is off.
	 *
	 * Blocks not listed here are core and always available.
	 *
	 * @var array<string, string>
	 */
	const MODULE_BLOCKS = array(
		'event-registration' => \QuickEventsManager\Registration\RegistrationModule::ID,
		'event-calendar'     => \QuickEventsManager\Calendar\CalendarModule::ID,
	);

	/**
	 * Hook into block registration.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register each block from its block.json.
	 *
	 * Passing a directory path to register_block_type() makes it read
	 * block.json, which is what gives the editor the block's attributes, title
	 * and category without duplicating them in PHP. It arrived in WordPress 5.8;
	 * on anything older the blocks are simply absent and the shortcodes still
	 * work.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$registry = \QuickEventsManager\Plugin::instance()->registry();

		foreach ( self::BLOCKS as $name => $renderer ) {
			if ( isset( self::MODULE_BLOCKS[ $name ] ) && ! $registry->is_enabled( self::MODULE_BLOCKS[ $name ] ) ) {
				continue;
			}

			$metadata = QEVM_PATH . 'build/' . $name;

			if ( ! is_readable( $metadata . '/block.json' ) ) {
				continue;
			}

			$type = register_block_type(
				$metadata,
				array(
					'render_callback' => static function ( $attributes ) use ( $renderer ) {
						return call_user_func( array( Renderer::class, $renderer ), (array) $attributes );
					},
				)
			);

			self::translate( $type );
		}
	}

	/**
	 * Let the editor scripts be translated.
	 *
	 * **Without this every string in the block editor is English, permanently.**
	 * `load_plugin_textdomain()` covers PHP and does nothing for JavaScript:
	 * scripts need their own JSON translation files, and WordPress only loads
	 * those for handles that have been told which text domain and directory to
	 * look in. Nothing here did, so the four blocks' labels, help text and
	 * placeholders were untranslatable — invisible on an English site and
	 * absolute on any other. Found by the C10.6 sweep.
	 *
	 * @since 26.0
	 *
	 * @param mixed $type What register_block_type() returned.
	 * @return void
	 */
	private static function translate( $type ) {
		if ( ! function_exists( 'wp_set_script_translations' ) || ! is_object( $type ) ) {
			return;
		}

		$handles = isset( $type->editor_script_handles ) ? (array) $type->editor_script_handles : array();

		foreach ( $handles as $handle ) {
			wp_set_script_translations( (string) $handle, 'quick-events-manager', QEVM_PATH . 'languages' );
		}
	}
}
