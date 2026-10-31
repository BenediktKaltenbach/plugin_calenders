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
	private const OPTION_LEER   = 'dtrv_termine_leer';

	/**
	 * Alle gespiegelten Termine: Schlüssel → [id, kategorie].
	 * Kategorienübergreifend, damit ein in Google verschobener Termin
	 * (gleiche UID, anderer Kalender) nur die Kategorie wechselt.
	 *
	 * @var array<string, array{id: int, kategorie: string}>
	 */
	private static array $index = array();

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

		self::$index = self::index();
		$status      = array();
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
			$feed = Feed::lesen(
				wp_remote_retrieve_body( $antwort ),
				new \DateTimeImmutable( '-1 year', $tz ),
				new \DateTimeImmutable( '+3 years', $tz )
			);
		} catch ( \Throwable $e ) {
			return $fehler( 'iCal-Daten nicht lesbar: ' . $e->getMessage() );
		}
		$termine = $feed['termine'];

		$zaehler = array(
			'neu'       => 0,
			'geaendert' => 0,
			'entfernt'  => 0,
		);
		$gesehen = array();

		foreach ( $termine as $t ) {
			$gesehen[ $t['schluessel'] ] = true;
			$eintrag                     = self::$index[ $t['schluessel'] ] ?? null;
			$post_id                     = $eintrag['id'] ?? 0;
			$hash                        = md5( wp_json_encode( $t ) );

			if ( $post_id && $eintrag['kategorie'] === $slug && get_post_meta( $post_id, '_dtrv_hash', true ) === $hash && ! get_post_meta( $post_id, '_dtrv_entfernt', true ) ) {
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
			self::$index[ $t['schluessel'] ] = array(
				'id'        => $ergebnis,
				'kategorie' => $slug,
			);
			++$zaehler[ $post_id ? 'geaendert' : 'neu' ];
		}

		// Im Kalender gelöschte Termine nur markieren (Detailseiten verweisen evtl. darauf).
		$weg = array();
		foreach ( self::$index as $schluessel => $eintrag ) {
			if ( $eintrag['kategorie'] === $slug && ! isset( $gesehen[ $schluessel ] ) ) {
				$weg[] = $eintrag['id'];
			}
		}

		// Ein leerer Feed kann eine Google-Störung sein: erst beim zweiten leeren Abruf in Folge löschen.
		$meldung = '';
		$leer    = (array) get_option( self::OPTION_LEER, array() );
		if ( ! $termine && $weg && empty( $leer[ $slug ] ) ) {
			$leer[ $slug ] = 1;
			$meldung       = sprintf( 'Kalender ist leer. Ist das beim nächsten Abgleich noch so, werden %d Termine als gelöscht markiert.', count( $weg ) );
			$weg           = array();
		} else {
			unset( $leer[ $slug ] );
		}
		update_option( self::OPTION_LEER, $leer, false );

		foreach ( $weg as $post_id ) {
			if ( ! get_post_meta( $post_id, '_dtrv_entfernt', true ) ) {
				update_post_meta( $post_id, '_dtrv_entfernt', time() );
				++$zaehler['entfernt'];
			}
		}

		return array(
			'ok'      => true,
			'zeit'    => time(),
			'name'    => $feed['name'],
			'anzahl'  => count( $termine ),
			'meldung' => $meldung,
		) + $zaehler;
	}

	/**
	 * Baut den Index aller gespiegelten Termine und räumt Dubletten
	 * (gleicher Schlüssel mehrfach) auf. Behalten wird ein Eintrag mit
	 * Detailseite, sonst der neueste.
	 *
	 * @return array<string, array{id: int, kategorie: string}>
	 */
	private static function index(): array {
		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		update_meta_cache( 'post', $ids );

		$index = array();
		foreach ( $ids as $id ) {
			$schluessel = (string) get_post_meta( $id, '_dtrv_schluessel', true );
			$eintrag    = array(
				'id'        => $id,
				'kategorie' => (string) get_post_meta( $id, '_dtrv_kategorie', true ),
			);
			if ( ! isset( $index[ $schluessel ] ) ) {
				$index[ $schluessel ] = $eintrag;
				continue;
			}
			// Dublette: Eintrag mit Detailseite gewinnt, der andere wird gelöscht.
			$behalten = $index[ $schluessel ];
			$weg      = $eintrag;
			if ( ! get_post_meta( $behalten['id'], '_dtrv_detail_post', true ) && get_post_meta( $id, '_dtrv_detail_post', true ) ) {
				[ $behalten, $weg ] = array( $eintrag, $behalten );
			}
			$index[ $schluessel ] = $behalten;
			wp_delete_post( $weg['id'], true );
		}
		return $index;
	}
}
