<?php
/**
 * Enrollment endpoints.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Progress\ProgressCalculator;
use DeutschLMS\Roles\Capabilities;
use DeutschLMS\Roles\Roles;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /dlms/v1/courses/{id}/enroll  Enroll the current user (free courses).
 * GET  /dlms/v1/me/enrollments       The current user's enrollments with progress.
 */
final class EnrollmentController extends RestController {

	/**
	 * Enrollment service.
	 *
	 * @var EnrollmentService
	 */
	private EnrollmentService $enrollments;

	/**
	 * Progress calculator.
	 *
	 * @var ProgressCalculator
	 */
	private ProgressCalculator $calculator;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentService  $enrollments Enrollment service.
	 * @param ProgressCalculator $calculator  Progress calculator.
	 */
	public function __construct( EnrollmentService $enrollments, ProgressCalculator $calculator ) {
		$this->enrollments = $enrollments;
		$this->calculator  = $calculator;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/courses/(?P<id>\d+)/enroll',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enroll' ),
				'permission_callback' => array( $this, 'can_enroll' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/me/enrollments',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_mine' ),
				'permission_callback' => array( $this, 'can_list_mine' ),
			)
		);
	}

	/**
	 * Permission: logged in and allowed to self-enroll in this course.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_enroll( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return $this->denied( __( 'Please log in to enroll.', 'deutschlms' ) );
		}
		if ( ! current_user_can( Capabilities::ENROLL_COURSE, (int) $request['id'] ) ) {
			return $this->denied( __( 'Sorry, you are not allowed to enroll in this course.', 'deutschlms' ) );
		}
		return true;
	}

	/**
	 * Enrolls the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function enroll( WP_REST_Request $request ) {
		$course_id = (int) $request['id'];
		$result    = $this->enrollments->enroll( get_current_user_id(), $course_id, 'free' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$enrollment = $result['enrollment'];

		return new WP_REST_Response(
			array(
				'enrolled'    => true,
				'created'     => $result['created'],
				'course_id'   => $course_id,
				'status'      => $enrollment['status'],
				'enrolled_at' => mysql_to_rfc3339( $enrollment['enrolled_at'] ),
				'redirect'    => add_query_arg( 'dlms_notice', 'enrolled', get_permalink( $course_id ) ),
			),
			$result['created'] ? 201 : 200
		);
	}

	/**
	 * Permission: logged-in learner.
	 *
	 * @return true|WP_Error
	 */
	public function can_list_mine() {
		if ( ! is_user_logged_in() || ! current_user_can( Roles::CAP_TAKE_COURSES ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Lists the current user's enrollments.
	 *
	 * @return WP_REST_Response
	 */
	public function list_mine(): WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array();

		foreach ( $this->enrollments->for_user( $user_id ) as $course_id => $enrollment ) {
			$course = get_post( $course_id );
			if ( ! $course instanceof WP_Post || 'publish' !== $course->post_status ) {
				continue;
			}
			$summary = $this->calculator->summary( $user_id, $course_id );
			$items[] = array(
				'course_id'    => $course_id,
				'title'        => get_the_title( $course ),
				'link'         => get_permalink( $course ),
				'status'       => $enrollment['status'],
				'enrolled_at'  => mysql_to_rfc3339( $enrollment['enrolled_at'] ),
				'completed_at' => $enrollment['completed_at'] ? mysql_to_rfc3339( $enrollment['completed_at'] ) : null,
				'progress'     => array(
					'completed' => $summary['completed'],
					'total'     => $summary['total'],
					'percent'   => $summary['percent'],
				),
			);
		}

		return new WP_REST_Response( $items );
	}
}
