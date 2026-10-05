<?php
/**
 * Current course/step context.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which course or step the current post belongs to, for blocks and
 * shortcodes placed without an explicit course.
 */
final class Context {

	/**
	 * Course of the current post: the course itself, or the parent course of a
	 * lesson/topic. 0 elsewhere.
	 *
	 * @return int
	 */
	public static function current_course_id(): int {
		$post = get_post();
		if ( ! $post ) {
			return 0;
		}
		if ( PostTypes::COURSE === $post->post_type ) {
			return (int) $post->ID;
		}
		if ( PostTypes::is_step( $post ) ) {
			return absint( get_post_meta( $post->ID, Meta::COURSE_ID, true ) );
		}
		return 0;
	}

	/**
	 * Current lesson/topic ID, or 0.
	 *
	 * @return int
	 */
	public static function current_step_id(): int {
		$post = get_post();
		return ( $post && PostTypes::is_step( $post ) ) ? (int) $post->ID : 0;
	}
}
