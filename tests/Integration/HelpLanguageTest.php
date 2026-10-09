<?php
/**
 * Help language switch: texts in German, English and Tagalog.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Content\Meta;
use DeutschLMS\Frontend\HelpLanguage;
use DeutschLMS\Quiz\Questions;
use WP_REST_Request;

// The plugin's own strings, looked up in its translations.
// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment

/**
 * @covers \DeutschLMS\Frontend\HelpLanguage
 * @covers \DeutschLMS\Rest\HelpLanguageController
 * @covers \DeutschLMS\Frontend\Shortcodes
 * @covers \DeutschLMS\Frontend\ContentGate
 * @covers \DeutschLMS\Quiz\Questions
 */
final class HelpLanguageTest extends TestCase {

	public function tear_down(): void {
		HelpLanguage::reset();
		parent::tear_down();
	}

	/**
	 * A course with English and Tagalog, English for new learners.
	 *
	 * @return int
	 */
	private function course_with_help(): int {
		$course = $this->create_course( array( 'title' => 'A1 – Deutsch (Tagalog)' ) );
		update_post_meta( $course, Meta::HELP_LANGUAGES, array( 'en', 'tl' ) );
		update_post_meta( $course, Meta::HELP_DEFAULT, 'en' );
		return $course;
	}

	public function test_course_languages_start_with_german_and_ignore_unknown_codes(): void {
		$course = $this->create_course();
		$this->assertSame( array(), HelpLanguage::course_languages( $course ) );

		update_post_meta( $course, Meta::HELP_LANGUAGES, array( 'tl', 'xx', 'de', 'en' ) );
		$this->assertSame( array( 'de', 'en', 'tl' ), HelpLanguage::course_languages( $course ) );
		$this->assertSame( array( 'en', 'tl' ), HelpLanguage::sanitize_codes( 'tl, en de xx' ) );
		$this->assertSame( 'de', HelpLanguage::course_default( $course ) );

		update_post_meta( $course, Meta::HELP_DEFAULT, 'en' );
		$this->assertSame( 'en', HelpLanguage::course_default( $course ) );
	}

	public function test_without_help_languages_texts_are_plain(): void {
		HelpLanguage::use_course( $this->create_course() );

		$this->assertFalse( HelpLanguage::is_active() );
		$this->assertSame( 'Next', dlms_t( __( 'Next', 'deutschlms' ) ) );
		$this->assertSame( '3 questions', dlms_t( _n( '%d question', '%d questions', 3, 'deutschlms' ), 3 ) );
		$this->assertSame( '', HelpLanguage::switcher() );
		$this->assertSame( 'Hören', do_shortcode( '[dlms_t de="Hören" en="Listen"]' ) );
		$this->assertSame( 'Help', do_shortcode( '[dlms_lang en]Help[/dlms_lang]' ) );
	}

	public function test_plugin_texts_are_printed_in_every_language(): void {
		HelpLanguage::use_course( $this->course_with_help() );

		$html = dlms_t( __( 'Next', 'deutschlms' ) );
		$this->assertStringContainsString( '<span class="dlms-l" data-dlms-l="de" lang="de">Weiter</span>', $html );
		$this->assertStringContainsString( '<span class="dlms-l" data-dlms-l="en" lang="en">Next</span>', $html );
		$this->assertStringContainsString( '<span class="dlms-l" data-dlms-l="tl" lang="tl">Susunod</span>', $html );

		$plural = dlms_t( _n( '%d question', '%d questions', 3, 'deutschlms' ), 3 );
		$this->assertStringContainsString( '>3 Fragen<', $plural );
		$this->assertStringContainsString( '>3 questions<', $plural );
		$this->assertStringContainsString( '>3 tanong<', $plural );

		$back = dlms_t( __( 'Back to %s', 'deutschlms' ), '<A1>' );
		$this->assertStringContainsString( 'lang="en">Back to &lt;A1&gt;</span>', $back );
		$this->assertStringContainsString( 'lang="tl">Bumalik sa &lt;A1&gt;</span>', $back );
	}

