<?php
/**
 * Progress storage.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Progress;

use DeutschLMS\Database\Schema;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `dlms_progress` table: one row per completed step.
 *
 * A user's completed steps are cached in the object cache (group
 * `dlms_progress`) and invalidated on every write.
 */
final class ProgressRepository {

	private const CACHE_GROUP = 'dlms_progress';

	/**
	 * Database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db Database handle.
	 */
	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * A user's completed steps.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, string> Canonical step ID => completed_at (UTC).
	 */
	public function completed_steps( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$found  = false;
		$cached = wp_cache_get( $user_id, self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; cached above.
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT step_id, completed_at FROM %i WHERE user_id = %d',
				Schema::table( 'progress' ),
				$user_id
			),
			ARRAY_A
		);

		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[ (int) $row['step_id'] ] = (string) $row['completed_at'];
		}

		wp_cache_set( $user_id, $result, self::CACHE_GROUP );
		return $result;
	}

	/**
	 * Records a completed step. Race-safe and idempotent through the unique
	 * (user_id, step_id) key.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Canonical course ID.
	 * @param int    $step_id   Canonical step ID.
	 * @param string $step_type Post type of the step.
	 * @return bool True if a new row was created.
	 */
	public function record( int $user_id, int $course_id, int $step_id, string $step_type ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$inserted = $this->db->query(
			$this->db->prepare(
				'INSERT IGNORE INTO %i (user_id, course_id, step_id, step_type, completed_at) VALUES (%d, %d, %d, %s, %s)',
				Schema::table( 'progress' ),
				$user_id,
				$course_id,
				$step_id,
				$step_type,
				current_time( 'mysql', true )
			)
		);

		wp_cache_delete( $user_id, self::CACHE_GROUP );
		return is_int( $inserted ) && $inserted > 0;
	}
}
