<?php
/**
 * Server render for the "Mark Complete Button" block.
 *
 * @package DeutschLMS
 *
 * @var array $attributes Block attributes.
 */

use DeutschLMS\Frontend\Context;

defined( 'ABSPATH' ) || exit;

$dlms_html = dlms()->renderer()->mark_complete( Context::current_step_id() );

if ( '' === $dlms_html ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( array( 'class' => 'dlms' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	$dlms_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their own output.
);
