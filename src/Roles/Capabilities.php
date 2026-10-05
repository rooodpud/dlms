<?php
/**
 * Meta capability mapping.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Roles;

use DeutschLMS\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the plugin's meta capabilities onto primitive ones:
 *
 * - `dlms_enroll_course` ( $course_id ): self-enroll in a free course.
 * - `dlms_complete_step` ( $step_id ): record one's own progress on a lesson/topic.
 *
 * These checks are the "may this kind of user do this" gate. Business rules
 * (already enrolled, linear progression, ...) live in the services.
 */
final class Capabilities {

	public const ENROLL_COURSE = 'dlms_enroll_course';
	public const COMPLETE_STEP = 'dlms_complete_step';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Maps meta capabilities.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Extra arguments (object ID first).
	 * @return string[]
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::ENROLL_COURSE !== $cap && self::COMPLETE_STEP !== $cap ) {
			return $caps;
		}

		$object_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( ! $user_id || ! $object_id ) {
			return array( 'do_not_allow' );
		}

		if ( self::ENROLL_COURSE === $cap ) {
			return self::is_enrollable( $object_id ) ? array( Roles::CAP_TAKE_COURSES ) : array( 'do_not_allow' );
		}

		$step = get_post( $object_id );
		if ( ! $step || ! PostTypes::is_step( $step ) ) {
			return array( 'do_not_allow' );
		}
		return array( Roles::CAP_TAKE_COURSES );
	}

	/**
	 * Whether a course accepts free self-enrollment.
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public static function is_enrollable( int $course_id ): bool {
		$course     = get_post( $course_id );
		$enrollable = $course
			&& PostTypes::COURSE === $course->post_type
			&& 'publish' === $course->post_status;

		/**
		 * Filters whether a course accepts free self-enrollment.
		 *
		 * @param bool $enrollable Default: course exists and is published.
		 * @param int  $course_id  Course ID.
		 */
		return (bool) apply_filters( 'dlms_course_is_enrollable', $enrollable, $course_id );
	}
}
