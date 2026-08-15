<?php
/**
 * The wording people agree to when they register, and what is recorded about it.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Privacy;

use QuickEventsManager\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One place that decides what consent says, whether it is asked for, and how a
 * given wording is identified afterwards.
 *
 * The plugin stores names, email addresses and phone numbers. Until now it did
 * that with nothing recorded about why the person handed them over — which is
 * fine right up to the day somebody asks, and then there is no answer to give.
 *
 * Three things are stored against a registration: the wording's fingerprint,
 * the moment it was agreed to in UTC, and nothing else. Deliberately nothing
 * else: no IP address, no user agent. Those are the things people reach for to
 * make a consent record feel stronger, and all they really do is add personal
 * data to a table in the name of protecting personal data.
 *
 * @since 26.0
 */
final class Consent {

	/**
	 * Settings key holding the wording.
	 */
	const SETTING = 'consent_text';

	/**
	 * Length of the stored fingerprint.
	 *
	 * Twelve hex characters of SHA-1. The column takes 64, so there is room to
	 * grow, but the job here is telling two wordings apart on one site rather
	 * than resisting an attacker — nobody gains anything by forging a match
	 * against text they can read on the page.
	 */
	const VERSION_LENGTH = 12;

	/**
	 * The wording shown beside the checkbox.
	 *
	 * @since 26.0
	 *
	 * @return string Empty when consent is not being asked for.
	 */
	public static function text(): string {
		/*
		 * No fallback argument on purpose. Settings::get() treats a non-null
		 * fallback as beating the registered default, so passing '' here would
		 * mean a site that has never opened the settings screen asks for no
		 * consent at all — the opposite of the intended default.
		 */
		$text = (string) Settings::get( self::SETTING );

		/**
		 * Filter the consent wording.
		 *
		 * The recorded version is a fingerprint of whatever this returns, so a
		 * filter that varies the wording — per language, say — is recorded as
		 * the distinct wording it is rather than being credited to the text in
		 * the settings screen.
		 *
		 * @since 26.0
		 *
		 * @param string $text The configured wording.
		 */
		return trim( (string) apply_filters( 'qevm_consent_text', $text ) );
	}

	/**
	 * Whether a registration has to agree to anything.
	 *
	 * Emptying the wording is how a site turns consent off. That is the whole
	 * switch: a separate "ask for consent" checkbox beside a box for the text
	 * would only create the state where consent is required and says nothing.
	 *
	 * @since 26.0
	 */
	public static function is_required(): bool {
		return '' !== self::text();
	}

	/**
	 * The identifier stored against a registration.
	 *
	 * Derived from the wording rather than typed by hand. A version somebody
	 * has to remember to increment is a version that is wrong the first time
	 * the text is edited in a hurry, and a consent record that names the wrong
	 * wording is worse than one that names none.
	 *
	 * What it proves is bounded, and worth being straight about: the wording
	 * itself is not archived, so this answers "was this the wording that is on
	 * the site now?" and not "what exactly did they see?". Matching versions
	 * mean the text on the settings screen is the text they agreed to.
	 *
	 * @since 26.0
	 *
	 * @return string Empty when consent is not being asked for.
	 */
	public static function version(): string {
		$text = self::text();

		if ( '' === $text ) {
			return '';
		}

		return substr( sha1( $text ), 0, self::VERSION_LENGTH );
	}

	/**
	 * The markup a site owner may use in the wording.
	 *
	 * A link, because the one thing every consent notice needs is a way to
	 * reach the privacy policy, and emphasis because people will use it.
	 * Everything else is stripped.
	 *
	 * @since 26.0
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'em'     => array(),
			'strong' => array(),
		);
	}
}
