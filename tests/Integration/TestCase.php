<?php
/**
 * Base test case.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Plugin;
use DeutschLMS\Roles\Roles;
use WP_UnitTestCase;

/**
 * Factories for courses, lessons, topics and users.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Resets runtime caches between tests (DB changes are rolled back by core).
	 */
	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->flush_runtime_caches();
		wp_set_current_user( 0 );
	}

	/**
	 * Plugin instance.
	 *
	 * @return Plugin
	 */
	protected function lms(): Plugin {
		return Plugin::instance();
	}

	/**
	 * Creates a course.
	 *
	 * @param array $args { linear: bool, status: string, author: int }.
	 * @return int
	 */
	protected function create_course( array $args = array() ): int {
		$course_id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::COURSE,
				'post_status' => $args['status'] ?? 'publish',
				'post_author' => $args['author'] ?? 1,
				'post_title'  => $args['title'] ?? 'Course',
			)
		);
		if ( ! empty( $args['linear'] ) ) {
			update_post_meta( $course_id, Meta::LINEAR, true );
		}
		return $course_id;
	}

	/**
	 * Creates a lesson in a course.
	 *
	 * @param int   $course_id Course ID.
	 * @param int   $order     menu_order.
	 * @param array $args      { status: string, author: int }.
	 * @return int
	 */
	protected function create_lesson( int $course_id, int $order, array $args = array() ): int {
		$lesson_id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::LESSON,
				'post_status' => $args['status'] ?? 'publish',
				'post_author' => $args['author'] ?? 1,
				'post_title'  => 'Lesson ' . $order,
				'menu_order'  => $order,
			)
		);
		if ( $course_id ) {
			update_post_meta( $lesson_id, Meta::COURSE_ID, $course_id );
		}
		$this->lms()->flush_runtime_caches();
		return $lesson_id;
	}

	/**
	 * Creates a topic in a lesson.
	 *
	 * @param int   $lesson_id Lesson ID.
	 * @param int   $order     menu_order.
	 * @param array $args      { status: string, author: int }.
	 * @return int
	 */
	protected function create_topic( int $lesson_id, int $order, array $args = array() ): int {
		$topic_id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::TOPIC,
				'post_status' => $args['status'] ?? 'publish',
				'post_author' => $args['author'] ?? 1,
				'post_title'  => 'Topic ' . $order,
				'menu_order'  => $order,
			)
		);
		update_post_meta( $topic_id, Meta::LESSON_ID, $lesson_id );
		update_post_meta( $topic_id, Meta::COURSE_ID, (int) get_post_meta( $lesson_id, Meta::COURSE_ID, true ) );
		$this->lms()->flush_runtime_caches();
		return $topic_id;
	}

	/**
	 * Creates a published quiz.
	 *
	 * Default questions (2 points total):
	 *  - q_one (single): a_one is correct, a_two is not.
	 *  - q_two (single): b_one is not, b_two is correct.
	 * Correct answers: self::RIGHT; wrong answers: self::WRONG.
	 *
	 * @param int        $course_id Course ID.
	 * @param int        $parent_id Lesson/topic ID, or 0 for a final quiz.
	 * @param int        $order     menu_order.
	 * @param array      $settings  { pass_mark, attempts_limit, show_answers, status }.
	 * @param array|null $questions Questions (default: two single-choice questions).
	 * @return int
	 */
	protected function create_quiz( int $course_id, int $parent_id, int $order = 1, array $settings = array(), ?array $questions = null ): int {
		$quiz_id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::QUIZ,
				'post_status' => $settings['status'] ?? 'publish',
				'post_author' => 1,
				'post_title'  => 'Quiz ' . $order,
				'menu_order'  => $order,
			)
		);
		update_post_meta( $quiz_id, Meta::COURSE_ID, $course_id );
		update_post_meta( $quiz_id, Meta::PARENT_ID, $parent_id );
		update_post_meta( $quiz_id, Meta::QUESTIONS, $questions ?? self::default_questions() );
		update_post_meta( $quiz_id, Meta::PASS_MARK, $settings['pass_mark'] ?? 50 );
		update_post_meta( $quiz_id, Meta::ATTEMPTS_LIMIT, $settings['attempts_limit'] ?? 0 );
		update_post_meta( $quiz_id, Meta::SHOW_ANSWERS, ! empty( $settings['show_answers'] ) );
		$this->lms()->flush_runtime_caches();
		return $quiz_id;
	}

	/**
	 * Correct answers for the default questions.
	 */
	protected const RIGHT = array(
		'q_one' => array( 'a_one' ),
		'q_two' => array( 'b_two' ),
	);

	/**
	 * Wrong answers for the default questions.
	 */
	protected const WRONG = array(
		'q_one' => array( 'a_two' ),
		'q_two' => array( 'b_one' ),
	);

	/**
	 * Two single-choice questions, one point each.
	 *
	 * @return array
	 */
	protected static function default_questions(): array {
		return array(
			array(
				'id'      => 'q_one',
				'type'    => 'single',
				'text'    => 'Welcher Artikel: ___ Haus?',
				'points'  => 1,
				'answers' => array(
					array(
						'id'      => 'a_one',
						'text'    => 'das',
						'correct' => true,
					),
					array(
						'id'      => 'a_two',
						'text'    => 'der',
						'correct' => false,
					),
				),
			),
			array(
				'id'      => 'q_two',
				'type'    => 'single',
				'text'    => 'Welcher Artikel: ___ Frau?',
				'points'  => 1,
				'answers' => array(
					array(
						'id'      => 'b_one',
						'text'    => 'das',
						'correct' => false,
					),
					array(
						'id'      => 'b_two',
						'text'    => 'die',
						'correct' => true,
					),
				),
			),
		);
	}

	/**
	 * Creates a user with a role.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected function create_user( string $role = Roles::STUDENT ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}

	/**
	 * Creates a student enrolled in a course.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	protected function enrolled_student( int $course_id ): int {
		$user_id = $this->create_user();
		$result  = $this->lms()->enrollments()->enroll( $user_id, $course_id );
		$this->assertIsArray( $result );
		return $user_id;
	}

	/**
	 * Builds a course: lesson 1 (topics 1.1, 1.2), lesson 2 (no topics), lesson 3.
	 *
	 * @param bool $linear Linear progression.
	 * @return array{course: int, l1: int, t11: int, t12: int, l2: int, l3: int}
	 */
	protected function sample_course( bool $linear = false ): array {
		$course = $this->create_course( array( 'linear' => $linear ) );
		$l1     = $this->create_lesson( $course, 1 );
		$t11    = $this->create_topic( $l1, 1 );
		$t12    = $this->create_topic( $l1, 2 );
		$l2     = $this->create_lesson( $course, 2 );
		$l3     = $this->create_lesson( $course, 3 );
		return compact( 'course', 'l1', 't11', 't12', 'l2', 'l3' );
	}
}
