<?php
/**
 * The calendar feature module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Calendar;

use QuickEventsManager\Modules\Module;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * A month grid, for sites that want one.
 *
 * Off on a fresh install, like everything past core. A list of the next few
 * meetups is what most sites need, and a calendar is a large piece of UI to put
 * in front of somebody who did not ask for it.
 *
 * @since 26.0
 */
final class CalendarModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'calendar';

	/**
	 * Module id.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * Module title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Calendar view', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Show your events as a month grid, with a list view alongside it.', 'quick-events-manager' );
	}

	/**
	 * Which disclosure level this module belongs to.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Standard;
	}

	/**
	 * Can be switched off.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required() {
		return false;
	}

	/**
	 * Add the module's hooks.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {}

	/**
	 * Switching on needs no setup.
	 *
	 * The calendar reads the occurrence table, which core events already
	 * maintain, so there is nothing to create and nothing to backfill.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {}

	/**
	 * Switching off removes the calendar and nothing else.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {}
}
