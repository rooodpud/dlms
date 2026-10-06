<?php
/**
 * Noun pictures: an icon or an uploaded image per noun.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Pictures of nouns, for article questions and noun cards in lessons.
 *
 * A picture is written as a reference:
 * - `icon:sofa`   an icon of the bundled Tabler set (assets/icons/tabler/,
 *                 MIT), printed inline with `currentColor`, so it takes the
 *                 colour of its article (blue/red/green for der/die/das);
 * - `media:123`   an image from the Media Library (attachment ID);
 * - `tisch`       a noun of the picture library: its picture is used.
 *
 * The library (Courses → Noun pictures) holds one picture per noun, so
 * every card and question about that noun shows the same picture. Its keys
 * are the nouns in lower case with umlauts as ae/oe/ue (Kühlschrank →
 * kuehlschrank). Questions and cards look up their noun automatically and can
 * name another picture instead.
 */
final class NounPictures {

	/**
	 * Option holding the library: key => [ 'noun' => …, 'picture' => icon:… | media:… ].
	 */
	public const OPTION = 'dlms_noun_pictures';

	public const ICON  = 'icon:';
	public const MEDIA = 'media:';

	/**
	 * Most entries in the library.
	 */
	public const MAX_ENTRIES = 2000;

	/**
	 * Library, read once per request.
	 *
	 * @var array<string, array{noun: string, picture: string}>|null
	 */
	private static ?array $library = null;

	/**
	 * Printed icon markup, per icon name.
	 *
	 * @var array<string, string>
	 */
	private static array $icons = array();

	/**
	 * The library: key => [ noun, picture ], sorted by noun.
	 *
	 * @return array<string, array{noun: string, picture: string}>
	 */
	public static function library(): array {
		if ( null === self::$library ) {
			/**
			 * Filters the noun picture library.
			 *
			 * @param array $library key => [ 'noun' => string, 'picture' => 'icon:name' or 'media:ID' ].
			 */
			$filtered      = apply_filters( 'dlms_noun_pictures', get_option( self::OPTION, array() ) );
			self::$library = self::clean_library( is_array( $filtered ) ? $filtered : array() );
		}
		return self::$library;
	}

	/**
	 * The saved library, without the `dlms_noun_pictures` filter.
	 *
	 * @return array<string, array{noun: string, picture: string}>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return self::clean_library( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Saves the library (entries without a valid picture are left out).
	 *
	 * @param array $entries key or index => [ 'noun' => string, 'picture' => string ].
	 * @return array<string, array{noun: string, picture: string}> The saved library.
	 */
	public static function save_library( array $entries ): array {
		$library = self::clean_library( $entries );
		update_option( self::OPTION, $library, false );
		self::$library = null;
		return $library;
	}

	/**
	 * Clears the per-request caches (after the option changed).
	 */
	public static function flush(): void {
		self::$library = null;
	}

	/**
	 * Library key for a noun, e.g. "Kühlschrank" => "kuehlschrank".
	 *
	 * @param string $noun Noun.
	 * @return string
	 */
	public static function key_for( string $noun ): string {
		$noun = str_replace( array( 'ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß' ), array( 'ae', 'oe', 'ue', 'ae', 'oe', 'ue', 'ss' ), trim( $noun ) );
		return sanitize_key( $noun );
	}

	/**
	 * Cleans a picture reference: `icon:<name>` of a bundled icon, `media:<ID>`
	 * of an image attachment, or a library key; anything else gives ''.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function sanitize_ref( string $ref ): string {
		$ref = trim( $ref );
		if ( str_starts_with( $ref, self::ICON ) ) {
			$name = substr( $ref, strlen( self::ICON ) );
			return self::icon_exists( $name ) ? self::ICON . $name : '';
		}
		if ( str_starts_with( $ref, self::MEDIA ) ) {
			$id = absint( substr( $ref, strlen( self::MEDIA ) ) );
			return $id && wp_attachment_is_image( $id ) ? self::MEDIA . $id : '';
		}
		return self::key_for( $ref );
	}

	/**
	 * The icon or image a reference stands for: a library key is looked up;
	 * '' when there is none.
	 *
	 * @param string $ref Reference.
	 * @return string `icon:…`, `media:…` or ''.
	 */
	public static function resolve( string $ref ): string {
		if ( str_starts_with( $ref, self::ICON ) || str_starts_with( $ref, self::MEDIA ) ) {
			return $ref;
		}
		$key = self::key_for( $ref );
		return '' !== $key ? ( self::library()[ $key ]['picture'] ?? '' ) : '';
	}

