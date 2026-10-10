<?php
/**
 * Courses → Arrange courses.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\CourseOrder;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Drag-and-drop screen for the order of the courses in the course grid, with
 * the level (A1, A2 …) of each course. The order is saved as every course's
 * `menu_order`, the level as `_dlms_level`; nothing else changes.
 */
final class CourseArrange {

	public const SLUG   = 'dlms-arrange-courses';
	public const SCRIPT = 'dlms-course-arrange';

	private const ACTION = 'dlms_save_course_order';
	private const NONCE  = 'dlms_arrange_courses';

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 15 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * Capability to arrange courses: editing other people's courses, because
	 * the arrangement is site-wide.
	 *
	 * @return string
	 */
	public static function capability(): string {
		$type = get_post_type_object( PostTypes::COURSE );
		return $type ? (string) $type->cap->edit_others_posts : 'manage_options';
	}

	/**
	 * Menu entry under Courses.
	 */
	public function add_menu(): void {
		$this->hook = (string) add_submenu_page(
			'edit.php?post_type=' . PostTypes::COURSE,
			__( 'Arrange courses', 'deutschlms' ),
			__( 'Arrange courses', 'deutschlms' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Script and styles of the page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}
		wp_enqueue_style( self::SCRIPT, DLMS_URL . 'assets/admin/course-arrange.css', array(), dlms_asset_version( 'assets/admin/course-arrange.css' ) );
		wp_enqueue_script( self::SCRIPT, DLMS_URL . 'assets/admin/course-arrange.js', array( 'jquery-ui-sortable', 'wp-a11y' ), dlms_asset_version( 'assets/admin/course-arrange.js' ), true );
		wp_add_inline_script(
			self::SCRIPT,
			'window.dlmsCourseArrange = ' . wp_json_encode(
				array(
					/* translators: 1: course title, 2: new position, 3: number of courses. */
					'moved' => __( '%1$s moved to position %2$d of %3$d.', 'deutschlms' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Courses in the current order: arranged ones first, then by title.
	 *
	 * @return WP_Post[]
	 */
	private function courses(): array {
		$query = new WP_Query(
			array(
				'post_type'           => PostTypes::COURSE,
				'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A handful of courses; all of them must be arrangeable.
				'orderby'             => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);
		return $query->posts;
	}

	/**
	 * The page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}
		$courses  = $this->courses();
		$statuses = get_post_statuses();
		?>
		<div class="wrap dlms-arrange">
			<h1><?php esc_html_e( 'Arrange courses', 'deutschlms' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a notice. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The order and levels are saved.', 'deutschlms' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Drag the courses into the order you want them to appear in on the Kurse page, or use the arrow buttons. Choose a level for every course: with grouping by level, the course grid shows a heading (A1, A2 …) above each group and keeps this order inside the group. Draft courses are listed too; the course grid shows published ones only.', 'deutschlms' ); ?>
			</p>

			<?php if ( ! $courses ) : ?>
				<p><?php esc_html_e( 'There are no courses yet.', 'deutschlms' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>

					<ol class="dlms-arrange__list" id="dlms-arrange-list">
						<?php foreach ( $courses as $course ) : ?>
							<?php
							$thumb  = get_the_post_thumbnail( $course, array( 64, 64 ), array( 'alt' => '' ) );
							$level  = CourseOrder::level_for( $course->ID );
							$status = $statuses[ $course->post_status ] ?? $course->post_status;
							?>
							<li class="dlms-arrange__item" data-title="<?php echo esc_attr( get_the_title( $course ) ); ?>">
								<input type="hidden" name="course_order[]" value="<?php echo esc_attr( (string) $course->ID ); ?>" />
								<span class="dlms-arrange__handle dashicons dashicons-menu" aria-hidden="true"></span>
								<span class="dlms-arrange__thumb"><?php echo $thumb ? wp_kses_post( $thumb ) : ''; ?></span>
								<span class="dlms-arrange__title">
									<a href="<?php echo esc_url( (string) get_edit_post_link( $course->ID ) ); ?>"><?php echo esc_html( get_the_title( $course ) ); ?></a>
									<?php if ( 'publish' !== $course->post_status ) : ?>
										<span class="dlms-arrange__status"><?php echo esc_html( $status ); ?></span>
									<?php endif; ?>
								</span>
								<label class="dlms-arrange__level">
									<span class="screen-reader-text"><?php esc_html_e( 'Level', 'deutschlms' ); ?></span>
									<select name="course_level[<?php echo esc_attr( (string) $course->ID ); ?>]">
										<option value=""><?php esc_html_e( 'No level', 'deutschlms' ); ?></option>
										<?php foreach ( CourseOrder::LEVELS as $option ) : ?>
											<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $level, $option ); ?>><?php echo esc_html( $option ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<span class="dlms-arrange__buttons">
									<button type="button" class="button dlms-arrange__up">
										<span class="screen-reader-text"><?php esc_html_e( 'Move up', 'deutschlms' ); ?></span>
										<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
									</button>
									<button type="button" class="button dlms-arrange__down">
										<span class="screen-reader-text"><?php esc_html_e( 'Move down', 'deutschlms' ); ?></span>
										<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
									</button>
								</span>
							</li>
						<?php endforeach; ?>
					</ol>

					<?php submit_button( __( 'Save order and levels', 'deutschlms' ) ); ?>
				</form>

				<p class="description">
					<?php
					printf(
						/* translators: %s: shortcode. */
						esc_html__( 'Show the courses on a page with %s. Without group_by the courses follow this order without headings.', 'deutschlms' ),
						'<code>[dlms_course_grid group_by="level"]</code>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Saves the order and the levels.
	 */
	public function save(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, self::NONCE );

		$order  = isset( $_POST['course_order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['course_order'] ) ) : array();
		$levels = array();
		if ( isset( $_POST['course_level'] ) && is_array( $_POST['course_level'] ) ) {
			foreach ( wp_unslash( $_POST['course_level'] ) as $course_id => $level ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by CourseOrder::sanitize_level() in apply().
				$levels[ absint( $course_id ) ] = sanitize_text_field( (string) $level );
			}
		}

		$this->apply( $order, $levels );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => PostTypes::COURSE,
					'page'      => self::SLUG,
					'updated'   => 1,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Writes the positions and levels. Courses the user may not edit are
	 * skipped (and do not take a position).
	 *
	 * @param int[]                    $order  Course IDs in the wanted order.
	 * @param array<int|string, mixed> $levels Level per course ID.
	 */
	public function apply( array $order, array $levels ): void {
		$position = 0;
		foreach ( array_unique( array_filter( array_map( 'absint', $order ) ) ) as $course_id ) {
			$course = get_post( $course_id );
			if ( ! $course instanceof WP_Post || PostTypes::COURSE !== $course->post_type || ! current_user_can( 'edit_post', $course_id ) ) {
				continue;
			}

			$position += CourseOrder::STEP;
			if ( (int) $course->menu_order !== $position ) {
				wp_update_post(
					array(
						'ID'         => $course_id,
						'menu_order' => $position,
					)
				);
			}

			$level = CourseOrder::sanitize_level( $levels[ $course_id ] ?? '' );
			if ( '' === $level ) {
				delete_post_meta( $course_id, Meta::LEVEL );
			} else {
				update_post_meta( $course_id, Meta::LEVEL, $level );
			}
		}//end foreach
	}
}
