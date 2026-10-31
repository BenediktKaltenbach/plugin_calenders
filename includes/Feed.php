<?php
/**
 * Liest einen iCal-Feed (sabre/vobject) und liefert einzelne Termine.
 * Wiederkehrende Termine (RRULE) werden im Zeitfenster aufgelöst.
 * Ohne WordPress-Abhängigkeit, damit testbar.
 */

namespace DTRV\Termine;

use Sabre\VObject\Reader;

final class Feed {

	/**
	 * @return array{name: string, termine: array<int, array<string, mixed>>}
	 * @throws \Throwable Bei ungültigem iCal-Inhalt.
	 */
	public static function lesen( string $ics, \DateTimeInterface $von, \DateTimeInterface $bis ): array {
		$tz       = Format::zeitzone();
		$kalender = Reader::read( $ics, Reader::OPTION_FORGIVING );
		$name     = trim( (string) ( $kalender->{'X-WR-CALNAME'} ?? '' ) );
		$kalender = $kalender->expand( $von, $bis, $tz );

		$termine = array();
		foreach ( $kalender->select( 'VEVENT' ) as $ev ) {
			if ( ! isset( $ev->DTSTART ) ) {
				continue;
			}
			$ganztaegig = ! $ev->DTSTART->hasTime();
			$start      = $ev->DTSTART->getDateTime( $tz );

			if ( isset( $ev->DTEND ) ) {
				$ende = $ev->DTEND->getDateTime( $tz );
			} elseif ( isset( $ev->DURATION ) ) {
				$ende = $start->add( $ev->DURATION->getDateInterval() );
			} else {
				$ende = $ganztaegig ? $start->modify( '+1 day' ) : $start;
			}

			$beschreibung = Parser::beschreibung( (string) ( $ev->DESCRIPTION ?? '' ) );
			$status       = $beschreibung['felder']['status'] ?? '';
			if ( '' === $status && 'CANCELLED' === strtoupper( (string) ( $ev->STATUS ?? '' ) ) ) {
				$status = 'abgesagt';
			}

			$uid = trim( (string) ( $ev->UID ?? '' ) );
			if ( '' === $uid ) {
				$uid = md5( (string) $ev->SUMMARY . $start->format( 'c' ) );
			}
			$schluessel = $uid . '|' . ( isset( $ev->{'RECURRENCE-ID'} ) ? $ev->{'RECURRENCE-ID'}->getDateTime( $tz )->format( 'Ymd\THis' ) : '' );
			if ( isset( $termine[ $schluessel ] ) ) {
				$schluessel .= '|' . $start->getTimestamp();
			}

			$termine[ $schluessel ] = array(
				'schluessel' => $schluessel,
				'titel'      => trim( (string) ( $ev->SUMMARY ?? '' ) ),
				'ort'        => trim( (string) ( $ev->LOCATION ?? '' ) ),
				'start'      => $start->getTimestamp(),
				'ende'       => $ende->getTimestamp(),
				'ganztaegig' => $ganztaegig,
				'text'       => $beschreibung['text'],
				'felder'     => $beschreibung['felder'],
				'warnungen'  => $beschreibung['warnungen'],
				'status'     => $status,
			);
		}

		usort( $termine, static fn( $a, $b ) => $a['start'] <=> $b['start'] );
		return array(
			'name'    => $name,
			'termine' => $termine,
		);
	}
}
