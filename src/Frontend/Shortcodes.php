<?php
/**
 * Shortcodes (fallback for the blocks).
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

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
		return sprintf(
			'<span class="dlms-noun-card dlms-article--%1$s" role="listitem">%2$s<span class="dlms-noun-card__text"><strong class="dlms-noun-card__article">%3$s</strong> <span class="dlms-noun-card__word">%4$s</span></span></span>',
			esc_attr( $article ),
			NounPictures::render( $picture, 'dlms-noun-picture dlms-noun-card__picture' ),
			esc_html( $shown ),
			esc_html( $word )
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
