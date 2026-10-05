<?php
/**
 * Quiz tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Quiz\Grader;
use DeutschLMS\Quiz\Questions;
use DeutschLMS\Roles\Roles;
use WP_Error;

/**
 * @covers \DeutschLMS\Quiz\QuizService
 * @covers \DeutschLMS\Quiz\Grader
 * @covers \DeutschLMS\Quiz\Questions
 * @covers \DeutschLMS\Quiz\AttemptRepository
 */
final class QuizTest extends TestCase {

	// ---------------------------------------------------------------- Grading.

	public function test_grader_single_multiple_and_true_false(): void {
		$questions = Questions::sanitize(
			array(
				array(
					'id'      => 'q_single',
					'type'    => 'single',
					'text'    => 'Single',
					'answers' => array(
						array(
							'id'      => 'a_yes',
							'text'    => 'Yes',
							'correct' => true,
						),
						array(
							'id'   => 'a_no',
							'text' => 'No',
						),
					),
				),
				array(
					'id'      => 'q_multi',
					'type'    => 'multiple',
					'text'    => 'Multi',
					'points'  => 2,
					'answers' => array(
						array(
							'id'      => 'm_one',
							'text'    => 'One',
							'correct' => true,
						),
						array(
							'id'      => 'm_two',
							'text'    => 'Two',
							'correct' => true,
						),
						array(
							'id'   => 'm_three',
							'text' => 'Three',
						),
					),
				),
				array(
					'id'      => 'q_tf',
					'type'    => 'true_false',
					'text'    => 'True or false?',
					'answers' => array(
						array(
							'id'      => 'false',
							'correct' => true,
						),
					),
				),
			)
		);

		$all_right = Grader::grade(
			$questions,
			array(
				'q_single' => array( 'a_yes' ),
				'q_multi'  => array( 'm_two', 'm_one' ),
				'q_tf'     => array( 'false' ),
			)
		);
		$this->assertSame( 4, $all_right['score'] );
		$this->assertSame( 4, $all_right['max_score'] );
		$this->assertSame( 100.0, $all_right['percent'] );

		$partial_multi = Grader::grade(
			$questions,
			array(
				'q_single' => array( 'a_yes', 'a_no' ), // Only the first counts for single choice.
				'q_multi'  => array( 'm_one' ),         // All-or-nothing.
				'q_tf'     => array( 'true' ),
			)
		);
		$this->assertSame( 1, $partial_multi['score'] );
		$this->assertTrue( $partial_multi['results']['q_single']['correct'] );
		$this->assertFalse( $partial_multi['results']['q_multi']['correct'] );

		$garbage = Grader::grade( $questions, array( 'q_single' => array( 'nope' ) ) );
		$this->assertSame( 0, $garbage['score'] );
		$this->assertSame( array(), $garbage['results']['q_single']['selected'] );
	}

	public function test_pass_mark_boundary_uses_exact_math(): void {
		$this->assertTrue( Grader::passes( 4, 5, 80 ) );
		$this->assertFalse( Grader::passes( 3, 5, 80 ) );
		$this->assertTrue( Grader::passes( 2, 3, 66 ) );
		$this->assertFalse( Grader::passes( 2, 3, 67 ) );
		$this->assertFalse( Grader::passes( 0, 0, 0 ), 'An empty quiz never passes.' );
	}

	public function test_questions_sanitize_keeps_ids_and_flags_incomplete_ones(): void {
		$raw = array(
			array(
				'id'      => 'q_keep',
				'type'    => 'single',
				'text'    => '<script>alert(1)</script>Was ist das?',
				'answers' => array(
					array(
						'id'      => 'a_keep',
						'text'    => '<b>Ein Haus</b>',
						'correct' => true,
					),
					array(
						'id'   => 'a_keep',
						'text' => 'Duplicate id',
					),
				),
			),
			array(
				'type'    => 'single',
				'text'    => 'No correct answer',
				'answers' => array(
					array( 'text' => 'A' ),
					array( 'text' => 'B' ),
				),
			),
			array(
				'type' => 'single',
				'text' => '',
			),
			array(
				'type' => 'bogus',
				'text' => 'Unknown type falls back to single',
			),
		);

		$clean = Questions::sanitize( $raw );

		$this->assertCount( 3, $clean, 'Questions without text are dropped.' );
		$this->assertSame( 'q_keep', $clean[0]['id'] );
		$this->assertStringNotContainsString( '<', $clean[0]['text'] );
		$this->assertSame( 'Ein Haus', $clean[0]['answers'][0]['text'] );
		$this->assertNotSame( $clean[0]['answers'][0]['id'], $clean[0]['answers'][1]['id'], 'Duplicate IDs are replaced.' );
		$this->assertSame( 'single', $clean[2]['type'] );

		$this->assertCount( 1, Questions::playable( $clean ) );
		$this->assertCount( 2, Questions::problems( $clean ) );

		$public = Questions::public_view( Questions::playable( $clean ) );
		$this->assertArrayNotHasKey( 'correct', $public[0]['answers'][0] );
	}

