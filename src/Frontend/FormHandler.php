<?php
/**
 * No-JavaScript form handlers.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Progress\ProgressService;
use DeutschLMS\Quiz\QuizService;
use DeutschLMS\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the enroll, mark-complete and quiz forms through admin-post.php. They
 * work without JavaScript; with JavaScript the same forms go through the REST API.
 *
 * Every handler checks a per-object nonce and the matching capability, and
 * only redirects to URLs it builds itself.
 */
final class FormHandler {

	public const ENROLL_ACTION   = 'dlms_enroll';
	public const COMPLETE_ACTION = 'dlms_complete_step';
	public const QUIZ_ACTION     = 'dlms_submit_quiz';

	/**
	 * Enrollment service.
	 *
	 * @var EnrollmentService
	 */
	private EnrollmentService $enrollments;

	/**
	 * Progress service.
	 *
	 * @var ProgressService
	 */
	private ProgressService $progress;

	/**
	 * Quiz service.
	 *
	 * @var QuizService
	 */
	private QuizService $quizzes;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentService $enrollments Enrollment service.
	 * @param ProgressService   $progress    Progress service.
	 * @param QuizService       $quizzes     Quiz service.
	 */
	public function __construct( EnrollmentService $enrollments, ProgressService $progress, QuizService $quizzes ) {
		$this->enrollments = $enrollments;
		$this->progress    = $progress;
		$this->quizzes     = $quizzes;
	}

	/**
	 * Nonce action for submitting a quiz.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return string
	 */
	public static function quiz_nonce_action( int $quiz_id ): string {
		return self::QUIZ_ACTION . '_' . $quiz_id;
	}

	/**
	 * Nonce action for enrolling in a course.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public static function enroll_nonce_action( int $course_id ): string {
		return self::ENROLL_ACTION . '_' . $course_id;
	}

	/**
	 * Nonce action for completing a step.
	 *
	 * @param int $step_id Step ID.
	 * @return string
	 */
	public static function complete_nonce_action( int $step_id ): string {
		return self::COMPLETE_ACTION . '_' . $step_id;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ENROLL_ACTION, array( $this, 'handle_enroll' ) );
		add_action( 'admin_post_nopriv_' . self::ENROLL_ACTION, array( $this, 'handle_logged_out' ) );
		add_action( 'admin_post_' . self::COMPLETE_ACTION, array( $this, 'handle_complete' ) );
		add_action( 'admin_post_nopriv_' . self::COMPLETE_ACTION, array( $this, 'handle_logged_out' ) );
		add_action( 'admin_post_' . self::QUIZ_ACTION, array( $this, 'handle_quiz' ) );
		add_action( 'admin_post_nopriv_' . self::QUIZ_ACTION, array( $this, 'handle_logged_out' ) );
	}

	/**
	 * Quiz form: grades the submission and shows the result on the quiz page.
	 */
	public function handle_quiz(): void {
		$quiz_id = isset( $_POST['quiz_id'] ) ? absint( $_POST['quiz_id'] ) : 0;
		check_admin_referer( self::quiz_nonce_action( $quiz_id ), 'dlms_nonce' );

		$quiz_url = get_permalink( $quiz_id );
		if ( ! $quiz_url || PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			wp_die( esc_html__( 'Invalid quiz.', 'deutschlms' ), '', array( 'response' => 404 ) );
		}
		if ( ! current_user_can( Capabilities::COMPLETE_STEP, $quiz_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}

		// Values (answer IDs or typed gap answers) are capped in
		// QuizService::clean_answers() and checked against the quiz by the
		// grader; anything unknown is ignored.
		$raw    = isset( $_POST['dlms_answers'] ) && is_array( $_POST['dlms_answers'] ) ? map_deep( wp_unslash( $_POST['dlms_answers'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with map_deep().
		$result = $this->quizzes->submit( get_current_user_id(), $quiz_id, (array) $raw );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'dlms_notice', 'quiz_failed', $quiz_url ) );
			exit;
		}

		$url = $result['result_url'];
		if ( $result['course_completed'] ) {
			$url = add_query_arg( 'dlms_notice', 'course_completed', $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Enroll form.
	 */
	public function handle_enroll(): void {
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		check_admin_referer( self::enroll_nonce_action( $course_id ), 'dlms_nonce' );

		$course_url = get_permalink( $course_id );
		if ( ! $course_url ) {
			wp_die( esc_html__( 'Invalid course.', 'deutschlms' ), '', array( 'response' => 404 ) );
		}

		if ( ! current_user_can( Capabilities::ENROLL_COURSE, $course_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to enroll in this course.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}

		$result = $this->enrollments->enroll( get_current_user_id(), $course_id, 'free' );
		$notice = is_wp_error( $result ) ? 'enroll_failed' : 'enrolled';

		wp_safe_redirect( add_query_arg( 'dlms_notice', $notice, $course_url ) );
		exit;
	}

	/**
	 * Mark-complete form.
	 */
	public function handle_complete(): void {
		$step_id = isset( $_POST['step_id'] ) ? absint( $_POST['step_id'] ) : 0;
		check_admin_referer( self::complete_nonce_action( $step_id ), 'dlms_nonce' );

		$step_url = get_permalink( $step_id );
		if ( ! $step_url ) {
			wp_die( esc_html__( 'Invalid lesson or topic.', 'deutschlms' ), '', array( 'response' => 404 ) );
		}

		if ( ! current_user_can( Capabilities::COMPLETE_STEP, $step_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}

		$result = $this->progress->complete_step( get_current_user_id(), $step_id );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'dlms_notice', 'complete_failed', $step_url ) );
			exit;
		}

		$url = $this->progress->redirect_url( $result );
		if ( $result['course_completed'] ) {
			$url = add_query_arg( 'dlms_notice', 'course_completed', $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Visitors are sent to the login screen and back.
	 */
	public function handle_logged_out(): void {
		$redirect = wp_get_referer();
		wp_safe_redirect( wp_login_url( $redirect ? $redirect : home_url( '/' ) ) );
		exit;
	}
}
