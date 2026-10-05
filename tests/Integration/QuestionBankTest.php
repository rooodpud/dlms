<?php
/**
 * Question bank.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\QuizService;
use DeutschLMS\Roles\Roles;

/**
 * @covers \DeutschLMS\Quiz\QuestionBank
 * @covers \DeutschLMS\Quiz\QuizService
 * @covers \DeutschLMS\Rest\QuestionBankController
 */
final class QuestionBankTest extends TestCase {

	/**
	 * Makes sure the default terms exist (they are added once per site, which
	 * may have happened inside an earlier, rolled-back test).
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( QuestionBank::TERMS_OPTION, '0' );
		$this->bank()->maybe_seed_terms();
	}

	/**
	 * Question bank.
	 *
	 * @return QuestionBank
	 */
	private function bank(): QuestionBank {
		return $this->lms()->question_bank();
	}

	/**
	 * A single-choice question; the right answer is `a_r`.
	 *
	 * @param string $key  Question ID.
	 * @param string $text Question text.
	 * @return array
	 */
	private static function single( string $key, string $text ): array {
		return array(
			'id'      => $key,
			'type'    => 'single',
			'text'    => $text,
			'points'  => 1,
			'answers' => array(
				array(
					'id'      => 'a_r',
					'text'    => 'ja',
					'correct' => true,
				),
				array(
					'id'      => 'a_w',
					'text'    => 'nein',
					'correct' => false,
				),
			),
		);
	}

	/**
	 * A quiz in a fresh course that uses the given items.
	 *
	 * @param array $items Quiz items.
	 * @return array{course: int, quiz: int}
	 */
	private function bank_quiz( array $items ): array {
		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		$quiz   = $this->create_quiz( $course, $lesson );
		update_post_meta( $quiz, Meta::QUIZ_ITEMS, $items );
		return array(
			'course' => $course,
			'quiz'   => $quiz,
		);
	}

	/**
	 * A quiz item linking a bank question.
	 *
	 * @param int $post_id Question post ID.
	 * @return array
	 */
	private static function link( int $post_id ): array {
		return array(
			'kind'     => QuestionBank::KIND_QUESTION,
			'question' => $post_id,
		);
	}

	/**
	 * A question category.
	 *
	 * @param string $name Name.
	 * @return int Term ID.
	 */
	private function category( string $name ): int {
		return (int) wp_insert_term( $name, PostTypes::QUESTION_CATEGORY )['term_id'];
	}

	/**
	 * Right answers for questions built with single().
	 *
	 * @param array $questions Questions.
	 * @return array
	 */
	private static function right_answers( array $questions ): array {
		$answers = array();
		foreach ( $questions as $question ) {
			$answers[ $question['id'] ] = array( 'a_r' );
		}
		return $answers;
	}

