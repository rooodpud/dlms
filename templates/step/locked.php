<?php
/**
 * Shown instead of a lesson/topic the visitor may not open.
 *
 * Override by copying to yourtheme/deutschlms/step/locked.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type string $reason         not_logged_in|not_enrolled|drip|locked|unavailable|no_course|not_a_step.
 *     @type int    $step_id        Step ID.
 *     @type string $course_title   Course title.
 *     @type string $course_url     Course URL.
 *     @type string $login_url      Login URL returning here.
 *     @type string $blocking_title Unfinished prerequisite (linear courses).
 *     @type string $blocking_url   Its URL.
 *     @type string $available_on   Drip unlock date ('' if not dripped).
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_reason = $args['reason'];
$dlms_course = wp_strip_all_tags( $args['course_title'] );
?>
<div class="dlms-locked dlms-locked--<?php echo esc_attr( $dlms_reason ); ?>">
	<p class="dlms-locked__title">
		<span class="dlms-lock-icon" aria-hidden="true"></span>
		<?php
		if ( 'locked' === $dlms_reason || 'drip' === $dlms_reason ) {
			dlms_e( __( 'Not unlocked yet', 'deutschlms' ) );
		} elseif ( in_array( $dlms_reason, array( 'not_logged_in', 'not_enrolled' ), true ) ) {
			dlms_e( __( 'This content is for enrolled students', 'deutschlms' ) );
		} else {
			dlms_e( __( 'This content is not available', 'deutschlms' ) );
		}
		?>
	</p>

	<?php if ( 'drip' === $dlms_reason && '' !== $args['available_on'] ) : ?>
		<p>
			<?php
			dlms_e(
				/* translators: %s: date. */
				__( 'This lesson opens on %s. Come back then!', 'deutschlms' ),
				$args['available_on']
			);
			?>
		</p>
	<?php elseif ( 'locked' === $dlms_reason && '' !== $args['blocking_title'] ) : ?>
		<p>
			<?php dlms_e( __( 'This course unlocks its lessons in order. Please finish this step first:', 'deutschlms' ) ); ?>
			<a href="<?php echo esc_url( $args['blocking_url'] ); ?>"><?php echo dlms_title( (int) ( $args['blocking_id'] ?? 0 ), $args['blocking_title'] ); ?></a>
		</p>
	<?php elseif ( 'not_logged_in' === $dlms_reason && '' !== $dlms_course ) : ?>
		<p>
			<?php
			dlms_e(
				/* translators: %s: course title. */
				__( 'Log in and enroll in “%s” to continue.', 'deutschlms' ),
				$dlms_course
			);
			?>
		</p>
	<?php elseif ( 'not_enrolled' === $dlms_reason && '' !== $dlms_course ) : ?>
		<p>
			<?php
			dlms_e(
				/* translators: %s: course title. */
				__( 'Enroll in “%s” to open this lesson.', 'deutschlms' ),
				$dlms_course
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $args['course_url'] ) : ?>
		<p class="dlms-locked__back">
			<a class="dlms-link" href="<?php echo esc_url( $args['course_url'] ); ?>">
				<span class="dlms-arrow dlms-arrow--back" aria-hidden="true"></span>
				<?php dlms_e( __( 'Go to the course overview', 'deutschlms' ) ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
