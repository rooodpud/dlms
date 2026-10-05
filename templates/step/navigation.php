<?php
/**
 * Previous / next navigation within a course.
 *
 * Override by copying to yourtheme/deutschlms/step/navigation.php.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type string     $course_title Course title.
 *     @type string     $course_url   Course URL.
 *     @type array|null $previous     { title, url ('' if locked), locked }.
 *     @type array|null $next         { title, url ('' if locked), locked }.
 * }
 */

defined( 'ABSPATH' ) || exit;

$dlms_links = array(
	'prev' => array( $args['previous'], __( 'Previous', 'deutschlms' ) ),
	'next' => array( $args['next'], __( 'Next', 'deutschlms' ) ),
);
?>
<nav class="dlms-step-nav" aria-label="<?php esc_attr_e( 'Course navigation', 'deutschlms' ); ?>">
	<p class="dlms-step-nav__course">
		<a href="<?php echo esc_url( $args['course_url'] ); ?>">
			<span class="dlms-arrow dlms-arrow--back" aria-hidden="true"></span>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: course title. */
					__( 'Back to %s', 'deutschlms' ),
					wp_strip_all_tags( $args['course_title'] )
				)
			);
			?>
		</a>
	</p>
	<div class="dlms-step-nav__links">
		<?php foreach ( $dlms_links as $dlms_rel => $dlms_link ) : ?>
			<?php
			list( $dlms_target, $dlms_label ) = $dlms_link;
			if ( null === $dlms_target ) {
				echo '<span class="dlms-step-nav__spacer"></span>';
				continue;
			}
			?>
			<?php if ( '' !== $dlms_target['url'] ) : ?>
				<a class="dlms-step-nav__link dlms-step-nav__link--<?php echo esc_attr( $dlms_rel ); ?>" href="<?php echo esc_url( $dlms_target['url'] ); ?>" rel="<?php echo esc_attr( $dlms_rel ); ?>">
					<span class="dlms-step-nav__dir"><?php echo esc_html( $dlms_label ); ?></span>
					<span class="dlms-step-nav__title"><?php echo esc_html( $dlms_target['title'] ); ?></span>
				</a>
			<?php else : ?>
				<span class="dlms-step-nav__link dlms-step-nav__link--<?php echo esc_attr( $dlms_rel ); ?> is-locked">
					<span class="dlms-step-nav__dir"><?php echo esc_html( $dlms_label ); ?></span>
					<span class="dlms-step-nav__title"><?php echo esc_html( $dlms_target['title'] ); ?></span>
					<span class="dlms-sr"><?php esc_html_e( '(Locked)', 'deutschlms' ); ?></span>
				</span>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</nav>
