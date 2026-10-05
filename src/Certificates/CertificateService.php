<?php
/**
 * Course completion certificates.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Certificates;

use DeutschLMS\Access\AccessControl;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Enrollment\EnrollmentRepository;
use DeutschLMS\Enrollment\EnrollmentService;
use DeutschLMS\Frontend\Dates;
use DeutschLMS\Frontend\Templates;
use DeutschLMS\Roles\Roles;
use Dompdf\Dompdf;
use Dompdf\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds certificate PDFs on demand when a student has completed a course.
 *
 * Nothing is stored: the PDF is rendered from the template each time it is
 * requested, so no personal documents sit in the (public) uploads folder. The
 * certificate number is derived from the enrollment and a site secret, so it
 * is stable and can't be guessed.
 */
final class CertificateService {

	/**
	 * Enrollment service.
	 *
	 * @var EnrollmentService
	 */
	private EnrollmentService $enrollments;

	/**
	 * Access control.
	 *
	 * @var AccessControl
	 */
	private AccessControl $access;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentService $enrollments Enrollment service.
	 * @param AccessControl     $access      Access control.
	 */
	public function __construct( EnrollmentService $enrollments, AccessControl $access ) {
		$this->enrollments = $enrollments;
		$this->access      = $access;
	}

	/**
	 * Whether a course awards a certificate.
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public function is_enabled( int $course_id ): bool {
		return PostTypes::COURSE === get_post_type( $course_id ) && (bool) get_post_meta( $course_id, Meta::CERT_ENABLED, true );
	}

	/**
	 * Whether a user has earned the certificate of a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public function is_earned( int $user_id, int $course_id ): bool {
		$enrollment = $this->enrollments->get( $user_id, $course_id );
		return $this->is_enabled( $course_id )
			&& null !== $enrollment
			&& EnrollmentRepository::STATUS_COMPLETED === $enrollment['status'];
	}

	/**
	 * Whether a viewer may open a user's certificate: their own, or they manage
	 * the course or enrollments.
	 *
	 * @param int $viewer_id Viewing user.
	 * @param int $user_id   Certificate holder.
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public function can_view( int $viewer_id, int $user_id, int $course_id ): bool {
		if ( $viewer_id <= 0 ) {
			return false;
		}
		return $viewer_id === $user_id
			|| $this->access->can_manage_course( $viewer_id, $course_id )
			|| user_can( $viewer_id, Roles::CAP_MANAGE_ENROLLMENTS );
	}

	/**
	 * Download link (the holder defaults to the current user).
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   Certificate holder (0 = viewer).
	 * @return string
	 */
	public function url( int $course_id, int $user_id = 0 ): string {
		$args = array(
			'action'    => CertificateController::ACTION,
			'course_id' => $course_id,
		);
		if ( $user_id ) {
			$args['user_id'] = $user_id;
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Certificate number, e.g. "DLMS-000042-7F3A9C".
	 *
	 * @param array $enrollment Enrollment row.
	 * @return string
	 */
	public function number( array $enrollment ): string {
		$hash = hash_hmac( 'sha256', $enrollment['id'] . '|' . $enrollment['user_id'] . '|' . $enrollment['course_id'], wp_salt( 'auth' ) );
		return sprintf( 'DLMS-%06d-%s', $enrollment['id'], strtoupper( substr( $hash, 0, 6 ) ) );
	}

	/**
	 * Data for the certificate template.
	 *
	 * @param int $user_id   Certificate holder.
	 * @param int $course_id Course ID.
	 * @return array|WP_Error
	 */
	public function data( int $user_id, int $course_id ) {
		if ( ! $this->is_enabled( $course_id ) ) {
			return new WP_Error( 'dlms_no_certificate', __( 'This course does not award a certificate.', 'deutschlms' ), array( 'status' => 404 ) );
		}
		$user       = get_userdata( $user_id );
		$enrollment = $this->enrollments->get( $user_id, $course_id );
		if ( ! $user || null === $enrollment || EnrollmentRepository::STATUS_COMPLETED !== $enrollment['status'] ) {
			return new WP_Error( 'dlms_certificate_not_earned', __( 'The certificate is available once the course is completed.', 'deutschlms' ), array( 'status' => 403 ) );
		}

		$completed = strtotime( (string) $enrollment['completed_at'] . ' UTC' );
		$title     = (string) get_post_meta( $course_id, Meta::CERT_TITLE, true );

		/**
		 * Filters the certificate template data.
		 *
		 * @param array $data      Template data.
		 * @param int   $user_id   Certificate holder.
		 * @param int   $course_id Course ID.
		 */
		return (array) apply_filters(
			'dlms_certificate_data',
			array(
				'title'              => '' !== $title ? $title : __( 'Certificate of Completion', 'deutschlms' ),
				'student_name'       => $user->display_name,
				'course_title'       => wp_strip_all_tags( get_the_title( $course_id ) ),
				'completion_date'    => $completed ? Dates::format( $completed ) : '',
				'certificate_number' => $this->number( $enrollment ),
				'signer'             => (string) get_post_meta( $course_id, Meta::CERT_SIGNER, true ),
				'site_name'          => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'background_path'    => $this->background_path( $course_id ),
			),
			$user_id,
			$course_id
		);
	}

	/**
	 * Certificate HTML (what the PDF is rendered from).
	 *
	 * @param array $data Template data.
	 * @return string
	 */
	public function html( array $data ): string {
		return Templates::render( 'certificate/certificate.php', $data );
	}

	/**
	 * Renders the certificate PDF.
	 *
	 * @param int $user_id   Certificate holder.
	 * @param int $course_id Course ID.
	 * @return string|WP_Error PDF bytes.
	 */
	public function pdf( int $user_id, int $course_id ) {
		$data = $this->data( $user_id, $course_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$uploads = wp_get_upload_dir();
		$cache   = trailingslashit( get_temp_dir() ) . 'deutschlms-dompdf';
		wp_mkdir_p( $cache );

		$options = new Options();
		// No network access, no PHP or JavaScript in templates, and file access
		// limited to the plugin and the uploads folder (background images).
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setIsJavascriptEnabled( false );
		$options->setChroot( array( DLMS_PATH, $uploads['basedir'] ) );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setTempDir( $cache );
		$options->setFontCache( $cache );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $this->html( $data ), 'UTF-8' );
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->render();

		return (string) $dompdf->output();
	}

	/**
	 * Absolute path of the course's background image, if it is a local image
	 * in the uploads folder ('' otherwise).
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	private function background_path( int $course_id ): string {
		$attachment_id = absint( get_post_meta( $course_id, Meta::CERT_BACKGROUND, true ) );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}

		$path    = (string) get_attached_file( $attachment_id );
		$uploads = wp_get_upload_dir();
		$real    = $path ? realpath( $path ) : false;
		$base    = realpath( $uploads['basedir'] );
		if ( ! $real || ! $base || ! str_starts_with( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) ) ) {
			return '';
		}
		return wp_normalize_path( $real );
	}
}
