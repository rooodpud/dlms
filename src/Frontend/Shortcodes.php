<?php
/**
 * Shortcodes (fallback for the blocks).
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

use DeutschLMS\Content\AudioClips;
use DeutschLMS\Content\NounPictures;
use DeutschLMS\Quiz\Questions;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode equivalents of every block, for classic content and page builders.
 *
 * [dlms_course_outline course_id="" show_topics="yes"]
 * [dlms_progress_bar course_id="" show_label="yes"]
 * [dlms_enroll_button course_id=""]
 * [dlms_mark_complete step_id=""]
 * [dlms_course_grid columns="3" per_page="12" orderby="date" show_progress="yes"]
 * [dlms_student_dashboard show_completed="yes"]
 *
 * An empty course_id/step_id means "the current course/step".
 *
 * Content shortcodes for teaching articles with colours (der blue, die red,
 * das green); they don't replace the automatic course output:
 *
 * [dlms_noun der Tisch]                      Card: picture, coloured article and noun.
 * [dlms_noun die Lampe style="inline"]       Coloured text in a sentence: "die Lampe".
 * [dlms_noun der Tisch as="ein" style="inline"]  Shows "ein Tisch", coloured as der.
 * [dlms_noun article="das" word="Sofa"]       Same with named attributes.
 * [dlms_noun der Sessel picture="icon:armchair"]  Another picture (see below).
 * [dlms_nouns] [dlms_noun …] … [/dlms_nouns] A grid of cards.
 * [dlms_article_legend]                      The three colours with their genders.
 *
 * The picture is the noun's picture from the noun picture library
 * (Courses → Noun pictures). picture="…" names another one: an icon
 * (icon:sofa), a Media Library image (media:123) or another noun (lampe for
 * die Tischlampe); picture="" shows none.
 *
 * Noun cards have a play button that says the noun with its article
 * (audio="no" leaves it out). Audio for any German text:
 *
 * [dlms_say]Guten Morgen![/dlms_say]            The text with a play button.
 * [dlms_say show="no"]Guten Morgen![/dlms_say]  Only the button (listening tasks).
 * [dlms_say audio="123"]…[/dlms_say]            Plays that audio file (attachment ID).
 * [dlms_say voice="female"]…[/dlms_say]         The browser reads with a female (or male) voice.
 *
 * A dialogue: one line per speaker, with a play button per line and one for
 * the whole dialogue (the voices are for the browser's speech):
 *
 * [dlms_dialog voices="Marco=male, Amina=female"]
 * Marco: Guten Morgen, Amina!
 * Amina: Guten Morgen, Marco!
 * [/dlms_dialog]
 *
 * A button plays the text's recording from the audio library (Courses →
 * Audio); without one, the browser reads the text with a German voice. A
 * dialogue line first looks for the speaker's own recording, saved under
 * "Name: text" (e.g. "Joel: Guten Abend!").
 *
 * Meanings, word cards and flashcards:
 *
 * [dlms_noun der Tisch en="table" tl="mesa"]   A noun card with its meanings
 *                                            (en, tl, ceb, hi: a line each).
 * [dlms_word Guten Morgen en="Good morning" tl="Magandang umaga"]
 *                                            A card for any word or phrase
 *                                            (verbs, numbers, greetings …),
 *                                            picture as for nouns; say="…"
 *                                            speaks another text (21 →
 *                                            einundzwanzig).
 * [dlms_flashcards] [dlms_noun …] [dlms_word …] … [/dlms_flashcards]
 *                                            A flashcard deck: one card at a
 *                                            time, flip, next, back, shuffle;
 *                                            front="german" shows the German
 *                                            side first. A grid without JS.
 * [dlms_speed]                               The "slow" switch: recordings and
 *                                            the browser's voice play slower.
 *                                            Dialogues and decks have it too.
 *
 * Texts for the help language switch (courses with help languages, see
 * HelpLanguage); without help languages German, or everything:
 *
 * [dlms_t de="Hören und nachsprechen" en="Listen and repeat" tl="Makinig at ulitin"]
 *                                            A short text in the learner's
 *                                            language (headings, labels).
 * [dlms_lang en]English <strong>help</strong>[/dlms_lang]
 *                                            Content shown only in these
 *                                            languages ([dlms_lang en de] =
 *                                            English and German); inline
 *                                            HTML and shortcodes allowed;
 *                                            block="yes" for a <div>.
 * [dlms_flashcards title="Lernkarten" title_en="Flashcards"]
 *                                            Deck titles in every language.
 *
 * German learning texts (cards, sentences, dialogues) carry translate="no",
 * so browser translation (e.g. Google Translate) leaves them German.
 */
