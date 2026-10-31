<?php
/**
 * Ausgabe des Blocks „DTRV-Terminvorschlag“.
 */

defined( 'ABSPATH' ) || exit;

printf(
	'<div %s>%s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
	\DTRV\Termine\Formular::html() // phpcs:ignore WordPress.Security.EscapeOutput
);
