<?php
/**
 * The WooCommerce surface this plugin touches, for static analysis only.
 *
 * WooCommerce is not a dependency and is absent when PHPStan runs, so the bridge
 * in includes/Woo/ references functions and classes it cannot resolve. Declaring
 * the few that are actually called lets those files be analysed rather than
 * excluded — and excluding them would mean the one part of the codebase built
 * entirely on assumptions about somebody else's API is the part nothing checks.
 *
 * Why this rather than php-stubs/woocommerce-stubs: adding a dependency, even a
 * dev one, is a decision the engineering standards say to raise rather than take
 * in passing, and that package is large. Six signatures are cheaper. If the
 * bridge grows much past this, the package is the better answer.
 *
 * Deliberately loose types. These describe what is called, not what Woo
 * guarantees; the real objects have far larger interfaces, and pretending
 * otherwise here would be inventing a contract.
 *
 * Never loaded at runtime, never shipped: listed in phpstan.neon.dist under
 * scanFiles, and tests/ is excluded by .distignore.
 *
 * @package QuickEventsManager
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Declaring WooCommerce's own names, not ours.

/**
 * WooCommerce's main class. Only its existence is ever checked.
 */
class WooCommerce {
}

/**
 * A simple product.
 */
class WC_Product_Simple {

	/**
	 * Product id.
	 *
	 * @return int
	 */
	public function get_id() {
		return 0;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return '';
	}

	/**
	 * Price before any sale.
	 *
	 * @return string
	 */
	public function get_regular_price() {
		return '';
	}

	/**
	 * Whether it is virtual.
	 *
	 * @return bool
	 */
	public function is_virtual() {
		return false;
	}

	/**
	 * Whether only one may be bought at a time.
	 *
	 * @return bool
	 */
	public function is_sold_individually() {
		return false;
	}

	/**
	 * Set the name.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function set_name( $name ) {
	}

	/**
	 * Set the description.
	 *
	 * @param string $description Description.
	 * @return void
	 */
	public function set_description( $description ) {
	}

	/**
	 * Set the price.
	 *
	 * @param string $price Price in whole units.
	 * @return void
	 */
	public function set_regular_price( $price ) {
	}

	/**
	 * Set whether it is virtual.
	 *
	 * @param bool $virtual Whether it is.
	 * @return void
	 */
	public function set_virtual( $virtual ) {
	}

	/**
	 * Set whether only one may be bought at a time.
	 *
	 * @param bool $individually Whether so.
	 * @return void
	 */
	public function set_sold_individually( $individually ) {
	}

	/**
	 * Set catalogue visibility.
	 *
	 * @param string $visibility Visibility.
	 * @return void
	 */
	public function set_catalog_visibility( $visibility ) {
	}

	/**
	 * Set the post status.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	public function set_status( $status ) {
	}

	/**
	 * Write a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
	}

	/**
	 * Save, returning the id.
	 *
	 * @return int
	 */
	public function save() {
		return 0;
	}
}

/**
 * An order.
 */
class WC_Order {

	/**
	 * Order id.
	 *
	 * @return int
	 */
	public function get_id() {
		return 0;
	}

	/**
	 * The lines on it.
	 *
	 * @return array<int, WC_Order_Item_Product>
	 */
	public function get_items() {
		return array();
	}

	/**
	 * Add a product.
	 *
	 * @param mixed $product  The product.
	 * @param int   $quantity How many.
	 * @return int
	 */
	public function add_product( $product, $quantity = 1 ) {
		return 0;
	}

	/**
	 * Billing first name.
	 *
	 * @return string
	 */
	public function get_billing_first_name() {
		return '';
	}

	/**
	 * Billing last name.
	 *
	 * @return string
	 */
	public function get_billing_last_name() {
		return '';
	}

	/**
	 * Billing email.
	 *
	 * @return string
	 */
	public function get_billing_email() {
		return '';
	}

	/**
	 * Billing phone.
	 *
	 * @return string
	 */
	public function get_billing_phone() {
		return '';
	}

	/**
	 * Set billing first name.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function set_billing_first_name( $name ) {
	}

	/**
	 * Set billing last name.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function set_billing_last_name( $name ) {
	}

	/**
	 * Set billing email.
	 *
	 * @param string $email Email.
	 * @return void
	 */
	public function set_billing_email( $email ) {
	}

	/**
	 * Work out the totals.
	 *
	 * @return float
	 */
	public function calculate_totals() {
		return 0.0;
	}

	/**
	 * Add a note to the order.
	 *
	 * @param string $note The note.
	 * @return int
	 */
	public function add_order_note( $note ) {
		return 0;
	}

	/**
	 * Save.
	 *
	 * @return int
	 */
	public function save() {
		return 0;
	}
}

/**
 * One line of an order.
 */
class WC_Order_Item_Product {

	/**
	 * The product bought.
	 *
	 * @return int
	 */
	public function get_product_id() {
		return 0;
	}

	/**
	 * How many.
	 *
	 * @return int
	 */
	public function get_quantity() {
		return 0;
	}

	/**
	 * Read a meta value.
	 *
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return '';
	}

	/**
	 * Write a meta value.
	 *
	 * @param string $key    Meta key.
	 * @param mixed  $value  Value.
	 * @param bool   $unique Whether it replaces.
	 * @return void
	 */
	public function add_meta_data( $key, $value, $unique = false ) {
	}

	/**
	 * Save.
	 *
	 * @return int
	 */
	public function save() {
		return 0;
	}
}

/**
 * Fetch a product.
 *
 * @param int $id Product id.
 * @return WC_Product_Simple|false
 */
function wc_get_product( $id ) {
	return 0 === $id ? false : new WC_Product_Simple();
}

/**
 * Fetch an order.
 *
 * @param int $id Order id.
 * @return WC_Order|false
 */
function wc_get_order( $id ) {
	return 0 === $id ? false : new WC_Order();
}

/**
 * Make an order.
 *
 * @param array<string, mixed> $args Arguments.
 * @return WC_Order
 */
function wc_create_order( $args = array() ) {
	return new WC_Order();
}