	public function test_default_difficulty_and_cefr_levels_exist(): void {
		foreach ( array( 'Leicht', 'Mittel', 'Schwer' ) as $name ) {
			$this->assertNotEmpty( term_exists( $name, PostTypes::QUESTION_DIFFICULTY ), $name );
		}
		foreach ( array( 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' ) as $name ) {
			$this->assertNotEmpty( term_exists( $name, PostTypes::QUESTION_LEVEL ), $name );
		}
	}

	public function test_create_keeps_a_free_question_id_and_replaces_a_used_one(): void {
		$first  = $this->bank()->create( 1, self::single( 'q_same', 'Erste' ) );
		$second = $this->bank()->create( 1, self::single( 'q_same', 'Zweite' ) );
		$kept   = $this->bank()->create( 1, self::single( 'q_same', 'Dritte' ), array(), true );

		$this->assertSame( 'q_same', $this->bank()->get( $first )['id'] );
		$this->assertNotSame( 'q_same', $this->bank()->get( $second )['id'] );
		$this->assertSame( 'q_same', $this->bank()->get( $kept )['id'] );
		$this->assertSame( 'Erste', get_the_title( $first ) );
		$this->assertSame( '1', get_post_meta( $first, Meta::QUESTION_READY, true ) );
		$this->assertSame( 'single', get_post_meta( $first, Meta::QUESTION_TYPE, true ) );
	}

	public function test_title_shows_gaps_with_their_answers(): void {
		$id = $this->bank()->create(
			1,
			array(
				'id'   => 'q_gap',
				'type' => 'fill_blank',
				'text' => 'Ich {bin|war} müde.',
			)
		);
		$this->assertSame( 'Ich [bin|war] müde.', get_the_title( $id ) );
	}

	public function test_editing_a_shared_question_changes_every_quiz_and_keeps_its_id(): void {
		$question = $this->bank()->create( 1, self::single( 'q_shared', 'Alt' ) );
		$first    = $this->bank_quiz( array( self::link( $question ) ) );
		$second   = $this->bank_quiz( array( self::link( $question ) ) );
		$admin    = $this->create_user( 'administrator' );
		wp_set_current_user( $admin );

		$this->bank()->save_quiz_items(
			$admin,
			$first['quiz'],
			array(
				array(
					'kind'     => 'question',
					'post_id'  => $question,
					'changed'  => true,
					'question' => self::single( 'q_other', 'Neu' ),
				),
			)
		);

		$this->assertSame( 'Neu', $this->lms()->quizzes()->questions( $second['quiz'] )[0]['text'] );
		$this->assertSame( 'q_shared', $this->lms()->quizzes()->questions( $second['quiz'] )[0]['id'] );
		$this->assertSame( 'Neu', get_the_title( $question ) );
		$this->assertEqualsCanonicalizing( array( $first['quiz'], $second['quiz'] ), $this->bank()->quizzes_using( $question ) );
	}

	public function test_unchanged_and_other_authors_questions_are_not_overwritten(): void {
		$question = $this->bank()->create( 1, self::single( 'q_keep', 'Original' ) );
		$quiz     = $this->bank_quiz( array( self::link( $question ) ) )['quiz'];
		$entry    = array(
			'kind'     => 'question',
			'post_id'  => $question,
			'changed'  => true,
			'question' => self::single( 'q_keep', 'Geändert' ),
		);

		$instructor = $this->create_user( Roles::INSTRUCTOR );
		wp_set_current_user( $instructor );
		$this->bank()->save_quiz_items( $instructor, $quiz, array( $entry ) );
		$this->assertSame( 'Original', $this->bank()->get( $question )['text'] );

		$admin = $this->create_user( 'administrator' );
		wp_set_current_user( $admin );
		$this->bank()->save_quiz_items( $admin, $quiz, array( array_merge( $entry, array( 'changed' => false ) ) ) );
		$this->assertSame( 'Original', $this->bank()->get( $question )['text'] );
		$this->assertSame( array( self::link( $question ) ), $this->bank()->items( $quiz ) );
	}

	public function test_new_questions_go_to_the_bank_and_removing_only_unlinks(): void {
		$quiz  = $this->bank_quiz( array() )['quiz'];
		$admin = $this->create_user( 'administrator' );
		$level = (int) term_exists( 'A2', PostTypes::QUESTION_LEVEL )['term_id'];
		wp_set_current_user( $admin );

		$items = $this->bank()->save_quiz_items(
			$admin,
			$quiz,
			array(
				array(
					'kind'     => 'question',
					'post_id'  => 0,
					'question' => self::single( 'q_fresh', 'Neu im Quiz' ),
					'terms'    => array( 'level' => array( $level ) ),
				),
				array(
					'kind'     => 'question',
					'post_id'  => 0,
					'question' => self::single( 'q_empty', '' ),
				),
			)
		);
		$this->assertCount( 1, $items, 'A question without text is not created.' );
		$post_id = $items[0]['question'];
		$this->assertSame( PostTypes::QUESTION, get_post_type( $post_id ) );
		$this->assertSame( 'q_fresh', $this->bank()->get( $post_id )['id'] );
		$this->assertSame( $level, $this->bank()->terms_of( $post_id )['level'] );

		$this->bank()->save_quiz_items( $admin, $quiz, array() );
		$this->assertSame( array(), $this->bank()->items( $quiz ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( array(), $this->bank()->quizzes_using( $post_id ) );
	}

	public function test_old_quizzes_still_work_and_moving_them_keeps_ids_and_results(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array( 'show_answers' => true ) );
		$student = $this->enrolled_student( $course );
		$quizzes = $this->lms()->quizzes();

		$this->assertNull( $this->bank()->items( $quiz ) );
		$result = $quizzes->submit( $student, $quiz, self::RIGHT );
		$this->assertTrue( $result['passed'] );

		$level = (int) term_exists( 'A2', PostTypes::QUESTION_LEVEL )['term_id'];
		$this->assertSame( 2, $this->bank()->import_quiz( 1, $quiz, array( 'level' => array( $level ) ) ) );
		$this->assertSame( 0, $this->bank()->import_quiz( 1, $quiz ), 'Moving twice does nothing.' );
		$this->assertSame( array( 'q_one', 'q_two' ), array_column( $quizzes->playable_questions( $quiz ), 'id' ) );
		$this->assertCount( 2, get_post_meta( $quiz, Meta::QUESTIONS, true ), 'The old questions stay as a backup.' );
		$this->assertSame( $level, $this->bank()->terms_of( $this->bank()->items( $quiz )[0]['question'] )['level'] );

		$attempt = $quizzes->get_attempt( $student, $result['attempt_id'] );
		$this->assertCount( 2, $quizzes->result_details( $quiz, $attempt ) );
		$this->assertTrue( $quizzes->submit( $student, $quiz, self::RIGHT )['passed'] );
	}

	public function test_an_interrupted_move_links_questions_already_in_the_bank(): void {
		$course = $this->create_course();
		$quiz   = $this->create_quiz( $course, $this->create_lesson( $course, 1 ) );
		$done   = $this->bank()->create( 1, self::default_questions()[0], array(), true );
		$other  = $this->bank()->create( 1, array_merge( self::default_questions()[1], array( 'text' => 'Anderer Text' ) ), array(), true );

		$this->assertSame( 2, $this->bank()->import_quiz( 1, $quiz ) );
		$linked = array_column( $this->bank()->items( $quiz ), 'question' );
		$this->assertSame( $done, $linked[0], 'Same ID and content: linked.' );
		$this->assertNotSame( $other, $linked[1], 'Same ID, other content: added.' );
	}

	public function test_random_rule_draws_complete_questions_of_its_category_without_repeats(): void {
		$perfekt = $this->category( 'Perfekt' );
		$other   = $this->category( 'Wortschatz' );
		$in      = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$in[] = $this->bank()->create( 1, self::single( 'q_p' . $i, 'Perfekt ' . $i ), array( 'category' => array( $perfekt ) ) );
		}
		$this->bank()->create( 1, self::single( 'q_o1', 'Anders' ), array( 'category' => array( $other ) ) );
		$broken = self::single( 'q_broken', 'Unvollständig' );
		unset( $broken['answers'][0]['correct'] );
		$this->bank()->create( 1, $broken, array( 'category' => array( $perfekt ) ) );

		$rule = array(
			'kind'     => 'random',
			'count'    => 3,
			'category' => $perfekt,
		);
		$quiz = $this->bank_quiz( array( self::link( $in[0] ), $rule ) )['quiz'];

		$questions = $this->lms()->quizzes()->playable_questions( $quiz );
		$ids       = array_column( $questions, 'id' );
		$this->assertCount( 4, $ids );
		$this->assertSame( 'q_p1', $ids[0] );
		$this->assertSame( $ids, array_unique( $ids ) );
		$this->assertEmpty( array_diff( $ids, array( 'q_p1', 'q_p2', 'q_p3', 'q_p4', 'q_p5' ) ) );
		$this->assertSame( 4, $this->lms()->quizzes()->question_count( $quiz ) );

		update_post_meta( $quiz, Meta::QUIZ_ITEMS, array( self::link( $in[0] ), array_merge( $rule, array( 'count' => 10 ) ) ) );
		$this->assertSame( 5, $this->lms()->quizzes()->question_count( $quiz ), 'Only 4 others can be drawn.' );
	}

	public function test_students_keep_their_draw_until_they_submit(): void {
		$category = $this->category( 'Dativ' );
		for ( $i = 1; $i <= 8; $i++ ) {
			$this->bank()->create( 1, self::single( 'q_d' . $i, 'Dativ ' . $i ), array( 'category' => array( $category ) ) );
		}
		$setup   = $this->bank_quiz(
			array(
				array(
					'kind'     => 'random',
					'count'    => 3,
					'category' => $category,
				),
			)
		);
		$quiz    = $setup['quiz'];
		$student = $this->enrolled_student( $setup['course'] );
		$quizzes = $this->lms()->quizzes();

		$drawn = $quizzes->playable_questions( $quiz, $student );
		$this->assertCount( 3, $drawn );
		$this->assertSame( array_column( $drawn, 'id' ), array_column( $quizzes->playable_questions( $quiz, $student ), 'id' ), 'A reload shows the same questions.' );

		$result = $quizzes->submit( $student, $quiz, self::right_answers( $drawn ) );
		$this->assertTrue( $result['passed'] );
		$this->assertSame( 3, $result['max_score'] );
		$this->assertSame( 3, $result['score'] );
		$this->assertArrayNotHasKey( $quiz, (array) get_user_meta( $student, QuizService::DRAWS_META, true ) );

		$attempt = $quizzes->get_attempt( $student, $result['attempt_id'] );
		$this->assertSame( array_column( $drawn, 'text' ), array_column( $quizzes->result_details( $quiz, $attempt ), 'text' ) );
	}

	public function test_a_quiz_whose_random_pool_is_empty_cannot_be_taken(): void {
		$empty   = $this->category( 'Leer' );
		$setup   = $this->bank_quiz(
			array(
				array(
					'kind'     => 'random',
					'count'    => 5,
					'category' => $empty,
				),
			)
		);
		$student = $this->enrolled_student( $setup['course'] );

		$this->assertSame( 0, $this->lms()->quizzes()->question_count( $setup['quiz'] ) );
		$check = $this->lms()->quizzes()->can_attempt( $student, $setup['quiz'] );
		$this->assertWPError( $check );
		$this->assertSame( 'dlms_quiz_empty', $check->get_error_code() );
	}

	public function test_items_are_sanitized(): void {
		$items = QuestionBank::sanitize_items(
			array(
				array( 'question' => 5 ),
				array(
					'kind'     => 'question',
					'question' => 5,
				),
				array( 'question' => 0 ),
				array(
					'kind'  => 'random',
					'count' => 500,
					'type'  => 'essay',
				),
				'junk',
			)
		);
		$this->assertSame(
			array(
				self::link( 5 ),
				array(
					'kind'       => 'random',
					'count'      => QuestionBank::MAX_RANDOM_COUNT,
					'category'   => 0,
					'difficulty' => 0,
					'level'      => 0,
					'type'       => '',
				),
			),
			$items
		);
	}

	public function test_questions_in_lists_the_questions_of_given_quizzes(): void {
		$one   = $this->bank()->create( 1, self::single( 'q_x1', 'Eins' ) );
		$two   = $this->bank()->create( 1, self::single( 'q_x2', 'Zwei' ) );
		$first = $this->bank_quiz( array( self::link( $one ) ) )['quiz'];
		$this->bank_quiz( array( self::link( $two ) ) );

		$this->assertSame( array( $one ), $this->bank()->questions_in( array( $first ) ) );
	}

	public function test_rest_search_and_count_need_question_rights(): void {
		$category = $this->category( 'Modalverben' );
		$this->bank()->create( 1, self::single( 'q_m1', 'Ich muss arbeiten' ), array( 'category' => array( $category ) ) );
		$this->bank()->create( 1, self::single( 'q_m2', 'Du darfst gehen' ) );

		wp_set_current_user( $this->create_user() );
		$denied = rest_do_request( new \WP_REST_Request( 'GET', '/dlms/v1/questions' ) );
		$this->assertSame( 403, $denied->get_status() );

		wp_set_current_user( $this->create_user( 'administrator' ) );
		$request = new \WP_REST_Request( 'GET', '/dlms/v1/questions' );
		$request->set_param( 'search', 'arbeiten' );
		$found = rest_do_request( $request )->get_data();
		$this->assertSame( 1, $found['total'] );
		$this->assertSame( 'q_m1', $found['items'][0]['question']['id'] );
		$this->assertSame( array( $category ), $found['items'][0]['terms']['category'] );

		$count = new \WP_REST_Request( 'GET', '/dlms/v1/questions/count' );
		$count->set_param( 'category', $category );
		$this->assertSame( 1, rest_do_request( $count )->get_data()['count'] );
	}
}
