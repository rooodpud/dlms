<?php
/**
 * Front-end assets.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Frontend;

use DeutschLMS\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared stylesheet and the progressive-enhancement script.
 * Everything is served from the plugin folder; nothing loads from a CDN.
 */
final class Assets {

	public const STYLE  = 'dlms-frontend';
	public const SCRIPT = 'dlms-frontend';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		// Before blocks register on init (10), because block.json refers to the style handle.
		add_action( 'init', array( $this, 'register' ), 8 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_lms_pages' ) );
	}

	/**
	 * Registers the handles.
	 */
	public function register(): void {
		wp_register_style( self::STYLE, DLMS_URL . 'assets/css/frontend.css', array(), DLMS_VERSION );

		wp_register_script(
			self::SCRIPT,
			DLMS_URL . 'assets/js/frontend.js',
			array(),
			DLMS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Loads assets up front on course/lesson/topic pages.
	 */
	public function enqueue_on_lms_pages(): void {
		if ( is_singular( array( PostTypes::COURSE, PostTypes::LESSON, PostTypes::TOPIC ) ) || is_post_type_archive( PostTypes::COURSE ) ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueues the style and script (safe to call repeatedly). Called by the
	 * renderer, so blocks and shortcodes on any page get their assets.
	 */
	public static function enqueue(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			return;
		}
		wp_enqueue_style( self::STYLE );

		if ( ! is_user_logged_in() || wp_script_is( self::SCRIPT, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( self::SCRIPT );
		wp_add_inline_script(
			self::SCRIPT,
			'window.dlmsFrontend = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'dlms/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'working'       => __( 'Saving…', 'deutschlms' ),
						'error'         => __( 'Something went wrong. Please try again.', 'deutschlms' ),
						'orderHint'     => __( 'Drag the words into your sentence in the right order, or click them. Drag a word in your sentence to move it, or click it to put it back.', 'deutschlms' ),
						'orderHintPick' => __( 'Choose the word for each position. Each word can be used only once.', 'deutschlms' ),
						'orderSentence' => __( 'Your sentence', 'deutschlms' ),
						'orderWords'    => __( 'Words to use', 'deutschlms' ),
						'orderEmpty'    => __( 'Your sentence appears here.', 'deutschlms' ),
						/* translators: %s: a word or phrase. */
						'orderAdd'      => __( 'Add “%s”', 'deutschlms' ),
						/* translators: %s: a word or phrase. */
						'orderRemove'   => __( 'Remove “%s” from your sentence', 'deutschlms' ),
						/* translators: %s: a word or phrase. */
						'orderAdded'    => __( 'Added “%s”.', 'deutschlms' ),
						/* translators: %s: a word or phrase. */
						'orderRemoved'  => __( 'Removed “%s”.', 'deutschlms' ),
						/* translators: 1: a word or phrase, 2: its position in the sentence. */
						'orderMoved'    => __( 'Moved “%1$s” to position %2$d.', 'deutschlms' ),
						'timeOneMinute' => __( 'One minute left.', 'deutschlms' ),
					),
				)
			) . ';',
			'before'
		);
	}
}
