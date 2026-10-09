<?php
/**
 * "Title translations" meta box.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use DeutschLMS\Frontend\HelpLanguage;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Translations of a course, lesson, topic or quiz title for the language
 * switch: in English the outline shows "Übung 0.1 a: Wörter (Words)" and the
 * page shows "Words" under its heading (see HelpLanguage::title()).
 */
final class TitleTranslations {

	private const NONCE_ACTION = 'dlms_title_translations';
	private const NONCE_FIELD  = 'dlms_title_translations_nonce';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		foreach ( self::post_types() as $post_type ) {
			add_action( 'save_post_' . $post_type, array( $this, 'save' ) );
		}
	}

	/**
	 * Post types with title translations.
	 *
	 * @return string[]
	 */
	private static function post_types(): array {
		return array_merge( array( PostTypes::COURSE ), PostTypes::step_types() );
	}

	/**
	 * Adds the box.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_meta_box( $post_type ): void {
		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}
		add_meta_box( 'dlms-title-translations', __( 'Title translations', 'deutschlms' ), array( $this, 'render' ), $post_type, 'side' );
	}

	/**
	 * Renders the fields.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$help      = HelpLanguage::title_help( $post->ID );
		$languages = HelpLanguage::languages();
		?>
		<p class="description"><?php esc_html_e( 'For courses with help languages: shown in brackets after the German title, in the language the learner chose (e.g. "Words" for "Übung 0.1 a: Wörter").', 'deutschlms' ); ?></p>
		<?php foreach ( HelpLanguage::translation_codes() as $dlms_code ) : ?>
			<p>
				<label for="dlms-title-help-<?php echo esc_attr( $dlms_code ); ?>"><?php echo esc_html( $languages[ $dlms_code ]['name'] ); ?></label>
				<input type="text" class="widefat" id="dlms-title-help-<?php echo esc_attr( $dlms_code ); ?>" name="dlms_title_help[<?php echo esc_attr( $dlms_code ); ?>]" lang="<?php echo esc_attr( $dlms_code ); ?>" maxlength="200" value="<?php echo esc_attr( $help[ $dlms_code ] ?? '' ); ?>" />
			</p>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Saves the translations.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['dlms_title_help'] ) && is_array( $_POST['dlms_title_help'] ) ? map_deep( wp_unslash( $_POST['dlms_title_help'] ), 'sanitize_text_field' ) : array();
		update_post_meta( (int) $post_id, Meta::TITLE_HELP, HelpLanguage::sanitize_texts( $raw, 200 ) );
	}
}
