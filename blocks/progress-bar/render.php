<?php
/**
 * Server render for the "Course Progress Bar" block.
 *
 * @package DeutschLMS
 *
 * @var array $attributes Block attributes.
 */

use DeutschLMS\Frontend\Context;

defined( 'ABSPATH' ) || exit;

$dlms_course_id = ! empty( $attributes['courseId'] ) ? absint( $attributes['courseId'] ) : Context::current_course_id();
$dlms_html      = dlms()->renderer()->progress_bar( $dlms_course_id, ! isset( $attributes['showLabel'] ) || (bool) $attributes['showLabel'] );

if ( '' === $dlms_html ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( array( 'class' => 'dlms' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	$dlms_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their own output.
);
