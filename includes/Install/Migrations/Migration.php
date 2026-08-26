<?php
/**
 * What every migration has to be able to answer.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Install\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * One numbered, forward-only step from one schema version to the next.
 *
 * There are no down migrations. A migration that turns out to be wrong is
 * corrected by a later migration, never by reversing it: a site that has already
 * run the bad one and a site that has not must both end up in the same place,
 * and only rolling forward achieves that. See docs/migrations.md.
 *
 * @since 26.0
 */
interface Migration {

	/**
	 * The schema version this migration brings a site up to.
	 *
	 * Unique across all migrations, and never reused or renumbered — it is the
	 * value written to the database once this migration finishes, and sites in
	 * the field will already be holding it.
	 *
	 * @since 26.0
	 *
	 * @return int
	 */
	public function version(): int;

	/**
	 * One line describing what this migration does, for logs and WP-CLI.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Process at most one batch of work.
	 *
	 * Called repeatedly until it reports nothing left to do, so it must find
	 * its own remaining work each time rather than being handed an offset. A
	 * stored offset goes wrong the moment the underlying rows change; asking
	 * "what is still unmigrated?" cannot.
	 *
	 * That requirement is also what makes a migration idempotent for free: if
	 * the query for remaining work returns nothing, the migration is complete,
	 * whether it ran a moment ago or never at all.
	 *
	 * @since 26.0
	 *
	 * @param int $batch_size Maximum rows to process in this call.
	 * @return int Rows processed. Zero means there is nothing left to do.
	 */
	public function run( int $batch_size ): int;
}
