<?php
/**
 * Lesson/topic/quiz access rules.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Access;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Progress\ProgressCalculator;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a user may open a lesson, topic or quiz.
 *
 * Rules, in order:
 *  1. The post must be a step attached to a course.
 *  2. Users who can edit the course (its instructor, LMS admins, administrators)
 *     always have access, including to drafts, so they can preview.
 *  3. Course and step must be published, and the step must be reachable in the
 *     course tree (a topic under a draft lesson is not).
 *  4. The user must be logged in and enrolled.
 *  5. Drip: a lesson (and everything inside it) opens a set number of days
 *     after the user enrolled.
 *  6. Linear courses: every step before this one in course order must be
 *     complete, except the step's own ancestors (a topic quiz doesn't wait for
 *     its topic — passing the quiz is what completes the topic).
 */
final class AccessControl {

	public const ALLOWED       = 'allowed';
	public const BYPASS        = 'bypass';
	public const NOT_A_STEP    = 'not_a_step';
	public const NO_COURSE     = 'no_course';
	public const UNAVAILABLE   = 'unavailable';
	public const NOT_LOGGED_IN = 'not_logged_in';
	public const NOT_ENROLLED  = 'not_enrolled';
	public const DRIP          = 'drip';
	public const LOCKED        = 'locked';

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
	 * Progress calculator.
	 *
	 * @var ProgressCalculator
	 */
	private ProgressCalculator $calculator;

	/**
	 * Constructor.
	 *
	 * @param CourseStructure    $structure   Structure reader.
	 * @param EnrollmentService  $enrollments Enrollment service.
	 * @param ProgressCalculator $calculator  Progress calculator.
	 */
	public function __construct( CourseStructure $structure, EnrollmentService $enrollments, ProgressCalculator $calculator ) {
		$this->structure   = $structure;
		$this->enrollments = $enrollments;
		$this->calculator  = $calculator;
	}

	/**
	 * Checks access to a lesson, topic or quiz.
	 *
	 * @param int $user_id User ID (0 for visitors).
	 * @param int $step_id Step ID.
	 * @return AccessResult
	 */
	public function check( int $user_id, int $step_id ): AccessResult {
		$result = $this->evaluate( $user_id, $step_id );

		/**
		 * Filters an access decision.
		 *
		 * @param AccessResult $result  Decision.
		 * @param int          $user_id User ID.
		 * @param int          $step_id Step ID.
		 */
		$filtered = apply_filters( 'dlms_step_access', $result, $user_id, $step_id );
		return $filtered instanceof AccessResult ? $filtered : $result;
	}

	/**
	 * Whether a user can see a step (shortcut).
	 *
	 * @param int $user_id User ID.
	 * @param int $step_id Step ID.
	 * @return bool
	 */
	public function can_view( int $user_id, int $step_id ): bool {
		return $this->check( $user_id, $step_id )->allowed;
	}

	/**
	 * Whether a user manages a course and bypasses enrollment checks.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public function can_manage_course( int $user_id, int $course_id ): bool {
		return $user_id > 0 && $course_id > 0 && user_can( $user_id, 'edit_post', $course_id );
	}

	/**
	 * Unix time a step unlocks for a user through drip (0 = no drip delay).
	 * Topics and quizzes inside a lesson follow that lesson's schedule.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @param int $step_id   Step ID.
	 * @return int
	 */
	public function drip_available_at( int $user_id, int $course_id, int $step_id ): int {
		$lesson_id = $this->structure->get_lesson_of( $step_id );
		$days      = $lesson_id ? $this->structure->get_drip_days( $lesson_id ) : 0;
		if ( $days <= 0 ) {
			return 0;
		}

		$enrollment = $this->enrollments->get( $user_id, $course_id );
		if ( null === $enrollment ) {
			return 0;
		}

		$enrolled_at = strtotime( $enrollment['enrolled_at'] . ' UTC' );
		return $enrolled_at ? $enrolled_at + $days * DAY_IN_SECONDS : 0;
	}

	/**
	 * First unfinished prerequisite of a step in a linear course (0 if none or
	 * the course isn't linear). Returns a step the student can act on: a lesson
	 * or topic to mark complete, or a quiz to pass.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @param int $step_id   Step ID.
	 * @return int
	 */
	public function first_unmet_prerequisite( int $user_id, int $course_id, int $step_id ): int {
		if ( ! $this->structure->is_linear( $course_id ) ) {
			return 0;
		}

		$tree      = $this->structure->get_tree( $course_id );
		$ancestors = $this->structure->get_ancestors( $step_id );
		$skipped   = array();

		foreach ( $tree['steps'] as $id ) {
			if ( $id === $step_id ) {
				return 0;
			}
			if ( in_array( $id, $ancestors, true ) || $this->inside( $id, $skipped, $tree['parent'] ) ) {
				continue;
			}

			$has_children = ! empty( $tree['children'][ $id ] );
			if ( $this->calculator->is_step_complete( $user_id, $id ) ) {
				if ( $has_children ) {
					$skipped[] = $id;
				}
				continue;
			}
			if ( ! $has_children ) {
				return $id;
			}
			// An unfinished lesson/topic: its first unfinished child is the blocker.
		}

		return 0;
	}

	/**
	 * Unfiltered access decision.
	 *
	 * @param int $user_id User ID.
	 * @param int $step_id Step ID.
	 * @return AccessResult
	 */
	private function evaluate( int $user_id, int $step_id ): AccessResult {
		$step = get_post( $step_id );
		if ( ! $step instanceof WP_Post || ! PostTypes::is_step( $step ) ) {
			return new AccessResult( false, self::NOT_A_STEP );
		}

		$course_id = $this->structure->get_course_id( $step_id );
		$course    = $course_id ? get_post( $course_id ) : null;
		if ( ! $course instanceof WP_Post || PostTypes::COURSE !== $course->post_type ) {
			return new AccessResult( false, self::NO_COURSE );
		}

		if ( $this->can_manage_course( $user_id, $course_id ) ) {
			return new AccessResult( true, self::BYPASS, $course_id );
		}

		if ( 'publish' !== $course->post_status || 'publish' !== $step->post_status || ! $this->structure->contains( $course_id, $step_id ) ) {
			return new AccessResult( false, self::UNAVAILABLE, $course_id );
		}

		if ( $user_id <= 0 ) {
			return new AccessResult( false, self::NOT_LOGGED_IN, $course_id );
		}

		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return new AccessResult( false, self::NOT_ENROLLED, $course_id );
		}

		$available_at = $this->drip_available_at( $user_id, $course_id, $step_id );
		if ( $available_at > time() ) {
			return new AccessResult( false, self::DRIP, $course_id, 0, $available_at );
		}

		$blocking = $this->first_unmet_prerequisite( $user_id, $course_id, $step_id );
		if ( $blocking > 0 ) {
			return new AccessResult( false, self::LOCKED, $course_id, $blocking );
		}

		return new AccessResult( true, self::ALLOWED, $course_id );
	}

	/**
	 * Whether a step sits inside one of the given (complete) containers.
	 *
	 * @param int   $id         Step ID.
	 * @param int[] $containers Container IDs.
	 * @param array $parents    Child => parent map.
	 * @return bool
	 */
	private function inside( int $id, array $containers, array $parents ): bool {
		while ( isset( $parents[ $id ] ) && $parents[ $id ] > 0 ) {
			$id = $parents[ $id ];
			if ( in_array( $id, $containers, true ) ) {
				return true;
			}
		}
		return false;
	}
}
