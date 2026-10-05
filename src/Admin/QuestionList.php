<?php
/**
 * Questions list screen.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\Questions;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Courses → Questions: type and "Used in" columns (categories, difficulty and
 * level come from the taxonomies), filters for type, category, difficulty,
 * level and course/lesson, and menu entries for the three term lists.
 */
final class QuestionList {

	/**
	 * Structure reader.
	 *
	 * @var CourseStructure
	 */
	private CourseStructure $structure;

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
	 * @param QuestionBank    $bank      Question bank.
	 */
	public function __construct( CourseStructure $structure, QuestionBank $bank ) {
		$this->structure = $structure;
		$this->bank      = $bank;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_term_menus' ) );
		add_filter( 'parent_file', array( $this, 'highlight_menu' ) );
		add_filter( 'manage_' . PostTypes::QUESTION . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . PostTypes::QUESTION . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'filters' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
	}

	/**
	 * Menu entries for the three term lists, under Courses.
	 */
	public function add_term_menus(): void {
		foreach ( PostTypes::question_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object ) {
				continue;
			}
			add_submenu_page(
				'edit.php?post_type=' . PostTypes::COURSE,
				$object->labels->name,
				$object->labels->menu_name,
				$object->cap->manage_terms,
				'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . PostTypes::QUESTION
			);
		}
	}

