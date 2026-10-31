<?php
/**
 * Koordinaten für Termine und Vorschläge.
 *
 * Reihenfolge (erster Treffer gewinnt):
 *   1. von Hand im Admin gewählt (bleibt beim Abgleich erhalten)
 *   2. Zeile „Koordinaten:“ im Termin – Zahlen oder ein Google-Maps-/OpenStreetMap-Link
 *   3. GEO-Feld des Kalendereintrags
 *   4. Ortsangabe, nachgeschlagen bei OpenStreetMap (Nominatim)
 *
 * Nominatim-Regeln: höchstens 1 Anfrage pro Sekunde, eigener User-Agent, Ergebnisse
 * zwischenspeichern, keine Suche beim Tippen (deshalb Knopf „Ort suchen“).
 */

namespace DTRV\Termine;

final class Geo {

	private const NOMINATIM      = 'https://nominatim.openstreetmap.org/search';
	private const CACHE_TAGE     = 30;
	private const MAX_JE_ABGLEICH = 15;

	private static int $anfragen = 0;

	/**
	 * Koordinaten aus Text: „50.7151, 7.1393“ oder Links von Google Maps/OpenStreetMap.
	 * Ohne WordPress-Abhängigkeit.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	public static function lesen( string $text ): ?array {
		$text   = trim( rawurldecode( $text ) );
		$muster = array(
			'/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',                       // Google: Ort im Pfad
			'/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',                           // Google: Kartenmitte
			'/[?&](?:q|query|ll|sll|center|destination|coordinate)=(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/', // Google/Apple: Parameter
			'/[?&]mlat=(-?\d{1,2}\.\d+)&mlon=(-?\d{1,3}\.\d+)/',              // OSM: Marker
			'/#map=\d+\/(-?\d{1,2}\.\d+)\/(-?\d{1,3}\.\d+)/',                // OSM: Kartenausschnitt
			'/^(-?\d{1,2}\.\d+)\s*[,; ]\s*(-?\d{1,3}\.\d+)$/',                // „50.7151, 7.1393“
		);
		foreach ( $muster as $re ) {
			if ( preg_match( $re, $text, $m ) ) {
				$lat = (float) $m[1];
				$lng = (float) $m[2];
				if ( abs( $lat ) <= 90 && abs( $lng ) <= 180 && ( 0.0 !== $lat || 0.0 !== $lng ) ) {
					return array(
						'lat' => round( $lat, 6 ),
						'lng' => round( $lng, 6 ),
					);
				}
			}
		}
		return null;
	}

	/**
	 * Wie lesen(), versteht aber auch kopierten Teilen-Text („Name, Adresse https://…“)
	 * und löst Kurzlinks von Google Maps und Apple Karten einmal auf.
	 */
	public static function lesen_mit_kurzlink( string $text ): ?array {
		$geo = self::lesen( $text );
		if ( $geo ) {
			return $geo;
		}
		if ( ! preg_match( '#https?://\S+#i', $text, $m ) ) {
			return null;
		}
		$url = rtrim( $m[0], '.,;)' );
		$geo = self::lesen( $url );
		if ( $geo || ! preg_match( '#^https://(maps\.app\.goo\.gl|goo\.gl/maps|maps\.apple(\.com)?/p)/#i', $url ) ) {
			return $geo;
		}
		$cache = 'dtrv_geo_link_' . md5( $url );
		$ziel  = get_transient( $cache );
		if ( false === $ziel ) {
			$antwort = wp_remote_head( $url, array( 'redirection' => 0, 'timeout' => 10 ) );
			$ziel    = is_wp_error( $antwort ) ? '' : (string) wp_remote_retrieve_header( $antwort, 'location' );
			// Fehlschläge nur kurz merken, damit ein späterer Versuch klappen kann.
			set_transient( $cache, $ziel, '' !== $ziel ? self::CACHE_TAGE * DAY_IN_SECONDS : HOUR_IN_SECONDS );
		}
		return '' !== $ziel ? self::lesen( $ziel ) : null;
	}

	/** Liegt die Suche schon im Zwischenspeicher? (zählt dann nicht gegen das Limit) */
	private static function im_cache( string $suche, int $anzahl ): bool {
		$suche = trim( preg_replace( '/\s+/', ' ', $suche ) );
		return is_array( get_transient( 'dtrv_geo_' . md5( mb_strtolower( $suche ) . '|' . $anzahl ) ) );
	}

