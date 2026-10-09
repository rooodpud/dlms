<?php
/**
 * "Mark complete" control on a lesson/topic.
 *
 * Override by copying to yourtheme/deutschlms/step/mark-complete.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type int    $step_id      Step ID.
 *     @type string $step_type    dlms_lesson|dlms_topic.
 *     @type string $state        can_complete|completed|auto|preview.
 *     @type bool   $has_topics   Lesson has topics (for the "auto" message).
 *     @type string $action_url   Form action (admin-post.php).
 *     @type string $nonce_action Nonce action.
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_state = $args['state'];
?>
<div class="dlms-complete dlms-complete--<?php echo esc_attr( $dlms_state ); ?>">
	<?php if ( 'can_complete' === $dlms_state ) : ?>
		<form class="dlms-complete__form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>" data-dlms-action="complete" data-dlms-id="<?php echo esc_attr( (string) $args['step_id'] ); ?>">
			<input type="hidden" name="action" value="dlms_complete_step" />
			<input type="hidden" name="step_id" value="<?php echo esc_attr( (string) $args['step_id'] ); ?>" />
			<input type="hidden" name="dlms_nonce" value="<?php echo esc_attr( wp_create_nonce( $args['nonce_action'] ) ); ?>" />
			<button type="submit" class="dlms-button">
				<?php
				echo 'dlms_topic' === $args['step_type']
					? dlms_t( __( 'Mark topic complete', 'deutschlms' ) )
					: dlms_t( __( 'Mark lesson complete', 'deutschlms' ) );
				?>
			</button>
			<p class="dlms-form-status" role="status" aria-live="polite"></p>
		</form>

	<?php elseif ( 'completed' === $dlms_state ) : ?>
		<p class="dlms-complete__status dlms-status--complete">
			<span class="dlms-status-icon" aria-hidden="true"></span>
			<?php dlms_e( __( 'Completed', 'deutschlms' ) ); ?>
		</p>

	<?php elseif ( 'auto' === $dlms_state ) : ?>
		<p class="dlms-complete__status">
			<?php
			if ( 'dlms_topic' === $args['step_type'] ) {
				dlms_e( __( 'This topic is completed automatically when you pass its quiz.', 'deutschlms' ) );
			} elseif ( ! empty( $args['has_topics'] ) ) {
				dlms_e( __( 'This lesson is completed automatically when you finish all of its topics and quizzes.', 'deutschlms' ) );
			} else {
				dlms_e( __( 'This lesson is completed automatically when you pass its quiz.', 'deutschlms' ) );
			}
			?>
		</p>

	<?php else : ?>
		<p class="dlms-complete__status dlms-complete__status--preview"><?php dlms_e( __( 'Preview: you can see this because you manage the course. Progress is only tracked for enrolled students.', 'deutschlms' ) ); ?></p>
	<?php endif; ?>
</div>
