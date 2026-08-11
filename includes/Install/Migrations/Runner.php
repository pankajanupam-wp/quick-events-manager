<?php
/**
 * Applies pending migrations, in order, within a request's budget.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Install\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that decides which migrations still need to run, and runs them.
 *
 * Two things make this safe to call on every admin request. It compares one
 * stored integer before doing anything, so the ordinary case costs a single
 * option read. And it works in batches inside a time budget, so a migration
 * larger than one request can survive being cut off — the next request picks up
 * whatever is left rather than starting again.
 *
 * The version is only written once a migration reports itself complete. A
 * request that dies halfway through leaves the stored version where it was, and
 * the migration is re-entered from its own query for remaining work.
 *
 * @since 26.0
 */
final class Runner {

	/**
	 * Rows processed per batch, before the time budget is checked again.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Seconds of work to attempt in a single request.
	 *
	 * Deliberately short. This runs on `admin_init`, so the budget is time an
	 * administrator spends watching a page load, not time PHP is willing to
	 * allow.
	 */
	const TIME_BUDGET = 10;

	/**
	 * How long the lock survives if the process holding it dies.
	 */
	const LOCK_TTL = 300;

	/**
	 * Option holding the lock.
	 */
	const LOCK_OPTION = 'qevm_migration_lock';

	/**
	 * Every migration, in the order they must run.
	 *
	 * Listed explicitly rather than discovered from the filesystem: a directory
	 * scan makes the running order depend on file names, and a migration that
	 * silently stops being registered because it was renamed is exactly the
	 * failure this must not have.
	 *
	 * @since 26.0
	 *
	 * @return Migration[]
	 */
	public static function migrations(): array {
		$migrations = array(
			new LegacyPostType(),
			new BookerColumns(),
		);

		usort(
			$migrations,
			static fn( Migration $a, Migration $b ): int => $a->version() <=> $b->version()
		);

		return $migrations;
	}

	/**
	 * The highest migration version this codebase contains.
	 *
	 * Derived from the migrations themselves rather than read from a constant,
	 * so adding a migration cannot be half-done.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public static function highest_migration_version(): int {
		$migrations = self::migrations();

		if ( empty( $migrations ) ) {
			return 0;
		}

		return end( $migrations )->version();
	}

	/**
	 * The version a fully upgraded site ends up holding.
	 *
	 * The larger of the schema version and the highest migration, because the
	 * two move for different reasons. A release can change the schema without
	 * needing any data transformed — C1.2 adds the occurrences table, which
	 * dbDelta creates and which has no existing data to convert. That still has
	 * to advance the stored version, or the upgrade path never runs and the
	 * table is never created on an existing install.
	 *
	 * Taking the maximum rather than trusting either one alone means neither
	 * can be forgotten: a migration above the constant still runs, and a
	 * constant above the migrations still triggers the schema pass.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public static function target_version(): int {
		return max( (int) QEVM_DB_VERSION, self::highest_migration_version() );
	}

	/**
	 * The version this site has completed.
	 *
	 * Absent means zero, which is the correct reading for both a site upgrading
	 * from 1.0 and a fresh install: neither has run anything, and every
	 * migration is a no-op against data that is not there.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public static function current_version(): int {
		return (int) get_option( QEVM_OPTION_DB_VERSION, 0 );
	}

	/**
	 * Whether anything is outstanding.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function needs_upgrade(): bool {
		return self::current_version() < self::target_version();
	}

	/**
	 * Migrations this site has not finished yet.
	 *
	 * @since 26.0
	 *
	 * @return Migration[]
	 */
	public static function pending(): array {
		$current = self::current_version();

		return array_values(
			array_filter(
				self::migrations(),
				static fn( Migration $migration ): bool => $migration->version() > $current
			)
		);
	}

