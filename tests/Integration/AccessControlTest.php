<?php
/**
 * Access control tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Roles\Roles;

/**
 * @covers \DeutschLMS\Access\AccessControl
 */
final class AccessControlTest extends TestCase {

	public function test_visitor_is_asked_to_log_in(): void {
		$c = $this->sample_course();

		$result = $this->lms()->access()->check( 0, $c['l1'] );

		$this->assertFalse( $result->allowed );
		$this->assertSame( AccessControl::NOT_LOGGED_IN, $result->reason );
		$this->assertSame( $c['course'], $result->course_id );
	}

	public function test_logged_in_user_who_is_not_enrolled_is_denied(): void {
		$c       = $this->sample_course();
		$student = $this->create_user();

		$result = $this->lms()->access()->check( $student, $c['t11'] );

		$this->assertFalse( $result->allowed );
		$this->assertSame( AccessControl::NOT_ENROLLED, $result->reason );
	}

	public function test_enrolled_student_can_open_every_step_of_a_free_form_course(): void {
		$c       = $this->sample_course( false );
		$student = $this->enrolled_student( $c['course'] );

		foreach ( array( 'l1', 't11', 't12', 'l2', 'l3' ) as $step ) {
			$this->assertTrue( $this->lms()->access()->can_view( $student, $c[ $step ] ), "Step {$step} should be open." );
		}
	}

	public function test_enrollment_in_another_course_does_not_grant_access(): void {
		$c       = $this->sample_course();
		$other   = $this->create_course();
		$student = $this->enrolled_student( $other );

		$this->assertSame( AccessControl::NOT_ENROLLED, $this->lms()->access()->check( $student, $c['l1'] )->reason );
	}

	public function test_linear_course_unlocks_first_step_only(): void {
		$c       = $this->sample_course( true );
		$student = $this->enrolled_student( $c['course'] );
		$access  = $this->lms()->access();

		$this->assertTrue( $access->can_view( $student, $c['l1'] ) );
		$this->assertTrue( $access->can_view( $student, $c['t11'] ) );

		$t12 = $access->check( $student, $c['t12'] );
		$this->assertSame( AccessControl::LOCKED, $t12->reason );
		$this->assertSame( $c['t11'], $t12->blocking_step_id );

		$l2 = $access->check( $student, $c['l2'] );
		$this->assertSame( AccessControl::LOCKED, $l2->reason );
		$this->assertSame( $c['t11'], $l2->blocking_step_id, 'The blocker points to the first unfinished topic of lesson 1.' );

		$this->assertFalse( $access->can_view( $student, $c['l3'] ) );
	}

	public function test_linear_course_unlocks_in_order_as_steps_are_completed(): void {
		$c        = $this->sample_course( true );
		$student  = $this->enrolled_student( $c['course'] );
		$access   = $this->lms()->access();
		$progress = $this->lms()->progress();

		$this->assertIsArray( $progress->complete_step( $student, $c['t11'] ) );
		$this->assertTrue( $access->can_view( $student, $c['t12'] ) );
		$this->assertFalse( $access->can_view( $student, $c['l2'] ) );

		$this->assertIsArray( $progress->complete_step( $student, $c['t12'] ) );
		$this->assertTrue( $access->can_view( $student, $c['l2'] ), 'Lesson 1 auto-completes, unlocking lesson 2.' );
		$this->assertFalse( $access->can_view( $student, $c['l3'] ) );

		$this->assertIsArray( $progress->complete_step( $student, $c['l2'] ) );
		$this->assertTrue( $access->can_view( $student, $c['l3'] ) );
	}

	public function test_course_author_and_admins_bypass_enrollment(): void {
		$instructor = $this->create_user( Roles::INSTRUCTOR );
		$course     = $this->create_course(
			array(
				'linear' => true,
				'author' => $instructor,
			)
		);
		$l1         = $this->create_lesson( $course, 1, array( 'author' => $instructor ) );
		$l2         = $this->create_lesson( $course, 2, array( 'author' => $instructor ) );
		$lms_admin  = $this->create_user( Roles::LMS_ADMIN );
		$admin      = $this->create_user( 'administrator' );
		$access     = $this->lms()->access();

		foreach ( array( $instructor, $lms_admin, $admin ) as $user_id ) {
			$result = $access->check( $user_id, $l2 );
			$this->assertTrue( $result->allowed );
			$this->assertSame( AccessControl::BYPASS, $result->reason );
		}
		$this->assertTrue( $access->can_view( $instructor, $l1 ) );
	}

	public function test_instructor_does_not_bypass_another_instructors_course(): void {
		$owner  = $this->create_user( Roles::INSTRUCTOR );
		$other  = $this->create_user( Roles::INSTRUCTOR );
		$course = $this->create_course( array( 'author' => $owner ) );
		$lesson = $this->create_lesson( $course, 1, array( 'author' => $owner ) );

		$this->assertSame( AccessControl::NOT_ENROLLED, $this->lms()->access()->check( $other, $lesson )->reason );
	}

	public function test_unpublished_steps_and_courses_are_unavailable_to_students(): void {
		$course  = $this->create_course();
		$draft   = $this->create_lesson( $course, 1, array( 'status' => 'draft' ) );
		$topic   = $this->create_topic( $draft, 1 );
		$student = $this->enrolled_student( $course );

		$this->assertSame( AccessControl::UNAVAILABLE, $this->lms()->access()->check( $student, $draft )->reason );
		$this->assertSame( AccessControl::UNAVAILABLE, $this->lms()->access()->check( $student, $topic )->reason, 'A published topic under a draft lesson is not reachable.' );

		$hidden_course = $this->create_course();
		$lesson        = $this->create_lesson( $hidden_course, 1 );
		$student2      = $this->enrolled_student( $hidden_course );
		wp_update_post(
			array(
				'ID'          => $hidden_course,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( AccessControl::UNAVAILABLE, $this->lms()->access()->check( $student2, $lesson )->reason );
	}

	public function test_orphan_steps_and_other_post_types_are_denied(): void {
		$orphan  = $this->create_lesson( 0, 1 );
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$student = $this->create_user();

		$this->assertSame( AccessControl::NO_COURSE, $this->lms()->access()->check( $student, $orphan )->reason );
		$this->assertSame( AccessControl::NOT_A_STEP, $this->lms()->access()->check( $student, $page )->reason );
	}

	public function test_access_decision_is_filterable(): void {
		$c       = $this->sample_course();
		$student = $this->enrolled_student( $c['course'] );

		$deny = static function ( $result ) {
			return new \DeutschLMS\Access\AccessResult( false, 'drip', $result->course_id );
		};
		add_filter( 'dlms_step_access', $deny );
		$result = $this->lms()->access()->check( $student, $c['l1'] );
		remove_filter( 'dlms_step_access', $deny );

		$this->assertFalse( $result->allowed );
		$this->assertSame( 'drip', $result->reason );
	}
}
