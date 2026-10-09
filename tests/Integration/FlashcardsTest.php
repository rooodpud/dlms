<?php
/**
 * Cards with meanings, word cards, flashcard decks and the "slow" switch.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Admin\AudioPage;
use DeutschLMS\Content\AudioClips;
use DeutschLMS\Frontend\Assets;

/**
 * @covers \DeutschLMS\Frontend\Shortcodes
 * @covers \DeutschLMS\Frontend\Assets
 * @covers \DeutschLMS\Content\AudioClips
 * @covers \DeutschLMS\Admin\AudioPage
 */
final class FlashcardsTest extends TestCase {

	public function test_noun_card_shows_meanings_with_their_language(): void {
		$html = do_shortcode( '[dlms_noun der Tisch en="table" tl="mesa"]' );

		$this->assertStringContainsString( 'dlms-card dlms-noun-card dlms-article--der', $html );
		$this->assertStringContainsString( '<span class="dlms-card__meaning dlms-card__meaning--en" lang="en">table</span>', $html );
		$this->assertStringContainsString( '<span class="dlms-card__meaning dlms-card__meaning--tl" lang="tl">mesa</span>', $html );
		// English before Tagalog, both before the play button.
		$this->assertLessThan( strpos( $html, 'lang="tl"' ), strpos( $html, 'lang="en"' ) );
		$this->assertLessThan( strpos( $html, 'dlms-play' ), strpos( $html, 'dlms-card__meanings' ) );
	}

	public function test_noun_card_without_meanings_has_no_meaning_box(): void {
		$this->assertStringNotContainsString( 'dlms-card__meanings', do_shortcode( '[dlms_noun die Lampe]' ) );
	}

	public function test_meanings_are_escaped(): void {
		$html = do_shortcode( '[dlms_noun das Sofa en="<b>sofa</b>"]' );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;sofa&lt;/b&gt;', $html );
	}

	public function test_word_card_takes_several_words_and_says_them(): void {
		$html = do_shortcode( '[dlms_word Guten Morgen en="Good morning" tl="Magandang umaga" picture="icon:sun"]' );

		$this->assertStringContainsString( 'dlms-card dlms-word-card has-audio', $html );
		$this->assertStringContainsString( '<span class="dlms-card__text dlms-word-card__text" lang="de" translate="no">Guten Morgen</span>', $html );
		$this->assertStringContainsString( 'data-dlms-say="Guten Morgen"', $html );
		$this->assertStringContainsString( 'lang="tl">Magandang umaga</span>', $html );
		$this->assertStringContainsString( '<svg', $html );
	}

	public function test_word_card_can_say_another_text(): void {
		$html = do_shortcode( '[dlms_word 21 say="einundzwanzig" audio="yes"]' );
		$this->assertStringContainsString( 'lang="de" translate="no">21</span>', $html );
		$this->assertStringContainsString( 'data-dlms-say="einundzwanzig"', $html );
	}

	public function test_word_card_plays_the_recording_of_its_text(): void {
		$audio = self::factory()->attachment->create(
			array(
				'file'           => 'hallo.mp3',
				'post_mime_type' => 'audio/mpeg',
			)
		);
		AudioClips::save( 'Hallo!', $audio );
		$this->assertStringContainsString( 'data-dlms-src=', do_shortcode( '[dlms_word Hallo!]' ) );
	}

	public function test_word_card_without_audio_or_word(): void {
		$this->assertStringNotContainsString( 'dlms-play', do_shortcode( '[dlms_word Tschüss audio="no"]' ) );
		$this->assertSame( '', do_shortcode( '[dlms_word word=""]' ) );
	}

	public function test_flashcards_wrap_the_cards_and_load_the_deck_script(): void {
		$html = do_shortcode( '[dlms_flashcards title="Grüße" front="german"][dlms_word Hallo][dlms_noun der Morgen][/dlms_flashcards]' );

		$this->assertStringContainsString( '<div class="dlms dlms-flashcards" data-front="german">', $html );
		$this->assertStringContainsString( '<strong>Grüße</strong>', $html );
		$this->assertStringContainsString( 'class="dlms-speed"', $html );
		$this->assertSame( 2, substr_count( $html, 'role="listitem"' ) );
		$this->assertTrue( wp_script_is( Assets::CARDS, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::AUDIO, 'enqueued' ) );
	}

	public function test_flashcards_front_defaults_to_the_picture(): void {
		$html = do_shortcode( '[dlms_flashcards front="other"][dlms_word Hallo][/dlms_flashcards]' );
		$this->assertStringContainsString( 'data-front="picture"', $html );
	}

	public function test_empty_flashcards_render_nothing(): void {
		$this->assertSame( '', do_shortcode( '[dlms_flashcards][/dlms_flashcards]' ) );
	}

	public function test_dialogue_line_prefers_the_speakers_own_recording(): void {
		$word = self::factory()->attachment->create(
			array(
				'file'           => 'guten-abend.mp3',
				'post_mime_type' => 'audio/mpeg',
			)
		);
		$joel = self::factory()->attachment->create(
			array(
				'file'           => 'joel-guten-abend.mp3',
				'post_mime_type' => 'audio/mpeg',
			)
		);
		AudioClips::save( 'Guten Abend!', $word );
		AudioClips::save( 'Joel: Guten Abend!', $joel );

		$html = do_shortcode(
			'[dlms_dialog voices="Joel=male, Maria=female"]
Joel: Guten Abend!
Maria: Guten Abend!
[/dlms_dialog]'
		);
		$this->assertStringContainsString( 'joel-guten-abend.mp3', $html );
		// Maria has no own recording: the text's recording plays.
		$this->assertSame( 1, substr_count( $html, 'joel-guten-abend.mp3' ) );
		$this->assertSame( 1, substr_count( $html, '/guten-abend.mp3' ) );
	}

	public function test_audio_page_lists_word_cards_and_speaker_recordings(): void {
		$joel = self::factory()->attachment->create(
			array(
				'file'           => 'joel-hallo.mp3',
				'post_mime_type' => 'audio/mpeg',
			)
		);
		AudioClips::save( 'Joel: Hallo!', $joel );
		$content = '[dlms_flashcards][dlms_word Guten Morgen en="Good morning"][dlms_word 21 say="einundzwanzig"][dlms_word Tschüss audio="no"][/dlms_flashcards]'
			. '[dlms_dialog]
Joel: Hallo!
Maria: Hallo!
[/dlms_dialog]';

		$texts = array_column( AudioPage::content_texts( $content ), 'text' );
		$this->assertContains( 'Guten Morgen', $texts );
		$this->assertContains( 'einundzwanzig', $texts );
		$this->assertNotContains( 'Tschüss', $texts );
		$this->assertContains( 'Joel: Hallo!', $texts );
		$this->assertContains( 'Hallo!', $texts );
	}

	public function test_speed_switch_and_dialogue_bar(): void {
		$speed = do_shortcode( '[dlms_speed]' );
		$this->assertStringContainsString( '<button type="button" class="dlms-speed" aria-pressed="false"', $speed );
		$this->assertStringContainsString( 'class="dlms-speed__check"', $speed, 'A tick in the box while slow speech is on.' );
		$this->assertStringContainsString( 'Slow speech', $speed );
		$dialog = do_shortcode( "[dlms_dialog]\nMaria: Hallo!\nJoel: Hallo, Maria!\n[/dlms_dialog]" );
		$this->assertStringContainsString( 'dlms-play-all', $dialog );
		$this->assertStringContainsString( 'dlms-speed', $dialog );
	}
}
