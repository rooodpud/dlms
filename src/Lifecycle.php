<?php
/**
 * Activation and deactivation.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Database\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin lifecycle callbacks.
 */
final class Lifecycle {

	/**
	 * Activation: create tables and roles, register post types and flush rewrites.
	 *
	 * On a network-wide activation every site installs lazily on its next request
	 * (see Installer::maybe_upgrade()), so huge networks don't time out here.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			return;
		}

		Installer::install();
		( new PostTypes() )->register();
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation: only flush rewrites. Roles, tables and data are kept, so users
	 * keep their LMS roles and progress if the plugin is reactivated.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}
}
