<?php
/**
 * Who to contact about an event, wherever those details are stored.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Organizers;

use QuickEventsManager\Events\Meta;
use QuickEventsManager\Records\EventRecord;

defined( 'ABSPATH' ) || exit;

/**
 * One organiser, read from a record or from the event's own meta.
 *
 * The same two-places problem as a venue and the same answer, which is why
 * almost all of it is inherited. What differs is what an organiser is made of:
 * a name, an email address, a phone number and a link, none of which read as a
 * single line the way an address does.
 *
 * The class name is spelled the American way to match `_qevm_organizer_*`,
 * registered in 26.0 and unchangeable. Every string a user sees says
 * "organiser".
 *
 * @since 26.0
 */
final class Organizer extends EventRecord {

	/**
	 * Reusable organisers are `qevm_organizer` posts.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function post_type() {
		return QEVM_POST_TYPE_ORGANIZER;
	}

	/**
	 * Organiser records need the organisers module.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function module_id() {
		return OrganizersModule::ID;
	}

	/**
	 * The event meta key naming an organiser.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function id_key() {
		return Meta::ORGANIZER_ID;
	}

	/**
	 * The fields an organiser is made of, in reading order.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array(
			Meta::ORGANIZER_NAME,
			Meta::ORGANIZER_EMAIL,
			Meta::ORGANIZER_PHONE,
			Meta::ORGANIZER_URL,
		);
	}

	/**
	 * The organiser's name is its post title.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public static function name_key() {
		return Meta::ORGANIZER_NAME;
	}

	/**
	 * The fields an organiser record stores as meta, name excluded.
	 *
	 * @since 26.0
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array_values(
			array_filter(
				self::keys(),
				static function ( $key ) {
					return Meta::ORGANIZER_NAME !== $key;
				}
			)
		);
	}

	/**
	 * The organiser's email address.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function email() {
		return $this->part( Meta::ORGANIZER_EMAIL );
	}

	/**
	 * The organiser's phone number.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function phone() {
		return $this->part( Meta::ORGANIZER_PHONE );
	}

	/**
	 * The organiser's web address.
	 *
	 * @since 26.0
	 *
	 * @return string
	 */
	public function url() {
		return $this->part( Meta::ORGANIZER_URL );
	}
}
