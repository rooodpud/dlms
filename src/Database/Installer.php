<?php
/**
 * Install and upgrade routine.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Database;

use DeutschLMS\Roles\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Runs schema and role installs and versioned migrations.
 */
final class Installer {

	/**
	 * Installs or upgrades whatever is out of date. Cheap when nothing changed:
	 * two autoloaded options compared against constants.
	 */
	public static function maybe_upgrade(): void {
		$db_version    = (string) get_option( Schema::OPTION, '' );
		$roles_version = (string) get_option( Roles::OPTION, '' );

		$changed = false;

		if ( version_compare( $db_version, Schema::VERSION, '<' ) ) {
			Schema::install();
			Schema::migrate( $db_version );
			update_option( Schema::OPTION, Schema::VERSION, true );
			$changed = true;
		}

		if ( version_compare( $roles_version, Roles::VERSION, '<' ) ) {
			Roles::sync();
			update_option( Roles::OPTION, Roles::VERSION, true );
			$changed = true;
		}

		if ( $changed && did_action( 'init' ) ) {
			// Post types register on init; flush once they exist.
			add_action( 'init', array( self::class, 'flush_rewrites' ), 99 );
		}
	}

	/**
	 * Full install, used on activation.
	 */
	public static function install(): void {
		Schema::install();
		update_option( Schema::OPTION, Schema::VERSION, true );
		Roles::sync();
		update_option( Roles::OPTION, Roles::VERSION, true );
	}

	/**
	 * Flushes rewrite rules (soft flush; no .htaccess write).
	 */
	public static function flush_rewrites(): void {
		flush_rewrite_rules( false );
	}
}
