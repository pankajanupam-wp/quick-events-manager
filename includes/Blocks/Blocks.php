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
	 * @var array<string, callable-string>
	 */
	const BLOCKS = array(
		'event-list'         => 'event_list',
		'event-details'      => 'event_details',
		'event-registration' => 'registration_form',
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

		foreach ( self::BLOCKS as $name => $renderer ) {
			$metadata = QEVM_PATH . 'build/' . $name;

			if ( ! is_readable( $metadata . '/block.json' ) ) {
				continue;
			}

			register_block_type(
				$metadata,
				array(
					'render_callback' => static function ( $attributes ) use ( $renderer ) {
						return call_user_func( array( Renderer::class, $renderer ), (array) $attributes );
					},
				)
			);
		}
	}
}
