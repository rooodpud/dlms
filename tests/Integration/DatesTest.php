<?php
/**
 * Student-facing date tests.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Tests\Integration;

use DeutschLMS\Frontend\Dates;

/**
 * @covers \DeutschLMS\Frontend\Dates
 */
final class DatesTest extends TestCase {

	/**
	 * 3 October 2026, noon UTC.
	 */
	private const TIMESTAMP = 1791028800;

	public function tear_down(): void {
		remove_all_filters( 'gettext_with_context_deutschlms' );
		parent::tear_down();
	}

	public function test_without_translation_the_site_date_format_is_used(): void {
		update_option( 'date_format', 'Y-m-d' );

		$this->assertSame( '2026-10-03', Dates::format( self::TIMESTAMP ) );
		$this->assertSame( wp_date( 'Y-m-d', self::TIMESTAMP ), Dates::format( self::TIMESTAMP ) );
	}

	public function test_a_translation_sets_its_own_format_and_month_names(): void {
		update_option( 'date_format', 'F j, Y' );
		add_filter(
			'gettext_with_context_deutschlms',
			static function ( $translation, $text, $context ) {
				$german = array(
					'date format' => array( 'site' => 'j. F Y' ),
					'month name'  => array(
						'October' => 'Oktober',
						'March'   => 'März',
					),
				);
				return $german[ $context ][ $text ] ?? $translation;
			},
			10,
			3
		);

		$this->assertSame( '3. Oktober 2026', Dates::format( self::TIMESTAMP ) );
		// Non-ASCII month names survive the date format.
		$this->assertSame( '15. März 2026', Dates::format( 1773576000 ) );
	}
}
