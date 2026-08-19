<?php
/**
 * Where the admin stylesheet loads.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Admin\Assets;

/**
 * A stylesheet that loads on one screen out of six is not a stylesheet.
 *
 * This is written against the bug rather than the fix: `admin.css` was enqueued
 * by the event editor alone, so the attendee screen's own styles — and the
 * broadcast box added to it two stages later — had never been applied on the
 * screen they were written for. Nothing reports that. The markup is right, the
 * class names are right, and the page just looks like unstyled HTML.
 */
final class AdminAssetsTest extends TestCase {

	/**
	 * Forget any stylesheet a previous test left enqueued.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wp_dequeue_style( Assets::HANDLE );

		parent::tearDown();
	}

	/**
	 * Every screen this plugin owns gets the stylesheet.
	 *
	 * @return void
	 */
	public function test_the_plugins_own_screens_get_the_stylesheet() {
		foreach ( $this->our_screens() as $description => $screen ) {
			wp_dequeue_style( Assets::HANDLE );

			$this->on_screen( $screen );

			$this->assertTrue(
				wp_style_is( Assets::HANDLE, 'enqueued' ),
				'the stylesheet did not load on ' . $description
			);
		}
	}

	/**
	 * Somebody else's screen does not.
	 *
	 * @return void
	 */
	public function test_other_screens_do_not() {
		foreach ( array( 'edit-post', 'options-general', 'plugins', 'dashboard' ) as $id ) {
			wp_dequeue_style( Assets::HANDLE );

			$this->on_screen( array( 'id' => $id ) );

			$this->assertFalse(
				wp_style_is( Assets::HANDLE, 'enqueued' ),
				'the stylesheet loaded on ' . $id
			);
		}
	}

	/**
	 * The screens the plugin owns, and how each one says so.
	 *
	 * Two kinds, because the plugin has two kinds: the editor and list carry
	 * the post type, and the submenu pages carry a `qevm-` slug.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function our_screens() {
		return array(
			'the event editor'     => array(
				'id'        => QEVM_POST_TYPE,
				'post_type' => QEVM_POST_TYPE,
			),
			'the event list'       => array(
				'id'        => 'edit-' . QEVM_POST_TYPE,
				'post_type' => QEVM_POST_TYPE,
			),
			'the attendees screen' => array(
				'id'        => QEVM_POST_TYPE . '_page_qevm-attendees',
				'post_type' => QEVM_POST_TYPE,
			),
			'the dates screen'     => array(
				'id'        => QEVM_POST_TYPE . '_page_qevm-dates',
				'post_type' => QEVM_POST_TYPE,
			),
			'a settings page'      => array( 'id' => 'settings_page_qevm-settings' ),
			'the features screen'  => array( 'id' => 'toplevel_page_qevm-features' ),
		);
	}

	/**
	 * Pretend to be on a screen, and let the loader decide.
	 *
	 * @param array<string, string> $screen Screen properties.
	 * @return void
	 */
	private function on_screen( array $screen ) {
		$current = \WP_Screen::get( isset( $screen['id'] ) ? $screen['id'] : 'dashboard' );

		if ( isset( $screen['post_type'] ) ) {
			$current->post_type = $screen['post_type'];
		}

		$GLOBALS['current_screen'] = $current; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for a screen the loader has to read.

		( new Assets() )->enqueue( 'irrelevant' );
	}
}
