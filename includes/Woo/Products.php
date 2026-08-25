<?php
/**
 * A ticket type, as a thing in a shop.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Keeping a Woo product in step with a ticket type.
 *
 * **The ticket type stays the source of truth.** The product is a shopfront for
 * it: the name, the price and whether it is on sale all come from the ticket
 * type, and editing the product in Woo is not how you change a ticket. That
 * direction is deliberate — two editable copies of a price is a support
 * conversation waiting to happen, and the one people would edit is not the one
 * capacity is counted against.
 *
 * **Virtual products, never shipped.** A ticket has no weight and no address,
 * and a shipping calculator on the checkout for a village hall talk is the kind
 * of detail that makes a bridge feel unfinished.
 *
 * @since 26.0
 */
final class Products {

	/**
	 * Meta on the product naming the ticket type it stands for.
	 */
	const TICKET_META = '_qevm_ticket_type_id';

	/**
	 * Meta on the product naming the event.
	 */
	const EVENT_META = '_qevm_event_id';

	/**
	 * Add the hooks.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'qevm_ticket_type_saved', array( __CLASS__, 'on_save' ), 10, 2 );

		/*
		 * A ticket is bought once and used once. Woo would otherwise let
		 * somebody put three in a basket and pay for one, because quantity is a
		 * shop's idea rather than a door's.
		 */
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'one_at_a_time' ), 10, 2 );
	}

	/**
	 * What the save hook calls.
	 *
	 * A wrapper that returns nothing, because an action callback with a return
	 * value is one whose value nobody reads — and `sync()` has one worth reading
	 * for the code that calls it directly.
	 *
	 * @since 26.0
	 *
	 * @param mixed $ticket_type_id The ticket type.
	 * @param mixed $event_id       The event.
	 * @return void
	 */
	public static function on_save( $ticket_type_id, $event_id ) {
		self::sync( $ticket_type_id, $event_id );
	}

	/**
	 * Make the shop match the ticket type.
	 *
	 * @since 26.0
	 *
	 * @param mixed $ticket_type_id The ticket type.
	 * @param mixed $event_id       The event it belongs to.
	 * @return int The product id, or 0.
	 */
	public static function sync( $ticket_type_id, $event_id ) {
		if ( ! WooModule::woo_is_active() ) {
			return 0;
		}

		$type = self::ticket_type( (int) $event_id, (int) $ticket_type_id );

		if ( null === $type ) {
			return 0;
		}

		$product_id = self::product_for( (int) $ticket_type_id );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
		}

		$product->set_name(
			sprintf(
				/* translators: 1: Event title. 2: Ticket type name. */
				__( '%1$s — %2$s', 'quick-events-manager' ),
				wp_strip_all_tags( get_the_title( (int) $event_id ) ),
				$type->name()
			)
		);

		$product->set_description( (string) $type->description() );
		$product->set_regular_price( self::major_price( $type ) );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_status( $type->status()->is_sellable() ? 'publish' : 'private' );

		$product->update_meta_data( self::TICKET_META, (int) $ticket_type_id );
		$product->update_meta_data( self::EVENT_META, (int) $event_id );

		return (int) $product->save();
	}

	/**
	 * The product standing for a ticket type, if there is one.
	 *
	 * @since 26.0
	 *
	 * @param int $ticket_type_id Ticket type id.
	 * @return int
	 */
	public static function product_for( int $ticket_type_id ): int {
		/*
		 * A meta_query rather than meta_key/meta_value: the same query, and the
		 * shape WP_Query documents, which is also the one static analysis can
		 * check. One row, by a meta key, on an admin save.
		 */
		$found = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'private', 'draft' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
					array(
						'key'   => self::TICKET_META,
						'value' => $ticket_type_id,
					),
				),
			)
		);

		return array() !== $found ? (int) $found[0] : 0;
	}

	/**
	 * Which ticket type a product stands for.
	 *
	 * @since 26.0
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public static function ticket_type_for( int $product_id ): int {
		return (int) get_post_meta( $product_id, self::TICKET_META, true );
	}

	/**
	 * Which event a product's ticket belongs to.
	 *
	 * @since 26.0
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	public static function event_for( int $product_id ): int {
		return (int) get_post_meta( $product_id, self::EVENT_META, true );
	}

	/**
	 * One ticket at a time, for anything that is one of ours.
	 *
	 * @since 26.0
	 *
	 * @param mixed $individually Whether Woo already thinks so.
	 * @param mixed $product      The product.
	 * @return bool
	 */
	public static function one_at_a_time( $individually, $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) && self::ticket_type_for( (int) $product->get_id() ) > 0 ) {
			return true;
		}

		return (bool) $individually;
	}

	/**
	 * The price as Woo wants it: whole units, as a plain decimal string.
	 *
	 * Converted at this one boundary. Woo stores prices as decimal strings and
	 * this plugin stores minor units; the conversion belongs where the two meet
	 * rather than sprinkled through the bridge.
	 *
	 * @since 26.0
	 *
	 * @param object $type The ticket type.
	 * @return string
	 */
	private static function major_price( $type ): string {
		return \QuickEventsManager\Commerce\Money::from_minor( (int) $type->price_minor() )->to_decimal_string();
	}

	/**
	 * One ticket type of one event, asked for through the filter.
	 *
	 * Nothing here names a class from the ticketing module, the same as
	 * everywhere else that needs to know what an event sells.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id       Event id.
	 * @param int $ticket_type_id Ticket type id.
	 * @return object|null
	 */
	private static function ticket_type( int $event_id, int $ticket_type_id ) {
		/** This filter is documented in includes/Registration/RegistrationService.php */
		$types = (array) apply_filters( 'qevm_event_ticket_types', array(), $event_id );

		foreach ( $types as $type ) {
			if ( is_object( $type ) && method_exists( $type, 'id' ) && (int) $type->id() === $ticket_type_id ) {
				return $type;
			}
		}

		return null;
	}
}
