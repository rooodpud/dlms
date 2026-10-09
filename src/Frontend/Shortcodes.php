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
 * Audio); without one, the browser reads the text with a German voice.
 */
final class Shortcodes {

	/**
	 * Shortcodes that lay out the course UI themselves (see ContentGate).
	 */
	public const LAYOUT = array( 'course_outline', 'progress_bar', 'enroll_button', 'mark_complete', 'course_grid', 'student_dashboard' );

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
			),
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
				'<span class="dlms dlms-noun dlms-article--%1$s"><strong>%2$s</strong> %3$s</span>',
				esc_attr( $article ),
				esc_html( $shown ),
				esc_html( $word )
			);
		}

		$picture = null === $args['picture'] ? NounPictures::key_for( $word ) : NounPictures::sanitize_ref( (string) $args['picture'] );
		$button  = $this->flag( $args['audio'] ) ? AudioClips::button( $article . ' ' . $word, 0, array( 'css_class' => 'dlms-noun-card__play' ) ) : '';
		return sprintf(
			'<span class="dlms-noun-card dlms-article--%1$s%5$s" role="listitem">%2$s<span class="dlms-noun-card__text"><strong class="dlms-noun-card__article">%3$s</strong> <span class="dlms-noun-card__word">%4$s</span></span>%6$s</span>',
			esc_attr( $article ),
			NounPictures::render( $picture, 'dlms-noun-picture dlms-noun-card__picture' ),
			esc_html( $shown ),
			esc_html( $word ),
			'' !== $button ? ' has-audio' : '',
			// Safe markup from AudioClips::button().
			$button
		);
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
			'der' => __( 'masculine', 'deutschlms' ),
			'die' => __( 'feminine', 'deutschlms' ),
			'das' => __( 'neuter', 'deutschlms' ),
		);
		$items   = '';
		foreach ( $genders as $article => $gender ) {
			$items .= sprintf(
				'<span class="dlms-legend__item dlms-article--%1$s" role="listitem"><strong>%1$s</strong> <span>%2$s</span></span>',
				esc_attr( $article ),
				esc_html( $gender )
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
		return '<span class="dlms dlms-say">' . $button . '<span class="dlms-say__text" lang="de">' . wp_kses_post( $html ) . '</span></span>';
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
			$voice  = $voices[ mb_strtolower( $line['speaker'] ) ] ?? '';
			$items .= sprintf(
				'<li class="dlms-dialog__line%1$s">%2$s<span class="dlms-dialog__text">%3$s<span class="dlms-say__text" lang="de">%4$s</span></span></li>',
				'' !== $voice ? ' dlms-dialog__line--' . $voice : '',
				AudioClips::button( $text, 0, array( 'voice' => $voice ) ),
				'' !== $line['speaker'] ? '<strong class="dlms-dialog__speaker">' . esc_html( $line['speaker'] ) . ':</strong> ' : '',
				wp_kses_post( $html )
			);
		}
		if ( '' === $items ) {
			return '';
		}
		return sprintf(
			'<div class="dlms dlms-dialog"><div class="dlms-dialog__bar"><button type="button" class="dlms-play-all" aria-pressed="false">%1$s<span>%2$s</span></button></div><ul class="dlms-dialog__lines">%3$s</ul></div>',
			AudioClips::icon(),
			esc_html__( 'Play the whole dialogue', 'deutschlms' ),
			// Built above from escaped parts.
			$items
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
