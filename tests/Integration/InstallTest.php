<?php
/**
 * Install / roles tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Database\Schema;
use DeutschLMS\Roles\Roles;

/**
 * @covers \DeutschLMS\Database\Schema
 * @covers \DeutschLMS\Database\Installer
 * @covers \DeutschLMS\Roles\Roles
 */
final class InstallTest extends TestCase {

	public function test_tables_exist_and_version_is_stored(): void {
		global $wpdb;

		foreach ( array( 'enrollments', 'progress' ) as $table ) {
			$name = Schema::table( $table );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}

	public function test_roles_have_expected_capabilities(): void {
		$student    = get_role( Roles::STUDENT );
		$instructor = get_role( Roles::INSTRUCTOR );
		$lms_admin  = get_role( Roles::LMS_ADMIN );

		$this->assertNotNull( $student );
		$this->assertTrue( $student->has_cap( Roles::CAP_TAKE_COURSES ) );
		$this->assertFalse( $student->has_cap( 'edit_dlms_courses' ) );

		$this->assertTrue( $instructor->has_cap( 'edit_dlms_courses' ) );
		$this->assertTrue( $instructor->has_cap( 'publish_dlms_lessons' ) );
		$this->assertFalse( $instructor->has_cap( 'edit_others_dlms_courses' ) );
		$this->assertFalse( $instructor->has_cap( 'manage_options' ) );

		$this->assertTrue( $lms_admin->has_cap( 'edit_others_dlms_topics' ) );
		$this->assertTrue( $lms_admin->has_cap( Roles::CAP_MANAGE ) );
		$this->assertFalse( $lms_admin->has_cap( 'manage_options' ) );

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'delete_others_dlms_courses' ) );
		$this->assertTrue( get_role( 'subscriber' )->has_cap( Roles::CAP_TAKE_COURSES ) );
	}

	public function test_install_is_idempotent(): void {
		\DeutschLMS\Database\Installer::install();
		\DeutschLMS\Database\Installer::install();

		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}
}
