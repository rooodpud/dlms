<?php
/**
 * Access decision value object.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Access;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of an access check, with the reason, so the front end can show
 * the right message ("log in", "enroll", "finish X first", "opens on …").
 */
final class AccessResult {

	/**
	 * Constructor.
	 *
	 * @param bool   $allowed          Whether access is granted.
	 * @param string $reason           One of the AccessControl reason constants.
	 * @param int    $course_id        Course the step belongs to (0 if none).
	 * @param int    $blocking_step_id First unfinished prerequisite (linear courses).
	 * @param int    $available_at     Unix time the step unlocks (drip), 0 if n/a.
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly string $reason,
		public readonly int $course_id = 0,
		public readonly int $blocking_step_id = 0,
		public readonly int $available_at = 0
	) {
	}
}