	// ----------------------------------------------------------- Taking quizzes.

	public function test_failed_attempt_is_stored_but_does_not_complete_the_quiz(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array( 'pass_mark' => 100 ) );
		$student = $this->enrolled_student( $course );

		$result = $this->lms()->quizzes()->submit( $student, $quiz, array( 'q_one' => array( 'a_one' ) ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['passed'] );
		$this->assertSame( 1, $result['score'] );
		$this->assertSame( 50.0, $result['percent'] );
		$this->assertFalse( $this->lms()->quizzes()->has_passed( $student, $quiz ) );
		$this->assertCount( 1, $this->lms()->quizzes()->attempts( $student, $quiz ) );
	}

	public function test_passing_completes_quiz_and_rolls_up_to_lesson(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson );
		$other   = $this->create_lesson( $course, 2 );
		$student = $this->enrolled_student( $course );
		$calc    = $this->lms()->calculator();

		$this->assertFalse( $this->lms()->progress()->is_manually_completable( $lesson ), 'A lesson with a quiz completes through the quiz.' );
		$manual = $this->lms()->progress()->complete_step( $student, $lesson );
		$this->assertInstanceOf( WP_Error::class, $manual );

		$result = $this->lms()->quizzes()->submit( $student, $quiz, self::RIGHT );

