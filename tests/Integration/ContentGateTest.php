<?php
/**
 * Front-end content protection tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

/**
 * @covers \DeutschLMS\Frontend\ContentGate
 * @covers \DeutschLMS\Frontend\Renderer
 * @covers \DeutschLMS\Frontend\Templates
 */
final class ContentGateTest extends TestCase {

	/**
	 * Renders a post's content the way a theme's single template would.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function render_single( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		return $html;
	}

	public function test_visitor_sees_lock_message_instead_of_lesson_content(): void {
		$c = $this->sample_course();
		wp_update_post(
			array(
				'ID'           => $c['l2'],
				'post_content' => 'GEHEIM-INHALT',
			)
		);

		$html = $this->render_single( $c['l2'] );

		$this->assertStringNotContainsString( 'GEHEIM-INHALT', $html );
		$this->assertStringContainsString( 'dlms-locked--not_logged_in', $html );
		$this->assertStringContainsString( 'Log in to enroll', $html );
	}

	public function test_enrolled_student_sees_content_mark_complete_and_navigation(): void {
		$c = $this->sample_course();
		wp_update_post(
			array(
				'ID'           => $c['l2'],
				'post_content' => 'GEHEIM-INHALT',
			)
		);
		wp_set_current_user( $this->enrolled_student( $c['course'] ) );

		$html = $this->render_single( $c['l2'] );

		$this->assertStringContainsString( 'GEHEIM-INHALT', $html );
		$this->assertStringContainsString( 'data-dlms-action="complete"', $html );
		$this->assertStringContainsString( 'name="dlms_nonce"', $html );
		$this->assertStringContainsString( 'dlms-step-nav', $html );
	}

	public function test_linear_lock_names_the_blocking_step(): void {
		$c = $this->sample_course( true );
		wp_set_current_user( $this->enrolled_student( $c['course'] ) );

		$html = $this->render_single( $c['l3'] );

		$this->assertStringContainsString( 'dlms-locked--locked', $html );
		$this->assertStringContainsString( esc_url( get_permalink( $c['t11'] ) ), $html );
	}

	public function test_course_page_shows_enroll_form_and_outline(): void {
		$c = $this->sample_course();
		wp_set_current_user( $this->create_user() );

		$html = $this->render_single( $c['course'] );

		$this->assertStringContainsString( 'data-dlms-action="enroll"', $html );
		$this->assertStringContainsString( 'dlms-outline', $html );
		$this->assertStringContainsString( 'Lesson 3', $html );
		$this->assertStringNotContainsString( 'href="' . esc_url( get_permalink( $c['l1'] ) ) . '"', $html, 'Locked lessons are not linked.' );
	}

	public function test_auto_output_is_skipped_when_content_has_lms_blocks(): void {
		$course = $this->create_course();
		wp_update_post(
			array(
				'ID'           => $course,
				'post_content' => '<!-- wp:deutschlms/enroll-button /-->',
			)
		);
		wp_set_current_user( $this->create_user() );

		$html = $this->render_single( $course );

		$this->assertSame( 1, substr_count( $html, 'data-dlms-action="enroll"' ), 'Only the block renders the enroll form.' );
		$this->assertStringNotContainsString( 'dlms-outline', $html );
	}

	public function test_excerpt_of_locked_step_is_empty(): void {
		$c = $this->sample_course();
		wp_update_post(
			array(
				'ID'           => $c['l2'],
				'post_excerpt' => 'GEHEIM-AUSZUG',
			)
		);

		$this->assertSame( '', get_the_excerpt( $c['l2'] ) );
	}

	public function test_theme_override_template_is_used(): void {
		$override = static function ( $path, $template ) {
			return 'notice.php' === $template ? dirname( __DIR__ ) . '/fixtures/notice-override.php' : $path;
		};
		add_filter( 'dlms_locate_template', $override, 10, 2 );
		$html = \DeutschLMS\Frontend\Templates::render(
			'notice.php',
			array(
				'type'    => 'success',
				'message' => 'Hi',
			)
		);
		remove_filter( 'dlms_locate_template', $override, 10 );

		$this->assertSame( 'OVERRIDE:Hi', trim( $html ) );
	}

	public function test_template_paths_cannot_escape_the_templates_folder(): void {
		$this->assertSame( '', \DeutschLMS\Frontend\Templates::locate( '../deutschlms.php' ) );
		$this->assertSame( '', \DeutschLMS\Frontend\Templates::locate( 'course/outline.txt' ) );
	}
}
