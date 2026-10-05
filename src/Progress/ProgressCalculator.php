<?php
/**
 * Progress queries.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Progress;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Integrations\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "what has this user completed?" for steps and courses.
 *
 * Completion rules:
 *  - A step without children (a topic without quizzes, a lesson without topics
 *    or quizzes) is complete when a progress row exists (the student marked it).
 *  - A quiz is complete when the student has passed it (a progress row is
 *    written on the first passing attempt).
 *  - A lesson or topic WITH children (topics and/or quizzes) is complete when
 *    all of its children are complete; a row is also stored at that moment.
 *  - Percentages only count the course's current published steps, so progress
 *    on removed steps doesn't inflate them.
 */
final class ProgressCalculator {

	/**
	 * Structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

	/**
	 * Storage.
	 *
	 * @var ProgressRepository
	 */
	private ProgressRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param CourseStructure    $structure  Structure reader.
	 * @param ProgressRepository $repository Storage.
	 */
	public function __construct( CourseStructure $structure, ProgressRepository $repository ) {
		$this->structure  = $structure;
		$this->repository = $repository;
	}

	/**
	 * Whether a progress row exists for a step (no derivation).
	 *
	 * @param int $user_id User ID.
	 * @param int $step_id Step ID (any language).
	 * @return bool
	 */
	public function has_record( int $user_id, int $step_id ): bool {
		if ( $user_id <= 0 || $step_id <= 0 ) {
			return false;
		}
		$completed = $this->repository->completed_steps( $user_id );
		return isset( $completed[ Multilingual::canonical_id( $step_id, (string) get_post_type( $step_id ) ) ] );
	}

	/**
	 * Whether a step is complete for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $step_id Lesson, topic or quiz ID.
	 * @return bool
	 */
	public function is_step_complete( int $user_id, int $step_id ): bool {
		if ( $this->has_record( $user_id, $step_id ) ) {
			return true;
		}
		$children = $this->structure->get_children( $step_id );
		return array() !== $children && $this->all_complete( $user_id, $children );
	}

	/**
	 * Whether every given step is complete.
	 *
	 * @param int   $user_id  User ID.
	 * @param int[] $step_ids Step IDs.
	 * @return bool
	 */
	public function all_complete( int $user_id, array $step_ids ): bool {
		foreach ( $step_ids as $step_id ) {
			if ( ! $this->is_step_complete( $user_id, (int) $step_id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a step completes by itself (the student marks it complete):
	 * lessons and topics without children. Quizzes complete by passing; steps
	 * with children complete when their children do.
	 *
	 * @param int $step_id Step ID.
	 * @return bool
	 */
	public function is_manually_completable( int $step_id ): bool {
		$type = get_post_type( $step_id );
		if ( PostTypes::LESSON !== $type && PostTypes::TOPIC !== $type ) {
			return false;
		}
		return array() === $this->structure->get_children( $step_id );
	}

	/**
	 * Progress summary for a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array{total: int, completed: int, percent: int, is_complete: bool, next_step_id: int}
	 *   next_step_id is the first incomplete step in course order (0 when done).
	 */
	public function summary( int $user_id, int $course_id ): array {
		$steps     = $this->structure->get_steps( $course_id );
		$total     = count( $steps );
		$completed = 0;
		$next      = 0;

		foreach ( $steps as $step_id ) {
			if ( $this->is_step_complete( $user_id, $step_id ) ) {
				++$completed;
			} elseif ( 0 === $next ) {
				$next = $step_id;
			}
		}

		return array(
			'total'        => $total,
			'completed'    => $completed,
			'percent'      => $total > 0 ? (int) floor( $completed * 100 / $total ) : 0,
			'is_complete'  => $total > 0 && $completed === $total,
			'next_step_id' => $next,
		);
	}
}
