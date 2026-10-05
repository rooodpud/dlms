<?php
/**
 * Course settings meta box.
 *
 * @package DeutschLMS
 */

namespace DeutschLMS\Admin;

use DeutschLMS\Content\Meta;
use DeutschLMS\Content\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Per-course settings: linear progression and the completion certificate.
 */
final class CourseSettings {

	public const SCRIPT = 'dlms-course-settings';

	private const NONCE_ACTION = 'dlms_course_settings';
	private const NONCE_FIELD  = 'dlms_course_settings_nonce';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostTypes::COURSE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . PostTypes::COURSE, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'dlms-course-settings',
			__( 'Course settings', 'deutschlms' ),
			array( $this, 'render' ),
			PostTypes::COURSE,
			'side'
		);
	}

	/**
	 * Renders the settings.
	 *
	 * @param WP_Post $post Course.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$linear     = (bool) get_post_meta( $post->ID, Meta::LINEAR, true );
		$cert       = (bool) get_post_meta( $post->ID, Meta::CERT_ENABLED, true );
		$title      = (string) get_post_meta( $post->ID, Meta::CERT_TITLE, true );
		$signer     = (string) get_post_meta( $post->ID, Meta::CERT_SIGNER, true );
		$background = absint( get_post_meta( $post->ID, Meta::CERT_BACKGROUND, true ) );
		$preview    = $background ? wp_get_attachment_image_url( $background, 'medium' ) : '';
		?>
		<p>
			<label>
				<input type="checkbox" name="dlms_linear_progression" value="1" <?php checked( $linear ); ?> />
				<?php esc_html_e( 'Linear progression', 'deutschlms' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Students must finish each lesson, topic and quiz in order before the next one unlocks.', 'deutschlms' ); ?>
		</p>

		<hr />

		<p>
			<label>
				<input type="checkbox" name="dlms_certificate_enabled" value="1" <?php checked( $cert ); ?> />
				<?php esc_html_e( 'Certificate on completion', 'deutschlms' ); ?>
			</label>
		</p>
		<p>
			<label for="dlms-certificate-title"><?php esc_html_e( 'Certificate heading', 'deutschlms' ); ?></label>
			<input type="text" id="dlms-certificate-title" name="dlms_certificate_title" class="widefat" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Certificate of Completion', 'deutschlms' ); ?>" />
		</p>
		<p>
			<label for="dlms-certificate-signer"><?php esc_html_e( 'Signed by (name)', 'deutschlms' ); ?></label>
			<input type="text" id="dlms-certificate-signer" name="dlms_certificate_signer" class="widefat" value="<?php echo esc_attr( $signer ); ?>" />
		</p>
		<div class="dlms-certificate-background">
			<p><?php esc_html_e( 'Background image (optional, landscape A4)', 'deutschlms' ); ?></p>
			<input type="hidden" id="dlms-certificate-background" name="dlms_certificate_background" value="<?php echo esc_attr( (string) $background ); ?>" />
			<img id="dlms-certificate-background-preview" alt=""<?php echo $preview ? ' src="' . esc_url( $preview ) . '"' : ''; ?> style="max-width:100%;<?php echo $preview ? '' : 'display:none;'; ?>" />
			<p>
				<button type="button" class="button" id="dlms-certificate-background-choose"><?php esc_html_e( 'Choose image', 'deutschlms' ); ?></button>
				<button type="button" class="button-link button-link-delete" id="dlms-certificate-background-remove"<?php echo $background ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove image', 'deutschlms' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the settings.
	 *
	 * @param int $post_id Course ID.
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Meta::LINEAR, ! empty( $_POST['dlms_linear_progression'] ) );
		update_post_meta( $post_id, Meta::CERT_ENABLED, ! empty( $_POST['dlms_certificate_enabled'] ) );

		if ( isset( $_POST['dlms_certificate_title'] ) ) {
			update_post_meta( $post_id, Meta::CERT_TITLE, mb_substr( sanitize_text_field( wp_unslash( $_POST['dlms_certificate_title'] ) ), 0, 120 ) );
		}
		if ( isset( $_POST['dlms_certificate_signer'] ) ) {
			update_post_meta( $post_id, Meta::CERT_SIGNER, mb_substr( sanitize_text_field( wp_unslash( $_POST['dlms_certificate_signer'] ) ), 0, 120 ) );
		}
		if ( isset( $_POST['dlms_certificate_background'] ) ) {
			$attachment_id = absint( $_POST['dlms_certificate_background'] );
			// Only images from the media library the user can see.
			if ( $attachment_id && ( ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) ) {
				$attachment_id = 0;
			}
			update_post_meta( $post_id, Meta::CERT_BACKGROUND, $attachment_id );
		}
	}

	/**
	 * Media picker script on the course edit screen.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || PostTypes::COURSE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( self::SCRIPT, DLMS_URL . 'assets/admin/course-settings.js', array( 'wp-i18n', 'media-editor' ), DLMS_VERSION, true );
		wp_set_script_translations( self::SCRIPT, 'deutschlms', DLMS_PATH . 'languages' );
	}
}
