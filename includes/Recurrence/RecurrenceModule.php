<?php
/**
 * The recurring events feature module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Domain\ModuleLevel;
use QuickEventsManager\Modules\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Events that repeat.
 *
 * Off on a fresh install. Somebody publishing a list of meetups should not be
 * handed recurrence rules, exclusion dates and a "this and following" prompt —
 * and with this module off, no event has a rule, nothing generates, and the
 * horizon task is not on the schedule.
 *
 * **The gate is at the generator, not at the event's meta.** An event can carry a
 * recurrence rule from a previous life — the module was on and has been switched
 * off, or the rule arrived through the REST API. Gating on "does this event have
 * a rule" would keep generating for those, so the check is at
 * `OccurrenceSync::build_recurring()`, the one place every generation passes
 * through. This is the same mistake C3.4 made with the registration form, where
 * the gate was on the per-event meta and the form rendered with the module off.
 *
 * Switching it off leaves every occurrence row where it is. A site that turns
 * recurrence off keeps the dates it already had — they stop being regenerated,
 * which is not the same as being deleted, and an organiser who wanted them gone
 * would have said so.
 *
 * @since 26.0
 */
final class RecurrenceModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'recurrence';

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
		return __( 'Recurring events', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Repeat an event daily, weekly, monthly or yearly, with dates you can skip and individual dates you can move or call off.', 'quick-events-manager' );
	}

	/**
	 * Disclosure level.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Extended;
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
	public function register() {
		( new Horizon() )->register();

		if ( is_admin() ) {
			( new RepeatBox() )->register();
			( new DatesScreen() )->register();
		}
	}

	/**
	 * Whether the site has switched recurrence on.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return \QuickEventsManager\Plugin::instance()->registry()->is_enabled( self::ID );
	}

	/**
	 * Nothing to create.
	 *
	 * Recurrence needs no table of its own: `qevm_occurrences` already exists for
	 * every event, and a recurring one simply has more rows in it. The one column
	 * recurrence added, `recurrence_id`, belongs to the occurrences table and is
	 * created with it, because a nullable column on a table that already exists
	 * costs nothing and a schema that changes when a module is toggled is a
	 * schema nobody can reason about.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Horizon::schedule();
	}

	/**
	 * Switching off leaves every date where it is.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {
		Horizon::unschedule();
	}
}
