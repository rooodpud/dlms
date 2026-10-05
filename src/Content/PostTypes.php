<?php
/**
 * Course, lesson, topic, quiz and question post types.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Content;

use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\Questions;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the content post types and their meta.
 *
 * Hierarchy: Course → Lesson → Topic. Lessons store `_dlms_course_id`; topics
 * store `_dlms_course_id` and `_dlms_lesson_id`. Quizzes store `_dlms_course_id`
 * and `_dlms_parent_id` (a lesson, a topic, or 0 for the course itself).
 * Sequence is `menu_order` within each parent.
 *
 * Questions (the question bank) are admin-only posts that quizzes link to;
 * they are sorted with three taxonomies: categories (hierarchical),
 * difficulty and CEFR level (one term each).
 */
final class PostTypes {

	public const COURSE = 'dlms_course';
	public const LESSON = 'dlms_lesson';
	public const TOPIC  = 'dlms_topic';
	public const QUIZ   = 'dlms_quiz';

	public const QUESTION = 'dlms_question';

	public const QUESTION_CATEGORY   = 'dlms_question_category';
	public const QUESTION_DIFFICULTY = 'dlms_question_difficulty';
	public const QUESTION_LEVEL      = 'dlms_question_level';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_action( 'init', array( $this, 'register_meta' ), 6 );
	}

	/**
	 * Post types that make up a course ("steps"): lessons, topics, quizzes.
	 *
	 * @return string[]
	 */
	public static function step_types(): array {
		return array( self::LESSON, self::TOPIC, self::QUIZ );
	}

	/**
	 * Plural capability types, keyed by post type.
	 *
	 * @return array<string, string>
	 */
	public static function capability_types(): array {
		return array(
			self::COURSE   => 'dlms_courses',
			self::LESSON   => 'dlms_lessons',
			self::TOPIC    => 'dlms_topics',
			self::QUIZ     => 'dlms_quizzes',
			self::QUESTION => 'dlms_questions',
		);
	}

	/**
	 * Whether a post is a lesson, topic or quiz.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @return bool
	 */
	public static function is_step( $post ): bool {
		$post = get_post( $post );
		return $post instanceof WP_Post && in_array( $post->post_type, self::step_types(), true );
	}

	/**
	 * Registers the post types.
	 */
	public function register(): void {
		$caps = self::capability_types();

		register_post_type(
			self::COURSE,
			array(
				'labels'           => $this->course_labels(),
				'description'      => __( 'Courses made of lessons and topics.', 'deutschlms' ),
				'public'           => true,
				'show_in_rest'     => true,
				'rest_base'        => 'dlms-courses',
				'menu_icon'        => 'dashicons-welcome-learn-more',
				'menu_position'    => 25,
				'has_archive'      => (string) apply_filters( 'dlms_course_archive_slug', 'courses' ),
				'rewrite'          => array(
					'slug'       => (string) apply_filters( 'dlms_course_slug', 'courses' ),
					'with_front' => false,
				),
				'supports'         => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
				'capability_type'  => array( 'dlms_course', $caps[ self::COURSE ] ),
				'map_meta_cap'     => true,
				'delete_with_user' => false,
			)
		);

		$step_defaults = array(
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => true,
			'show_in_menu'        => 'edit.php?post_type=' . self::COURSE,
			'has_archive'         => false,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'author', 'revisions' ),
			'map_meta_cap'        => true,
			'delete_with_user'    => false,
		);

		register_post_type(
			self::LESSON,
			array_merge(
				$step_defaults,
				array(
					'labels'          => $this->lesson_labels(),
					'rest_base'       => 'dlms-lessons',
					'rewrite'         => array(
						'slug'       => (string) apply_filters( 'dlms_lesson_slug', 'lessons' ),
						'with_front' => false,
					),
					'capability_type' => array( 'dlms_lesson', $caps[ self::LESSON ] ),
				)
			)
		);

		register_post_type(
			self::TOPIC,
			array_merge(
				$step_defaults,
				array(
					'labels'          => $this->topic_labels(),
					'rest_base'       => 'dlms-topics',
					'rewrite'         => array(
						'slug'       => (string) apply_filters( 'dlms_topic_slug', 'topics' ),
						'with_front' => false,
					),
					'capability_type' => array( 'dlms_topic', $caps[ self::TOPIC ] ),
				)
			)
		);

		register_post_type(
			self::QUIZ,
			array_merge(
				$step_defaults,
				array(
					'labels'          => $this->quiz_labels(),
					'rest_base'       => 'dlms-quizzes',
					'rewrite'         => array(
						'slug'       => (string) apply_filters( 'dlms_quiz_slug', 'quizzes' ),
						'with_front' => false,
					),
					'supports'        => array( 'title', 'editor', 'author', 'revisions' ),
					'capability_type' => array( 'dlms_quiz', $caps[ self::QUIZ ] ),
				)
			)
		);

		// Admin-only: questions hold the correct answers, so they never get a
		// public URL or REST route; students only see them inside a quiz.
		register_post_type(
			self::QUESTION,
			array(
				'labels'              => $this->question_labels(),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . self::COURSE,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'author' ),
				'capability_type'     => array( 'dlms_question', $caps[ self::QUESTION ] ),
				'map_meta_cap'        => true,
				'delete_with_user'    => false,
			)
		);

		$taxonomy_defaults = array(
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_nav_menus' => false,
			'show_in_rest'      => false,
			'show_tagcloud'     => false,
			'show_admin_column' => true,
			'query_var'         => false,
			'rewrite'           => false,
			'capabilities'      => array(
				'manage_terms' => 'edit_others_' . $caps[ self::QUESTION ],
				'edit_terms'   => 'edit_others_' . $caps[ self::QUESTION ],
				'delete_terms' => 'edit_others_' . $caps[ self::QUESTION ],
				'assign_terms' => 'edit_' . $caps[ self::QUESTION ],
			),
		);
		register_taxonomy(
			self::QUESTION_CATEGORY,
			self::QUESTION,
			array_merge(
				$taxonomy_defaults,
				array(
					'hierarchical' => true,
					'labels'       => array(
						'name'          => _x( 'Question categories', 'taxonomy general name', 'deutschlms' ),
						'singular_name' => _x( 'Question category', 'taxonomy singular name', 'deutschlms' ),
						'menu_name'     => __( 'Question categories', 'deutschlms' ),
						'all_items'     => __( 'All categories', 'deutschlms' ),
						'edit_item'     => __( 'Edit category', 'deutschlms' ),
						'add_new_item'  => __( 'Add new category', 'deutschlms' ),
						'parent_item'   => __( 'Parent category', 'deutschlms' ),
						'search_items'  => __( 'Search categories', 'deutschlms' ),
						'not_found'     => __( 'No categories found.', 'deutschlms' ),
						'back_to_items' => __( '← Back to categories', 'deutschlms' ),
					),
				)
			)
		);
		// One term per question; the question screen shows a select instead of the default box.
		register_taxonomy(
			self::QUESTION_DIFFICULTY,
			self::QUESTION,
			array_merge(
				$taxonomy_defaults,
				array(
					'hierarchical' => false,
					'meta_box_cb'  => false,
					'labels'       => array(
						'name'          => _x( 'Difficulty', 'taxonomy general name', 'deutschlms' ),
						'singular_name' => _x( 'Difficulty', 'taxonomy singular name', 'deutschlms' ),
						'menu_name'     => __( 'Difficulty levels', 'deutschlms' ),
						'all_items'     => __( 'All difficulty levels', 'deutschlms' ),
						'edit_item'     => __( 'Edit difficulty level', 'deutschlms' ),
						'add_new_item'  => __( 'Add new difficulty level', 'deutschlms' ),
						'search_items'  => __( 'Search difficulty levels', 'deutschlms' ),
						'not_found'     => __( 'No difficulty levels found.', 'deutschlms' ),
						'back_to_items' => __( '← Back to difficulty levels', 'deutschlms' ),
					),
				)
			)
		);
		register_taxonomy(
			self::QUESTION_LEVEL,
			self::QUESTION,
			array_merge(
				$taxonomy_defaults,
				array(
					'hierarchical' => false,
					'meta_box_cb'  => false,
					'labels'       => array(
						'name'          => _x( 'CEFR level', 'taxonomy general name', 'deutschlms' ),
						'singular_name' => _x( 'CEFR level', 'taxonomy singular name', 'deutschlms' ),
						'menu_name'     => __( 'CEFR levels', 'deutschlms' ),
						'all_items'     => __( 'All CEFR levels', 'deutschlms' ),
						'edit_item'     => __( 'Edit CEFR level', 'deutschlms' ),
						'add_new_item'  => __( 'Add new CEFR level', 'deutschlms' ),
						'search_items'  => __( 'Search CEFR levels', 'deutschlms' ),
						'not_found'     => __( 'No CEFR levels found.', 'deutschlms' ),
						'back_to_items' => __( '← Back to CEFR levels', 'deutschlms' ),
					),
				)
			)
		);
	}

	/**
	 * The three question taxonomies, keyed by the name used in quiz items and
	 * filters.
	 *
	 * @return array<string, string>
	 */
	public static function question_taxonomies(): array {
		return array(
			'category'   => self::QUESTION_CATEGORY,
			'difficulty' => self::QUESTION_DIFFICULTY,
			'level'      => self::QUESTION_LEVEL,
		);
	}

	/**
	 * Registers post meta with types, sanitization and auth.
	 *
	 * Meta is not exposed in REST; it is edited through capability-checked
	 * meta boxes and the course builder endpoints.
	 */
	public function register_meta(): void {
		$auth = static function ( $allowed, $meta_key, $object_id ) {
			return current_user_can( 'edit_post', (int) $object_id );
		};

		register_post_meta(
			self::COURSE,
			Meta::LINEAR,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
				'show_in_rest'      => false,
			)
		);

		$id_meta = array(
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		);

		register_post_meta( self::LESSON, Meta::COURSE_ID, $id_meta );
		register_post_meta( self::LESSON, Meta::DRIP_DAYS, $id_meta );
		register_post_meta( self::TOPIC, Meta::COURSE_ID, $id_meta );
		register_post_meta( self::TOPIC, Meta::LESSON_ID, $id_meta );
		register_post_meta( self::QUIZ, Meta::COURSE_ID, $id_meta );
		register_post_meta( self::QUIZ, Meta::PARENT_ID, $id_meta );
		register_post_meta( self::QUIZ, Meta::ATTEMPTS_LIMIT, $id_meta );
		register_post_meta(
			self::QUIZ,
			Meta::TIME_LIMIT,
			array_merge(
				$id_meta,
				array( 'sanitize_callback' => static fn( $value ) => min( 600, absint( $value ) ) )
			)
		);
		register_post_meta(
			self::QUIZ,
			Meta::PASS_MARK,
			array_merge(
				$id_meta,
				array(
					'default'           => 80,
					'sanitize_callback' => static fn( $value ) => max( 0, min( 100, absint( $value ) ) ),
				)
			)
		);

		$bool_meta = array(
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		);
		register_post_meta( self::QUIZ, Meta::SHOW_ANSWERS, $bool_meta );
		register_post_meta(
			self::QUIZ,
			Meta::QUESTIONS,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( Questions::class, 'sanitize' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => false,
			)
		);

		$text_meta = array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		);
		register_post_meta(
			self::QUIZ,
			Meta::QUIZ_ITEMS,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( QuestionBank::class, 'sanitize_items' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => false,
			)
		);
		register_post_meta(
			self::QUESTION,
			Meta::QUESTION,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => static fn( $value ) => Questions::sanitize_one( $value ) ?? array(),
				'auth_callback'     => $auth,
				'show_in_rest'      => false,
			)
		);
		foreach ( array( Meta::QUESTION_KEY, Meta::QUESTION_TYPE, Meta::QUESTION_READY ) as $key ) {
			register_post_meta( self::QUESTION, $key, $text_meta );
		}

		register_post_meta( self::COURSE, Meta::CERT_ENABLED, $bool_meta );
		register_post_meta( self::COURSE, Meta::CERT_TITLE, $text_meta );
		register_post_meta( self::COURSE, Meta::CERT_SIGNER, $text_meta );
		register_post_meta( self::COURSE, Meta::CERT_BACKGROUND, $id_meta );
		register_post_meta(
			self::COURSE,
			Meta::SECTION_HEADINGS,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( CourseStructure::class, 'sanitize_headings' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Course labels.
	 *
	 * @return array<string, string>
	 */
	private function course_labels(): array {
		return array(
			'name'                  => _x( 'Courses', 'post type general name', 'deutschlms' ),
			'singular_name'         => _x( 'Course', 'post type singular name', 'deutschlms' ),
			'menu_name'             => _x( 'Courses', 'admin menu', 'deutschlms' ),
			'add_new'               => __( 'Add New Course', 'deutschlms' ),
			'add_new_item'          => __( 'Add New Course', 'deutschlms' ),
			'edit_item'             => __( 'Edit Course', 'deutschlms' ),
			'new_item'              => __( 'New Course', 'deutschlms' ),
			'view_item'             => __( 'View Course', 'deutschlms' ),
			'view_items'            => __( 'View Courses', 'deutschlms' ),
			'search_items'          => __( 'Search Courses', 'deutschlms' ),
			'not_found'             => __( 'No courses found.', 'deutschlms' ),
			'not_found_in_trash'    => __( 'No courses found in Trash.', 'deutschlms' ),
			'all_items'             => __( 'All Courses', 'deutschlms' ),
			'archives'              => __( 'Course Archives', 'deutschlms' ),
			'attributes'            => __( 'Course Attributes', 'deutschlms' ),
			'insert_into_item'      => __( 'Insert into course', 'deutschlms' ),
			'uploaded_to_this_item' => __( 'Uploaded to this course', 'deutschlms' ),
			'filter_items_list'     => __( 'Filter courses list', 'deutschlms' ),
			'items_list_navigation' => __( 'Courses list navigation', 'deutschlms' ),
			'items_list'            => __( 'Courses list', 'deutschlms' ),
			'item_published'        => __( 'Course published.', 'deutschlms' ),
			'item_updated'          => __( 'Course updated.', 'deutschlms' ),
		);
	}

	/**
	 * Lesson labels.
	 *
	 * @return array<string, string>
	 */
	private function lesson_labels(): array {
		return array(
			'name'                  => _x( 'Lessons', 'post type general name', 'deutschlms' ),
			'singular_name'         => _x( 'Lesson', 'post type singular name', 'deutschlms' ),
			'menu_name'             => _x( 'Lessons', 'admin menu', 'deutschlms' ),
			'add_new'               => __( 'Add New Lesson', 'deutschlms' ),
			'add_new_item'          => __( 'Add New Lesson', 'deutschlms' ),
			'edit_item'             => __( 'Edit Lesson', 'deutschlms' ),
			'new_item'              => __( 'New Lesson', 'deutschlms' ),
			'view_item'             => __( 'View Lesson', 'deutschlms' ),
			'view_items'            => __( 'View Lessons', 'deutschlms' ),
			'search_items'          => __( 'Search Lessons', 'deutschlms' ),
			'not_found'             => __( 'No lessons found.', 'deutschlms' ),
			'not_found_in_trash'    => __( 'No lessons found in Trash.', 'deutschlms' ),
			'all_items'             => __( 'Lessons', 'deutschlms' ),
			'insert_into_item'      => __( 'Insert into lesson', 'deutschlms' ),
			'uploaded_to_this_item' => __( 'Uploaded to this lesson', 'deutschlms' ),
			'filter_items_list'     => __( 'Filter lessons list', 'deutschlms' ),
			'items_list_navigation' => __( 'Lessons list navigation', 'deutschlms' ),
			'items_list'            => __( 'Lessons list', 'deutschlms' ),
			'item_published'        => __( 'Lesson published.', 'deutschlms' ),
			'item_updated'          => __( 'Lesson updated.', 'deutschlms' ),
		);
	}

	/**
	 * Topic labels.
	 *
	 * @return array<string, string>
	 */
	private function topic_labels(): array {
		return array(
			'name'                  => _x( 'Topics', 'post type general name', 'deutschlms' ),
			'singular_name'         => _x( 'Topic', 'post type singular name', 'deutschlms' ),
			'menu_name'             => _x( 'Topics', 'admin menu', 'deutschlms' ),
			'add_new'               => __( 'Add New Topic', 'deutschlms' ),
			'add_new_item'          => __( 'Add New Topic', 'deutschlms' ),
			'edit_item'             => __( 'Edit Topic', 'deutschlms' ),
			'new_item'              => __( 'New Topic', 'deutschlms' ),
			'view_item'             => __( 'View Topic', 'deutschlms' ),
			'view_items'            => __( 'View Topics', 'deutschlms' ),
			'search_items'          => __( 'Search Topics', 'deutschlms' ),
			'not_found'             => __( 'No topics found.', 'deutschlms' ),
			'not_found_in_trash'    => __( 'No topics found in Trash.', 'deutschlms' ),
			'all_items'             => __( 'Topics', 'deutschlms' ),
			'insert_into_item'      => __( 'Insert into topic', 'deutschlms' ),
			'uploaded_to_this_item' => __( 'Uploaded to this topic', 'deutschlms' ),
			'filter_items_list'     => __( 'Filter topics list', 'deutschlms' ),
			'items_list_navigation' => __( 'Topics list navigation', 'deutschlms' ),
			'items_list'            => __( 'Topics list', 'deutschlms' ),
			'item_published'        => __( 'Topic published.', 'deutschlms' ),
			'item_updated'          => __( 'Topic updated.', 'deutschlms' ),
		);
	}

	/**
	 * Question labels.
	 *
	 * @return array<string, string>
	 */
	private function question_labels(): array {
		return array(
			'name'                  => _x( 'Questions', 'post type general name', 'deutschlms' ),
			'singular_name'         => _x( 'Question', 'post type singular name', 'deutschlms' ),
			'menu_name'             => _x( 'Questions', 'admin menu', 'deutschlms' ),
			'add_new'               => __( 'Add New Question', 'deutschlms' ),
			'add_new_item'          => __( 'Add New Question', 'deutschlms' ),
			'edit_item'             => __( 'Edit Question', 'deutschlms' ),
			'new_item'              => __( 'New Question', 'deutschlms' ),
			'search_items'          => __( 'Search Questions', 'deutschlms' ),
			'not_found'             => __( 'No questions found.', 'deutschlms' ),
			'not_found_in_trash'    => __( 'No questions found in Trash.', 'deutschlms' ),
			'all_items'             => __( 'Questions', 'deutschlms' ),
			'filter_items_list'     => __( 'Filter questions list', 'deutschlms' ),
			'items_list_navigation' => __( 'Questions list navigation', 'deutschlms' ),
			'items_list'            => __( 'Questions list', 'deutschlms' ),
			'item_published'        => __( 'Question saved.', 'deutschlms' ),
			'item_updated'          => __( 'Question updated.', 'deutschlms' ),
		);
	}

	/**
	 * Quiz labels.
	 *
	 * @return array<string, string>
	 */
	private function quiz_labels(): array {
		return array(
			'name'                  => _x( 'Quizzes', 'post type general name', 'deutschlms' ),
			'singular_name'         => _x( 'Quiz', 'post type singular name', 'deutschlms' ),
			'menu_name'             => _x( 'Quizzes', 'admin menu', 'deutschlms' ),
			'add_new'               => __( 'Add New Quiz', 'deutschlms' ),
			'add_new_item'          => __( 'Add New Quiz', 'deutschlms' ),
			'edit_item'             => __( 'Edit Quiz', 'deutschlms' ),
			'new_item'              => __( 'New Quiz', 'deutschlms' ),
			'view_item'             => __( 'View Quiz', 'deutschlms' ),
			'view_items'            => __( 'View Quizzes', 'deutschlms' ),
			'search_items'          => __( 'Search Quizzes', 'deutschlms' ),
			'not_found'             => __( 'No quizzes found.', 'deutschlms' ),
			'not_found_in_trash'    => __( 'No quizzes found in Trash.', 'deutschlms' ),
			'all_items'             => __( 'Quizzes', 'deutschlms' ),
			'filter_items_list'     => __( 'Filter quizzes list', 'deutschlms' ),
			'items_list_navigation' => __( 'Quizzes list navigation', 'deutschlms' ),
			'items_list'            => __( 'Quizzes list', 'deutschlms' ),
			'item_published'        => __( 'Quiz published.', 'deutschlms' ),
			'item_updated'          => __( 'Quiz updated.', 'deutschlms' ),
		);
	}
}
