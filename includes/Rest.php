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