	public function test_reading_the_languages_leaves_the_site_locale_alone(): void {
		HelpLanguage::use_course( $this->course_with_help() );
		$controller = \WP_Translation_Controller::get_instance();
		$before     = $controller->get_locale();

		dlms_t( __( 'Next', 'deutschlms' ) );

		// load_textdomain() with the files' locales would switch every translation of the site.
		$this->assertSame( $before, $controller->get_locale() );
	}

	public function test_languages_with_the_same_text_share_one_element(): void {
		HelpLanguage::use_course( $this->course_with_help() );

		// "Quiz" is German and English; Tagalog has its own word.
		$html = dlms_t( __( 'Quiz', 'deutschlms' ) );
		$this->assertStringContainsString( 'data-dlms-l="de en" lang="de">Quiz</span>', $html );
		$this->assertStringContainsString( 'data-dlms-l="tl" lang="tl">Pagsasanay</span>', $html );

		// Not a plugin string: the same in every language, no elements.
		$this->assertSame( 'Maria &amp; Joel', dlms_t( 'Maria & Joel' ) );
	}

	public function test_texts_without_tagalog_fall_back_to_english(): void {
		HelpLanguage::use_course( $this->course_with_help() );
		$html = HelpLanguage::texts(
			array(
				'de' => 'Lernkarten',
				'en' => 'Flashcards',
			)
		);
		$this->assertStringContainsString( 'data-dlms-l="en tl" lang="en">Flashcards</span>', $html );
	}

	public function test_attributes_start_in_the_current_language_and_carry_their_key(): void {
		HelpLanguage::use_course( $this->course_with_help() );

		$attr = dlms_attr( 'aria-label', __( 'Listen: %s', 'deutschlms' ), 'Guten Tag' );
		$this->assertStringStartsWith( 'aria-label="Listen: Guten Tag" data-dlms-i18n-aria-label="', $attr );

		$config = HelpLanguage::config();
		$this->assertSame( array( 'de', 'en', 'tl' ), $config['languages'] );
		$this->assertSame( 'en', $config['current'] );
		$formats = reset( $config['attrs'] );
		$this->assertSame( 'Pakinggan: %s', $formats['tl'] );
		$this->assertSame( '', $config['save'], 'Visitors save in the browser only.' );
	}

	public function test_script_strings_come_in_every_language(): void {
		HelpLanguage::use_course( $this->course_with_help() );
		$all = HelpLanguage::strings(
			array(
				'previous' => __( 'Previous', 'deutschlms' ),
				'flip'     => __( 'Turn over', 'deutschlms' ),
			)
		);
		$this->assertSame( 'Zurück', $all['de']['previous'] );
		$this->assertSame( 'Turn over', $all['en']['flip'] );
		$this->assertSame( 'Baligtarin', $all['tl']['flip'] );
	}

	public function test_the_learners_saved_language_wins_over_the_course_default(): void {
		HelpLanguage::use_course( $this->course_with_help() );
		$this->assertSame( 'en', HelpLanguage::current() );

		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$this->assertTrue( HelpLanguage::save_user_language( $user, 'tl' ) );
		$this->assertSame( 'tl', HelpLanguage::current() );
		$this->assertFalse( HelpLanguage::save_user_language( $user, 'xx' ) );

		$switch = HelpLanguage::switcher();
		$this->assertStringContainsString( 'data-dlms-help-set="tl" lang="tl" aria-pressed="true">Tagalog</button>', $switch );
		$this->assertStringContainsString( 'data-dlms-help-set="de" lang="de" aria-pressed="false">Deutsch</button>', $switch );
	}

