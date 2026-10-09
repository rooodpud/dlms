<?php
/**
 * Course progress bar.
 *
 * Override by copying to yourtheme/deutschlms/course/progress-bar.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type string $course_title Course title.
 *     @type int    $percent      0–100.
 *     @type int    $completed    Completed steps.
 *     @type int    $total        Total steps.
 *     @type bool   $show_label   Print the text label.
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_percent = max( 0, min( 100, (int) $args['percent'] ) );
?>
<div class="dlms-progress">
	<div
		class="dlms-progress__bar"
		role="progressbar"
		aria-valuemin="0"
		aria-valuemax="100"
		aria-valuenow="<?php echo esc_attr( (string) $dlms_percent ); ?>"
		<?php echo dlms_attr( 'aria-label', /* translators: %s: course title. */ __( 'Progress in %s', 'deutschlms' ), wp_strip_all_tags( $args['course_title'] ) ); ?>
	>
		<span class="dlms-progress__fill" style="width: <?php echo esc_attr( (string) $dlms_percent ); ?>%"></span>
	</div>
	<?php if ( ! empty( $args['show_label'] ) ) : ?>
		<p class="dlms-progress__label">
			<?php
			dlms_e(
				/* translators: 1: percentage, 2: completed steps, 3: total steps. */
				_n( '%1$d%% complete · %2$d of %3$d step', '%1$d%% complete · %2$d of %3$d steps', (int) $args['total'], 'deutschlms' ),
				$dlms_percent,
				(int) $args['completed'],
				(int) $args['total']
			);
			?>
		</p>
	<?php endif; ?>
</div>
