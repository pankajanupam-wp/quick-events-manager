<?php
/**
 * The commerce enums.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Domain\OrderStatus;
use QuickEventsManager\Domain\TransactionKind;
use QuickEventsManager\Domain\TransactionStatus;

/**
 * The three state machines money moves through.
 */
#[CoversClass( OrderStatus::class )]
#[CoversClass( TransactionKind::class )]
#[CoversClass( TransactionStatus::class )]
class CommerceEnumTest extends TestCase {

	/**
	 * The backing values are what the status columns store.
	 *
	 * Pinned literally rather than derived, because the point is to fail if
	 * somebody changes one. Renaming a case is free; changing its value is a
	 * migration of every order a site has ever taken.
	 *
	 * @return void
	 */
	public function test_stored_values_are_the_contract() {
		$this->assertSame( 'pending', OrderStatus::Pending->value );
		$this->assertSame( 'paid', OrderStatus::Paid->value );
		$this->assertSame( 'partially_refunded', OrderStatus::PartiallyRefunded->value );
		$this->assertSame( 'refunded', OrderStatus::Refunded->value );
		$this->assertSame( 'failed', OrderStatus::Failed->value );
		$this->assertSame( 'abandoned', OrderStatus::Abandoned->value );

		$this->assertSame( 'charge', TransactionKind::Charge->value );
		$this->assertSame( 'refund', TransactionKind::Refund->value );

		$this->assertSame( 'pending', TransactionStatus::Pending->value );
		$this->assertSame( 'succeeded', TransactionStatus::Succeeded->value );
		$this->assertSame( 'failed', TransactionStatus::Failed->value );
	}

	/**
	 * A checkout in progress is holding seats; one that was walked away from is not.
	 *
	 * The whole reason `abandoned` is a status of its own. Without it, either
	 * an abandoned checkout keeps eating a seat forever, or a failed payment
	 * and a walked-away basket become the same thing and an abandonment figure
	 * means nothing.
	 *
	 * @return void
	 */
	public function test_what_holds_a_seat() {
		$this->assertTrue( OrderStatus::Pending->holds_capacity(), 'a checkout in progress is holding one' );
		$this->assertTrue( OrderStatus::Paid->holds_capacity() );
		$this->assertTrue( OrderStatus::PartiallyRefunded->holds_capacity(), 'part of the money back is not the place back' );

		$this->assertFalse( OrderStatus::Abandoned->holds_capacity() );
		$this->assertFalse( OrderStatus::Failed->holds_capacity() );
		$this->assertFalse( OrderStatus::Refunded->holds_capacity(), 'the place went back when the money did' );
	}

	/**
	 * Settled means money was taken and not all of it returned.
	 *
	 * @return void
	 */
	public function test_what_counts_as_settled() {
		$settled = array_values(
			array_filter( OrderStatus::cases(), static fn( $status ) => $status->is_settled() )
		);

		$this->assertSame( array( OrderStatus::Paid, OrderStatus::PartiallyRefunded ), $settled );
	}

	/**
	 * The kind decides the sign.
	 *
	 * @return void
	 */
	public function test_refunds_are_negative_and_charges_are_not() {
		$this->assertSame( 1, TransactionKind::Charge->sign() );
		$this->assertSame( -1, TransactionKind::Refund->sign() );
	}

	/**
	 * Only a succeeded line is money.
	 *
	 * A row exists before the gateway has answered — that is what makes the
	 * attempt idempotent — so "there is a row" must never mean "we were paid".
	 *
	 * @return void
	 */
	public function test_only_succeeded_counts() {
		$this->assertTrue( TransactionStatus::Succeeded->counts() );
		$this->assertFalse( TransactionStatus::Pending->counts() );
		$this->assertFalse( TransactionStatus::Failed->counts() );
	}

	/**
	 * Anything unrecognised falls back to the safe end.
	 *
	 * @return void
	 */
	public function test_unknown_values_fall_back() {
		$this->assertSame( OrderStatus::Pending, OrderStatus::from_value( 'nonsense' ) );
		$this->assertSame( OrderStatus::Pending, OrderStatus::from_value( null ) );
		$this->assertSame( TransactionKind::Charge, TransactionKind::from_value( '' ) );
		$this->assertSame( TransactionStatus::Pending, TransactionStatus::from_value( 'maybe' ) );
	}

	/**
	 * Handing one of these back to itself is not an error.
	 *
	 * The repositories take "a kind" rather than "the string a kind is stored
	 * as", and a caller passing the enum was an easy way to get a fatal.
	 *
	 * @return void
	 */
	public function test_an_enum_passes_through_unchanged() {
		$this->assertSame( OrderStatus::Refunded, OrderStatus::from_value( OrderStatus::Refunded ) );
		$this->assertSame( TransactionKind::Refund, TransactionKind::from_value( TransactionKind::Refund ) );
		$this->assertSame( TransactionStatus::Failed, TransactionStatus::from_value( TransactionStatus::Failed ) );
	}

	/**
	 * Every state says something different to a person.
	 *
	 * @return void
	 */
	public function test_labels_are_distinct() {
		foreach ( array( OrderStatus::cases(), TransactionKind::cases(), TransactionStatus::cases() ) as $cases ) {
			$labels = array_map( static fn( $state ) => $state->label(), $cases );

			$this->assertSame( count( $labels ), count( array_unique( $labels ) ) );
			$this->assertNotContains( '', $labels );
		}
	}
}
