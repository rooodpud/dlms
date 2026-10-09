<?php
/**
 * Audio: recordings of German sentences and words, with the browser's
 * German voice as a fallback.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Content;

use DeutschLMS\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The audio library (Courses → Audio) holds one recording (an audio file of
 * the Media Library) per German text. A text is matched case-insensitively,
 * with spaces, quotes and the punctuation at its ends ignored, so "Guten
 * Morgen!" and "guten Morgen" share one recording.
 *
 * Play buttons ([dlms_say], noun cards, listening questions) play the
 * recording of their text. Where there is none yet, the browser reads the
 * text with a German voice (Web Speech API, in the student's browser; the
 * plugin itself sends nothing anywhere).
 */
final class AudioClips {

	/**
	 * Option holding the library: key => [ 'text' => …, 'media' => attachment ID ].
	 */
	public const OPTION = 'dlms_audio_clips';

	/**
	 * Most entries in the library.
	 */
	public const MAX_ENTRIES = 5000;

	/**
	 * Longest text that can be spoken.
	 */
	public const MAX_TEXT = 1000;

	/**
	 * Library, read once per request.
	 *
	 * @var array<string, array{text: string, media: int}>|null
	 */
	private static ?array $library = null;

	/**
	 * The library: key => [ text, media ].
	 *
	 * @return array<string, array{text: string, media: int}>
	 */
	public static function library(): array {
		if ( null === self::$library ) {
			/**
			 * Filters the audio library.
			 *
			 * @param array $library key => [ 'text' => string, 'media' => audio attachment ID ].
			 */
			$filtered      = apply_filters( 'dlms_audio_clips', get_option( self::OPTION, array() ) );
			self::$library = self::clean_library( is_array( $filtered ) ? $filtered : array() );
		}
		return self::$library;
	}

	/**
	 * The saved library, without the `dlms_audio_clips` filter.
	 *
	 * @return array<string, array{text: string, media: int}>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return self::clean_library( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sets (or, with 0, removes) the recording of a text.
	 *
	 * @param string $text     German text.
	 * @param int    $media_id Audio attachment ID, 0 = remove.
	 * @return bool False when the text is empty or the file is no audio file.
	 */
	public static function save( string $text, int $media_id ): bool {
		$text = self::clean_text( $text );
		$key  = self::key_for( $text );
		if ( '' === $key || ( $media_id && ! self::is_audio( $media_id ) ) ) {
			return false;
		}
		$library = self::stored();
		if ( $media_id ) {
			$library[ $key ] = array(
				'text'  => $text,
				'media' => $media_id,
			);
		} else {
			unset( $library[ $key ] );
		}
		update_option( self::OPTION, self::clean_library( $library ), false );
		self::$library = null;
		return true;
	}

	/**
	 * Clears the per-request cache (after the option changed).
	 */
	public static function flush(): void {
		self::$library = null;
	}

	/**
	 * Library key of a text: lower case, single spaces, without quotes and
	 * without the punctuation at its ends ('' for an empty text).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function key_for( string $text ): string {
		$text = mb_strtolower( self::clean_text( $text ) );
		$text = str_replace( array( '"', '„', '“', '”', '‚', '‘', '’', '«', '»' ), '', $text );
		$text = trim( (string) preg_replace( '/^[\s.,;:!?…–-]+|[\s.,;:!?…–-]+$/u', '', $text ) );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return '' === $text ? '' : substr( md5( $text ), 0, 16 );
	}

	/**
	 * A text as spoken: tags removed, single spaces, capped.
	 *
	 * @param string $text Text (may contain HTML).
	 * @return string
	 */
	public static function clean_text( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_substr( $text, 0, self::MAX_TEXT );
	}

	/**
	 * The recording of a text (attachment ID, 0 = none).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function media_for( string $text ): int {
		$key = self::key_for( $text );
		return '' !== $key ? (int) ( self::library()[ $key ]['media'] ?? 0 ) : 0;
	}

	/**
	 * Whether an attachment is an audio file.
	 *
	 * @param int $media_id Attachment ID.
	 * @return bool
	 */
	public static function is_audio( int $media_id ): bool {
		return $media_id > 0 && 'attachment' === get_post_type( $media_id ) && wp_attachment_is( 'audio', $media_id );
	}

	/**
	 * URL of an audio attachment ('' when it is none).
	 *
	 * @param int $media_id Attachment ID.
	 * @return string
	 */
	public static function url( int $media_id ): string {
		if ( ! self::is_audio( $media_id ) ) {
			return '';
		}
		$url = wp_get_attachment_url( $media_id );
		return $url ? (string) $url : '';
	}

	/**
	 * What a play button plays: a recording (its own, else the library's for
	 * the text) or, without one, the text for the browser's voice.
	 *
	 * @param string $text     German text.
	 * @param int    $media_id Own recording (0 = look the text up).
	 * @return array{src: string, text: string}
	 */
	public static function source( string $text, int $media_id = 0 ): array {
		$text = self::clean_text( $text );
		$src  = self::url( $media_id );
		if ( '' === $src && '' !== $text ) {
			$src = self::url( self::media_for( $text ) );
		}
		return array(
			'src'  => $src,
			'text' => $text,
		);
	}

