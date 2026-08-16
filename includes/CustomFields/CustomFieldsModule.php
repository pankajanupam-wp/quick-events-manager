<?php
/**
 * The custom registration fields feature module.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\CustomFields;

use QuickEventsManager\Install\Installer;
use QuickEventsManager\Modules\Module;

use QuickEventsManager\Domain\ModuleLevel;

defined( 'ABSPATH' ) || exit;

/**
 * Extra questions on the registration form.
 *
 * Off on a fresh install. The default form asks for a name and an email address
 * because that is what most events need, and every question added past that is
 * one more reason somebody abandons the form.
 *
 * **This module does not depend on the registration module**, deliberately,
 * even though questions with no form to appear on are of limited use.
 * Modules never depend on each other — where the dependency looks real it is on
 * the domain, which is always present. Wiring the two together would mean this
 * one has to know whether that one is enabled, and then so does every screen
 * either of them draws. Defining questions for a form that is switched off is
 * harmless; the questions are simply never asked.
 *
 * @since 26.0
 */
final class CustomFieldsModule implements Module {

	/**
	 * Module id, stored in the enabled-modules option.
	 */
	const ID = 'custom_fields';

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
		return __( 'Custom registration questions', 'quick-events-manager' );
	}

	/**
	 * Module description.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Ask your own questions when somebody registers — dietary needs, a session choice, anything you need to know.', 'quick-events-manager' );
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
			( new FieldsMetaBox() )->register();
		}
	}

	/**
	 * Create the answers table.
	 *
	 * Definitions live in post meta and need nothing. Answers get a table, and
	 * it is created here rather than at activation so an install that never
	 * asks a question of its own never grows one.
	 *
	 * Safe to run repeatedly — `dbDelta()` compares against what is already
	 * there — which matters because this is called again on every schema
	 * upgrade and every time the module is switched off and on.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function activate() {
		Installer::run_schema( AnswerRepository::schema() );
	}

	/**
	 * Switching off leaves every question and every answer in place.
	 *
	 * The questions stop being asked and stop being editable. Nothing is
	 * deleted, so switching back on restores the forms exactly as they were.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function deactivate() {}
}
