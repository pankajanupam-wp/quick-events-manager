<?php
/**
 * The domain enums.
 *
 * These run with no WordPress beyond the three-line i18n stub, which is the
 * point: the domain layer is testable without a database, an option table or a
 * hook system. If a test here needs more than that, something has leaked into
 * the wrong layer.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Domain\ModuleLevel;
use QuickEventsManager\Domain\RegistrationStatus;

/**
 * Covers the two state machines introduced with PHP 8.1 enums.
 */
#[CoversClass( RegistrationStatus::class )]
#[CoversClass( ModuleLevel::class )]
class DomainEnumTest extends TestCase {

	/**
	 * The backing values are what the status column stores.
	 *
	 * Pinned literally rather than derived from the enum, because the whole
	 * point is to fail if somebody changes one. Renaming a case is free;
	 * changing its value is a schema migration.
	 *
	 * @return void
	 */
	public function test_registration_status_values_are_the_stored_contract() {
		$this->assertSame( 'pending', RegistrationStatus::Pending->value );
		$this->assertSame( 'confirmed', RegistrationStatus::Confirmed->value );
		$this->assertSame( 'waitlisted', RegistrationStatus::Waitlisted->value );
		$this->assertSame( 'cancelled', RegistrationStatus::Cancelled->value );

		$this->assertSame(
			array( 'pending', 'confirmed', 'waitlisted', 'cancelled' ),
			RegistrationStatus::values()
		);
	}

	/**
	 * Only confirmed and pending hold a seat.
	 *
	 * This is the single definition capacity counting depends on. A waitlisted
	 * registration occupying a place would mean a full event never drains, and
	 * a cancelled one occupying a place would mean it never frees up.
	 *
	 * @return void
	 */
	public function test_only_confirmed_and_pending_occupy_a_place() {
		$this->assertTrue( RegistrationStatus::Confirmed->occupies_place() );
		$this->assertTrue( RegistrationStatus::Pending->occupies_place() );
		$this->assertFalse( RegistrationStatus::Waitlisted->occupies_place() );
		$this->assertFalse( RegistrationStatus::Cancelled->occupies_place() );

		$this->assertSame(
			array( 'pending', 'confirmed' ),
			RegistrationStatus::occupying_values()
		);
	}

	/**
	 * The occupying list and the per-case flag cannot disagree.
	 *
	 * @return void
	 */
	public function test_occupying_list_is_derived_not_duplicated() {
		foreach ( RegistrationStatus::all() as $status ) {
			$this->assertSame(
				$status->occupies_place(),
				in_array( $status->value, RegistrationStatus::occupying_values(), true ),
				$status->value . ' disagrees with occupying_values()'
			);
		}
	}

	/**
	 * Only a cancelled registration cannot be cancelled again.
	 *
	 * @return void
	 */
	public function test_cancellability() {
		$this->assertTrue( RegistrationStatus::Confirmed->is_cancellable() );
		$this->assertTrue( RegistrationStatus::Pending->is_cancellable() );
		$this->assertTrue( RegistrationStatus::Waitlisted->is_cancellable() );
		$this->assertFalse( RegistrationStatus::Cancelled->is_cancellable() );
	}

	/**
	 * Corrupt or hostile input falls back instead of throwing.
	 *
	 * Callers are reading a database column or a request parameter. An
	 * unrecognised value means bad data or tampering, and neither should take
	 * an admin screen down.
	 *
	 * @return void
	 */
	public function test_registration_status_coerce_falls_back() {
		$this->assertSame(
			RegistrationStatus::Waitlisted,
			RegistrationStatus::coerce( 'waitlisted' )
		);
		$this->assertSame(
			RegistrationStatus::Confirmed,
			RegistrationStatus::coerce( 'nonsense', RegistrationStatus::Confirmed )
		);
		$this->assertSame(
			RegistrationStatus::Confirmed,
			RegistrationStatus::coerce( null, RegistrationStatus::Confirmed )
		);
		$this->assertSame(
			RegistrationStatus::Confirmed,
			RegistrationStatus::coerce( 42, RegistrationStatus::Confirmed )
		);
		$this->assertNull( RegistrationStatus::coerce( 'nonsense' ) );

		// An enum passed back in comes back unchanged.
		$this->assertSame(
			RegistrationStatus::Pending,
			RegistrationStatus::coerce( RegistrationStatus::Pending )
		);
	}

	/**
	 * Every case has a distinct, non-empty label.
	 *
	 * @return void
	 */
	public function test_every_registration_status_has_a_distinct_label() {
		$labels = array_map(
			static fn( RegistrationStatus $s ): string => $s->label(),
			RegistrationStatus::all()
		);

		foreach ( $labels as $label ) {
			$this->assertNotSame( '', trim( $label ) );
		}

		$this->assertCount( count( $labels ), array_unique( $labels ) );
	}

	/**
	 * Levels are ordered by their backing int, shallowest first.
	 *
	 * The Features screen renders them in this order, so a beginner meets the
	 * basics before ticketing.
	 *
	 * @return void
	 */
	public function test_module_levels_are_ordered_shallowest_first() {
		$values = array_map(
			static fn( ModuleLevel $l ): int => $l->value,
			ModuleLevel::all()
		);

		$this->assertSame( array( 0, 1, 2, 3 ), $values );
		$this->assertSame( 0, ModuleLevel::Core->value );
	}

	/**
	 * An unknown level from a third-party module lands at the deepest tier.
	 *
	 * Modules can be registered by other plugins through the qevm_modules
	 * filter, so level() can return anything. Defaulting to Advanced keeps an
	 * unknown feature off the first screen a beginner sees.
	 *
	 * @return void
	 */
	public function test_module_level_coerce_defaults_to_advanced() {
		$this->assertSame( ModuleLevel::Standard, ModuleLevel::coerce( 1 ) );
		$this->assertSame( ModuleLevel::Advanced, ModuleLevel::coerce( 99 ) );
		$this->assertSame( ModuleLevel::Advanced, ModuleLevel::coerce( 'core' ) );
		$this->assertSame( ModuleLevel::Advanced, ModuleLevel::coerce( null ) );
		$this->assertSame( ModuleLevel::Core, ModuleLevel::coerce( ModuleLevel::Core ) );
	}

	/**
	 * Every level has a distinct title and a non-empty description.
	 *
	 * @return void
	 */
	public function test_every_module_level_has_a_distinct_title() {
		$titles = array_map(
			static fn( ModuleLevel $l ): string => $l->title(),
			ModuleLevel::all()
		);

		$this->assertCount( count( $titles ), array_unique( $titles ) );

		foreach ( ModuleLevel::all() as $level ) {
			$this->assertNotSame( '', trim( $level->description() ) );
		}
	}
}
