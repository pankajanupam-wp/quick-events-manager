<?php
/**
 * Building the query arguments that switch the occurrence join on.
 *
 * The SQL those arguments turn into is verified against a real database; what
 * can be checked here is the specification a caller ends up with, which is
 * where a silently dropped argument would hide.
 *
 * @package QuickEventsManager
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QuickEventsManager\Events\OccurrenceQuery;

/**
 * Occurrence query argument building.
 */
#[CoversClass( OccurrenceQuery::class )]
final class OccurrenceQueryTest extends TestCase {

	/**
	 * The occurrence specification inside a set of query arguments.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	private function spec( array $args ): array {
		return $args[ OccurrenceQuery::QUERY_VAR ];
	}

	/**
	 * Upcoming asks for future dates, soonest first.
	 *
	 * @return void
	 */
	public function test_upcoming_args() {
		$spec = $this->spec( OccurrenceQuery::upcoming_args() );

		$this->assertSame( 'upcoming', $spec['when'] );
		$this->assertSame( 'ASC', $spec['order'] );
		$this->assertTrue( $spec['required'] );
		$this->assertTrue( $spec['group'] );
	}

	/**
	 * Past asks for finished dates, newest first.
	 *
	 * @return void
	 */
	public function test_past_args() {
		$spec = $this->spec( OccurrenceQuery::past_args() );

		$this->assertSame( 'past', $spec['when'] );
		$this->assertSame( 'DESC', $spec['order'] );
	}

	/**
	 * "All" joins LEFT, so an undated event is still listed.
	 *
	 * An admin list that drops undated events the moment someone sorts by date
	 * makes those posts unreachable through the UI.
	 *
	 * @return void
	 */
	public function test_all_args_keeps_undated_events() {
		$spec = $this->spec( OccurrenceQuery::all_args() );

		$this->assertSame( 'any', $spec['when'] );
		$this->assertFalse( $spec['required'] );
	}

	/**
	 * The post type and status are set, and a caller can still override them.
	 *
	 * @return void
	 */
	public function test_defaults_are_overridable() {
		$args = OccurrenceQuery::upcoming_args();
		$this->assertSame( QEVM_POST_TYPE, $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );

		$custom = OccurrenceQuery::upcoming_args( array( 'post_status' => array( 'publish', 'draft' ) ) );
		$this->assertSame( array( 'publish', 'draft' ), $custom['post_status'] );
	}

	/**
	 * A caller's own occurrence settings refine the helper's, not the reverse.
	 *
	 * This was a real defect: args() overwrote the key outright, so passing
	 * `now` or `group` alongside upcoming_args() was accepted and then silently
	 * discarded. Silently is the problem — the caller gets no signal, and the
	 * query quietly answers a different question from the one asked.
	 *
	 * @return void
	 */
	public function test_a_caller_can_refine_the_specification() {
		$args = OccurrenceQuery::upcoming_args(
			array(
				'posts_per_page'           => 5,
				OccurrenceQuery::QUERY_VAR => array(
					'now'   => '2030-01-01 00:00:00',
					'group' => false,
				),
			)
		);

		$spec = $this->spec( $args );

		// The caller's values win.
		$this->assertSame( '2030-01-01 00:00:00', $spec['now'] );
		$this->assertFalse( $spec['group'] );

		// What the caller did not mention is untouched.
		$this->assertSame( 'upcoming', $spec['when'] );
		$this->assertSame( 'ASC', $spec['order'] );

		// And the ordinary arguments still came through.
		$this->assertSame( 5, $args['posts_per_page'] );
	}

	/**
	 * The query variable is not left in the arguments twice.
	 *
	 * @return void
	 */
	public function test_the_query_var_appears_once() {
		$args = OccurrenceQuery::upcoming_args(
			array( OccurrenceQuery::QUERY_VAR => array( 'now' => '2030-01-01 00:00:00' ) )
		);

		$this->assertCount( 1, array_keys( $args, $args[ OccurrenceQuery::QUERY_VAR ], true ) );
		$this->assertIsArray( $args[ OccurrenceQuery::QUERY_VAR ] );
	}

	/**
	 * Every key the clause builder reads has a default.
	 *
	 * A missing key would be an undefined index at the point the SQL is built,
	 * which is the worst place to find out.
	 *
	 * @return void
	 */
	public function test_every_specification_key_has_a_default() {
		$this->assertSame(
			array( 'when', 'order', 'now', 'group', 'required', 'statuses' ),
			array_keys( OccurrenceQuery::defaults() )
		);
	}
}
