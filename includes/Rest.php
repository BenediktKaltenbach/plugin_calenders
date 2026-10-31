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
	}

	public static function erlaubt( \WP_REST_Request $anfrage ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$schluessel = (string) get_option( Settings::OPTION_SCHLUESSEL, '' );
		return '' !== $schluessel && hash_equals( $schluessel, (string) $anfrage->get_param( 'schluessel' ) );
	}
}
