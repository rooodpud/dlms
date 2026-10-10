<?php
/**
 * Course order and levels.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Content;

defined( 'ABSPATH' ) || exit;

/**
 * The order of courses in the course grid and their level groups.
 *
 * The position is the course's `menu_order` (set on Courses → Arrange courses);
 * the level is the `_dlms_level` meta. Both are optional: courses without a
 * position sort by title, courses without a level go in a last group.
 */
final class CourseOrder {

	/**
	 * Levels a course can have, in the order of the group headings.
	 */
	public const LEVELS = array( 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' );

	/**
	 * Gap between the positions written by the arrange screen, so a course can
	 * later be slipped in between by hand.
	 */
	public const STEP = 10;

	/**
	 * Cleans a level value.
	 *
	 * @param mixed $level Raw value.
	 * @return string One of LEVELS, or '' for no level.
	 */
	public static function sanitize_level( $level ): string {
		$level = strtoupper( trim( (string) $level ) );
		return in_array( $level, self::LEVELS, true ) ? $level : '';
	}

	/**
	 * The level of a course.
	 *
	 * @param int $course_id Course ID.
	 * @return string One of LEVELS, or '' when none is set.
	 */
	public static function level_for( int $course_id ): string {
		return self::sanitize_level( get_post_meta( $course_id, Meta::LEVEL, true ) );
	}

	/**
	 * Splits an ordered list of courses into level groups. Groups follow the
	 * order of LEVELS; courses without a level come last. Within a group the
	 * given order is kept.
	 *
	 * @param array<int, array> $courses Courses, each with a 'level' key.
	 * @return array<int, array{level: string, courses: array<int, array>}>
	 */
	public static function group_by_level( array $courses ): array {
		$buckets = array();
		foreach ( $courses as $course ) {
			$level               = self::sanitize_level( $course['level'] ?? '' );
			$buckets[ $level ][] = $course;
		}

		$groups = array();
		foreach ( array_merge( self::LEVELS, array( '' ) ) as $level ) {
			if ( ! empty( $buckets[ $level ] ) ) {
				$groups[] = array(
					'level'   => $level,
					'courses' => $buckets[ $level ],
				);
			}
		}
		return $groups;
	}
}
