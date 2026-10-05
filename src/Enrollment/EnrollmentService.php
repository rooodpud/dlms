<?php
/**
 * Enrollment rules.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Enrollment;

use DeutschLMS\Content\PostTypes;
use DeutschLMS\Integrations\Multilingual;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Enrollment business rules. Callers check capabilities (`dlms_enroll_course`)
 * before calling enroll(); this class validates the data.
 */
final class EnrollmentService {

	/**
	 * Storage.
	 *
	 * @var EnrollmentRepository
	 */
	private EnrollmentRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository $repository Storage.
	 */
	public function __construct( EnrollmentRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Whether a user is enrolled (active or completed).
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID (any language).
	 * @return bool
	 */
	public function is_enrolled( int $user_id, int $course_id ): bool {
		return null !== $this->get( $user_id, $course_id );
	}

	/**
	 * A user's enrollment row.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID (any language).
	 * @return array|null
	 */
	public function get( int $user_id, int $course_id ): ?array {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return null;
		}
		return $this->repository->find( $user_id, $this->canonical( $course_id ) );
	}

	/**
	 * A user's enrollments, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array> Keyed by canonical course ID.
	 */
	public function for_user( int $user_id ): array {
		return $this->repository->for_user( $user_id );
	}

	/**
	 * Enrolls a user. Idempotent: enrolling twice returns the existing row.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID (any language).
	 * @param string $source    Enrollment source.
	 * @return array|WP_Error { enrollment: array, created: bool }
	 */
	public function enroll( int $user_id, int $course_id, string $source = 'free' ) {
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'dlms_invalid_user', __( 'Invalid user.', 'deutschlms' ), array( 'status' => 400 ) );
		}

		$course = get_post( $course_id );
		if ( ! $course instanceof WP_Post || PostTypes::COURSE !== $course->post_type ) {
			return new WP_Error( 'dlms_invalid_course', __( 'Invalid course.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		if ( 'publish' !== $course->post_status ) {
			return new WP_Error( 'dlms_course_unavailable', __( 'This course is not open for enrollment.', 'deutschlms' ), array( 'status' => 403 ) );
		}

		$canonical = $this->canonical( $course_id );
		$source    = substr( sanitize_key( $source ), 0, 32 );
		$created   = $this->repository->insert( $user_id, $canonical, '' !== $source ? $source : 'free' );

		$enrollment = $this->repository->find( $user_id, $canonical );
		if ( null === $enrollment ) {
			return new WP_Error( 'dlms_enroll_failed', __( 'Enrollment could not be saved. Please try again.', 'deutschlms' ), array( 'status' => 500 ) );
		}

		if ( $created ) {
			/**
			 * Fires after a user was enrolled in a course.
			 *
			 * @param int    $user_id   User ID.
			 * @param int    $course_id Canonical course ID.
			 * @param string $source    Enrollment source.
			 */
			do_action( 'dlms_user_enrolled', $user_id, $canonical, $enrollment['source'] );
		}

		return array(
			'enrollment' => $enrollment,
			'created'    => $created,
		);
	}

	/**
	 * Marks a course as completed for a user (once).
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID (any language).
	 * @return bool True if the status changed now.
	 */
	public function mark_completed( int $user_id, int $course_id ): bool {
		$canonical = $this->canonical( $course_id );
		$changed   = $this->repository->mark_completed( $user_id, $canonical );

		if ( $changed ) {
			/**
			 * Fires once when a user completes a course.
			 *
			 * @param int $user_id   User ID.
			 * @param int $course_id Canonical course ID.
			 */
			do_action( 'dlms_course_completed', $user_id, $canonical );
		}

		return $changed;
	}

	/**
	 * Clears a user's cached rows.
	 *
	 * @param int $user_id User ID.
	 */
	public function forget( int $user_id ): void {
		$this->repository->forget( $user_id );
	}

	/**
	 * Canonical (default-language) course ID.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	private function canonical( int $course_id ): int {
		return Multilingual::canonical_id( $course_id, PostTypes::COURSE );
	}
}
