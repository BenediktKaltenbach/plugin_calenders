<?php
/**
 * Abgleich: holt die iCal-Feeds und spiegelt die Termine in den Inhaltstyp.
 * Das Plugin liest nur, es schreibt nie in Google Kalender.
 */

namespace DTRV\Termine;

final class Sync {

	private const SPERRE        = 'dtrv_termine_sperre';
	public const OPTION_STATUS  = 'dtrv_termine_status';
	public const OPTION_LETZTER = 'dtrv_termine_letzter_abgleich';

	/** WP-Cron als Rückfall: nur abgleichen, wenn der echte Cronjob länger nicht lief. */
	public static function wenn_faellig(): void {
		if ( time() - (int) get_option( self::OPTION_LETZTER, 0 ) >= 50 * MINUTE_IN_SECONDS ) {
			self::alle();
		}
	}

	/**
	 * @return array<string, array<string, mixed>> Status je Kategorie.
	 */
	public static function alle(): array {
		if ( get_transient( self::SPERRE ) ) {
			return array( '_' => array( 'ok' => false, 'meldung' => 'Ein Abgleich läuft bereits.' ) );
		}
		set_transient( self::SPERRE, 1, 5 * MINUTE_IN_SECONDS );

		$status = array();
		foreach ( Plugin::kategorien() as $slug => $label ) {
			$url = Settings::ical_url( $slug );
			if ( '' === $url ) {
				$status[ $slug ] = array(
					'ok'      => false,
					'zeit'    => time(),
					'meldung' => 'Keine iCal-Adresse eingetragen.',
				);
				continue;
			}
			$status[ $slug ] = self::kategorie( $slug, $url );
		}

		update_option( self::OPTION_STATUS, $status, false );
		update_option( self::OPTION_LETZTER, time(), false );
		delete_transient( self::SPERRE );
		return $status;
	}

	private static function kategorie( string $slug, string $url ): array {
		$fehler = static fn( string $meldung ) => array(
			'ok'      => false,
			'zeit'    => time(),
			'meldung' => $meldung,
		);

		$antwort = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $antwort ) ) {
			return $fehler( 'Abruf fehlgeschlagen: ' . $antwort->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $antwort );
		if ( 200 !== $code ) {
			return $fehler( 'Google antwortet mit HTTP ' . $code . '. Ist die geheime iCal-Adresse noch gültig?' );
		}

		$tz = Format::zeitzone();
		try {
			$termine = Feed::termine(
				wp_remote_retrieve_body( $antwort ),
				new \DateTimeImmutable( '-1 year', $tz ),
				new \DateTimeImmutable( '+3 years', $tz )
			);
		} catch ( \Throwable $e ) {
			return $fehler( 'iCal-Daten nicht lesbar: ' . $e->getMessage() );
		}

		$vorhanden = self::vorhandene( $slug );
		$zaehler   = array(
			'neu'       => 0,
			'geaendert' => 0,
			'entfernt'  => 0,
		);
		$gesehen   = array();

		foreach ( $termine as $t ) {
			$gesehen[ $t['schluessel'] ] = true;
			$post_id                     = $vorhanden[ $t['schluessel'] ] ?? 0;
			$hash                        = md5( wp_json_encode( $t ) );

			if ( $post_id && get_post_meta( $post_id, '_dtrv_hash', true ) === $hash && ! get_post_meta( $post_id, '_dtrv_entfernt', true ) ) {
				continue;
			}

			$ergebnis = wp_insert_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_type'    => PostType::NAME,
						'post_status'  => 'publish',
						'post_title'   => $t['titel'],
						'post_content' => $t['text'],
						'meta_input'   => array(
							'_dtrv_schluessel' => $t['schluessel'],
							'_dtrv_kategorie'  => $slug,
							'_dtrv_start'      => $t['start'],
							'_dtrv_ende'       => $t['ende'],
							'_dtrv_ganztaegig' => $t['ganztaegig'] ? 1 : 0,
							'_dtrv_ort'        => $t['ort'],
							'_dtrv_status'     => $t['status'],
							'_dtrv_felder'     => $t['felder'],
							'_dtrv_warnungen'  => $t['warnungen'],
							'_dtrv_hash'       => $hash,
						),
					)
				),
				true
			);
			if ( is_wp_error( $ergebnis ) ) {
				continue;
			}
			delete_post_meta( $ergebnis, '_dtrv_entfernt' );
			++$zaehler[ $post_id ? 'geaendert' : 'neu' ];
		}

		// Im Kalender gelöschte Termine nur markieren (Detailseiten verweisen evtl. darauf).
		// Ein leerer Feed bei vorhandenen Terminen gilt als Störung, dann bleibt der letzte Stand.
		$meldung = '';
		if ( ! $termine && $vorhanden ) {
			$meldung = 'Feed war leer – letzter Stand bleibt erhalten.';
		} else {
			foreach ( array_diff_key( $vorhanden, $gesehen ) as $post_id ) {
				if ( ! get_post_meta( $post_id, '_dtrv_entfernt', true ) ) {
					update_post_meta( $post_id, '_dtrv_entfernt', time() );
					++$zaehler['entfernt'];
				}
			}
		}

		return array(
			'ok'      => true,
			'zeit'    => time(),
			'anzahl'  => count( $termine ),
			'meldung' => $meldung,
		) + $zaehler;
	}

	/** @return array<string, int> Schlüssel → Post-ID */
	private static function vorhandene( string $slug ): array {
		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_dtrv_kategorie', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$map = array();
		foreach ( $ids as $id ) {
			$map[ (string) get_post_meta( $id, '_dtrv_schluessel', true ) ] = $id;
		}
		return $map;
	}
}
