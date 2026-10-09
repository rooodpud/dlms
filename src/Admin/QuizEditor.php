<?php
/**
 * Quiz edit screen.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Content\StructureEditor;
use DeutschLMS\Frontend\HelpLanguage;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\Questions;
use DeutschLMS\Quiz\QuizService;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * "Questions" and "Quiz settings" boxes on the quiz edit screen.
 *
 * The quiz builder is a script that keeps a hidden JSON field in sync: the
 * quiz's items (bank questions with their data, and random rules). On save
 * QuestionBank::save_quiz_items() updates changed bank questions, creates new
 * ones and stores the links; everything is sanitized there. Correct answers
 * live in protected post meta and are only ever printed here and in the bank
 * screens, for users who can edit them.
 */
final class QuizEditor {

	public const SCRIPT = 'dlms-quiz-editor';

	private const NONCE_ACTION = 'dlms_quiz_editor';
	private const NONCE_FIELD  = 'dlms_quiz_editor_nonce';

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
	 * Question bank.
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
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostTypes::QUIZ, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . PostTypes::QUIZ, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box( 'dlms-quiz-questions-box', __( 'Questions', 'deutschlms' ), array( $this, 'render_questions' ), PostTypes::QUIZ, 'normal', 'high' );
		add_meta_box( 'dlms-quiz-settings-box', __( 'Quiz settings', 'deutschlms' ), array( $this, 'render_settings' ), PostTypes::QUIZ, 'side', 'high' );
	}

	/**
	 * Quiz builder mount point with the quiz's items as JSON.
	 *
	 * @param WP_Post $post Quiz.
	 */
	public function render_questions( WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		printf(
			'<input type="hidden" id="dlms-questions-json" name="dlms_quiz_items_json" value="%s" />',
			esc_attr( (string) wp_json_encode( $this->bank->editor_items( $post->ID ) ) )
		);
		self::print_mount( 'quiz', (string) admin_url( 'edit.php?post_type=' . PostTypes::QUESTION ) );
	}

	/**
	 * The editor's mount point (shared with the question screen).
	 *
	 * @param string $mode     'quiz' (quiz builder) or 'question' (one bank question).
	 * @param string $bank_url Questions list URL.
	 */
	public static function print_mount( string $mode, string $bank_url ): void {
		$type = get_post_type_object( PostTypes::QUESTION );
		printf(
			'<div id="dlms-quiz-editor" class="dlms-quiz-editor" data-mode="%s" data-types="%s" data-terms="%s" data-bank-url="%s" data-can-create="%s" data-help-languages="%s"><p>%s</p></div><noscript><p>%s</p></noscript>',
			esc_attr( $mode ),
			esc_attr( (string) wp_json_encode( Questions::types() ) ),
			esc_attr( (string) wp_json_encode( QuestionBank::term_options() ) ),
			esc_url( $bank_url ),
			$type && current_user_can( $type->cap->create_posts ) ? '1' : '0',
			// Languages of question translations (the language switch, see HelpLanguage).
			esc_attr( (string) wp_json_encode( self::help_languages() ) ),
			esc_html__( 'Loading questions…', 'deutschlms' ),
			esc_html__( 'The question editor needs JavaScript.', 'deutschlms' )
		);
	}

	/**
	 * Languages questions can be translated into: [ { code, name } ].
	 *
	 * @return array[]
	 */
	private static function help_languages(): array {
		$languages = HelpLanguage::languages();
		return array_map(
			static fn( $code ) => array(
				'code' => $code,
				'name' => $languages[ $code ]['name'],
			),
			HelpLanguage::translation_codes()
		);
	}

	/**
	 * Placement and grading settings.
	 *
	 * @param WP_Post $post Quiz.
	 */
	public function render_settings( WP_Post $post ): void {
		$course_id = $this->structure->get_course_id( $post->ID );
		$parent_id = $this->structure->get_quiz_parent_id( $post->ID );
		if ( ! $course_id && 'auto-draft' === $post->post_status ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only defaults for form fields.
			$course_id = isset( $_GET['dlms_course_id'] ) ? absint( $_GET['dlms_course_id'] ) : 0;
			$parent_id = isset( $_GET['dlms_parent_id'] ) ? absint( $_GET['dlms_parent_id'] ) : 0;
			// phpcs:enable
		}
		$current   = $course_id ? $course_id . ':' . $parent_id : '';
		$pass_mark = get_post_meta( $post->ID, Meta::PASS_MARK, true );
		$pass_mark = '' === $pass_mark ? 80 : (int) $pass_mark;
		$attempts  = absint( get_post_meta( $post->ID, Meta::ATTEMPTS_LIMIT, true ) );
		$show      = (bool) get_post_meta( $post->ID, Meta::SHOW_ANSWERS, true );
		// New quizzes start with the default; existing ones show what they have.
		$minutes = 'auto-draft' === $post->post_status && ! metadata_exists( 'post', $post->ID, Meta::TIME_LIMIT )
			? QuizService::DEFAULT_TIME_LIMIT
			: absint( get_post_meta( $post->ID, Meta::TIME_LIMIT, true ) );
		?>
		<p>
			<label for="dlms-quiz-placement"><strong><?php esc_html_e( 'Attached to', 'deutschlms' ); ?></strong></label>
			<select id="dlms-quiz-placement" name="dlms_quiz_placement" class="widefat">
				<option value=""><?php esc_html_e( '— Not in a course —', 'deutschlms' ); ?></option>
				<?php foreach ( $this->placement_options() as $group ) : ?>
					<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
						<?php foreach ( $group['options'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="dlms-pass-mark"><strong><?php esc_html_e( 'Pass mark (%)', 'deutschlms' ); ?></strong></label>
			<input type="number" id="dlms-pass-mark" name="dlms_pass_mark" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $pass_mark ); ?>" class="small-text" />
		</p>
		<p>
			<label for="dlms-attempts-limit"><strong><?php esc_html_e( 'Attempts allowed', 'deutschlms' ); ?></strong></label>
			<input type="number" id="dlms-attempts-limit" name="dlms_attempts_limit" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $attempts ); ?>" class="small-text" aria-describedby="dlms-attempts-help" />
			<span id="dlms-attempts-help" class="description"><?php esc_html_e( '0 = unlimited. Students who use up their attempts without passing need a reset (Users → profile).', 'deutschlms' ); ?></span>
		</p>
		<p>
			<label for="dlms-time-limit"><strong><?php esc_html_e( 'Time limit (minutes)', 'deutschlms' ); ?></strong></label>
			<input type="number" id="dlms-time-limit" name="dlms_time_limit" min="0" max="600" step="1" value="<?php echo esc_attr( (string) $minutes ); ?>" class="small-text" aria-describedby="dlms-time-limit-help" />
			<span id="dlms-time-limit-help" class="description"><?php esc_html_e( '0 = no time limit. When the time is up, the answers lock and are submitted automatically after 15 seconds. Answers that arrive too late count as not passed.', 'deutschlms' ); ?></span>
		</p>
		<p>
			<label>
				<input type="checkbox" name="dlms_show_answers" value="1" <?php checked( $show ); ?> />
				<?php esc_html_e( 'Show correct answers after each attempt', 'deutschlms' ); ?>
			</label>
			<br /><span class="description"><?php esc_html_e( 'With retakes allowed, students can use them on their next attempt.', 'deutschlms' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Saves questions, settings and placement.
	 *
	 * @param int $post_id Quiz ID.
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['dlms_quiz_items_json'] ) ) {
			// Decoded JSON is fully sanitized by QuestionBank::save_quiz_items().
			$decoded = json_decode( wp_unslash( $_POST['dlms_quiz_items_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) ) {
				$this->bank->save_quiz_items( get_current_user_id(), (int) $post_id, $decoded );
			}
		}

		if ( isset( $_POST['dlms_pass_mark'] ) ) {
			update_post_meta( $post_id, Meta::PASS_MARK, max( 0, min( 100, absint( $_POST['dlms_pass_mark'] ) ) ) );
		}
		if ( isset( $_POST['dlms_attempts_limit'] ) ) {
			update_post_meta( $post_id, Meta::ATTEMPTS_LIMIT, min( 100, absint( $_POST['dlms_attempts_limit'] ) ) );
		}
		if ( isset( $_POST['dlms_time_limit'] ) ) {
			update_post_meta( $post_id, Meta::TIME_LIMIT, min( 600, absint( $_POST['dlms_time_limit'] ) ) );
		}
		update_post_meta( $post_id, Meta::SHOW_ANSWERS, ! empty( $_POST['dlms_show_answers'] ) );

		if ( isset( $_POST['dlms_quiz_placement'] ) ) {
			$placement = sanitize_text_field( wp_unslash( $_POST['dlms_quiz_placement'] ) );
			$parts     = array_map( 'absint', explode( ':', $placement ) );
			$this->editor->assign_quiz( get_current_user_id(), (int) $post_id, $parts[0] ?? 0, $parts[1] ?? 0 );
		}
	}

	/**
	 * Enqueues the question editor on quiz screens.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || PostTypes::QUIZ !== $screen->post_type ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * The editor's script and styles (shared with the question screen).
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( self::SCRIPT, DLMS_URL . 'assets/admin/quiz-editor.css', array(), dlms_asset_version( 'assets/admin/quiz-editor.css' ) );
		NounPicturesPage::enqueue_picker();
		wp_enqueue_script( self::SCRIPT, DLMS_URL . 'assets/admin/quiz-editor.js', array( 'wp-i18n', 'wp-a11y', 'wp-data', 'wp-api-fetch', NounPicturesPage::SCRIPT ), dlms_asset_version( 'assets/admin/quiz-editor.js' ), true );
		wp_set_script_translations( self::SCRIPT, 'deutschlms', DLMS_PATH . 'languages' );
	}

	/**
	 * Placement choices, grouped by course: the course itself (final quiz),
	 * each lesson, and each topic. Only courses the user can edit.
	 *
	 * @return array<int, array{label: string, options: array<string, string>}>
	 */
	private function placement_options(): array {
		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::COURSE,
				'post_status'            => CourseStructure::EDITOR_STATUSES,
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Admin select of courses.
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$groups = array();
		foreach ( $query->posts as $course ) {
			if ( ! current_user_can( 'edit_post', $course->ID ) ) {
				continue;
			}
			$tree    = $this->structure->get_tree( $course->ID, false );
			$options = array( $course->ID . ':0' => __( 'Whole course (final quiz)', 'deutschlms' ) );
			foreach ( $tree['lessons'] as $lesson ) {
				/* translators: %s: lesson title. */
				$options[ $course->ID . ':' . $lesson['id'] ] = sprintf( __( 'Lesson: %s', 'deutschlms' ), $this->title( $lesson['id'] ) );
				foreach ( $lesson['topics'] as $topic_id ) {
					/* translators: %s: topic title. */
					$options[ $course->ID . ':' . $topic_id ] = sprintf( __( '— Topic: %s', 'deutschlms' ), $this->title( $topic_id ) );
				}
			}
			$groups[] = array(
				'label'   => $this->title( $course->ID ),
				'options' => $options,
			);
		}
		return $groups;
	}

	/**
	 * Plain title with a fallback.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function title( int $post_id ): string {
		$title = wp_strip_all_tags( get_the_title( $post_id ) );
		return '' !== $title ? $title : __( '(no title)', 'deutschlms' );
	}
}
