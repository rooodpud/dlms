<?php
/**
 * Course structure writer.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Content;

use DeutschLMS\Quiz\QuizService;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the course tree: ordering, assignment, creation.
 *
 * Every method takes the acting user and checks `edit_post` on each object it
 * touches, so callers can't skip authorization by accident.
 */
final class StructureEditor {

	/**
	 * Structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

	/**
	 * Constructor.
	 *
	 * @param CourseStructure $structure Structure reader.
	 */
	public function __construct( CourseStructure $structure ) {
		$this->structure = $structure;
	}

	/**
	 * Saves the order of a course's lessons, topics and quizzes. Topics may move
	 * between lessons, and quizzes between the course, its lessons and topics.
	 *
	 * Payload:
	 *     $lessons = [ [ 'id' => int, 'topics' => [ int | [ 'id' => int, 'quizzes' => int[] ] ], 'quizzes' => int[] ], ... ]
	 *     $course_quizzes = int[] | null (null keeps the current course-level quizzes)
	 *     $headings = [ [ 'id' => string, 'title' => string, 'before' => lesson ID or 0 ], ... ] | null
	 *                 (the course's complete section heading list; null keeps the current one)
	 *
	 * Only items already in this course are accepted. Items the payload omits
	 * keep their relative order after the listed ones.
	 *
	 * @param int        $user_id        Acting user.
	 * @param int        $course_id      Course ID.
	 * @param array      $lessons        Lesson payload.
	 * @param array|null $course_quizzes Course-level quiz IDs.
	 * @param array|null $headings       Section headings.
	 * @return true|WP_Error
	 */
	public function save_order( int $user_id, int $course_id, array $lessons, ?array $course_quizzes = null, ?array $headings = null ) {
		if ( ! $this->is_course( $course_id ) ) {
			return new WP_Error( 'dlms_invalid_course', __( 'Invalid course.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $course_id ) ) {
			return $this->forbidden();
		}

		$tree  = $this->structure->get_tree( $course_id, false );
		$valid = array(
			PostTypes::LESSON => array_column( $tree['lessons'], 'id' ),
			PostTypes::TOPIC  => array(),
			PostTypes::QUIZ   => array(),
		);
		foreach ( $tree['types'] as $id => $type ) {
			if ( PostTypes::LESSON !== $type ) {
				$valid[ $type ][] = $id;
			}
		}

		// post ID => [ 'menu_order' => int, 'meta' => [ key => value ] ].
		$changes = array();
		$seen    = array();

		$claim = function ( int $id, string $type ) use ( &$seen, $valid ): bool {
			if ( ! in_array( $id, $valid[ $type ], true ) || isset( $seen[ $id ] ) ) {
				return false;
			}
			$seen[ $id ] = true;
			return true;
		};

		$lesson_position = 0;
		foreach ( $lessons as $lesson ) {
			$lesson_id = isset( $lesson['id'] ) ? absint( $lesson['id'] ) : 0;
			if ( ! $claim( $lesson_id, PostTypes::LESSON ) ) {
				return $this->invalid( 'lesson' );
			}
			$changes[ $lesson_id ] = array( 'menu_order' => ++$lesson_position );

			$topic_position = 0;
			foreach ( (array) ( $lesson['topics'] ?? array() ) as $topic ) {
				$topic_id = absint( is_array( $topic ) ? ( $topic['id'] ?? 0 ) : $topic );
				if ( ! $claim( $topic_id, PostTypes::TOPIC ) ) {
					return $this->invalid( 'topic' );
				}
				$changes[ $topic_id ] = array(
					'menu_order' => ++$topic_position,
					'meta'       => array( Meta::LESSON_ID => $lesson_id ),
				);
				if ( is_array( $topic ) && isset( $topic['quizzes'] ) ) {
					$result = $this->place_quizzes( (array) $topic['quizzes'], $topic_id, $claim, $changes );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
			}

			if ( isset( $lesson['quizzes'] ) ) {
				$result = $this->place_quizzes( (array) $lesson['quizzes'], $lesson_id, $claim, $changes );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}//end foreach

		if ( null !== $course_quizzes ) {
			$result = $this->place_quizzes( $course_quizzes, 0, $claim, $changes );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( null !== $headings ) {
			$headings = CourseStructure::sanitize_headings( $headings );
			foreach ( $headings as $heading ) {
				if ( 0 !== $heading['before'] && ! in_array( $heading['before'], $valid[ PostTypes::LESSON ], true ) ) {
					return $this->invalid( 'heading' );
				}
			}
		}

		// Anything left out keeps its parent and goes after the listed items.
		$fallback = 1000;
		foreach ( $tree['steps'] as $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$changes[ $id ] = array( 'menu_order' => ++$fallback );
			}
		}

		// Authorize everything before writing anything.
		foreach ( array_keys( $changes ) as $post_id ) {
			if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
				return $this->forbidden();
			}
		}

		foreach ( $changes as $post_id => $change ) {
			$this->set_menu_order( $post_id, $change['menu_order'] );
			foreach ( $change['meta'] ?? array() as $key => $value ) {
				if ( (int) get_post_meta( $post_id, $key, true ) !== $value ) {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}

		if ( null !== $headings ) {
			update_post_meta( $course_id, Meta::SECTION_HEADINGS, $headings );
		}

		$this->structure->flush();

		/**
		 * Fires after a course's structure was reordered.
		 *
		 * @param int $course_id Course ID.
		 * @param int $user_id   Acting user.
		 */
		do_action( 'dlms_course_structure_saved', $course_id, $user_id );

		return true;
	}

	/**
	 * Creates a draft lesson at the end of a course.
	 *
	 * @param int    $user_id   Acting user.
	 * @param int    $course_id Course ID.
	 * @param string $title     Lesson title.
	 * @return int|WP_Error New lesson ID.
	 */
	public function create_lesson( int $user_id, int $course_id, string $title ) {
		if ( ! $this->is_course( $course_id ) ) {
			return new WP_Error( 'dlms_invalid_course', __( 'Invalid course.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $course_id ) || ! $this->can_create( $user_id, PostTypes::LESSON ) ) {
			return $this->forbidden();
		}

		return $this->insert_step(
			$user_id,
			PostTypes::LESSON,
			$title,
			$this->structure->max_menu_order( $this->structure->get_lessons( $course_id, false ) ) + 1,
			array( Meta::COURSE_ID => $course_id )
		);
	}

	/**
	 * Creates a draft topic at the end of a lesson.
	 *
	 * @param int    $user_id   Acting user.
	 * @param int    $lesson_id Lesson ID.
	 * @param string $title     Topic title.
	 * @return int|WP_Error New topic ID.
	 */
	public function create_topic( int $user_id, int $lesson_id, string $title ) {
		$course_id = $this->structure->get_course_id( $lesson_id );
		if ( PostTypes::LESSON !== get_post_type( $lesson_id ) || ! $this->is_course( $course_id ) ) {
			return new WP_Error( 'dlms_invalid_lesson', __( 'Invalid lesson.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $course_id ) || ! user_can( $user_id, 'edit_post', $lesson_id ) || ! $this->can_create( $user_id, PostTypes::TOPIC ) ) {
			return $this->forbidden();
		}

		return $this->insert_step(
			$user_id,
			PostTypes::TOPIC,
			$title,
			$this->structure->max_menu_order( $this->structure->get_topics( $lesson_id, false ) ) + 1,
			array(
				Meta::COURSE_ID => $course_id,
				Meta::LESSON_ID => $lesson_id,
			)
		);
	}

	/**
	 * Creates a draft quiz at the end of a course, lesson or topic.
	 *
	 * @param int    $user_id   Acting user.
	 * @param int    $course_id Course ID.
	 * @param int    $parent_id Lesson or topic ID, or 0 for a course-level quiz.
	 * @param string $title     Quiz title.
	 * @return int|WP_Error New quiz ID.
	 */
	public function create_quiz( int $user_id, int $course_id, int $parent_id, string $title ) {
		if ( ! $this->is_course( $course_id ) ) {
			return new WP_Error( 'dlms_invalid_course', __( 'Invalid course.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		$check = $this->check_quiz_parent( $user_id, $course_id, $parent_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! user_can( $user_id, 'edit_post', $course_id ) || ! $this->can_create( $user_id, PostTypes::QUIZ ) ) {
			return $this->forbidden();
		}

		$siblings = $parent_id ? $this->structure->get_quizzes( $parent_id, false ) : $this->structure->get_course_quizzes( $course_id, false );

		return $this->insert_step(
			$user_id,
			PostTypes::QUIZ,
			$title,
			$this->structure->max_menu_order( $siblings ) + 1,
			array(
				Meta::COURSE_ID  => $course_id,
				Meta::PARENT_ID  => $parent_id,
				Meta::TIME_LIMIT => QuizService::DEFAULT_TIME_LIMIT,
				Meta::QUIZ_ITEMS => array(),
			)
		);
	}

	/**
	 * Moves a lesson to a course (or detaches it with $course_id = 0). Its topics
	 * and quizzes follow it. A lesson moved into a course goes to the end.
	 *
	 * @param int $user_id   Acting user.
	 * @param int $lesson_id Lesson ID.
	 * @param int $course_id Target course ID, or 0.
	 * @return true|WP_Error
	 */
	public function assign_lesson( int $user_id, int $lesson_id, int $course_id ) {
		if ( PostTypes::LESSON !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'dlms_invalid_lesson', __( 'Invalid lesson.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $lesson_id ) ) {
			return $this->forbidden();
		}
		if ( $course_id && ! $this->is_course( $course_id ) ) {
			return new WP_Error( 'dlms_invalid_course', __( 'Invalid course.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( $course_id && ! user_can( $user_id, 'edit_post', $course_id ) ) {
			return $this->forbidden();
		}

		$current = $this->structure->get_course_id( $lesson_id );
		if ( $current === $course_id ) {
			return true;
		}

		$descendants = $current ? $this->descendants( $current, $lesson_id ) : array();

		if ( $course_id ) {
			$order = $this->structure->max_menu_order( $this->structure->get_lessons( $course_id, false ) ) + 1;
			update_post_meta( $lesson_id, Meta::COURSE_ID, $course_id );
			$this->set_menu_order( $lesson_id, $order );
		} else {
			delete_post_meta( $lesson_id, Meta::COURSE_ID );
		}
		$this->set_course( $descendants, $course_id );

		$this->structure->flush();
		return true;
	}

	/**
	 * Moves a topic to a lesson (or detaches it with $lesson_id = 0). Its quizzes
	 * follow it. A topic moved to another lesson goes to the end.
	 *
	 * @param int $user_id   Acting user.
	 * @param int $topic_id  Topic ID.
	 * @param int $lesson_id Target lesson ID, or 0.
	 * @return true|WP_Error
	 */
	public function assign_topic( int $user_id, int $topic_id, int $lesson_id ) {
		if ( PostTypes::TOPIC !== get_post_type( $topic_id ) ) {
			return new WP_Error( 'dlms_invalid_topic', __( 'Invalid topic.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $topic_id ) ) {
			return $this->forbidden();
		}

		$old_course = $this->structure->get_course_id( $topic_id );
		$quizzes    = $old_course ? $this->descendants( $old_course, $topic_id ) : array();

		if ( ! $lesson_id ) {
			delete_post_meta( $topic_id, Meta::LESSON_ID );
			delete_post_meta( $topic_id, Meta::COURSE_ID );
			$this->set_course( $quizzes, 0 );
			$this->structure->flush();
			return true;
		}

		$course_id = $this->structure->get_course_id( $lesson_id );
		if ( PostTypes::LESSON !== get_post_type( $lesson_id ) || ! $this->is_course( $course_id ) ) {
			return new WP_Error( 'dlms_invalid_lesson', __( 'The selected lesson is not part of a course.', 'deutschlms' ), array( 'status' => 400 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $lesson_id ) || ! user_can( $user_id, 'edit_post', $course_id ) ) {
			return $this->forbidden();
		}

		if ( $this->structure->get_lesson_id( $topic_id ) === $lesson_id && $old_course === $course_id ) {
			return true;
		}

		$order = $this->structure->max_menu_order( $this->structure->get_topics( $lesson_id, false ) ) + 1;
		update_post_meta( $topic_id, Meta::LESSON_ID, $lesson_id );
		update_post_meta( $topic_id, Meta::COURSE_ID, $course_id );
		$this->set_menu_order( $topic_id, $order );
		$this->set_course( $quizzes, $course_id );

		$this->structure->flush();
		return true;
	}

	/**
	 * Attaches a quiz to a course, lesson or topic, or detaches it
	 * ($course_id = 0). A quiz moved to a new place goes to the end.
	 *
	 * @param int $user_id   Acting user.
	 * @param int $quiz_id   Quiz ID.
	 * @param int $course_id Course ID, or 0 to detach.
	 * @param int $parent_id Lesson or topic ID in that course, or 0 for course level.
	 * @return true|WP_Error
	 */
	public function assign_quiz( int $user_id, int $quiz_id, int $course_id, int $parent_id ) {
		if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
			return new WP_Error( 'dlms_invalid_quiz', __( 'Invalid quiz.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $quiz_id ) ) {
			return $this->forbidden();
		}

		if ( ! $course_id ) {
			delete_post_meta( $quiz_id, Meta::COURSE_ID );
			delete_post_meta( $quiz_id, Meta::PARENT_ID );
			$this->structure->flush();
			return true;
		}

		if ( ! $this->is_course( $course_id ) ) {
			return new WP_Error( 'dlms_invalid_course', __( 'Invalid course.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $course_id ) ) {
			return $this->forbidden();
		}
		$check = $this->check_quiz_parent( $user_id, $course_id, $parent_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( $this->structure->get_course_id( $quiz_id ) === $course_id && $this->structure->get_quiz_parent_id( $quiz_id ) === $parent_id ) {
			return true;
		}

		$siblings = $parent_id ? $this->structure->get_quizzes( $parent_id, false ) : $this->structure->get_course_quizzes( $course_id, false );
		update_post_meta( $quiz_id, Meta::COURSE_ID, $course_id );
		update_post_meta( $quiz_id, Meta::PARENT_ID, $parent_id );
		$this->set_menu_order( $quiz_id, $this->structure->max_menu_order( $siblings ) + 1 );

		$this->structure->flush();
		return true;
	}

	/**
	 * Validates and records quiz placements for save_order().
	 *
	 * @param array    $quiz_ids  Quiz IDs in order.
	 * @param int      $parent_id Lesson/topic ID, or 0 for course level.
	 * @param callable $claim     Uniqueness/membership check.
	 * @param array    $changes   Change set (by reference).
	 * @return true|WP_Error
	 */
	private function place_quizzes( array $quiz_ids, int $parent_id, callable $claim, array &$changes ) {
		$position = 0;
		foreach ( $quiz_ids as $quiz_id ) {
			$quiz_id = absint( $quiz_id );
			if ( ! $claim( $quiz_id, PostTypes::QUIZ ) ) {
				return $this->invalid( 'quiz' );
			}
			$changes[ $quiz_id ] = array(
				'menu_order' => ++$position,
				'meta'       => array( Meta::PARENT_ID => $parent_id ),
			);
		}
		return true;
	}

	/**
	 * Checks that a quiz parent is the course itself (0) or one of its lessons
	 * or topics, and that the user may edit it.
	 *
	 * @param int $user_id   Acting user.
	 * @param int $course_id Course ID.
	 * @param int $parent_id Parent ID.
	 * @return true|WP_Error
	 */
	private function check_quiz_parent( int $user_id, int $course_id, int $parent_id ) {
		if ( 0 === $parent_id ) {
			return true;
		}
		$type = get_post_type( $parent_id );
		if ( ! in_array( $type, array( PostTypes::LESSON, PostTypes::TOPIC ), true ) || $this->structure->get_course_id( $parent_id ) !== $course_id ) {
			return new WP_Error( 'dlms_invalid_parent', __( 'A quiz can only be attached to this course or one of its lessons or topics.', 'deutschlms' ), array( 'status' => 400 ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $parent_id ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * All descendants of a lesson or topic in a course's editor tree.
	 *
	 * @param int $course_id Course ID.
	 * @param int $step_id   Lesson or topic ID.
	 * @return int[]
	 */
	private function descendants( int $course_id, int $step_id ): array {
		$children = $this->structure->get_tree( $course_id, false )['children'];
		$result   = array();
		$queue    = $children[ $step_id ] ?? array();
		while ( $queue ) {
			$id       = array_shift( $queue );
			$result[] = $id;
			$queue    = array_merge( $queue, $children[ $id ] ?? array() );
		}
		return $result;
	}

	/**
	 * Points steps at a new course (or detaches them with 0).
	 *
	 * @param int[] $step_ids  Step IDs.
	 * @param int   $course_id Course ID.
	 */
	private function set_course( array $step_ids, int $course_id ): void {
		foreach ( $step_ids as $step_id ) {
			if ( $course_id ) {
				update_post_meta( $step_id, Meta::COURSE_ID, $course_id );
			} else {
				delete_post_meta( $step_id, Meta::COURSE_ID );
			}
		}
	}

	/**
	 * Inserts a draft step.
	 *
	 * @param int    $user_id    Author.
	 * @param string $post_type  Post type.
	 * @param string $title      Title.
	 * @param int    $menu_order Position.
	 * @param array  $meta       Meta to set.
	 * @return int|WP_Error
	 */
	private function insert_step( int $user_id, string $post_type, string $title, int $menu_order, array $meta ) {
		$title = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) {
			return new WP_Error( 'dlms_empty_title', __( 'Please enter a title.', 'deutschlms' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_title'  => $title,
				'post_status' => 'draft',
				'post_author' => $user_id,
				'menu_order'  => $menu_order,
				'meta_input'  => $meta,
			),
			true
		);

		$this->structure->flush();
		return $post_id;
	}

	/**
	 * Updates menu_order without re-saving the whole post.
	 *
	 * Going through wp_update_post() would re-run content filters (kses) over
	 * posts the acting user didn't write, and create revisions, just to change
	 * their position.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $menu_order New position.
	 */
	private function set_menu_order( int $post_id, int $menu_order ): void {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || (int) $post->menu_order === $menu_order ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache is cleaned right below.
		$wpdb->update( $wpdb->posts, array( 'menu_order' => $menu_order ), array( 'ID' => $post_id ), array( '%d' ), array( '%d' ) );
		clean_post_cache( $post_id );
	}

	/**
	 * Whether an ID is a course (any status but trash).
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	private function is_course( int $course_id ): bool {
		$course = $course_id ? get_post( $course_id ) : null;
		return $course instanceof WP_Post && PostTypes::COURSE === $course->post_type && 'trash' !== $course->post_status;
	}

	/**
	 * Whether a user may create posts of a type.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function can_create( int $user_id, string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		return $object && user_can( $user_id, $object->cap->create_posts );
	}

	/**
	 * Error for an item that doesn't belong to the course (or appears twice).
	 *
	 * @param string $what lesson|topic|quiz|heading.
	 * @return WP_Error
	 */
	private function invalid( string $what ): WP_Error {
		$messages = array(
			'lesson'  => __( 'The structure contains a lesson that does not belong to this course.', 'deutschlms' ),
			'topic'   => __( 'The structure contains a topic that does not belong to this course.', 'deutschlms' ),
			'quiz'    => __( 'The structure contains a quiz that does not belong to this course.', 'deutschlms' ),
			'heading' => __( 'A section heading is placed above a lesson that does not belong to this course.', 'deutschlms' ),
		);
		return new WP_Error( 'dlms_invalid_' . $what, $messages[ $what ], array( 'status' => 400 ) );
	}

	/**
	 * Standard authorization error.
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'dlms_forbidden', __( 'Sorry, you are not allowed to change this course.', 'deutschlms' ), array( 'status' => 403 ) );
	}
}