	/**
	 * Keeps the Courses menu open on the term screens.
	 *
	 * @param string $parent_file Parent menu file.
	 * @return string
	 */
	public function highlight_menu( $parent_file ) {
		global $submenu_file;
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->taxonomy, PostTypes::question_taxonomies(), true ) ) {
			$submenu_file = 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=' . PostTypes::QUESTION; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- How core picks the highlighted submenu.
			return 'edit.php?post_type=' . PostTypes::COURSE;
		}
		return $parent_file;
	}

	/**
	 * Columns: question, type, terms, used in.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$result['dlms_used_in'] = __( 'Used in', 'deutschlms' );
			}
			$result[ $key ] = 'title' === $key ? __( 'Question', 'deutschlms' ) : $label;
			if ( 'title' === $key ) {
				$result['dlms_type'] = __( 'Type', 'deutschlms' );
			}
		}
		if ( ! isset( $result['dlms_used_in'] ) ) {
			$result['dlms_used_in'] = __( 'Used in', 'deutschlms' );
		}
		return $result;
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Question ID.
	 */
	public function column_content( $column, $post_id ): void {
		if ( 'dlms_type' === $column ) {
			$type  = (string) get_post_meta( (int) $post_id, Meta::QUESTION_TYPE, true );
			$types = Questions::types();
			echo esc_html( $types[ $type ] ?? '—' );
			if ( '0' === get_post_meta( (int) $post_id, Meta::QUESTION_READY, true ) ) {
				echo '<br /><span class="dlms-question-incomplete">' . esc_html__( 'Incomplete: not shown to students', 'deutschlms' ) . '</span>';
			}
			return;
		}
		if ( 'dlms_used_in' !== $column ) {
			return;
		}

		$quizzes = $this->bank->quizzes_using( (int) $post_id );
		if ( ! $quizzes ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not used in any quiz', 'deutschlms' ) . '</span>';
			return;
		}
		$links = array();
		foreach ( array_slice( $quizzes, 0, 3 ) as $quiz_id ) {
			$title   = get_the_title( $quiz_id );
			$title   = '' !== $title ? $title : __( '(no title)', 'deutschlms' );
			$link    = get_edit_post_link( $quiz_id );
			$links[] = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
		}
		echo implode( '<br />', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		if ( count( $quizzes ) > 3 ) {
			echo '<br />' . esc_html(
				sprintf(
					/* translators: %d: number of further quizzes. */
					_n( '+ %d more quiz', '+ %d more quizzes', count( $quizzes ) - 3, 'deutschlms' ),
					count( $quizzes ) - 3
				)
			);
		}
	}

	/**
	 * Filter dropdowns above the list.
	 *
	 * @param string $post_type Current post type.
	 */
	public function filters( $post_type ): void {
		if ( PostTypes::QUESTION !== $post_type ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters (read-only GET).
		$type = isset( $_GET['dlms_qtype'] ) ? sanitize_key( wp_unslash( $_GET['dlms_qtype'] ) ) : '';
		$used = isset( $_GET['dlms_qused'] ) ? sanitize_text_field( wp_unslash( $_GET['dlms_qused'] ) ) : '';
		// phpcs:enable
		?>
		<label for="dlms-qtype-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'deutschlms' ); ?></label>
		<select id="dlms-qtype-filter" name="dlms_qtype">
			<option value=""><?php esc_html_e( 'All types', 'deutschlms' ); ?></option>
			<?php foreach ( Questions::types() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		$labels = array(
			'category'   => __( 'All categories', 'deutschlms' ),
			'difficulty' => __( 'All difficulty levels', 'deutschlms' ),
			'level'      => __( 'All CEFR levels', 'deutschlms' ),
		);
		$names  = array(
			'category'   => 'dlms_qcat',
			'difficulty' => 'dlms_qdiff',
			'level'      => 'dlms_qlevel',
		);
		foreach ( QuestionBank::term_options() as $name => $terms ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter (read-only GET).
			$selected = isset( $_GET[ $names[ $name ] ] ) ? absint( $_GET[ $names[ $name ] ] ) : 0;
			?>
			<label for="dlms-<?php echo esc_attr( $name ); ?>-filter" class="screen-reader-text"><?php echo esc_html( $labels[ $name ] ); ?></label>
			<select id="dlms-<?php echo esc_attr( $name ); ?>-filter" name="<?php echo esc_attr( $names[ $name ] ); ?>">
				<option value="0"><?php echo esc_html( $labels[ $name ] ); ?></option>
				<?php foreach ( $terms as $term ) : ?>
					<option value="<?php echo esc_attr( (string) $term['id'] ); ?>" <?php selected( $selected, $term['id'] ); ?>><?php echo esc_html( str_repeat( '— ', $term['depth'] ) . $term['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
		}
		?>
		<label for="dlms-qused-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by course or lesson', 'deutschlms' ); ?></label>
		<select id="dlms-qused-filter" name="dlms_qused">
			<option value=""><?php esc_html_e( 'All courses and lessons', 'deutschlms' ); ?></option>
			<?php foreach ( $this->courses() as $course ) : ?>
				<optgroup label="<?php echo esc_attr( get_the_title( $course ) ); ?>">
					<option value="<?php echo esc_attr( 'c:' . $course->ID ); ?>" <?php selected( $used, 'c:' . $course->ID ); ?>><?php esc_html_e( 'Whole course', 'deutschlms' ); ?></option>
					<?php foreach ( $this->structure->get_tree( $course->ID, false )['lessons'] as $lesson ) : ?>
						<option value="<?php echo esc_attr( 'l:' . $lesson['id'] ); ?>" <?php selected( $used, 'l:' . $lesson['id'] ); ?>><?php echo esc_html( get_the_title( $lesson['id'] ) ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Applies the filters to the list query.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_query( $query ): void {
		global $pagenow;
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! is_admin() || 'edit.php' !== $pagenow || PostTypes::QUESTION !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters (read-only GET).
		$type = isset( $_GET['dlms_qtype'] ) ? sanitize_key( wp_unslash( $_GET['dlms_qtype'] ) ) : '';
		if ( '' !== $type && array_key_exists( $type, Questions::types() ) ) {
			$meta   = (array) $query->get( 'meta_query' );
			$meta[] = array(
				'key'   => Meta::QUESTION_TYPE,
				'value' => $type,
			);
			$query->set( 'meta_query', $meta );
		}

		$tax   = (array) $query->get( 'tax_query' );
		$names = array(
			'category'   => 'dlms_qcat',
			'difficulty' => 'dlms_qdiff',
			'level'      => 'dlms_qlevel',
		);
		foreach ( PostTypes::question_taxonomies() as $name => $taxonomy ) {
			$term_id = isset( $_GET[ $names[ $name ] ] ) ? absint( $_GET[ $names[ $name ] ] ) : 0;
			if ( $term_id ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => array( $term_id ),
				);
			}
		}
		if ( $tax ) {
			$query->set( 'tax_query', $tax );
		}

		$used = isset( $_GET['dlms_qused'] ) ? sanitize_text_field( wp_unslash( $_GET['dlms_qused'] ) ) : '';
		// phpcs:enable
		if ( preg_match( '/^([cl]):(\d+)$/', $used, $match ) ) {
			$ids = $this->bank->questions_in( $this->quizzes_of( $match[1], (int) $match[2] ) );
			$query->set( 'post__in', $ids ? $ids : array( 0 ) );
		}
	}

	/**
	 * Quizzes of a course ("c") or of a lesson and its topics ("l").
	 *
	 * @param string $kind "c" or "l".
	 * @param int    $id   Course or lesson ID.
	 * @return int[]
	 */
	private function quizzes_of( string $kind, int $id ): array {
		if ( 'c' === $kind ) {
			$tree = $this->structure->get_tree( $id, false );
			return array_values(
				array_filter(
					$tree['steps'],
					static fn( $step_id ) => PostTypes::QUIZ === ( $tree['types'][ $step_id ] ?? '' )
				)
			);
		}

		$course_id = $this->structure->get_course_id( $id );
		$tree      = $this->structure->get_tree( $course_id, false );
		foreach ( $tree['lessons'] as $lesson ) {
			if ( $lesson['id'] !== $id ) {
				continue;
			}
			$quizzes = $lesson['quizzes'];
			foreach ( $lesson['topics'] as $topic_id ) {
				foreach ( $tree['children'][ $topic_id ] ?? array() as $child_id ) {
					if ( PostTypes::QUIZ === ( $tree['types'][ $child_id ] ?? '' ) ) {
						$quizzes[] = $child_id;
					}
				}
			}
			return $quizzes;
		}
		return array();
	}

	/**
	 * Courses for the filter (the ones the user can edit).
	 *
	 * @return \WP_Post[]
	 */
	private function courses(): array {
		$courses = get_posts(
			array(
				'post_type'      => PostTypes::COURSE,
				'post_status'    => CourseStructure::EDITOR_STATUSES,
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return array_values( array_filter( $courses, static fn( $course ) => current_user_can( 'edit_post', $course->ID ) ) );
	}
}
