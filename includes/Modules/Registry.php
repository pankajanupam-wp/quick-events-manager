<?php
/**
 * Knows every module that exists and which ones are switched on.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * The list of feature modules, and the gate in front of each one.
 *
 * @since 26.0
 */
final class Registry {

	/**
	 * All known modules, keyed by id.
	 *
	 * @var Module[]
	 */
	private $modules = array();

	/**
	 * Ids of the modules that have had register() called this request.
	 *
	 * @var string[]
	 */
	private $booted = array();

	/**
	 * Build the registry with the modules this release ships.
	 *
	 * Modules are constructed here but not registered: constructing one must
	 * stay side-effect free, or a disabled module would still be doing work.
	 *
	 * @since 26.0
	 */
	public function __construct() {
		$modules = array(
			new \QuickEventsManager\Events\EventsModule(),
			new \QuickEventsManager\Registration\RegistrationModule(),
		);

		/**
		 * Filter the feature modules available on the Features screen.
		 *
		 * This is the extension point for add-on plugins: return your own
		 * object implementing QuickEventsManager\Modules\Module and it gains a toggle, an
		 * activation routine and the same enabled/disabled guarantees as a
		 * bundled module.
		 *
		 * @since 26.0
		 *
		 * @param Module[] $modules Module instances.
		 */
		$modules = apply_filters( 'qevm_modules', $modules );

		foreach ( $modules as $module ) {
			if ( $module instanceof Module ) {
				$this->modules[ $module->id() ] = $module;
			}
		}
	}

	/**
	 * Every known module, enabled or not.
	 *
	 * @since 26.0
	 *
	 * @return Module[] Keyed by id.
	 */
	public function all() {
		return $this->modules;
	}

	/**
	 * A single module by id.
	 *
	 * @since 26.0
	 *
	 * @param string $id Module id.
	 * @return Module|null
	 */
	public function get( $id ) {
		return isset( $this->modules[ $id ] ) ? $this->modules[ $id ] : null;
	}

	/**
	 * Ids of the modules currently switched on.
	 *
	 * Required modules are always included, whatever the option says, so a
	 * corrupted or hand-edited option cannot switch off the events themselves.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public function enabled_ids() {
		$stored = get_option( QEVM_OPTION_MODULES, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$enabled = array();

		foreach ( $this->modules as $id => $module ) {
			if ( $module->is_required() || in_array( $id, $stored, true ) ) {
				$enabled[] = $id;
			}
		}

		return $enabled;
	}

	/**
	 * Whether a module is switched on.
	 *
	 * @since 26.0
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function is_enabled( $id ) {
		return in_array( $id, $this->enabled_ids(), true );
	}

	/**
	 * Call register() on every enabled module, once.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function boot() {
		foreach ( $this->enabled_ids() as $id ) {
			if ( in_array( $id, $this->booted, true ) ) {
				continue;
			}

			$this->booted[] = $id;
			$this->modules[ $id ]->register();
		}
	}

	/**
	 * Switch a module on, running its one-time setup.
	 *
	 * Returns false for an unknown id rather than throwing, because the caller
	 * is a form submission and an unknown id means tampering, not a bug.
	 *
	 * @since 26.0
	 *
	 * @param string $id Module id.
	 * @return bool Whether the module is now enabled.
	 */
	public function enable( $id ) {
		$module = $this->get( $id );

		if ( null === $module ) {
			return false;
		}

		if ( ! $this->is_enabled( $id ) ) {
			$stored   = (array) get_option( QEVM_OPTION_MODULES, array() );
			$stored[] = $id;

			update_option( QEVM_OPTION_MODULES, array_values( array_unique( $stored ) ) );

			$module->activate();

			/**
			 * Fires after a module has been switched on and set itself up.
			 *
			 * @since 26.0
			 *
			 * @param string $id Module id.
			 */
			do_action( 'qevm_module_enabled', $id );
		}

		return true;
	}

	/**
	 * Switch a module off.
	 *
	 * @since 26.0
	 *
	 * @param string $id Module id.
	 * @return bool Whether the module is now disabled.
	 */
	public function disable( $id ) {
		$module = $this->get( $id );

		if ( null === $module || $module->is_required() ) {
			return false;
		}

		if ( $this->is_enabled( $id ) ) {
			$stored = array_diff( (array) get_option( QEVM_OPTION_MODULES, array() ), array( $id ) );

			update_option( QEVM_OPTION_MODULES, array_values( $stored ) );

			$module->deactivate();

			/**
			 * Fires after a module has been switched off.
			 *
			 * @since 26.0
			 *
			 * @param string $id Module id.
			 */
			do_action( 'qevm_module_disabled', $id );
		}

		return true;
	}
}
