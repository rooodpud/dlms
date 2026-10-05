<?php
/**
 * Progress endpoints.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Progress\ProgressCalculator;
use DeutschLMS\Progress\ProgressService;
use DeutschLMS\Roles\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /dlms/v1/steps/{id}/complete     Mark a lesson/topic complete.
 * GET  /dlms/v1/courses/{id}/progress   The current user's progress in a course.
 */
final class ProgressController extends RestController {

	/**
	 * Progress service.
	 *
	 * @var ProgressService
	 */
	private ProgressService $progress;

	/**
	 * Progress calculator.
	 *
	 * @var ProgressCalculator
	 */
	private ProgressCalculator $calculator;

	/**
	 * Enrollment service.
	 *
	 * @var EnrollmentService
	 */
	private EnrollmentService $enrollments;

	/**
	 * Constructor.
	 *
	 * @param ProgressService    $progress    Progress service.
	 * @param ProgressCalculator $calculator  Progress calculator.
	 * @param EnrollmentService  $enrollments Enrollment service.
	 */
	public function __construct( ProgressService $progress, ProgressCalculator $calculator, EnrollmentService $enrollments ) {
		$this->progress    = $progress;
		$this->calculator  = $calculator;
		$this->enrollments = $enrollments;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/steps/(?P<id>\d+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => array( $this, 'can_complete' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/courses/(?P<id>\d+)/progress',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_progress' ),
				'permission_callback' => array( $this, 'can_read_progress' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
	}

	/**
	 * Permission: logged in and allowed to record progress on this step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_complete( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::COMPLETE_STEP, (int) $request['id'] ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Marks the step complete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete( WP_REST_Request $request ) {
		$result = $this->progress->complete_step( get_current_user_id(), (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$redirect = $this->progress->redirect_url( $result );
		if ( $result['course_completed'] ) {
			$redirect = add_query_arg( 'dlms_notice', 'course_completed', $redirect );
		}

		return new WP_REST_Response(
			array(
				'completed'        => true,
				'step_id'          => $result['step_id'],
				'course_id'        => $result['course_id'],
				'newly_completed'  => $result['newly_completed'],
				'course_completed' => $result['course_completed'],
				'next_step_id'     => $result['next_step_id'],
				'progress'         => $this->format_summary( $result['summary'] ),
				'redirect'         => $redirect,
			)
		);
	}

	/**
	 * Permission: logged in and enrolled in the course.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_read_progress( WP_REST_Request $request ) {
		$course_id = (int) $request['id'];
		if ( ! is_user_logged_in() || PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->denied();
		}
		if ( ! $this->enrollments->is_enrolled( get_current_user_id(), $course_id ) ) {
			return $this->denied( __( 'You are not enrolled in this course.', 'deutschlms' ) );
		}
		return true;
	}

	/**
	 * Returns the current user's progress.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_progress( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request['id'];
		$summary   = $this->calculator->summary( get_current_user_id(), $course_id );

		return new WP_REST_Response(
			array_merge( array( 'course_id' => $course_id ), $this->format_summary( $summary ) )
		);
	}

	/**
	 * Public shape of a progress summary.
	 *
	 * @param array $summary Calculator summary.
	 * @return array
	 */
	private function format_summary( array $summary ): array {
		return array(
			'completed'    => $summary['completed'],
			'total'        => $summary['total'],
			'percent'      => $summary['percent'],
			'is_complete'  => $summary['is_complete'],
			'next_step_id' => $summary['next_step_id'],
		);
	}
}