	/**
	 * Apply what is pending, as far as the time budget allows.
	 *
	 * @since 26.0
	 *
	 * @return int Number of migrations completed in this call.
	 */
	public static function run(): int {
		if ( ! self::needs_upgrade() ) {
			return 0;
		}

		if ( ! self::lock() ) {
			return 0;
		}

		$completed = 0;
		$finished  = true;
		$deadline  = microtime( true ) + self::TIME_BUDGET;

		try {
			foreach ( self::pending() as $migration ) {
				if ( ! self::apply( $migration, $deadline ) ) {
					// Out of time. The rest waits for the next request.
					$finished = false;
					break;
				}

				update_option( QEVM_OPTION_DB_VERSION, $migration->version(), false );
				++$completed;

				/**
				 * Fires after a migration has finished and been recorded.
				 *
				 * @since 26.0
				 *
				 * @param int    $version     Version now stored.
				 * @param string $description What the migration did.
				 */
				do_action( 'qevm_migration_completed', $migration->version(), $migration->description() );
			}

			/*
			 * Advance to the schema version once every migration is through.
			 *
			 * Without this a release that changed the schema but needed no data
			 * migration would leave the stored version at the highest migration
			 * — below the target — so needs_upgrade() would stay true and the
			 * schema pass would run again on every single admin request,
			 * forever. The tables would be correct and the site would be slow
			 * for the rest of its life.
			 */
			if ( $finished ) {
				update_option( QEVM_OPTION_DB_VERSION, self::target_version(), false );
			}
		} finally {
			self::unlock();
		}

		return $completed;
	}

	/**
	 * Run one migration to completion, or until the deadline passes.
	 *
	 * @since 26.0
	 *
	 * @param Migration $migration Migration to run.
	 * @param float     $deadline  microtime() value to stop at.
	 * @return bool True if the migration finished.
	 */
	private static function apply( Migration $migration, float $deadline ): bool {
		$batch_size = (int) apply_filters( 'qevm_migration_batch_size', self::BATCH_SIZE, $migration->version() );
		$batch_size = max( 1, $batch_size );

		do {
			$processed = $migration->run( $batch_size );

			if ( 0 === $processed ) {
				return true;
			}
		} while ( microtime( true ) < $deadline );

		/*
		 * The deadline passed with work still outstanding. Returning false
		 * leaves the stored version alone, so the next request re-enters this
		 * same migration and asks it again what is left.
		 */
		return false;
	}

	/**
	 * Take the lock, if it is free.
	 *
	 * Two administrators loading the dashboard at the same moment would
	 * otherwise both start migrating. Every migration is required to be
	 * idempotent, so the result would still be correct — but it would be
	 * correct after doing the work twice, and on a large table that is the
	 * difference between a slow page and a timeout.
	 *
	 * This is a hand-written INSERT rather than add_option(), because
	 * add_option() is not atomic: it reads the option first and inserts only if
	 * it was missing, so two requests can both pass the check. Reading core
	 * (wp-includes/option.php) shows the insert itself is
	 * `INSERT ... ON DUPLICATE KEY UPDATE`, which succeeds either way. Going
	 * straight to `INSERT IGNORE` and asking how many rows changed is the part
	 * that is genuinely atomic, because `option_name` carries a UNIQUE key.
	 *
	 * @since 26.0
	 *
	 * @return bool Whether this process now holds the lock.
	 */
	private static function lock(): bool {
		global $wpdb;

		self::clear_expired_lock();

		/*
		 * autoload 'no' is understood as "do not autoload" by every supported
		 * version: 6.5 uses yes/no, and 6.6 onwards autoloads only the values
		 * in wp_autoload_values_to_autoload(), which 'no' is not among.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock is the point; a cached answer would defeat it.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::LOCK_OPTION,
				(string) ( time() + self::LOCK_TTL )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		/*
		 * The row was written underneath the options cache, so anything that
		 * read this option earlier in the request would still see it missing.
		 */
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return true;
	}

	/**
	 * Release the lock.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private static function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Drop a lock left behind by a process that died.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	private static function clear_expired_lock(): void {
		$expires = get_option( self::LOCK_OPTION );

		if ( false !== $expires && (int) $expires < time() ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}