		$this->assertTrue( $result['passed'] );
		$this->assertSame( $other, $result['next_step_id'] );
		$this->assertTrue( $calc->is_step_complete( $student, $quiz ) );
		$this->assertTrue( $calc->has_record( $student, $lesson ), 'Lesson completion is stored.' );
		$this->assertFalse( $result['course_completed'] );
	}

	public function test_topic_quiz_completes_topic_and_then_lesson(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$topic   = $this->create_topic( $lesson, 1 );
		$quiz    = $this->create_quiz( $course, $topic );
		$student = $this->enrolled_student( $course );
		$calc    = $this->lms()->calculator();

		$this->assertSame( array( $lesson, $topic, $quiz ), $this->lms()->structure()->get_steps( $course ) );

		$this->lms()->quizzes()->submit( $student, $quiz, self::RIGHT );

		$this->assertTrue( $calc->has_record( $student, $topic ) );
		$this->assertTrue( $calc->has_record( $student, $lesson ) );
	}

	public function test_final_quiz_is_required_for_course_completion(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$final   = $this->create_quiz( $course, 0 );
		$student = $this->enrolled_student( $course );

		$after_lesson = $this->lms()->progress()->complete_step( $student, $lesson );
		$this->assertFalse( $after_lesson['course_completed'] );
		$this->assertSame( $final, $after_lesson['next_step_id'] );

		$failed = $this->lms()->quizzes()->submit( $student, $final, self::WRONG );
		$this->assertFalse( $failed['course_completed'] );

		$passed = $this->lms()->quizzes()->submit( $student, $final, self::RIGHT );
		$this->assertTrue( $passed['course_completed'] );
		$this->assertSame( 'completed', $this->lms()->enrollments()->get( $student, $course )['status'] );
	}

	public function test_attempts_limit_and_reset(): void {
		$course    = $this->create_course();
		$lesson    = $this->create_lesson( $course, 1 );
		$quiz      = $this->create_quiz( $course, $lesson, 1, array( 'attempts_limit' => 2 ) );
		$student   = $this->enrolled_student( $course );
		$quizzes   = $this->lms()->quizzes();
		$lms_admin = $this->create_user( Roles::LMS_ADMIN );

		$quizzes->submit( $student, $quiz, self::WRONG );
		$second = $quizzes->submit( $student, $quiz, self::WRONG );
		$this->assertSame( 0, $second['attempts_remaining'] );

		$blocked = $quizzes->submit( $student, $quiz, self::RIGHT );
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'dlms_no_attempts_left', $blocked->get_error_code() );

		$denied = $quizzes->reset_attempts( $student, $student, $quiz );
		$this->assertInstanceOf( WP_Error::class, $denied, 'Students cannot reset their own attempts.' );

		$this->assertSame( 2, $quizzes->reset_attempts( $lms_admin, $student, $quiz ) );
		$this->assertSame( 2, $quizzes->attempts_remaining( $student, $quiz ) );
		$this->assertCount( 2, $quizzes->all_attempts_of( $student ), 'Reset attempts stay on record.' );

		$after_reset = $quizzes->submit( $student, $quiz, self::RIGHT );
		$this->assertTrue( $after_reset['passed'] );
	}

	public function test_student_summary_and_instructor_reset(): void {
		$owner    = $this->create_user( Roles::INSTRUCTOR );
		$stranger = $this->create_user( Roles::INSTRUCTOR );
		$course   = $this->create_course( array( 'author' => $owner ) );
		$lesson   = $this->create_lesson( $course, 1, array( 'author' => $owner ) );
		$quiz     = $this->create_quiz( $course, $lesson, 1, array( 'attempts_limit' => 1 ) );
		$student  = $this->enrolled_student( $course );
		$quizzes  = $this->lms()->quizzes();

		$quizzes->submit( $student, $quiz, self::WRONG );

		$summary = $quizzes->student_summary( $quiz );
		$this->assertCount( 1, $summary );
		$this->assertSame( $student, $summary[0]['user_id'] );
		$this->assertSame( 1, $summary[0]['attempts'] );
		$this->assertFalse( $summary[0]['passed'] );

		$this->assertInstanceOf( WP_Error::class, $quizzes->reset_attempts( $stranger, $student, $quiz ), 'Another instructor cannot reset.' );
		$this->assertSame( 1, $quizzes->reset_attempts( $owner, $student, $quiz ), 'The course instructor can reset.' );
		$this->assertSame( array(), $quizzes->student_summary( $quiz ) );
	}

	public function test_cannot_take_quiz_without_enrollment_or_questions(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson );
		$visitor = $this->create_user();

		$not_enrolled = $this->lms()->quizzes()->submit( $visitor, $quiz, self::RIGHT );
		$this->assertSame( 'dlms_not_enrolled', $not_enrolled->get_error_code() );

		$empty   = $this->create_quiz( $course, $lesson, 2, array(), array() );
		$student = $this->enrolled_student( $course );
		$this->assertNotContains( $empty, $this->lms()->structure()->get_steps( $course ), 'Quizzes without questions are not part of the course for students.' );
		$this->assertInstanceOf( WP_Error::class, $this->lms()->quizzes()->submit( $student, $empty, array() ) );
	}

	public function test_passing_twice_keeps_one_completion_and_retakes_never_undo_it(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson );
		$student = $this->enrolled_student( $course );
		$events  = 0;
		add_action(
			'dlms_step_completed',
			static function ( $user_id, $step_id ) use ( &$events, $quiz ) {
				if ( $step_id === $quiz ) {
					++$events;
				}
			},
			10,
			2
		);

		$this->lms()->quizzes()->submit( $student, $quiz, self::RIGHT );
		$this->lms()->quizzes()->submit( $student, $quiz, self::WRONG );
		$this->lms()->quizzes()->submit( $student, $quiz, self::RIGHT );

		$this->assertSame( 1, $events );
		$this->assertTrue( $this->lms()->quizzes()->has_passed( $student, $quiz ) );
		$this->assertSame( 100.0, $this->lms()->quizzes()->best_attempt( $student, $quiz )['percent'] );
	}

	// ------------------------------------------------------- Linear + quizzes.

	public function test_linear_course_with_quizzes_at_every_level(): void {
		$course  = $this->create_course( array( 'linear' => true ) );
		$l1      = $this->create_lesson( $course, 1 );
		$t1      = $this->create_topic( $l1, 1 );
		$tq      = $this->create_quiz( $course, $t1, 1 );
		$lq      = $this->create_quiz( $course, $l1, 2 );
		$l2      = $this->create_lesson( $course, 2 );
		$final   = $this->create_quiz( $course, 0, 3 );
		$student = $this->enrolled_student( $course );
		$access  = $this->lms()->access();

		$this->assertSame( array( $l1, $t1, $tq, $lq, $l2, $final ), $this->lms()->structure()->get_steps( $course ) );

		$this->assertTrue( $access->can_view( $student, $t1 ) );
		$this->assertTrue( $access->can_view( $student, $tq ), 'A topic quiz opens with its topic; passing it completes the topic.' );
		$this->assertSame( $tq, $access->check( $student, $lq )->blocking_step_id );
		$this->assertSame( $tq, $access->check( $student, $l2 )->blocking_step_id );

		$this->lms()->quizzes()->submit( $student, $tq, self::RIGHT );
		$this->assertTrue( $access->can_view( $student, $lq ) );
		$this->assertSame( $lq, $access->check( $student, $l2 )->blocking_step_id );

		$this->lms()->quizzes()->submit( $student, $lq, self::RIGHT );
		$this->assertTrue( $access->can_view( $student, $l2 ) );
		$this->assertSame( AccessControl::LOCKED, $access->check( $student, $final )->reason );

		$this->lms()->progress()->complete_step( $student, $l2 );
		$this->assertTrue( $access->can_view( $student, $final ) );

		$done = $this->lms()->quizzes()->submit( $student, $final, self::RIGHT );
		$this->assertTrue( $done['course_completed'] );
	}

	// ------------------------------------------------------------ No leaking.

	public function test_quiz_page_and_rest_never_contain_correct_answers(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson );
		$student = $this->enrolled_student( $course );
		wp_set_current_user( $student );

		$this->go_to( get_permalink( $quiz ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();

		$this->assertStringContainsString( 'name="dlms_answers[q_one][]"', $html );
		$this->assertStringContainsString( 'value="a_one"', $html );
		$this->assertStringNotContainsString( 'correct', strtolower( wp_strip_all_tags( $html ) ), 'Nothing in the form says which answer is correct.' );
		$this->assertStringNotContainsString( '"correct"', $html );

		$rest = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/dlms-quizzes/' . $quiz ) )->get_data();
		$this->assertStringNotContainsString( 'a_one', wp_json_encode( $rest ) );
		$this->assertArrayNotHasKey( '_dlms_questions', (array) ( $rest['meta'] ?? array() ) );
	}

	public function test_result_details_reveal_answers_only_when_enabled(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$hidden  = $this->create_quiz( $course, $lesson, 1 );
		$shown   = $this->create_quiz( $course, $lesson, 2, array( 'show_answers' => true ) );
		$student = $this->enrolled_student( $course );
		$quizzes = $this->lms()->quizzes();

		$a = $quizzes->submit( $student, $hidden, self::WRONG );
		$b = $quizzes->submit( $student, $shown, self::WRONG );

		$hidden_details = $quizzes->result_details( $hidden, $quizzes->get_attempt( $student, $a['attempt_id'] ) );
		$shown_details  = $quizzes->result_details( $shown, $quizzes->get_attempt( $student, $b['attempt_id'] ) );

		$this->assertNull( $hidden_details[0]['answers'][0]['is_correct'] );
		$this->assertTrue( $shown_details[0]['answers'][0]['is_correct'] );
		$this->assertFalse( $shown_details[0]['correct'] );
	}

	public function test_attempts_are_private(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson );
		$student = $this->enrolled_student( $course );
		$other   = $this->enrolled_student( $course );
		$admin   = $this->create_user( 'administrator' );

		$result = $this->lms()->quizzes()->submit( $student, $quiz, self::RIGHT );

		$this->assertNotNull( $this->lms()->quizzes()->get_attempt( $student, $result['attempt_id'] ) );
		$this->assertNull( $this->lms()->quizzes()->get_attempt( $other, $result['attempt_id'] ) );
		$this->assertNotNull( $this->lms()->quizzes()->get_attempt( $admin, $result['attempt_id'] ) );
	}
}
