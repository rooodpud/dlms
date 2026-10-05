<?php
/**
 * Question edit screen.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\Questions;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * One bank question: the same editor as in the quiz builder (one card), a
 * box for difficulty and CEFR level (categories use WordPress's own box), and
 * the quizzes that use the question.
 *
 * Title and search text are generated from the question when the post is
 * saved (wp_insert_post_data); the question itself is stored on save_post.
 */
final class QuestionEditor {

	private const NONCE_ACTION = 'dlms_question_editor';
	private const NONCE_FIELD  = 'dlms_question_editor_nonce';

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
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostTypes::QUESTION, array( $this, 'add_meta_boxes' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'derive_title' ) );
		add_action( 'save_post_' . PostTypes::QUESTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box( 'dlms-question-box', __( 'Question', 'deutschlms' ), array( $this, 'render_question' ), PostTypes::QUESTION, 'normal', 'high' );
		add_meta_box( 'dlms-question-terms-box', __( 'Difficulty and level', 'deutschlms' ), array( $this, 'render_terms' ), PostTypes::QUESTION, 'side', 'default' );
		add_meta_box( 'dlms-question-usage-box', __( 'Used in', 'deutschlms' ), array( $this, 'render_usage' ), PostTypes::QUESTION, 'side', 'default' );
	}

	/**
	 * Editor mount point with the question as JSON.
	 *
	 * @param WP_Post $post Question.
	 */
	public function render_question( WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$item = $this->bank->editor_item( $post->ID );
		if ( null === $item ) {
			$item = array(
				'kind'     => QuestionBank::KIND_QUESTION,
				'post_id'  => $post->ID,
				'editable' => true,
				'used_in'  => 0,
				'question' => null,
			);
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		printf(
			'<input type="hidden" id="dlms-questions-json" name="dlms_question_json" value="%s" />',
			esc_attr( (string) wp_json_encode( array( $item ) ) )
		);
		QuizEditor::print_mount( 'question', (string) admin_url( 'edit.php?post_type=' . PostTypes::QUESTION ) );
	}

	/**
	 * Difficulty and CEFR level selects (one term each).
	 *
	 * @param WP_Post $post Question.
	 */
	public function render_terms( WP_Post $post ): void {
		$current = $this->bank->terms_of( $post->ID );
		$options = QuestionBank::term_options();
		$fields  = array(
			'difficulty' => __( 'Difficulty', 'deutschlms' ),
			'level'      => __( 'CEFR level', 'deutschlms' ),
		);
		foreach ( $fields as $name => $label ) {
			$taxonomy = PostTypes::question_taxonomies()[ $name ];
			?>
			<p>
				<label for="dlms-question-<?php echo esc_attr( $name ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
				<select id="dlms-question-<?php echo esc_attr( $name ); ?>" name="dlms_question_terms[<?php echo esc_attr( $name ); ?>]" class="widefat">
					<option value="0"><?php esc_html_e( '— None —', 'deutschlms' ); ?></option>
					<?php foreach ( $options[ $name ] as $term ) : ?>
						<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php selected( $current[ $name ], $term['id'] ); ?>><?php echo esc_html( $term['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php
			$manage = get_taxonomy( $taxonomy );
			if ( $manage && current_user_can( $manage->cap->manage_terms ) ) {
				printf(
					'<p class="description"><a href="%s">%s</a></p>',
					esc_url( admin_url( 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . PostTypes::QUESTION ) ),
					esc_html__( 'Edit this list', 'deutschlms' )
				);
			}
		}//end foreach
	}

	/**
	 * The quizzes that link this question.
	 *
	 * @param WP_Post $post Question.
	 */
	public function render_usage( WP_Post $post ): void {
		$quizzes = $this->bank->quizzes_using( $post->ID );
		if ( ! $quizzes ) {
			echo '<p>' . esc_html__( 'Not used in any quiz yet. Add it to a quiz from the quiz builder ("Add from question bank").', 'deutschlms' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of quizzes. */
				_n( 'Changes appear in %d quiz:', 'Changes appear in all %d quizzes:', count( $quizzes ), 'deutschlms' ),
				count( $quizzes )
			)
		) . '</p><ul class="ul-disc">';
		foreach ( $quizzes as $quiz_id ) {
			$title = get_the_title( $quiz_id );
			$title = '' !== $title ? $title : __( '(no title)', 'deutschlms' );
			$link  = get_edit_post_link( $quiz_id );
			echo '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Sets the title and search text from the question being saved.
	 *
	 * @param array $data Slashed post data.
	 * @return array
	 */
	public function derive_title( $data ) {
		if ( PostTypes::QUESTION !== ( $data['post_type'] ?? '' ) || ! $this->valid_request() ) {
			return $data;
		}
		$question = Questions::sanitize_one( $this->posted_question() );
		if ( null !== $question ) {
			$data['post_title']   = wp_slash( QuestionBank::title_for( $question ) );
			$data['post_content'] = wp_slash( QuestionBank::search_text( $question ) );
		}
		return $data;
	}

	/**
	 * Stores the question, difficulty and level.
	 *
	 * @param int $post_id Question ID.
	 */
	public function save( $post_id ): void {
		if ( ! $this->valid_request() || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$this->bank->save_from_editor( (int) $post_id, $this->posted_question() );

		$terms = isset( $_POST['dlms_question_terms'] ) && is_array( $_POST['dlms_question_terms'] ) ? wp_unslash( $_POST['dlms_question_terms'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in valid_request(); absint below.
		$clean = array();
		foreach ( array( 'difficulty', 'level' ) as $name ) {
			$taxonomy = get_taxonomy( PostTypes::question_taxonomies()[ $name ] );
			if ( isset( $terms[ $name ] ) && $taxonomy && current_user_can( $taxonomy->cap->assign_terms ) ) {
				$clean[ $name ] = array( absint( $terms[ $name ] ) );
			}
		}
		$this->bank->set_terms( (int) $post_id, $clean );
	}

	/**
	 * Enqueues the editor on question screens.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && PostTypes::QUESTION === $screen->post_type ) {
			QuizEditor::enqueue_assets();
		}
	}

	/**
	 * Whether this request is a save from the question screen.
	 *
	 * @return bool
	 */
	private function valid_request(): bool {
		return isset( $_POST[ self::NONCE_FIELD ], $_POST['dlms_question_json'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION );
	}

	/**
	 * The question sent by the editor (raw; sanitized by the caller).
	 *
	 * @return mixed
	 */
	private function posted_question() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in valid_request(); sanitized by Questions::sanitize_one().
		$decoded = json_decode( wp_unslash( $_POST['dlms_question_json'] ?? '' ), true );
		return is_array( $decoded ) && is_array( $decoded[0]['question'] ?? null ) ? $decoded[0]['question'] : null;
	}
}