	public function test_the_switch_also_stays_on_screen_once_per_page(): void {
		HelpLanguage::use_course( $this->course_with_help() );
		$help = new HelpLanguage();

		ob_start();
		$help->print_dock();
		$this->assertSame( '', ob_get_clean(), 'No dock without a switch on the page.' );

		HelpLanguage::switcher();
		HelpLanguage::switcher();
		ob_start();
		$help->print_dock();
		$dock = ob_get_clean();

		$this->assertSame( 1, substr_count( $dock, 'data-dlms-help-dock' ) );
		$this->assertStringContainsString( 'aria-controls="dlms-help-dock-panel"', $dock );
		$this->assertStringContainsString( 'data-dlms-help-set="tl"', $dock );
		// The tab shows the current language's code.
		$this->assertStringContainsString( 'data-dlms-l="en" lang="en">EN</span>', $dock );
		// The tab's label follows the switch (help-language.js, config attrs).
		$this->assertContains( 'Palitan ang wika ng mga tagubilin at tulong', array_column( HelpLanguage::config()['attrs'], 'tl' ) );
	}

	public function test_rest_route_saves_the_language_of_logged_in_users(): void {
		$request = new WP_REST_Request( 'POST', '/dlms/v1/help-language' );
		$request->set_param( 'language', 'tl' );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'tl', HelpLanguage::user_language( $user ) );

