<?php
/**
 * Course order, levels and the course grid.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Admin\CourseArrange;
use DeutschLMS\Content\CourseOrder;
use DeutschLMS\Content\Meta;

/**
 * @covers \DeutschLMS\Content\CourseOrder
 * @covers \DeutschLMS\Admin\CourseArrange
 * @covers \DeutschLMS\Frontend\Renderer::course_grid
 * @covers \DeutschLMS\Frontend\Shortcodes::course_grid
 */
final class CourseOrderTest extends TestCase {

	/**
	 * Creates a course with a position and level.
	 *
	 * @param string $title Title.
	 * @param int    $order menu_order.
	 * @param string $level Level or ''.
	 * @param string $status Post status.
	 * @return int
	 */
	private function course( string $title, int $order = 0, string $level = '', string $status = 'publish' ): int {
		$id = $this->create_course(
			array(
				'title'  => $title,
				'status' => $status,
			)
		);
		wp_update_post(
			array(
				'ID'         => $id,
				'menu_order' => $order,
			)
		);
		if ( '' !== $level ) {
			update_post_meta( $id, Meta::LEVEL, $level );
		}
		return $id;
	}

	/**
	 * Course titles in the order they appear in the HTML.
	 *
	 * @param string $html Grid HTML.
	 * @return string[]
	 */
	private static function titles( string $html ): array {
		preg_match_all( '#<h3 class="dlms-card__title"><a [^>]*>([^<]*)</a></h3>#', $html, $matches );
		return array_map( 'html_entity_decode', $matches[1] );
	}

	/**
	 * Group headings in the order they appear in the HTML.
	 *
	 * @param string $html Grid HTML.
	 * @return string[]
	 */
	private static function headings( string $html ): array {
		preg_match_all( '#<h2 class="dlms-course-group__title">([^<]*)</h2>#', $html, $matches );
		return $matches[1];
	}

	public function test_sanitize_level_accepts_only_known_levels(): void {
		$this->assertSame( 'A1', CourseOrder::sanitize_level( ' a1 ' ) );
		$this->assertSame( 'C2', CourseOrder::sanitize_level( 'C2' ) );
		$this->assertSame( '', CourseOrder::sanitize_level( 'Z9' ) );
		$this->assertSame( '', CourseOrder::sanitize_level( null ) );
	}

	public function test_group_by_level_orders_groups_and_puts_no_level_last(): void {
		$groups = CourseOrder::group_by_level(
			array(
				array(
					'id'    => 1,
					'level' => '',
				),
				array(
					'id'    => 2,
					'level' => 'A2',
				),
				array(
					'id'    => 3,
					'level' => 'A1',
				),
				array(
					'id'    => 4,
					'level' => 'A2',
				),
			)
		);

		$this->assertSame( array( 'A1', 'A2', '' ), array_column( $groups, 'level' ) );
		$this->assertSame( array( 2, 4 ), array_column( $groups[1]['courses'], 'id' ) );
	}

	public function test_grid_follows_the_arrangement_then_title(): void {
		$this->course( 'Zeta', 20 );
		$this->course( 'Alpha', 30 );
		$this->course( 'Unplaced B' );
		$this->course( 'Unplaced A' );
		$this->course( 'Draft', 5, '', 'draft' );

		$html = do_shortcode( '[dlms_course_grid]' );

		// Not arranged courses (position 0) come first, by title; drafts are never shown.
		$this->assertSame( array( 'Unplaced A', 'Unplaced B', 'Zeta', 'Alpha' ), self::titles( $html ) );
		$this->assertSame( array(), self::headings( $html ) );
	}

	public function test_order_attribute_reverses_the_arrangement(): void {
		$this->course( 'One', 10 );
		$this->course( 'Two', 20 );

		$this->assertSame( array( 'Two', 'One' ), self::titles( do_shortcode( '[dlms_course_grid order="desc"]' ) ) );
	}

	public function test_title_and_date_orders_still_work(): void {
		$old = $this->course( 'Banana', 10 );
		$this->course( 'Apple', 20 );
		wp_update_post(
			array(
				'ID'            => $old,
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			)
		);

		$this->assertSame( array( 'Apple', 'Banana' ), self::titles( do_shortcode( '[dlms_course_grid orderby="title"]' ) ) );
		$this->assertSame( array( 'Apple', 'Banana' ), self::titles( do_shortcode( '[dlms_course_grid orderby="date"]' ) ) );
	}

	public function test_ids_attribute_shows_only_those_courses_in_that_order(): void {
		$a = $this->course( 'A', 10 );
		$b = $this->course( 'B', 20 );
		$this->course( 'C', 30 );

		$html = do_shortcode( sprintf( '[dlms_course_grid ids="%d,%d"]', $b, $a ) );

		$this->assertSame( array( 'B', 'A' ), self::titles( $html ) );
	}

	public function test_group_by_level_shows_a_heading_per_level(): void {
		$this->course( 'A2 course', 10, 'A2' );
		$this->course( 'A1 second', 30, 'A1' );
		$this->course( 'A1 first', 20, 'A1' );
		$this->course( 'No level', 5 );

		$html = do_shortcode( '[dlms_course_grid group_by="level"]' );

		$this->assertSame( array( 'A1', 'A2', 'Other courses' ), self::headings( $html ) );
		$this->assertSame( array( 'A1 first', 'A1 second', 'A2 course', 'No level' ), self::titles( $html ) );
	}

	public function test_group_by_level_without_any_level_has_no_headings(): void {
		$this->course( 'One', 10 );
		$this->course( 'Two', 20 );

		$html = do_shortcode( '[dlms_course_grid group_by="level"]' );

		$this->assertSame( array(), self::headings( $html ) );
		$this->assertSame( array( 'One', 'Two' ), self::titles( $html ) );
	}

	public function test_arrange_writes_positions_and_levels(): void {
		$one   = $this->course( 'One' );
		$two   = $this->course( 'Two' );
		$three = $this->course( 'Three', 0, 'A2' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		( new CourseArrange() )->apply(
			array( $three, $one, $two ),
			array(
				$three => '',
				$one   => 'A1',
				$two   => 'bogus',
			)
		);

		$this->assertSame( CourseOrder::STEP, (int) get_post( $three )->menu_order );
		$this->assertSame( 2 * CourseOrder::STEP, (int) get_post( $one )->menu_order );
		$this->assertSame( 3 * CourseOrder::STEP, (int) get_post( $two )->menu_order );
		$this->assertSame( '', CourseOrder::level_for( $three ) );
		$this->assertSame( 'A1', CourseOrder::level_for( $one ) );
		$this->assertSame( '', CourseOrder::level_for( $two ) );
		$this->assertSame( array( 'Three', 'One', 'Two' ), self::titles( do_shortcode( '[dlms_course_grid]' ) ) );
	}

	public function test_arrange_ignores_courses_the_user_cannot_edit(): void {
		$course = $this->course( 'Mine', 40 );
		wp_set_current_user( 0 );

		( new CourseArrange() )->apply( array( $course ), array( $course => 'A1' ) );

		$this->assertSame( 40, (int) get_post( $course )->menu_order );
		$this->assertSame( '', CourseOrder::level_for( $course ) );
	}
}
