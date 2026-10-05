<?php
/**
 * Role and capability definitions.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Roles;

use DeutschLMS\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the Student, Instructor and LMS Admin roles and their capabilities.
 */
final class Roles {

	/**
	 * Bump when role definitions change so sync() re-runs.
	 *
	 * 2: quiz capabilities (Phase 2).
	 * 3: question bank capabilities.
	 */
	public const VERSION = '3';

	/**
	 * Option holding the synced roles version.
	 */
	public const OPTION = 'dlms_roles_version';

	public const STUDENT    = 'dlms_student';
	public const INSTRUCTOR = 'dlms_instructor';
	public const LMS_ADMIN  = 'dlms_lms_admin';

	/**
	 * Enroll in free courses and record one's own progress.
	 */
	public const CAP_TAKE_COURSES = 'dlms_take_courses';

	/**
	 * Manage LMS-wide settings (used from Phase 2 on).
	 */
	public const CAP_MANAGE = 'dlms_manage_lms';

	/**
	 * Enroll or unenroll other users.
	 */
	public const CAP_MANAGE_ENROLLMENTS = 'dlms_manage_enrollments';

	/**
	 * View LMS reports.
	 */
	public const CAP_VIEW_REPORTS = 'dlms_view_reports';

	/**
	 * Core roles that may take courses. Filterable via `dlms_learner_roles`.
	 *
	 * @return string[]
	 */
	public static function learner_roles(): array {
		return (array) apply_filters( 'dlms_learner_roles', array( 'subscriber', 'contributor', 'author', 'editor' ) );
	}

	/**
	 * Primitive capabilities for a content post type.
	 *
	 * @param string $plural    Plural capability type, e.g. "dlms_courses".
	 * @param bool   $others    Include capabilities over other users' posts.
	 * @return string[]
	 */
	public static function content_caps( string $plural, bool $others ): array {
		$caps = array(
			"edit_{$plural}",
			"edit_published_{$plural}",
			"publish_{$plural}",
			"delete_{$plural}",
			"delete_published_{$plural}",
		);
		if ( $others ) {
			$caps = array_merge(
				$caps,
				array(
					"edit_others_{$plural}",
					"edit_private_{$plural}",
					"read_private_{$plural}",
					"delete_others_{$plural}",
					"delete_private_{$plural}",
				)
			);
		}
		return $caps;
	}

	/**
	 * All content capabilities across course, lesson and topic types.
	 *
	 * @param bool $others Include capabilities over other users' posts.
	 * @return string[]
	 */
	public static function all_content_caps( bool $others ): array {
		$caps = array();
		foreach ( PostTypes::capability_types() as $plural ) {
			$caps = array_merge( $caps, self::content_caps( $plural, $others ) );
		}
		return $caps;
	}

	/**
	 * Role definitions: role => [ display name, capabilities ].
	 *
	 * Display names are stored untranslated, like core role names.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function definitions(): array {
		return array(
			self::STUDENT    => array(
				'Student',
				array( 'read', self::CAP_TAKE_COURSES ),
			),
			self::INSTRUCTOR => array(
				'Instructor',
				array_merge(
					array( 'read', 'upload_files', self::CAP_TAKE_COURSES ),
					self::all_content_caps( false )
				),
			),
			self::LMS_ADMIN  => array(
				'LMS Admin',
				array_merge(
					array(
						'read',
						'upload_files',
						self::CAP_TAKE_COURSES,
						self::CAP_MANAGE,
						self::CAP_MANAGE_ENROLLMENTS,
						self::CAP_VIEW_REPORTS,
					),
					self::all_content_caps( true )
				),
			),
		);
	}

	/**
	 * Every capability the plugin defines (granted to administrators).
	 *
	 * @return string[]
	 */
	public static function all_caps(): array {
		return array_merge(
			array( self::CAP_TAKE_COURSES, self::CAP_MANAGE, self::CAP_MANAGE_ENROLLMENTS, self::CAP_VIEW_REPORTS ),
			self::all_content_caps( true )
		);
	}

	/**
	 * Creates the roles and grants capabilities. Additive and idempotent: never
	 * removes a capability, so manual changes by a site owner survive.
	 */
	public static function sync(): void {
		foreach ( self::definitions() as $role_name => $definition ) {
			list( $label, $caps ) = $definition;
			$role                 = get_role( $role_name );
			if ( null === $role ) {
				add_role( $role_name, $label, array_fill_keys( $caps, true ) );
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}

		$administrator = get_role( 'administrator' );
		if ( null !== $administrator ) {
			foreach ( self::all_caps() as $cap ) {
				$administrator->add_cap( $cap );
			}
		}

		foreach ( self::learner_roles() as $role_name ) {
			$role = get_role( $role_name );
			if ( null !== $role ) {
				$role->add_cap( self::CAP_TAKE_COURSES );
			}
		}
	}
}
