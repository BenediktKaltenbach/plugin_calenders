<?php
/**
 * Ausgabe des Blocks „Termin-Infobox“.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

printf(
	'<div %s>%s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
	\DTRV\Termine\Renderer::infobox( (int) ( $attributes['terminId'] ?? 0 ) ) // phpcs:ignore WordPress.Security.EscapeOutput
);
