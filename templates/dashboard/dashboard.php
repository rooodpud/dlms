<?php
/**
 * Student dashboard: enrolled courses with progress.
 *
 * Override by copying to yourtheme/deutschlms/dashboard/dashboard.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type bool   $logged_in   Whether the visitor is logged in.
 *     @type string $login_url   Login URL returning here.
 *     @type string $archive_url Course archive URL.
 *     @type array  $courses     List of {
 *         id, title, url, thumbnail_html, percent, completed, total, is_complete, continue_url, started,
 *         certificate_url ('' unless earned)
 *     }.
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_heading_id = wp_unique_id( 'dlms-dashboard-' );
?>
<section class="dlms-dashboard" aria-labelledby="<?php echo esc_attr( $dlms_heading_id ); ?>">
	<h2 class="dlms-dashboard__heading" id="<?php echo esc_attr( $dlms_heading_id ); ?>"><?php esc_html_e( 'My courses', 'deutschlms' ); ?></h2>

	<?php if ( ! $args['logged_in'] ) : ?>
		<p><?php esc_html_e( 'Please log in to see your courses.', 'deutschlms' ); ?></p>
		<p><a class="dlms-button" href="<?php echo esc_url( $args['login_url'] ); ?>"><?php esc_html_e( 'Log in', 'deutschlms' ); ?></a></p>

	<?php elseif ( empty( $args['courses'] ) ) : ?>
		<p class="dlms-empty"><?php esc_html_e( 'You are not enrolled in any courses yet.', 'deutschlms' ); ?></p>
		<?php if ( '' !== $args['archive_url'] ) : ?>
			<p><a class="dlms-button" href="<?php echo esc_url( $args['archive_url'] ); ?>"><?php esc_html_e( 'Browse courses', 'deutschlms' ); ?></a></p>
		<?php endif; ?>

	<?php else : ?>
		<ul class="dlms-dashboard__list">
			<?php foreach ( $args['courses'] as $dlms_course ) : ?>
				<li class="dlms-dashboard__course<?php echo $dlms_course['is_complete'] ? ' is-complete' : ''; ?>">
					<?php if ( '' !== $dlms_course['thumbnail_html'] ) : ?>
						<div class="dlms-dashboard__thumb" aria-hidden="true"><?php echo wp_kses_post( $dlms_course['thumbnail_html'] ); ?></div>
					<?php endif; ?>
					<div class="dlms-dashboard__body">
						<h3 class="dlms-dashboard__title"><a href="<?php echo esc_url( $dlms_course['url'] ); ?>"><?php echo esc_html( $dlms_course['title'] ); ?></a></h3>
						<p class="dlms-badge dlms-badge--<?php echo $dlms_course['is_complete'] ? 'complete' : ( $dlms_course['started'] ? 'progress' : 'new' ); ?>">
							<?php
							if ( $dlms_course['is_complete'] ) {
								esc_html_e( 'Completed', 'deutschlms' );
							} elseif ( $dlms_course['started'] ) {
								esc_html_e( 'In progress', 'deutschlms' );
							} else {
								esc_html_e( 'Not started', 'deutschlms' );
							}
							?>
						</p>
						<?php
						dlms_template(
							'course/progress-bar.php',
							array(
								'course_title' => $dlms_course['title'],
								'percent'      => $dlms_course['percent'],
								'completed'    => $dlms_course['completed'],
								'total'        => $dlms_course['total'],
								'show_label'   => true,
							)
						);
						?>
					</div>
					<div class="dlms-dashboard__action">
						<a class="dlms-button<?php echo $dlms_course['is_complete'] ? ' dlms-button--secondary' : ''; ?>" href="<?php echo esc_url( $dlms_course['continue_url'] ); ?>">
							<?php
							if ( $dlms_course['is_complete'] ) {
								esc_html_e( 'Review', 'deutschlms' );
							} elseif ( $dlms_course['started'] ) {
								esc_html_e( 'Continue', 'deutschlms' );
							} else {
								esc_html_e( 'Start', 'deutschlms' );
							}
							?>
							<span class="dlms-sr"><?php echo esc_html( wp_strip_all_tags( $dlms_course['title'] ) ); ?></span>
						</a>
						<?php if ( '' !== $dlms_course['certificate_url'] ) : ?>
							<a class="dlms-link dlms-dashboard__certificate" href="<?php echo esc_url( $dlms_course['certificate_url'] ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Certificate', 'deutschlms' ); ?>
								<span class="dlms-sr"><?php echo esc_html( wp_strip_all_tags( $dlms_course['title'] ) ); ?></span>
							</a>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
