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

/**
 * An exercise instruction as escaped HTML: German, and its translation in
 * the learner's help language under it (HelpLanguage::instruction()).
 * Keep the gettext call inside: dlms_instruction( __( '…', 'deutschlms' ) ).
 *
 * @param string $translated Text from __(), _x() or _n().
 * @param mixed  ...$args    Values for the placeholders (escaped).
 * @return string
 */
function dlms_instruction( string $translated, ...$args ): string {
	return \DeutschLMS\Frontend\HelpLanguage::instruction( $translated, ...$args );
}

/**
 * Version of a plugin file for its URL (?ver=): the plugin version and the
 * file's modification time, so browsers load a changed file at once
 * instead of an old cached copy with the same plugin version.
 *
 * @param string $path Path inside the plugin, e.g. 'assets/js/frontend.js'.
 * @return string
 */
function dlms_asset_version( string $path ): string {
	$time = @filemtime( DLMS_PATH . $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file just keeps the plugin version.
	return false === $time ? DLMS_VERSION : DLMS_VERSION . '.' . $time;
}

/**
 * A translated DeutschLMS string as escaped HTML, in every help language of
 * the course (see \DeutschLMS\Frontend\HelpLanguage); without a choice of
 * languages simply the escaped text. Keep the gettext call inside, so the
 * string is found for translation: dlms_t( __( 'Next', 'deutschlms' ) ).
 *
 * @param string $translated Text from __(), _x() or _n().
 * @param mixed  ...$args    Values for the placeholders (escaped).
 * @return string
 */
function dlms_t( string $translated, ...$args ): string {
	return \DeutschLMS\Frontend\HelpLanguage::text( $translated, ...$args );
}

/**
 * Prints dlms_t().
 *
 * @param string $translated Text from __(), _x() or _n().
 * @param mixed  ...$args    Values for the placeholders (escaped).
 */
function dlms_e( string $translated, ...$args ): void {
	echo \DeutschLMS\Frontend\HelpLanguage::text( $translated, ...$args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in HelpLanguage::text().
}

/**
 * An attribute (aria-label or title) with a translated DeutschLMS string,
 * kept in the learner's help language: aria-label="…".
 *
 * @param string $name       Attribute.
 * @param string $translated Text from __(), _x() or _n().
 * @param mixed  ...$args    Values for the placeholders.
 * @return string Attribute markup (no leading space).
 */
function dlms_attr( string $name, string $translated, ...$args ): string {
	return \DeutschLMS\Frontend\HelpLanguage::attr( $name, $translated, ...$args );
}

/**
 * A course, lesson, topic or quiz title as escaped HTML, with its
 * translation in the learner's help language.
 *
 * @param int         $post_id Post ID.
 * @param string|null $title   Title (default: the post's).
 * @return string
 */
function dlms_title( int $post_id, ?string $title = null ): string {
	return \DeutschLMS\Frontend\HelpLanguage::title( $post_id, $title );
}
