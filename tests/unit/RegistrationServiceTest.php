<?php
/**
 * Turning a submitted form into the people a booking covers.
 *
 * Everything here is pure: what the service does with the names on a
 * submission, before any of it reaches a database. The rest of create() is
 * $wpdb and is verified against real MySQL.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Registration\RegistrationService;

/**
 * Guest name handling.
 */
#[CoversClass( RegistrationService::class )]
final class RegistrationServiceTest extends TestCase {

	/**
	 * A booking for one has no guests to read.
	 *
	 * @return void
	 */
	public function test_a_single_place_collects_no_guest_names() {
		$this->assertSame(
			array(),
			RegistrationService::guest_names( array( 2 => 'Grace Hopper' ), 1 )
		);
	}

	/**
	 * Names arrive keyed by the place they belong to.
	 *
	 * @return void
	 */
	public function test_names_are_kept_against_their_position() {
		$names = RegistrationService::guest_names(
			array(
				2 => 'Grace Hopper',
				3 => 'Katherine Johnson',
			),
			3
		);

		$this->assertSame(
			array(
				2 => 'Grace Hopper',
				3 => 'Katherine Johnson',
			),
			$names
		);
	}

	/**
	 * Position 1 is the booker, whose name is a field of its own.
	 *
	 * @return void
	 */
	public function test_the_first_place_is_never_read_from_the_guest_fields() {
		$names = RegistrationService::guest_names(
			array(
				1 => 'Somebody Else',
				2 => 'Grace Hopper',
			),
			2
		);

		$this->assertSame( array( 2 => 'Grace Hopper' ), $names );
	}

	/**
	 * More names than places must not become more places.
	 *
	 * Capacity was counted against the quantity. Honouring the names instead
	 * would let a submission write as many attendee rows as it liked into an
	 * event with one place left.
	 *
	 * @return void
	 */
	public function test_names_beyond_the_quantity_are_discarded() {
		$submitted = array();

		for ( $position = 2; $position <= 20; $position++ ) {
			$submitted[ $position ] = 'Gatecrasher ' . $position;
		}

		$names = RegistrationService::guest_names( $submitted, 3 );

		$this->assertSame( array( 2, 3 ), array_keys( $names ) );
	}

	/**
	 * A quantity above the cap cannot widen the range either.
	 *
	 * @return void
	 */
	public function test_the_place_cap_bounds_the_names_read() {
		$submitted = array();

		for ( $position = 2; $position <= 60; $position++ ) {
			$submitted[ $position ] = 'Person ' . $position;
		}

		$names = RegistrationService::guest_names( $submitted, 50 );

		$this->assertCount( RegistrationService::MAX_PLACES - 1, $names );
		$this->assertArrayNotHasKey( RegistrationService::MAX_PLACES + 1, $names );
	}

	/**
	 * An unnamed place is stored as unnamed, not as a blank string.
	 *
	 * @return void
	 */
	public function test_blank_names_are_dropped_rather_than_stored() {
		$names = RegistrationService::guest_names(
			array(
				2 => '   ',
				3 => 'Katherine Johnson',
				4 => '',
			),
			4
		);

		$this->assertSame( array( 3 => 'Katherine Johnson' ), $names );
	}

	/**
	 * Names are sanitised and trimmed on the way in.
	 *
	 * @return void
	 */
	public function test_names_are_sanitised() {
		$names = RegistrationService::guest_names(
			array( 2 => "  <script>alert('x')</script>Grace  " ),
			2
		);

		$this->assertArrayHasKey( 2, $names );
		$this->assertStringNotContainsString( '<script>', $names[2] );
		$this->assertSame( trim( $names[2] ), $names[2] );
	}

	/**
	 * A name longer than the column is cut, not refused.
	 *
	 * @return void
	 */
	public function test_an_over_long_name_is_truncated_to_the_column_width() {
		$names = RegistrationService::guest_names(
			array( 2 => str_repeat( 'a', 400 ) ),
			2
		);

		$this->assertSame( 190, mb_strlen( $names[2] ) );
	}

	/**
	 * The richer per-guest shape custom fields will send is accepted now.
	 *
	 * @return void
	 */
	public function test_a_guest_may_be_submitted_as_an_array() {
		$names = RegistrationService::guest_names(
			array( 2 => array( 'name' => 'Grace Hopper' ) ),
			2
		);

		$this->assertSame( array( 2 => 'Grace Hopper' ), $names );
	}

	/**
	 * Input that is not an array at all is not an error.
	 *
	 * @return void
	 */
	public function test_unusable_input_yields_no_names() {
		$this->assertSame( array(), RegistrationService::guest_names( 'Grace Hopper', 3 ) );
		$this->assertSame( array(), RegistrationService::guest_names( null, 3 ) );
		$this->assertSame( array(), RegistrationService::guest_names( array( 2 => array( 'x' ) ), 3 ) );
	}

	/**
	 * One place, one person — including when only one place was booked.
	 *
	 * @return void
	 */
	public function test_a_booking_produces_one_person_per_place() {
		$people = RegistrationService::people(
			array(
				'name'     => 'Ada Lovelace',
				'email'    => 'ada@example.com',
				'quantity' => 3,
				'guests'   => array( 2 => 'Grace Hopper' ),
			)
		);

		$this->assertCount( 3, $people );
		$this->assertSame( 'Ada Lovelace', $people[0]['name'] );
		$this->assertSame( 'Grace Hopper', $people[1]['name'] );
		$this->assertSame( '', $people[2]['name'] );
	}

	/**
	 * The booker takes the first place and is the only address collected.
	 *
	 * The form asks for one email address. Copying it onto a guest would say
	 * something about them that nobody entered.
	 *
	 * @return void
	 */
	public function test_only_the_booker_carries_an_email_address() {
		$people = RegistrationService::people(
			array(
				'name'     => 'Ada Lovelace',
				'email'    => 'ada@example.com',
				'quantity' => 2,
				'guests'   => array( 2 => 'Grace Hopper' ),
			)
		);

		$this->assertSame( 'ada@example.com', $people[0]['email'] );
		$this->assertSame( '', $people[1]['email'] );
	}

	/**
	 * A booking for one still produces a person.
	 *
	 * @return void
	 */
	public function test_a_single_place_still_produces_one_person() {
		$people = RegistrationService::people(
			array(
				'name'     => 'Ada Lovelace',
				'email'    => 'ada@example.com',
				'quantity' => 1,
				'guests'   => array(),
			)
		);

		$this->assertCount( 1, $people );
		$this->assertSame( 'Ada Lovelace', $people[0]['name'] );
	}
}
