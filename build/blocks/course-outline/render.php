<?php
/**
 * Server render for the "Course Outline" block.
 *
 * @package DeutschLMS
 *
 * @var array $attributes Block attributes.
 */

use DeutschLMS\Frontend\Context;

defined( 'ABSPATH' ) || exit;

$dlms_course_id = ! empty( $attributes['courseId'] ) ? absint( $attributes['courseId'] ) : Context::current_course_id();
$dlms_html      = dlms()->renderer()->course_outline( $dlms_course_id, ! isset( $attributes['showTopics'] ) || (bool) $attributes['showTopics'] );

if ( '' === $dlms_html ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( array( 'class' => 'dlms' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	$dlms_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their own output.
);
