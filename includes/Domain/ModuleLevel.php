<?php
/**
 * How far into the plugin a feature sits.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The progressive-disclosure tier a module belongs to.
 *
 * The Features screen groups modules by level so that somebody who wants a list
 * of meetups is never shown a payment gateway. The case names are for
 * developers; the titles below are what a site owner reads.
 *
 * Backed by int so the ordering is the enum's own, rather than something each
 * caller has to remember to sort by.
 *
 * @since 26.0
 */
enum ModuleLevel: int {

	/**
	 * Always on. This is what the plugin is.
	 */
	case Core = 0;

	/**
	 * The first thing most sites want after publishing events.
	 */
	case Standard = 1;

	/**
	 * More control over how registration works and how events look.
	 */
	case Extended = 2;

	/**
	 * Larger or more complex events.
	 */
	case Advanced = 3;

	/**
	 * Section heading on the Features screen.
	 *
	 * @since 26.0
	 */
	public function title(): string {
		return match ( $this ) {
			self::Core     => __( 'The basics', 'quick-events-manager' ),
			self::Standard => __( 'Taking registrations', 'quick-events-manager' ),
			self::Extended => __( 'Going further', 'quick-events-manager' ),
			self::Advanced => __( 'Advanced', 'quick-events-manager' ),
		};
	}

	/**
	 * One line under the heading, explaining who the section is for.
	 *
	 * @since 26.0
	 */
	public function description(): string {
		return match ( $this ) {
			self::Core     => __( 'Everything you need to publish events. This is always on.', 'quick-events-manager' ),
			self::Standard => __( 'Let people sign up, and keep track of who is coming.', 'quick-events-manager' ),
			self::Extended => __( 'More control over how registration works and how your events look.', 'quick-events-manager' ),
			self::Advanced => __( 'For larger or more complex events.', 'quick-events-manager' ),
		};
	}

	/**
	 * Every level, lowest first.
	 *
	 * @since 26.0
	 *
	 * @return self[]
	 */
	public static function all(): array {
		return array( self::Core, self::Standard, self::Extended, self::Advanced );
	}

	/**
	 * Resolve an untrusted value, falling back to the deepest level.
	 *
	 * A module registered by another plugin through the `qevm_modules` filter
	 * could return anything. Treating an unknown level as Advanced keeps it off
	 * the first screen a beginner sees, which is the safer default.
	 *
	 * @since 26.0
	 *
	 * @param mixed $value Raw value.
	 */
	public static function coerce( mixed $value ): self {
		if ( $value instanceof self ) {
			return $value;
		}

		return is_int( $value ) ? ( self::tryFrom( $value ) ?? self::Advanced ) : self::Advanced;
	}
}
