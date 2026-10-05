<?php
/**
 * Flash notice after an action (enrolled, course completed, error).
 *
 * Override by copying to yourtheme/deutschlms/notice.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type string $type            success|error.
 *     @type string $message         Message text.
 *     @type string $certificate_url Certificate link to offer ('' for none).
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="dlms-notice dlms-notice--<?php echo esc_attr( $args['type'] ); ?>" role="<?php echo 'error' === $args['type'] ? 'alert' : 'status'; ?>">
	<?php echo esc_html( $args['message'] ); ?>
	<?php if ( ! empty( $args['certificate_url'] ) ) : ?>
		<a class="dlms-link" href="<?php echo esc_url( $args['certificate_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Download your certificate (PDF)', 'deutschlms' ); ?></a>
	<?php endif; ?>
</div>
