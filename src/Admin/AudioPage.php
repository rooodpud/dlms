<?php
/**
 * Courses → Audio.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\AudioClips;
use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Frontend\Assets;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\Questions;

defined( 'ABSPATH' ) || exit;

/**
 * The audio library: one recording per German text. Lists every text that
 * has a play button: [dlms_say] texts, dialogue lines and noun cards in
 * course content, and
 * the texts of listening questions, plus recordings whose text is no longer
 * used. Texts without a recording are read by the browser's German voice.
 * Changes are saved right away through the REST API.
 */
final class AudioPage {

	public const SLUG   = 'dlms-audio';
	public const SCRIPT = 'dlms-audio-page';

	/**
	 * Most posts and questions read for the list.
	 */
	private const MAX_POSTS = 3000;

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private QuestionBank $bank;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Constructor.
	 *
	 * @param QuestionBank $bank Question bank.
	 */
	public function __construct( QuestionBank $bank ) {
		$this->bank = $bank;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Menu entry under Courses.
	 */
	public function add_menu(): void {
		$this->hook = (string) add_submenu_page(
			'edit.php?post_type=' . PostTypes::COURSE,
			__( 'Audio', 'deutschlms' ),
			__( 'Audio', 'deutschlms' ),
			NounPicturesPage::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Scripts for the page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		Assets::enqueue_audio();
		wp_enqueue_style( NounPicturesPage::SCRIPT, DLMS_URL . 'assets/admin/noun-pictures.css', array(), dlms_asset_version( 'assets/admin/noun-pictures.css' ) );
		wp_enqueue_script( self::SCRIPT, DLMS_URL . 'assets/admin/audio-page.js', array( 'wp-i18n', 'wp-a11y', 'wp-api-fetch' ), dlms_asset_version( 'assets/admin/audio-page.js' ), true );
		wp_set_script_translations( self::SCRIPT, 'deutschlms', DLMS_PATH . 'languages' );
		wp_add_inline_script(
			self::SCRIPT,
			'window.dlmsAudioPage = ' . wp_json_encode(
				array(
					'restPath'  => '/dlms/v1/audio-clips',
					'canUpload' => current_user_can( 'upload_files' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The page.
	 */
	public function render(): void {
		if ( ! current_user_can( NounPicturesPage::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Audio', 'deutschlms' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'One recording per German text. Every play button with that text plays it: sentences and dialogues in lessons ([dlms_say], [dlms_dialog]), noun cards and listening questions. Texts without a recording are read aloud by the student’s browser with a German voice.', 'deutschlms' ); ?>
			</p>
			<div id="dlms-audio-page" data-texts="<?php echo esc_attr( (string) wp_json_encode( $this->texts() ) ); ?>">
				<p><?php esc_html_e( 'Loading…', 'deutschlms' ); ?></p>
			</div>
			<noscript><p><?php esc_html_e( 'This page needs JavaScript.', 'deutschlms' ); ?></p></noscript>
		</div>
		<?php
	}

	/**
	 * Every text with a play button, with where it is used and its recording.
	 *
	 * @return array<int, array{key: string, text: string, kind: string, where: array, media: int, url: string, file: string}>
	 */
	public function texts(): array {
		$texts = array();
		$add   = static function ( string $text, string $kind, int $post_id ) use ( &$texts ): void {
			$text = AudioClips::clean_text( $text );
			$key  = AudioClips::key_for( $text );
			if ( '' === $key ) {
				return;
			}
			if ( ! isset( $texts[ $key ] ) ) {
				$texts[ $key ] = array(
					'key'   => $key,
					'text'  => $text,
					'kind'  => $kind,
					'where' => array(),
				);
			}
			if ( $post_id && count( $texts[ $key ]['where'] ) < 5 && ! isset( $texts[ $key ]['where'][ $post_id ] ) ) {
				$texts[ $key ]['where'][ $post_id ] = array(
					'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
					'link'  => (string) get_edit_post_link( $post_id, 'raw' ),
				);
			}
		};

		// Course content: [dlms_say] and noun cards.
		$posts = get_posts(
			array(
				'post_type'      => array( PostTypes::COURSE, PostTypes::LESSON, PostTypes::TOPIC, PostTypes::QUIZ ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => self::MAX_POSTS,
				'no_found_rows'  => true,
				's'              => '[dlms_',
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			foreach ( self::content_texts( (string) $post->post_content ) as $found ) {
				$add( $found['text'], $found['kind'], (int) $post->ID );
			}
		}

		// Listening questions.
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::QUESTION,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => self::MAX_POSTS,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin screen.
					array(
						'key'     => Meta::QUESTION,
						'value'   => '"listen"',
						'compare' => 'LIKE',
					),
				),
			)
		);
		foreach ( $ids as $post_id ) {
			$question = $this->bank->get( (int) $post_id );
			if ( null !== $question && '' !== (string) ( $question['listen'] ?? '' ) && empty( $question['audio'] ) ) {
				$add( $question['listen'], 'question', (int) $post_id );
			}
		}

		// Recordings, also of texts no longer used.
		foreach ( AudioClips::library() as $key => $entry ) {
			if ( ! isset( $texts[ $key ] ) ) {
				$add( $entry['text'], 'unused', 0 );
			}
			$texts[ $key ]['media'] = $entry['media'];
		}

		$rows = array();
		foreach ( $texts as $row ) {
			$media        = (int) ( $row['media'] ?? 0 );
			$row['media'] = $media;
			$row['url']   = AudioClips::url( $media );
			$row['file']  = '' !== $row['url'] ? wp_basename( (string) get_attached_file( $media ) ) : '';
			$row['where'] = array_values( $row['where'] );
			$rows[]       = $row;
		}
		return $rows;
	}

	/**
	 * Texts with play buttons in post content: [dlms_say] and the lines of
	 * [dlms_dialog] (kind "sentence"; a line with the speaker's own
	 * recording as "Name: text"), noun cards (kind "word", said as
	 * "der Tisch") and word cards (kind "word", their text or say="…").
	 *
	 * @param string $content Post content.
	 * @return array<int, array{text: string, kind: string}>
	 */
	public static function content_texts( string $content ): array {
		$found = array();
		if ( ! str_contains( $content, '[dlms_' ) ) {
			return $found;
		}
		preg_match_all( '/' . get_shortcode_regex( array( 'dlms_say', 'dlms_dialog', 'dlms_noun', 'dlms_word' ) ) . '/s', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			// [[escaped]] shortcodes are not shortcodes.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}
			$atts = shortcode_parse_atts( $match[3] );
			$atts = is_array( $atts ) ? $atts : array();
			if ( 'dlms_dialog' === $match[2] ) {
				foreach ( AudioClips::dialog_lines( $match[5] ) as $line ) {
					$text    = do_shortcode( $line['html'] );
					$own     = '' !== $line['speaker'] ? $line['speaker'] . ': ' . AudioClips::clean_text( $text ) : '';
					$found[] = array(
						'text' => '' !== $own && AudioClips::media_for( $own ) ? $own : $text,
						'kind' => 'sentence',
					);
				}
				continue;
			}
			if ( 'dlms_word' === $match[2] ) {
				$positional = array();
				foreach ( $atts as $att_key => $value ) {
					if ( is_int( $att_key ) ) {
						$positional[] = (string) $value;
					}
				}
				$say   = trim( (string) ( $atts['say'] ?? '' ) );
				$text  = '' !== $say ? $say : trim( (string) ( $atts['word'] ?? implode( ' ', $positional ) ) );
				$audio = strtolower( (string) ( $atts['audio'] ?? 'yes' ) );
				if ( '' !== $text && in_array( $audio, array( '1', 'yes', 'true', 'on' ), true ) ) {
					$found[] = array(
						'text' => $text,
						'kind' => 'word',
					);
				}
				continue;
			}
			if ( 'dlms_say' === $match[2] ) {
				$text = '' !== trim( $match[5] ) ? do_shortcode( $match[5] ) : (string) ( $atts['text'] ?? '' );
				if ( empty( $atts['audio'] ) ) {
					$found[] = array(
						'text' => $text,
						'kind' => 'sentence',
					);
				}
				continue;
			}
			$article = strtolower( (string) ( $atts['article'] ?? ( $atts[0] ?? '' ) ) );
			$word    = trim( (string) ( $atts['word'] ?? ( $atts[1] ?? '' ) ) );
			$style   = (string) ( $atts['style'] ?? 'card' );
			$audio   = strtolower( (string) ( $atts['audio'] ?? 'yes' ) );
			if ( 'inline' === $style || '' === $word || ! in_array( $article, Questions::ARTICLES, true ) || ! in_array( $audio, array( '1', 'yes', 'true', 'on' ), true ) ) {
				continue;
			}
			$found[] = array(
				'text' => $article . ' ' . $word,
				'kind' => 'word',
			);
		}//end foreach
		return $found;
	}
}
