<?php
/**
 * Beim Löschen des Plugins: Einstellungen und gespiegelte Termine entfernen.
 * Die Termine selbst liegen weiter in Google Kalender.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'dtrv_termine_einstellungen', 'dtrv_termine_cron_schluessel', 'dtrv_termine_status', 'dtrv_termine_letzter_abgleich', 'dtrv_termine_leer', 'dtrv_termine_spam', 'dtrv_termine_schema', 'dtrv_termine_sperre' ) as $option ) {
	delete_option( $option );
}

$ids = get_posts(
	array(
		'post_type'      => array( 'dtrv_termin', 'dtrv_vorschlag' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $ids as $id ) {
	// Nur vom Plugin heruntergeladene Bilder (Kalender) oder Bilder aus Vorschlägen löschen,
	// keine vorhandenen Mediathek-Bilder, die über „Bild:“ verlinkt waren.
	$bild = (int) get_post_meta( $id, '_dtrv_bild', true );
	if ( $bild && ( get_post_meta( $bild, '_dtrv_bild_quelle', true ) || 'dtrv_vorschlag' === get_post_type( $id ) ) ) {
		wp_delete_attachment( $bild, true );
	}
	wp_delete_post( $id, true );
}
