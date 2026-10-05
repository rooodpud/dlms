<?php
/**
 * Course structure reader.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Content;

use DeutschLMS\Integrations\Multilingual;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the ordered course tree:
 *
 *     Course
 *     ├─ Lesson
 *     │  ├─ Topic
 *     │  │  └─ Quiz (topic quiz)
 *     │  └─ Quiz (lesson quiz)
 *     └─ Quiz (course-level / final quiz)
 *
 * "Steps" are every lesson, topic and quiz, in pre-order: a lesson, then each of
 * its topics followed by that topic's quizzes, then the lesson's quizzes; the
 * course-level quizzes come after the last lesson.
 *
 * Results are cached per request and flushed whenever a post cache is cleaned.
 */
final class CourseStructure {

	/**
	 * Statuses shown to course editors in the builder.
	 */
	public const EDITOR_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Runtime cache: "{course_id}:{published_only}" => tree.
	 *
	 * @var array<string, array>
	 */
	private array $cache = array();

	/**
	 * Clears the runtime cache.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Parent course of a lesson, topic or quiz (0 if none).
	 *
	 * @param int $step_id Step ID.
	 * @return int
	 */
	public function get_course_id( int $step_id ): int {
		return absint( get_post_meta( $step_id, Meta::COURSE_ID, true ) );
	}

	/**
	 * Parent lesson of a topic as stored (0 if none).
	 *
	 * @param int $topic_id Topic ID.
	 * @return int
	 */
	public function get_lesson_id( int $topic_id ): int {
		return absint( get_post_meta( $topic_id, Meta::LESSON_ID, true ) );
	}

	/**
	 * What a quiz is attached to as stored: lesson/topic ID, or 0 for the course.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public function get_quiz_parent_id( int $quiz_id ): int {
		return absint( get_post_meta( $quiz_id, Meta::PARENT_ID, true ) );
	}

	/**
	 * Whether a course is set to linear progression.
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public function is_linear( int $course_id ): bool {
		return (bool) get_post_meta( $course_id, Meta::LINEAR, true );
	}

	/**
	 * Days after enrollment before a lesson unlocks (0 = immediately).
	 *
	 * @param int $lesson_id Lesson ID.
	 * @return int
	 */
	public function get_drip_days( int $lesson_id ): int {
		return absint( get_post_meta( $lesson_id, Meta::DRIP_DAYS, true ) );
	}

	/**
	 * Section headings of a course, grouped by the lesson they stand above.
	 *
	 * Headings are not steps: they only label groups of lessons in the outline
	 * (e.g. "Grammatik" above lesson 1, "Wortschatz" above lesson 30). Each one
	 * is anchored to the lesson after it, so it moves with that lesson. When
	 * that lesson isn't part of the requested view (a draft in the student view,
	 * or no longer in the course), the heading moves down to the next lesson
	 * that is, or to the end (key 0: after the last lesson, before the final
	 * quizzes).
	 *
	 * @param int  $course_id      Course ID.
	 * @param bool $published_only Student view (published lessons only).
	 * @return array<int, array<int, array{id: string, title: string}>> Lesson ID (or 0) => headings in order.
	 */
	public function get_headings( int $course_id, bool $published_only = true ): array {
		$stored = self::sanitize_headings( get_post_meta( $course_id, Meta::SECTION_HEADINGS, true ) );
		if ( array() === $stored ) {
			return array();
		}

		$shown  = $this->get_lessons( $course_id, $published_only );
		$all    = $this->get_lessons( $course_id, false );
		$by_key = array();
		foreach ( $all as $lesson_id ) {
			$by_key[ Multilingual::canonical_id( $lesson_id, PostTypes::LESSON ) ] = $lesson_id;
		}

		$result = array();
		foreach ( $stored as $heading ) {
			$anchor = 0;
			// Copied to a translation, the anchor is still the original lesson's ID.
			$lesson = in_array( $heading['before'], $all, true ) ? $heading['before'] : ( $by_key[ Multilingual::canonical_id( $heading['before'], PostTypes::LESSON ) ] ?? 0 );
			$start  = $lesson ? array_search( $lesson, $all, true ) : false;
			if ( false !== $start ) {
				foreach ( array_slice( $all, (int) $start ) as $candidate ) {
					if ( in_array( $candidate, $shown, true ) ) {
						$anchor = $candidate;
						break;
					}
				}
			}
			$result[ $anchor ][] = array(
				'id'    => $heading['id'],
				'title' => $heading['title'],
			);
		}
		return $result;
	}

