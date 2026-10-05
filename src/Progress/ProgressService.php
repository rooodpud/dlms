<?php
/**
 * Progress writes.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Progress;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Integrations\Multilingual;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Marks steps complete and rolls completion up to topics, lessons and courses.
 *
 * Callers check the `dlms_complete_step` capability first; this class enforces
 * enrollment, access (incl. linear progression and drip) and completion rules.
 */
final class ProgressService {

	/**
	 * Structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

	/**
	 * Enrollment service.
	 *
	 * @var EnrollmentService
	 */
	private EnrollmentService $enrollments;

	/**
	 * Storage.
	 *
	 * @var ProgressRepository
	 */
	private ProgressRepository $repository;

	/**
	 * Progress calculator.
	 *
	 * @var ProgressCalculator
	 */
	private ProgressCalculator $calculator;

	/**
	 * Access control.
	 *
	 * @var AccessControl
	 */
	private AccessControl $access;

	/**
	 * Constructor.
	 *
	 * @param CourseStructure    $structure   Structure reader.
	 * @param EnrollmentService  $enrollments Enrollment service.
	 * @param ProgressRepository $repository  Storage.
	 * @param ProgressCalculator $calculator  Progress calculator.
	 * @param AccessControl      $access      Access control.
	 */
	public function __construct(
		CourseStructure $structure,
		EnrollmentService $enrollments,
		ProgressRepository $repository,
		ProgressCalculator $calculator,
		AccessControl $access
	) {
		$this->structure   = $structure;
		$this->enrollments = $enrollments;
		$this->repository  = $repository;
		$this->calculator  = $calculator;
		$this->access      = $access;
	}

	/**
	 * Whether a step can be marked complete by hand (see ProgressCalculator).
	 *
	 * @param int $step_id Step ID.
	 * @return bool
	 */
	public function is_manually_completable( int $step_id ): bool {
		return $this->calculator->is_manually_completable( $step_id );
	}

	/**
	 * Checks that a user may record progress on a step: enrolled, and the step
	 * is open to them (published, in the course, linear/drip rules met).
	 *
	 * @param int $user_id User ID.
	 * @param int $step_id Step ID.
	 * @return int|WP_Error Course ID.
	 */
	public function check_can_progress( int $user_id, int $step_id ) {
		if ( ! PostTypes::is_step( $step_id ) ) {
			return new WP_Error( 'dlms_invalid_step', __( 'Invalid lesson, topic or quiz.', 'deutschlms' ), array( 'status' => 404 ) );
		}

		$course_id = $this->structure->get_course_id( $step_id );
		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return new WP_Error( 'dlms_not_enrolled', __( 'Enroll in this course to track your progress.', 'deutschlms' ), array( 'status' => 403 ) );
		}

		// Course managers bypass access checks to preview drafts, but progress can
		// only be recorded on published steps that are part of the course.
		$access = $this->access->check( $user_id, $step_id );
		if ( ! $access->allowed || ( AccessControl::BYPASS === $access->reason && ! $this->structure->contains( $course_id, $step_id ) ) ) {
			$message = in_array( $access->reason, array( AccessControl::LOCKED, AccessControl::DRIP ), true )
				? __( 'This step is not unlocked yet.', 'deutschlms' )
				: __( 'You cannot complete this step.', 'deutschlms' );
			return new WP_Error( 'dlms_step_locked', $message, array( 'status' => 403 ) );
		}

		return $course_id;
	}

	/**
	 * Marks a lesson or topic without children complete for a user.
	 *
	 * Idempotent: completing a step twice is not an error.
	 *
	 * @param int $user_id User ID.
	 * @param int $step_id Lesson or topic ID.
	 * @return array|WP_Error {
	 *     step_id: int, course_id: int, newly_completed: bool, course_completed: bool,
	 *     next_step_id: int, summary: array
	 * }
	 */
	public function complete_step( int $user_id, int $step_id ) {
		$course_id = $this->check_can_progress( $user_id, $step_id );
		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		if ( PostTypes::QUIZ === get_post_type( $step_id ) ) {
			return new WP_Error( 'dlms_quiz_requires_pass', __( 'A quiz is completed by passing it.', 'deutschlms' ), array( 'status' => 409 ) );
		}
		if ( ! $this->is_manually_completable( $step_id ) ) {
			$message = PostTypes::LESSON === get_post_type( $step_id )
				? __( 'This lesson is completed automatically once all of its topics and quizzes are complete.', 'deutschlms' )
				: __( 'This topic is completed automatically once you pass its quiz.', 'deutschlms' );
			return new WP_Error( 'dlms_step_auto_completes', $message, array( 'status' => 409 ) );
		}

		return $this->record_and_roll_up( $user_id, $course_id, $step_id );
	}

	/**
	 * Records a passed quiz. Called by the quiz service after a passing attempt;
	 * the caller has already checked access via check_can_progress().
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array Same shape as complete_step().
	 */
	public function record_quiz_passed( int $user_id, int $quiz_id ): array {
		return $this->record_and_roll_up( $user_id, $this->structure->get_course_id( $quiz_id ), $quiz_id );
	}

	/**
	 * Where the student should go after completing a step: the next step in
	 * course order, or the course page at the end.
	 *
	 * @param array $result Result of complete_step() / record_quiz_passed().
	 * @return string
	 */
	public function redirect_url( array $result ): string {
		$target = $result['next_step_id'] ? $result['next_step_id'] : $result['course_id'];
		$url    = get_permalink( $target );
		return $url ? $url : home_url( '/' );
	}

	/**
	 * Stores the step, completes any ancestors whose children are now all done,
	 * and completes the course when every step is done.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @param int $step_id   Step ID.
	 * @return array
	 */
	private function record_and_roll_up( int $user_id, int $course_id, int $step_id ): array {
		$created = $this->record( $user_id, $course_id, $step_id );

		foreach ( $this->structure->get_ancestors( $step_id ) as $ancestor ) {
			if ( $this->calculator->has_record( $user_id, $ancestor ) ) {
				continue;
			}
			if ( ! $this->calculator->is_step_complete( $user_id, $ancestor ) ) {
				break;
			}
			$this->record( $user_id, $course_id, $ancestor );
		}

		$summary          = $this->calculator->summary( $user_id, $course_id );
		$course_completed = false;
		if ( $summary['is_complete'] ) {
			$course_completed = $this->enrollments->mark_completed( $user_id, $course_id );
		}

		return array(
			'step_id'          => $step_id,
			'course_id'        => $course_id,
			'newly_completed'  => $created,
			'course_completed' => $course_completed,
			'next_step_id'     => $this->structure->get_next_step( $course_id, $step_id ),
			'summary'          => $summary,
		);
	}

	/**
	 * Stores a progress row and fires the completion action once.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @param int $step_id   Step ID.
	 * @return bool True if newly recorded.
	 */
	private function record( int $user_id, int $course_id, int $step_id ): bool {
		$step_type = (string) get_post_type( $step_id );
		$created   = $this->repository->record(
			$user_id,
			Multilingual::canonical_id( $course_id, PostTypes::COURSE ),
			Multilingual::canonical_id( $step_id, $step_type ),
			$step_type
		);

		if ( $created ) {
			/**
			 * Fires when a user completes a lesson, topic or quiz for the first time.
			 *
			 * @param int    $user_id   User ID.
			 * @param int    $step_id   Step ID.
			 * @param int    $course_id Course ID.
			 * @param string $step_type Post type.
			 */
			do_action( 'dlms_step_completed', $user_id, $step_id, $course_id, $step_type );
		}

		return $created;
	}
}
