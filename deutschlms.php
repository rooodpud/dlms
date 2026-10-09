<?php
/**
 * Plugin Name:       DeutschLMS
 * Description:       A secure learning management system: courses, lessons, topics, enrollment and progress tracking.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Pradeep Hingorani
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deutschlms
 * Domain Path:       /languages
 *
 * @package DeutschLMS
 */

defined( 'ABSPATH' ) || exit;

define( 'DLMS_VERSION', '0.4.0' );
define( 'DLMS_FILE', __FILE__ );
define( 'DLMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DLMS_URL', plugin_dir_url( __FILE__ ) );
define( 'DLMS_MIN_PHP', '8.1' );
define( 'DLMS_MIN_WP', '6.5' );

if ( version_compare( PHP_VERSION, DLMS_MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), DLMS_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
						__( 'DeutschLMS requires PHP %1$s and WordPress %2$s or newer. The plugin is inactive until the server is upgraded.', 'deutschlms' ),
						DLMS_MIN_PHP,
						DLMS_MIN_WP
					)
				)
			);
		}
	);
	return;
}//end if

if ( ! is_readable( DLMS_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'DeutschLMS is missing its autoloader. Run "composer install" in the plugin folder.', 'deutschlms' )
			);
		}
	);
	return;
}

require_once DLMS_PATH . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \DeutschLMS\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \DeutschLMS\Lifecycle::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \DeutschLMS\Plugin::instance(), 'boot' ) );
