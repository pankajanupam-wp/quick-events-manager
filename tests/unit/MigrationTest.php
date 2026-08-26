<?php
/**
 * The migration framework.
 *
 * These cover the parts that can be reasoned about without a database: the
 * ordering, the version bookkeeping, and the contract every migration has to
 * meet. Whether a migration actually moves rows is an integration question and
 * is answered in tests/integration/.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Install\Migrations\LegacyPostType;
use QuickEventsManager\Install\Migrations\Migration;
use QuickEventsManager\Install\Migrations\Runner;

/**
 * Versioning, ordering and the migration contract.
 */
#[CoversClass( Runner::class )]
#[CoversClass( LegacyPostType::class )]
final class MigrationTest extends TestCase {

	/**
	 * Start each test from an unmigrated site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub_State::reset();
	}

	/**
	 * The constant must never be behind the migrations.
	 *
	 * A migration added without bumping QEVM_DB_VERSION never runs, because the
	 * runner would already consider the site up to date. This is the check that
	 * makes step 4 of the checklist in docs/migrations.md enforceable.
	 *
	 * The relationship is "at least", not "equal": a release can change the
	 * schema without needing a data migration behind it. C1.2 is exactly that —
	 * it adds the occurrences table, which dbDelta creates, and there is no
	 * existing data to transform because nothing has been published yet. The
	 * version still has to move, or `needs_upgrade()` stays false and the table
	 * is never created on an existing install.
	 *
	 * @return void
	 */
	public function test_db_version_constant_is_never_behind_the_migrations() {
		$this->assertGreaterThanOrEqual(
			Runner::highest_migration_version(),
			QEVM_DB_VERSION,
			'QEVM_DB_VERSION is behind a registered migration, so that migration would never run.'
		);
	}

	/**
	 * A schema bump with no migration behind it still triggers an upgrade.
	 *
	 * This is the case that broke in C1.2 and was caught only against a real
	 * database. The occurrences table needs no data migration, so the highest
	 * migration stayed at 1 while QEVM_DB_VERSION moved to 2 — and
	 * needs_upgrade() was comparing against the migrations, so it answered
	 * false and the table was never created on an existing install.
	 *
	 * @return void
	 */
	public function test_a_schema_bump_without_a_migration_still_needs_an_upgrade() {
		$this->assertGreaterThanOrEqual(
			QEVM_DB_VERSION,
			Runner::target_version(),
			'target_version() must never be below the schema version.'
		);

		update_option( QEVM_OPTION_DB_VERSION, QEVM_DB_VERSION - 1 );
		$this->assertTrue( Runner::needs_upgrade() );

		update_option( QEVM_OPTION_DB_VERSION, QEVM_DB_VERSION );
		$this->assertFalse( Runner::needs_upgrade() );
	}

	/**
	 * Versions are unique, positive, and ordered.
	 *
	 * @return void
	 */
	public function test_migration_versions_are_unique_and_ordered() {
		$versions = array_map(
			static fn( Migration $migration ): int => $migration->version(),
			Runner::migrations()
		);

		$this->assertNotEmpty( $versions );
		$this->assertSame( array_unique( $versions ), $versions, 'Two migrations share a version.' );

		$sorted = $versions;
		sort( $sorted );
		$this->assertSame( $sorted, $versions, 'migrations() must return them in ascending order.' );

		foreach ( $versions as $version ) {
			$this->assertGreaterThan( 0, $version, 'Version 0 means "nothing has run" and cannot be a migration.' );
		}
	}

	/**
	 * Every migration says what it does.
	 *
	 * @return void
	 */
	public function test_every_migration_has_a_description() {
		foreach ( Runner::migrations() as $migration ) {
			$this->assertNotSame(
				'',
				trim( $migration->description() ),
				get_class( $migration ) . ' has no description.'
			);
		}
	}

	/**
	 * A site with no stored version is treated as having run nothing.
	 *
	 * This is the reading that matters for a 1.0 site: 1.0 never wrote the
	 * option, so its absence has to mean zero rather than "current".
	 *
	 * @return void
	 */
	public function test_a_site_with_no_stored_version_is_at_zero() {
		$this->assertSame( 0, Runner::current_version() );
		$this->assertTrue( Runner::needs_upgrade() );
		$this->assertCount( count( Runner::migrations() ), Runner::pending() );
	}

	/**
	 * A site at the target version has nothing outstanding.
	 *
	 * @return void
	 */
	public function test_a_current_site_has_nothing_pending() {
		update_option( QEVM_OPTION_DB_VERSION, Runner::target_version() );

		$this->assertFalse( Runner::needs_upgrade() );
		$this->assertSame( array(), Runner::pending() );
	}

	/**
	 * Only migrations above the stored version are pending.
	 *
	 * @return void
	 */
	public function test_pending_excludes_what_has_already_run() {
		update_option( QEVM_OPTION_DB_VERSION, 1 );

		foreach ( Runner::pending() as $migration ) {
			$this->assertGreaterThan( 1, $migration->version() );
		}
	}

	/**
	 * A version stored as a string still compares as a number.
	 *
	 * The option was written as the string '1' before C1.1, and an option round
	 * trip through the database returns a string regardless. Comparing '10'
	 * against 9 as strings would say 9 is larger.
	 *
	 * @return void
	 */
	public function test_a_string_version_is_read_as_an_integer() {
		update_option( QEVM_OPTION_DB_VERSION, '1' );

		$this->assertSame( 1, Runner::current_version() );
	}

	/**
	 * Migration 1 is the legacy post type move, at version 1.
	 *
	 * Pinned literally: this number is stored on sites in the field, so it is a
	 * contract rather than an implementation detail.
	 *
	 * @return void
	 */
	public function test_legacy_post_type_is_migration_one() {
		$migration = new LegacyPostType();

		$this->assertSame( 1, $migration->version() );
		$this->assertSame( 'events', LegacyPostType::LEGACY_POST_TYPE );
	}
}
