<?php
/**
 * Zerlegt die Beschreibung eines Google-Termins in freien Text und Schlüsselzeilen.
 *
 * Aufbau: freier Text, dann eine Zeile „---“, dann Zeilen „Schlüssel: Wert“.
 * Ohne WordPress-Abhängigkeit, damit testbar.
 */

namespace DTRV\Termine;

final class Parser {

	/** Erlaubte Schreibweisen (normalisiert) → Feldname. */
	private const SCHLUESSEL = array(
		'anmeldung'   => 'anmeldung',
		'anmelden'    => 'anmeldung',
		'infos'       => 'infos',
		'info'        => 'infos',
		'klasse'      => 'klasse',
		'land'        => 'land',
		'detail'      => 'detail',
		'details'     => 'detail',
		'detailseite' => 'detail',
		'status'      => 'status',
		'bild'        => 'bild',
		'foto'        => 'bild',
	);

	private const STATUS = array(
		'abgesagt'   => 'abgesagt',
		'verschoben' => 'verschoben',
		'vorlaeufig' => 'vorlaeufig',
		'vorlaufig'  => 'vorlaeufig',
	);

	private const DETAIL = array( 'beitrag', 'seite', 'keine' );

	/**
	 * @return array{text: string, felder: array<string, string>, warnungen: string[]}
	 */
	public static function beschreibung( string $roh ): array {
		$zeilen  = preg_split( '/\R/u', self::html_zu_text( $roh ) );
		$trenner = null;
		foreach ( $zeilen as $i => $zeile ) {
			if ( preg_match( '/^\s*[-–—]{3,}\s*$/u', $zeile ) ) {
				$trenner = $i;
			}
		}

		if ( null !== $trenner ) {
			$frei  = array_slice( $zeilen, 0, $trenner );
			$block = array_slice( $zeilen, $trenner + 1 );
		} else {
			// Ohne Trenner: nur bekannte Schlüsselzeilen ganz am Ende auswerten.
			$i = count( $zeilen );
			while ( $i > 0 && ( '' === trim( $zeilen[ $i - 1 ] ) || null !== self::bekannter_schluessel( $zeilen[ $i - 1 ] ) ) ) {
				--$i;
			}
			$frei  = array_slice( $zeilen, 0, $i );
			$block = array_slice( $zeilen, $i );
		}

		$felder    = array();
		$warnungen = array();
		foreach ( $block as $zeile ) {
			if ( '' === trim( $zeile ) ) {
				continue;
			}
			if ( ! preg_match( '/^\s*([^:]+?)\s*:\s*(.*?)\s*$/u', $zeile, $m ) ) {
				$warnungen[] = 'Zeile nicht erkannt: „' . trim( $zeile ) . '“';
				continue;
			}
			$feld = self::SCHLUESSEL[ self::normalisieren( $m[1] ) ] ?? null;
			if ( null === $feld ) {
				$warnungen[] = 'Unbekannter Schlüssel „' . $m[1] . '“';
				continue;
			}
			$wert = self::wert( $feld, $m[2] );
			if ( null === $wert ) {
				$warnungen[] = 'Ungültiger Wert bei „' . $m[1] . '“: „' . $m[2] . '“';
				continue;
			}
			$felder[ $feld ] = $wert;
		}

		return array(
			'text'      => trim( implode( "\n", $frei ) ),
			'felder'    => $felder,
			'warnungen' => $warnungen,
		);
	}

	/**
	 * Google speichert Beschreibungen oft als HTML. Links werden zu ihrer Adresse.
	 */
	public static function html_zu_text( string $roh ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $roh );
		if ( str_contains( $text, '<' ) ) {
			$text = preg_replace_callback(
				'#<a\s[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>(.*?)</a>#is',
				static function ( $m ) {
					$label = trim( strip_tags( $m[2] ) );
					$href  = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( '' === $label || $label === $href || preg_match( '#^(https?://|www\.)#i', $label ) ) {
						return $href;
					}
					return $label . ' (' . $href . ')';
				},
				$text
			);
			$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
			$text = preg_replace( '#</(p|div|li|h[1-6])>#i', "\n", $text );
			$text = strip_tags( $text );
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_replace( "\u{00A0}", ' ', $text );
	}

	private static function bekannter_schluessel( string $zeile ): ?string {
		if ( ! preg_match( '/^\s*([^:]+?)\s*:/u', $zeile, $m ) ) {
			return null;
		}
		return self::SCHLUESSEL[ self::normalisieren( $m[1] ) ] ?? null;
	}

	private static function normalisieren( string $s ): string {
		$s = mb_strtolower( trim( $s ), 'UTF-8' );
		$s = strtr( $s, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		return preg_replace( '/[^a-z]/', '', $s );
	}

	private static function wert( string $feld, string $wert ): ?string {
		$wert = trim( $wert );
		if ( '' === $wert ) {
			return null;
		}
		switch ( $feld ) {
			case 'anmeldung':
			case 'infos':
			case 'bild':
				if ( preg_match( '/^www\./i', $wert ) ) {
					$wert = 'https://' . $wert;
				}
				$ok = filter_var( $wert, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $wert );
				return $ok ? $wert : null;

			case 'klasse':
				$teile = array_map( 'trim', explode( '|', $wert ) );
				$teile[0] = mb_strtoupper( $teile[0], 'UTF-8' );
				return implode( ' | ', array_filter( $teile, 'strlen' ) );

			case 'land':
				$wert = strtoupper( $wert );
				return preg_match( '/^[A-Z]{2}$/', $wert ) ? $wert : null;

			case 'detail':
				$wert = self::normalisieren( $wert );
				return in_array( $wert, self::DETAIL, true ) ? $wert : null;

			case 'status':
				return self::STATUS[ self::normalisieren( $wert ) ] ?? null;
		}
		return null;
	}
}
