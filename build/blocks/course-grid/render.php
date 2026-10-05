<?php
/**
 * Server render for the "Course Grid" block.
 *
 * @package DeutschLMS
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$dlms_html = dlms()->renderer()->course_grid(
	array(
		'columns'       => isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 3,
		'per_page'      => isset( $attributes['perPage'] ) ? (int) $attributes['perPage'] : 12,
		'orderby'       => isset( $attributes['orderBy'] ) ? sanitize_key( $attributes['orderBy'] ) : 'date',
		'show_progress' => ! isset( $attributes['showProgress'] ) || (bool) $attributes['showProgress'],
	)
);

if ( '' === $dlms_html ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( array( 'class' => 'dlms' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	$dlms_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their own output.
);
