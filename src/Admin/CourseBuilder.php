<?php
/**
 * Course builder meta box.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Drag-and-drop builder for a course's lessons and topics, shown on the
 * course edit screen. The UI talks to the capability-checked
 * `dlms/v1/courses/{id}/structure` endpoints.
 */
final class CourseBuilder {

	public const SCRIPT = 'dlms-course-builder';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostTypes::COURSE, array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'dlms-course-builder-box',
			__( 'Course builder', 'deutschlms' ),
			array( $this, 'render' ),
			PostTypes::COURSE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the mount point; the script builds the UI.
	 *
	 * @param WP_Post $post Course.
	 */
	public function render( WP_Post $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		printf(
			'<div id="dlms-course-builder" class="dlms-builder" data-course-id="%d"><p class="dlms-builder__loading">%s</p></div><noscript><p>%s</p></noscript>',
			(int) $post->ID,
			esc_html__( 'Loading course structure…', 'deutschlms' ),
			esc_html__( 'The course builder needs JavaScript. You can still assign lessons to this course from each lesson’s edit screen.', 'deutschlms' )
		);
	}

	/**
	 * Enqueues builder assets on the course edit screen only.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || PostTypes::COURSE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( self::SCRIPT, DLMS_URL . 'assets/admin/course-builder.css', array(), dlms_asset_version( 'assets/admin/course-builder.css' ) );
		wp_enqueue_script(
			self::SCRIPT,
			DLMS_URL . 'assets/admin/course-builder.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ),
			dlms_asset_version( 'assets/admin/course-builder.js' ),
			true
		);
		wp_set_script_translations( self::SCRIPT, 'deutschlms', DLMS_PATH . 'languages' );
	}
}
