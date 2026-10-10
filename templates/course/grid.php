<?php
/**
 * Course grid.
 *
 * Override by copying to yourtheme/deutschlms/course/grid.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type int   $columns 1–4.
 *     @type array $groups  List of { label (heading, '' = none), courses } in display order.
 *     @type array $courses All courses, flat: {
 *         id, title, url, excerpt, thumbnail_html, lesson_count, enrolled, percent (int|null), level
 *     }.
 * }
 */

defined( 'ABSPATH' ) || exit;

// Older theme overrides and callers only know the flat list.
$dlms_groups = isset( $args['groups'] ) ? $args['groups'] : array(
	array(
		'label'   => '',
		'courses' => $args['courses'],
	),
);
$dlms_cols   = (int) $args['columns'];
?>
<?php if ( empty( $args['courses'] ) ) : ?>
	<div class="dlms-grid dlms-grid--cols-<?php echo esc_attr( (string) $dlms_cols ); ?>">
		<p class="dlms-empty"><?php esc_html_e( 'No courses are available yet.', 'deutschlms' ); ?></p>
	</div>
<?php endif; ?>

<?php foreach ( $dlms_groups as $dlms_group ) : ?>
	<?php if ( '' !== $dlms_group['label'] ) : ?>
	<section class="dlms-course-group">
		<h2 class="dlms-course-group__title"><?php echo esc_html( $dlms_group['label'] ); ?></h2>
	<?php endif; ?>
	<div class="dlms-grid dlms-grid--cols-<?php echo esc_attr( (string) $dlms_cols ); ?>">
		<?php foreach ( $dlms_group['courses'] as $dlms_course ) : ?>
			<article class="dlms-card<?php echo $dlms_course['enrolled'] ? ' is-enrolled' : ''; ?>">
				<?php if ( '' !== $dlms_course['thumbnail_html'] ) : ?>
					<a class="dlms-card__media" href="<?php echo esc_url( $dlms_course['url'] ); ?>" tabindex="-1" aria-hidden="true">
						<?php echo wp_kses_post( $dlms_course['thumbnail_html'] ); ?>
					</a>
				<?php endif; ?>
				<div class="dlms-card__body">
					<h3 class="dlms-card__title"><a href="<?php echo esc_url( $dlms_course['url'] ); ?>"><?php echo esc_html( $dlms_course['title'] ); ?></a></h3>
					<p class="dlms-card__meta">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of lessons. */
								_n( '%d lesson', '%d lessons', (int) $dlms_course['lesson_count'], 'deutschlms' ),
								(int) $dlms_course['lesson_count']
							)
						);
						?>
					</p>
					<?php if ( '' !== $dlms_course['excerpt'] ) : ?>
						<p class="dlms-card__excerpt"><?php echo esc_html( wp_strip_all_tags( $dlms_course['excerpt'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( null !== $dlms_course['percent'] ) : ?>
						<?php
						dlms_template(
							'course/progress-bar.php',
							array(
								'course_title' => $dlms_course['title'],
								'percent'      => $dlms_course['percent'],
								'completed'    => 0,
								'total'        => 0,
								'show_label'   => false,
							)
						);
						?>
						<p class="dlms-card__progress-label">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: percentage. */
									__( '%d%% complete', 'deutschlms' ),
									(int) $dlms_course['percent']
								)
							);
							?>
						</p>
					<?php elseif ( $dlms_course['enrolled'] ) : ?>
						<p class="dlms-card__badge"><?php esc_html_e( 'Enrolled', 'deutschlms' ); ?></p>
					<?php endif; ?>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
	<?php if ( '' !== $dlms_group['label'] ) : ?>
	</section>
	<?php endif; ?>
<?php endforeach; ?>
