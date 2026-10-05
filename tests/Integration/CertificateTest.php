<?php
/**
 * Certificate tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\Meta;
use DeutschLMS\Roles\Roles;
use WP_Error;

/**
 * @covers \DeutschLMS\Certificates\CertificateService
 */
final class CertificateTest extends TestCase {

	/**
	 * A course with a certificate, and a student who completed it.
	 *
	 * @return array{course: int, student: int}
	 */
	private function completed_course(): array {
		$course = $this->create_course( array( 'title' => 'Deutsch A1' ) );
		update_post_meta( $course, Meta::CERT_ENABLED, true );
		update_post_meta( $course, Meta::CERT_SIGNER, 'Frau Müller' );
		$lesson  = $this->create_lesson( $course, 1 );
		$student = $this->enrolled_student( $course );
		wp_update_user(
			array(
				'ID'           => $student,
				'display_name' => 'Jürgen Groß',
			)
		);
		$this->lms()->progress()->complete_step( $student, $lesson );
		return compact( 'course', 'student' );
	}

	public function test_certificate_is_earned_only_after_completion(): void {
		$course = $this->create_course();
		update_post_meta( $course, Meta::CERT_ENABLED, true );
		$lesson  = $this->create_lesson( $course, 1 );
		$student = $this->enrolled_student( $course );
		$certs   = $this->lms()->certificates();

		$this->assertFalse( $certs->is_earned( $student, $course ) );
		$this->assertInstanceOf( WP_Error::class, $certs->pdf( $student, $course ) );

		$this->lms()->progress()->complete_step( $student, $lesson );
		$this->assertTrue( $certs->is_earned( $student, $course ) );
	}

	public function test_disabled_certificate_is_never_earned(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$student = $this->enrolled_student( $course );
		$this->lms()->progress()->complete_step( $student, $lesson );

		$this->assertFalse( $this->lms()->certificates()->is_earned( $student, $course ) );
		$this->assertSame( 'dlms_no_certificate', $this->lms()->certificates()->data( $student, $course )->get_error_code() );
	}

	public function test_certificate_data_number_and_html(): void {
		$c     = $this->completed_course();
		$certs = $this->lms()->certificates();
		$data  = $certs->data( $c['student'], $c['course'] );

		$this->assertSame( 'Jürgen Groß', $data['student_name'] );
		$this->assertSame( 'Deutsch A1', $data['course_title'] );
		$this->assertSame( 'Frau Müller', $data['signer'] );
		$this->assertMatchesRegularExpression( '/^DLMS-\d{6}-[0-9A-F]{6}$/', $data['certificate_number'] );
		$this->assertSame( $data['certificate_number'], $certs->data( $c['student'], $c['course'] )['certificate_number'], 'Stable across requests.' );

		$html = $certs->html( $data );
		$this->assertStringContainsString( 'Jürgen Groß', $html );
		$this->assertStringContainsString( 'Deutsch A1', $html );
	}

	public function test_pdf_is_generated(): void {
		$c   = $this->completed_course();
		$pdf = $this->lms()->certificates()->pdf( $c['student'], $c['course'] );

		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF-', $pdf );
		$this->assertGreaterThan( 1000, strlen( $pdf ) );
	}

	public function test_who_may_view_a_certificate(): void {
		$c          = $this->completed_course();
		$certs      = $this->lms()->certificates();
		$other      = $this->create_user();
		$lms_admin  = $this->create_user( Roles::LMS_ADMIN );
		$instructor = $this->create_user( Roles::INSTRUCTOR );

		$this->assertTrue( $certs->can_view( $c['student'], $c['student'], $c['course'] ) );
		$this->assertFalse( $certs->can_view( $other, $c['student'], $c['course'] ) );
		$this->assertFalse( $certs->can_view( 0, $c['student'], $c['course'] ) );
		$this->assertTrue( $certs->can_view( $lms_admin, $c['student'], $c['course'] ) );
		$this->assertFalse( $certs->can_view( $instructor, $c['student'], $c['course'] ), 'Only the instructor of that course.' );
	}

	public function test_background_must_be_a_local_image(): void {
		$c = $this->completed_course();

		update_post_meta( $c['course'], Meta::CERT_BACKGROUND, self::factory()->post->create() );
		$this->assertSame( '', $this->lms()->certificates()->data( $c['student'], $c['course'] )['background_path'] );
	}
}
