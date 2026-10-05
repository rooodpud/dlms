<?php
/**
 * Custom table schema.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the custom tables and their versioned migrations.
 *
 * Fresh installs get the current schema straight from dbDelta(). Migrations
 * only run on sites upgrading from an older schema version, for changes
 * dbDelta() can't express (data backfills, column renames).
 */
final class Schema {

	/**
	 * Current schema version. Bump whenever get_schema() or migrations() change.
	 *
	 * 1.1.0: quiz attempts table (Phase 2).
	 * 1.2.0: quiz attempts get `started_at` and `late` (quiz time limits).
	 */
	public const VERSION = '1.2.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const OPTION = 'dlms_db_version';

	/**
	 * Full table name for a short table key.
	 *
	 * @param string $name Short name, e.g. "enrollments".
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'dlms_' . $name;
	}

	/**
	 * Creates or updates tables.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::get_schema() );
	}

	/**
	 * CREATE TABLE statements in dbDelta() format (two spaces after PRIMARY KEY,
	 * one column per line, no IF NOT EXISTS).
	 *
	 * Dates are stored in UTC.
	 *
	 * @return string[]
	 */
	public static function get_schema(): array {
		global $wpdb;

		$collate     = $wpdb->get_charset_collate();
		$enrollments = self::table( 'enrollments' );
		$progress    = self::table( 'progress' );
		$attempts    = self::table( 'quiz_attempts' );

		return array(
			"CREATE TABLE {$enrollments} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  source varchar(32) NOT NULL DEFAULT 'free',
  enrolled_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_course (user_id,course_id),
  KEY course_status (course_id,status)
) {$collate};",
			"CREATE TABLE {$progress} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  step_id bigint(20) unsigned NOT NULL,
  step_type varchar(20) NOT NULL,
  completed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_step (user_id,step_id),
  KEY user_course (user_id,course_id),
  KEY course_id (course_id)
) {$collate};",
			// One row per submitted attempt. `answers` is a JSON snapshot of what
			// was selected and how each question was graded at submission time,
			// so results stay readable after the quiz is edited. A reset sets
			// status to 'reset' instead of deleting the row.
			"CREATE TABLE {$attempts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  quiz_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  score int(10) unsigned NOT NULL DEFAULT 0,
  max_score int(10) unsigned NOT NULL DEFAULT 0,
  percent decimal(5,2) NOT NULL DEFAULT 0.00,
  passed tinyint(1) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'completed',
  answers longtext NOT NULL,
  started_at datetime DEFAULT NULL,
  late tinyint(1) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_quiz (user_id,quiz_id),
  KEY quiz_id (quiz_id),
  KEY course_id (course_id)
) {$collate};",
		);
	}

	/**
	 * Runs migrations newer than the installed version.
	 *
	 * @param string $from Installed schema version ('' on a fresh install).
	 */
	public static function migrate( string $from ): void {
		if ( '' === $from ) {
			return;
		}
		foreach ( self::migrations() as $version => $callback ) {
			if ( version_compare( $from, $version, '<' ) ) {
				call_user_func( $callback );
			}
		}
	}

	/**
	 * Version => callable map, oldest first.
	 *
	 * @return array<string, callable>
	 */
	private static function migrations(): array {
		return array();
	}
}
