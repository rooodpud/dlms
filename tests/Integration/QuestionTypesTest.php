<?php
/**
 * Gap-fill and word-order question tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Quiz\Grader;
use DeutschLMS\Quiz\Questions;
use DeutschLMS\Quiz\QuizService;

/**
 * @covers \DeutschLMS\Quiz\Questions
 * @covers \DeutschLMS\Quiz\Grader
 * @covers \DeutschLMS\Quiz\QuizService
 */
final class QuestionTypesTest extends TestCase {

	/**
	 * A gap-fill and a word-order question.
	 *
	 * @return array
	 */
	private static function questions(): array {
		return array(
			array(
				'id'          => 'q_gap',
				'type'        => 'fill_blank',
				'text'        => "Perfekt:\nIch {bin} gestern nach München {gefahren}. Es {war|ist} schön.",
				'explanation' => 'fahren → sein',
			),
			array(
				'id'           => 'q_order',
				'type'         => 'word_order',
				'text'         => 'Bilden Sie einen Satz.',
				'answers'      => array(
					array(
						'id'   => 'w_ich',
						'text' => 'ich',
					),
					array(
						'id'   => 'w_bin',
						'text' => 'bin',
					),
					array(
						'id'   => 'w_gestern',
						'text' => 'gestern',
					),
					array(
						'id'   => 'w_zu',
						'text' => 'zu Hause',
					),
					array(
						'id'   => 'w_geblieben',
						'text' => 'geblieben',
					),
				),
				'alternatives' => array( 'Gestern bin ich zu Hause geblieben.' ),
			),
		);
	}

