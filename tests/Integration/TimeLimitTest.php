<?php
/**
 * Quiz time limits.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\Meta;
use DeutschLMS\Database\Schema;
use DeutschLMS\Quiz\QuizService;

/**
 * @covers \DeutschLMS\Quiz\QuizService
 * @covers \DeutschLMS\Quiz\AttemptRepository
 * @covers \DeutschLMS\Frontend\Renderer::quiz_view
 */
final class TimeLimitTest extends TestCase {

	/**
	 * A timed quiz in a fresh course and an enrolled student.
	 *
	 * @param int $minutes Time limit.
	 * @return array{quiz: int, student: int}
	 */
	private function timed_quiz( int $minutes = 8 ): array {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		$quiz   = $this->create_quiz( $course, $lesson );
		update_post_meta( $quiz, Meta::TIME_LIMIT, $minutes );
		return array(
			'quiz'    => $quiz,
			'student' => $this->enrolled_student( $course ),
		);
	}

	/**
	 * All-correct answers for the default questions.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array
	 */
	private function right_answers( int $quiz_id ): array {
		$answers = array();
		foreach ( $this->lms()->quizzes()->playable_questions( $quiz_id ) as $question ) {
			$answers[ $question['id'] ] = array_values(
				array_map(
					static fn( $answer ) => $answer['id'],
					array_filter( $question['answers'], static fn( $answer ) => ! empty( $answer['correct'] ) )
				)
			);
		}
		return $answers;
	}

	/**
	 * Moves the student's running clock into the past.
	 *
	 * @param int $student User ID.
	 * @param int $quiz    Quiz ID.
	 * @param int $seconds How long ago it started.
	 */
	private function started_ago( int $student, int $quiz, int $seconds ): void {
		update_user_meta( $student, QuizService::TIMERS_META, array( $quiz => time() - $seconds ) );
	}

	public function test_settings_default_to_no_limit_and_cap_at_600(): void {
		$c = $this->timed_quiz( 0 );
		$this->assertSame( 0, $this->lms()->quizzes()->settings( $c['quiz'] )['time_limit'] );
		$this->assertNull( $this->lms()->quizzes()->start_timer( $c['student'], $c['quiz'] ) );

		update_post_meta( $c['quiz'], Meta::TIME_LIMIT, 9999 );
		$this->assertSame( 600, $this->lms()->quizzes()->settings( $c['quiz'] )['time_limit'] );
	}

	public function test_clock_survives_reload_and_restarts_once_fully_expired(): void {
		$c       = $this->timed_quiz( 8 );
		$quizzes = $this->lms()->quizzes();

		$this->assertSame( 480, $quizzes->start_timer( $c['student'], $c['quiz'] ) );

		$this->started_ago( $c['student'], $c['quiz'], 100 );
		$this->assertSame( 380, $quizzes->start_timer( $c['student'], $c['quiz'] ), 'Reloading keeps the clock.' );

		$this->started_ago( $c['student'], $c['quiz'], 490 );
		$this->assertSame( -10, $quizzes->start_timer( $c['student'], $c['quiz'] ), 'During "time is up" the clock is negative.' );

		$this->started_ago( $c['student'], $c['quiz'], 480 + QuizService::TIME_UP_SECONDS + QuizService::GRACE_SECONDS + 5 );
		$this->assertSame( 480, $quizzes->start_timer( $c['student'], $c['quiz'] ), 'A clock that ran out completely starts again.' );
	}

