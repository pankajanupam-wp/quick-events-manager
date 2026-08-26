<?php
/**
 * The identifier that survives a series being split.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Recurrence;

use QuickEventsManager\Events\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Minting and reading `series_uuid`.
 *
 * A recurring event is one post with many occurrence rows. `event_id` already
 * groups those rows, so the uuid earns its place only when "this and following"
 * splits a series into two posts and the two halves still have to be recognisable
 * as one series. See docs/recurrence.md §4.4.
 *
 * **Minted when recurrence is first switched on**, not at the first split. That
 * makes "everything in this series" one query with one shape whether or not the
 * series has ever been split; minting it lazily would leave two code paths, and
 * the rarely-exercised one is the one that breaks.
 *
 * A non-recurring event has no uuid and is not a series of one. Pretending
 * otherwise would put every ordinary event through the recurrence paths for the
 * sake of uniformity nobody benefits from.
 *
 * @since 26.0
 */
final class Series {

	/**
	 * A new series identifier.
	 *
	 * @since 26.0
	 */
	public static function mint(): string {
		return wp_generate_uuid4();
	}

	/**
	 * One event's series identifier, or ''.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	public static function for_event( int $event_id ): string {
		if ( $event_id <= 0 ) {
			return '';
		}

		$stored = get_post_meta( $event_id, Meta::SERIES_UUID, true );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Give an event a series identifier if it has not got one.
	 *
	 * Idempotent, and deliberately never replaces an existing value. Reassigning
	 * a uuid would orphan the other half of an already-split series from this
	 * one — they would stop being the same series with nothing on screen to say
	 * why.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return string The identifier the event now has, or '' if it could not be given one.
	 */
	public static function ensure( int $event_id ): string {
		if ( $event_id <= 0 ) {
			return '';
		}

		$existing = self::for_event( $event_id );

		if ( '' !== $existing ) {
			return $existing;
		}

		$uuid = self::mint();

		update_post_meta( $event_id, Meta::SERIES_UUID, $uuid );

		return $uuid;
	}

	/**
	 * Copy one event's series identifier onto another.
	 *
	 * What a split calls, so both halves carry the same uuid — the property the
	 * Stage 6 gate checks.
	 *
	 * @since 26.0
	 *
	 * @param int $from_event_id Event to copy from.
	 * @param int $to_event_id   Event to copy to.
	 * @return string The identifier both now share, or ''.
	 */
	public static function copy( int $from_event_id, int $to_event_id ): string {
		$uuid = self::for_event( $from_event_id );

		if ( '' === $uuid || $to_event_id <= 0 ) {
			return '';
		}

		update_post_meta( $to_event_id, Meta::SERIES_UUID, $uuid );

		return $uuid;
	}

	/**
	 * Take a series identifier away, when recurrence is switched off.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return void
	 */
	public static function forget( int $event_id ): void {
		if ( $event_id > 0 ) {
			delete_post_meta( $event_id, Meta::SERIES_UUID );
		}
	}

	/**
	 * One event's rule, if it has a readable one.
	 *
	 * The single place anything asks "is this event recurring". A caller that
	 * reads the meta itself has to repeat the parse and decide for itself what
	 * an unreadable rule means.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return Rule|null
	 */
	public static function rule_for_event( int $event_id ): ?Rule {
		if ( $event_id <= 0 ) {
			return null;
		}

		$stored = get_post_meta( $event_id, Meta::RECURRENCE_RULE, true );

		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			return null;
		}

		$rule = Rule::from_string( $stored );

		if ( null === $rule || is_wp_error( $rule->validate() ) ) {
			return null;
		}

		return $rule;
	}

	/**
	 * Whether an event repeats.
	 *
	 * @since 26.0
	 *
	 * @param int $event_id Event id.
	 * @return bool
	 */
	public static function is_recurring( int $event_id ): bool {
		return null !== self::rule_for_event( $event_id );
	}
}
