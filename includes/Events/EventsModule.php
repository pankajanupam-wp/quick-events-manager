<?php
/**
 * The core module: events themselves.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Events;

use QuickEventsManager\Install\Installer;
use QuickEventsManager\Modules\Module;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a site gets on a fresh install, and the only module that cannot
 * be switched off.
 *
 * @since 26.0
 */
final class EventsModule implements Module {

	/**
	 * Module id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id() {
		return 'events';
	}

	/**
	 * Module title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Events', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create events with dates, times, locations and categories, and list them on your site.', 'quick-events-manager' );
	}

	/**
	 * Disclosure level.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Core;
	}

	/**
	 * Core cannot be switched off.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required() {
		return true;
	}

	/**
	 * Add the module's hooks.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( PostType::class, 'register_post_type' ) );
		add_action( 'init', array( PostType::class, 'register_taxonomies' ) );
		add_action( 'init', array( Meta::class, 'register' ) );

		( new Query() )->register();
		( new OccurrenceQuery() )->register();
		( new OccurrenceSync() )->register();
		( new \QuickEventsManager\Frontend\Shortcodes() )->register();
		( new \QuickEventsManager\Frontend\Assets() )->register();
		( new \QuickEventsManager\Frontend\SingleEvent() )->register();
		( new \QuickEventsManager\Frontend\Schema() )->register();
		( new \QuickEventsManager\Frontend\Ics() )->register();
		( new \QuickEventsManager\Blocks\Blocks() )->register();
		( new \QuickEventsManager\Rest\EventsController() )->register();

		if ( is_admin() ) {
			( new MetaBox() )->register();
			( new AdminColumns() )->register();
			( new Duplicator() )->register();
		}
	}

	/**
	 * Create the occurrences table.
	 *
	 * Unlike the registration module's table this always exists, because this
	 * module cannot be switched off — every event has at least one date, so
	 * every install needs somewhere indexed to put it.
	 *
	 * Safe to run repeatedly: dbDelta() compares against the existing schema.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Installer::run_schema( self::schema() );
	}

	/**
	 * The occurrences table definition.
	 *
	 * Dates live here rather than in post meta because wp_postmeta cannot
	 * answer a date range efficiently: meta_value is an unindexed longtext, and
	 * WP_Meta_Query's `'type' => 'DATETIME'` wraps it in a CAST, which defeats
	 * any index even where one exists. The old query was four postmeta
	 * self-joins, a CAST in the WHERE and a filesort. See
	 * docs/adr/0003-occurrence-table.md.
	 *
	 * end_utc is NOT NULL, and an event with no stated end stores its start
	 * here. That single decision is what collapses "is this still upcoming"
	 * from a three-branch OR into one range condition — and status_end is the
	 * index that makes the range a seek rather than a scan. Both halves are
	 * needed: the Stage 1 gate shipped four indexes that all led with
	 * start_utc, so `end_utc >= now` had nothing to seek on and read all 10,000
	 * rows at every selectivity. Measurements are in
	 * docs/development-plan.md#stage-1.
	 *
	 * status leads because every date query constrains it, and putting it first
	 * keeps the status test inside the index instead of on each row fetched.
	 *
	 * series_uuid and is_exception are created empty and stay empty until
	 * recurrence lands in stage 6. They cost nothing now and save altering a
	 * large table later.
	 *
	 * recurrence_id was not anticipated in stage 1 and is added in C6.2. It holds
	 * the UTC start of the slot the rule generated, and it is what a row is
	 * identified by — because `start_utc` stops identifying anything the moment a
	 * single occurrence can be moved, which is exactly what stage 6 adds. It is
	 * nullable rather than defaulted so a one-off event's row is distinguishable
	 * from a generated one that happens to start at the epoch. See
	 * docs/adr/0015-recurrence-identity-and-overrides.md.
	 *
	 * @since 26.0
	 *
	 * @return string CREATE TABLE statement for dbDelta().
	 */
	public static function schema() {
		$table   = Installer::table( 'occurrences' );
		$collate = Installer::charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			series_uuid char(36) NOT NULL DEFAULT '',
			recurrence_id datetime DEFAULT NULL,
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			start_local datetime NOT NULL,
			end_local datetime NOT NULL,
			timezone varchar(64) NOT NULL,
			all_day tinyint(1) NOT NULL DEFAULT 0,
			is_exception tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY start_utc (start_utc),
			KEY event_start (event_id, start_utc),
			KEY series_start (series_uuid, start_utc),
			KEY status_start (status, start_utc),
			KEY status_end (status, end_utc)
		) {$collate};";
	}

	/**
	 * Never called: this module is required.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {}
}
