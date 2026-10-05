<?php
/**
 * Section headings between lessons.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\CourseStructure;
use DeutschLMS\Content\Meta;
use DeutschLMS\Roles\Roles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \DeutschLMS\Content\CourseStructure::get_headings
 * @covers \DeutschLMS\Content\CourseStructure::sanitize_headings
 * @covers \DeutschLMS\Content\StructureEditor::save_order
 * @covers \DeutschLMS\Rest\CourseBuilderController
 * @covers \DeutschLMS\Frontend\Renderer::course_outline
 */
final class SectionHeadingsTest extends TestCase {

	/**
	 * Dispatches a JSON request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route under dlms/v1.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Response
	 */
	private function request( string $method, string $path, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/dlms/v1' . $path );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Lesson payload for save_order() in the given order.
	 *
	 * @param int[] $lesson_ids Lesson IDs.
	 * @return array
	 */
	private static function lessons_payload( array $lesson_ids ): array {
		return array_map( static fn( $id ) => array( 'id' => $id ), $lesson_ids );
	}

	public function test_sanitize_drops_empty_titles_and_fixes_ids(): void {
		$clean = CourseStructure::sanitize_headings(
			array(
				array(
					'id'     => 'grammar',
					'title'  => '  <b>Grammatik</b> und Übungen ',
					'before' => '12',
				),
				array(
					'id'     => 'grammar',
					'title'  => 'Wortschatz-Übungen',
					'before' => 30,
				),
				array(
					'title'  => '   ',
					'before' => 5,
				),
				'not an array',
			)
		);

		$this->assertCount( 2, $clean );
		$this->assertSame( 'grammar', $clean[0]['id'] );
		$this->assertSame( 'Grammatik und Übungen', $clean[0]['title'] );
		$this->assertSame( 12, $clean[0]['before'] );
		$this->assertNotSame( 'grammar', $clean[1]['id'], 'A duplicate ID gets a new one.' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $clean[1]['id'] );
		$this->assertSame( array(), CourseStructure::sanitize_headings( 'nope' ) );
	}

	public function test_save_order_stores_headings_and_they_follow_their_lesson(): void {
		$course    = $this->create_course();
		$l1        = $this->create_lesson( $course, 1 );
		$l2        = $this->create_lesson( $course, 2 );
		$l3        = $this->create_lesson( $course, 3 );
		$admin     = $this->create_user( 'administrator' );
		$editor    = $this->lms()->structure_editor();
		$structure = $this->lms()->structure();

		$result = $editor->save_order(
			$admin,
			$course,
			self::lessons_payload( array( $l1, $l2, $l3 ) ),
			null,
			array(
				array(
					'title'  => 'Grammatik und Übungen',
					'before' => $l1,
				),
				array(
					'title'  => 'Wortschatz-Übungen',
					'before' => $l3,
				),
				array(
					'title'  => 'Ende',
					'before' => 0,
				),
			)
		);
		$this->assertTrue( $result );

		$headings = $structure->get_headings( $course );
		$this->assertSame( array( 'Grammatik und Übungen' ), array_column( $headings[ $l1 ], 'title' ) );
		$this->assertSame( array( 'Wortschatz-Übungen' ), array_column( $headings[ $l3 ], 'title' ) );
		$this->assertSame( array( 'Ende' ), array_column( $headings[0], 'title' ) );

		// Reordering without a heading list keeps the headings on their lessons.
		$this->assertTrue( $editor->save_order( $admin, $course, self::lessons_payload( array( $l3, $l1, $l2 ) ) ) );
		$this->assertSame( array( 'Wortschatz-Übungen' ), array_column( $structure->get_headings( $course )[ $l3 ], 'title' ) );

		// An empty list removes them.
		$this->assertTrue( $editor->save_order( $admin, $course, self::lessons_payload( array( $l1, $l2, $l3 ) ), null, array() ) );
		$this->assertSame( array(), $structure->get_headings( $course ) );
	}

	public function test_save_order_rejects_heading_above_foreign_lesson_and_writes_nothing(): void {
		$course  = $this->create_course();
		$l1      = $this->create_lesson( $course, 1 );
		$l2      = $this->create_lesson( $course, 2 );
		$foreign = $this->create_lesson( $this->create_course(), 1 );
		$admin   = $this->create_user( 'administrator' );

		$result = $this->lms()->structure_editor()->save_order(
			$admin,
			$course,
			self::lessons_payload( array( $l2, $l1 ) ),
			null,
			array(
				array(
					'title'  => 'Fremd',
					'before' => $foreign,
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dlms_invalid_heading', $result->get_error_code() );
		$this->assertSame( array( $l1, $l2 ), $this->lms()->structure()->get_lessons( $course ), 'Order unchanged.' );
		$this->assertSame( '', get_post_meta( $course, Meta::SECTION_HEADINGS, true ) );
	}

	public function test_student_view_moves_heading_past_draft_lesson(): void {
		$course = $this->create_course();
		$l1     = $this->create_lesson( $course, 1 );
		$draft  = $this->create_lesson( $course, 2, array( 'status' => 'draft' ) );
		$l3     = $this->create_lesson( $course, 3 );
		update_post_meta(
			$course,
			Meta::SECTION_HEADINGS,
			array(
				array(
					'id'     => 'vocab',
					'title'  => 'Wortschatz-Übungen',
					'before' => $draft,
				),
			)
		);

		$this->assertArrayHasKey( $draft, $this->lms()->structure()->get_headings( $course, false ) );
		$student_view = $this->lms()->structure()->get_headings( $course );
		$this->assertSame( array( $l3 ), array_keys( $student_view ) );
		$this->assertSame( 'vocab', $student_view[ $l3 ][0]['id'] );

		// No published lesson after it: the heading goes to the end.
		wp_update_post(
			array(
				'ID'          => $l3,
				'post_status' => 'draft',
			)
		);
		$this->lms()->flush_runtime_caches();
		$this->assertSame( array( 0 ), array_keys( $this->lms()->structure()->get_headings( $course ) ) );
		$this->assertNotEmpty( $l1 );
	}

	public function test_outline_splits_lessons_under_headings(): void {
		$course = $this->create_course();
		$l1     = $this->create_lesson( $course, 1 );
		$l2     = $this->create_lesson( $course, 2 );
		$final  = $this->create_quiz( $course, 0 );
		update_post_meta(
			$course,
			Meta::SECTION_HEADINGS,
			array(
				array(
					'id'     => 'a',
					'title'  => 'Grammatik & Übungen',
					'before' => $l1,
				),
				array(
					'id'     => 'b',
					'title'  => '<script>x</script>Wortschatz-Übungen',
					'before' => $l2,
				),
			)
		);

		$html = $this->lms()->renderer()->course_outline( $course );

		$this->assertSame( 2, substr_count( $html, '<h3 class="dlms-outline__section">' ) );
		$this->assertSame( 2, substr_count( $html, '<ol class="dlms-outline__lessons">' ) );
		$this->assertSame( substr_count( $html, '<ol' ), substr_count( $html, '</ol>' ), 'Lists are balanced.' );
		$this->assertStringContainsString( 'Grammatik &amp; Übungen', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertMatchesRegularExpression( '/Grammatik.*Lesson 1.*Wortschatz-Übungen.*Lesson 2/s', $html );
		$this->assertStringContainsString( 'dlms-outline__final', $html, 'Final quiz still listed.' );
		$this->assertNotEmpty( $final );
	}

	public function test_outline_without_headings_is_one_list(): void {
		$course = $this->create_course();
		$this->create_lesson( $course, 1 );
		$this->create_lesson( $course, 2 );

		$html = $this->lms()->renderer()->course_outline( $course );

		$this->assertSame( 1, substr_count( $html, '<ol class="dlms-outline__lessons">' ) );
		$this->assertStringNotContainsString( 'dlms-outline__section', $html );
	}

	public function test_rest_round_trip_and_permissions(): void {
		$course = $this->create_course();
		$l1     = $this->create_lesson( $course, 1 );
		$l2     = $this->create_lesson( $course, 2 );

		wp_set_current_user( $this->create_user( 'administrator' ) );
		$saved = $this->request(
			'PUT',
			"/courses/{$course}/structure",
			array(
				'lessons'  => array( array( 'id' => $l1 ), array( 'id' => $l2 ) ),
				'headings' => array(
					array(
						'id'     => '',
						'title'  => 'Wortschatz-Übungen',
						'before' => $l2,
					),
				),
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$headings = $saved->get_data()['headings'];
		$this->assertCount( 1, $headings );
		$this->assertSame( 'Wortschatz-Übungen', $headings[0]['title'] );
		$this->assertSame( $l2, $headings[0]['before'] );
		$this->assertNotSame( '', $headings[0]['id'] );

		$tree = $this->request( 'GET', "/courses/{$course}/structure" )->get_data();
		$this->assertSame( $headings, $tree['headings'] );

		$bad = $this->request(
			'PUT',
			"/courses/{$course}/structure",
			array(
				'lessons'  => array( array( 'id' => $l1 ) ),
				'headings' => array( array( 'title' => 'Ohne Anker' ) ),
			)
		);
		$this->assertSame( 400, $bad->get_status(), '`before` is required.' );

		wp_set_current_user( $this->create_user( Roles::STUDENT ) );
		$denied = $this->request(
			'PUT',
			"/courses/{$course}/structure",
			array(
				'lessons'  => array( array( 'id' => $l1 ) ),
				'headings' => array(),
			)
		);
		$this->assertContains( $denied->get_status(), array( 401, 403 ) );
		$this->assertCount( 1, $this->lms()->structure()->get_headings( $course ) );
	}
}