	public function test_sanitize_keeps_gaps_blocks_and_alternatives(): void {
		$questions = Questions::sanitize( self::questions() );

		$this->assertSame( 'fill_blank', $questions[0]['type'] );
		$this->assertSame( array(), $questions[0]['answers'] );
		$this->assertSame( array( array( 'bin' ), array( 'gefahren' ), array( 'war', 'ist' ) ), Questions::gaps( $questions[0]['text'] ) );

		$this->assertSame( 'word_order', $questions[1]['type'] );
		$this->assertSame( array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben' ), array_column( $questions[1]['answers'], 'id' ) );
		$this->assertArrayNotHasKey( 'correct', $questions[1]['answers'][0] );
		$this->assertSame( array( 'Gestern bin ich zu Hause geblieben.' ), $questions[1]['alternatives'] );
		$this->assertSame( 'Ich bin gestern zu Hause geblieben', Questions::solution_sentence( $questions[1] ) );
		$this->assertSame( array(), Questions::problems( $questions ) );
	}

	public function test_incomplete_gap_and_order_questions_are_flagged(): void {
		$questions = Questions::sanitize(
			array(
				array(
					'type' => 'fill_blank',
					'text' => 'No gaps here.',
				),
				array(
					'type' => 'fill_blank',
					'text' => 'An empty gap: { | }.',
				),
				array(
					'type'    => 'word_order',
					'text'    => 'Only one block',
					'answers' => array( array( 'text' => 'allein' ) ),
				),
			)
		);

		$this->assertCount( 3, Questions::problems( $questions ) );
		$this->assertSame( array(), Questions::playable( $questions ) );
	}

	public function test_public_view_never_contains_the_solutions(): void {
		$view = Questions::public_view( Questions::sanitize( self::questions() ) );
		$json = (string) wp_json_encode( $view );

		foreach ( array( 'bin', 'gefahren', 'war', 'fahren → sein', 'Gestern bin ich' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $view[0]['text'] . implode( '', $view[0]['segments'] ) );
		}
		$this->assertStringNotContainsString( 'alternatives', $json );
		$this->assertStringNotContainsString( 'explanation', $json );
		$this->assertSame( array( "Perfekt:\nIch ", ' gestern nach München ', '. Es ', ' schön.' ), $view[0]['segments'] );
		$this->assertCount( 3, $view[0]['gap_sizes'] );

		// Same blocks, but never in the solved order.
		$ids = array_column( $view[1]['answers'], 'id' );
		$this->assertEqualsCanonicalizing( array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben' ), $ids );
		$this->assertNotSame( array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben' ), $ids );
	}

	public function test_gap_fill_grading(): void {
		$questions = Questions::sanitize( self::questions() );
		$gap       = array( $questions[0] );

		$right = Grader::grade( $gap, array( 'q_gap' => array( 'bin', 'gefahren', 'ist' ) ) );
		$this->assertTrue( $right['results']['q_gap']['correct'], 'Any listed alternative is accepted.' );

		$spaced = Grader::grade( $gap, array( 'q_gap' => array( '  bin ', 'gefahren', 'war' ) ) );
		$this->assertTrue( $spaced['results']['q_gap']['correct'], 'Extra spaces are forgiven.' );

		$case = Grader::grade( $gap, array( 'q_gap' => array( 'Bin', 'gefahren', 'war' ) ) );
		$this->assertFalse( $case['results']['q_gap']['correct'], 'Capitalization counts.' );
		$this->assertSame( array( false, true, true ), $case['results']['q_gap']['gaps'] );

		$partial = Grader::grade( $gap, array( 'q_gap' => array( 'bin', 'gefahrt', 'war' ) ) );
		$this->assertSame( 0, $partial['score'], 'All-or-nothing per question.' );

		$empty = Grader::grade( $gap, array() );
		$this->assertFalse( $empty['results']['q_gap']['correct'] );
		$this->assertSame( array( '', '', '' ), $empty['results']['q_gap']['selected'] );
	}

	public function test_gap_fill_umlauts_must_be_exact(): void {
		$questions = Questions::sanitize(
			array(
				array(
					'id'   => 'q_u',
					'type' => 'fill_blank',
					'text' => 'Wir {müssen} {heißen}.',
				),
			)
		);

		$this->assertTrue( Grader::grade( $questions, array( 'q_u' => array( 'müssen', 'heißen' ) ) )['results']['q_u']['correct'] );
		$this->assertFalse( Grader::grade( $questions, array( 'q_u' => array( 'mussen', 'heissen' ) ) )['results']['q_u']['correct'] );
		// "ü" as u + combining diaeresis (decomposed) is the same letter.
		if ( class_exists( 'Normalizer' ) ) {
			$this->assertTrue( Grader::grade( $questions, array( 'q_u' => array( "mu\u{0308}ssen", 'heißen' ) ) )['results']['q_u']['correct'] );
		}
	}

	public function test_word_order_grading(): void {
		$questions = Questions::sanitize( self::questions() );
		$order     = array( $questions[1] );

		$solution = Grader::grade( $order, array( 'q_order' => array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben' ) ) );
		$this->assertTrue( $solution['results']['q_order']['correct'] );

		$alternative = Grader::grade( $order, array( 'q_order' => array( 'w_gestern', 'w_bin', 'w_ich', 'w_zu', 'w_geblieben' ) ) );
		$this->assertTrue( $alternative['results']['q_order']['correct'], 'Alternatives are accepted, ignoring case and punctuation.' );

		$wrong = Grader::grade( $order, array( 'q_order' => array( 'w_ich', 'w_gestern', 'w_bin', 'w_zu', 'w_geblieben' ) ) );
		$this->assertFalse( $wrong['results']['q_order']['correct'] );

		$missing = Grader::grade( $order, array( 'q_order' => array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu' ) ) );
		$this->assertFalse( $missing['results']['q_order']['correct'], 'Every block must be used.' );

		$cheat = Grader::grade( $order, array( 'q_order' => array( 'w_ich', 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben', 'x_fake' ) ) );
		$this->assertSame( array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben' ), $cheat['results']['q_order']['selected'], 'Duplicates and unknown IDs are dropped.' );
	}

	public function test_word_order_blocks_with_the_same_text_are_interchangeable(): void {
		$questions = Questions::sanitize(
			array(
				array(
					'id'      => 'q_die',
					'type'    => 'word_order',
					'text'    => 'Ordnen Sie.',
					'answers' => array(
						array(
							'id'   => 'd_one',
							'text' => 'die',
						),
						array(
							'id'   => 'f_frau',
							'text' => 'Frau',
						),
						array(
							'id'   => 'k_kauft',
							'text' => 'kauft',
						),
						array(
							'id'   => 'd_two',
							'text' => 'die',
						),
						array(
							'id'   => 't_tasche',
							'text' => 'Tasche',
						),
					),
				),
			)
		);

		$swapped = Grader::grade( $questions, array( 'q_die' => array( 'd_two', 'f_frau', 'k_kauft', 'd_one', 't_tasche' ) ) );
		$this->assertTrue( $swapped['results']['q_die']['correct'] );
	}

	public function test_clean_answers_keeps_typed_text_and_positions(): void {
		$clean = QuizService::clean_answers(
			array(
				'q_gap'   => array( 'Müller', '', '<b>war</b>' ),
				'Q_UPPER' => array( 'a_one' ),
				'bad key' => array( 'x' ),
			)
		);

		$this->assertSame( array( 'Müller', '', 'war' ), $clean['q_gap'] );
		$this->assertSame( array( 'a_one' ), $clean['q_upper'] );
		$this->assertArrayNotHasKey( 'bad key', $clean );
	}

	public function test_submit_and_result_details_for_new_types(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array( 'show_answers' => true ), self::questions() );
		$student = $this->enrolled_student( $course );
		$quizzes = $this->lms()->quizzes();

		$failed = $quizzes->submit(
			$student,
			$quiz,
			array(
				'q_gap'   => array( 'bin', 'gefahrt', 'war' ),
				'q_order' => array( 'w_gestern', 'w_ich', 'w_bin', 'w_zu', 'w_geblieben' ),
			)
		);
		$this->assertSame( 0, $failed['score'] );
		$this->assertFalse( $quizzes->has_passed( $student, $quiz ) );

		$details = $quizzes->result_details( $quiz, $quizzes->get_attempt( $student, $failed['attempt_id'] ) );
		$this->assertSame( 'fill_blank', $details[0]['type'] );
		$this->assertSame( 'gefahrt', $details[0]['gaps'][1]['given'] );
		$this->assertFalse( $details[0]['gaps'][1]['is_correct'] );
		$this->assertSame( 'gefahren', $details[0]['gaps'][1]['solution'] );
		$this->assertSame( 'war / ist', $details[0]['gaps'][2]['solution'] );
		$this->assertStringNotContainsString( 'gefahren', $details[0]['text'] );
		$this->assertSame( 'Gestern ich bin zu Hause geblieben.', $details[1]['given'] );
		$this->assertSame( 'Ich bin gestern zu Hause geblieben.', $details[1]['solution'] );

		$passed = $quizzes->submit(
			$student,
			$quiz,
			array(
				'q_gap'   => array( 'bin', 'gefahren', 'war' ),
				'q_order' => array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben' ),
			)
		);
		$this->assertSame( 2, $passed['score'] );
		$this->assertTrue( $quizzes->has_passed( $student, $quiz ) );
	}

	public function test_solutions_stay_hidden_in_results_unless_enabled(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array(), self::questions() );
		$student = $this->enrolled_student( $course );
		$quizzes = $this->lms()->quizzes();

		$result  = $quizzes->submit( $student, $quiz, array() );
		$details = $quizzes->result_details( $quiz, $quizzes->get_attempt( $student, $result['attempt_id'] ) );

		$this->assertSame( '', $details[0]['gaps'][0]['solution'] );
		$this->assertSame( '', $details[1]['solution'] );
		$this->assertSame( '', $details[0]['explanation'] );
	}

	public function test_rest_submission_with_typed_answers(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array(), self::questions() );
		$student = $this->enrolled_student( $course );
		wp_set_current_user( $student );

		$request = new \WP_REST_Request( 'POST', '/dlms/v1/quizzes/' . $quiz . '/attempts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'answers' => array(
						'q_gap'   => array( 'bin', 'gefahren', 'war' ),
						'q_order' => array( 'w_ich', 'w_bin', 'w_gestern', 'w_zu', 'w_geblieben' ),
					),
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['passed'] );
	}

	public function test_quiz_form_renders_gaps_and_blocks_without_solutions(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array(), self::questions() );
		$student = $this->enrolled_student( $course );
		wp_set_current_user( $student );

		$this->go_to( get_permalink( $quiz ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();

		$this->assertSame( 3, substr_count( $html, 'name="dlms_answers[q_gap][]"' ) );
		$this->assertSame( 5, substr_count( $html, 'name="dlms_answers[q_order][]"' ) );
		$this->assertStringContainsString( 'data-dlms-chars', $html );
		$this->assertStringNotContainsString( 'gefahren', $html );
		$this->assertStringNotContainsString( '{bin}', $html );
		$this->assertStringNotContainsString( 'Gestern bin ich', $html );
		$this->assertStringContainsString( 'data-dlms-order="drag"', $html, 'Word order defaults to drag and drop.' );
	}

	public function test_sentence_end_comes_from_the_sentence_to_translate(): void {
		$this->assertSame( '?', Questions::sentence_end( array( 'text' => 'Did you visit your grandma?' ) ) );
		$this->assertSame( '!', Questions::sentence_end( array( 'text' => 'Brush your teeth!' ) ) );
		$this->assertSame( '!', Questions::sentence_end( array( 'text' => 'Sit down, please! (Sie)' ) ) );
		$this->assertSame( '.', Questions::sentence_end( array( 'text' => "I'm drinking tea because I have a cold. (weil)" ) ) );
		$this->assertSame( '.', Questions::sentence_end( array( 'text' => 'Bilden Sie einen Satz' ) ) );
	}

	public function test_capitalize_and_finish(): void {
		$this->assertSame( 'Am Samstag', Questions::capitalize( 'am Samstag' ) );
		$this->assertSame( 'Über', Questions::capitalize( 'über' ) );
		$this->assertSame( 'meinen Geburtstag.', Questions::finish( 'meinen Geburtstag', '.' ) );
		$this->assertSame( 'Peter kommt später?', Questions::finish( 'Peter kommt später,', '?' ) );
		$this->assertSame( 'z. B.', Questions::finish( 'z. B.', '.' ), 'A block that already ends with a mark keeps it.' );
	}

	public function test_dropdowns_start_with_a_capital_and_end_with_the_mark(): void {
		$raw               = self::questions();
		$raw[1]['display'] = 'select';
		$raw[1]['text']    = 'Did you stay at home yesterday?';
		$course            = $this->create_course();
		$lesson            = $this->create_lesson( $course, 1 );
		$quiz              = $this->create_quiz( $course, $lesson, 1, array(), $raw );
		wp_set_current_user( $this->enrolled_student( $course ) );

		$html = $this->lms()->renderer()->quiz_view( $quiz );

		$this->assertStringContainsString( 'data-dlms-ending="?"', $html );
		preg_match_all( '/<select name="dlms_answers\[q_order\]\[\]".*?<\/select>/s', $html, $selects );
		$this->assertCount( 5, $selects[0] );
		$this->assertStringContainsString( 'data-text="ich">Ich</option>', $selects[0][0], 'First position: capital letter.' );
		$this->assertStringContainsString( 'data-text="ich">ich</option>', $selects[0][1] );
		$this->assertStringContainsString( 'data-text="geblieben">geblieben?</option>', $selects[0][4], 'Last position: end mark.' );
	}

	public function test_word_order_display_is_drag_unless_select(): void {
		$raw               = self::questions();
		$raw[1]['display'] = 'select';
		$raw[]             = array_merge(
			$raw[1],
			array(
				'id'      => 'q_order2',
				'display' => 'something else',
			)
		);
		$raw[0]['display'] = 'select';

		$questions = Questions::sanitize( $raw );

		$this->assertSame( 'select', $questions[1]['display'] );
		$this->assertSame( 'drag', $questions[2]['display'] );
		$this->assertArrayNotHasKey( 'display', $questions[0], 'Only word-order questions have a display.' );
		$this->assertSame( 'drag', Questions::display( array( 'type' => 'word_order' ) ), 'Questions saved before the setting existed.' );
		$this->assertSame( 'select', Questions::public_view( Questions::playable( $questions ) )[1]['display'] );
	}

	public function test_quiz_form_marks_dropdown_questions(): void {
		$raw               = self::questions();
		$raw[1]['display'] = 'select';
		$course            = $this->create_course();
		$lesson            = $this->create_lesson( $course, 1 );
		$quiz              = $this->create_quiz( $course, $lesson, 1, array(), $raw );
		wp_set_current_user( $this->enrolled_student( $course ) );

		$html = $this->lms()->renderer()->quiz_view( $quiz );

		$this->assertStringContainsString( 'class="dlms-order dlms-order--select" data-dlms-order="select"', $html );
		$this->assertSame( 5, substr_count( $html, 'name="dlms_answers[q_order][]"' ) );
	}
}