	/**
	 * Ortssuche bei OpenStreetMap, zwischengespeichert.
	 *
	 * @return array<int, array{lat: float, lng: float, name: string}>|\WP_Error
	 */
	public static function suchen( string $suche, int $anzahl = 5 ) {
		$suche = trim( preg_replace( '/\s+/', ' ', $suche ) );
		if ( mb_strlen( $suche ) < 3 ) {
			return array();
		}
		$cache    = 'dtrv_geo_' . md5( mb_strtolower( $suche ) . '|' . $anzahl );
		$treffer  = get_transient( $cache );
		if ( is_array( $treffer ) ) {
			return $treffer;
		}

		// Höchstens 1 Anfrage pro Sekunde (über alle Besucher und den Abgleich hinweg).
		$letzte = (float) get_transient( 'dtrv_geo_letzte' );
		$warten = $letzte + 1.1 - microtime( true );
		if ( $warten > 0 ) {
			usleep( (int) ( min( $warten, 2 ) * 1000000 ) );
		}
		set_transient( 'dtrv_geo_letzte', microtime( true ), MINUTE_IN_SECONDS );

		$antwort = wp_remote_get(
			add_query_arg(
				array(
					'q'               => $suche,
					'format'          => 'jsonv2',
					'limit'           => $anzahl,
					'accept-language' => 'de',
				),
				self::NOMINATIM
			),
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'DTRV-Termine/' . VERSION . ' (' . home_url( '/' ) . ')' ),
			)
		);
		if ( is_wp_error( $antwort ) || 200 !== wp_remote_retrieve_response_code( $antwort ) ) {
			return new \WP_Error( 'dtrv_geo', 'OpenStreetMap ist gerade nicht erreichbar.' );
		}

		$treffer = array();
		foreach ( (array) json_decode( wp_remote_retrieve_body( $antwort ), true ) as $t ) {
			if ( isset( $t['lat'], $t['lon'] ) ) {
				$treffer[] = array(
					'lat'  => round( (float) $t['lat'], 6 ),
					'lng'  => round( (float) $t['lon'], 6 ),
					'name' => (string) ( $t['display_name'] ?? $suche ),
				);
			}
		}
		set_transient( $cache, $treffer, self::CACHE_TAGE * DAY_IN_SECONDS );
		return $treffer;
	}

	/** Beim Abgleich: Koordinaten eines Termins bestimmen und speichern. */
	public static function pruefen( int $termin_id ): void {
		if ( ! $termin_id ) {
			return;
		}
		$manuell = get_post_meta( $termin_id, '_dtrv_geo_manuell', true );
		if ( is_array( $manuell ) && isset( $manuell['lat'], $manuell['lng'] ) ) {
			self::speichern( $termin_id, $manuell + array( 'quelle' => 'manuell' ) );
			return;
		}

		$felder = (array) get_post_meta( $termin_id, '_dtrv_felder', true );
		$fehler = '';
		if ( ! empty( $felder['koordinaten'] ) ) {
			$geo = self::lesen_mit_kurzlink( $felder['koordinaten'] );
			if ( $geo ) {
				self::speichern( $termin_id, $geo + array( 'quelle' => 'zeile' ) );
				return;
			}
			$fehler = 'Zeile „Koordinaten:“ nicht lesbar – bitte Zahlen wie „50.7151, 7.1393“ oder einen Karten-Link angeben.';
			update_post_meta( $termin_id, '_dtrv_geo_fehler', $fehler );
		}

		$ics = get_post_meta( $termin_id, '_dtrv_geo_ics', true );
		if ( is_array( $ics ) && isset( $ics['lat'], $ics['lng'] ) ) {
			self::speichern( $termin_id, $ics + array( 'quelle' => 'kalender' ), $fehler );
			return;
		}

		// OpenStreetMap nur für kommende Termine und begrenzt je Abgleich (Nominatim-Regeln).
		$ort = trim( (string) get_post_meta( $termin_id, '_dtrv_ort', true ) );
		if ( '' === $ort || (int) get_post_meta( $termin_id, '_dtrv_ende', true ) < time() ) {
			return;
		}
		$alt = get_post_meta( $termin_id, '_dtrv_geo', true );
		if ( is_array( $alt ) && 'osm' === ( $alt['quelle'] ?? '' ) && ( $alt['suche'] ?? '' ) === $ort ) {
			return;
		}
		if ( ! self::im_cache( $ort, 1 ) ) {
			if ( self::$anfragen >= self::MAX_JE_ABGLEICH ) {
				return;
			}
			++self::$anfragen;
		}
		$treffer = self::suchen( $ort, 1 );
		if ( is_wp_error( $treffer ) ) {
			return;
		}
		if ( ! $treffer ) {
			delete_post_meta( $termin_id, '_dtrv_geo' );
			update_post_meta( $termin_id, '_dtrv_geo_fehler', trim( $fehler . ' Ort „' . $ort . '“ bei OpenStreetMap nicht gefunden – im Termin über „Ort suchen“ wählen oder eine Zeile „Koordinaten:“ ergänzen.' ) );
			return;
		}
		self::speichern(
			$termin_id,
			array(
				'lat'    => $treffer[0]['lat'],
				'lng'    => $treffer[0]['lng'],
				'name'   => $treffer[0]['name'],
				'quelle' => 'osm',
				'suche'  => $ort,
			),
			$fehler
		);
	}

	/** Speichert die Koordinaten; ein Fehler aus der „Koordinaten:“-Zeile bleibt sichtbar. */
	private static function speichern( int $termin_id, array $geo, string $fehler = '' ): void {
		update_post_meta( $termin_id, '_dtrv_geo', $geo );
		if ( '' === $fehler ) {
			delete_post_meta( $termin_id, '_dtrv_geo_fehler' );
		}
	}

	/** @return array{lat: float, lng: float, quelle: string, name?: string}|null */
	public static function von_termin( int $termin_id ): ?array {
		$geo = get_post_meta( $termin_id, '_dtrv_geo', true );
		return is_array( $geo ) && isset( $geo['lat'], $geo['lng'] ) ? $geo : null;
	}

	public static function osm_link( float $lat, float $lng ): string {
		return sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=15/%1$s/%2$s', $lat, $lng );
	}
}