	/**
	 * Markup of a picture ('' when there is none). Decorative: the noun is
	 * always written next to it.
	 *
	 * @param string $ref       Reference (see the class description).
	 * @param string $css_class CSS class.
	 * @return string Safe markup.
	 */
	public static function render( string $ref, string $css_class = 'dlms-noun-picture' ): string {
		$picture = self::resolve( $ref );
		if ( str_starts_with( $picture, self::ICON ) ) {
			$svg = self::icon_svg( substr( $picture, strlen( self::ICON ) ) );
			return '' === $svg ? '' : str_replace( '%CLASS%', esc_attr( $css_class . ' dlms-noun-picture--icon' ), $svg );
		}
		if ( str_starts_with( $picture, self::MEDIA ) ) {
			$id = absint( substr( $picture, strlen( self::MEDIA ) ) );
			if ( ! $id || ! wp_attachment_is_image( $id ) ) {
				return '';
			}
			return (string) wp_get_attachment_image(
				$id,
				'medium',
				false,
				array(
					'class'    => $css_class . ' dlms-noun-picture--image',
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
		}
		return '';
	}

	/**
	 * Preview URL of a reference for the admin screens ('' when there is none).
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	public static function preview_url( string $ref ): string {
		$picture = self::resolve( $ref );
		if ( str_starts_with( $picture, self::ICON ) ) {
			return self::icon_url( substr( $picture, strlen( self::ICON ) ) );
		}
		if ( str_starts_with( $picture, self::MEDIA ) ) {
			$url = wp_get_attachment_image_url( absint( substr( $picture, strlen( self::MEDIA ) ) ), 'thumbnail' );
			return $url ? (string) $url : '';
		}
		return '';
	}

	/**
	 * What the admin scripts need: the library with previews, and where the
	 * icons and their search index are.
	 *
	 * @return array{library: array, iconBase: string, iconIndex: string}
	 */
	public static function editor_data(): array {
		$library = array();
		foreach ( self::library() as $key => $entry ) {
			$library[ $key ] = array(
				'noun'    => $entry['noun'],
				'picture' => $entry['picture'],
				'url'     => self::preview_url( $entry['picture'] ),
			);
		}
		return array(
			'library'   => $library,
			'iconBase'  => DLMS_URL . 'assets/icons/tabler/outline/',
			'iconIndex' => DLMS_URL . 'assets/icons/tabler/icons.json?ver=' . rawurlencode( DLMS_VERSION ),
		);
	}

	/**
	 * Whether a bundled icon exists.
	 *
	 * @param string $name Icon name, e.g. "sofa".
	 * @return bool
	 */
	public static function icon_exists( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9-]{1,80}$/', $name ) && is_readable( self::icon_file( $name ) );
	}

	/**
	 * URL of a bundled icon.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	public static function icon_url( string $name ): string {
		return self::icon_exists( $name ) ? DLMS_URL . 'assets/icons/tabler/outline/' . $name . '.svg' : '';
	}

	/**
	 * Inline SVG of a bundled icon, with a %CLASS% placeholder ('' when unknown).
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	private static function icon_svg( string $name ): string {
		if ( ! isset( self::$icons[ $name ] ) ) {
			$svg = self::icon_exists( $name ) ? (string) file_get_contents( self::icon_file( $name ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$svg = wp_kses( $svg, self::allowed_svg() );
			// Decorative, never focusable; the class goes on the root element.
			$svg                  = (string) preg_replace( '/^\s*<svg\b/i', '<svg aria-hidden="true" focusable="false" class="%CLASS%"', $svg, 1 );
			self::$icons[ $name ] = str_contains( $svg, '%CLASS%' ) ? trim( $svg ) : '';
		}
		return self::$icons[ $name ];
	}

	/**
	 * Path of a bundled icon.
	 *
	 * @param string $name Icon name (already checked).
	 * @return string
	 */
	private static function icon_file( string $name ): string {
		return DLMS_PATH . 'assets/icons/tabler/outline/' . $name . '.svg';
	}

	/**
	 * Cleans library entries: keys from the noun, valid pictures only.
	 *
	 * @param array $entries Entries.
	 * @return array<string, array{noun: string, picture: string}>
	 */
	private static function clean_library( array $entries ): array {
		$library = array();
		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) || count( $library ) >= self::MAX_ENTRIES ) {
				continue;
			}
			$noun    = sanitize_text_field( (string) ( $entry['noun'] ?? ( is_string( $key ) ? $key : '' ) ) );
			$noun    = mb_substr( $noun, 0, 100 );
			$key     = self::key_for( $noun );
			$picture = self::sanitize_ref( (string) ( $entry['picture'] ?? '' ) );
			// Only icons and images: a library entry never points to another noun.
			if ( '' === $key || ! ( str_starts_with( $picture, self::ICON ) || str_starts_with( $picture, self::MEDIA ) ) ) {
				continue;
			}
			$library[ $key ] = array(
				'noun'    => $noun,
				'picture' => $picture,
			);
		}
		uasort( $library, static fn( $a, $b ) => strnatcasecmp( $a['noun'], $b['noun'] ) );
		return $library;
	}

	/**
	 * SVG elements and attributes allowed in icons.
	 *
	 * @return array
	 */
	private static function allowed_svg(): array {
		$paint   = array_fill_keys(
			array( 'fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'opacity', 'transform' ),
			true
		);
		$shapes  = array(
			'path'     => array( 'd' => true ),
			'rect'     => array_fill_keys( array( 'x', 'y', 'width', 'height', 'rx', 'ry' ), true ),
			'circle'   => array_fill_keys( array( 'cx', 'cy', 'r' ), true ),
			'ellipse'  => array_fill_keys( array( 'cx', 'cy', 'rx', 'ry' ), true ),
			'line'     => array_fill_keys( array( 'x1', 'y1', 'x2', 'y2' ), true ),
			'polyline' => array( 'points' => true ),
			'polygon'  => array( 'points' => true ),
			'g'        => array(),
		);
		$allowed = array(
			'svg' => $paint + array_fill_keys( array( 'xmlns', 'viewbox', 'width', 'height' ), true ),
		);
		foreach ( $shapes as $tag => $attributes ) {
			$allowed[ $tag ] = $paint + $attributes;
		}
		return $allowed;
	}
}