	/**
	 * Markup of a play button ('' when there is nothing to play). The button
	 * carries the recording's URL, or else the text for the browser's voice.
	 *
	 * @param string $text     German text.
	 * @param int    $media_id Own recording (0 = look the text up).
	 * @param array  $args     Options: label (accessible name, '' = "Listen:
	 *                         <text>"), css_class (extra class), hide_text
	 *                         (keep the text out of the page when there is a
	 *                         recording, for listening questions), voice
	 *                         ('female' or 'male' for the browser's voice).
	 * @return string Safe markup.
	 */
	public static function button( string $text, int $media_id = 0, array $args = array() ): string {
		$args   = wp_parse_args(
			$args,
			array(
				'label'     => '',
				'css_class' => '',
				'hide_text' => false,
				'voice'     => '',
			)
		);
		$source = self::source( $text, $media_id );
		if ( '' === $source['src'] && '' === $source['text'] ) {
			return '';
		}
		Assets::enqueue_audio();

		// Labels follow the help language (HelpLanguage); a given label is used as it is.
		$label = '' !== $args['label']
			? dlms_attr( 'aria-label', $args['label'] )
			: dlms_attr(
				'aria-label',
				/* translators: %s: German text. */
				__( 'Listen: %s', 'deutschlms' ),
				$source['text']
			);
		$data = '' !== $source['src']
			? ' data-dlms-src="' . esc_url( $source['src'] ) . '"'
			: '';
		if ( '' === $source['src'] || ! $args['hide_text'] ) {
			$data .= ' data-dlms-say="' . esc_attr( $source['text'] ) . '"';
		}
		$voice = self::voice( (string) $args['voice'] );
		if ( '' !== $voice ) {
			$data .= ' data-dlms-voice="' . $voice . '"';
		}
		return sprintf(
			'<button type="button" class="dlms-play%1$s"%2$s %3$s %4$s>%5$s</button>',
			'' !== $args['css_class'] ? ' ' . esc_attr( $args['css_class'] ) : '',
			// Escaped above.
			$data,
			$label,
			dlms_attr( 'title', __( 'Listen', 'deutschlms' ) ),
			self::icon()
		);
	}

	/**
	 * The "slow speech" switch, a check box: while it is ticked, recordings
	 * and the browser's voice play slower (audio.js; the choice is
	 * remembered in the browser).
	 *
	 * @return string Safe markup.
	 */
	public static function speed_button(): string {
		Assets::enqueue_audio();
		return sprintf(
			'<button type="button" class="dlms-speed" aria-pressed="false" %1$s><span class="dlms-speed__box" aria-hidden="true"><svg class="dlms-speed__check" viewBox="0 0 16 16" focusable="false"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span>%2$s</span></button>',
			dlms_attr( 'title', __( 'Tick to hear the recordings and the voice more slowly', 'deutschlms' ) ),
			dlms_t( __( 'Slow speech', 'deutschlms' ) )
		);
	}

	/**
	 * The play/stop icon of play buttons (CSS shows the square while playing).
	 *
	 * @return string Safe markup.
	 */
	public static function icon(): string {
		return '<svg class="dlms-play__icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18"><path class="dlms-play__start" d="M8 5.5v13l10.5-6.5z" fill="currentColor"/><path class="dlms-play__stop" d="M7 7h10v10H7z" fill="currentColor"/></svg>';
	}

	/**
	 * A voice for the browser's speech: 'female', 'male' or '' (the browser's
	 * best German voice). Accepts English and German words and m/f/w.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function voice( string $value ): string {
		$value = mb_strtolower( trim( $value ) );
		if ( in_array( $value, array( 'female', 'f', 'w', 'woman', 'frau', 'weiblich' ), true ) ) {
			return 'female';
		}
		if ( in_array( $value, array( 'male', 'm', 'man', 'mann', 'männlich' ), true ) ) {
			return 'male';
		}
		return '';
	}

	/**
	 * Speakers' voices from a list like "Marco=male, Amina=female".
	 *
	 * @param string $names Names with = or : and a voice, separated by commas.
	 * @return array<string, string> Lower-case name => 'female' or 'male'.
	 */
	public static function voices( string $names ): array {
		$voices = array();
		foreach ( preg_split( '/[,;]/', $names ) as $pair ) {
			$parts = preg_split( '/[=:]/', $pair, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$name  = mb_strtolower( trim( $parts[0] ) );
			$voice = self::voice( $parts[1] );
			if ( '' !== $name && '' !== $voice ) {
				$voices[ $name ] = $voice;
			}
		}
		return $voices;
	}

	/**
	 * The lines of a [dlms_dialog]: one per line (or paragraph), each
	 * starting with the speaker's name and a colon ("Marco: Guten Morgen!").
	 * A line without a name has no speaker.
	 *
	 * @param string $content Shortcode content (wpautop's <br> and <p> allowed).
	 * @return array<int, array{speaker: string, html: string}>
	 */
	public static function dialog_lines( string $content ): array {
		$content = (string) preg_replace( '#<br\s*/?>|</?p(?:\s[^>]*)?>#i', "\n", $content );
		$lines   = array();
		foreach ( preg_split( '/\R/u', $content ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$speaker = '';
			if ( preg_match( '#^(?:<(?:strong|b)>)?\s*([^:<>\[\]"]{1,40}?)\s*:\s*(?:</(?:strong|b)>)?\s*(.+)$#su', $line, $match ) ) {
				$speaker = trim( $match[1] );
				$line    = trim( $match[2] );
			}
			$lines[] = array(
				'speaker' => $speaker,
				'html'    => $line,
			);
		}
		return $lines;
	}

	/**
	 * Cleans library entries: keys from the text, audio files only.
	 *
	 * @param array $entries Entries.
	 * @return array<string, array{text: string, media: int}>
	 */
	private static function clean_library( array $entries ): array {
		$library = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || count( $library ) >= self::MAX_ENTRIES ) {
				continue;
			}
			$text  = self::clean_text( (string) ( $entry['text'] ?? '' ) );
			$key   = self::key_for( $text );
			$media = absint( $entry['media'] ?? 0 );
			if ( '' === $key || ! $media ) {
				continue;
			}
			$library[ $key ] = array(
				'text'  => $text,
				'media' => $media,
			);
		}
		return $library;
	}
}
