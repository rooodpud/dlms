<?php
/**
 * Certificate download.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Certificates;

defined( 'ABSPATH' ) || exit;

/**
 * Serves certificate PDFs at admin-post.php?action=dlms_certificate&course_id=…
 * (and &user_id=… for managers). Read-only, so no nonce; access is decided by
 * CertificateService::can_view() on every request.
 */
final class CertificateController {

	public const ACTION = 'dlms_certificate';

	/**
	 * Certificate service.
	 *
	 * @var CertificateService
	 */
	private CertificateService $certificates;

	/**
	 * Constructor.
	 *
	 * @param CertificateService $certificates Certificate service.
	 */
	public function __construct( CertificateService $certificates ) {
		$this->certificates = $certificates;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'login_first' ) );
	}

	/**
	 * Streams the PDF.
	 */
	public function download(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only download; authorization below.
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		$user_id   = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		// phpcs:enable

		$viewer_id = get_current_user_id();
		$user_id   = $user_id ? $user_id : $viewer_id;

		if ( ! $this->certificates->can_view( $viewer_id, $user_id, $course_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view this certificate.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}

		$pdf = $this->certificates->pdf( $user_id, $course_id );
		if ( is_wp_error( $pdf ) ) {
			$data = $pdf->get_error_data();
			wp_die( esc_html( $pdf->get_error_message() ), '', array( 'response' => (int) ( $data['status'] ?? 400 ) ) );
		}

		$filename = sanitize_file_name( sprintf( 'certificate-%s.pdf', get_post_field( 'post_name', $course_id ) ) );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
		exit;
	}

	/**
	 * Visitors log in first and come back.
	 */
	public function login_first(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only used to build the return URL.
		$course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		wp_safe_redirect( wp_login_url( $this->certificates->url( $course_id ) ) );
		exit;
	}
}
