<?php
/**
 * Article questions (der/die/das), noun pictures and the noun shortcodes.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\NounPictures;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\Questions;

/**
 * @covers \DeutschLMS\Quiz\Questions
 * @covers \DeutschLMS\Quiz\QuizService
 * @covers \DeutschLMS\Content\NounPictures
 * @covers \DeutschLMS\Rest\NounPicturesController
 * @covers \DeutschLMS\Frontend\Renderer
 * @covers \DeutschLMS\Frontend\Shortcodes
 * @covers \DeutschLMS\Frontend\ContentGate
 */
final class ArticleQuestionTest extends TestCase {

	/**
	 * Picture library: Lampe and Tisch have icons.
	 */
	public function set_up(): void {
		parent::set_up();
		NounPictures::save_library(
			array(
				array(
					'noun'    => 'Lampe',
					'picture' => 'icon:lamp',
				),
				array(
					'noun'    => 'Tisch',
					'picture' => 'icon:desk',
				),
			)
		);
	}

	/**
	 * Two article questions: die Lampe (has a picture) and das Sofa (none).
	 *
	 * @return array
	 */
	private static function questions(): array {
		return array(
			array(
				'id'          => 'q_lampe',
				'type'        => 'article',
				'text'        => 'Lampe',
				'explanation' => 'Nomen auf -e sind oft feminin.',
				'answers'     => array(
					array(
						'id'      => 'die',
						'correct' => true,
					),
				),
			),
			array(
				'id'      => 'q_sofa',
				'type'    => 'article',
				'text'    => 'Sofa',
				'answers' => array(
					array(
						'id'      => 'das',
						'correct' => true,
					),
				),
			),
		);
	}

	/**
	 * The quiz page's content for the current user.
	 *
	 * @param int   $quiz Quiz ID.
	 * @param array $post Form data posted to the page (go_to() clears $_POST).
	 * @return string
	 */
	private function quiz_page( int $quiz, array $post = array() ): string {
		$this->go_to( get_permalink( $quiz ) );
		$_POST = $post; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test request.
		$html  = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		$_POST = array();
		return $html;
	}

	public function test_sanitize_always_gives_der_die_das_with_one_correct(): void {
		$question = Questions::sanitize_one(
			array(
				'type'    => 'article',
				'text'    => 'Tisch',
				'picture' => 'tisch',
				'answers' => array(
					array(
						'id'      => 'das',
						'correct' => false,
					),
					array(
						'id'      => 'der',
						'correct' => true,
					),
					array(
						'id'      => 'die',
						'correct' => true,
					),
					array(
						'id'      => 'den',
						'correct' => true,
					),
				),
			)
		);

		$this->assertSame( array( 'der', 'die', 'das' ), array_column( $question['answers'], 'id' ) );
		$this->assertSame( array( 'der' ), Questions::correct_ids( $question ), 'Only the first marked article counts.' );
		$this->assertSame( 'der', Questions::article_of( $question ) );
		$this->assertSame( '', $question['picture'], 'The noun\'s own picture is the default, not stored.' );
		$this->assertSame( 'tisch', Questions::picture( $question ) );
		$this->assertTrue( Questions::is_playable( $question ) );
		$this->assertSame( 'der Tisch', QuestionBank::title_for( $question ) );
	}

	public function test_picture_choices_of_a_question(): void {
		$compound = Questions::sanitize_one(
			array(
				'type'    => 'article',
				'text'    => 'Tischlampe',
				'picture' => 'Lampe',
			)
		);
		$this->assertSame( 'lampe', $compound['picture'], 'A compound noun can use another noun\'s picture.' );
		$this->assertSame( 'icon:lamp', NounPictures::resolve( Questions::picture( $compound ) ) );

		$icon = Questions::sanitize_one(
			array(
				'type'    => 'article',
				'text'    => 'Sessel',
				'picture' => 'icon:armchair',
			)
		);
		$this->assertSame( 'icon:armchair', $icon['picture'] );

		foreach ( array( '../../etc/passwd', 'icon:../../wp-config', 'icon:gibt-es-nicht', 'media:999999' ) as $bad ) {
			$question = Questions::sanitize_one(
				array(
					'type'    => 'article',
					'text'    => 'Tisch',
					'picture' => $bad,
				)
			);
			$this->assertContains( $question['picture'], array( '', 'etcpasswd' ), $bad );
			$this->assertStringNotContainsString( '/', $question['picture'] );
		}
	}

	public function test_unmarked_question_is_incomplete(): void {
		$question = Questions::sanitize_one(
			array(
				'type' => 'article',
				'text' => 'Tisch',
			)
		);

		$this->assertFalse( Questions::is_playable( $question ) );
		$this->assertNotEmpty( Questions::problems( array( $question ) ) );
	}