	public function test_attempt_in_time_passes_and_clears_the_clock(): void {
		$c       = $this->timed_quiz( 8 );
		$quizzes = $this->lms()->quizzes();
		$quizzes->start_timer( $c['student'], $c['quiz'] );
		$this->started_ago( $c['student'], $c['quiz'], 480 + QuizService::TIME_UP_SECONDS );

		$result = $quizzes->submit( $c['student'], $c['quiz'], $this->right_answers( $c['quiz'] ) );

		$this->assertTrue( $result['passed'] );
		$this->assertFalse( $result['late'] );
		$this->assertSame( 0, $quizzes->timer_start( $c['student'], $c['quiz'] ), 'The next attempt starts a new clock.' );
		$attempt = $quizzes->get_attempt( $c['student'], $result['attempt_id'] );
		$this->assertFalse( $attempt['late'] );
		$this->assertNotNull( $attempt['started_at'] );
	}

	public function test_late_attempt_is_graded_but_fails(): void {
		$c       = $this->timed_quiz( 8 );
		$quizzes = $this->lms()->quizzes();
		$this->started_ago( $c['student'], $c['quiz'], 480 + QuizService::TIME_UP_SECONDS + QuizService::GRACE_SECONDS + 1 );

		$result = $quizzes->submit( $c['student'], $c['quiz'], $this->right_answers( $c['quiz'] ) );

		$this->assertFalse( $result['passed'] );
		$this->assertTrue( $result['late'] );
		$this->assertEquals( 100, $result['percent'], 'Still graded.' );
		$this->assertFalse( $quizzes->has_passed( $c['student'], $c['quiz'] ) );
		$this->assertTrue( $quizzes->get_attempt( $c['student'], $result['attempt_id'] )['late'] );
	}

	public function test_attempt_without_a_started_clock_is_late(): void {
		$c = $this->timed_quiz( 8 );

		$result = $this->lms()->quizzes()->submit( $c['student'], $c['quiz'], $this->right_answers( $c['quiz'] ) );

		$this->assertTrue( $result['late'] );
		$this->assertFalse( $result['passed'] );
	}

	public function test_untimed_quiz_is_never_late(): void {
		$c = $this->timed_quiz( 0 );

		$result = $this->lms()->quizzes()->submit( $c['student'], $c['quiz'], $this->right_answers( $c['quiz'] ) );

		$this->assertFalse( $result['late'] );
		$this->assertTrue( $result['passed'] );
	}

	public function test_form_starts_the_clock_and_shows_timer_and_dialog(): void {
		$c = $this->timed_quiz( 8 );
		wp_set_current_user( $c['student'] );

		$html = $this->lms()->renderer()->quiz_view( $c['quiz'] );

		$this->assertGreaterThan( 0, $this->lms()->quizzes()->timer_start( $c['student'], $c['quiz'] ) );
		$this->assertStringContainsString( 'data-dlms-time-remaining="480"', $html );
		$this->assertStringContainsString( 'data-dlms-timer', $html );
		$this->assertStringContainsString( 'data-dlms-timeup', $html );
		$this->assertStringContainsString( 'Time limit: 8 minutes', $html );
	}

	public function test_preview_does_not_start_a_clock(): void {
		$c     = $this->timed_quiz( 8 );
		$admin = $this->create_user( 'administrator' );
		wp_set_current_user( $admin );

		$html = $this->lms()->renderer()->quiz_view( $c['quiz'] );

		$this->assertSame( 0, $this->lms()->quizzes()->timer_start( $admin, $c['quiz'] ) );
		$this->assertStringNotContainsString( 'data-dlms-time-remaining', $html );
		$this->assertStringContainsString( 'Time limit: 8 minutes', $html );
	}

	public function test_new_quizzes_from_the_builder_get_the_default(): void {
		$course = $this->create_course();
		$admin  = $this->create_user( 'administrator' );

		$quiz = $this->lms()->structure_editor()->create_quiz( $admin, $course, 0, 'Neu' );

		$this->assertSame( QuizService::DEFAULT_TIME_LIMIT, $this->lms()->quizzes()->settings( $quiz )['time_limit'] );
	}

	public function test_schema_has_the_new_columns(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::table( 'quiz_attempts' ) ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'started_at', $columns );
		$this->assertContains( 'late', $columns );
	}
}
