<?php
/**
 * Course outline: lessons with nested topics and quizzes, then final quizzes.
 * Section headings split the lessons into separate lists, each headed by them.
 *
 * Override by copying to yourtheme/deutschlms/course/outline.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type int    $course_id    Course ID.
 *     @type string $course_title Course title.
 *     @type array  $lessons      Items (see parts/step-item.php), each with `topics`
 *                                (items with their own `quizzes`), `quizzes` and
 *                                `headings` (section heading texts shown above it).
 *     @type array  $quizzes      Course-level (final) quiz items.
 *     @type array  $end_headings Section heading texts after the last lesson.
 *     @type bool   $show_topics  Whether topics and quizzes are listed.
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_heading_id = wp_unique_id( 'dlms-outline-' );

/**
 * Prints a nested list of items.
 *
 * @param array  $items Items.
 * @param string $list_class List class.
 */
$dlms_list = static function ( array $items, string $list_class ) {
	if ( empty( $items ) ) {
		return;
	}
	echo '<ol class="' . esc_attr( $list_class ) . '">';
	foreach ( $items as $dlms_child ) {
		echo '<li class="dlms-outline__child dlms-status--' . esc_attr( $dlms_child['status'] ) . ( $dlms_child['current'] ? ' is-current' : '' ) . '">';
		dlms_template( 'parts/step-item.php', array( 'item' => $dlms_child ) );
		if ( ! empty( $dlms_child['quizzes'] ) ) {
			echo '<ol class="dlms-outline__quizzes">';
			foreach ( $dlms_child['quizzes'] as $dlms_quiz ) {
				echo '<li class="dlms-outline__child dlms-status--' . esc_attr( $dlms_quiz['status'] ) . ( $dlms_quiz['current'] ? ' is-current' : '' ) . '">';
				dlms_template( 'parts/step-item.php', array( 'item' => $dlms_quiz ) );
				echo '</li>';
			}
			echo '</ol>';
		}
		echo '</li>';
	}
	echo '</ol>';
};

/**
 * Prints section headings.
 *
 * @param string[] $titles Heading texts.
 */
$dlms_sections = static function ( array $titles ) {
	foreach ( $titles as $dlms_title ) {
		echo '<h3 class="dlms-outline__section">' . esc_html( $dlms_title ) . '</h3>';
	}
};

$dlms_open = false;
?>
<nav class="dlms-outline" aria-labelledby="<?php echo esc_attr( $dlms_heading_id ); ?>">
	<h2 class="dlms-outline__heading" id="<?php echo esc_attr( $dlms_heading_id ); ?>"><?php esc_html_e( 'Course content', 'deutschlms' ); ?></h2>

	<?php if ( empty( $args['lessons'] ) && empty( $args['quizzes'] ) ) : ?>
		<p class="dlms-empty"><?php esc_html_e( 'This course has no lessons yet.', 'deutschlms' ); ?></p>
	<?php else : ?>
		<?php foreach ( $args['lessons'] as $dlms_lesson ) : ?>
			<?php if ( ! empty( $dlms_lesson['headings'] ) ) : ?>
				<?php echo $dlms_open ? '</ol>' : ''; ?>
				<?php $dlms_sections( $dlms_lesson['headings'] ); ?>
				<?php $dlms_open = false; ?>
			<?php endif; ?>
			<?php if ( ! $dlms_open ) : ?>
				<ol class="dlms-outline__lessons">
				<?php $dlms_open = true; ?>
			<?php endif; ?>
				<li class="dlms-outline__lesson dlms-status--<?php echo esc_attr( $dlms_lesson['status'] ); ?><?php echo $dlms_lesson['current'] ? ' is-current' : ''; ?>">
					<?php dlms_template( 'parts/step-item.php', array( 'item' => $dlms_lesson ) ); ?>
					<?php $dlms_list( array_merge( $dlms_lesson['topics'], $dlms_lesson['quizzes'] ), 'dlms-outline__topics' ); ?>
				</li>
		<?php endforeach; ?>
		<?php if ( ! empty( $args['end_headings'] ) ) : ?>
			<?php echo $dlms_open ? '</ol>' : ''; ?>
			<?php $dlms_sections( $args['end_headings'] ); ?>
			<?php $dlms_open = false; ?>
		<?php endif; ?>
		<?php if ( ! $dlms_open && ! empty( $args['quizzes'] ) ) : ?>
			<ol class="dlms-outline__lessons">
			<?php $dlms_open = true; ?>
		<?php endif; ?>
			<?php foreach ( $args['quizzes'] as $dlms_final ) : ?>
				<li class="dlms-outline__lesson dlms-outline__final dlms-status--<?php echo esc_attr( $dlms_final['status'] ); ?><?php echo $dlms_final['current'] ? ' is-current' : ''; ?>">
					<?php dlms_template( 'parts/step-item.php', array( 'item' => $dlms_final ) ); ?>
				</li>
			<?php endforeach; ?>
		<?php echo $dlms_open ? '</ol>' : ''; ?>
	<?php endif; ?>
</nav>
