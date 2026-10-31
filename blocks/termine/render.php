<?php
/**
 * Ausgabe des Blocks „DTRV-Termine“.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

printf(
	'<div %s>%s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
	\DTRV\Termine\Renderer::liste( $attributes ) // phpcs:ignore WordPress.Security.EscapeOutput
);
