<?php
/**
 * Enrollment box on a course.
 *
 * Override by copying to yourtheme/deutschlms/course/enroll-button.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type int        $course_id    Course ID.
 *     @type string     $course_title Course title.
 *     @type string     $state        logged_out|can_enroll|enrolled|completed|manager|not_available.
 *     @type string     $login_url    Login URL returning to the course.
 *     @type string     $register_url Registration URL ('' when registration is closed).
 *     @type string     $action_url   Form action (admin-post.php).
 *     @type string     $nonce_action Nonce action for the enroll form.
 *     @type string     $continue_url Where "Continue" leads.
 *     @type bool       $has_progress Whether the user has completed any step.
 *     @type string     $first_step   URL of the first step ('' if the course is empty).
 *     @type string     $certificate_url Certificate download link ('' unless earned).
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_state = $args['state'];
?>
<div class="dlms-enroll dlms-enroll--<?php echo esc_attr( $dlms_state ); ?>">
	<?php if ( 'logged_out' === $dlms_state ) : ?>
		<p class="dlms-enroll__text"><?php dlms_e( __( 'Log in to enroll in this course. It is free.', 'deutschlms' ) ); ?></p>
		<p class="dlms-enroll__actions">
			<a class="dlms-button" href="<?php echo esc_url( $args['login_url'] ); ?>"><?php dlms_e( __( 'Log in to enroll', 'deutschlms' ) ); ?></a>
			<?php if ( '' !== $args['register_url'] ) : ?>
				<a class="dlms-link" href="<?php echo esc_url( $args['register_url'] ); ?>"><?php dlms_e( __( 'Create an account', 'deutschlms' ) ); ?></a>
			<?php endif; ?>
		</p>

	<?php elseif ( 'can_enroll' === $dlms_state ) : ?>
		<form class="dlms-enroll__form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>" data-dlms-action="enroll" data-dlms-id="<?php echo esc_attr( (string) $args['course_id'] ); ?>">
			<input type="hidden" name="action" value="dlms_enroll" />
			<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $args['course_id'] ); ?>" />
			<input type="hidden" name="dlms_nonce" value="<?php echo esc_attr( wp_create_nonce( $args['nonce_action'] ) ); ?>" />
			<button type="submit" class="dlms-button"><?php dlms_e( __( 'Enroll for free', 'deutschlms' ) ); ?></button>
			<p class="dlms-form-status" role="status" aria-live="polite"></p>
		</form>

	<?php elseif ( 'enrolled' === $dlms_state ) : ?>
		<p class="dlms-enroll__text"><?php dlms_e( __( 'You are enrolled in this course.', 'deutschlms' ) ); ?></p>
		<?php if ( '' !== $args['continue_url'] ) : ?>
			<p class="dlms-enroll__actions">
				<a class="dlms-button" href="<?php echo esc_url( $args['continue_url'] ); ?>">
					<?php echo $args['has_progress'] ? dlms_t( __( 'Continue learning', 'deutschlms' ) ) : dlms_t( __( 'Start course', 'deutschlms' ) ); ?>
				</a>
			</p>
		<?php endif; ?>

	<?php elseif ( 'completed' === $dlms_state ) : ?>
		<p class="dlms-enroll__text dlms-status--complete">
			<span class="dlms-status-icon" aria-hidden="true"></span>
			<?php dlms_e( __( 'You have completed this course.', 'deutschlms' ) ); ?>
		</p>
		<p class="dlms-enroll__actions">
			<?php if ( '' !== $args['certificate_url'] ) : ?>
				<a class="dlms-button" href="<?php echo esc_url( $args['certificate_url'] ); ?>" target="_blank" rel="noopener"><?php dlms_e( __( 'Download certificate (PDF)', 'deutschlms' ) ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $args['first_step'] ) : ?>
				<a class="dlms-link" href="<?php echo esc_url( $args['first_step'] ); ?>"><?php dlms_e( __( 'Review the course', 'deutschlms' ) ); ?></a>
			<?php endif; ?>
		</p>

	<?php elseif ( 'manager' === $dlms_state ) : ?>
		<p class="dlms-enroll__text"><?php dlms_e( __( 'You manage this course, so you can open every lesson without enrolling.', 'deutschlms' ) ); ?></p>

	<?php else : ?>
		<p class="dlms-enroll__text"><?php dlms_e( __( 'Enrollment is not available for your account.', 'deutschlms' ) ); ?></p>
	<?php endif; ?>
</div>
