<?php
/**
 * The ticketing module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tickets;

use QuickEventsManager\Domain\ModuleLevel;
use QuickEventsManager\Install\Installer;
use QuickEventsManager\Modules\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket types, off until somebody asks for them.
 *
 * The chunk definition says "table + repository + admin UI" and does not say
 * module, exactly as C6.3's did not. It has to be one: an install that lists a
 * few meetups should not grow a ticketing screen, and the architecture's whole
 * claim is that a disabled module registers no hooks, creates no tables and
 * shows no UI.
 *
 * Switching it off leaves every type and every ticket in place. The types stop
 * being offered and stop being editable; nothing is deleted, so switching back
 * on restores exactly what was there.
 *
 * @since 26.0
 */
final class TicketsModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'tickets';

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
		return __( 'Ticket types', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Offer more than one kind of place — member and guest, full and concession — each with its own capacity.', 'quick-events-manager' );
	}

	/**
	 * Which disclosure level this module belongs to.
	 *
	 * @since 26.0
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Advanced;
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
		if ( is_admin() ) {
			( new TicketTypesBox() )->register();
		}
	}

	/**
	 * Create the ticket types table.
	 *
	 * Safe to run repeatedly — `dbDelta()` compares against what is already
	 * there — which matters because this runs again on every schema upgrade and
	 * every time the module is switched off and on.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Installer::run_schema( TicketTypeRepository::schema() );
	}

	/**
	 * Switching off sells nothing and deletes nothing.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {
	}

	/**
	 * Whether the site has switched ticketing on.
	 *
	 * @since 26.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return \QuickEventsManager\Plugin::instance()->registry()->is_enabled( self::ID );
	}
}
