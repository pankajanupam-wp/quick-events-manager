<?php
/**
 * Activating across a network.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Install\Installer;
use QuickEventsManager\Plugin;

/**
 * Every site on a network needs its own setup, and only the first one got it.
 *
 * Found in C10.5 by converting the throwaway test site to a two-site network and
 * activating across it. Site 2 came up with the code loaded, the post type
 * registered, and **no roles at all** — because activation is one hook call for
 * the whole network while everything it does is per site: the tables carry the
 * site's own prefix, the roles live in the site's own options table, and the
 * rewrite rules are the site's own.
 *
 * A site added to the network *afterwards* got nothing either, which is the same
 * failure arriving later and even less visibly.
 *
 * **These tests do not need a network to be useful.** They assert the shape of
 * the fix — that activation is per site and that new sites are hooked — which is
 * what a single-site suite can check and what a regression would break. The
 * behaviour itself was verified against a real two-site network, and
 * docs/development-plan.md records what that run showed.
 */
final class NetworkActivationTest extends TestCase {

	/**
	 * Activation accepts the flag WordPress passes it.
	 *
	 * `register_activation_hook` hands the callback `$network_wide`. A callback
	 * that does not take it cannot tell a network activation from an ordinary
	 * one, which is precisely how every site but the first was missed.
	 *
	 * @return void
	 */
	public function test_activation_is_told_whether_it_is_network_wide() {
		$activate = new \ReflectionMethod( Plugin::class, 'activate' );

		$this->assertGreaterThanOrEqual(
			1,
			$activate->getNumberOfParameters(),
			'activation must be able to see the network-wide flag'
		);

		$this->assertSame( 'network_wide', $activate->getParameters()[0]->getName() );
	}

	/**
	 * Setting one site up is a separate step from deciding which sites to do.
	 *
	 * @return void
	 */
	public function test_one_site_can_be_set_up_on_its_own() {
		$new_site = new \ReflectionMethod( Plugin::class, 'activate_new_site' );

		$this->assertTrue( $new_site->isPublic(), 'a hook callback has to be reachable' );
		$this->assertSame(
			1,
			$new_site->getNumberOfParameters(),
			'wp_initialize_site hands over the site it just made'
		);
	}

	/**
	 * A new site on a network is hooked up.
	 *
	 * @return void
	 */
	public function test_a_new_site_is_hooked() {
		$this->assertNotFalse(
			has_action( 'wp_initialize_site', array( Plugin::class, 'activate_new_site' ) ),
			'wp_initialize_site is the only thing that fires for a site created tomorrow'
		);
	}

	/**
	 * The single-site path still works, and is what an ordinary install takes.
	 *
	 * A fix that sets up a network and breaks the one-site case is not a fix.
	 *
	 * @return void
	 */
	public function test_a_single_site_activation_still_installs() {
		Plugin::activate();

		$this->assertTrue( post_type_exists( QEVM_POST_TYPE ) );
		$this->assertNotNull( get_role( 'qevm_event_staff' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'edit_qevm_events' ) );
	}

	/**
	 * Activation builds the tables, not just the roles.
	 *
	 * The defect this exists for: activation called `install()` — capabilities
	 * and roles, no tables — and then the migration runner, which stamps the
	 * stored schema version as current. `maybe_upgrade()` is the only other
	 * thing that creates tables and it runs only when that version is behind,
	 * so on a brand-new site it never ran again. The occurrences table was
	 * never created at all, and both the event archive and the calendar read
	 * it: a new user would have installed this, made an event, and found an
	 * empty listing.
	 *
	 * Nothing caught it because every environment it was developed in had been
	 * through a version bump, which is exactly when `maybe_upgrade()` does
	 * fire. So the table is dropped here first — that is the state a fresh
	 * install is in, and the only state where this can be observed.
	 *
	 * @return void
	 */
	public function test_activation_creates_the_tables_a_fresh_site_has_none_of() {
		global $wpdb;

		$table = Installer::table( 'occurrences' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the state a fresh install is in.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		/*
		 * And the stored version says everything is current, which is the other
		 * half of the trap: with it set, nothing else will ever build the table.
		 */
		update_option( QEVM_OPTION_DB_VERSION, QEVM_DB_VERSION, false );

		Plugin::activate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asking whether activation built it.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		$this->assertSame( $table, $exists, 'activation must create the tables an always-on module owns' );

		$this->restore_schema();
	}
}
