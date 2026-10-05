<?php
/**
 * Dates shown to students.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Formats dates for students in the plugin's own language.
 *
 * Without a translation this is wp_date() with the site's date format. A
 * translation can set its own format and month names, so the German
 * translation shows "3. Oktober 2026" even when the site itself runs in
 * English (WordPress takes month names from the site language).
 */
final class Dates {

	/**
	 * Date without time, in the site's timezone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function format( int $timestamp ): string {
		/* translators: PHP date format for dates shown to students, e.g. "j. F Y" (F = month name below). Keep "site" to use the site's date format. */
		$format = _x( 'site', 'date format', 'deutschlms' );
		if ( 'site' === $format ) {
			return (string) wp_date( (string) get_option( 'date_format' ), $timestamp );
		}

		$months = array(
			1  => _x( 'January', 'month name', 'deutschlms' ),
			2  => _x( 'February', 'month name', 'deutschlms' ),
			3  => _x( 'March', 'month name', 'deutschlms' ),
			4  => _x( 'April', 'month name', 'deutschlms' ),
			5  => _x( 'May', 'month name', 'deutschlms' ),
			6  => _x( 'June', 'month name', 'deutschlms' ),
			7  => _x( 'July', 'month name', 'deutschlms' ),
			8  => _x( 'August', 'month name', 'deutschlms' ),
			9  => _x( 'September', 'month name', 'deutschlms' ),
			10 => _x( 'October', 'month name', 'deutschlms' ),
			11 => _x( 'November', 'month name', 'deutschlms' ),
			12 => _x( 'December', 'month name', 'deutschlms' ),
		);
		$month  = $months[ (int) wp_date( 'n', $timestamp ) ];

		// Put the translated month name in place of "F", escaped for the date format.
		return (string) wp_date( str_replace( 'F', backslashit( $month ), $format ), $timestamp );
	}
}
