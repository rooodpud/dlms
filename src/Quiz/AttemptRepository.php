<?php
/**
 * Quiz attempt storage.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Quiz;

use DeutschLMS\Database\Schema;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `dlms_quiz_attempts` table. All queries are prepared.
 *
 * A user's attempts on a quiz are cached in the object cache (group
 * `dlms_quiz_attempts`) and invalidated on every write.
 */
final class AttemptRepository {

	public const STATUS_COMPLETED = 'completed';
	public const STATUS_RESET     = 'reset';

	private const CACHE_GROUP = 'dlms_quiz_attempts';

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
	 * Stores an attempt.
	 *
	 * @param array $data { user_id, quiz_id, course_id, score, max_score, percent, passed, answers (array),
	 *                    started_at (Unix time or null; timed quizzes), late (bool) }.
	 * @return int Attempt ID (0 on failure).
	 */
	public function insert( array $data ): int {
		$inserted = $this->db->insert(
			Schema::table( 'quiz_attempts' ),
			array(
				'user_id'    => (int) $data['user_id'],
				'quiz_id'    => (int) $data['quiz_id'],
				'course_id'  => (int) $data['course_id'],
				'score'      => (int) $data['score'],
				'max_score'  => (int) $data['max_score'],
				'percent'    => (float) $data['percent'],
				'passed'     => $data['passed'] ? 1 : 0,
				'status'     => self::STATUS_COMPLETED,
				'answers'    => (string) wp_json_encode( $data['answers'] ),
				'started_at' => empty( $data['started_at'] ) ? null : gmdate( 'Y-m-d H:i:s', (int) $data['started_at'] ),
				'late'       => empty( $data['late'] ) ? 0 : 1,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		$this->forget( (int) $data['user_id'], (int) $data['quiz_id'] );
		return $inserted ? (int) $this->db->insert_id : 0;
	}

	/**
	 * A user's counted (not reset) attempts on a quiz, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Canonical quiz ID.
	 * @return array[]
	 */
	public function for_user_quiz( int $user_id, int $quiz_id ): array {
		if ( $user_id <= 0 || $quiz_id <= 0 ) {
			return array();
		}

		$key    = $user_id . ':' . $quiz_id;
		$found  = false;
		$cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND quiz_id = %d AND status = %s ORDER BY created_at DESC, id DESC',
				Schema::table( 'quiz_attempts' ),
				$user_id,
				$quiz_id,
				self::STATUS_COMPLETED
			),
			ARRAY_A
		);

		$rows = array_map( array( $this, 'hydrate' ), (array) $rows );
		wp_cache_set( $key, $rows, self::CACHE_GROUP );
		return $rows;
	}

	/**
	 * Every attempt of a user (incl. reset ones), newest first. Admin views only.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	public function for_user( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT 500',
				Schema::table( 'quiz_attempts' ),
				$user_id
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Per-student summary of counted attempts on a quiz, most recent first.
	 *
	 * @param int $quiz_id Canonical quiz ID.
	 * @return array[] { user_id, attempts, best_percent, passed, last_at }.
	 */
	public function summary_for_quiz( int $quiz_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT user_id, COUNT(*) AS attempts, MAX(percent) AS best_percent, MAX(passed) AS passed, MAX(created_at) AS last_at
				FROM %i WHERE quiz_id = %d AND status = %s
				GROUP BY user_id ORDER BY last_at DESC LIMIT 200',
				Schema::table( 'quiz_attempts' ),
				$quiz_id,
				self::STATUS_COMPLETED
			),
			ARRAY_A
		);
		return array_map(
			static fn( array $row ): array => array(
				'user_id'      => (int) $row['user_id'],
				'attempts'     => (int) $row['attempts'],
				'best_percent' => (float) $row['best_percent'],
				'passed'       => (bool) (int) $row['passed'],
				'last_at'      => (string) $row['last_at'],
			),
			(array) $rows
		);
	}

	/**
	 * One attempt by ID.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @return array|null
	 */
	public function find( int $attempt_id ): ?array {
		if ( $attempt_id <= 0 ) {
			return null;
		}
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'quiz_attempts' ), $attempt_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Marks a user's attempts on a quiz as reset, so they no longer count
	 * toward the attempts limit. Rows are kept for the record.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Canonical quiz ID.
	 * @return int Number of attempts reset.
	 */
	public function reset( int $user_id, int $quiz_id ): int {
		$updated = $this->db->query(
			$this->db->prepare(
				'UPDATE %i SET status = %s WHERE user_id = %d AND quiz_id = %d AND status = %s',
				Schema::table( 'quiz_attempts' ),
				self::STATUS_RESET,
				$user_id,
				$quiz_id,
				self::STATUS_COMPLETED
			)
		);
		$this->forget( $user_id, $quiz_id );
		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Drops cached attempts.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Canonical quiz ID.
	 */
	public function forget( int $user_id, int $quiz_id ): void {
		wp_cache_delete( $user_id . ':' . $quiz_id, self::CACHE_GROUP );
	}

	/**
	 * Casts a raw row and decodes the answers snapshot.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		foreach ( array( 'id', 'user_id', 'quiz_id', 'course_id', 'score', 'max_score' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['percent'] = (float) $row['percent'];
		$row['passed']  = (bool) (int) $row['passed'];
		$row['late']    = (bool) (int) ( $row['late'] ?? 0 );
		$answers        = json_decode( (string) $row['answers'], true );
		$row['answers'] = is_array( $answers ) ? $answers : array();
		return $row;
	}
}
