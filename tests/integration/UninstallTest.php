<?php
/**
 * What removing the plugin does to a site's data.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Registration\Repository;

/**
 * The most destructive thing this plugin can do, and it used to do it silently.
 *
 * `uninstall.php` dropped every table unconditionally. Somebody removing the
 * plugin to try a different one for an afternoon lost every registration, every
 * attendee and every answer — with no warning, and no setting they could have
 * used to say otherwise. docs/database.md had promised the opposite since before
 * any of it was written, and an audit in stage 7 found the contradiction.
 *
 * It was resolved in favour of the promise, because that is the half that cannot
 * be undone if it turns out to be the wrong choice.
 *
 * **These tests run uninstall against the real database**, so each one puts the
 * schema back afterwards. That is also why they assert on a table that exists in
 * every install rather than a module's.
 */
final class UninstallTest extends TestCase {

	/**
	 * Run the uninstall routine the way WordPress does.
	 *
	 * @return void
	 */
	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'quick-events-manager/quick-events-manager.php' );
		}

		/*
		 * Included afresh each time: the file defines functions at the top
		 * level, so a second include would fatal on redeclaration. Calling the
		 * function directly once it exists is the same code path.
		 */
		if ( ! function_exists( 'qevm_uninstall_site' ) ) {
			require dirname( __DIR__, 2 ) . '/uninstall.php';

			return;
		}

		qevm_uninstall_site();
	}

	/**
	 * With the switch off — the default — nothing is removed.
	 *
	 * @return void
	 */
	public function test_by_default_nothing_is_deleted() {
		$this->set_delete_on_uninstall( false );

		$event_id = $this->make_event();

		$this->book( $event_id );

		$this->uninstall();

		$this->assertTrue( Repository::table_exists(), 'the bookings table should still be there' );
		$this->assertSame( 1, $this->count_rows( 'registrations', 'event_id = ' . (int) $event_id ) );
	}

	/**
	 * And with it on, everything goes.
	 *
	 * @return void
	 */
	public function test_with_the_switch_on_the_tables_go() {
		/*
		 * Which modules this site had, so the tables can be rebuilt afterwards.
		 * Restoring a hardcoded list instead left every *other* module's table
		 * missing for the rest of the run — which showed up three test files
		 * later as the WooCommerce bridge failing to make a product, because
		 * there was no ticket types table to read.
		 */
		$enabled = get_option( QEVM_OPTION_MODULES, array() );

		$this->set_delete_on_uninstall( true );

		$this->book( $this->make_event() );

		$this->uninstall();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asking whether uninstall did its job.
		$left = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'qevm_registrations' ) );

		$this->assertNull( $left, 'the bookings table should be gone' );

		/*
		 * Uninstall deletes the enabled-modules option along with everything
		 * else, and `restore_schema()` rebuilds the tables of whichever modules
		 * are enabled — so without putting the option back first it rebuilds
		 * nothing, and every test after this one runs against tables that are
		 * not there. That shows up as a wall of "table doesn't exist" rather
		 * than as a failure, which is worse.
		 */
		update_option( QEVM_OPTION_MODULES, $enabled );

		$this->restore_schema();

		$this->assertTrue( Repository::table_exists(), 'and the fixture is whole again for the next test' );
	}

	/**
	 * The default really is off, in the defaults rather than by accident.
	 *
	 * A default that happens to be falsey because the key is missing is not the
	 * same as a decision, and only one of the two survives somebody adding an
	 * array_merge somewhere.
	 *
	 * @return void
	 */
	public function test_the_default_is_off() {
		$defaults = \QuickEventsManager\Admin\Settings::defaults();

		$this->assertArrayHasKey( 'delete_on_uninstall', $defaults );
		$this->assertFalse( $defaults['delete_on_uninstall'] );
	}

	/**
	 * Saving the settings screen with the box unticked means no.
	 *
	 * @return void
	 */
	public function test_unticking_the_box_is_honoured() {
		$this->set_delete_on_uninstall( true );

		$saved = ( new \QuickEventsManager\Admin\Settings() )->sanitize(
			array(
				'delete_on_uninstall_present' => '1',
			)
		);

		$this->assertFalse( $saved['delete_on_uninstall'] );
	}

	/**
	 * A save from a screen that never showed the box leaves it alone.
	 *
	 * The field only renders when registration is on. A site with it off saving
	 * anything else must not quietly change what happens to its data.
	 *
	 * @return void
	 */
	public function test_a_save_without_the_field_changes_nothing() {
		$this->set_delete_on_uninstall( true );

		$saved = ( new \QuickEventsManager\Admin\Settings() )->sanitize( array( 'auto_details' => '1' ) );

		$this->assertTrue( $saved['delete_on_uninstall'], 'absent is not the same as unticked' );
	}

	/**
	 * Write the switch.
	 *
	 * @param bool $on Whether to delete on uninstall.
	 * @return void
	 */
	private function set_delete_on_uninstall( bool $on ) {
		$settings = (array) get_option( QEVM_OPTION_SETTINGS, array() );

		$settings['delete_on_uninstall'] = $on;

		update_option( QEVM_OPTION_SETTINGS, $settings );
	}
}
