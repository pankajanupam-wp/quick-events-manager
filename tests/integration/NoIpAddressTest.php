<?php
/**
 * The promise that no IP address is stored, checked against the database.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Install\Installer;

/**
 * AC-5: no IP address exists in any table or option.
 *
 * `readme.txt` tells wordpress.org users that the plugin stores no IP
 * addresses, and the rate limiter is built around that claim — it hashes the
 * address with `wp_salt()` into a transient key precisely so the address
 * itself never lands anywhere. A claim like that is worth exactly as much as
 * the thing that checks it.
 *
 * This inspects the schema and the stored data rather than the source, because
 * the failure this guards against is somebody adding an `ip` column in good
 * faith three stages from now. Reading the code would not catch that; reading
 * the database does.
 */
final class NoIpAddressTest extends TestCase {

	/**
	 * Put the request back as it was.
	 *
	 * PHP has no request boundary inside a test run, so a `$_SERVER` value set
	 * here stays set for every test that follows — and the rate limiter reads
	 * `REMOTE_ADDR` on every public booking. Harmless today, and exactly the
	 * kind of leak that makes an unrelated test fail six months from now for a
	 * reason nobody can find.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CLIENT_IP'] );

		parent::tearDown();
	}

	/**
	 * Column names that would hold an address, however they are spelled.
	 */
	private const FORBIDDEN = array(
		'ip',
		'ip_address',
		'ipaddress',
		'remote_addr',
		'remote_ip',
		'client_ip',
		'user_ip',
		'visitor_ip',
	);

	/**
	 * No table the plugin owns has a column for an address.
	 *
	 * @return void
	 */
	public function test_no_plugin_table_has_a_column_for_an_address() {
		global $wpdb;

		foreach ( $this->tables() as $table ) {
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );

			foreach ( (array) $columns as $column ) {
				$this->assertNotContains(
					strtolower( (string) $column ),
					self::FORBIDDEN,
					sprintf(
						'%s.%s looks like it stores an IP address, which readme.txt promises the plugin does not.',
						$table,
						$column
					)
				);
			}
		}
	}

	/**
	 * A real booking writes no address into its row.
	 *
	 * The column check above catches a new column. This catches an address
	 * smuggled into an existing one — the `fields` JSON is the obvious place,
	 * because it takes anything.
	 *
	 * @return void
	 */
	public function test_a_booking_stores_no_address_anywhere_in_its_row() {
		global $wpdb;

		$this->quieten_registration();

		$address                   = '203.0.113.42';
		$_SERVER['REMOTE_ADDR']    = $address;
		$_SERVER['HTTP_CLIENT_IP'] = $address;

		$registration = $this->book( $this->make_event() );

		$this->assertNotWPError( $registration );

		foreach ( $this->tables() as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$this->assertStringNotContainsString(
					$address,
					(string) wp_json_encode( $row ),
					'the submitting address ended up in ' . $table
				);
			}
		}
	}

	/**
	 * The rate limiter remembers the visitor without storing them.
	 *
	 * It has to tell one submitter from another, which is the point at which
	 * most implementations write the address down. This one hashes it with
	 * `wp_salt()` and keeps only the digest, so the option row that backs the
	 * transient cannot be read back into an address.
	 *
	 * @return void
	 */
	public function test_the_rate_limiter_stores_a_digest_and_not_an_address() {
		global $wpdb;

		$address                = '198.51.100.7';
		$_SERVER['REMOTE_ADDR'] = $address;

		// Deliberately not quietened: the limiter is what has to run.
		add_filter( 'qevm_attendee_email', '__return_empty_array' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$this->assertNotWPError( $this->book( $this->make_event() ) );

		$options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%qevm%'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertNotEmpty( $options, 'the limiter should have recorded something' );

		foreach ( $options as $option ) {
			$this->assertStringNotContainsString( $address, $option['option_name'] );
			$this->assertStringNotContainsString( $address, (string) $option['option_value'] );
		}
	}

	/**
	 * No plugin option holds an address either.
	 *
	 * @return void
	 */
	public function test_no_plugin_option_holds_an_address() {
		global $wpdb;

		$options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'qevm%' OR option_name LIKE '_transient_qevm%'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( (array) $options as $option ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
				(string) $option['option_value'],
				$option['option_name'] . ' contains something shaped like an IPv4 address'
			);
		}
	}

	/**
	 * Every table the plugin owns that currently exists.
	 *
	 * @return string[]
	 */
	private function tables() {
		global $wpdb;

		$tables = array();

		foreach ( array( 'occurrences', 'registrations', 'attendees' ) as $name ) {
			$table = Installer::table( $name );

			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found ) {
				$tables[] = $table;
			}
		}

		$this->assertNotEmpty( $tables, 'no plugin tables were found to inspect' );

		return $tables;
	}
}
