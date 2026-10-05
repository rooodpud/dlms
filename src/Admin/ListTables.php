<?php
/**
 * Admin list table additions.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Course/Lesson columns and a course filter to the lesson and topic
 * lists, and limits instructors' lists to their own content.
 */
final class ListTables {

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
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		foreach ( PostTypes::step_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column_content' ), 10, 2 );
		}
		add_action( 'restrict_manage_posts', array( $this, 'course_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
	}

	/**
	 * Adds columns after the title.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['dlms_course'] = __( 'Course', 'deutschlms' );
				$post_type             = get_current_screen()?->post_type;
				if ( PostTypes::TOPIC === $post_type ) {
					$result['dlms_lesson'] = __( 'Lesson', 'deutschlms' );
				} elseif ( PostTypes::QUIZ === $post_type ) {
					$result['dlms_parent'] = __( 'Attached to', 'deutschlms' );
				}
			}
		}
		return $result;
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function column_content( $column, $post_id ): void {
		if ( 'dlms_course' === $column ) {
			$this->print_link( $this->structure->get_course_id( (int) $post_id ) );
		} elseif ( 'dlms_lesson' === $column ) {
			$this->print_link( $this->structure->get_lesson_id( (int) $post_id ) );
		} elseif ( 'dlms_parent' === $column ) {
			$parent_id = $this->structure->get_quiz_parent_id( (int) $post_id );
			if ( $parent_id ) {
				$this->print_link( $parent_id );
			} elseif ( $this->structure->get_course_id( (int) $post_id ) ) {
				esc_html_e( 'Whole course (final quiz)', 'deutschlms' );
			} else {
				echo '<span aria-hidden="true">—</span>';
			}
		}
	}

	/**
	 * Course dropdown above the lesson/topic lists.
	 *
	 * @param string $post_type Current post type.
	 */
	public function course_filter( $post_type ): void {
		if ( ! in_array( $post_type, PostTypes::step_types(), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter (read-only GET).
		$selected = isset( $_GET['dlms_course'] ) ? absint( $_GET['dlms_course'] ) : 0;
		$courses  = get_posts(
			array(
				'post_type'      => PostTypes::COURSE,
				'post_status'    => CourseStructure::EDITOR_STATUSES,
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Admin filter dropdown.
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<label for="dlms-course-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by course', 'deutschlms' ); ?></label>
		<select id="dlms-course-filter" name="dlms_course">
			<option value="0"><?php esc_html_e( 'All courses', 'deutschlms' ); ?></option>
			<?php foreach ( $courses as $course ) : ?>
				<option value="<?php echo esc_attr( (string) $course->ID ); ?>" <?php selected( $selected, $course->ID ); ?>><?php echo esc_html( get_the_title( $course ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Applies the course filter, and shows instructors only their own content.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! is_admin() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$types     = PostTypes::capability_types();
		if ( ! is_string( $post_type ) || ! isset( $types[ $post_type ] ) ) {
			return;
		}

		if ( ! current_user_can( "edit_others_{$types[ $post_type ]}" ) ) {
			$query->set( 'author', get_current_user_id() );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter (read-only GET).
		$course_id = isset( $_GET['dlms_course'] ) ? absint( $_GET['dlms_course'] ) : 0;
		if ( $course_id && PostTypes::COURSE !== $post_type ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array(
				'key'   => Meta::COURSE_ID,
				'value' => $course_id,
				'type'  => 'UNSIGNED',
			);
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Prints an edit link for a post, or a dash.
	 *
	 * @param int $post_id Post ID.
	 */
	private function print_link( int $post_id ): void {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		$title = get_the_title( $post_id );
		$link  = get_edit_post_link( $post_id );
		if ( $link ) {
			printf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) );
		} else {
			echo esc_html( $title );
		}
	}
}
