<?php
/**
 * Template loader.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Loads front-end templates. Themes override a template by copying it to
 * `yourtheme/deutschlms/<same path>`, e.g. `yourtheme/deutschlms/course/outline.php`.
 * Child themes win over parent themes.
 */
final class Templates {

	/**
	 * Theme folder that holds overrides.
	 */
	public const THEME_DIR = 'deutschlms';

	/**
	 * Absolute path of a template, preferring theme overrides.
	 *
	 * @param string $template Relative path, e.g. "course/outline.php".
	 * @return string Empty string when not found or the name is invalid.
	 */
	public static function locate( string $template ): string {
		$template = ltrim( str_replace( '\\', '/', $template ), '/' );
		if ( '' === $template || str_contains( $template, '..' ) || ! str_ends_with( $template, '.php' ) ) {
			return '';
		}

		$path = locate_template( array( self::THEME_DIR . '/' . $template ) );
		if ( '' === $path ) {
			$path = DLMS_PATH . 'templates/' . $template;
		}

		/**
		 * Filters the resolved template path.
		 *
		 * @param string $path     Absolute path.
		 * @param string $template Relative template name.
		 */
		$path = (string) apply_filters( 'dlms_locate_template', $path, $template );

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Renders a template to a string. Templates receive their data in `$args`
	 * and are responsible for escaping their output.
	 *
	 * @param string $template Relative path.
	 * @param array  $args     Template data.
	 * @return string
	 */
	public static function render( string $template, array $args = array() ): string {
		$path = self::locate( $template );
		if ( '' === $path ) {
			return '';
		}

		/**
		 * Filters template data before rendering.
		 *
		 * @param array  $args     Template data.
		 * @param string $template Relative template name.
		 */
		$args = (array) apply_filters( 'dlms_template_args', $args, $template );

		ob_start();
		// $args is read by the included template.
		( static function ( string $dlms_template_path, array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			include $dlms_template_path;
		} )( $path, $args );
		return (string) ob_get_clean();
	}
}
