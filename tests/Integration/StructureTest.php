<?php
/**
 * Course structure tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\Meta;
use DeutschLMS\Roles\Roles;
use WP_Error;

/**
 * @covers \DeutschLMS\Content\CourseStructure
 * @covers \DeutschLMS\Content\StructureEditor
 */
final class StructureTest extends TestCase {

	public function test_tree_is_ordered_by_menu_order(): void {
		$course = $this->create_course();
		$late   = $this->create_lesson( $course, 5 );
		$early  = $this->create_lesson( $course, 1 );
		$t2     = $this->create_topic( $early, 2 );
		$t1     = $this->create_topic( $early, 1 );

		$this->assertSame( array( $early, $t1, $t2, $late ), $this->lms()->structure()->get_steps( $course ) );
		$this->assertSame( $t1, $this->lms()->structure()->get_next_step( $course, $early ) );
		$this->assertSame( $t2, $this->lms()->structure()->get_previous_step( $course, $late ) );
	}

	public function test_published_view_hides_drafts_but_editor_view_shows_them(): void {
		$course = $this->create_course();
		$pub    = $this->create_lesson( $course, 1 );
		$draft  = $this->create_lesson( $course, 2, array( 'status' => 'draft' ) );

		$this->assertSame( array( $pub ), $this->lms()->structure()->get_lessons( $course ) );
		$this->assertSame( array( $pub, $draft ), $this->lms()->structure()->get_lessons( $course, false ) );
	}

	public function test_save_order_reorders_and_moves_topics_between_lessons(): void {
		$c     = $this->sample_course();
		$admin = $this->create_user( 'administrator' );

		$result = $this->lms()->structure_editor()->save_order(
			$admin,
			$c['course'],
			array(
				array(
					'id'     => $c['l2'],
					'topics' => array( $c['t12'] ),
				),
				array(
					'id'     => $c['l1'],
					'topics' => array( $c['t11'] ),
				),
				array(
					'id'     => $c['l3'],
					'topics' => array(),
				),
			)
		);

		$this->assertTrue( $result );
		$this->assertSame(
			array( $c['l2'], $c['t12'], $c['l1'], $c['t11'], $c['l3'] ),
			$this->lms()->structure()->get_steps( $c['course'] )
		);
		$this->assertSame( $c['l2'], (int) get_post_meta( $c['t12'], Meta::LESSON_ID, true ) );
	}

	public function test_save_order_rejects_foreign_lessons_and_topics(): void {
		$c      = $this->sample_course();
		$other  = $this->sample_course();
		$admin  = $this->create_user( 'administrator' );
		$editor = $this->lms()->structure_editor();

		$foreign_lesson = $editor->save_order( $admin, $c['course'], array( array( 'id' => $other['l1'] ) ) );
		$this->assertInstanceOf( WP_Error::class, $foreign_lesson );

		$foreign_topic = $editor->save_order(
			$admin,
			$c['course'],
			array(
				array(
					'id'     => $c['l1'],
					'topics' => array( $other['t11'] ),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $foreign_topic );

		$duplicate = $editor->save_order(
			$admin,
			$c['course'],
			array(
				array( 'id' => $c['l1'] ),
				array( 'id' => $c['l1'] ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $duplicate );
	}

	public function test_instructor_can_only_restructure_own_courses(): void {
		$owner  = $this->create_user( Roles::INSTRUCTOR );
		$other  = $this->create_user( Roles::INSTRUCTOR );
		$course = $this->create_course( array( 'author' => $owner ) );
		$lesson = $this->create_lesson( $course, 1, array( 'author' => $owner ) );
		$editor = $this->lms()->structure_editor();

		$this->assertTrue( $editor->save_order( $owner, $course, array( array( 'id' => $lesson ) ) ) );

		$denied = $editor->save_order( $other, $course, array( array( 'id' => $lesson ) ) );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );

		$this->assertInstanceOf( WP_Error::class, $editor->create_lesson( $other, $course, 'Sneaky' ) );
		$this->assertInstanceOf( WP_Error::class, $editor->create_topic( $other, $lesson, 'Sneaky' ) );
	}

	public function test_create_lesson_and_topic_append_drafts(): void {
		$c     = $this->sample_course();
		$admin = $this->create_user( 'administrator' );

		$lesson = $this->lms()->structure_editor()->create_lesson( $admin, $c['course'], 'Neue Lektion' );
		$this->assertIsInt( $lesson );
		$this->assertSame( 'draft', get_post_status( $lesson ) );
		$this->assertSame( $c['course'], $this->lms()->structure()->get_course_id( $lesson ) );
		$this->assertSame( $lesson, array_slice( $this->lms()->structure()->get_lessons( $c['course'], false ), -1 )[0] );

		$topic = $this->lms()->structure_editor()->create_topic( $admin, $c['l1'], 'Neues Thema' );
		$this->assertIsInt( $topic );
		$this->assertSame( $c['l1'], $this->lms()->structure()->get_lesson_id( $topic ) );
		$this->assertSame( $c['course'], $this->lms()->structure()->get_course_id( $topic ) );

		$empty = $this->lms()->structure_editor()->create_lesson( $admin, $c['course'], '   ' );
		$this->assertInstanceOf( WP_Error::class, $empty );
	}

	public function test_moving_a_lesson_to_another_course_moves_its_topics(): void {
		$c      = $this->sample_course();
		$target = $this->create_course();
		$admin  = $this->create_user( 'administrator' );

		$this->assertTrue( $this->lms()->structure_editor()->assign_lesson( $admin, $c['l1'], $target ) );

		$this->assertSame( array( $c['l1'], $c['t11'], $c['t12'] ), $this->lms()->structure()->get_steps( $target ) );
		$this->assertNotContains( $c['l1'], $this->lms()->structure()->get_steps( $c['course'] ) );
	}

	public function test_instructor_cannot_attach_lessons_to_someone_elses_course(): void {
		$owner     = $this->create_user( Roles::INSTRUCTOR );
		$intruder  = $this->create_user( Roles::INSTRUCTOR );
		$course    = $this->create_course( array( 'author' => $owner ) );
		$own_draft = $this->create_lesson( 0, 1, array( 'author' => $intruder ) );

		$result = $this->lms()->structure_editor()->assign_lesson( $intruder, $own_draft, $course );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $this->lms()->structure()->get_course_id( $own_draft ) );
	}
}
