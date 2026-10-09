<?php
/**
 * Help language switch: instructions, buttons and help in the learner's language.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * A course can offer its learners a choice of language for everything that
 * is not German course content: the plugin's buttons and messages, headings
 * and instructions in the content, title translations, question translations
 * and explanations. German is always offered; the course settings add more
 * (e.g. English and Tagalog) and choose the one new learners start with.
 *
 * How it works:
 * - Each text is printed once per language, as `<span class="dlms-l"
 *   data-dlms-l="en" lang="en">`; the stylesheet shows only the spans of the
 *   language in `<html data-dlms-help="en">`. Switching needs no reload, so
 *   a quiz in progress keeps its answers.
 * - Plugin strings keep their normal gettext calls (so `make-pot` finds
 *   them) and are wrapped: `dlms_t( __( 'Next', 'deutschlms' ) )`. A gettext
 *   filter remembers the original (English) text of each translation, so the
 *   wrapper can print the English original, the German translation and the
 *   other languages (languages/deutschlms-<locale>.mo, read with WordPress'
 *   MO reader: load_textdomain() would switch the whole site's translations
 *   to the file's locale).
 * - Attributes (aria-label, title) are printed in the current language;
 *   help-language.js changes them from a dictionary in `dlmsHelpConfig.attrs`.
 * - The choice: the learner's account (user meta), else the browser
 *   (localStorage, read by a tiny script in the page head), else the course
 *   default. Without a choice of languages nothing changes: every helper
 *   prints the plain translated text.
 */
final class HelpLanguage {

	/**
	 * User meta: the learner's language code.
	 */
	public const USER_META = 'dlms_help_language';

	/**
	 * Key of the choice in localStorage.
	 */
	public const STORAGE_KEY = 'dlmsHelpLanguage';

	/**
	 * Script handle.
	 */
	public const SCRIPT = 'dlms-help';

	/**
	 * German: always offered, the language of the course content.
	 */
	public const GERMAN = 'de';

	/**
	 * Attributes help-language.js keeps in the current language.
	 */
	public const ATTRIBUTES = array( 'aria-label', 'title' );

	/**
	 * Original texts of translations seen in this request, by translation:
	 * [ 's', text, context ] or [ 'n', singular, plural, number ].
	 *
	 * @var array<string, array>
	 */
	private static array $known = array();

	/**
	 * Attribute texts used on the page, by key: lang => format.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $attrs = array();

	/**
	 * Translation files read for the languages (null = none).
	 *
	 * @var array<string, \MO|null>
	 */
	private static array $files = array();

	/**
	 * Course used instead of the current page's (tests, REST rendering); null = the page's.
	 *
	 * @var int|null
	 */
	private static ?int $course = null;