	public function test_public_view_has_no_correct_article(): void {
		$view = Questions::public_view( Questions::sanitize( self::questions() ) );

		$this->assertSame( array( 'der', 'die', 'das' ), array_column( $view[0]['answers'], 'text' ) );
		$this->assertArrayNotHasKey( 'correct', $view[0]['answers'][1] );
		$this->assertArrayNotHasKey( 'explanation', $view[0] );
		$this->assertSame( 'lampe', Questions::picture( $view[0] ) );
	}

	public function test_grading_and_result_details(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array(), self::questions() );
		$student = $this->enrolled_student( $course );
		$quizzes = $this->lms()->quizzes();

		$result = $quizzes->submit(
			$student,
			$quiz,
			array(
				'q_lampe' => array( 'der' ),
				'q_sofa'  => array( 'das' ),
			)
		);
		$this->assertSame( 1, $result['score'] );

		$details = $quizzes->result_details( $quiz, $quizzes->get_attempt( $student, $result['attempt_id'] ) );
		$this->assertSame( 'article', $details[0]['type'] );
		$this->assertSame( 'lampe', $details[0]['picture'] );
		$this->assertSame( '', $details[0]['article'], 'A wrong answer does not reveal the article unless answers are shown.' );
		$this->assertSame( 'das', $details[1]['article'], 'A right answer shows the noun in its colour.' );
		$this->assertSame( array( 'der', 'die', 'das' ), array_column( $details[0]['answers'], 'label' ) );
		$this->assertTrue( $details[0]['answers'][0]['selected'] );
		$this->assertNull( $details[0]['answers'][1]['is_correct'] );
	}

	public function test_result_details_show_the_article_when_answers_are_shown(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array( 'show_answers' => true ), self::questions() );
		$student = $this->enrolled_student( $course );
		$quizzes = $this->lms()->quizzes();

		$result  = $quizzes->submit( $student, $quiz, array( 'q_lampe' => array( 'das' ) ) );
		$details = $quizzes->result_details( $quiz, $quizzes->get_attempt( $student, $result['attempt_id'] ) );

		$this->assertSame( 'die', $details[0]['article'] );
		$this->assertTrue( $details[0]['answers'][1]['is_correct'] );
		$this->assertSame( 'Nomen auf -e sind oft feminin.', $details[0]['explanation'] );

		wp_set_current_user( $student );
		$this->go_to( add_query_arg( 'dlms_attempt', $result['attempt_id'], get_permalink( $quiz ) ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		$this->assertStringContainsString( 'dlms-article-pill is-wrong">das<', $html, 'Your answer: das, not coloured.' );
		$this->assertStringContainsString( 'dlms-article-pill is-filled dlms-article--die">die<', $html, 'Correct answer: die, in red.' );
		$this->assertStringNotContainsString( '>der<', $html, 'The third article is not shown.' );
	}

	public function test_quiz_form_shows_picture_and_coloured_choices(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array(), self::questions() );
		$student = $this->enrolled_student( $course );
		wp_set_current_user( $student );

		$html = $this->quiz_page( $quiz );

		$this->assertSame( 3, substr_count( $html, 'name="dlms_answers[q_lampe][]"' ) );
		foreach ( Questions::ARTICLES as $article ) {
			$this->assertStringContainsString( 'dlms-article-choice dlms-article--' . $article, $html );
		}
		$this->assertSame( 1, substr_count( $html, '<svg' ), 'Only the noun with a picture (Lampe) shows one.' );
		$this->assertStringContainsString( 'dlms-noun-picture--icon', $html );
		$this->assertStringNotContainsString( 'feminin', $html );
		$this->assertStringNotContainsString( 'dlms-article-q dlms-article--', $html, 'The form never colours the noun by its answer.' );
		$this->assertStringContainsString( 'data-dlms-action="quiz"', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	public function test_noun_picture_library(): void {
		$this->assertSame( 'kuehlschrank', NounPictures::key_for( 'Kühlschrank' ) );
		$this->assertSame( 'icon:desk', NounPictures::resolve( 'tisch' ) );
		$this->assertSame( 'icon:desk', NounPictures::resolve( 'Tisch' ) );
		$this->assertSame( '', NounPictures::resolve( 'sofa' ) );
		$this->assertSame( 'icon:sofa', NounPictures::resolve( 'icon:sofa' ) );

		$svg = NounPictures::render( 'tisch' );
		$this->assertStringStartsWith( '<svg aria-hidden="true" focusable="false" class="dlms-noun-picture dlms-noun-picture--icon"', $svg );
		$this->assertStringContainsString( 'stroke="currentColor"', $svg );
		$this->assertSame( '', NounPictures::render( 'sofa' ) );
		$this->assertStringEndsWith( '/assets/icons/tabler/outline/desk.svg', NounPictures::preview_url( 'tisch' ) );

		$saved = NounPictures::save_library(
			array(
				array(
					'noun'    => 'Kühlschrank',
					'picture' => 'icon:fridge',
				),
				array(
					'noun'    => 'Sofa',
					'picture' => 'icon:gibt-es-nicht',
				),
				array(
					'noun'    => 'Bett',
					'picture' => 'tisch',
				),
				array(
					'noun'    => '<b>Uhr</b>',
					'picture' => 'icon:clock',
				),
			)
		);
		$this->assertSame( array( 'kuehlschrank', 'uhr' ), array_keys( $saved ), 'Only icons and images are kept; keys come from the noun.' );
		$this->assertSame( 'Kühlschrank', $saved['kuehlschrank']['noun'] );
		$this->assertSame( 'Uhr', $saved['uhr']['noun'] );
		$this->assertSame( 'icon:fridge', NounPictures::resolve( 'kuehlschrank' ) );
	}

	public function test_icons_are_sanitized(): void {
		$this->assertFalse( NounPictures::icon_exists( '../outline/sofa' ) );
		$this->assertFalse( NounPictures::icon_exists( 'Sofa' ) );
		$this->assertTrue( NounPictures::icon_exists( 'sofa' ) );
		$this->assertSame( '', NounPictures::sanitize_ref( 'media:0' ) );
		$this->assertSame( '', NounPictures::sanitize_ref( 'media:' . $this->create_course() ), 'Only image attachments.' );
	}

	public function test_rest_saves_a_noun_picture(): void {
		$request = new \WP_REST_Request( 'POST', '/dlms/v1/noun-pictures' );
		$request->set_param( 'noun', 'Sofa' );
		$request->set_param( 'picture', 'icon:sofa' );

		wp_set_current_user( $this->create_user() );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( '', NounPictures::resolve( 'sofa' ) );

		wp_set_current_user( $this->create_user( 'administrator' ) );
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( 'sofa', $data['key'] );
		$this->assertSame( 'icon:sofa', NounPictures::resolve( 'sofa' ) );
		$this->assertSame( 'icon:desk', NounPictures::resolve( 'tisch' ), 'Other nouns keep their pictures.' );

		$bad = new \WP_REST_Request( 'POST', '/dlms/v1/noun-pictures' );
		$bad->set_param( 'noun', 'Sofa' );
		$bad->set_param( 'picture', 'tisch' );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );

		$clear = new \WP_REST_Request( 'POST', '/dlms/v1/noun-pictures' );
		$clear->set_param( 'noun', 'Sofa' );
		$clear->set_param( 'picture', '' );
		rest_do_request( $clear );
		$this->assertSame( '', NounPictures::resolve( 'sofa' ) );
	}

	public function test_course_managers_try_quizzes_without_saving(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array( 'time_limit' => 5 ), self::questions() );
		$admin   = $this->create_user( 'administrator' );
		$quizzes = $this->lms()->quizzes();
		wp_set_current_user( $admin );

		$form = $this->quiz_page( $quiz );
		$this->assertStringContainsString( 'dlms-quiz__form--trial', $form );
		$this->assertStringContainsString( 'name="dlms_trial_nonce"', $form );
		$this->assertStringNotContainsString( 'data-dlms-action="quiz"', $form, 'Not sent through the REST API.' );
		$this->assertStringNotContainsString( 'data-dlms-time-remaining', $form, 'No clock for a trial.' );
		$this->assertStringNotContainsString( 'disabled', $form );
		$this->assertStringContainsString( 'type="submit"', $form );

		$result = $this->quiz_page(
			$quiz,
			array(
				'dlms_trial_nonce' => wp_create_nonce( \DeutschLMS\Frontend\Renderer::trial_nonce_action( $quiz ) ),
				'dlms_trial_draw'  => '',
				'dlms_answers'     => array(
					'q_lampe' => array( 'die' ),
					'q_sofa'  => array( 'der' ),
				),
			)
		);

		$this->assertStringContainsString( 'dlms-quiz-result', $result );
		$this->assertStringContainsString( 'dlms-quiz__trial', $result );
		$this->assertSame( 1, substr_count( $result, 'dlms-quiz-result__question is-correct' ) );
		$this->assertSame( 2, substr_count( $result, 'class="dlms-article-pill' ), 'Only the given article per question (answers are not shown in this quiz).' );
		$this->assertStringContainsString( 'dlms-article-pill is-filled dlms-article--die', $result, 'A right answer is shown in its colour.' );
		$this->assertStringContainsString( 'dlms-article-pill is-wrong">der<', $result, 'A wrong answer is not coloured.' );
		$this->assertSame( array(), $quizzes->attempts( $admin, $quiz ), 'A trial is never stored.' );
		$this->assertFalse( $quizzes->has_passed( $admin, $quiz ) );

		$again = $this->quiz_page(
			$quiz,
			array(
				'dlms_trial_nonce' => 'wrong',
				'dlms_answers'     => array( 'q_lampe' => array( 'die' ) ),
			)
		);
		$this->assertStringNotContainsString( 'dlms-quiz-result', $again, 'A bad nonce shows the form again.' );
	}

	public function test_trial_of_a_random_quiz_is_graded_on_the_questions_shown(): void {
		$course   = $this->create_course();
		$lesson   = $this->create_lesson( $course, 1 );
		$category = (int) wp_insert_term( 'Artikel', \DeutschLMS\Content\PostTypes::QUESTION_CATEGORY )['term_id'];
		foreach ( self::questions() as $question ) {
			$this->lms()->question_bank()->create( 1, $question, array( 'category' => array( $category ) ) );
		}
		$quiz = $this->create_quiz( $course, $lesson );
		update_post_meta(
			$quiz,
			\DeutschLMS\Content\Meta::QUIZ_ITEMS,
			array(
				array(
					'kind'     => QuestionBank::KIND_RANDOM,
					'count'    => 1,
					'category' => $category,
				),
			)
		);
		$quizzes = $this->lms()->quizzes();

		$shown = $quizzes->trial_questions( $quiz );
		$this->assertCount( 1, $shown['questions'] );
		$this->assertNotSame( '', $shown['draw'] );

		$asked = $shown['questions'][0];
		$trial = $quizzes->trial( $quiz, array( $asked['id'] => array( Questions::article_of( $asked ) ) ), json_decode( $shown['draw'], true ) );
		$this->assertSame( array( $asked['id'] ), array_column( $trial['questions'], 'id' ) );
		$this->assertSame( 100.0, (float) $trial['attempt']['percent'] );
	}

	public function test_noun_shortcodes(): void {
		$card = do_shortcode( '[dlms_noun der Tisch]' );
		$this->assertStringContainsString( 'dlms-noun-card dlms-article--der', $card );
		$this->assertStringContainsString( '<svg', $card );
		$this->assertStringContainsString( '<strong class="dlms-noun-card__article">der</strong>', $card );

		$inline = do_shortcode( '[dlms_noun der Tisch as="ein" style="inline"]' );
		$this->assertSame( '<span class="dlms dlms-noun dlms-article--der"><strong>ein</strong> Tisch</span>', $inline );

		$no_picture = do_shortcode( '[dlms_noun article="die" word="Lampe" picture=""]' );
		$this->assertStringContainsString( 'dlms-article--die', $no_picture );
		$this->assertStringNotContainsString( '<svg', $no_picture );

		$other = do_shortcode( '[dlms_noun die Tischlampe picture="lampe"]' );
		$this->assertStringContainsString( '<svg', $other );
		$icon = do_shortcode( '[dlms_noun der Sessel picture="icon:armchair"]' );
		$this->assertStringContainsString( '<svg', $icon );
		$this->assertStringNotContainsString( '<svg', do_shortcode( '[dlms_noun das Sofa]' ), 'No picture in the library yet.' );

		$this->assertSame( '', do_shortcode( '[dlms_noun den Tisch]' ) );

		$grid = do_shortcode( "[dlms_nouns]<br />\n[dlms_noun die Lampe]<br />\n[dlms_noun das Bett][/dlms_nouns]" );
		$this->assertStringStartsWith( '<span class="dlms dlms-nouns" role="list">', $grid );
		$this->assertSame( 2, substr_count( $grid, 'role="listitem"' ) );
		$this->assertStringNotContainsString( '<br', $grid );

		$legend = do_shortcode( '[dlms_article_legend]' );
		$this->assertSame( 3, substr_count( $legend, 'dlms-legend__item' ) );
	}

	public function test_noun_shortcodes_keep_the_automatic_lesson_output(): void {
		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$student = $this->enrolled_student( $course );
		wp_update_post(
			array(
				'ID'           => $lesson,
				'post_content' => '[dlms_nouns][dlms_noun der Tisch][/dlms_nouns]',
			)
		);
		wp_set_current_user( $student );

		$this->go_to( get_permalink( $lesson ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();

		$this->assertStringContainsString( 'dlms-noun-card', $html );
		$this->assertStringContainsString( 'dlms-step-nav', $html, 'Content shortcodes do not switch off the course UI.' );
	}
}
