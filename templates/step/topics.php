<?php
/**
 * What's inside a step: on a lesson, its topics (each with its quizzes) and
 * the lesson's quizzes; on a topic, its quizzes.
 *
 * Override by copying to yourtheme/deutschlms/step/topics.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type int    $step_id   Lesson or topic ID.
 *     @type string $step_type dlms_lesson|dlms_topic.
 *     @type array  $topics    Child items (see parts/step-item.php), each with `quizzes`.
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_heading_id = wp_unique_id( 'dlms-topics-' );
$dlms_heading    = 'dlms_topic' === $args['step_type']
	? dlms_t( __( 'Exercises for this topic', 'deutschlms' ) )
	: dlms_t( __( 'In this lesson', 'deutschlms' ) );
?>
<section class="dlms-topics" aria-labelledby="<?php echo esc_attr( $dlms_heading_id ); ?>">
	<h2 class="dlms-topics__heading" id="<?php echo esc_attr( $dlms_heading_id ); ?>"><?php echo $dlms_heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by dlms_t(). ?></h2>
	<ol class="dlms-topics__list">
		<?php foreach ( $args['topics'] as $dlms_topic ) : ?>
			<li class="dlms-topics__item dlms-status--<?php echo esc_attr( $dlms_topic['status'] ); ?>">
				<?php dlms_template( 'parts/step-item.php', array( 'item' => $dlms_topic ) ); ?>
				<?php if ( ! empty( $dlms_topic['quizzes'] ) ) : ?>
					<ol class="dlms-outline__quizzes">
						<?php foreach ( $dlms_topic['quizzes'] as $dlms_quiz ) : ?>
							<li class="dlms-topics__item dlms-status--<?php echo esc_attr( $dlms_quiz['status'] ); ?>">
								<?php dlms_template( 'parts/step-item.php', array( 'item' => $dlms_quiz ) ); ?>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
