<?php
/**
 * Audio: the audio library, [dlms_say], noun card buttons and listening
 * questions.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Admin\AudioPage;
use DeutschLMS\Content\AudioClips;
use DeutschLMS\Quiz\Questions;

/**
 * @covers \DeutschLMS\Content\AudioClips
 * @covers \DeutschLMS\Rest\AudioClipsController
 * @covers \DeutschLMS\Admin\AudioPage
 * @covers \DeutschLMS\Frontend\Shortcodes
 * @covers \DeutschLMS\Quiz\Questions
 * @covers \DeutschLMS\Quiz\QuizService
 */
final class AudioTest extends TestCase {

	/**
	 * An audio attachment.
	 *
	 * @param string $file File name.
	 * @return int
	 */
	private function audio_file( string $file = 'guten-morgen.mp3' ): int {
		return self::factory()->attachment->create(
			array(
				'file'           => $file,
				'post_mime_type' => 'audio/mpeg',
				'post_title'     => $file,
			)
		);
	}

	/**
	 * The quiz page's content for the current user.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	private function page( string $url ): string {
		$this->go_to( $url );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		return $html;
	}

	public function test_texts_share_a_key_regardless_of_case_quotes_and_end_marks(): void {
		$key = AudioClips::key_for( 'Guten Morgen!' );
		$this->assertSame( $key, AudioClips::key_for( '  guten   morgen ' ) );
		$this->assertSame( $key, AudioClips::key_for( '„Guten Morgen“.' ) );
		$this->assertSame( $key, AudioClips::key_for( '<strong>Guten</strong> Morgen' ) );
		$this->assertNotSame( $key, AudioClips::key_for( 'Guten Abend!' ) );
		$this->assertSame( '', AudioClips::key_for( ' ?! ' ) );
	}

	public function test_library_keeps_only_audio_files(): void {
		$audio = $this->audio_file();
		$image = self::factory()->attachment->create(
			array(
				'file'           => 'bild.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertFalse( AudioClips::save( 'Guten Morgen!', $image ) );
		$this->assertSame( 0, AudioClips::media_for( 'Guten Morgen!' ) );

		$this->assertTrue( AudioClips::save( 'Guten Morgen!', $audio ) );
		$this->assertSame( $audio, AudioClips::media_for( 'guten Morgen' ) );
		$this->assertStringEndsWith( 'guten-morgen.mp3', AudioClips::source( 'Guten Morgen' )['src'] );

		$this->assertTrue( AudioClips::save( 'Guten Morgen', 0 ) );
		$this->assertSame( 0, AudioClips::media_for( 'Guten Morgen!' ) );
		$this->assertSame( array(), AudioClips::stored() );
	}

	public function test_say_shortcode_uses_the_browser_voice_until_there_is_a_recording(): void {
		$html = do_shortcode( '[dlms_say]Guten Morgen, <strong>Housekeeping</strong>![/dlms_say]' );
		$this->assertStringContainsString( 'class="dlms dlms-say"', $html );
		$this->assertStringContainsString( 'data-dlms-say="Guten Morgen, Housekeeping!"', $html );
		$this->assertStringNotContainsString( 'data-dlms-src', $html );
		$this->assertStringContainsString( '<span class="dlms-say__text" lang="de" translate="no">Guten Morgen, <strong>Housekeeping</strong>!</span>', $html );
		$this->assertTrue( wp_script_is( 'dlms-audio', 'enqueued' ), 'The play script loads, also for visitors.' );

		AudioClips::save( 'Guten Morgen, Housekeeping', $this->audio_file() );
		$html = do_shortcode( '[dlms_say]Guten Morgen, <strong>Housekeeping</strong>![/dlms_say]' );
		$this->assertStringContainsString( 'data-dlms-src="', $html );
		$this->assertStringContainsString( 'data-dlms-say=', $html, 'The text stays as a fallback.' );

		$only = do_shortcode( '[dlms_say show="no"]Guten Morgen, Housekeeping![/dlms_say]' );
		$this->assertStringNotContainsString( 'dlms-say__text', $only );
		$this->assertStringNotContainsString( 'data-dlms-say', $only, 'A listening task with a recording keeps the text out of the page.' );

		$this->assertSame( '', do_shortcode( '[dlms_say][/dlms_say]' ) );
		$this->assertStringContainsString( 'data-dlms-say="Hallo"', do_shortcode( '[dlms_say text="Hallo"]' ) );
	}

	public function test_say_shortcode_asks_for_a_voice(): void {
		$this->assertStringContainsString( 'data-dlms-voice="female"', do_shortcode( '[dlms_say voice="female"]Ich bin fertig.[/dlms_say]' ) );
		$this->assertStringContainsString( 'data-dlms-voice="male"', do_shortcode( '[dlms_say voice="Mann"]Hier ist noch ein Fleck.[/dlms_say]' ) );
		$this->assertStringNotContainsString( 'data-dlms-voice', do_shortcode( '[dlms_say voice="robot"]Hallo[/dlms_say]' ) );

		$this->assertSame(
			array(
				'marco'       => 'male',
				'amina'       => 'female',
				'frau berger' => 'female',
			),
			AudioClips::voices( 'Marco=male, Amina: f; Frau Berger=weiblich, Tomasz=?, =male' )
		);
	}

	public function test_dialog_shortcode(): void {
		// As the shortcode block passes it: wpautop's line breaks.
		$html = do_shortcode(
			"[dlms_dialog voices=\"Marco=male, Amina=female\"]<br />\nMarco: Guten Morgen, Amina!<br />\n<strong>Amina:</strong> Danke, [dlms_noun der Gast style=\"inline\"]!<br />\nAlles klar.<br />\n[/dlms_dialog]"
		);
		$this->assertStringContainsString( 'class="dlms dlms-dialog"', $html );
		$this->assertSame( 1, substr_count( $html, 'class="dlms-play-all"' ) );
		$this->assertSame( 3, substr_count( $html, '<li class="dlms-dialog__line' ) );
		$this->assertStringContainsString( '<li class="dlms-dialog__line dlms-dialog__line--male"><button type="button" class="dlms-play" data-dlms-say="Guten Morgen, Amina!" data-dlms-voice="male"', $html );
		$this->assertStringContainsString( '<strong class="dlms-dialog__speaker">Marco:</strong> <span class="dlms-say__text" lang="de" translate="no">Guten Morgen, Amina!</span>', $html );
		$this->assertStringContainsString( 'data-dlms-say="Danke, der Gast!" data-dlms-voice="female"', $html );
		$this->assertStringContainsString( 'dlms-article--der', $html, 'Inline nouns work in a line.' );
		$this->assertStringContainsString( '<li class="dlms-dialog__line"><button type="button" class="dlms-play" data-dlms-say="Alles klar."', $html, 'A line without a speaker.' );

		$this->assertSame( '', do_shortcode( '[dlms_dialog][/dlms_dialog]' ) );
		$this->assertSame(
			array(
				array(
					'text' => 'Guten Morgen!',
					'kind' => 'sentence',
				),
				array(
					'text' => 'Danke.',
					'kind' => 'sentence',
				),
			),
			AudioPage::content_texts( "[dlms_dialog voices=\"Marco=male\"]\nMarco: Guten Morgen!\nAmina: Danke.\n[/dlms_dialog]" )
		);
	}

	public function test_noun_cards_say_the_noun_with_its_article(): void {
		$card = do_shortcode( '[dlms_noun der Kissenbezug as="ein"]' );
		$this->assertStringContainsString( 'has-audio', $card );
		$this->assertStringContainsString( 'data-dlms-say="der Kissenbezug"', $card );

		$this->assertStringNotContainsString( 'dlms-play', do_shortcode( '[dlms_noun der Eimer audio="no"]' ) );
		$this->assertStringNotContainsString( 'dlms-play', do_shortcode( '[dlms_noun der Eimer style="inline"]' ) );
	}

	public function test_listening_questions(): void {
		$audio     = $this->audio_file( 'frage.mp3' );
		$questions = Questions::sanitize(
			array(
				array(
					'id'      => 'q_hoeren',
					'type'    => 'single',
					'text'    => 'Was möchte der Gast?',
					'listen'  => 'Können Sie bitte später wiederkommen?',
					'answers' => array(
						array(
							'id'      => 'a_spaeter',
							'text'    => 'Die Reinigung später',
							'correct' => true,
						),
						array(
							'id'   => 'a_jetzt',
							'text' => 'Die Reinigung jetzt',
						),
					),
				),
				array(
					'id'      => 'q_datei',
					'type'    => 'true_false',
					'text'    => 'Der Gast ist krank.',
					'audio'   => $audio,
					'answers' => array(
						array(
							'id'      => 'false',
							'correct' => true,
						),
					),
				),
				array(
					'id'    => 'q_bild',
					'type'  => 'fill_blank',
					'text'  => 'Ich {bin} hier.',
					'audio' => $this->create_course(),
				),
			)
		);
		$this->assertSame( 'Können Sie bitte später wiederkommen?', $questions[0]['listen'] );
		$this->assertArrayNotHasKey( 'audio', $questions[0] );
		$this->assertSame( $audio, $questions[1]['audio'] );
		$this->assertArrayNotHasKey( 'listen', $questions[1] );
		$this->assertArrayNotHasKey( 'audio', $questions[2], 'Only audio attachments.' );
		$this->assertFalse( Questions::has_audio( $questions[2] ) );

		$course  = $this->create_course();
		$lesson  = $this->create_lesson( $course, 1 );
		$quiz    = $this->create_quiz( $course, $lesson, 1, array( 'show_answers' => true ), $questions );
		$student = $this->enrolled_student( $course );
		wp_set_current_user( $student );

		$form = $this->page( get_permalink( $quiz ) );
		$this->assertSame( 2, substr_count( $form, 'dlms-quiz__listen"' ) );
		$this->assertStringContainsString( 'data-dlms-say="Können Sie bitte später wiederkommen?"', $form );
		$this->assertStringContainsString( 'frage.mp3', $form );

		$quizzes = $this->lms()->quizzes();
		$result  = $quizzes->submit( $student, $quiz, array( 'q_hoeren' => array( 'a_spaeter' ) ) );
		$details = $quizzes->result_details( $quiz, $quizzes->get_attempt( $student, $result['attempt_id'] ) );
		$this->assertSame( 'Können Sie bitte später wiederkommen?', $details[0]['listen'] );

		$html = $this->page( add_query_arg( 'dlms_attempt', $result['attempt_id'], get_permalink( $quiz ) ) );
		$this->assertStringContainsString( 'dlms-quiz-result__listen', $html );
	}

	public function test_rest_saves_a_recording(): void {
		$audio   = $this->audio_file();
		$request = new \WP_REST_Request( 'POST', '/dlms/v1/audio-clips' );
		$request->set_param( 'text', 'Guten Morgen!' );
		$request->set_param( 'media', $audio );

		wp_set_current_user( $this->create_user() );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( $this->create_user( 'administrator' ) );
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( $audio, $data['media'] );
		$this->assertSame( 'guten-morgen.mp3', $data['file'] );
		$this->assertSame( $audio, AudioClips::media_for( 'Guten Morgen' ) );

		$bad = new \WP_REST_Request( 'POST', '/dlms/v1/audio-clips' );
		$bad->set_param( 'text', 'Guten Morgen!' );
		$bad->set_param( 'media', $this->create_course() );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );

		$clear = new \WP_REST_Request( 'POST', '/dlms/v1/audio-clips' );
		$clear->set_param( 'text', 'Guten Morgen!' );
		$clear->set_param( 'media', 0 );
		rest_do_request( $clear );
		$this->assertSame( 0, AudioClips::media_for( 'Guten Morgen' ) );
	}

	public function test_audio_page_lists_texts_with_play_buttons(): void {
		$found = AudioPage::content_texts(
			'[dlms_say]Guten Morgen![/dlms_say] [dlms_noun der Eimer] [dlms_noun die Lampe style="inline"] '
			. '[dlms_noun das Tuch audio="no"] [dlms_say audio="5"]Eigene Datei[/dlms_say] [[dlms_say]Kein Code[/dlms_say]]'
		);
		$this->assertSame(
			array(
				array(
					'text' => 'Guten Morgen!',
					'kind' => 'sentence',
				),
				array(
					'text' => 'der Eimer',
					'kind' => 'word',
				),
			),
			$found
		);

		$course = $this->create_course();
		$lesson = $this->create_lesson( $course, 1 );
		wp_update_post(
			array(
				'ID'           => $lesson,
				'post_content' => '[dlms_say]Housekeeping![/dlms_say]',
			)
		);
		AudioClips::save( 'Alter Satz', $this->audio_file( 'alt.mp3' ) );
		$page  = new AudioPage( $this->lms()->question_bank() );
		$texts = array_column( $page->texts(), null, 'text' );
		$this->assertSame( 'sentence', $texts['Housekeeping!']['kind'] );
		$this->assertCount( 1, $texts['Housekeeping!']['where'] );
		$this->assertSame( 'unused', $texts['Alter Satz']['kind'] );
		$this->assertSame( 'alt.mp3', $texts['Alter Satz']['file'] );
	}
}
