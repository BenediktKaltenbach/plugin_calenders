<?php
/**
 * REST-Endpunkt für den echten Cronjob:
 * GET /wp-json/dtrv-termine/v1/abgleich?schluessel=…
 */

namespace DTRV\Termine;

final class Rest {

	public static function routen(): void {
		register_rest_route(
			'dtrv-termine/v1',
			'/abgleich',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => static fn() => rest_ensure_response( Sync::alle() ),
				'permission_callback' => array( self::class, 'erlaubt' ),
			)
		);

		// Termine mit Status der Detailseiten: für die Auswahl in der Infobox und das Briefing.
		register_rest_route(
			'dtrv-termine/v1',
			'/termine',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'termine' ),
				'permission_callback' => static fn( \WP_REST_Request $anfrage ) => current_user_can( 'edit_posts' ) || self::erlaubt( $anfrage ),
			)
		);

		// Offene Vorschläge für das wöchentliche Briefing (ohne Kontaktdaten).
		register_rest_route(
			'dtrv-termine/v1',
			'/vorschlaege',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'vorschlaege' ),
				'permission_callback' => array( self::class, 'erlaubt' ),
			)
		);
	}

	public static function termine(): \WP_REST_Response {
		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'meta_key'       => '_dtrv_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
			)
		);
		$liste = array();
		foreach ( $ids as $id ) {
			$d       = PostType::daten( $id );
			$seite   = Detailseiten::id( $id, 'seite' );
			$liste[] = array(
				'id'        => $id,
				'titel'     => get_the_title( $id ),
				'datum'     => Format::zeitraum( $d['start'], $d['ende'], $d['ganztaegig'] ),
				'start'     => gmdate( 'c', $d['start'] ),
				'kategorie' => Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'],
				'ort'       => $d['ort'],
				'status'    => $d['status'],
				'entfernt'  => (bool) $d['entfernt'],
				'anmeldung' => $d['felder']['anmeldung'] ?? '',
				'seite'     => Detailseiten::status( $id, 'seite' ),
				'seite_url' => $seite ? (string) get_permalink( $seite ) : '',
				'beitrag'   => Detailseiten::status( $id, 'beitrag' ),
				'bild'      => Detailseiten::bild_status( $id ),
				'warnungen' => $d['warnungen'],
				'bild_fehler' => (string) get_post_meta( $id, '_dtrv_bild_fehler', true ),
			);
		}
		return rest_ensure_response( $liste );
	}

	public static function vorschlaege(): \WP_REST_Response {
		$ids = get_posts(
			array(
				'post_type'      => Vorschlaege::NAME,
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_dtrv_vstatus',
						'value'   => array( 'neu', 'freigegeben' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$liste = array();
		foreach ( $ids as $id ) {
			$d = Vorschlaege::daten( $id );
			unset( $d['email'], $d['name'] );
			$d['bild']        = $d['bild'] ? wp_get_attachment_url( $d['bild'] ) : '';
			$d['titel']       = get_the_title( $id );
			$d['eingang']     = get_post_time( 'c', true, $id );
			$d['google_link'] = Vorschlaege::google_link( $id );
			$d['admin_link']  = admin_url( 'post.php?post=' . $id . '&action=edit' );
			$liste[]          = array( 'id' => $id ) + $d;
		}
		return rest_ensure_response( $liste );
	}

	public static function erlaubt( \WP_REST_Request $anfrage ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$schluessel = (string) get_option( Settings::OPTION_SCHLUESSEL, '' );
		return '' !== $schluessel && hash_equals( $schluessel, (string) $anfrage->get_param( 'schluessel' ) );
	}
}
