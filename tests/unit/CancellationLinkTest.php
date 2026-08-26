<?php
/**
 * The signature on a cancellation link.
 *
 * @package QuickEventsManager
 */

namespace QuickEventsManager\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Registration\CancellationLink;

/**
 * Signing and verification, which is pure enough to test without a database.
 */
final class CancellationLinkTest extends TestCase {

	/**
	 * A signature this site produced verifies.
	 *
	 * @return void
	 */
	public function test_a_genuine_link_verifies() {
		$expires = time() + HOUR_IN_SECONDS;
		$token   = CancellationLink::sign( 'QEVM-7F3K9A', $expires );

		$this->assertTrue( CancellationLink::verify( 'QEVM-7F3K9A', $expires, $token ) );
	}

	/**
	 * Signing is deterministic, or a link would stop working on its own.
	 *
	 * @return void
	 */
	public function test_the_same_input_signs_the_same_way_twice() {
		$expires = time() + HOUR_IN_SECONDS;

		$this->assertSame(
			CancellationLink::sign( 'QEVM-7F3K9A', $expires ),
			CancellationLink::sign( 'QEVM-7F3K9A', $expires )
		);
	}

	/**
	 * A signature for one booking does not work on another.
	 *
	 * @return void
	 */
	public function test_a_signature_does_not_transfer_between_bookings() {
		$expires = time() + HOUR_IN_SECONDS;
		$token   = CancellationLink::sign( 'QEVM-7F3K9A', $expires );

		$result = CancellationLink::verify( 'QEVM-AAAAAA', $expires, $token );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_cancel_invalid', $result->get_error_code() );
	}

	/**
	 * The expiry is signed, so it cannot be extended by editing the URL.
	 *
	 * This is the attack the whole design turns on. If only the reference were
	 * signed, anybody holding a genuine link could change the timestamp in the
	 * address bar and keep it alive forever.
	 *
	 * @return void
	 */
	public function test_the_expiry_cannot_be_pushed_out_by_hand() {
		$expires = time() + HOUR_IN_SECONDS;
		$token   = CancellationLink::sign( 'QEVM-7F3K9A', $expires );

		$result = CancellationLink::verify( 'QEVM-7F3K9A', $expires + DAY_IN_SECONDS, $token );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_cancel_invalid', $result->get_error_code() );
	}

	/**
	 * A genuine signature stops working once its moment has passed.
	 *
	 * @return void
	 */
	public function test_a_genuine_link_expires() {
		$expires = time() - 1;
		$token   = CancellationLink::sign( 'QEVM-7F3K9A', $expires );

		$result = CancellationLink::verify( 'QEVM-7F3K9A', $expires, $token );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_cancel_expired', $result->get_error_code() );
	}

	/**
	 * Expired and forged are told apart, and say different things.
	 *
	 * Telling somebody their link expired when it was never valid sends them
	 * hunting for a newer email that does not exist.
	 *
	 * @return void
	 */
	public function test_expired_and_forged_are_different_answers() {
		$expires = time() - 1;

		$this->assertSame(
			'qevm_cancel_invalid',
			CancellationLink::verify( 'QEVM-7F3K9A', $expires, str_repeat( 'a', 64 ) )->get_error_code(),
			'a bad signature on an old link is a forgery, not an expiry'
		);
	}

	/**
	 * Missing pieces are refused rather than treated as empty strings.
	 *
	 * @param mixed $code    Reference.
	 * @param mixed $expires Expiry.
	 * @param mixed $token   Signature.
	 * @return void
	 */
	#[DataProvider( 'incomplete_requests' )]
	public function test_an_incomplete_link_is_refused( $code, $expires, $token ) {
		$result = CancellationLink::verify( $code, $expires, $token );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_cancel_incomplete', $result->get_error_code() );
	}

	/**
	 * Links with something missing.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
	 */
	public static function incomplete_requests() {
		return array(
			'no code'             => array( '', time() + 60, str_repeat( 'a', 64 ) ),
			'no token'            => array( 'QEVM-7F3K9A', time() + 60, '' ),
			'no expiry'           => array( 'QEVM-7F3K9A', 0, str_repeat( 'a', 64 ) ),
			'expiry not a number' => array( 'QEVM-7F3K9A', 'soon', str_repeat( 'a', 64 ) ),
			'negative expiry'     => array( 'QEVM-7F3K9A', -1, str_repeat( 'a', 64 ) ),
		);
	}

	/**
	 * A truncated signature fails on length, not on a partial comparison.
	 *
	 * @return void
	 */
	public function test_a_truncated_signature_is_refused() {
		$expires = time() + HOUR_IN_SECONDS;
		$token   = CancellationLink::sign( 'QEVM-7F3K9A', $expires );

		$result = CancellationLink::verify( 'QEVM-7F3K9A', $expires, substr( $token, 0, 32 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'qevm_cancel_invalid', $result->get_error_code() );
	}

	/**
	 * The signature is a full-length SHA-256 hex digest.
	 *
	 * @return void
	 */
	public function test_the_signature_is_sha256_hex() {
		$token = CancellationLink::sign( 'QEVM-7F3K9A', time() );

		$this->assertSame( 64, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
	}

	/**
	 * The separator stops two different bookings signing identically.
	 *
	 * Without it, code `QEVM-1` with expiry `23` and code `QEVM-12` with
	 * expiry `3` are the same string, so one link would cancel the other's
	 * booking. The codes this plugin generates are fixed-length, which hides
	 * the bug rather than removing it — the separator is what removes it.
	 *
	 * @return void
	 */
	public function test_the_payload_cannot_be_made_ambiguous() {
		$this->assertNotSame(
			CancellationLink::sign( 'QEVM-1', 23 ),
			CancellationLink::sign( 'QEVM-12', 3 )
		);
	}

	/**
	 * Fail unless the value is a WP_Error.
	 *
	 * @param mixed $value Value to check.
	 * @return void
	 */
	private function assertWPError( $value ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches PHPUnit's own assertion naming.
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
