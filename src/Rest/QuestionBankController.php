<?php
/**
 * Question bank endpoints.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Rest;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Quiz\QuestionBank;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * For the quiz builder. Both routes need the right to edit questions; the
 * list includes the correct answers (the builder shows and edits them).
 *
 * GET /dlms/v1/questions        Search the bank (search, type, category, difficulty, level, page, per_page).
 * GET /dlms/v1/questions/count  How many complete, published questions a random rule can draw from.
 */
final class QuestionBankController extends RestController {

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private QuestionBank $bank;

	/**
	 * Constructor.
	 *
	 * @param QuestionBank $bank Question bank.
	 */
	public function __construct( QuestionBank $bank ) {
		$this->bank = $bank;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$filters = array(
			'type'       => array(
				'type'    => 'string',
				'default' => '',
				'enum'    => array( '', 'single', 'multiple', 'true_false', 'fill_blank', 'word_order', 'article' ),
			),
			'category'   => $this->term_arg(),
			'difficulty' => $this->term_arg(),
			'level'      => $this->term_arg(),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/questions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'can_edit_questions' ),
				'args'                => array_merge(
					$filters,
					array(
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'maxLength'         => 200,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
					)
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/questions/count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'count' ),
				'permission_callback' => array( $this, 'can_edit_questions' ),
				'args'                => $filters,
			)
		);
	}

	/**
	 * Permission: may edit (and so see) bank questions.
	 *
	 * @return true|WP_Error
	 */
	public function can_edit_questions() {
		$type = get_post_type_object( PostTypes::QUESTION );
		if ( ! $type || ! current_user_can( $type->cap->edit_posts ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Search results.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			$this->bank->search(
				array(
					'search'     => (string) $request['search'],
					'type'       => (string) $request['type'],
					'category'   => (int) $request['category'],
					'difficulty' => (int) $request['difficulty'],
					'level'      => (int) $request['level'],
					'page'       => (int) $request['page'],
					'per_page'   => (int) $request['per_page'],
				)
			)
		);
	}

	/**
	 * Number of questions a random rule can draw from.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function count( WP_REST_Request $request ): WP_REST_Response {
		$rule = QuestionBank::sanitize_rule(
			array(
				'type'       => (string) $request['type'],
				'category'   => (int) $request['category'],
				'difficulty' => (int) $request['difficulty'],
				'level'      => (int) $request['level'],
			)
		);
		return new WP_REST_Response( array( 'count' => count( $this->bank->matching_ids( $rule ) ) ) );
	}

	/**
	 * Schema for an optional term ID filter.
	 *
	 * @return array
	 */
	private function term_arg(): array {
		return array(
			'type'              => 'integer',
			'default'           => 0,
			'minimum'           => 0,
			'sanitize_callback' => 'absint',
		);
	}
}
