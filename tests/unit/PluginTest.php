<?php
/**
 * Covers release metadata and shipped-file hygiene.
 *
 * The classic way to break a WordPress release is to bump one of the three
 * version numbers and forget the other two. WordPress.org serves whatever the
 * readme's stable tag points at, so a mismatch publishes a version that does
 * not exist.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\TestCase;

/**
 * Release and packaging guards.
 */
final class PluginTest extends TestCase {

	/**
	 * Read a file from the plugin root.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function plugin_file( $name ) {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $name );
	}

	/**
	 * Pull a header value out of a file.
	 *
	 * @param string $contents File contents.
	 * @param string $field    Header name.
	 * @return string
	 */
	private function header_value( $contents, $field ) {
		$found = preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $contents, $matches );

		return $found ? trim( $matches[1] ) : '';
	}

	/**
	 * Every PHP file that ships to wordpress.org.
	 *
	 * @return string[] Absolute paths.
	 */
	private function shipped_php_files() {
		$root      = dirname( __DIR__, 2 );
		$excluded  = array( '/tests/', '/vendor/', '/node_modules/', '/src/', '/docs/' );
		$found     = array();
		$directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );

		foreach ( new RecursiveIteratorIterator( $directory ) as $file ) {
			$path = $file->getPathname();

			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			foreach ( $excluded as $fragment ) {
				if ( false !== strpos( $path, $fragment ) ) {
					continue 2;
				}
			}

			$found[] = $path;
		}

		return $found;
	}

	/**
	 * The plugin header version, the constant and the readme stable tag must
	 * agree, or wordpress.org serves the wrong build.
	 *
	 * @return void
	 */
	public function test_version_is_consistent_across_the_release_metadata() {
		$header_version = $this->header_value( $this->plugin_file( 'quick-events-manager.php' ), 'Version' );
		$stable_tag     = $this->header_value( $this->plugin_file( 'readme.txt' ), 'Stable tag' );

		$this->assertNotSame( '', $header_version, 'Plugin header is missing a Version.' );
		$this->assertSame( $header_version, QEM_VERSION, 'QEM_VERSION is out of sync with the plugin header.' );
		$this->assertSame( $header_version, $stable_tag, 'Readme stable tag is out of sync with the plugin header.' );
	}

	/**
	 * Version 1.0's readme had no stable tag at all, which makes
	 * wordpress.org fall back to trunk and serve whatever happens to be there.
	 *
	 * @return void
	 */
	public function test_readme_declares_a_stable_tag() {
		$stable_tag = $this->header_value( $this->plugin_file( 'readme.txt' ), 'Stable tag' );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+(\.\d+)?$/', $stable_tag );
	}

	/**
	 * Support claims in the header and the readme must match each other.
	 *
	 * @dataProvider shared_header_provider
	 *
	 * @param string $field Header name present in both files.
	 * @return void
	 */
	public function test_support_headers_match_the_readme( $field ) {
		$this->assertSame(
			$this->header_value( $this->plugin_file( 'quick-events-manager.php' ), $field ),
			$this->header_value( $this->plugin_file( 'readme.txt' ), $field ),
			sprintf( '"%s" differs between the plugin header and readme.txt.', $field )
		);
	}

	/**
	 * Headers that appear in both files.
	 *
	 * @return array
	 */
	public static function shared_header_provider() {
		return array(
			array( 'Requires at least' ),
			array( 'Tested up to' ),
			array( 'Requires PHP' ),
		);
	}

	/**
	 * WordPress.org rejects readmes with more than five tags.
	 *
	 * @return void
	 */
	public function test_readme_has_at_most_five_tags() {
		$tags = $this->header_value( $this->plugin_file( 'readme.txt' ), 'Tags' );
		$tags = array_filter( array_map( 'trim', explode( ',', $tags ) ) );

		$this->assertLessThanOrEqual( 5, count( $tags ) );
		$this->assertNotEmpty( $tags );
	}

	/**
	 * The short description is truncated by wordpress.org past 150 characters.
	 *
	 * @return void
	 */
	public function test_readme_short_description_fits() {
		$readme = $this->plugin_file( 'readme.txt' );

		preg_match( '/^Stable tag:.*$\n(?:^[A-Za-z ]+:.*$\n)*\s*(.+)$/m', $readme, $matches );

		$this->assertNotEmpty( $matches, 'Could not find the short description.' );
		$this->assertLessThanOrEqual( 150, strlen( trim( $matches[1] ) ) );
	}

	/**
	 * Every released version needs a changelog entry.
	 *
	 * @return void
	 */
	public function test_changelog_documents_the_current_version() {
		$this->assertMatchesRegularExpression(
			'/^=\s*' . preg_quote( QEM_VERSION, '/' ) . '\s*=$/m',
			$this->plugin_file( 'readme.txt' ),
			'readme.txt has no changelog entry for the current version.'
		);
	}

	/**
	 * The GPL is a distribution requirement for wordpress.org.
	 *
	 * @return void
	 */
	public function test_license_is_declared_and_shipped() {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/LICENSE' );

		$this->assertStringContainsString(
			'GPL',
			$this->header_value( $this->plugin_file( 'quick-events-manager.php' ), 'License' )
		);
		$this->assertStringContainsString(
			'GPL',
			$this->header_value( $this->plugin_file( 'readme.txt' ), 'License' )
		);
	}

	/**
	 * Deleting the plugin has to clean up after itself, and only then.
	 *
	 * @return void
	 */
	public function test_uninstall_handler_is_guarded() {
		$uninstall = $this->plugin_file( 'uninstall.php' );

		$this->assertStringContainsString( 'WP_UNINSTALL_PLUGIN', $uninstall );
		$this->assertStringContainsString( 'qem_settings', $uninstall );
		$this->assertStringContainsString( 'is_multisite', $uninstall );
	}

	/**
	 * Every shipped file must refuse to run when loaded directly, or it
	 * executes outside WordPress with none of its functions defined.
	 *
	 * @return void
	 */
	public function test_shipped_files_are_guarded() {
		foreach ( $this->shipped_php_files() as $path ) {
			$contents = (string) file_get_contents( $path );
			$name     = basename( $path );

			$guard = 'uninstall.php' === $name ? 'WP_UNINSTALL_PLUGIN' : 'ABSPATH';

			$this->assertStringContainsString(
				$guard,
				$contents,
				sprintf( '%s has no %s guard.', $path, $guard )
			);
		}
	}

	/**
	 * Development files must not reach the published package.
	 *
	 * @dataProvider excluded_path_provider
	 *
	 * @param string $path Path that must be excluded.
	 * @return void
	 */
	public function test_distignore_excludes_development_files( $path ) {
		$distignore = $this->plugin_file( '.distignore' );

		$this->assertMatchesRegularExpression(
			'/^' . preg_quote( $path, '/' ) . '$/m',
			$distignore,
			sprintf( '.distignore does not exclude %s.', $path )
		);
	}

	/**
	 * Paths that must never ship.
	 *
	 * @return array
	 */
	public static function excluded_path_provider() {
		return array(
			array( 'tests' ),
			array( 'docs' ),
			array( 'src' ),
			array( 'node_modules' ),
			array( 'vendor' ),
			array( 'composer.json' ),
			array( 'package.json' ),
			array( 'phpunit.xml.dist' ),
			array( 'README.md' ),
			array( '.wp-env.json' ),
		);
	}

	/**
	 * The built block assets must NOT be excluded — the blocks do not work
	 * without them, and they are the one build artefact that has to ship.
	 *
	 * @return void
	 */
	public function test_distignore_does_not_exclude_the_build_output() {
		$this->assertDoesNotMatchRegularExpression(
			'/^build$/m',
			$this->plugin_file( '.distignore' ),
			'The build directory holds the compiled blocks and must ship.'
		);
	}

	/**
	 * The post type key has to stay inside core's 20-character limit, and must
	 * never change once released — it is written into wp_posts.
	 *
	 * @return void
	 */
	public function test_post_type_key_is_valid_and_stable() {
		$this->assertSame( 'qem_event', QEM_POST_TYPE );
		$this->assertLessThanOrEqual( 20, strlen( QEM_POST_TYPE ) );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', QEM_POST_TYPE );
	}

	/**
	 * Taxonomy keys have a 32-character limit.
	 *
	 * @return void
	 */
	public function test_taxonomy_keys_are_valid() {
		foreach ( array( QEM_TAX_CATEGORY, QEM_TAX_TAG ) as $taxonomy ) {
			$this->assertLessThanOrEqual( 32, strlen( $taxonomy ) );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $taxonomy );
		}
	}
}
