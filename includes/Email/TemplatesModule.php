<?php
/**
 * The editable email templates feature module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Email;

use QuickEventsManager\Modules\Module;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the site owner rewrite the emails this plugin sends.
 *
 * Off on a fresh install, and the built-in wording is what everybody gets until
 * it is switched on. That is deliberate rather than lazy: a template edited once
 * is a template that stops improving, so a site which never needed to change the
 * words keeps receiving whatever the current release says.
 *
 * @since 26.0
 */
final class TemplatesModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'email_templates';

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
		return __( 'Email templates', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Write your own wording for the emails this plugin sends, with an HTML option.', 'quick-events-manager' );
	}

	/**
	 * Which disclosure level this module belongs to.
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
		if ( is_admin() ) {
			( new TemplatesScreen() )->register();
		}
	}

	/**
	 * Switching on needs no setup.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {}

	/**
	 * Switching off returns every email to its built-in wording.
	 *
	 * What was written is kept, not deleted, so switching back on restores it.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {}
}
