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
	public const AUDIO  = 'dlms-audio';
	public const CARDS  = 'dlms-flashcards';
	public const HELP   = HelpLanguage::SCRIPT;

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
		wp_register_style( self::STYLE, DLMS_URL . 'assets/css/frontend.css', array(), dlms_asset_version( 'assets/css/frontend.css' ) );

		// The language switch and the texts in every help language; the other scripts use it.
		wp_register_script(
			self::HELP,
			DLMS_URL . 'assets/js/help-language.js',
			array(),
			dlms_asset_version( 'assets/js/help-language.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			self::SCRIPT,
			DLMS_URL . 'assets/js/frontend.js',
			array( self::HELP ),
			dlms_asset_version( 'assets/js/frontend.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			self::AUDIO,
			DLMS_URL . 'assets/js/audio.js',
			array( self::HELP ),
			dlms_asset_version( 'assets/js/audio.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			self::CARDS,
			DLMS_URL . 'assets/js/flashcards.js',
			array( self::AUDIO ),
			dlms_asset_version( 'assets/js/flashcards.js' ),
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
		$i18n = array(
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
			/* translators: %d: seconds. Keep the %d: the page replaces it every second. */
			'timeUpIn'      => __( 'Automatic submission in %d seconds.', 'deutschlms' ),
		);
		wp_add_inline_script(
			self::SCRIPT,
			'window.dlmsFrontend = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'dlms/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => $i18n,
					'i18nAll' => HelpLanguage::strings( $i18n ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueues the style and the play-button script (safe to call repeatedly).
	 * Unlike enqueue(), also for visitors who are not logged in.
	 */
	public static function enqueue_audio(): void {
		if ( ! wp_script_is( self::AUDIO, 'registered' ) ) {
			return;
		}
		wp_enqueue_style( self::STYLE );
		if ( wp_script_is( self::AUDIO, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( self::AUDIO );
		$i18n = array(
			'noVoice'  => __( 'This device has no German voice. You can add one in the language or speech settings of your device.', 'deutschlms' ),
			'noSpeech' => __( 'This browser cannot read texts aloud.', 'deutschlms' ),
			'failed'   => __( 'The recording could not be played.', 'deutschlms' ),
		);
		wp_add_inline_script(
			self::AUDIO,
			'window.dlmsAudio = ' . wp_json_encode(
				array(
					'i18n'    => $i18n,
					'i18nAll' => HelpLanguage::strings( $i18n ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueues the flashcard deck script (with the play buttons; safe to
	 * call repeatedly), also for visitors who are not logged in.
	 */
	public static function enqueue_flashcards(): void {
		if ( ! wp_script_is( self::CARDS, 'registered' ) ) {
			return;
		}
		self::enqueue_audio();
		if ( wp_script_is( self::CARDS, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( self::CARDS );
		$i18n = array(
			'deck'     => __( 'Flashcards', 'deutschlms' ),
			'previous' => __( 'Previous', 'deutschlms' ),
			'next'     => __( 'Next', 'deutschlms' ),
			'flip'     => __( 'Turn over', 'deutschlms' ),
			'shuffle'  => __( 'Shuffle', 'deutschlms' ),
			'shuffled' => __( 'The cards are shuffled.', 'deutschlms' ),
			/* translators: 1: number of the card shown, 2: number of cards. */
			'position' => __( 'Card %1$d of %2$d', 'deutschlms' ),
			'front'    => __( 'Front', 'deutschlms' ),
			'picture'  => __( 'Picture first', 'deutschlms' ),
			'german'   => __( 'German first', 'deutschlms' ),
			'all'      => __( 'Show all cards', 'deutschlms' ),
			'one'      => __( 'One card at a time', 'deutschlms' ),
			'hint'     => __( 'Think of the answer, then turn the card over. Click the card or press the space bar.', 'deutschlms' ),
		);
		wp_add_inline_script(
			self::CARDS,
			'window.dlmsFlashcards = ' . wp_json_encode(
				array(
					'i18n'    => $i18n,
					'i18nAll' => HelpLanguage::strings( $i18n ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueues the language switch script and the stylesheet (safe to call
	 * repeatedly); its configuration is added in the footer
	 * (HelpLanguage::add_config()).
	 */
	public static function enqueue_help(): void {
		if ( ! wp_script_is( self::HELP, 'registered' ) ) {
			return;
		}
		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::HELP );
	}
}
