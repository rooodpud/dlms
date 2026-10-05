<?php
/**
 * Quiz REST endpoints and quiz placement in the course structure.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\Meta;
use DeutschLMS\Roles\Roles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \DeutschLMS\Rest\QuizController
 * @covers \DeutschLMS\Rest\CourseBuilderController
 * @covers \DeutschLMS\Content\StructureEditor
 * @covers \DeutschLMS\Content\CourseStructure
 */
final class QuizRestAndStructureTest extends TestCase {

	/**
	 * Dispatches a JSON request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route under dlms/v1.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Response
	 */
	private function request( string $method, string $path, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/dlms/v1' . $path );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_submit_attempt_over_rest(): void {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		$quiz   = $this->create_quiz( $course, $lesson );

		$this->assertSame( 401, $this->request( 'POST', "/quizzes/{$quiz}/attempts", array( 'answers' => self::RIGHT ) )->get_status() );

		wp_set_current_user( $this->create_user() );
		$this->assertSame( 403, $this->request( 'POST', "/quizzes/{$quiz}/attempts", array( 'answers' => self::RIGHT ) )->get_status(), 'Not enrolled.' );

		wp_set_current_user( $this->enrolled_student( $course ) );
		$response = $this->request( 'POST', "/quizzes/{$quiz}/attempts", array( 'answers' => self::RIGHT ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['passed'] );
		$this->assertStringContainsString( 'dlms_attempt=', $response->get_data()['redirect'] );
		$this->assertArrayNotHasKey( 'answers', $response->get_data(), 'Correct answers are not returned.' );

		$list = $this->request( 'GET', "/quizzes/{$quiz}/attempts" )->get_data();
		$this->assertTrue( $list['passed'] );
		$this->assertCount( 1, $list['attempts'] );
	}

	public function test_attempt_payload_is_validated(): void {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		$quiz   = $this->create_quiz( $course, $lesson );
		wp_set_current_user( $this->enrolled_student( $course ) );

		$this->assertSame( 400, $this->request( 'POST', "/quizzes/{$quiz}/attempts", array( 'answers' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', "/quizzes/{$quiz}/attempts", array() )->get_status() );

		$this->assertSame( 403, $this->request( 'POST', "/quizzes/{$lesson}/attempts", array( 'answers' => self::RIGHT ) )->get_status(), 'Lessons are not quizzes.' );
	}

	public function test_builder_creates_and_places_quizzes(): void {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		$topic  = $this->create_topic( $lesson, 1 );
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$final = $this->request(
			'POST',
			"/courses/{$course}/quizzes",
			array(
				'title'     => 'Abschlusstest',
				'parent_id' => 0,
			)
		);
		$this->assertSame( 201, $final->get_status() );
		$this->assertSame( 0, $this->lms()->structure()->get_quiz_parent_id( $final->get_data()['id'] ) );
		$this->assertSame( 0, $final->get_data()['question_count'] );

		$topic_quiz = $this->request(
			'POST',
			"/courses/{$course}/quizzes",
			array(
				'title'     => 'Thema-Quiz',
				'parent_id' => $topic,
			)
		);
		$this->assertSame( 201, $topic_quiz->get_status() );

		$foreign_lesson = $this->create_lesson( $this->create_course(), 1 );
		$bad            = $this->request(
			'POST',
			"/courses/{$course}/quizzes",
			array(
				'title'     => 'Nope',
				'parent_id' => $foreign_lesson,
			)
		);
		$this->assertSame( 400, $bad->get_status() );

		$tree = $this->request( 'GET', "/courses/{$course}/structure" )->get_data();
		$this->assertSame( array( $final->get_data()['id'] ), array_column( $tree['quizzes'], 'id' ) );
		$this->assertSame( array( $topic_quiz->get_data()['id'] ), array_column( $tree['lessons'][0]['topics'][0]['quizzes'], 'id' ) );
	}

	public function test_save_order_moves_quizzes_between_levels(): void {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		$topic  = $this->create_topic( $lesson, 1 );
		$q1     = $this->create_quiz( $course, $topic, 1 );
		$q2     = $this->create_quiz( $course, 0, 2 );
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$saved = $this->request(
			'PUT',
			"/courses/{$course}/structure",
			array(
				'lessons' => array(
					array(
						'id'      => $lesson,
						'topics'  => array(
							array(
								'id'      => $topic,
								'quizzes' => array( $q2 ),
							),
						),
						'quizzes' => array(),
					),
				),
				'quizzes' => array( $q1 ),
			)
		);

		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( $topic, (int) get_post_meta( $q2, Meta::PARENT_ID, true ) );
		$this->assertSame( 0, (int) get_post_meta( $q1, Meta::PARENT_ID, true ) );
		$this->assertSame( array( $lesson, $topic, $q2, $q1 ), $this->lms()->structure()->get_steps( $course ) );
	}

	public function test_save_order_rejects_quizzes_from_other_courses(): void {
		$course       = $this->create_course();
		$lesson       = $this->create_lesson( $course, 1 );
		$other_course = $this->create_course();
		$other_quiz   = $this->create_quiz( $other_course, 0 );
		$admin        = $this->create_user( 'administrator' );

		$result = $this->lms()->structure_editor()->save_order( $admin, $course, array( array( 'id' => $lesson ) ), array( $other_quiz ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dlms_invalid_quiz', $result->get_error_code() );
	}

	public function test_moving_a_lesson_brings_its_quizzes(): void {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		$topic  = $this->create_topic( $lesson, 1 );
		$tq     = $this->create_quiz( $course, $topic, 1 );
		$lq     = $this->create_quiz( $course, $lesson, 2 );
		$target = $this->create_course();
		$admin  = $this->create_user( 'administrator' );

		$this->assertTrue( $this->lms()->structure_editor()->assign_lesson( $admin, $lesson, $target ) );

		$this->assertSame( array( $lesson, $topic, $tq, $lq ), $this->lms()->structure()->get_steps( $target ) );
		$this->assertSame( array(), $this->lms()->structure()->get_steps( $course ) );
	}

	public function test_instructor_cannot_attach_quiz_to_another_course(): void {
		$owner    = $this->create_user( Roles::INSTRUCTOR );
		$intruder = $this->create_user( Roles::INSTRUCTOR );
		$course   = $this->create_course( array( 'author' => $owner ) );
		$quiz     = self::factory()->post->create(
			array(
				'post_type'   => 'dlms_quiz',
				'post_author' => $intruder,
			)
		);

		$result = $this->lms()->structure_editor()->assign_quiz( $intruder, $quiz, $course, 0 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $this->lms()->structure()->get_course_id( $quiz ) );
	}
}
