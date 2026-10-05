<?php
/**
 * One lesson/topic/quiz row in the outline and step lists (the caller nests
 * children).
 *
 * Override by copying to yourtheme/deutschlms/parts/step-item.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type array $item {
 *         @type int    $id           Step ID.
 *         @type string $type         dlms_lesson|dlms_topic|dlms_quiz.
 *         @type string $title        Title.
 *         @type string $url          Link ('' when the user cannot open it).
 *         @type string $status       complete|available|scheduled|locked.
 *         @type bool   $current      Whether this is the step being viewed.
 *         @type string $available_on Drip unlock date ('' if none).
 *     }
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_item   = $args['item'];
$dlms_labels = array(
	'complete'  => __( 'Completed', 'deutschlms' ),
	'locked'    => __( 'Locked', 'deutschlms' ),
	'scheduled' => __( 'Locked', 'deutschlms' ),
);
?>
<span class="dlms-step-row">
	<span class="dlms-status-icon" aria-hidden="true"></span>
	<?php if ( 'dlms_quiz' === ( $dlms_item['type'] ?? '' ) ) : ?>
		<span class="dlms-step-row__type"><?php esc_html_e( 'Quiz', 'deutschlms' ); ?></span>
	<?php endif; ?>
	<?php if ( '' !== $dlms_item['url'] ) : ?>
		<a class="dlms-step-row__title" href="<?php echo esc_url( $dlms_item['url'] ); ?>"<?php echo $dlms_item['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $dlms_item['title'] ); ?></a>
	<?php else : ?>
		<span class="dlms-step-row__title"><?php echo esc_html( $dlms_item['title'] ); ?></span>
	<?php endif; ?>
	<?php if ( 'scheduled' === $dlms_item['status'] && '' !== ( $dlms_item['available_on'] ?? '' ) ) : ?>
		<span class="dlms-step-row__date">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: date. */
					__( 'Available on %s', 'deutschlms' ),
					$dlms_item['available_on']
				)
			);
			?>
		</span>
	<?php elseif ( isset( $dlms_labels[ $dlms_item['status'] ] ) ) : ?>
		<span class="dlms-sr"><?php echo esc_html( '(' . $dlms_labels[ $dlms_item['status'] ] . ')' ); ?></span>
	<?php endif; ?>
</span>
