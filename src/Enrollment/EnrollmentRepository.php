<?php
/**
 * Enrollment storage.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Enrollment;

use DeutschLMS\Database\Schema;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `dlms_enrollments` table. All queries are prepared.
 *
 * Rows are cached per user in the object cache (group `dlms_enrollments`) and
 * invalidated on every write.
 */
final class EnrollmentRepository {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_COMPLETED = 'completed';

	private const CACHE_GROUP = 'dlms_enrollments';

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
	 * A user's enrollment in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Canonical course ID.
	 * @return array|null
	 */
	public function find( int $user_id, int $course_id ): ?array {
		$rows = $this->for_user( $user_id );
		return $rows[ $course_id ] ?? null;
	}

	/**
	 * All of a user's enrollments, newest first, keyed by course ID.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array>
	 */
	public function for_user( int $user_id ): array {
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
				'SELECT * FROM %i WHERE user_id = %d ORDER BY enrolled_at DESC, id DESC',
				Schema::table( 'enrollments' ),
				$user_id
			),
			ARRAY_A
		);

		$result = array();
		foreach ( (array) $rows as $row ) {
			$row                         = $this->hydrate( $row );
			$result[ $row['course_id'] ] = $row;
		}

		wp_cache_set( $user_id, $result, self::CACHE_GROUP );
		return $result;
	}

	/**
	 * Creates an enrollment. Race-safe: the unique (user_id, course_id) key makes
	 * a duplicate insert a no-op.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Canonical course ID.
	 * @param string $source    How the user was enrolled ("free", ...).
	 * @return bool True if a new row was created.
	 */
	public function insert( int $user_id, int $course_id, string $source ): bool {
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$inserted = $this->db->query(
			$this->db->prepare(
				'INSERT IGNORE INTO %i (user_id, course_id, status, source, enrolled_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s)',
				Schema::table( 'enrollments' ),
				$user_id,
				$course_id,
				self::STATUS_ACTIVE,
				$source,
				$now,
				$now
			)
		);

		wp_cache_delete( $user_id, self::CACHE_GROUP );
		return is_int( $inserted ) && $inserted > 0;
	}

	/**
	 * Marks an active enrollment as completed.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Canonical course ID.
	 * @return bool True if the row changed.
	 */
	public function mark_completed( int $user_id, int $course_id ): bool {
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$updated = $this->db->query(
			$this->db->prepare(
				'UPDATE %i SET status = %s, completed_at = %s, updated_at = %s WHERE user_id = %d AND course_id = %d AND status = %s',
				Schema::table( 'enrollments' ),
				self::STATUS_COMPLETED,
				$now,
				$now,
				$user_id,
				$course_id,
				self::STATUS_ACTIVE
			)
		);

		wp_cache_delete( $user_id, self::CACHE_GROUP );
		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Drops a user's cached rows.
	 *
	 * @param int $user_id User ID.
	 */
	public function forget( int $user_id ): void {
		wp_cache_delete( $user_id, self::CACHE_GROUP );
	}

	/**
	 * Casts a raw row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		$row['id']        = (int) $row['id'];
		$row['user_id']   = (int) $row['user_id'];
		$row['course_id'] = (int) $row['course_id'];
		return $row;
	}
}