	/**
	 * The switch that stays on screen (see dock()), printed in the footer; '' = none.
	 *
	 * @var string
	 */
	private static string $dock = '';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'gettext_deutschlms', array( self::class, 'remember' ), 10, 2 );
		add_filter( 'gettext_with_context_deutschlms', array( self::class, 'remember_with_context' ), 10, 3 );
		add_filter( 'ngettext_deutschlms', array( self::class, 'remember_plural' ), 10, 4 );
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
		add_action( 'wp_head', array( $this, 'print_head_script' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_dock' ), 1 );
		add_action( 'wp_footer', array( $this, 'add_config' ), 1 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Languages a course can offer: code => { name (in the language itself),
	 * locale, file (translation file without .mo; '' = the plugin's own
	 * English texts) }. German comes first.
	 *
	 * @return array<string, array{name: string, locale: string, file: string}>
	 */
	public static function languages(): array {
		$german    = array(
			'name'   => 'Deutsch',
			'locale' => 'de_DE',
			'file'   => 'deutschlms-de_DE',
		);
		$languages = array(
			self::GERMAN => $german,
			'en'         => array(
				'name'   => 'English',
				'locale' => 'en_US',
				'file'   => '',
			),
			'tl'         => array(
				'name'   => 'Tagalog',
				'locale' => 'tl',
				'file'   => 'deutschlms-tl',
			),
		);

		/**
		 * Filters the help languages a course can offer. Add a language with
		 * its translation file in the plugin's languages folder, e.g.
		 * 'hi' => [ 'name' => 'हिन्दी', 'locale' => 'hi_IN', 'file' => 'deutschlms-hi_IN' ].
		 *
		 * @param array $languages Languages by code.
		 */
		$languages = (array) apply_filters( 'dlms_help_languages', $languages );

		$clean = array( self::GERMAN => $german );
		foreach ( $languages as $code => $language ) {
			if ( ! is_string( $code ) || ! preg_match( '/^[a-z]{2,3}$/', $code ) || ! is_array( $language ) ) {
				continue;
			}
			$clean[ $code ] = array(
				'name'   => (string) ( $language['name'] ?? $code ),
				'locale' => (string) ( $language['locale'] ?? $code ),
				'file'   => sanitize_file_name( (string) ( $language['file'] ?? '' ) ),
			);
		}
		return $clean;
	}

	/**
	 * Codes of the languages besides German (the ones translations are written in).
	 *
	 * @return string[]
	 */
	public static function translation_codes(): array {
		return array_values( array_diff( array_keys( self::languages() ), array( self::GERMAN ) ) );
	}

	/**
	 * Cleans a list of language codes for a course: known codes besides German,
	 * in the registry's order.
	 *
	 * @param mixed $raw Codes (array or space/comma separated string).
	 * @return string[]
	 */
	public static function sanitize_codes( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		$raw = array_map( 'strval', is_array( $raw ) ? $raw : array() );
		return array_values( array_intersect( self::translation_codes(), $raw ) );
	}

	/**
	 * Cleans translations keyed by language code (title or question translations).
	 *
	 * @param mixed $raw       Translations.
	 * @param int   $max       Maximum length of each.
	 * @param bool  $multiline Keep line breaks.
	 * @return array<string, string>
	 */
	public static function sanitize_texts( $raw, int $max = 500, bool $multiline = false ): array {
		$clean = array();
		if ( ! is_array( $raw ) ) {
			return $clean;
		}
		foreach ( self::translation_codes() as $code ) {
			if ( ! isset( $raw[ $code ] ) || ! is_scalar( $raw[ $code ] ) ) {
				continue;
			}
			$text = $multiline ? sanitize_textarea_field( (string) $raw[ $code ] ) : sanitize_text_field( (string) $raw[ $code ] );
			$text = trim( mb_substr( $text, 0, $max ) );
			if ( '' !== $text ) {
				$clean[ $code ] = $text;
			}
		}
		return $clean;
	}

	/**
	 * Languages a course offers, German first ([] = no choice).
	 *
	 * @param int $course_id Course ID.
	 * @return string[]
	 */
	public static function course_languages( int $course_id ): array {
		if ( $course_id <= 0 || PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return array();
		}
		$codes = self::sanitize_codes( get_post_meta( $course_id, Meta::HELP_LANGUAGES, true ) );
		return $codes ? array_merge( array( self::GERMAN ), $codes ) : array();
	}

	/**
	 * Language new learners of a course start with.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public static function course_default( int $course_id ): string {
		$default = (string) get_post_meta( $course_id, Meta::HELP_DEFAULT, true );
		return in_array( $default, self::course_languages( $course_id ), true ) ? $default : self::GERMAN;
	}

	/**
	 * Uses another course than the current page's (null = the page's again).
	 *
	 * @param int|null $course_id Course ID.
	 */
	public static function use_course( ?int $course_id ): void {
		self::$course = $course_id;
	}

	/**
	 * Course of the current page.
	 *
	 * @return int
	 */
	public static function course_id(): int {
		return self::$course ?? Context::current_course_id();
	}

	/**
	 * Languages of the current page ([] = no choice: plain output).
	 *
	 * @return string[]
	 */
	public static function page_languages(): array {
		return self::course_languages( self::course_id() );
	}

	/**
	 * Whether the current page offers a choice.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return array() !== self::page_languages();
	}

	/**
	 * The language the page starts with: the learner's choice (saved in
	 * their account), else the course default. '' without a choice.
	 *
	 * @return string
	 */
	public static function current(): string {
		$languages = self::page_languages();
		if ( ! $languages ) {
			return '';
		}
		$saved = self::user_language();
		return in_array( $saved, $languages, true ) ? $saved : self::course_default( self::course_id() );
	}

	/**
	 * The current user's saved language ('' = none).
	 *
	 * @param int $user_id User ID (0 = current user).
	 * @return string
	 */
	public static function user_language( int $user_id = 0 ): string {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}
		$code = (string) get_user_meta( $user_id, self::USER_META, true );
		return array_key_exists( $code, self::languages() ) ? $code : '';
	}

	/**
	 * Saves a user's language.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Language code.
	 * @return bool False for an unknown language.
	 */
	public static function save_user_language( int $user_id, string $code ): bool {
		if ( $user_id <= 0 || ! array_key_exists( $code, self::languages() ) ) {
			return false;
		}
		update_user_meta( $user_id, self::USER_META, $code );
		return true;
	}

	/*
	 * Remembering the original texts of translations.
	 */

	/**
	 * Remembers the original of a translation (gettext filter; returns it unchanged).
	 *
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @return string
	 */
	public static function remember( $translation, $text ) {
		self::$known[ (string) $translation ] = array( 's', (string) $text, '' );
		return $translation;
	}

	/**
	 * Remembers the original of a translation with context.
	 *
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @param string $context     Context.
	 * @return string
	 */
	public static function remember_with_context( $translation, $text, $context ) {
		self::$known[ (string) $translation ] = array( 's', (string) $text, (string) $context );
		return $translation;
	}

	/**
	 * Remembers the originals of a plural translation.
	 *
	 * @param string $translation Translation.
	 * @param string $single      Singular original.
	 * @param string $plural      Plural original.
	 * @param int    $number      Number.
	 * @return string
	 */
	public static function remember_plural( $translation, $single, $plural, $number ) {
		self::$known[ (string) $translation ] = array( 'n', (string) $single, (string) $plural, (int) $number );
		return $translation;
	}

	/**
	 * A translated plugin string in every language of the page: language =>
	 * format (the given text for all when its original is unknown).
	 *
	 * @param string   $translated Text as returned by __(), _x() or _n().
	 * @param string[] $languages  Languages (default: the page's).
	 * @return array<string, string>
	 */
	public static function formats( string $translated, ?array $languages = null ): array {
		$languages = $languages ?? self::page_languages();
		$entry     = self::$known[ $translated ] ?? null;
		$formats   = array();
		foreach ( $languages as $code ) {
			$formats[ $code ] = $entry ? self::translate_entry( $entry, $code ) : $translated;
		}
		return $formats;
	}

	/**
	 * Translates a remembered original into a language.
	 *
	 * @param array  $entry Entry from $known.
	 * @param string $code  Language.
	 * @return string
	 */
	private static function translate_entry( array $entry, string $code ): string {
		$file    = self::file( $code );
		$context = '' !== ( $entry[2] ?? '' ) && 's' === $entry[0] ? $entry[2] : null;
		if ( 'n' === $entry[0] ) {
			return $file ? $file->translate_plural( $entry[1], $entry[2], $entry[3] ) : ( 1 === $entry[3] ? $entry[1] : $entry[2] );
		}
		return $file ? $file->translate( $entry[1], $context ) : $entry[1];
	}

	/**
	 * A language's translations (null = the original English, or no file).
	 * Read on first use from the plugin's languages folder.
	 *
	 * @param string $code Language.
	 * @return \MO|null
	 */
	private static function file( string $code ): ?\MO {
		if ( array_key_exists( $code, self::$files ) ) {
			return self::$files[ $code ];
		}
		$language = self::languages()[ $code ] ?? null;
		$file     = null;
		if ( $language && '' !== $language['file'] ) {
			$path = DLMS_PATH . 'languages/' . $language['file'] . '.mo';
			$mo   = new \MO();
			if ( is_readable( $path ) && $mo->import_from_file( $path ) ) {
				$file = $mo;
			}
		}
		self::$files[ $code ] = $file;
		return $file;
	}

	/*
	 * Output.
	 */

	/**
	 * Fills a format, tolerating a wrong number of values.
	 *
	 * @param string $format Format.
	 * @param array  $args   Values.
	 * @return string
	 */
	public static function format( string $format, array $args ): string {
		if ( array() === $args ) {
			return $format;
		}
		try {
			return vsprintf( $format, $args );
		} catch ( \ValueError $error ) {
			return $format;
		}
	}

	/**
	 * Markup with one element per language: language => safe HTML. Languages
	 * with the same HTML share an element (data-dlms-l="de en"); a language
	 * without its own HTML gets English, else German; empty HTML prints
	 * nothing in that language. Without a choice of languages: the German HTML.
	 *
	 * @param array<string, string> $html      Safe HTML by language.
	 * @param string                $tag       Element (span, p, div).
	 * @param string                $css_class Extra class.
	 * @return string
	 */
	public static function variants( array $html, string $tag = 'span', string $css_class = '' ): string {
		$languages = self::page_languages();
		if ( ! $languages ) {
			return self::pick( $html, self::GERMAN );
		}
		$groups = array();
		foreach ( $languages as $code ) {
			$groups[ self::pick( $html, $code ) ][] = $code;
		}
		$tag = in_array( $tag, array( 'span', 'p', 'div' ), true ) ? $tag : 'span';
		if ( 1 === count( $groups ) && '' === $css_class ) {
			return (string) array_key_first( $groups );
		}
		$out = '';
		foreach ( $groups as $markup => $codes ) {
			$markup = (string) $markup;
			if ( '' === $markup ) {
				continue;
			}
			$out .= sprintf(
				'<%1$s class="dlms-l%2$s" data-dlms-l="%3$s" lang="%4$s">%5$s</%1$s>',
				$tag,
				'' !== $css_class ? ' ' . esc_attr( $css_class ) : '',
				esc_attr( implode( ' ', $codes ) ),
				esc_attr( self::html_lang( $codes[0] ) ),
				$markup
			);
		}
		return $out;
	}

	/**
	 * The text for a language: its own, else English, else German.
	 *
	 * @param array<string, string> $texts Texts by language.
	 * @param string                $code  Language.
	 * @return string
	 */
	public static function pick( array $texts, string $code ): string {
		foreach ( array( $code, 'en', self::GERMAN ) as $candidate ) {
			if ( array_key_exists( $candidate, $texts ) ) {
				return (string) $texts[ $candidate ];
			}
		}
		return (string) ( $texts ? reset( $texts ) : '' );
	}

	/**
	 * Value of a lang attribute for a language code.
	 *
	 * @param string $code Language.
	 * @return string
	 */
	private static function html_lang( string $code ): string {
		return str_replace( '_', '-', $code );
	}

	/**
	 * A translated plugin string as escaped HTML, in every language of the
	 * page; filled with the values (escaped too).
	 *
	 * @param string $translated Text from __(), _x() or _n().
	 * @param mixed  ...$args    Values for the placeholders.
	 * @return string
	 */
	public static function text( string $translated, ...$args ): string {
		if ( ! self::is_active() ) {
			return esc_html( self::format( $translated, $args ) );
		}
		$html = array();
		foreach ( self::formats( $translated ) as $code => $format ) {
			$html[ $code ] = esc_html( self::format( $format, $args ) );
		}
		return self::variants( $html );
	}

	/**
	 * An instruction of an exercise (what to do to answer): the German text,
	 * and under it its translation in the learner's language; in German
	 * just the German text (the user's rule, 2026-10-09: A1 learners can't
	 * read the German instructions yet). Without a choice of languages: the
	 * text in the site language, as text().
	 *
	 * @param string $translated Text from __(), _x() or _n().
	 * @param mixed  ...$args    Values for the placeholders.
	 * @return string
	 */
	public static function instruction( string $translated, ...$args ): string {
		if ( ! self::is_active() ) {
			return esc_html( self::format( $translated, $args ) );
		}
		$formats = self::formats( $translated );
		$german  = esc_html( self::format( $formats[ self::GERMAN ] ?? $translated, $args ) );
		$help    = array( self::GERMAN => '' );
		foreach ( $formats as $code => $format ) {
			$text = esc_html( self::format( $format, $args ) );
			if ( self::GERMAN !== $code && $text !== $german ) {
				$help[ $code ] = $text;
			}
		}
		return '<span class="dlms-instruction" lang="de" translate="no">' . $german . '</span>' . self::variants( $help, 'span', 'dlms-instruction__help' );
	}

	/**
	 * Like text(), but the values are safe HTML (e.g. a link) and stay as they are.
	 *
	 * @param string $translated Text from __(), _x() or _n().
	 * @param string ...$html    Safe HTML for the placeholders.
	 * @return string
	 */
	public static function markup( string $translated, string ...$html ): string {
		if ( ! self::is_active() ) {
			return self::format( esc_html( $translated ), $html );
		}
		$out = array();
		foreach ( self::formats( $translated ) as $code => $format ) {
			$out[ $code ] = self::format( esc_html( $format ), $html );
		}
		return self::variants( $out );
	}

	/**
	 * An attribute with a translated plugin string: name="…" in the current
	 * language; with a choice of languages, plus the key help-language.js
	 * uses to change it.
	 *
	 * @param string $name       aria-label or title.
	 * @param string $translated Text from __(), _x() or _n().
	 * @param mixed  ...$args    Values for the placeholders.
	 * @return string Attribute markup (no leading space).
	 */
	public static function attr( string $name, string $translated, ...$args ): string {
		$name = in_array( $name, self::ATTRIBUTES, true ) ? $name : 'aria-label';
		if ( ! self::is_active() ) {
			return sprintf( '%s="%s"', $name, esc_attr( self::format( $translated, $args ) ) );
		}
		$formats = self::formats( $translated );
		$value   = self::format( self::pick( $formats, self::current() ), $args );
		$out     = sprintf( '%s="%s"', $name, esc_attr( $value ) );
		if ( count( array_unique( $formats ) ) > 1 ) {
			$key                 = 'k' . substr( md5( (string) wp_json_encode( $formats ) ), 0, 10 );
			self::$attrs[ $key ] = $formats;
			$out                .= sprintf( ' data-dlms-i18n-%s="%s"', $name, esc_attr( (string) wp_json_encode( array_merge( array( $key ), array_map( 'strval', $args ) ) ) ) );
		}
		return $out;
	}

	/**
	 * Translated plugin strings for a script: language => key => format
	 * ([] without a choice of languages; the script then uses its i18n).
	 *
	 * @param array<string, string> $strings Key => text from __(), _x() or _n().
	 * @return array<string, array<string, string>>
	 */
	public static function strings( array $strings ): array {
		if ( ! self::is_active() ) {
			return array();
		}
		$all = array();
		foreach ( $strings as $key => $translated ) {
			foreach ( self::formats( (string) $translated ) as $code => $format ) {
				$all[ $code ][ $key ] = $format;
			}
		}
		return $all;
	}

	/**
	 * Texts written in several languages (shortcode attributes, question
	 * translations) as escaped HTML in every language of the page.
	 *
	 * @param array<string, string> $texts Plain text by language ('de' = German).
	 * @param string                $tag   Element.
	 * @param string                $css_class Extra class.
	 * @return string
	 */
	public static function texts( array $texts, string $tag = 'span', string $css_class = '' ): string {
		return self::variants( array_map( 'esc_html', $texts ), $tag, $css_class );
	}

	/*
	 * Titles.
	 */

	/**
	 * Translations of a post's title (language => text), e.g. 'en' => 'Words'.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	public static function title_help( int $post_id ): array {
		return self::sanitize_texts( get_post_meta( $post_id, Meta::TITLE_HELP, true ), 200 );
	}

	/**
	 * A course, lesson, topic or quiz title as escaped HTML; with a choice of
	 * languages and a translation, the translation follows in brackets
	 * ("Übung 0.1 a: Wörter (Words)"), in German nothing.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $title   Title (default: the post's).
	 * @return string
	 */
	public static function title( int $post_id, ?string $title = null ): string {
		$title = wp_strip_all_tags( $title ?? get_the_title( $post_id ) );
		$help  = self::is_active() ? self::title_help( $post_id ) : array();
		if ( ! $help ) {
			return esc_html( $title );
		}
		$html = array( self::GERMAN => '' );
		foreach ( $help as $code => $text ) {
			$html[ $code ] = '<span class="dlms-title-help"> (' . esc_html( $text ) . ')</span>';
		}
		return '<span lang="de" translate="no">' . esc_html( $title ) . '</span>' . self::variants( $html );
	}

	/**
	 * The title translation under the page heading of a step (no brackets;
	 * nothing in German or without a translation).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function subtitle( int $post_id ): string {
		$help = self::is_active() ? self::title_help( $post_id ) : array();
		if ( ! $help ) {
			return '';
		}
		return self::variants( array( self::GERMAN => '' ) + array_map( 'esc_html', $help ), 'p', 'dlms-subtitle' );
	}

	/*
	 * Questions.
	 */

	/**
	 * Translation of a question's text, under the German text ('' without one).
	 *
	 * @param array $question Question (with `help`).
	 * @return string
	 */
	public static function question_help( array $question ): string {
		$help = self::is_active() && is_array( $question['help'] ?? null ) ? $question['help'] : array();
		if ( ! $help ) {
			return '';
		}
		return self::variants( array( self::GERMAN => '' ) + array_map( 'esc_html', $help ), 'span', 'dlms-quiz__help' );
	}

	/**
	 * A question's explanation: German, or its translation in the learner's
	 * language (German when there is none). Line breaks are kept.
	 *
	 * @param string $german       German explanation.
	 * @param array  $translations Translations by language.
	 * @return string
	 */
	public static function explanation( string $german, array $translations ): string {
		$texts = array( self::GERMAN => $german );
		if ( self::is_active() ) {
			foreach ( $translations as $code => $text ) {
				if ( is_string( $text ) && '' !== $text ) {
					$texts[ $code ] = $text;
				}
			}
		}
		if ( 1 === count( $texts ) && '' === $german ) {
			return '';
		}
		// A language without a translation gets English, else German (see pick()).
		return self::variants( array_map( static fn( $text ) => nl2br( esc_html( (string) $text ) ), $texts ) );
	}

	/*
	 * Switch and page setup.
	 */

	/**
	 * The language switch ('' without a choice).
	 *
	 * @return string
	 */
	public static function switcher(): string {
		$languages = self::page_languages();
		if ( ! $languages ) {
			return '';
		}
		Assets::enqueue_help();
		$label = wp_unique_id( 'dlms-help-label-' );
		if ( '' === self::$dock ) {
			self::$dock = self::dock( $languages );
		}

		return sprintf(
			'<div class="dlms dlms-help-switch" role="group" aria-labelledby="%1$s"><span class="dlms-help-switch__icon" aria-hidden="true">%2$s</span><span class="dlms-help-switch__label" id="%1$s">%3$s</span><span class="dlms-help-switch__buttons">%4$s</span></div>',
			esc_attr( $label ),
			self::globe(),
			self::text( __( 'Instructions and help in:', 'deutschlms' ) ),
			self::buttons( $languages )
		);
	}

	/**
	 * The switch that stays on screen while the learner scrolls: on wide
	 * screens a panel at the right edge, on smaller ones a tab at the right
	 * edge that opens the panel (tap or swipe left; help-language.js).
	 *
	 * @param string[] $languages Language codes.
	 * @return string
	 */
	private static function dock( array $languages ): string {
		$codes = array();
		foreach ( $languages as $code ) {
			$codes[ $code ] = esc_html( strtoupper( $code ) );
		}
		return sprintf(
			'<div class="dlms dlms-help-dock" data-dlms-help-dock><button type="button" class="dlms-help-dock__tab" aria-expanded="false" aria-controls="dlms-help-dock-panel" %1$s><span class="dlms-help-dock__icon" aria-hidden="true">%2$s</span><span class="dlms-help-dock__code" aria-hidden="true">%3$s</span></button><div class="dlms-help-dock__panel" id="dlms-help-dock-panel" role="group" aria-labelledby="dlms-help-dock-label"><span class="dlms-help-dock__label" id="dlms-help-dock-label"><span class="dlms-help-dock__icon" aria-hidden="true">%2$s</span>%4$s</span><span class="dlms-help-dock__buttons">%5$s</span></div></div>',
			self::attr( 'aria-label', __( 'Change the language of instructions and help', 'deutschlms' ) ),
			self::globe(),
			self::variants( $codes ),
			self::text( __( 'Instructions and help in:', 'deutschlms' ) ),
			self::buttons( $languages )
		);
	}

	/**
	 * Prints the switch that stays on screen (pages with a switch).
	 */
	public function print_dock(): void {
		echo self::$dock; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in dock().
	}

	/**
	 * One button per language.
	 *
	 * @param string[] $languages Language codes.
	 * @return string
	 */
	private static function buttons( array $languages ): string {
		$current = self::current();
		$names   = self::languages();
		$buttons = '';
		foreach ( $languages as $code ) {
			$buttons .= sprintf(
				'<button type="button" class="dlms-help-switch__button" data-dlms-help-set="%1$s" lang="%2$s" aria-pressed="%3$s">%4$s</button>',
				esc_attr( $code ),
				esc_attr( self::html_lang( $code ) ),
				$code === $current ? 'true' : 'false',
				esc_html( $names[ $code ]['name'] )
			);
		}
		return $buttons;
	}

	/**
	 * The globe icon of the switch.
	 *
	 * @return string
	 */
	private static function globe(): string {
		return '<svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 18 0 9 9 0 1 0-18 0M3.6 9h16.8M3.6 15h16.8M11.5 3a17 17 0 0 0 0 18M12.5 3a17 17 0 0 1 0 18"/></svg>';
	}

	/**
	 * Adds the current language and the course's languages to `<html>`, so
	 * the page shows the right language before any script runs.
	 *
	 * @param string $output Attributes.
	 * @return string
	 */
	public function language_attributes( $output ) {
		$languages = is_admin() ? array() : self::page_languages();
		if ( ! $languages ) {
			return $output;
		}
		return $output . sprintf(
			' data-dlms-help="%1$s" data-dlms-help-languages="%2$s"%3$s',
			esc_attr( self::current() ),
			esc_attr( implode( ' ', $languages ) ),
			'' !== self::user_language() ? ' data-dlms-help-saved="1"' : ''
		);
	}

	/**
	 * A class on `<body>` for themes: dlms-help-active.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( self::is_active() ) {
			$classes[] = 'dlms-help-active';
		}
		return $classes;
	}

	/**
	 * Head script: a visitor's choice from the browser, before the page shows
	 * (no flash of the wrong language). An account's saved choice wins.
	 */
	public function print_head_script(): void {
		$languages = self::page_languages();
		if ( ! $languages ) {
			return;
		}
		// Only the texts of the current language (codes are [a-z]{2,3}, see languages()).
		$rules = array_map(
			static fn( $code ) => sprintf( 'html[data-dlms-help="%1$s"] .dlms-l:not([data-dlms-l~="%1$s"])', $code ),
			$languages
		);
		printf( "<style id=\"dlms-help-css\">%s{display:none!important}</style>\n", implode( ',', $rules ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed rules with validated language codes.

		$script = sprintf(
			'(function(h){try{var l=(h.getAttribute("data-dlms-help-languages")||"").split(" "),s=window.localStorage.getItem(%1$s);if(!h.hasAttribute("data-dlms-help-saved")&&s&&l.indexOf(s)>-1){h.setAttribute("data-dlms-help",s);}if(!h.hasAttribute("data-dlms-help")){h.setAttribute("data-dlms-help",%2$s);h.setAttribute("data-dlms-help-languages",%3$s);}}catch(e){}})(document.documentElement);',
			wp_json_encode( self::STORAGE_KEY ),
			wp_json_encode( self::current() ),
			wp_json_encode( implode( ' ', self::page_languages() ) )
		);
		wp_print_inline_script_tag( $script, array( 'id' => 'dlms-help-head' ) );
	}

	/**
	 * Configuration of help-language.js, with the attribute texts used on the
	 * page (added before the footer scripts print).
	 */
	public function add_config(): void {
		if ( ! wp_script_is( self::SCRIPT, 'enqueued' ) ) {
			return;
		}
		wp_add_inline_script( self::SCRIPT, 'window.dlmsHelpConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Configuration of help-language.js.
	 *
	 * @return array
	 */
	public static function config(): array {
		$languages = self::page_languages();
		$names     = self::languages();
		$logged_in = is_user_logged_in();
		return array(
			'languages'  => $languages,
			'names'      => array_map( static fn( $code ) => $names[ $code ]['name'], array_combine( $languages, $languages ) ),
			'current'    => self::current(),
			'storageKey' => self::STORAGE_KEY,
			'attrs'      => $languages ? self::$attrs : array(),
			'save'       => $logged_in && $languages ? esc_url_raw( rest_url( 'dlms/v1/help-language' ) ) : '',
			'nonce'      => $logged_in && $languages ? wp_create_nonce( 'wp_rest' ) : '',
		);
	}

	/**
	 * Forgets the remembered texts and the page's attribute texts (tests).
	 */
	public static function reset(): void {
		self::$known  = array();
		self::$attrs  = array();
		self::$course = null;
		self::$dock   = '';
	}
}
