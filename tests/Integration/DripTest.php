<?php
/**
 * Drip content tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Content\Meta;
use DeutschLMS\Database\Schema;

/**
 * @covers \DeutschLMS\Access\AccessControl::drip_available_at
 */
final class DripTest extends TestCase {

	/**
	 * Moves a student's enrollment date into the past.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @param int $days_ago  Days.
	 */
	private function enrolled_days_ago( int $user_id, int $course_id, int $days_ago ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'enrollments' ),
			array( 'enrolled_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ) ),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			)
		);
		$this->lms()->enrollments()->forget( $user_id );
	}

	public function test_dripped_lesson_and_its_children_open_after_the_delay(): void {
		$course = $this->create_course();
		$l1     = $this->create_lesson( $course, 1 );
		$l2     = $this->create_lesson( $course, 2 );
		$topic  = $this->create_topic( $l2, 1 );
		$quiz   = $this->create_quiz( $course, $l2 );
		update_post_meta( $l2, Meta::DRIP_DAYS, 7 );
		$student = $this->enrolled_student( $course );
		$access  = $this->lms()->access();

		$this->assertTrue( $access->can_view( $student, $l1 ) );
		foreach ( array( $l2, $topic, $quiz ) as $step ) {
			$result = $access->check( $student, $step );
			$this->assertSame( AccessControl::DRIP, $result->reason );
			$this->assertEqualsWithDelta( time() + 7 * DAY_IN_SECONDS, $result->available_at, 5 );
		}

		$this->assertInstanceOf( \WP_Error::class, $this->lms()->progress()->complete_step( $student, $topic ) );

		$this->enrolled_days_ago( $student, $course, 6 );
		$this->assertSame( AccessControl::DRIP, $access->check( $student, $l2 )->reason );

		$this->enrolled_days_ago( $student, $course, 8 );
		$this->assertTrue( $access->can_view( $student, $l2 ) );
		$this->assertTrue( $access->can_view( $student, $topic ) );
		$this->assertTrue( $access->can_view( $student, $quiz ) );
	}

	public function test_drip_is_per_student_and_managers_bypass_it(): void {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		update_post_meta( $lesson, Meta::DRIP_DAYS, 3 );
		$early = $this->enrolled_student( $course );
		$late  = $this->enrolled_student( $course );
		$admin = $this->create_user( 'administrator' );

		$this->enrolled_days_ago( $early, $course, 10 );

		$this->assertTrue( $this->lms()->access()->can_view( $early, $lesson ) );
		$this->assertFalse( $this->lms()->access()->can_view( $late, $lesson ) );
		$this->assertTrue( $this->lms()->access()->can_view( $admin, $lesson ) );
	}

	public function test_outline_and_lock_message_show_the_opening_date(): void {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		update_post_meta( $lesson, Meta::DRIP_DAYS, 2 );
		wp_set_current_user( $this->enrolled_student( $course ) );
		$date = wp_date( get_option( 'date_format' ), time() + 2 * DAY_IN_SECONDS );

		$outline = $this->lms()->renderer()->course_outline( $course );
		$this->assertStringContainsString( 'dlms-status--scheduled', $outline );
		$this->assertStringContainsString( esc_html( $date ), $outline );

		$locked = $this->lms()->renderer()->locked_message( $this->lms()->access()->check( get_current_user_id(), $lesson ), $lesson );
		$this->assertStringContainsString( 'dlms-locked--drip', $locked );
		$this->assertStringContainsString( esc_html( $date ), $locked );
	}
}
