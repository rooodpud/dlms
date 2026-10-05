<?php
/**
 * Course builder endpoints.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Content\StructureEditor;
use DeutschLMS\Quiz\QuestionBank;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin course builder API. Every route requires `edit_post` on the course;
 * StructureEditor re-checks `edit_post` on each item it touches.
 *
 * GET  /dlms/v1/courses/{id}/structure  Tree including drafts.
 * PUT  /dlms/v1/courses/{id}/structure  Save order (topics/quizzes may move) and section headings.
 * POST /dlms/v1/courses/{id}/lessons    Create a draft lesson.
 * POST /dlms/v1/lessons/{id}/topics     Create a draft topic.
 * POST /dlms/v1/courses/{id}/quizzes    Create a draft quiz (parent_id: 0, lesson or topic).
 */
final class CourseBuilderController extends RestController {

	/**
	 * Structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

	/**
	 * Structure writer.
	 *
	 * @var StructureEditor
	 */
	private StructureEditor $editor;

	/**
	 * Question bank (question counts).
	 *
	 * @var QuestionBank
	 */
	private QuestionBank $bank;

	/**
	 * Constructor.
	 *
	 * @param CourseStructure $structure Structure reader.
	 * @param StructureEditor $editor    Structure writer.
	 * @param QuestionBank    $bank      Question bank.
	 */
	public function __construct( CourseStructure $structure, StructureEditor $editor, QuestionBank $bank ) {
		$this->structure = $structure;
		$this->editor    = $editor;
		$this->bank      = $bank;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$id_list = array(
			'type'     => 'array',
			'maxItems' => 500,
			'items'    => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/courses/(?P<id>\d+)/structure',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_structure' ),
					'permission_callback' => array( $this, 'can_edit_course' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_structure' ),
					'permission_callback' => array( $this, 'can_edit_course' ),
					'args'                => array(
						'id'       => $this->id_arg(),
						'lessons'  => array(
							'type'              => 'array',
							'required'          => true,
							'maxItems'          => 500,
							'validate_callback' => 'rest_validate_request_arg',
							'items'             => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'id'      => array(
										'type'     => 'integer',
										'minimum'  => 1,
										'required' => true,
									),
									// A topic is its ID, or { id, quizzes } to also place its quizzes.
									'topics'  => array(
										'type'     => 'array',
										'maxItems' => 500,
										'items'    => array(
											'type'       => array( 'integer', 'object' ),
											'minimum'    => 1,
											'additionalProperties' => false,
											'properties' => array(
												'id'      => array(
													'type' => 'integer',
													'minimum' => 1,
													'required' => true,
												),
												'quizzes' => $id_list,
											),
										),
									),
									'quizzes' => $id_list,
								),
							),
						),
						'quizzes'  => array_merge( $id_list, array( 'validate_callback' => 'rest_validate_request_arg' ) ),
						// The complete heading list; omit it to keep the current one.
						'headings' => array(
							'type'              => 'array',
							'maxItems'          => 50,
							'validate_callback' => 'rest_validate_request_arg',
							'items'             => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'id'     => array(
										'type'      => 'string',
										'maxLength' => 40,
										'pattern'   => '^[a-z0-9_-]*$',
									),
									'title'  => array(
										'type'      => 'string',
										'required'  => true,
										'maxLength' => 200,
									),
									'before' => array(
										'type'     => 'integer',
										'minimum'  => 0,
										'required' => true,
									),
								),
							),
						),
					),
				),
			)
		);

		$title_arg = array(
			'type'              => 'string',
			'required'          => true,
			'minLength'         => 1,
			'maxLength'         => 200,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/courses/(?P<id>\d+)/lessons',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_lesson' ),
				'permission_callback' => array( $this, 'can_edit_course' ),
				'args'                => array(
					'id'    => $this->id_arg(),
					'title' => $title_arg,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/lessons/(?P<id>\d+)/topics',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_topic' ),
				'permission_callback' => array( $this, 'can_edit_lesson' ),
				'args'                => array(
					'id'    => $this->id_arg(),
					'title' => $title_arg,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/courses/(?P<id>\d+)/quizzes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_quiz' ),
				'permission_callback' => array( $this, 'can_edit_course' ),
				'args'                => array(
					'id'        => $this->id_arg(),
					'title'     => $title_arg,
					'parent_id' => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Permission: may edit this course.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_edit_course( WP_REST_Request $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) || ! current_user_can( 'edit_post', $course_id ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Permission: may edit this lesson and its course.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_edit_lesson( WP_REST_Request $request ) {
		$lesson_id = (int) $request['id'];
		$course_id = $this->structure->get_course_id( $lesson_id );
		if ( PostTypes::LESSON !== get_post_type( $lesson_id ) || ! current_user_can( 'edit_post', $lesson_id ) || ! current_user_can( 'edit_post', $course_id ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Returns the tree with drafts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_structure( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->tree_response( (int) $request['id'] ) );
	}

	/**
	 * Saves the order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_structure( WP_REST_Request $request ) {
		$course_id = (int) $request['id'];
		$quizzes   = $request->has_param( 'quizzes' ) ? (array) $request['quizzes'] : null;
		$headings  = $request->has_param( 'headings' ) ? (array) $request['headings'] : null;
		$result    = $this->editor->save_order( get_current_user_id(), $course_id, (array) $request['lessons'], $quizzes, $headings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $this->tree_response( $course_id ) );
	}

	/**
	 * Creates a lesson.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_lesson( WP_REST_Request $request ) {
		$lesson_id = $this->editor->create_lesson( get_current_user_id(), (int) $request['id'], (string) $request['title'] );
		if ( is_wp_error( $lesson_id ) ) {
			return $lesson_id;
		}
		return new WP_REST_Response( $this->item( $lesson_id ), 201 );
	}

	/**
	 * Creates a topic.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_topic( WP_REST_Request $request ) {
		$topic_id = $this->editor->create_topic( get_current_user_id(), (int) $request['id'], (string) $request['title'] );
		if ( is_wp_error( $topic_id ) ) {
			return $topic_id;
		}
		return new WP_REST_Response( $this->item( $topic_id ), 201 );
	}

	/**
	 * Creates a quiz.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_quiz( WP_REST_Request $request ) {
		$quiz_id = $this->editor->create_quiz( get_current_user_id(), (int) $request['id'], (int) $request['parent_id'], (string) $request['title'] );
		if ( is_wp_error( $quiz_id ) ) {
			return $quiz_id;
		}
		return new WP_REST_Response( $this->item( $quiz_id ), 201 );
	}

	/**
	 * Tree response (includes drafts, as the builder shows them).
	 *
	 * @param int $course_id Course ID.
	 * @return array
	 */
	private function tree_response( int $course_id ): array {
		$this->structure->flush();
		$tree    = $this->structure->get_tree( $course_id, false );
		$lessons = array();
		foreach ( $tree['lessons'] as $lesson ) {
			$item           = $this->item( $lesson['id'] );
			$item['topics'] = array();
			foreach ( $lesson['topics'] as $topic_id ) {
				$topic            = $this->item( $topic_id );
				$topic['quizzes'] = array_map( array( $this, 'item' ), $tree['children'][ $topic_id ] ?? array() );
				$item['topics'][] = $topic;
			}
			$item['quizzes'] = array_map( array( $this, 'item' ), $lesson['quizzes'] );
			$lessons[]       = $item;
		}

		// Placed where the builder shows them: above a lesson, or 0 = after the last one.
		$headings = array();
		foreach ( $this->structure->get_headings( $course_id, false ) as $before => $group ) {
			foreach ( $group as $heading ) {
				$headings[] = array_merge( $heading, array( 'before' => (int) $before ) );
			}
		}

		return array(
			'course_id' => $course_id,
			'lessons'   => $lessons,
			'quizzes'   => array_map( array( $this, 'item' ), $tree['quizzes'] ),
			'headings'  => $headings,
		);
	}

	/**
	 * Builder item.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function item( int $post_id ): array {
		$status = get_post_status( $post_id );
		$object = get_post_status_object( (string) $status );
		$item   = array(
			'id'           => $post_id,
			'type'         => get_post_type( $post_id ),
			'title'        => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'status'       => $status,
			'status_label' => $object ? $object->label : (string) $status,
			'edit_link'    => current_user_can( 'edit_post', $post_id ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
			'view_link'    => (string) get_permalink( $post_id ),
		);
		if ( PostTypes::QUIZ === $item['type'] ) {
			$item['question_count'] = $this->bank->question_count( $post_id );
		}
		return $item;
	}
}
