<?php
/**
 * Beitragsbilder aus dem Kalender.
 *
 * Quelle: Zeile „Bild: <URL>“ im Termin, sonst das erste Bild unter den Anhängen
 * (bei Google ein Drive-Link). Drive-Dateien müssen per Link öffentlich sein, z. B.
 * weil sie in einem öffentlich freigegebenen Ordner liegen; das Plugin nutzt keine
 * Google-API. Jedes Bild wird nur einmal in die Mediathek geladen.
 *
 * Gesetzt wird es als Beitragsbild von Seite und Beitrag des Termins, aber nur wenn
 * dort kein Bild, das Standardbild der Kategorie oder ein früheres Kalenderbild ist –
 * ein von Hand gewähltes Bild wird nie überschrieben.
 */

namespace DTRV\Termine;

final class Bilder {

	private const MAX_MB          = 15;
	private const NEUER_VERSUCH   = 6 * 3600;

	/** Drive-Datei-ID aus den üblichen Link-Formen, sonst ''. Ohne WordPress-Abhängigkeit. */
	public static function drive_id( string $url ): string {
		if ( ! preg_match( '#^https?://(drive|docs)\.google\.com/#i', $url ) ) {
			return '';
		}
		if ( preg_match( '#/file/d/([A-Za-z0-9_-]{10,})#', $url, $m ) || preg_match( '#[?&]id=([A-Za-z0-9_-]{10,})#', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** Adresse zum Herunterladen (Drive-Links werden zum direkten Download). */
	public static function download_url( string $url ): string {
		$id = self::drive_id( $url );
		return '' !== $id ? 'https://drive.google.com/uc?export=download&id=' . $id : $url;
	}

	/** Bildquelle eines Termins: „Bild:“-Zeile vor Kalender-Anhang. */
	public static function quelle( int $termin_id ): string {
		$felder = (array) get_post_meta( $termin_id, '_dtrv_felder', true );
		if ( ! empty( $felder['bild'] ) ) {
			return (string) $felder['bild'];
		}
		return (string) get_post_meta( $termin_id, '_dtrv_anhang', true );
	}

	/** Beim Abgleich: Bild laden (falls nötig) und auf Seite/Beitrag setzen. */
	public static function pruefen( int $termin_id ): void {
		if ( ! $termin_id ) {
			return;
		}
		// Vergangene Termine ohne Seite/Beitrag brauchen kein Bild.
		$vorbei = (int) get_post_meta( $termin_id, '_dtrv_ende', true ) < time();
		if ( $vorbei && ! Detailseiten::id( $termin_id, 'seite' ) && ! Detailseiten::id( $termin_id, 'beitrag' ) ) {
			return;
		}
		$url = self::quelle( $termin_id );
		if ( '' === $url ) {
			delete_post_meta( $termin_id, '_dtrv_bild' );
			delete_post_meta( $termin_id, '_dtrv_bild_fehler' );
			return;
		}

		$anhang = self::anhang( $termin_id, $url );
		if ( ! $anhang ) {
			return;
		}
		update_post_meta( $termin_id, '_dtrv_bild', $anhang );
		delete_post_meta( $termin_id, '_dtrv_bild_fehler' );

		foreach ( array_keys( Detailseiten::ARTEN ) as $art ) {
			$post = Detailseiten::id( $termin_id, $art );
			if ( $post && get_post( $post ) ) {
				self::setzen( $post, $termin_id, $anhang );
			}
		}
	}

	/** Setzt das Kalenderbild als Beitragsbild, wenn kein eigenes Bild gewählt wurde. */
	public static function setzen( int $post_id, int $termin_id, int $anhang ): void {
		$aktuell  = (int) get_post_thumbnail_id( $post_id );
		$kat      = (string) get_post_meta( $termin_id, '_dtrv_kategorie', true );
		$standard = (int) ( Settings::einstellungen()['standardbild'][ $kat ] ?? 0 );
		$frueher  = (int) get_post_meta( $post_id, '_dtrv_kalenderbild', true );
		if ( $aktuell && $aktuell !== $standard && $aktuell !== $frueher ) {
			return;
		}
		set_post_thumbnail( $post_id, $anhang );
		update_post_meta( $post_id, '_dtrv_kalenderbild', $anhang );
	}

	/**
	 * Mediathek-ID zum Bild, lädt es bei Bedarf herunter.
	 *
	 * @return int 0 bei Fehler (Meldung in _dtrv_bild_fehler).
	 */
	private static function anhang( int $termin_id, string $url ): int {
		// Bild aus der eigenen Mediathek: direkt verwenden.
		$lokal = attachment_url_to_postid( $url );
		if ( $lokal ) {
			return $lokal;
		}

		$schluessel = self::drive_id( $url );
		$schluessel = '' !== $schluessel ? 'drive:' . $schluessel : 'url:' . md5( $url );

		$vorhanden = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_dtrv_bild_quelle', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $schluessel, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $vorhanden ) {
			return (int) $vorhanden[0];
		}

		// Fehlgeschlagene Downloads nicht stündlich wiederholen.
		$versuch = (array) get_post_meta( $termin_id, '_dtrv_bild_versuch', true );
		if ( ( $versuch['quelle'] ?? '' ) === $schluessel && time() - (int) ( $versuch['zeit'] ?? 0 ) < self::NEUER_VERSUCH ) {
			return 0;
		}
		update_post_meta(
			$termin_id,
			'_dtrv_bild_versuch',
			array(
				'quelle' => $schluessel,
				'zeit'   => time(),
			)
		);

		$ergebnis = self::herunterladen( $termin_id, $url, $schluessel );
		if ( is_wp_error( $ergebnis ) ) {
			update_post_meta( $termin_id, '_dtrv_bild_fehler', $ergebnis->get_error_message() );
			return 0;
		}
		return $ergebnis;
	}

	/** @return int|\WP_Error ID in der Mediathek. */
	private static function herunterladen( int $termin_id, string $url, string $schluessel ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$datei = download_url( self::download_url( $url ), 30 );
		if ( is_wp_error( $datei ) ) {
			return new \WP_Error( 'dtrv', 'Bild konnte nicht geladen werden: ' . $datei->get_error_message() );
		}

		$typ = wp_get_image_mime( $datei );
		$ext = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		)[ $typ ] ?? '';
		if ( '' === $ext ) {
			wp_delete_file( $datei );
			return new \WP_Error(
				'dtrv',
				self::drive_id( $url )
					? 'Bild nicht öffentlich freigegeben (Drive-Datei muss „Jeder mit dem Link“ sein, z. B. im freigegebenen Bilder-Ordner).'
					: 'Unter der Adresse liegt kein JPG-, PNG- oder WebP-Bild.'
			);
		}
		if ( filesize( $datei ) > self::MAX_MB * MB_IN_BYTES ) {
			wp_delete_file( $datei );
			return new \WP_Error( 'dtrv', 'Bild ist größer als ' . self::MAX_MB . ' MB.' );
		}

		$titel  = get_the_title( $termin_id );
		$anhang = media_handle_sideload(
			array(
				'name'     => sanitize_title( $titel ) . '.' . $ext,
				'tmp_name' => $datei,
			),
			0,
			$titel
		);
		if ( is_wp_error( $anhang ) ) {
			wp_delete_file( $datei );
			return new \WP_Error( 'dtrv', 'Bild konnte nicht gespeichert werden: ' . $anhang->get_error_message() );
		}
		update_post_meta( $anhang, '_dtrv_bild_quelle', $schluessel );
		return $anhang;
	}
}
