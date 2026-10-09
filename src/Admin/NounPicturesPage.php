<?php
/**
 * Courses → Noun pictures.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\NounPictures;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Quiz\QuestionBank;
use DeutschLMS\Quiz\Questions;

defined( 'ABSPATH' ) || exit;

/**
 * The noun picture library: one icon or uploaded image per noun, used by
 * every article question and noun card with that noun. Lists the nouns of
 * the library and those of the article questions (so missing pictures show
 * up); changes are saved right away through the REST API.
 */
final class NounPicturesPage {

	public const SLUG   = 'dlms-noun-pictures';
	public const SCRIPT = 'dlms-noun-pictures';

	/**
	 * Most article questions read for the list.
	 */
	private const MAX_QUESTIONS = 2000;

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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Capability to manage the library (editing questions).
	 *
	 * @return string
	 */
	public static function capability(): string {
		$type = get_post_type_object( PostTypes::QUESTION );
		return $type ? (string) $type->cap->edit_posts : 'manage_options';
	}

	/**
	 * Menu entry under Courses.
	 */
	public function add_menu(): void {
		$this->hook = (string) add_submenu_page(
			'edit.php?post_type=' . PostTypes::COURSE,
			__( 'Noun pictures', 'deutschlms' ),
			__( 'Noun pictures', 'deutschlms' ),
			self::capability(),
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
		self::enqueue_picker();
		wp_enqueue_script( self::SCRIPT . '-page', DLMS_URL . 'assets/admin/noun-pictures-page.js', array( self::SCRIPT ), dlms_asset_version( 'assets/admin/noun-pictures-page.js' ), true );
		wp_set_script_translations( self::SCRIPT . '-page', 'deutschlms', DLMS_PATH . 'languages' );
	}

	/**
	 * The picture picker (icons and Media Library), shared with the question editor.
	 */
	public static function enqueue_picker(): void {
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( self::SCRIPT, DLMS_URL . 'assets/admin/noun-pictures.css', array(), dlms_asset_version( 'assets/admin/noun-pictures.css' ) );
		wp_enqueue_script( self::SCRIPT, DLMS_URL . 'assets/admin/noun-pictures.js', array( 'wp-i18n', 'wp-a11y', 'wp-api-fetch' ), dlms_asset_version( 'assets/admin/noun-pictures.js' ), true );
		wp_set_script_translations( self::SCRIPT, 'deutschlms', DLMS_PATH . 'languages' );
		$data                = NounPictures::editor_data();
		$data['canUpload']   = current_user_can( 'upload_files' );
		$data['restPath']    = '/dlms/v1/noun-pictures';
		$data['libraryPage'] = admin_url( 'edit.php?post_type=' . PostTypes::COURSE . '&page=' . self::SLUG );
		wp_add_inline_script( self::SCRIPT, 'window.dlmsNounPictureData = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * The page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'deutschlms' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Noun pictures', 'deutschlms' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'One picture per noun: an icon or your own image. Every article question and noun card with that noun shows it, in the colour of its article.', 'deutschlms' ); ?>
			</p>
			<div id="dlms-noun-pictures" data-nouns="<?php echo esc_attr( (string) wp_json_encode( $this->nouns() ) ); ?>">
				<p><?php esc_html_e( 'Loading…', 'deutschlms' ); ?></p>
			</div>
			<noscript><p><?php esc_html_e( 'This page needs JavaScript.', 'deutschlms' ); ?></p></noscript>
		</div>
		<?php
	}

	/**
	 * Nouns for the list: the library plus the nouns of article questions.
	 *
	 * @return array<int, array{key: string, noun: string, article: string, questions: int}>
	 */
	private function nouns(): array {
		$nouns = array();
		foreach ( NounPictures::library() as $key => $entry ) {
			$nouns[ $key ] = array(
				'key'       => $key,
				'noun'      => $entry['noun'],
				'article'   => '',
				'questions' => 0,
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => PostTypes::QUESTION,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => self::MAX_QUESTIONS,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Meta::QUESTION_TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen, indexed key.
				'meta_value'     => Questions::TYPE_ARTICLE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin screen.
			)
		);
		foreach ( $ids as $post_id ) {
			$question = $this->bank->get( (int) $post_id );
			if ( null === $question || Questions::TYPE_ARTICLE !== $question['type'] ) {
				continue;
			}
			// Questions with their own icon or image don't need the library; one
			// that names another noun (die Tischlampe → lampe) counts for that noun.
			$own = (string) ( $question['picture'] ?? '' );
			if ( str_contains( $own, ':' ) ) {
				continue;
			}
			$noun_key = NounPictures::key_for( $question['text'] );
			$key      = '' !== $own ? $own : $noun_key;
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $nouns[ $key ] ) ) {
				$nouns[ $key ] = array(
					'key'       => $key,
					'noun'      => $key,
					'article'   => '',
					'questions' => 0,
				);
			}
			if ( $noun_key === $key ) {
				$nouns[ $key ]['noun']    = $question['text'];
				$nouns[ $key ]['article'] = Questions::article_of( $question );
			}
			++$nouns[ $key ]['questions'];
		}//end foreach

		uasort( $nouns, static fn( $a, $b ) => strnatcasecmp( $a['noun'], $b['noun'] ) );
		return array_values( $nouns );
	}
}
