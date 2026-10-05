<?php
/**
 * Server render for the "Student Dashboard" block.
 *
 * @package DeutschLMS
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$dlms_html = dlms()->renderer()->student_dashboard(
	array( 'show_completed' => ! isset( $attributes['showCompleted'] ) || (bool) $attributes['showCompleted'] )
);

if ( '' === $dlms_html ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( array( 'class' => 'dlms' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	$dlms_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their own output.
);
