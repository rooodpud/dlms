<?php
/**
 * Quiz endpoints.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Quiz\QuizService;
use DeutschLMS\Roles\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /dlms/v1/quizzes/{id}/attempts  Submit answers; graded on the server.
 * GET  /dlms/v1/quizzes/{id}/attempts  The current user's attempts (scores only).
 */
final class QuizController extends RestController {

	/**
	 * Quiz service.
	 *
	 * @var QuizService
	 */
	private QuizService $quizzes;

	/**
	 * Constructor.
	 *
	 * @param QuizService $quizzes Quiz service.
	 */
	public function __construct( QuizService $quizzes ) {
		$this->quizzes = $quizzes;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/quizzes/(?P<id>\d+)/attempts',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => array( $this, 'can_take' ),
					'args'                => array(
						'id'      => $this->id_arg(),
						'answers' => array(
							'type'                 => 'object',
							'required'             => true,
							'maxProperties'        => 200,
							'additionalProperties' => array(
								'type'     => 'array',
								'maxItems' => 20,
								// Answer/block IDs, or typed gap answers.
								'items'    => array(
									'type'      => 'string',
									'maxLength' => 100,
								),
							),
							'validate_callback'    => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_attempts' ),
					'permission_callback' => array( $this, 'can_take' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
			)
		);
	}

	/**
	 * Permission: logged in and allowed to record progress on this quiz.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_take( WP_REST_Request $request ) {
		$quiz_id = (int) $request['id'];
		if ( ! is_user_logged_in() || PostTypes::QUIZ !== get_post_type( $quiz_id ) || ! current_user_can( Capabilities::COMPLETE_STEP, $quiz_id ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Grades and stores an attempt.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$result = $this->quizzes->submit( get_current_user_id(), (int) $request['id'], (array) $request['answers'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$redirect = $result['result_url'];
		if ( $result['course_completed'] ) {
			$redirect = add_query_arg( 'dlms_notice', 'course_completed', $redirect );
		}

		return new WP_REST_Response(
			array(
				'attempt_id'         => $result['attempt_id'],
				'score'              => $result['score'],
				'max_score'          => $result['max_score'],
				'percent'            => $result['percent'],
				'pass_mark'          => $result['pass_mark'],
				'passed'             => $result['passed'],
				'late'               => $result['late'],
				'attempts_remaining' => $result['attempts_remaining'],
				'course_completed'   => $result['course_completed'],
				'next_step_id'       => $result['next_step_id'],
				'redirect'           => $redirect,
			),
			201
		);
	}

	/**
	 * Lists the current user's attempts (no answers).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_attempts( WP_REST_Request $request ): WP_REST_Response {
		$quiz_id = (int) $request['id'];
		$items   = array();
		foreach ( $this->quizzes->attempts( get_current_user_id(), $quiz_id ) as $attempt ) {
			$items[] = array(
				'id'         => $attempt['id'],
				'score'      => $attempt['score'],
				'max_score'  => $attempt['max_score'],
				'percent'    => $attempt['percent'],
				'passed'     => $attempt['passed'],
				'late'       => $attempt['late'],
				'created_at' => mysql_to_rfc3339( $attempt['created_at'] ),
			);
		}

		return new WP_REST_Response(
			array(
				'quiz_id'            => $quiz_id,
				'passed'             => $this->quizzes->has_passed( get_current_user_id(), $quiz_id ),
				'attempts_remaining' => $this->quizzes->attempts_remaining( get_current_user_id(), $quiz_id ),
				'attempts'           => $items,
			)
		);
	}
}
