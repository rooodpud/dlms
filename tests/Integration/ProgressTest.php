<?php
/**
 * Progress logic tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Enrollment\EnrollmentRepository;
use WP_Error;

/**
 * @covers \DeutschLMS\Progress\ProgressService
 * @covers \DeutschLMS\Progress\ProgressCalculator
 * @covers \DeutschLMS\Progress\ProgressRepository
 */
final class ProgressTest extends TestCase {

	public function test_new_enrollment_starts_at_zero_percent(): void {
		$c       = $this->sample_course();
		$student = $this->enrolled_student( $c['course'] );

		$summary = $this->lms()->calculator()->summary( $student, $c['course'] );

		$this->assertSame( 5, $summary['total'] );
		$this->assertSame( 0, $summary['completed'] );
		$this->assertSame( 0, $summary['percent'] );
		$this->assertFalse( $summary['is_complete'] );
		$this->assertSame( $c['l1'], $summary['next_step_id'] );
	}

	public function test_completing_a_step_records_progress_and_returns_next_step(): void {
		$c       = $this->sample_course();
		$student = $this->enrolled_student( $c['course'] );
		$fired   = 0;
		add_action(
			'dlms_step_completed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$result = $this->lms()->progress()->complete_step( $student, $c['t11'] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['newly_completed'] );
		$this->assertSame( $c['t12'], $result['next_step_id'] );
		$this->assertSame( 1, $result['summary']['completed'] );
		$this->assertSame( 20, $result['summary']['percent'] );
		$this->assertTrue( $this->lms()->calculator()->is_step_complete( $student, $c['t11'] ) );
		$this->assertSame( 1, $fired );
	}

	public function test_completing_twice_is_idempotent(): void {
		$c       = $this->sample_course();
		$student = $this->enrolled_student( $c['course'] );

		$this->lms()->progress()->complete_step( $student, $c['l2'] );
		$second = $this->lms()->progress()->complete_step( $student, $c['l2'] );

		$this->assertIsArray( $second );
		$this->assertFalse( $second['newly_completed'] );
		$this->assertSame( 1, $second['summary']['completed'] );
	}

	public function test_progress_requires_enrollment(): void {
		$c        = $this->sample_course();
		$visitor  = $this->create_user();
		$instruct = $this->create_user( 'administrator' );

		$result = $this->lms()->progress()->complete_step( $visitor, $c['l2'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dlms_not_enrolled', $result->get_error_code() );

		$admin_result = $this->lms()->progress()->complete_step( $instruct, $c['l2'] );
		$this->assertInstanceOf( WP_Error::class, $admin_result, 'Managers can preview, but progress is only tracked for enrolled users.' );
	}

	public function test_lesson_with_topics_cannot_be_completed_by_hand(): void {
		$c       = $this->sample_course();
		$student = $this->enrolled_student( $c['course'] );

		$result = $this->lms()->progress()->complete_step( $student, $c['l1'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dlms_step_auto_completes', $result->get_error_code() );
		$this->assertFalse( $this->lms()->calculator()->is_step_complete( $student, $c['l1'] ) );
	}

	public function test_lesson_completes_when_its_last_topic_is_completed(): void {
		$c       = $this->sample_course();
		$student = $this->enrolled_student( $c['course'] );
		$calc    = $this->lms()->calculator();

		$this->lms()->progress()->complete_step( $student, $c['t11'] );
		$this->assertFalse( $calc->is_step_complete( $student, $c['l1'] ) );

		$result = $this->lms()->progress()->complete_step( $student, $c['t12'] );

		$this->assertTrue( $calc->is_step_complete( $student, $c['l1'] ) );
		$this->assertTrue( $calc->has_record( $student, $c['l1'] ), 'The lesson completion is stored, not only derived.' );
		$this->assertSame( 3, $result['summary']['completed'] );
		$this->assertSame( $c['l2'], $result['next_step_id'] );
	}

	public function test_linear_course_rejects_out_of_order_completion(): void {
		$c       = $this->sample_course( true );
		$student = $this->enrolled_student( $c['course'] );

		$result = $this->lms()->progress()->complete_step( $student, $c['l3'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dlms_step_locked', $result->get_error_code() );
		$this->assertSame( 0, $this->lms()->calculator()->summary( $student, $c['course'] )['completed'] );
	}

	public function test_free_form_course_allows_any_order(): void {
		$c       = $this->sample_course( false );
		$student = $this->enrolled_student( $c['course'] );

		$this->assertIsArray( $this->lms()->progress()->complete_step( $student, $c['l3'] ) );
		$this->assertIsArray( $this->lms()->progress()->complete_step( $student, $c['t12'] ) );
	}

	public function test_finishing_every_step_completes_the_course_once(): void {
		$c         = $this->sample_course( true );
		$student   = $this->enrolled_student( $c['course'] );
		$completed = array();
		add_action(
			'dlms_course_completed',
			static function ( $user_id, $course_id ) use ( &$completed ) {
				$completed[] = array( $user_id, $course_id );
			},
			10,
			2
		);

		foreach ( array( 't11', 't12', 'l2' ) as $step ) {
			$result = $this->lms()->progress()->complete_step( $student, $c[ $step ] );
			$this->assertFalse( $result['course_completed'] );
		}
		$final = $this->lms()->progress()->complete_step( $student, $c['l3'] );

		$this->assertTrue( $final['course_completed'] );
		$this->assertSame( 100, $final['summary']['percent'] );
		$this->assertTrue( $final['summary']['is_complete'] );
		$this->assertSame( 0, $final['next_step_id'] );

		$enrollment = $this->lms()->enrollments()->get( $student, $c['course'] );
		$this->assertSame( EnrollmentRepository::STATUS_COMPLETED, $enrollment['status'] );
		$this->assertNotEmpty( $enrollment['completed_at'] );

		$again = $this->lms()->progress()->complete_step( $student, $c['l3'] );
		$this->assertFalse( $again['course_completed'] );
		$this->assertSame( array( array( $student, $c['course'] ) ), $completed );
	}

	public function test_percent_ignores_removed_steps_and_counts_new_ones(): void {
		$c       = $this->sample_course();
		$student = $this->enrolled_student( $c['course'] );

		$this->lms()->progress()->complete_step( $student, $c['l2'] );
		$this->lms()->progress()->complete_step( $student, $c['l3'] );
		$this->assertSame( 40, $this->lms()->calculator()->summary( $student, $c['course'] )['percent'] );

		wp_trash_post( $c['l3'] );
		$this->lms()->flush_runtime_caches();
		$this->assertSame( 25, $this->lms()->calculator()->summary( $student, $c['course'] )['percent'], '1 of 4 remaining steps.' );

		$this->create_lesson( $c['course'], 4 );
		$this->assertSame( 20, $this->lms()->calculator()->summary( $student, $c['course'] )['percent'], '1 of 5 steps after adding one.' );
	}

	public function test_progress_is_per_user(): void {
		$c   = $this->sample_course();
		$one = $this->enrolled_student( $c['course'] );
		$two = $this->enrolled_student( $c['course'] );

		$this->lms()->progress()->complete_step( $one, $c['l2'] );

		$this->assertTrue( $this->lms()->calculator()->is_step_complete( $one, $c['l2'] ) );
		$this->assertFalse( $this->lms()->calculator()->is_step_complete( $two, $c['l2'] ) );
	}

	public function test_invalid_step_is_rejected(): void {
		$student = $this->create_user();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->lms()->progress()->complete_step( $student, $page );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dlms_invalid_step', $result->get_error_code() );
	}
}
