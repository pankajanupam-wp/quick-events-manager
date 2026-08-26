<?php
/**
 * Prices, where somebody choosing a ticket can see them.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Registration\RegistrationModule;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;

/**
 * The picker showed names and availability and no prices at all.
 *
 * Not an oversight: money had nowhere to be formatted until C9.2, so the form
 * deliberately said nothing about it rather than printing a raw `1999`. This is
 * that gap closing, and it is the visible half of the chunk — the other half is
 * a value object nobody sees.
 */
final class TicketPriceTest extends TestCase {

	/**
	 * Both modules on, table present, and a known currency.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->enable_registration();
		$this->switch_module_on( TicketsModule::ID );

		if ( ! TicketTypeRepository::table_exists() ) {
			( new TicketsModule() )->activate();

			$this->restore_schema();
		}

		( new TicketsModule() )->register();

		$settings             = (array) get_option( QEVM_OPTION_SETTINGS, array() );
		$settings['currency'] = 'GBP';

		update_option( QEVM_OPTION_SETTINGS, $settings );
	}

	/**
	 * A priced ticket says what it costs.
	 *
	 * @return void
	 */
	public function test_the_form_prints_the_price() {
		$event_id = $this->make_event();

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 1999,
			)
		);

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Guest',
				'price_minor' => 4950,
			)
		);

		$form = $this->render_form( $event_id );

		$this->assertStringContainsString( '£19.99', $form );
		$this->assertStringContainsString( '£49.50', $form );
		$this->assertStringNotContainsString( '1999', $form, 'a raw minor-unit figure on a page is a visible bug' );
	}

	/**
	 * A free ticket says "Free", not "£0.00".
	 *
	 * Zero is a price and "free" is what it means. Printing the arithmetic
	 * makes somebody read the number twice to find out it costs nothing.
	 *
	 * @return void
	 */
	public function test_a_free_ticket_says_free() {
		$event_id = $this->make_event();

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Standard',
				'price_minor' => 0,
			)
		);

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Supporter',
				'price_minor' => 2500,
			)
		);

		$form = $this->render_form( $event_id );

		$this->assertStringContainsString( 'Free', $form );
		$this->assertStringNotContainsString( '£0.00', $form );
		$this->assertStringContainsString( '£25.00', $form );
	}

	/**
	 * The site's currency is the one printed.
	 *
	 * @return void
	 */
	public function test_the_site_currency_is_used() {
		$settings             = (array) get_option( QEVM_OPTION_SETTINGS, array() );
		$settings['currency'] = 'INR';

		update_option( QEVM_OPTION_SETTINGS, $settings );

		$event_id = $this->make_event();

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 50000,
			)
		);

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Guest',
				'price_minor' => 75000,
			)
		);

		$this->assertStringContainsString( '₹500.00', $this->render_form( $event_id ) );
	}

	/**
	 * With ticketing off there are no prices, because there are no types.
	 *
	 * @return void
	 */
	public function test_nothing_is_priced_when_ticketing_is_off() {
		$event_id = $this->make_event();

		TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => 'Member',
				'price_minor' => 1999,
			)
		);

		update_option( QEVM_OPTION_MODULES, array( RegistrationModule::ID ) );

		remove_all_filters( 'qevm_event_ticket_types' );

		$form = $this->render_form( $event_id );

		$this->assertStringNotContainsString( '£19.99', $form );
		$this->assertStringNotContainsString( 'Which kind of place', $form );
	}

	/**
	 * Render the public form.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private function render_form( $event_id ) {
		return (string) \QuickEventsManager\Frontend\Renderer::registration_form( array( 'id' => (string) $event_id ) );
	}
}
