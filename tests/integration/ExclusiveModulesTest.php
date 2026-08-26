<?php
/**
 * Modules that cannot be on at the same time.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\ModuleLevel;
use QuickEventsManager\Modules\Exclusive;
use QuickEventsManager\Modules\Module;
use QuickEventsManager\Modules\Registry;

/**
 * Switching one on switches the other off, from either end.
 *
 * The pair that needs this is WooCommerce and the built-in gateway: both want
 * to own taking money for a ticket, and with both on neither fails loudly — the
 * site owner is left with two payment paths and no way to know which one a
 * customer used.
 */
final class ExclusiveModulesTest extends TestCase {

	/**
	 * Register two modules that refuse to share a site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		add_filter(
			'qevm_modules',
			static function ( $modules ) {
				$modules[] = new FakeTill();
				$modules[] = new FakeOtherTill();
				$modules[] = new FakeHarmless();

				return $modules;
			}
		);
	}

	/**
	 * Put the module list back the way the site had it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( 'qevm_modules' );

		parent::tearDown();
	}

	/**
	 * A registry that can see the fakes.
	 *
	 * Built here rather than reached through the plugin singleton, whose own
	 * registry was constructed at boot and reads its module list once. Adding a
	 * reload() to production code so that a test can see its fixtures would be
	 * the test dictating the design.
	 *
	 * @return Registry
	 */
	private function registry(): Registry {
		return new Registry();
	}

	/**
	 * Switching one on switches the other off.
	 *
	 * @return void
	 */
	public function test_enabling_one_disables_the_other() {
		$registry = $this->registry();

		$registry->enable( 'fake_till' );

		$this->assertTrue( $registry->is_enabled( 'fake_till' ) );

		$registry->enable( 'fake_other_till' );

		$this->assertTrue( $registry->is_enabled( 'fake_other_till' ) );
		$this->assertFalse( $registry->is_enabled( 'fake_till' ), 'two tills is worse than either' );
	}

	/**
	 * Declaring it at one end is enough.
	 *
	 * Only one of these two names the other. Requiring both to say it is how
	 * the two lists drift apart, and the one that drifts is the one nobody
	 * reads.
	 *
	 * @return void
	 */
	public function test_the_conflict_works_from_the_other_end_too() {
		$registry = $this->registry();

		$registry->enable( 'fake_other_till' );
		$registry->enable( 'fake_till' );

		$this->assertTrue( $registry->is_enabled( 'fake_till' ) );
		$this->assertFalse( $registry->is_enabled( 'fake_other_till' ) );
	}

	/**
	 * Everything else is left alone.
	 *
	 * @return void
	 */
	public function test_unrelated_modules_are_untouched() {
		$registry = $this->registry();

		$registry->enable( 'fake_harmless' );
		$registry->enable( 'fake_till' );
		$registry->enable( 'fake_other_till' );

		$this->assertTrue( $registry->is_enabled( 'fake_harmless' ), 'a calendar has no opinion about a till' );
	}

	/**
	 * The one being switched off gets its deactivate() run.
	 *
	 * @return void
	 */
	public function test_the_displaced_module_is_shut_down_properly() {
		$registry = $this->registry();

		$registry->enable( 'fake_till' );

		FakeTill::$shut_down = false;

		$registry->enable( 'fake_other_till' );

		$this->assertTrue( FakeTill::$shut_down, 'switched off, not merely forgotten' );
	}
}

/**
 * A module that owns a checkout.
 */
final class FakeTill implements Module, Exclusive {

	/**
	 * Whether deactivate() ran.
	 *
	 * @var bool
	 */
	public static $shut_down = false;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'fake_till';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title() {
		return 'Fake till';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return 'Takes money.';
	}

	/**
	 * Level.
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Advanced;
	}

	/**
	 * Not required.
	 *
	 * @return bool
	 */
	public function is_required() {
		return false;
	}

	/**
	 * No hooks.
	 *
	 * @return void
	 */
	public function register() {
	}

	/**
	 * No schema.
	 *
	 * @return void
	 */
	public function activate() {
	}

	/**
	 * Remember being switched off.
	 *
	 * @return void
	 */
	public function deactivate() {
		self::$shut_down = true;
	}

	/**
	 * What it cannot live beside.
	 *
	 * @return array<int, string>
	 */
	public function conflicts(): array {
		return array( 'fake_other_till' );
	}
}

/**
 * Another module that owns a checkout, which does not name the first.
 */
final class FakeOtherTill implements Module {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'fake_other_till';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title() {
		return 'Fake other till';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return 'Also takes money.';
	}

	/**
	 * Level.
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Advanced;
	}

	/**
	 * Not required.
	 *
	 * @return bool
	 */
	public function is_required() {
		return false;
	}

	/**
	 * No hooks.
	 *
	 * @return void
	 */
	public function register() {
	}

	/**
	 * No schema.
	 *
	 * @return void
	 */
	public function activate() {
	}

	/**
	 * Nothing to undo.
	 *
	 * @return void
	 */
	public function deactivate() {
	}
}

/**
 * A module with no opinion about anything.
 */
final class FakeHarmless implements Module {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'fake_harmless';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function title() {
		return 'Fake harmless';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return 'Shows a calendar.';
	}

	/**
	 * Level.
	 */
	public function level(): ModuleLevel {
		return ModuleLevel::Standard;
	}

	/**
	 * Not required.
	 *
	 * @return bool
	 */
	public function is_required() {
		return false;
	}

	/**
	 * No hooks.
	 *
	 * @return void
	 */
	public function register() {
	}

	/**
	 * No schema.
	 *
	 * @return void
	 */
	public function activate() {
	}

	/**
	 * Nothing to undo.
	 *
	 * @return void
	 */
	public function deactivate() {
	}
}
