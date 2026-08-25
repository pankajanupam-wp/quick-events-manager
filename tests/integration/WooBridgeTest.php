<?php
/**
 * Selling a ticket through WooCommerce.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Integration;

use QuickEventsManager\Domain\RegistrationStatus;
use QuickEventsManager\Registration\Repository;
use QuickEventsManager\Tickets\TicketTypeRepository;
use QuickEventsManager\Tickets\TicketsModule;
use QuickEventsManager\Woo\Orders;
use QuickEventsManager\Woo\Products;
use QuickEventsManager\Woo\WooModule;

/**
 * Against the real WooCommerce, or not at all.
 *
 * Skipped rather than faked when Woo is absent. A fake of somebody else's plugin
 * proves that the fake matches what was assumed about it, which is exactly the
 * assumption most likely to be wrong — and this bridge is entirely made of
 * assumptions about Woo's hooks, its product API and when it fires each status.
 *
 * To run these: `wp plugin activate woocommerce` in the tests environment, and
 * give phpunit `-d memory_limit=512M`, because WordPress plus Woo plus the suite
 * does not fit in the container's default 128M.
 */
final class WooBridgeTest extends TestCase {

	/**
	 * Both modules on, or skip.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! WooModule::woo_is_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		add_filter( 'qevm_registration_rate_limit', '__return_zero' );
		add_filter( 'qevm_attendee_email', '__return_empty_array' );
		add_filter( 'qevm_organizer_email', '__return_empty_array' );

		$this->switch_module_on( TicketsModule::ID );
		$this->switch_module_on( WooModule::ID );

		if ( ! TicketTypeRepository::table_exists() ) {
			( new TicketsModule() )->activate();

			$this->restore_schema();
		}

		( new TicketsModule() )->register();
		( new WooModule() )->register();
	}

	/**
	 * A ticket type becomes a product people can buy.
	 *
	 * @return void
	 */
	public function test_a_ticket_type_becomes_a_product() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500 );

		$product_id = Products::sync( $type_id, $event_id );

		$this->assertGreaterThan( 0, $product_id );

		$product = wc_get_product( $product_id );

		$this->assertStringContainsString( 'Member', $product->get_name() );
		$this->assertSame( '25.00', $product->get_regular_price(), 'minor units become what a shop prints' );
		$this->assertTrue( $product->is_virtual(), 'nobody posts a ticket' );
		$this->assertTrue( $product->is_sold_individually(), 'one seat per purchase' );
		$this->assertSame( $type_id, Products::ticket_type_for( $product_id ) );
		$this->assertSame( $event_id, Products::event_for( $product_id ) );
	}

	/**
	 * Saving the ticket type again updates the same product.
	 *
	 * A second product for the same ticket would be two prices, two stock
	 * counts and a customer buying the wrong one.
	 *
	 * @return void
	 */
	public function test_a_second_save_updates_rather_than_duplicates() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500 );

		$first = Products::sync( $type_id, $event_id );

		TicketTypeRepository::update( $type_id, array( 'price_minor' => 3000 ) );

		$second = Products::sync( $type_id, $event_id );

		$this->assertSame( $first, $second, 'the same product' );
		$this->assertSame( '30.00', wc_get_product( $second )->get_regular_price(), 'with the new price' );
	}

	/**
	 * An archived ticket type stops being sold without vanishing.
	 *
	 * @return void
	 */
	public function test_an_archived_type_is_taken_off_sale() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500 );

		$product_id = Products::sync( $type_id, $event_id );

		TicketTypeRepository::archive( $type_id );

		Products::sync( $type_id, $event_id );

		$this->assertSame( 'private', get_post_status( $product_id ), 'off the shelf, still in the records' );
	}

	/**
	 * Paying for the product makes the booking.
	 *
	 * @return void
	 */
	public function test_a_paid_order_becomes_a_booking() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500 );

		$order = $this->an_order_for( Products::sync( $type_id, $event_id ) );

		Orders::book( $order->get_id() );

		$bookings = Repository::for_event( $event_id );

		$this->assertCount( 1, $bookings );
		$this->assertSame( 'Ada Lovelace', $bookings[0]->booker_name() );
		$this->assertSame( 'ada@example.com', $bookings[0]->booker_email() );
		$this->assertSame( RegistrationStatus::Confirmed, $bookings[0]->status() );
		$this->assertSame( $type_id, $bookings[0]->ticket_type_id(), 'and it knows which ticket was bought' );
	}

	/**
	 * Woo firing more than one paid status makes one booking.
	 *
	 * An order goes processing, then completed, and both mean paid. Acting on
	 * each would produce two people at the door with the same name.
	 *
	 * **Two things stop that, and this test cannot tell them apart.** The line
	 * remembers which booking it produced, and the registration service refuses
	 * a second booking for the same address at the same event. Removing the
	 * first guard leaves the second holding, which is why the sabotage of it
	 * passed — recorded here rather than dressed up as an isolated test. What
	 * the line meta is provably load-bearing for is the refund below: without
	 * it, nothing can find the booking to cancel.
	 *
	 * @return void
	 */
	public function test_two_paid_statuses_make_one_booking() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500 );

		$order = $this->an_order_for( Products::sync( $type_id, $event_id ) );

		Orders::book( $order->get_id() );
		Orders::book( $order->get_id() );

		$this->assertCount( 1, Repository::for_event( $event_id ) );

		$booked = 0;

		foreach ( wc_get_order( $order->get_id() )->get_items() as $item ) {
			$booked = (int) $item->get_meta( Orders::BOOKING_META );
		}

		$this->assertSame( Repository::for_event( $event_id )[0]->id(), $booked, 'the line names the booking it made' );
	}

	/**
	 * Refunding in Woo cancels the booking.
	 *
	 * @return void
	 */
	public function test_a_refund_in_woo_frees_the_place() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500 );

		$order = $this->an_order_for( Products::sync( $type_id, $event_id ) );

		Orders::book( $order->get_id() );

		$this->assertSame( 1, Repository::count_taken( $event_id ) );

		Orders::release( $order->get_id() );

		$this->assertSame( RegistrationStatus::Cancelled, Repository::for_event( $event_id )[0]->status() );
		$this->assertSame( 0, Repository::count_taken( $event_id ), 'the seat is back' );
	}

	/**
	 * An order full of ordinary shop products books nobody.
	 *
	 * @return void
	 */
	public function test_an_unrelated_order_is_ignored() {
		list( $event_id ) = $this->a_ticket( 'Member', 2500 );

		$mug = new \WC_Product_Simple();
		$mug->set_name( 'Mug' );
		$mug->set_regular_price( '8.00' );
		$mug_id = (int) $mug->save();

		$order = $this->an_order_for( $mug_id );

		Orders::book( $order->get_id() );

		$this->assertSame( array(), Repository::for_event( $event_id ) );
	}

	/**
	 * A purchase for a full event joins the waiting list rather than overselling.
	 *
	 * Woo does not know the room's capacity, and teaching it would be a second
	 * stock system to keep in step. Going through the ordinary booking path
	 * means the answer is the same one everybody else gets — and the organiser
	 * can see it and refund it.
	 *
	 * @return void
	 */
	public function test_a_purchase_for_a_full_event_waits_rather_than_oversells() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500, 1 );

		$this->book(
			$event_id,
			array(
				'ticket_type_id' => $type_id,
				'email'          => 'first@example.com',
			)
		);

		$order = $this->an_order_for( Products::sync( $type_id, $event_id ) );

		Orders::book( $order->get_id() );

		$bought = Repository::for_event( $event_id );

		$this->assertCount( 2, $bought );

		$woo = null;

		foreach ( $bought as $booking ) {
			if ( 'ada@example.com' === $booking->booker_email() ) {
				$woo = $booking;
			}
		}

		$this->assertNotNull( $woo, 'the purchase produced a booking' );
		$this->assertSame( RegistrationStatus::Waitlisted, $woo->status(), 'a sold ticket does not create a seat' );
	}

	/**
	 * With the module off, buying the product books nobody.
	 *
	 * @return void
	 */
	public function test_nothing_happens_with_the_module_off() {
		list( $event_id, $type_id ) = $this->a_ticket( 'Member', 2500 );

		$product_id = Products::sync( $type_id, $event_id );

		remove_all_actions( 'woocommerce_order_status_completed' );

		$order = $this->an_order_for( $product_id );

		do_action( 'woocommerce_order_status_completed', $order->get_id() );

		$this->assertSame( array(), Repository::for_event( $event_id ) );
	}

	/**
	 * An event with one ticket type.
	 *
	 * @param string $name     Ticket name.
	 * @param int    $price    Price in minor units.
	 * @param int    $capacity Event capacity, 0 for unlimited.
	 * @return array{0: int, 1: int}
	 */
	private function a_ticket( string $name, int $price, int $capacity = 0 ): array {
		$event_id = $this->make_event( array( 'capacity' => $capacity ) );

		$type_id = TicketTypeRepository::insert(
			$event_id,
			array(
				'name'        => $name,
				'price_minor' => $price,
			)
		);

		return array( $event_id, $type_id );
	}

	/**
	 * A Woo order containing one of something.
	 *
	 * @param int $product_id Product id.
	 * @return \WC_Order
	 */
	private function an_order_for( int $product_id ) {
		$order = wc_create_order();

		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
