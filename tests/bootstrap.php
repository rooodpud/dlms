<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with DeutschLMS active.
 *
 * @package DeutschLMS
 */

$dlms_root = dirname( __DIR__ );

require_once $dlms_root . '/tools/dev/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required by wp-phpunit.

$dlms_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $dlms_tests_dir ) {
	$dlms_tests_dir = $dlms_root . '/tools/dev/vendor/wp-phpunit/wp-phpunit';
}

require_once $dlms_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $dlms_root ) {
		require $dlms_root . '/deutschlms.php';
	}
);

// Activation doesn't run in tests; install tables and roles once WordPress is up.
tests_add_filter(
	'init',
	static function () {
		\DeutschLMS\Database\Installer::install();
	},
	0
);

require $dlms_tests_dir . '/includes/bootstrap.php';
