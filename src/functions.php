<?php
/**
 * Public template functions for themes and other plugins.
 *
 * @package DeutschLMS
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dlms' ) ) {
	/**
	 * The DeutschLMS plugin instance (service access).
	 *
	 * @return \DeutschLMS\Plugin
	 */
	function dlms(): \DeutschLMS\Plugin {
		return \DeutschLMS\Plugin::instance();
	}
}

/**
 * Whether a user is enrolled in a course.
 *
 * @param int $course_id Course ID.
 * @param int $user_id   User ID (default: current user).
 * @return bool
 */
function dlms_is_enrolled( int $course_id, int $user_id = 0 ): bool {
	return dlms()->enrollments()->is_enrolled( $user_id ? $user_id : get_current_user_id(), $course_id );
}

/**
 * A user's progress in a course.
 *
 * @param int $course_id Course ID.
 * @param int $user_id   User ID (default: current user).
 * @return array{total: int, completed: int, percent: int, is_complete: bool, next_step_id: int}
 */
function dlms_get_course_progress( int $course_id, int $user_id = 0 ): array {
	return dlms()->calculator()->summary( $user_id ? $user_id : get_current_user_id(), $course_id );
}

/**
 * Whether a user has completed a lesson or topic.
 *
 * @param int $step_id Lesson or topic ID.
 * @param int $user_id User ID (default: current user).
 * @return bool
 */
function dlms_is_step_complete( int $step_id, int $user_id = 0 ): bool {
	return dlms()->calculator()->is_step_complete( $user_id ? $user_id : get_current_user_id(), $step_id );
}

/**
 * Whether a user may view a lesson or topic.
 *
 * @param int $step_id Lesson or topic ID.
 * @param int $user_id User ID (default: current user).
 * @return bool
 */
function dlms_can_view_step( int $step_id, int $user_id = 0 ): bool {
	return dlms()->access()->can_view( $user_id ? $user_id : get_current_user_id(), $step_id );
}

/**
 * Renders a DeutschLMS template (theme overrides apply).
 *
 * @param string $template Relative path, e.g. "course/outline.php".
 * @param array  $args     Template data.
 * @return string
 */
function dlms_get_template_html( string $template, array $args = array() ): string {
	return \DeutschLMS\Frontend\Templates::render( $template, $args );
}

/**
 * Prints a DeutschLMS template (theme overrides apply). Templates escape their
 * own output, so this is safe to use from inside other templates.
 *
 * @param string $template Relative path, e.g. "course/progress-bar.php".
 * @param array  $args     Template data.
 */
function dlms_template( string $template, array $args = array() ): void {
	echo \DeutschLMS\Frontend\Templates::render( $template, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their own output.
}