final class Shortcodes {

	/**
	 * Shortcodes that lay out the course UI themselves (see ContentGate).
	 */
	public const LAYOUT = array( 'course_outline', 'progress_bar', 'enroll_button', 'mark_complete', 'course_grid', 'student_dashboard' );

	/**
	 * Meaning lines on cards: attribute => lang code, in the order shown.
	 */
	public const MEANINGS = array(
		'en'  => 'en',
		'tl'  => 'tl',
		'ceb' => 'ceb',
		'hi'  => 'hi',
	);

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'dlms_course_outline', array( $this, 'course_outline' ) );
		add_shortcode( 'dlms_progress_bar', array( $this, 'progress_bar' ) );
		add_shortcode( 'dlms_enroll_button', array( $this, 'enroll_button' ) );
		add_shortcode( 'dlms_mark_complete', array( $this, 'mark_complete' ) );
		add_shortcode( 'dlms_course_grid', array( $this, 'course_grid' ) );
		add_shortcode( 'dlms_student_dashboard', array( $this, 'student_dashboard' ) );
		add_shortcode( 'dlms_noun', array( $this, 'noun' ) );
		add_shortcode( 'dlms_nouns', array( $this, 'nouns' ) );
		add_shortcode( 'dlms_article_legend', array( $this, 'article_legend' ) );
		add_shortcode( 'dlms_say', array( $this, 'say' ) );
		add_shortcode( 'dlms_dialog', array( $this, 'dialog' ) );
		add_shortcode( 'dlms_word', array( $this, 'word' ) );
		add_shortcode( 'dlms_flashcards', array( $this, 'flashcards' ) );
		add_shortcode( 'dlms_t', array( $this, 'text' ) );
		add_shortcode( 'dlms_lang', array( $this, 'lang' ) );
		add_shortcode( 'dlms_speed', array( $this, 'speed' ) );
	}

	/**
	 * [dlms_course_outline].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function course_outline( $atts ): string {
		$atts = shortcode_atts(
			array(
				'course_id'   => 0,
				'show_topics' => 'yes',
			),
			$atts,
			'dlms_course_outline'
		);
		return $this->wrap( $this->renderer->course_outline( $this->course_id( $atts['course_id'] ), $this->flag( $atts['show_topics'] ) ) );
	}

	/**
	 * [dlms_progress_bar].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function progress_bar( $atts ): string {
		$atts = shortcode_atts(
			array(
				'course_id'  => 0,
				'show_label' => 'yes',
			),
			$atts,
			'dlms_progress_bar'
		);
		return $this->wrap( $this->renderer->progress_bar( $this->course_id( $atts['course_id'] ), $this->flag( $atts['show_label'] ) ) );
	}

	/**
	 * [dlms_enroll_button].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function enroll_button( $atts ): string {
		$atts = shortcode_atts( array( 'course_id' => 0 ), $atts, 'dlms_enroll_button' );
		return $this->wrap( $this->renderer->notice() . $this->renderer->enroll_button( $this->course_id( $atts['course_id'] ) ) );
	}

	/**
	 * [dlms_mark_complete].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function mark_complete( $atts ): string {
		$atts    = shortcode_atts( array( 'step_id' => 0 ), $atts, 'dlms_mark_complete' );
		$step_id = absint( $atts['step_id'] );
		return $this->wrap( $this->renderer->mark_complete( $step_id ? $step_id : Context::current_step_id() ) );
	}

	/**
	 * [dlms_course_grid].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function course_grid( $atts ): string {
		$atts = shortcode_atts(
			array(
				'columns'       => 3,
				'per_page'      => 12,
				'orderby'       => 'date',
				'show_progress' => 'yes',
			),
			$atts,
			'dlms_course_grid'
		);
		return $this->wrap(
			$this->renderer->course_grid(
				array(
					'columns'       => absint( $atts['columns'] ),
					'per_page'      => absint( $atts['per_page'] ),
					'orderby'       => sanitize_key( $atts['orderby'] ),
					'show_progress' => $this->flag( $atts['show_progress'] ),
				)
			)
		);
	}

	/**
	 * [dlms_student_dashboard].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function student_dashboard( $atts ): string {
		$atts = shortcode_atts( array( 'show_completed' => 'yes' ), $atts, 'dlms_student_dashboard' );
		return $this->wrap( $this->renderer->student_dashboard( array( 'show_completed' => $this->flag( $atts['show_completed'] ) ) ) );
	}

	/**
	 * [dlms_noun]: a noun with its colour-coded article.
	 *
	 * @param array|string $atts Attributes (positional: article, noun).
	 * @return string
	 */
	public function noun( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$args = shortcode_atts(
			array(
				'article' => (string) ( $atts[0] ?? '' ),
				'word'    => (string) ( $atts[1] ?? '' ),
				'picture' => null,
				'as'      => '',
				'style'   => 'card',
				'audio'   => 'yes',
			) + array_fill_keys( array_keys( self::MEANINGS ), '' ),
			$atts,
			'dlms_noun'
		);

		$article = strtolower( trim( (string) $args['article'] ) );
		$word    = trim( (string) $args['word'] );
		if ( '' === $word || ! in_array( $article, Questions::ARTICLES, true ) ) {
			return '';
		}
		$shown = '' !== trim( (string) $args['as'] ) ? trim( (string) $args['as'] ) : $article;
		Assets::enqueue();

		if ( 'inline' === $args['style'] ) {
			return sprintf(
				'<span class="dlms dlms-noun dlms-article--%1$s" lang="de" translate="no"><strong>%2$s</strong> %3$s</span>',
				esc_attr( $article ),
				esc_html( $shown ),
				esc_html( $word )
			);
		}

		$picture = null === $args['picture'] ? NounPictures::key_for( $word ) : NounPictures::sanitize_ref( (string) $args['picture'] );
		$button  = $this->flag( $args['audio'] ) ? AudioClips::button( $article . ' ' . $word, 0, array( 'css_class' => 'dlms-noun-card__play' ) ) : '';
		return sprintf(
			'<span class="dlms-card dlms-noun-card dlms-article--%1$s%5$s" role="listitem">%2$s<span class="dlms-card__text dlms-noun-card__text" lang="de" translate="no"><strong class="dlms-noun-card__article">%3$s</strong> <span class="dlms-noun-card__word">%4$s</span></span>%7$s%6$s</span>',
			esc_attr( $article ),
			NounPictures::render( $picture, 'dlms-noun-picture dlms-noun-card__picture' ),
			esc_html( $shown ),
			esc_html( $word ),
			'' !== $button ? ' has-audio' : '',
			// Safe markup from AudioClips::button().
			$button,
			// Escaped in meanings().
			$this->meanings( $args )
		);
	}

	/**
	 * [dlms_word]: a card for any German word or phrase, without an article
	 * colour: picture, the word, its meanings and a play button.
	 *
	 * @param array|string $atts Attributes (positional: the word, may be
	 *                           several words): word, picture, say, audio,
	 *                           en, tl, ceb, hi.
	 * @return string
	 */
	public function word( $atts ): string {
		$atts       = is_array( $atts ) ? $atts : array();
		$positional = array();
		foreach ( $atts as $key => $value ) {
			if ( is_int( $key ) ) {
				$positional[] = (string) $value;
			}
		}
		$args = shortcode_atts(
			array(
				'word'    => implode( ' ', $positional ),
				'picture' => null,
				'say'     => '',
				'audio'   => 'yes',
			) + array_fill_keys( array_keys( self::MEANINGS ), '' ),
			$atts,
			'dlms_word'
		);
		$word = trim( (string) $args['word'] );
		if ( '' === $word ) {
			return '';
		}
		Assets::enqueue();
		$picture = null === $args['picture'] ? NounPictures::key_for( $word ) : NounPictures::sanitize_ref( (string) $args['picture'] );
		$say     = trim( (string) $args['say'] );
		$say     = '' !== $say ? $say : $word;
		$button  = $this->flag( $args['audio'] ) ? AudioClips::button( $say, 0, array( 'css_class' => 'dlms-noun-card__play' ) ) : '';
		return sprintf(
			'<span class="dlms-card dlms-word-card%3$s" role="listitem">%1$s<span class="dlms-card__text dlms-word-card__text" lang="de" translate="no">%2$s</span>%5$s%4$s</span>',
			NounPictures::render( $picture, 'dlms-noun-picture dlms-noun-card__picture' ),
			esc_html( $word ),
			'' !== $button ? ' has-audio' : '',
			// Safe markup from AudioClips::button().
			$button,
			// Escaped in meanings().
			$this->meanings( $args )
		);
	}

	/**
	 * [dlms_flashcards]: the cards inside as a deck, one at a time, with
	 * flip, next, back and shuffle (flashcards.js). A grid without JavaScript.
	 *
	 * @param array|string $atts    Attributes: front (picture|german), title,
	 *                              title_en, title_tl … (title in a help language).
	 * @param string|null  $content [dlms_noun] and [dlms_word] shortcodes.
	 * @return string
	 */
	public function flashcards( $atts, $content = null ): string {
		$args = shortcode_atts(
			array(
				'front' => 'picture',
				'title' => '',
			),
			$atts,
			'dlms_flashcards'
		);
		// Drop the line breaks and paragraphs wpautop() put between the cards.
		$content = (string) preg_replace( '#<br\s*/?>|</?p>#i', '', (string) $content );
		$cards   = trim( do_shortcode( $content ) );
		if ( '' === $cards ) {
			return '';
		}
		Assets::enqueue();
		Assets::enqueue_flashcards();
		$title  = trim( (string) $args['title'] );
		$titles = array();
		if ( '' !== $title ) {
			$titles = array( HelpLanguage::GERMAN => $title ) + $this->translations( $atts, 'title_' );
		}
		return sprintf(
			'<div class="dlms dlms-flashcards" data-front="%1$s"><div class="dlms-flashcards__head">%2$s%4$s</div><div class="dlms-flashcards__cards dlms-nouns" role="list">%3$s</div></div>',
			'german' === $args['front'] ? 'german' : 'picture',
			'<p class="dlms-flashcards__title"><strong>' . ( $titles ? HelpLanguage::texts( $titles ) : dlms_t( __( 'Flashcards', 'deutschlms' ) ) ) . '</strong></p>',
			// Built by the card shortcodes from escaped parts.
			$cards,
			AudioClips::speed_button()
		);
	}

	/**
	 * [dlms_t de="…" en="…" tl="…"]: a short text in the learner's help
	 * language (German without help languages).
	 *
	 * @param array|string $atts Attributes: de and the help languages' codes.
	 * @return string
	 */
	public function text( $atts ): string {
		$atts  = is_array( $atts ) ? $atts : array();
		$texts = array( HelpLanguage::GERMAN => trim( (string) ( $atts[ HelpLanguage::GERMAN ] ?? '' ) ) ) + $this->translations( $atts, '' );
		return HelpLanguage::texts( $texts );
	}

	/**
	 * [dlms_lang en tl]…[/dlms_lang]: content shown only in these help
	 * languages. Without help languages on the page it is always shown.
	 *
	 * @param array|string $atts    Language codes (positional or languages="en tl"); block="yes" for a div.
	 * @param string|null  $content Content (inline HTML, shortcodes).
	 * @return string
	 */
	public function lang( $atts, $content = null ): string {
		$atts  = is_array( $atts ) ? $atts : array();
		$block = $this->flag( $atts['block'] ?? 'no' );
		$codes = array();
		foreach ( $atts as $key => $value ) {
			if ( is_int( $key ) ) {
				$codes[] = (string) $value;
			} elseif ( 'languages' === $key ) {
				$codes = array_merge( $codes, preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) );
			}
		}
		$known = array_keys( HelpLanguage::languages() );
		// In the author's order: the first one is the content's language.
		$codes = array_values( array_unique( array_intersect( array_map( 'strtolower', $codes ), $known ) ) );
		$html  = do_shortcode( (string) $content );
		if ( ! $block ) {
			// Drop the paragraphs wpautop() may wrap around inline content.
			$html = (string) preg_replace( '#^\s*</p>|<p>\s*$#i', '', $html );
		}
		if ( ! $codes || ! HelpLanguage::is_active() ) {
			return $html;
		}
		return sprintf(
			'<%1$s class="dlms-l" data-dlms-l="%2$s" lang="%3$s">%4$s</%1$s>',
			$block ? 'div' : 'span',
			esc_attr( implode( ' ', $codes ) ),
			esc_attr( $codes[0] ),
			// Post content, filtered like the rest of the post.
			$html
		);
	}

	/**
	 * Translations from shortcode attributes: help language code => text, for
	 * attributes named <prefix><code> (title_en, title_tl; or en, tl).
	 *
	 * @param mixed  $atts   Attributes.
	 * @param string $prefix Attribute prefix.
	 * @return array<string, string>
	 */
	private function translations( $atts, string $prefix ): array {
		$texts = array();
		if ( ! is_array( $atts ) ) {
			return $texts;
		}
		foreach ( HelpLanguage::translation_codes() as $code ) {
			$value = trim( (string) ( $atts[ $prefix . $code ] ?? '' ) );
			if ( '' !== $value ) {
				$texts[ $code ] = $value;
			}
		}
		return $texts;
	}

	/**
	 * [dlms_speed]: the switch for slower playback.
	 *
	 * @return string
	 */
	public function speed(): string {
		Assets::enqueue();
		return '<span class="dlms dlms-speed-bar">' . AudioClips::speed_button() . '</span>';
	}

	/**
	 * Meaning lines of a card ('' when there are none).
	 *
	 * @param array $args Shortcode attributes (en, tl, ceb, hi).
	 * @return string
	 */
	private function meanings( array $args ): string {
		$lines = '';
		foreach ( self::MEANINGS as $attribute => $lang ) {
			$text = trim( (string) ( $args[ $attribute ] ?? '' ) );
			if ( '' !== $text ) {
				$lines .= sprintf( '<span class="dlms-card__meaning dlms-card__meaning--%1$s" lang="%1$s">%2$s</span>', esc_attr( $lang ), esc_html( $text ) );
			}
		}
		return '' === $lines ? '' : '<span class="dlms-card__meanings">' . $lines . '</span>';
	}

	/**
	 * [dlms_nouns]: a grid of noun cards. Built from inline elements, so it
	 * stays valid inside the paragraph the shortcode block wraps it in.
	 *
	 * @param array|string $atts    Attributes (none).
	 * @param string|null  $content [dlms_noun] shortcodes.
	 * @return string
	 */
	public function nouns( $atts, $content = null ): string {
		unset( $atts );
		// Drop the line breaks and paragraphs wpautop() put between the cards.
		$content = (string) preg_replace( '#<br\s*/?>|</?p>#i', '', (string) $content );
		$cards   = trim( do_shortcode( $content ) );
		if ( '' === $cards ) {
			return '';
		}
		Assets::enqueue();
		return '<span class="dlms dlms-nouns" role="list">' . $cards . '</span>';
	}

	/**
	 * [dlms_article_legend]: der / die / das with their colours and genders.
	 *
	 * @return string
	 */
	public function article_legend(): string {
		Assets::enqueue();
		$genders = array(
			'der' => dlms_t( __( 'masculine', 'deutschlms' ) ),
			'die' => dlms_t( __( 'feminine', 'deutschlms' ) ),
			'das' => dlms_t( __( 'neuter', 'deutschlms' ) ),
		);
		$items   = '';
		foreach ( $genders as $article => $gender ) {
			$items .= sprintf(
				'<span class="dlms-legend__item dlms-article--%1$s" role="listitem"><strong lang="de" translate="no">%1$s</strong> <span>%2$s</span></span>',
				esc_attr( $article ),
				// Escaped by dlms_t().
				$gender
			);
		}
		return '<span class="dlms dlms-legend" role="list">' . $items . '</span>';
	}

	/**
	 * [dlms_say]: German text with a play button.
	 *
	 * @param array|string $atts    Attributes: text, audio (attachment ID), show
	 *                              (yes/no), voice (female/male).
	 * @param string|null  $content The text (inline HTML allowed).
	 * @return string
	 */
	public function say( $atts, $content = null ): string {
		$args = shortcode_atts(
			array(
				'text'  => '',
				'audio' => 0,
				'show'  => 'yes',
				'voice' => '',
			),
			$atts,
			'dlms_say'
		);
		$html = trim( do_shortcode( (string) $content ) );
		if ( '' === $html ) {
			$html = esc_html( (string) $args['text'] );
		}
		$text = AudioClips::clean_text( $html );
		if ( '' === $text ) {
			return '';
		}
		$show   = $this->flag( $args['show'] );
		$button = AudioClips::button(
			$text,
			absint( $args['audio'] ),
			array(
				'hide_text' => ! $show,
				'voice'     => (string) $args['voice'],
			)
		);
		if ( ! $show ) {
			return '<span class="dlms dlms-say dlms-say--button">' . $button . '</span>';
		}
		return '<span class="dlms dlms-say">' . $button . '<span class="dlms-say__text" lang="de" translate="no">' . wp_kses_post( $html ) . '</span></span>';
	}

	/**
	 * [dlms_dialog]: a dialogue, each line with its speaker and a play button,
	 * and a button that plays the whole dialogue line by line.
	 *
	 * @param array|string $atts    Attributes: voices ("Marco=male, Amina=female").
	 * @param string|null  $content One line per speaker: "Name: text".
	 * @return string
	 */
	public function dialog( $atts, $content = null ): string {
		$args   = shortcode_atts( array( 'voices' => '' ), $atts, 'dlms_dialog' );
		$voices = AudioClips::voices( (string) $args['voices'] );
		$items  = '';
		foreach ( AudioClips::dialog_lines( (string) $content ) as $line ) {
			$html = trim( do_shortcode( $line['html'] ) );
			$text = AudioClips::clean_text( $html );
			if ( '' === $text ) {
				continue;
			}
			$voice = $voices[ mb_strtolower( $line['speaker'] ) ] ?? '';
			// The speaker's own recording ("Joel: Guten Abend!") comes first, so
			// a line that is also a word card or a phrase keeps its voice.
			$media  = '' !== $line['speaker'] ? AudioClips::media_for( $line['speaker'] . ': ' . $text ) : 0;
			$items .= sprintf(
				'<li class="dlms-dialog__line%1$s">%2$s<span class="dlms-dialog__text">%3$s<span class="dlms-say__text" lang="de" translate="no">%4$s</span></span></li>',
				'' !== $voice ? ' dlms-dialog__line--' . $voice : '',
				AudioClips::button( $text, $media, array( 'voice' => $voice ) ),
				'' !== $line['speaker'] ? '<strong class="dlms-dialog__speaker">' . esc_html( $line['speaker'] ) . ':</strong> ' : '',
				wp_kses_post( $html )
			);
		}
		if ( '' === $items ) {
			return '';
		}
		return sprintf(
			'<div class="dlms dlms-dialog"><div class="dlms-dialog__bar"><button type="button" class="dlms-play-all" aria-pressed="false">%1$s<span>%2$s</span></button>%4$s</div><ul class="dlms-dialog__lines">%3$s</ul></div>',
			AudioClips::icon(),
			dlms_t( __( 'Play the whole dialogue', 'deutschlms' ) ),
			// Built above from escaped parts.
			$items,
			AudioClips::speed_button()
		);
	}

	/**
	 * Explicit course ID or the current context.
	 *
	 * @param mixed $value Attribute value.
	 * @return int
	 */
	private function course_id( $value ): int {
		$course_id = absint( $value );
		return $course_id ? $course_id : Context::current_course_id();
	}

	/**
	 * Parses yes/no style attributes.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private function flag( $value ): bool {
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Wraps shortcode output in the plugin's style scope.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private function wrap( string $html ): string {
		return '' === $html ? '' : '<div class="dlms dlms-shortcode">' . $html . '</div>';
	}
}
