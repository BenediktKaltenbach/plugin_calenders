<?php
/**
 * Schreibzugriff auf Google Kalender über ein Dienstkonto (service account).
 *
 * Das Dienstkonto gehört zum Google-Cloud-Projekt des Verbandskontos und hat nur Zugriff
 * auf die Kalender, die mit ihm geteilt wurden („Änderungen an Terminen vornehmen“).
 * Der Schlüssel steht in wp-config.php, nie in der Datenbank oder im Repository:
 *
 *   define( 'DTRV_TERMINE_GOOGLE_KEY_FILE', '/pfad/außerhalb/des/webroots/dtrv-google.json' );
 *
 * Ohne Schlüssel bleibt das Plugin rein lesend (Knopf „Eintragen“ öffnet Google vorausgefüllt).
 */

namespace DTRV\Termine;

final class Google {

	private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
	private const TOKEN = 'https://oauth2.googleapis.com/token';
	private const API   = 'https://www.googleapis.com/calendar/v3/calendars/';

	/** @return array{client_email: string, private_key: string}|null */
	private static function schluessel(): ?array {
		return self::lesen()['schluessel'];
	}

	/**
	 * Liest den Schlüssel aus wp-config.php und sagt, was nicht stimmt.
	 *
	 * @return array{schluessel: array|null, problem: string}
	 */
	public static function lesen(): array {
		$ergebnis = static fn( ?array $k, string $problem ) => array(
			'schluessel' => $k,
			'problem'    => $problem,
		);
		if ( defined( 'DTRV_TERMINE_GOOGLE_KEY_FILE' ) ) {
			if ( ! is_readable( DTRV_TERMINE_GOOGLE_KEY_FILE ) ) {
				return $ergebnis( null, 'DTRV_TERMINE_GOOGLE_KEY_FILE ist gesetzt, aber die Datei ist nicht lesbar: ' . DTRV_TERMINE_GOOGLE_KEY_FILE );
			}
			$json = (string) file_get_contents( DTRV_TERMINE_GOOGLE_KEY_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( defined( 'DTRV_TERMINE_GOOGLE_KEY' ) ) {
			$json = (string) DTRV_TERMINE_GOOGLE_KEY;
		} else {
			return $ergebnis( null, '' );
		}
		return self::pruefen( $json );
	}

	/**
	 * Prüft den JSON-Inhalt eines Schlüssels. Repariert Zeilenumbrüche im privaten
	 * Schlüssel, die beim Kopieren aus „\n“ entstanden sind. Ohne WordPress-Abhängigkeit.
	 *
	 * @return array{schluessel: array|null, problem: string}
	 */
	public static function pruefen( string $json ): array {
		$json = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $json ) );
		$k    = json_decode( $json, true );
		if ( ! is_array( $k ) ) {
			$repariert = preg_replace_callback(
				'/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----\s*/s',
				static fn( $m ) => str_replace( array( "\r\n", "\n", "\r" ), '\\n', rtrim( $m[0] ) ) . '\\n',
				$json
			);
			$k = json_decode( (string) $repariert, true );
		}
		if ( ! is_array( $k ) ) {
			return array(
				'schluessel' => null,
				'problem'    => 'Der Schlüssel ist kein gültiges JSON (' . json_last_error_msg() . '). Bitte den Inhalt der .json-Datei unverändert einfügen.',
			);
		}
		foreach ( array( 'client_email', 'private_key' ) as $feld ) {
			if ( empty( $k[ $feld ] ) ) {
				return array(
					'schluessel' => null,
					'problem'    => 'Im Schlüssel fehlt „' . $feld . '“ – ist es die JSON-Datei eines Dienstkontos?',
				);
			}
		}
		if ( ! openssl_pkey_get_private( $k['private_key'] ) ) {
			return array(
				'schluessel' => null,
				'problem'    => 'Der private Schlüssel („private_key“) ist beschädigt. Bitte die .json-Datei erneut unverändert einfügen.',
			);
		}
		return array(
			'schluessel' => $k,
			'problem'    => '',
		);
	}

	public static function konfiguriert(): bool {
		return null !== self::schluessel();
	}

	/** E-Mail-Adresse des Dienstkontos (zum Teilen der Kalender). */
	public static function konto(): string {
		$k = self::schluessel();
		return $k ? (string) $k['client_email'] : '';
	}

	/** Kalender-ID aus der geheimen iCal-Adresse (…/ical/<ID>/private-…/basic.ics). */
	public static function kalender_id( string $kategorie ): string {
		return preg_match( '#/ical/([^/]+)/#', Settings::ical_url( $kategorie ), $m ) ? rawurldecode( $m[1] ) : '';
	}

	private static function b64( string $daten ): string {
		return rtrim( strtr( base64_encode( $daten ), '+/', '-_' ), '=' );
	}

