<?php
/**
 * Stripe credentials.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Commerce\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * Where the keys live, and what can be said about them out loud.
 *
 * **Not in `qevm_settings`.** That option is autoloaded, which means a secret
 * key would be read into memory on every request of every page of the site,
 * including ones that will never take a payment. Its own option, not autoloaded,
 * read only when something is actually about to talk to Stripe.
 *
 * **The secret is never printed back.** A settings screen that renders the key
 * into a `value` attribute puts it in the page source, in the browser's autofill
 * store and in any screenshot of the screen. The field shows how the stored key
 * ends and nothing more, and an empty submission leaves what is stored alone —
 * which is also what makes the screen safe to save from twice.
 *
 * **Test or live is read off the key**, not stored as a toggle beside it. Stripe
 * keys say which they are — `sk_test_` against `sk_live_` — and a separate mode
 * switch is a second source of truth that can disagree with the first. The one
 * that disagrees quietly is the one that charges real cards from a staging site.
 *
 * @since 26.0
 */
final class Keys {

	/**
	 * Option holding the credentials. Not autoloaded.
	 */
	const OPTION = 'qevm_stripe_keys';

	/**
	 * The secret key, or ''.
	 *
	 * @since 26.0
	 */
	public static function secret(): string {
		return self::read( 'secret' );
	}

	/**
	 * The publishable key, or ''.
	 *
	 * @since 26.0
	 */
	public static function publishable(): string {
		return self::read( 'publishable' );
	}

	/**
	 * The signing secret for webhooks, or ''.
	 *
	 * Stored here with the others, used in C9.5.
	 *
	 * @since 26.0
	 */
	public static function webhook_secret(): string {
		return self::read( 'webhook' );
	}

	/**
	 * Whether both keys needed to take a payment are present.
	 *
	 * @since 26.0
	 */
	public static function are_complete(): bool {
		return '' !== self::secret() && '' !== self::publishable();
	}

	/**
	 * Whether the stored keys are test keys.
	 *
	 * @since 26.0
	 */
	public static function are_test(): bool {
		return 0 === strpos( self::secret(), 'sk_test_' );
	}

	/**
	 * Whether the two keys are a matching pair.
	 *
	 * A live secret with a test publishable key is a checkout that collects a
	 * card against one account and charges against another: every payment
	 * fails, at the last step, in front of a customer. Cheap to catch here.
	 *
	 * @since 26.0
	 */
	public static function are_consistent(): bool {
		if ( ! self::are_complete() ) {
			return true;
		}

		return self::are_test() === ( 0 === strpos( self::publishable(), 'pk_test_' ) );
	}

	/**
	 * How a stored key may be shown on a screen: `…last four`.
	 *
	 * @since 26.0
	 *
	 * @param string $key A key.
	 */
	public static function mask( string $key ): string {
		if ( '' === $key ) {
			return '';
		}

		return '…' . substr( $key, -4 );
	}

	/**
	 * Write the keys, leaving anything submitted empty as it was.
	 *
	 * @since 26.0
	 *
	 * @param array<string, string> $keys Any of secret, publishable, webhook.
	 * @return void
	 */
	public static function save( array $keys ) {
		$stored = self::all();

		foreach ( array( 'secret', 'publishable', 'webhook' ) as $name ) {
			if ( ! isset( $keys[ $name ] ) ) {
				continue;
			}

			$value = trim( sanitize_text_field( (string) $keys[ $name ] ) );

			if ( '' === $value ) {
				continue;
			}

			$stored[ $name ] = $value;
		}

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Remove every key.
	 *
	 * @since 26.0
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
	}

	/**
	 * Everything stored.
	 *
	 * @since 26.0
	 *
	 * @return array<string, string>
	 */
	private static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * One stored key.
	 *
	 * @since 26.0
	 *
	 * @param string $name secret, publishable or webhook.
	 */
	private static function read( string $name ): string {
		$all = self::all();

		return isset( $all[ $name ] ) ? trim( (string) $all[ $name ] ) : '';
	}
}
