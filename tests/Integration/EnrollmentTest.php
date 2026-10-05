<?php
/**
 * Enrollment tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Enrollment\EnrollmentRepository;
use DeutschLMS\Roles\Capabilities;
use DeutschLMS\Roles\Roles;
use WP_Error;

/**
 * @covers \DeutschLMS\Enrollment\EnrollmentService
 * @covers \DeutschLMS\Enrollment\EnrollmentRepository
 * @covers \DeutschLMS\Roles\Capabilities
 */
final class EnrollmentTest extends TestCase {

	public function test_enroll_creates_an_active_enrollment_and_fires_action(): void {
		$course  = $this->create_course();
		$student = $this->create_user();
		$fired   = array();
		add_action(
			'dlms_user_enrolled',
			static function ( $user_id, $course_id, $source ) use ( &$fired ) {
				$fired[] = array( $user_id, $course_id, $source );
			},
			10,
			3
		);

		$result = $this->lms()->enrollments()->enroll( $student, $course );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created'] );
		$this->assertSame( EnrollmentRepository::STATUS_ACTIVE, $result['enrollment']['status'] );
		$this->assertSame( 'free', $result['enrollment']['source'] );
		$this->assertTrue( $this->lms()->enrollments()->is_enrolled( $student, $course ) );
		$this->assertSame( array( array( $student, $course, 'free' ) ), $fired );
	}

	public function test_enrolling_twice_keeps_one_row(): void {
		$course  = $this->create_course();
		$student = $this->create_user();

		$this->lms()->enrollments()->enroll( $student, $course );
		$second = $this->lms()->enrollments()->enroll( $student, $course );

		$this->assertFalse( $second['created'] );
		$this->assertCount( 1, $this->lms()->enrollments()->for_user( $student ) );
	}

	public function test_cannot_enroll_in_drafts_or_non_courses(): void {
		$student = $this->create_user();
		$draft   = $this->create_course( array( 'status' => 'draft' ) );
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertInstanceOf( WP_Error::class, $this->lms()->enrollments()->enroll( $student, $draft ) );
		$this->assertInstanceOf( WP_Error::class, $this->lms()->enrollments()->enroll( $student, $page ) );
		$this->assertInstanceOf( WP_Error::class, $this->lms()->enrollments()->enroll( 999999, $this->create_course() ) );
	}

	public function test_enroll_capability_mapping(): void {
		$course    = $this->create_course();
		$draft     = $this->create_course( array( 'status' => 'draft' ) );
		$student   = $this->create_user( Roles::STUDENT );
		$sub       = $this->create_user( 'subscriber' );
		$no_access = self::factory()->user->create( array( 'role' => '' ) );

		$this->assertTrue( user_can( $student, Capabilities::ENROLL_COURSE, $course ) );
		$this->assertTrue( user_can( $sub, Capabilities::ENROLL_COURSE, $course ), 'Subscribers can take courses.' );
		$this->assertFalse( user_can( $no_access, Capabilities::ENROLL_COURSE, $course ), 'Users without dlms_take_courses cannot enroll.' );
		$this->assertFalse( user_can( $student, Capabilities::ENROLL_COURSE, $draft ) );
		$this->assertFalse( user_can( 0, Capabilities::ENROLL_COURSE, $course ) );
	}

	public function test_enrollability_is_filterable(): void {
		$course  = $this->create_course();
		$student = $this->create_user();

		add_filter( 'dlms_course_is_enrollable', '__return_false' );
		$this->assertFalse( user_can( $student, Capabilities::ENROLL_COURSE, $course ) );
		remove_filter( 'dlms_course_is_enrollable', '__return_false' );
	}
}
