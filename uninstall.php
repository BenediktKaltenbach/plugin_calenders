<?php
/**
 * Beim Löschen des Plugins: Einstellungen und gespiegelte Termine entfernen.
 * Die Termine selbst liegen weiter in Google Kalender.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'dtrv_termine_einstellungen', 'dtrv_termine_cron_schluessel', 'dtrv_termine_status', 'dtrv_termine_letzter_abgleich', 'dtrv_termine_leer' ) as $option ) {
	delete_option( $option );
}

$ids = get_posts(
	array(
		'post_type'      => 'dtrv_termin',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
