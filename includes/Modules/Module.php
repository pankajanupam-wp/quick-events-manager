<?php
/**
 * The contract every feature module implements.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Modules;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * A unit of functionality the site owner can switch on or off.
 *
 * The plugin's organising idea is that a fresh install does one thing — events
 * — and everything beyond that is opted into. A module that is switched off
 * never has register() called, so it adds no hooks, registers no post types,
 * creates no tables and enqueues no assets. The toggle is a real boundary, not
 * a display filter over code that runs anyway.
 *
 * @since 26.0
 */
interface Module {

	/**
	 * Stable identifier, stored in the enabled-modules option.
	 *
	 * Never change one once released: it is persisted per site, and changing
	 * it silently switches the module off everywhere.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human-readable name for the Features screen.
	 *
	 * @since 26.0
	 *
	 * @return string Translated.
	 */
	public function title();

	/**
	 * One sentence explaining what switching this on gives you.
	 *
	 * @since 26.0
	 *
	 * @return string Translated.
	 */
	public function description();

	/**
	 * Which disclosure level this module belongs to.
	 *
	 * 0 is core and always on. 1 is the first step past the basics, and so on.
	 * The Features screen groups by this so someone who wants the simple thing
	 * never has to read past the top of the page.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel;

	/**
	 * Whether this module can be switched off.
	 *
	 * Core cannot; everything else can.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public function is_required();

	/**
	 * Add the module's hooks.
	 *
	 * Called on every request the module is enabled for, and never otherwise.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register();

	/**
	 * One-time setup when the module is switched on.
	 *
	 * Create tables, add capabilities, seed options. Must be safe to run more
	 * than once — a site owner can toggle a module off and on again.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate();

	/**
	 * Tidy up when the module is switched off.
	 *
	 * Must never destroy user data. Switching registration off has to leave
	 * the attendee rows intact, because switching it back on is expected to
	 * restore them. Data is only ever removed by uninstall.php.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate();
}
