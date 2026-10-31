<?php
/**
 * Datumsformatierung (ohne WordPress-Abhängigkeit, damit testbar).
 */

namespace DTRV\Termine;

final class Format {

	public const ZEITZONE = 'Europe/Berlin';

	public static function zeitzone(): \DateTimeZone {
		return new \DateTimeZone( self::ZEITZONE );
	}

	/**
	 * Formatiert einen Zeitraum wie auf dtrv.net üblich, z. B. „2.–5.4.2027“
	 * oder „23.5.2027, 10:00–14:00 Uhr“.
	 *
	 * @param int  $start      Unix-Zeitstempel.
	 * @param int  $ende       Unix-Zeitstempel (bei ganztägig: exklusiv, wie in iCal).
	 * @param bool $ganztaegig Ganztägiger Termin.
	 */
	public static function zeitraum( int $start, int $ende, bool $ganztaegig ): string {
		$tz = self::zeitzone();
		$s  = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $tz );
		$e  = ( new \DateTimeImmutable( '@' . max( $start, $ende ) ) )->setTimezone( $tz );

		if ( $ganztaegig ) {
			$e = $e->modify( '-1 day' );
			if ( $e < $s || $s->format( 'Y-m-d' ) === $e->format( 'Y-m-d' ) ) {
				return $s->format( 'j.n.Y' );
			}
			if ( $s->format( 'Y-m' ) === $e->format( 'Y-m' ) ) {
				return $s->format( 'j.' ) . '–' . $e->format( 'j.n.Y' );
			}
			if ( $s->format( 'Y' ) === $e->format( 'Y' ) ) {
				return $s->format( 'j.n.' ) . '–' . $e->format( 'j.n.Y' );
			}
			return $s->format( 'j.n.Y' ) . '–' . $e->format( 'j.n.Y' );
		}

		if ( $s->format( 'Y-m-d' ) === $e->format( 'Y-m-d' ) ) {
			$zeit = $s->format( 'G:i' ) . ( $e > $s ? '–' . $e->format( 'G:i' ) : '' );
			return $s->format( 'j.n.Y' ) . ', ' . $zeit . ' Uhr';
		}
		return $s->format( 'j.n.Y, G:i' ) . ' Uhr – ' . $e->format( 'j.n.Y, G:i' ) . ' Uhr';
	}

	/**
	 * Klasse je Kategorie vereinheitlichen (Art | Bergfaktor = Höhenmeter pro km):
	 * Wettkampf immer „W“, Breitensport „F“ oder „A“, Ausbildung/Verband ohne Klasse.
	 * Beispiele: („wettkampf“, „0-5“) → „W | 0-5“, („ausbildung“, „F | 0-5“) → „“.
	 */
	public static function klasse( string $kategorie, string $wert ): string {
		$wert = trim( $wert );
		if ( '' === $wert || ! in_array( $kategorie, array( 'wettkampf', 'breitensport' ), true ) ) {
			return '';
		}
		$teile = array_map( 'trim', explode( '|', $wert ) );
		if ( count( $teile ) >= 2 ) {
			$art  = strtoupper( $teile[0] );
			$berg = '' !== $teile[1] ? $teile[1] : '?';
		} elseif ( preg_match( '/^[AFW]$/i', $teile[0] ) ) {
			$art  = strtoupper( $teile[0] );
			$berg = '?';
		} else {
			$art  = '';
			$berg = $teile[0];
		}
		if ( 'wettkampf' === $kategorie ) {
			$art = 'W';
		}
		return '' !== $art ? $art . ' | ' . $berg : $berg;
	}

	/**
	 * Flaggen-Emoji aus einem ISO-Ländercode („NL“ → 🇳🇱).
	 */
	public static function flagge( string $land ): string {
		if ( ! preg_match( '/^[A-Z]{2}$/', $land ) ) {
			return '';
		}
		return mb_chr( 0x1F1E6 + ord( $land[0] ) - 65 ) . mb_chr( 0x1F1E6 + ord( $land[1] ) - 65 );
	}
}