	/** Zugriffstoken (1 Stunde gültig, zwischengespeichert). @return string|\WP_Error */
	private static function token() {
		$token = get_transient( 'dtrv_google_token' );
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}
		$k = self::schluessel();
		if ( ! $k ) {
			return new \WP_Error( 'dtrv_google', 'Kein Google-Schlüssel eingerichtet.' );
		}
		$jetzt = time();
		$jwt   = self::b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.'
			. self::b64(
				wp_json_encode(
					array(
						'iss'   => $k['client_email'],
						'scope' => self::SCOPE,
						'aud'   => self::TOKEN,
						'iat'   => $jetzt,
						'exp'   => $jetzt + 3600,
					)
				)
			);
		if ( ! openssl_sign( $jwt, $signatur, $k['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( 'dtrv_google', 'Google-Schlüssel ist ungültig (Signatur fehlgeschlagen).' );
		}
		$antwort = wp_remote_post(
			self::TOKEN,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt . '.' . self::b64( $signatur ),
				),
			)
		);
		$daten = is_wp_error( $antwort ) ? array() : (array) json_decode( wp_remote_retrieve_body( $antwort ), true );
		if ( empty( $daten['access_token'] ) ) {
			$grund = is_wp_error( $antwort ) ? $antwort->get_error_message() : ( $daten['error_description'] ?? $daten['error'] ?? 'unbekannt' );
			return new \WP_Error( 'dtrv_google', 'Anmeldung bei Google fehlgeschlagen: ' . $grund );
		}
		set_transient( 'dtrv_google_token', $daten['access_token'], max( 60, (int) ( $daten['expires_in'] ?? 3600 ) - 300 ) );
		return $daten['access_token'];
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function anfrage( string $methode, string $pfad, ?array $body = null ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$antwort = wp_remote_request(
			self::API . $pfad,
			array(
				'method'  => $methode,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => null !== $body ? wp_json_encode( $body ) : null,
			)
		);
		if ( is_wp_error( $antwort ) ) {
			return new \WP_Error( 'dtrv_google', 'Google nicht erreichbar: ' . $antwort->get_error_message() );
		}
		$code  = wp_remote_retrieve_response_code( $antwort );
		$daten = (array) json_decode( wp_remote_retrieve_body( $antwort ), true );
		if ( $code >= 300 ) {
			$meldung = $daten['error']['message'] ?? ( 'HTTP ' . $code );
			if ( 403 === $code || 404 === $code ) {
				$meldung .= ' – ist der Kalender mit ' . self::konto() . ' geteilt („Änderungen an Terminen vornehmen“)?';
			}
			return new \WP_Error( 'dtrv_google', 'Google meldet: ' . $meldung );
		}
		return $daten;
	}

	/**
	 * Legt einen Termin im Kalender der Kategorie an.
	 *
	 * @param array{titel: string, ort: string, text: string, start: \DateTimeImmutable, ende: \DateTimeImmutable, ganztaegig: bool} $t
	 * @return array{id: string, ical_uid: string, link: string}|\WP_Error
	 */
	public static function termin_anlegen( string $kategorie, array $t ) {
		$kalender = self::kalender_id( $kategorie );
		if ( '' === $kalender ) {
			return new \WP_Error( 'dtrv_google', 'Für diese Kategorie ist keine iCal-Adresse eingetragen.' );
		}
		$zeit = static fn( \DateTimeImmutable $d ) => $t['ganztaegig']
			? array( 'date' => $d->format( 'Y-m-d' ) )
			: array(
				'dateTime' => $d->format( 'Y-m-d\TH:i:s' ),
				'timeZone' => Format::ZEITZONE,
			);
		$daten = self::anfrage(
			'POST',
			rawurlencode( $kalender ) . '/events',
			array(
				'summary'     => $t['titel'],
				'location'    => $t['ort'],
				'description' => $t['text'],
				'start'       => $zeit( $t['start'] ),
				'end'         => $zeit( $t['ende'] ),
			)
		);
		if ( is_wp_error( $daten ) ) {
			return $daten;
		}
		return array(
			'id'       => (string) ( $daten['id'] ?? '' ),
			'ical_uid' => (string) ( $daten['iCalUID'] ?? '' ),
			'link'     => (string) ( $daten['htmlLink'] ?? '' ),
		);
	}

	/**
	 * Prüft je Kategorie, ob das Dienstkonto schreiben darf.
	 *
	 * @return array<string, string> Kategorie → „ok“ oder Fehlermeldung.
	 */
	public static function testen(): array {
		$ergebnis = array();
		foreach ( array_keys( Plugin::kategorien() ) as $slug ) {
			$kalender = self::kalender_id( $slug );
			if ( '' === $kalender ) {
				$ergebnis[ $slug ] = 'keine iCal-Adresse eingetragen';
				continue;
			}
			$daten = self::anfrage( 'GET', rawurlencode( $kalender ) . '/events?maxResults=1' );
			if ( is_wp_error( $daten ) ) {
				$ergebnis[ $slug ] = $daten->get_error_message();
				continue;
			}
			$rolle             = (string) ( $daten['accessRole'] ?? '' );
			$ergebnis[ $slug ] = in_array( $rolle, array( 'writer', 'owner' ), true )
				? 'ok'
				: 'nur Lesezugriff – bitte „Änderungen an Terminen vornehmen“ erlauben';
		}
		return $ergebnis;
	}
}
