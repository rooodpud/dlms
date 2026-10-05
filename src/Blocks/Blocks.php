<?php
/**
 * Block registration.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the dynamic blocks compiled to /build/blocks by @wordpress/scripts.
 * Each block renders server-side through the shared Renderer.
 */
final class Blocks {

	/**
	 * Block folder names under build/blocks.
	 */
	public const BLOCKS = array(
		'course-outline',
		'progress-bar',
		'mark-complete',
		'enroll-button',
		'course-grid',
		'student-dashboard',
	);

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'add_category' ) );
	}

	/**
	 * Registers each built block.
	 */
	public function register(): void {
		foreach ( self::BLOCKS as $block ) {
			$dir = DLMS_PATH . 'build/blocks/' . $block;
			if ( is_readable( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}

	/**
	 * Adds a "DeutschLMS" block inserter category.
	 *
	 * @param array $categories Categories.
	 * @return array
	 */
	public function add_category( $categories ) {
		array_unshift(
			$categories,
			array(
				'slug'  => 'deutschlms',
				'title' => __( 'DeutschLMS', 'deutschlms' ),
				'icon'  => null,
			)
		);
		return $categories;
	}
}
