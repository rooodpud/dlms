<?php
/**
 * Lesson/topic placement meta boxes.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Content\StructureEditor;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * "Course" box on lessons and "Lesson" box on topics. Only courses/lessons
 * the user can edit are offered, and the save path re-checks through
 * StructureEditor.
 */
final class StepMetaBoxes {

	private const NONCE_ACTION = 'dlms_step_placement';
	private const NONCE_FIELD  = 'dlms_step_placement_nonce';

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
	 * Constructor.
	 *
	 * @param CourseStructure $structure Structure reader.
	 * @param StructureEditor $editor    Structure writer.
	 */
	public function __construct( CourseStructure $structure, StructureEditor $editor ) {
		$this->structure = $structure;
		$this->editor    = $editor;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostTypes::LESSON, array( $this, 'add_lesson_box' ) );
		add_action( 'add_meta_boxes_' . PostTypes::TOPIC, array( $this, 'add_topic_box' ) );
		add_action( 'save_post_' . PostTypes::LESSON, array( $this, 'save_lesson' ) );
		add_action( 'save_post_' . PostTypes::TOPIC, array( $this, 'save_topic' ) );
	}

	/**
	 * Adds the lesson box.
	 */
	public function add_lesson_box(): void {
		add_meta_box( 'dlms-lesson-course', __( 'Course', 'deutschlms' ), array( $this, 'render_lesson_box' ), PostTypes::LESSON, 'side', 'high' );
	}

	/**
	 * Adds the topic box.
	 */
	public function add_topic_box(): void {
		add_meta_box( 'dlms-topic-lesson', __( 'Lesson', 'deutschlms' ), array( $this, 'render_topic_box' ), PostTypes::TOPIC, 'side', 'high' );
	}

	/**
	 * Course selector for a lesson.
	 *
	 * @param WP_Post $post Lesson.
	 */
	public function render_lesson_box( WP_Post $post ): void {
		$current = $this->structure->get_course_id( $post->ID );
		if ( ! $current && 'auto-draft' === $post->post_status ) {
			// Pre-selected when coming from a course's "Add lesson" link.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only default for a form field.
			$current = isset( $_GET['dlms_course_id'] ) ? absint( $_GET['dlms_course_id'] ) : 0;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p>
			<label for="dlms-course-id" class="screen-reader-text"><?php esc_html_e( 'Course', 'deutschlms' ); ?></label>
			<select id="dlms-course-id" name="dlms_course_id" class="widefat">
				<option value="0"><?php esc_html_e( '— Not in a course —', 'deutschlms' ); ?></option>
				<?php foreach ( $this->editable_courses() as $course ) : ?>
					<option value="<?php echo esc_attr( (string) $course->ID ); ?>" <?php selected( $current, $course->ID ); ?>>
						<?php echo esc_html( $this->label( $course ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description"><?php esc_html_e( 'Order lessons in the course builder on the course screen.', 'deutschlms' ); ?></p>
		<p>
			<label for="dlms-drip-days"><strong><?php esc_html_e( 'Unlock after (days)', 'deutschlms' ); ?></strong></label>
			<input type="number" id="dlms-drip-days" name="dlms_drip_days" min="0" max="3650" step="1" class="small-text" value="<?php echo esc_attr( (string) $this->structure->get_drip_days( $post->ID ) ); ?>" aria-describedby="dlms-drip-help" />
			<br /><span id="dlms-drip-help" class="description"><?php esc_html_e( 'Days after a student enrolls before this lesson (and its topics and quizzes) opens. 0 = right away.', 'deutschlms' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Lesson selector for a topic, grouped by course.
	 *
	 * @param WP_Post $post Topic.
	 */
	public function render_topic_box( WP_Post $post ): void {
		$current = $this->structure->get_lesson_id( $post->ID );
		if ( ! $current && 'auto-draft' === $post->post_status ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only default for a form field.
			$current = isset( $_GET['dlms_lesson_id'] ) ? absint( $_GET['dlms_lesson_id'] ) : 0;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p>
			<label for="dlms-lesson-id" class="screen-reader-text"><?php esc_html_e( 'Lesson', 'deutschlms' ); ?></label>
			<select id="dlms-lesson-id" name="dlms_lesson_id" class="widefat">
				<option value="0"><?php esc_html_e( '— Not in a lesson —', 'deutschlms' ); ?></option>
				<?php foreach ( $this->editable_courses() as $course ) : ?>
					<?php
					$lessons = array_filter(
						$this->structure->get_lessons( $course->ID, false ),
						static fn( $lesson_id ) => current_user_can( 'edit_post', $lesson_id )
					);
					if ( ! $lessons ) {
						continue;
					}
					?>
					<optgroup label="<?php echo esc_attr( $this->label( $course ) ); ?>">
						<?php foreach ( $lessons as $lesson_id ) : ?>
							<option value="<?php echo esc_attr( (string) $lesson_id ); ?>" <?php selected( $current, $lesson_id ); ?>>
								<?php echo esc_html( $this->label( get_post( $lesson_id ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Saves a lesson's course.
	 *
	 * @param int $post_id Lesson ID.
	 */
	public function save_lesson( $post_id ): void {
		if ( ! $this->verify_request( (int) $post_id ) || ! isset( $_POST['dlms_course_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_request().
			return;
		}
		$course_id = absint( $_POST['dlms_course_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_request().
		$this->editor->assign_lesson( get_current_user_id(), (int) $post_id, $course_id );

		if ( isset( $_POST['dlms_drip_days'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_request().
			update_post_meta( (int) $post_id, Meta::DRIP_DAYS, min( 3650, absint( $_POST['dlms_drip_days'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_request().
		}
	}

	/**
	 * Saves a topic's lesson (its course follows the lesson).
	 *
	 * @param int $post_id Topic ID.
	 */
	public function save_topic( $post_id ): void {
		if ( ! $this->verify_request( (int) $post_id ) || ! isset( $_POST['dlms_lesson_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_request().
			return;
		}
		$lesson_id = absint( $_POST['dlms_lesson_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_request().
		$this->editor->assign_topic( get_current_user_id(), (int) $post_id, $lesson_id );
	}

	/**
	 * Nonce, autosave and capability checks for a meta box save.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function verify_request( int $post_id ): bool {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return false;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Courses the current user may edit, alphabetically.
	 *
	 * @return WP_Post[]
	 */
	private function editable_courses(): array {
		$query = new WP_Query(
			array(
				'post_type'              => PostTypes::COURSE,
				'post_status'            => CourseStructure::EDITOR_STATUSES,
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Admin select of courses.
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);
		return array_values(
			array_filter(
				$query->posts,
				static fn( WP_Post $course ) => current_user_can( 'edit_post', $course->ID )
			)
		);
	}

	/**
	 * Option label: title plus status when not published.
	 *
	 * @param WP_Post|null $post Post.
	 * @return string
	 */
	private function label( $post ): string {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$title = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'deutschlms' );
		if ( 'publish' !== $post->post_status ) {
			$status = get_post_status_object( $post->post_status );
			/* translators: 1: post title, 2: post status such as "Draft". */
			$title = sprintf( __( '%1$s (%2$s)', 'deutschlms' ), $title, $status ? $status->label : $post->post_status );
		}
		return $title;
	}
}
