<?php
/**
 * REST API tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Roles\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \DeutschLMS\Rest\EnrollmentController
 * @covers \DeutschLMS\Rest\ProgressController
 * @covers \DeutschLMS\Rest\CourseBuilderController
 */
final class RestApiTest extends TestCase {

	/**
	 * Dispatches a request.
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

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'dlms/v1' );
		foreach ( array( '/dlms/v1/courses/(?P<id>\d+)/enroll', '/dlms/v1/me/enrollments', '/dlms/v1/steps/(?P<id>\d+)/complete', '/dlms/v1/courses/(?P<id>\d+)/progress', '/dlms/v1/courses/(?P<id>\d+)/structure', '/dlms/v1/courses/(?P<id>\d+)/lessons', '/dlms/v1/lessons/(?P<id>\d+)/topics' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
	}

	public function test_enroll_requires_login(): void {
		$course = $this->create_course();

		$response = $this->request( 'POST', "/courses/{$course}/enroll" );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $this->lms()->enrollments()->is_enrolled( 0, $course ) );
	}

	public function test_enroll_and_list_enrollments(): void {
		$c       = $this->sample_course();
		$student = $this->create_user();
		wp_set_current_user( $student );

		$created = $this->request( 'POST', "/courses/{$c['course']}/enroll" );
		$this->assertSame( 201, $created->get_status() );
		$this->assertTrue( $created->get_data()['enrolled'] );

		$again = $this->request( 'POST', "/courses/{$c['course']}/enroll" );
		$this->assertSame( 200, $again->get_status() );
		$this->assertFalse( $again->get_data()['created'] );

		$list = $this->request( 'GET', '/me/enrollments' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertCount( 1, $list->get_data() );
		$this->assertSame( $c['course'], $list->get_data()[0]['course_id'] );
		$this->assertSame( 0, $list->get_data()[0]['progress']['percent'] );
	}

	public function test_enroll_in_draft_course_is_forbidden(): void {
		$draft = $this->create_course( array( 'status' => 'draft' ) );
		wp_set_current_user( $this->create_user() );

		$this->assertSame( 403, $this->request( 'POST', "/courses/{$draft}/enroll" )->get_status() );
	}

	public function test_complete_step_flow(): void {
		$c       = $this->sample_course( true );
		$student = $this->enrolled_student( $c['course'] );
		wp_set_current_user( $student );

		$locked = $this->request( 'POST', "/steps/{$c['l2']}/complete" );
		$this->assertSame( 403, $locked->get_status() );
		$this->assertSame( 'dlms_step_locked', $locked->get_data()['code'] );

		$ok = $this->request( 'POST', "/steps/{$c['t11']}/complete" );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( get_permalink( $c['t12'] ), $ok->get_data()['redirect'] );
		$this->assertSame( 20, $ok->get_data()['progress']['percent'] );

		$progress = $this->request( 'GET', "/courses/{$c['course']}/progress" );
		$this->assertSame( 200, $progress->get_status() );
		$this->assertSame( 1, $progress->get_data()['completed'] );
	}

	public function test_complete_step_requires_login_and_enrollment(): void {
		$c = $this->sample_course();

		$this->assertSame( 401, $this->request( 'POST', "/steps/{$c['l2']}/complete" )->get_status() );

		wp_set_current_user( $this->create_user() );
		$this->assertSame( 403, $this->request( 'POST', "/steps/{$c['l2']}/complete" )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', "/courses/{$c['course']}/progress" )->get_status() );
	}

	public function test_structure_endpoints_require_edit_permission(): void {
		$c = $this->sample_course();

		wp_set_current_user( $this->enrolled_student( $c['course'] ) );
		$this->assertSame( 403, $this->request( 'GET', "/courses/{$c['course']}/structure" )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', "/courses/{$c['course']}/structure", array( 'lessons' => array() ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', "/courses/{$c['course']}/lessons", array( 'title' => 'X' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', "/lessons/{$c['l1']}/topics", array( 'title' => 'X' ) )->get_status() );

		wp_set_current_user( $this->create_user( Roles::INSTRUCTOR ) );
		$this->assertSame( 403, $this->request( 'GET', "/courses/{$c['course']}/structure" )->get_status(), 'Another instructor’s course.' );
	}

	public function test_structure_get_and_put(): void {
		$c = $this->sample_course();
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$tree = $this->request( 'GET', "/courses/{$c['course']}/structure" );
		$this->assertSame( 200, $tree->get_status() );
		$this->assertSame( array( $c['l1'], $c['l2'], $c['l3'] ), array_column( $tree->get_data()['lessons'], 'id' ) );

		$saved = $this->request(
			'PUT',
			"/courses/{$c['course']}/structure",
			array(
				'lessons' => array(
					array(
						'id'     => $c['l3'],
						'topics' => array(),
					),
					array(
						'id'     => $c['l1'],
						'topics' => array( $c['t12'], $c['t11'] ),
					),
					array(
						'id'     => $c['l2'],
						'topics' => array(),
					),
				),
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( array( $c['l3'], $c['l1'], $c['l2'] ), array_column( $saved->get_data()['lessons'], 'id' ) );
		$this->assertSame( array( $c['t12'], $c['t11'] ), array_column( $saved->get_data()['lessons'][1]['topics'], 'id' ) );
	}

	public function test_structure_put_validates_payload(): void {
		$c = $this->sample_course();
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$bad_shape = $this->request( 'PUT', "/courses/{$c['course']}/structure", array( 'lessons' => array( array( 'nope' => 1 ) ) ) );
		$this->assertSame( 400, $bad_shape->get_status() );

		$missing = $this->request( 'PUT', "/courses/{$c['course']}/structure", array() );
		$this->assertSame( 400, $missing->get_status() );
	}

	public function test_create_lesson_and_topic_via_rest(): void {
		$c = $this->sample_course();
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$lesson = $this->request( 'POST', "/courses/{$c['course']}/lessons", array( 'title' => '<b>Neue</b> Lektion' ) );
		$this->assertSame( 201, $lesson->get_status() );
		$this->assertSame( 'Neue Lektion', $lesson->get_data()['title'], 'Title is sanitized.' );
		$this->assertSame( 'draft', $lesson->get_data()['status'] );

		$topic = $this->request( 'POST', "/lessons/{$c['l2']}/topics", array( 'title' => 'Thema' ) );
		$this->assertSame( 201, $topic->get_status() );
		$this->assertSame( $c['l2'], $this->lms()->structure()->get_lesson_id( $topic->get_data()['id'] ) );
	}

	public function test_locked_lesson_content_is_not_exposed_by_core_rest(): void {
		$c = $this->sample_course();
		wp_update_post(
			array(
				'ID'           => $c['l2'],
				'post_content' => 'GEHEIM-INHALT',
			)
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/dlms-lessons/' . $c['l2'] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['dlms_locked'] );
		$this->assertSame( '', $data['content']['rendered'] );
		$this->assertStringNotContainsString( 'GEHEIM-INHALT', wp_json_encode( $data ) );

		wp_set_current_user( $this->enrolled_student( $c['course'] ) );
		$enrolled = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/dlms-lessons/' . $c['l2'] ) )->get_data();
		$this->assertStringContainsString( 'GEHEIM-INHALT', $enrolled['content']['rendered'] );
	}
}