	/**
	 * Cleans a stored or submitted heading list: trimmed plain-text titles (empty
	 * ones dropped, at most 200 characters), unique IDs, integer anchors, at
	 * most 50 headings.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array{id: string, title: string, before: int}>
	 */
	public static function sanitize_headings( $value ): array {
		$clean = array();
		$ids   = array();
		foreach ( is_array( $value ) ? $value : array() as $heading ) {
			if ( ! is_array( $heading ) ) {
				continue;
			}
			$title = trim( sanitize_text_field( (string) ( $heading['title'] ?? '' ) ) );
			if ( '' === $title ) {
				continue;
			}
			$id = substr( sanitize_key( (string) ( $heading['id'] ?? '' ) ), 0, 40 );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'h' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
			}
			$ids[ $id ] = true;
			$clean[]    = array(
				'id'     => $id,
				'title'  => mb_substr( $title, 0, 200 ),
				'before' => absint( $heading['before'] ?? 0 ),
			);
			if ( count( $clean ) >= 50 ) {
				break;
			}
		}//end foreach
		return $clean;
	}

	/**
	 * Whether a quiz has at least one question.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return bool
	 */
	public function quiz_has_questions( int $quiz_id ): bool {
		// Linked questions or random rules (question bank); otherwise the quiz's own questions.
		$key       = metadata_exists( 'post', $quiz_id, Meta::QUIZ_ITEMS ) ? Meta::QUIZ_ITEMS : Meta::QUESTIONS;
		$questions = get_post_meta( $quiz_id, $key, true );
		return is_array( $questions ) && array() !== $questions;
	}

	/**
	 * Ordered tree of a course.
	 *
	 * Shape:
	 *  - lessons:  list of [ 'id' => int, 'topics' => int[], 'quizzes' => int[] ] in order.
	 *  - quizzes:  course-level quiz IDs in order.
	 *  - steps:    every step in pre-order (see class description).
	 *  - parent:   topic/quiz ID => parent ID (0 for course-level quizzes).
	 *  - children: lesson/topic ID => ordered child IDs (topics, then quizzes).
	 *  - types:    step ID => post type.
	 *
	 * Only reachable steps are included: a topic under a lesson that isn't in the
	 * result, or a quiz whose parent isn't, is left out. In the student view
	 * (published only), quizzes without questions are left out too.
	 *
	 * @param int  $course_id      Course ID.
	 * @param bool $published_only Only published steps (student view).
	 * @return array{lessons: array<int, array{id: int, topics: int[], quizzes: int[]}>, quizzes: int[], steps: int[], parent: array<int, int>, children: array<int, int[]>, types: array<int, string>}
	 */
	public function get_tree( int $course_id, bool $published_only = true ): array {
		$key = $course_id . ':' . ( $published_only ? '1' : '0' );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		$tree = array(
			'lessons'  => array(),
			'quizzes'  => array(),
			'steps'    => array(),
			'parent'   => array(),
			'children' => array(),
			'types'    => array(),
		);

		if ( $course_id <= 0 ) {
			return $tree;
		}

		$by_type = array(
			PostTypes::LESSON => array(),
			PostTypes::TOPIC  => array(),
			PostTypes::QUIZ   => array(),
		);
		foreach ( $this->query_steps( $course_id, $published_only ) as $post ) {
			$by_type[ $post->post_type ][] = (int) $post->ID;
		}

		// Lessons, plus a canonical-ID map for translated children that still
		// point at the original-language parent.
		$lesson_ids = $by_type[ PostTypes::LESSON ];
		$canonical  = array();
		foreach ( $lesson_ids as $lesson_id ) {
			$canonical[ Multilingual::canonical_id( $lesson_id, PostTypes::LESSON ) ] = $lesson_id;
		}

		$topics_by_lesson = array();
		$topic_ids        = array();
		foreach ( $by_type[ PostTypes::TOPIC ] as $topic_id ) {
			$lesson_id = $this->resolve_parent( $this->get_lesson_id( $topic_id ), $lesson_ids, $canonical, PostTypes::LESSON );
			if ( $lesson_id ) {
				$topics_by_lesson[ $lesson_id ][] = $topic_id;
				$topic_ids[]                      = $topic_id;
				$canonical[ Multilingual::canonical_id( $topic_id, PostTypes::TOPIC ) ] = $topic_id;
			}
		}

		$quizzes_by_parent = array();
		$containers        = array_merge( $lesson_ids, $topic_ids );
		foreach ( $by_type[ PostTypes::QUIZ ] as $quiz_id ) {
			if ( $published_only && ! $this->quiz_has_questions( $quiz_id ) ) {
				continue;
			}
			$stored = $this->get_quiz_parent_id( $quiz_id );
			if ( 0 === $stored ) {
				$quizzes_by_parent[0][] = $quiz_id;
				continue;
			}
			$parent = $this->resolve_parent( $stored, $containers, $canonical, (string) get_post_type( $stored ) );
			if ( $parent ) {
				$quizzes_by_parent[ $parent ][] = $quiz_id;
			}
		}

		foreach ( $lesson_ids as $lesson_id ) {
			$topics            = $topics_by_lesson[ $lesson_id ] ?? array();
			$quizzes           = $quizzes_by_parent[ $lesson_id ] ?? array();
			$tree['lessons'][] = array(
				'id'      => $lesson_id,
				'topics'  => $topics,
				'quizzes' => $quizzes,
			);

			$this->add_step( $tree, $lesson_id, PostTypes::LESSON, null );
			$tree['children'][ $lesson_id ] = array_merge( $topics, $quizzes );

			foreach ( $topics as $topic_id ) {
				$this->add_step( $tree, $topic_id, PostTypes::TOPIC, $lesson_id );
				$topic_quizzes                 = $quizzes_by_parent[ $topic_id ] ?? array();
				$tree['children'][ $topic_id ] = $topic_quizzes;
				foreach ( $topic_quizzes as $quiz_id ) {
					$this->add_step( $tree, $quiz_id, PostTypes::QUIZ, $topic_id );
				}
			}
			foreach ( $quizzes as $quiz_id ) {
				$this->add_step( $tree, $quiz_id, PostTypes::QUIZ, $lesson_id );
			}
		}//end foreach

		$tree['quizzes'] = $quizzes_by_parent[0] ?? array();
		foreach ( $tree['quizzes'] as $quiz_id ) {
			$this->add_step( $tree, $quiz_id, PostTypes::QUIZ, 0 );
		}

		$this->cache[ $key ] = $tree;
		return $tree;
	}

	/**
	 * Ordered lesson IDs of a course.
	 *
	 * @param int  $course_id      Course ID.
	 * @param bool $published_only Only published.
	 * @return int[]
	 */
	public function get_lessons( int $course_id, bool $published_only = true ): array {
		return array_column( $this->get_tree( $course_id, $published_only )['lessons'], 'id' );
	}

	/**
	 * Ordered topic IDs of a lesson.
	 *
	 * @param int  $lesson_id      Lesson ID.
	 * @param bool $published_only Only published.
	 * @return int[]
	 */
	public function get_topics( int $lesson_id, bool $published_only = true ): array {
		foreach ( $this->get_tree( $this->get_course_id( $lesson_id ), $published_only )['lessons'] as $lesson ) {
			if ( $lesson['id'] === $lesson_id ) {
				return $lesson['topics'];
			}
		}
		return array();
	}

	/**
	 * Ordered quizzes attached to a lesson or topic.
	 *
	 * @param int  $parent_id      Lesson or topic ID.
	 * @param bool $published_only Only published.
	 * @return int[]
	 */
	public function get_quizzes( int $parent_id, bool $published_only = true ): array {
		$tree = $this->get_tree( $this->get_course_id( $parent_id ), $published_only );
		$ids  = $tree['children'][ $parent_id ] ?? array();
		return array_values( array_filter( $ids, static fn( $id ) => PostTypes::QUIZ === ( $tree['types'][ $id ] ?? '' ) ) );
	}

	/**
	 * Ordered course-level (final) quizzes.
	 *
	 * @param int  $course_id      Course ID.
	 * @param bool $published_only Only published.
	 * @return int[]
	 */
	public function get_course_quizzes( int $course_id, bool $published_only = true ): array {
		return $this->get_tree( $course_id, $published_only )['quizzes'];
	}

	/**
	 * Children of a lesson (topics, then quizzes) or topic (quizzes).
	 *
	 * @param int $step_id Step ID.
	 * @return int[]
	 */
	public function get_children( int $step_id ): array {
		return $this->get_tree( $this->get_course_id( $step_id ) )['children'][ $step_id ] ?? array();
	}

	/**
	 * Ancestors of a step in the published tree, nearest first (e.g. topic,
	 * lesson for a topic quiz). Empty for lessons and course-level quizzes.
	 *
	 * @param int $step_id Step ID.
	 * @return int[]
	 */
	public function get_ancestors( int $step_id ): array {
		$parents   = $this->get_tree( $this->get_course_id( $step_id ) )['parent'];
		$ancestors = array();
		$current   = $step_id;
		while ( isset( $parents[ $current ] ) && $parents[ $current ] > 0 ) {
			$current     = $parents[ $current ];
			$ancestors[] = $current;
		}
		return $ancestors;
	}

	/**
	 * The lesson a step belongs to (the lesson itself for lessons; 0 for
	 * course-level quizzes or unreachable steps).
	 *
	 * @param int $step_id Step ID.
	 * @return int
	 */
	public function get_lesson_of( int $step_id ): int {
		if ( PostTypes::LESSON === get_post_type( $step_id ) ) {
			return $step_id;
		}
		foreach ( $this->get_ancestors( $step_id ) as $ancestor ) {
			if ( PostTypes::LESSON === get_post_type( $ancestor ) ) {
				return $ancestor;
			}
		}
		return 0;
	}

	/**
	 * Flattened, ordered step IDs of a course.
	 *
	 * @param int  $course_id      Course ID.
	 * @param bool $published_only Only published.
	 * @return int[]
	 */
	public function get_steps( int $course_id, bool $published_only = true ): array {
		return $this->get_tree( $course_id, $published_only )['steps'];
	}

	/**
	 * Whether a step is part of a course's (published) tree.
	 *
	 * @param int $course_id Course ID.
	 * @param int $step_id   Step ID.
	 * @return bool
	 */
	public function contains( int $course_id, int $step_id ): bool {
		return in_array( $step_id, $this->get_steps( $course_id ), true );
	}

	/**
	 * Step before the given one in course order (0 if first).
	 *
	 * @param int $course_id Course ID.
	 * @param int $step_id   Step ID.
	 * @return int
	 */
	public function get_previous_step( int $course_id, int $step_id ): int {
		$steps = $this->get_steps( $course_id );
		$index = array_search( $step_id, $steps, true );
		return ( false !== $index && $index > 0 ) ? $steps[ $index - 1 ] : 0;
	}

	/**
	 * Step after the given one in course order (0 if last).
	 *
	 * @param int $course_id Course ID.
	 * @param int $step_id   Step ID.
	 * @return int
	 */
	public function get_next_step( int $course_id, int $step_id ): int {
		$steps = $this->get_steps( $course_id );
		$index = array_search( $step_id, $steps, true );
		return ( false !== $index && isset( $steps[ $index + 1 ] ) ) ? $steps[ $index + 1 ] : 0;
	}

	/**
	 * Highest menu_order among the given posts.
	 *
	 * @param int[] $ids Post IDs.
	 * @return int
	 */
	public function max_menu_order( array $ids ): int {
		$max = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post ) {
				$max = max( $max, (int) $post->menu_order );
			}
		}
		return $max;
	}

	/**
	 * Adds a step to the tree's flat lists.
	 *
	 * @param array    $tree   Tree (by reference).
	 * @param int      $id     Step ID.
	 * @param string   $type   Post type.
	 * @param int|null $parent_id Parent ID (null for lessons).
	 */
	private function add_step( array &$tree, int $id, string $type, ?int $parent_id ): void {
		$tree['steps'][]      = $id;
		$tree['types'][ $id ] = $type;
		if ( null !== $parent_id ) {
			$tree['parent'][ $id ] = $parent_id;
		}
	}

	/**
	 * Resolves a stored parent ID to a post in the result set, mapping through
	 * canonical (default-language) IDs for translations.
	 *
	 * @param int    $stored    Stored parent ID.
	 * @param int[]  $available Parent IDs present in the result.
	 * @param array  $canonical Canonical ID => present ID.
	 * @param string $type      Post type of the stored parent.
	 * @return int 0 when the parent isn't part of the result.
	 */
	private function resolve_parent( int $stored, array $available, array $canonical, string $type ): int {
		if ( $stored <= 0 ) {
			return 0;
		}
		if ( in_array( $stored, $available, true ) ) {
			return $stored;
		}
		return $canonical[ Multilingual::canonical_id( $stored, '' !== $type ? $type : PostTypes::LESSON ) ] ?? 0;
	}

	/**
	 * Queries the lessons, topics and quizzes of a course in a single query.
	 *
	 * With WPML/Polylang, steps may reference either the translated course or
	 * the original one (meta copied on translation), so both IDs are matched and
	 * the translation plugin's own language filter picks the right posts.
	 *
	 * @param int  $course_id      Course ID.
	 * @param bool $published_only Only published posts.
	 * @return WP_Post[]
	 */
	private function query_steps( int $course_id, bool $published_only ): array {
		$course_ids = array_values(
			array_unique(
				array( $course_id, Multilingual::canonical_id( $course_id, PostTypes::COURSE ) )
			)
		);

		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::step_types(),
				'post_status'            => $published_only ? 'publish' : self::EDITOR_STATUSES,
				'posts_per_page'         => -1,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed meta_key lookup; one query per course per request.
				'meta_query'             => array(
					array(
						'key'     => Meta::COURSE_ID,
						'value'   => $course_ids,
						'compare' => 'IN',
						'type'    => 'UNSIGNED',
					),
				),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'perm'                   => '',
			)
		);

		return $query->posts;
	}
}