		$request->set_param( 'language', 'xx' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'tl', HelpLanguage::user_language( $user ) );
	}

	public function test_instructions_show_german_with_the_translation_below(): void {
		$this->assertSame( 'Which article?', HelpLanguage::instruction( __( 'Which article?', 'deutschlms' ) ), 'Without a switch: the text as it is.' );

		HelpLanguage::use_course( $this->course_with_help() );
		$html = HelpLanguage::instruction( __( 'Which article?', 'deutschlms' ) );
		// German always, not for browser translation.
		$this->assertStringStartsWith( '<span class="dlms-instruction" lang="de" translate="no">Welcher Artikel?</span>', $html );
		// The translations follow the switch; in German nothing more.
		$this->assertStringContainsString( 'class="dlms-l dlms-instruction__help" data-dlms-l="en" lang="en">Which article?</span>', $html );
		$this->assertStringContainsString( 'data-dlms-l="tl" lang="tl">Aling artikulo?</span>', $html );
		$this->assertStringNotContainsString( 'data-dlms-l="de', $html );
	}

	public function test_asset_urls_change_when_the_file_changes(): void {
		$version = dlms_asset_version( 'assets/js/frontend.js' );
		$this->assertSame( DLMS_VERSION . '.' . filemtime( DLMS_PATH . 'assets/js/frontend.js' ), $version );
		$this->assertSame( DLMS_VERSION, dlms_asset_version( 'assets/js/missing.js' ) );
	}

	public function test_titles_get_their_translation_in_brackets(): void {
		$course = $this->course_with_help();
		$lesson = $this->create_lesson( $course, 1, array( 'title' => 'Übung 0.1 a: Wörter' ) );
		wp_update_post(
			array(
				'ID'         => $lesson,
				'post_title' => 'Übung 0.1 a: Wörter',
			)
		);
		update_post_meta(
			$lesson,
			Meta::TITLE_HELP,
			array(
				'en' => 'Words',
				'tl' => 'Mga salita',
			)
		);
		HelpLanguage::use_course( $course );

		$html = dlms_title( $lesson );
		$this->assertStringStartsWith( '<span lang="de" translate="no">Übung 0.1 a: Wörter</span>', $html );
		$this->assertStringContainsString( 'data-dlms-l="en" lang="en"><span class="dlms-title-help"> (Words)</span>', $html );
		$this->assertStringContainsString( '(Mga salita)', $html );
		$this->assertStringNotContainsString( 'data-dlms-l="de"', $html, 'German shows the title alone.' );

		$subtitle = HelpLanguage::subtitle( $lesson );
		$this->assertStringContainsString( '<p class="dlms-l dlms-subtitle" data-dlms-l="en" lang="en">Words</p>', $subtitle );

		HelpLanguage::use_course( $this->create_course() );
		$this->assertSame( 'Übung 0.1 a: Wörter', dlms_title( $lesson ) );
	}

	public function test_shortcodes_for_content_texts(): void {
		HelpLanguage::use_course( $this->course_with_help() );

		$heading = do_shortcode( '[dlms_t de="Hören und nachsprechen" en="Listen and repeat" tl="Makinig at ulitin"]' );
		$this->assertStringContainsString( 'data-dlms-l="de" lang="de">Hören und nachsprechen</span>', $heading );
		$this->assertStringContainsString( 'data-dlms-l="tl" lang="tl">Makinig at ulitin</span>', $heading );

		$help = do_shortcode( '[dlms_lang en de]In the <strong>morning</strong>[/dlms_lang]' );
		$this->assertSame( '<span class="dlms-l" data-dlms-l="en de" lang="en">In the <strong>morning</strong></span>', $help );

		$deck = do_shortcode( '[dlms_flashcards title="Lernkarten 0.1" title_en="Flashcards 0.1"][dlms_word Hallo][/dlms_flashcards]' );
		$this->assertStringContainsString( 'lang="en">Flashcards 0.1</span>', $deck );
		$this->assertStringContainsString( 'lang="de">Lernkarten 0.1</span>', $deck );
	}

	public function test_question_translations_are_kept_and_explanations_stay_out_of_the_form(): void {
		$question = Questions::sanitize_one(
			array(
				'type'             => 'true_false',
				'text'             => 'Gute Nacht sagt man am Morgen.',
				'help'             => array(
					'en' => 'You say “Gute Nacht” in the morning.',
					'xx' => 'ignored',
				),
				'explanation'      => 'Falsch.',
				'explanation_help' => array( 'tl' => 'Mali.' ),
				'answers'          => array(
					array(
						'id'      => 'false',
						'correct' => true,
					),
				),
			)
		);
		$this->assertSame( array( 'en' => 'You say “Gute Nacht” in the morning.' ), $question['help'] );
		$this->assertSame( array( 'tl' => 'Mali.' ), $question['explanation_help'] );

		$public = Questions::public_view( array( $question ) )[0];
		$this->assertArrayHasKey( 'help', $public );
		$this->assertArrayNotHasKey( 'explanation_help', $public );

		HelpLanguage::use_course( $this->course_with_help() );
		$this->assertStringContainsString( 'class="dlms-l dlms-quiz__help" data-dlms-l="en tl" lang="en">You say', HelpLanguage::question_help( $question ) );
		$explanation = HelpLanguage::explanation( $question['explanation'], $question['explanation_help'] );
		$this->assertStringContainsString( 'data-dlms-l="de en" lang="de">Falsch.</span>', $explanation );
		$this->assertStringContainsString( 'data-dlms-l="tl" lang="tl">Mali.</span>', $explanation );
	}

	public function test_course_page_starts_with_the_switch_and_marks_the_html_element(): void {
		$course = $this->course_with_help();
		$this->go_to( get_permalink( $course ) );

		$attributes = apply_filters( 'language_attributes', 'lang="de-DE"' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertStringContainsString( 'data-dlms-help="en" data-dlms-help-languages="de en tl"', $attributes );

		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		$this->assertStringStartsWith( '<div class="dlms dlms-help-switch"', $html );
		$this->assertStringContainsString( 'Instructions and help in:', $html );

		ob_start();
		( new HelpLanguage() )->print_head_script();
		$head = (string) ob_get_clean();
		$this->assertStringContainsString( 'html[data-dlms-help="tl"] .dlms-l:not([data-dlms-l~="tl"])', $head );
		$this->assertStringContainsString( 'dlmsHelpLanguage', $head );
	}
}
