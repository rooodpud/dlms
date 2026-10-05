<?php
/**
 * WordPress test suite configuration.
 *
 * The suite installs WordPress into its OWN database and empties it on every
 * run. Never point DB_NAME at a site's real database.
 *
 * Defaults match the local XAMPP setup; override with environment variables:
 * DLMS_TESTS_ABSPATH, DLMS_TESTS_DB_NAME, DLMS_TESTS_DB_USER,
 * DLMS_TESTS_DB_PASSWORD, DLMS_TESTS_DB_HOST.
 *
 * @package DeutschLMS
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Test bootstrap.

$dlms_env = static function ( string $name, string $fallback ): string {
	$value = getenv( $name );
	return false === $value ? $fallback : $value;
};

// WordPress core of the surrounding install (wp-content/plugins/deutschlms/tests → WP root).
define( 'ABSPATH', rtrim( $dlms_env( 'DLMS_TESTS_ABSPATH', dirname( __DIR__, 4 ) ), '/\\' ) . '/' );

define( 'DB_NAME', $dlms_env( 'DLMS_TESTS_DB_NAME', 'deutschlms_tests' ) );
define( 'DB_USER', $dlms_env( 'DLMS_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $dlms_env( 'DLMS_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', $dlms_env( 'DLMS_TESTS_DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'DeutschLMS Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

if ( 'deutschlms_tests' !== DB_NAME && false === getenv( 'DLMS_TESTS_ALLOW_ANY_DB' ) ) {
	// Guard against wiping a real site by accident.
	fwrite( STDERR, 'Refusing to run: test DB_NAME must be "deutschlms_tests" (set DLMS_TESTS_ALLOW_ANY_DB=1 to override).' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
