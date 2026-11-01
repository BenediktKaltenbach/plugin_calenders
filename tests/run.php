<?php
/**
 * Kleine Tests ohne WordPress: php tests/run.php (nach composer install).
 */

namespace DTRV\Termine;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/Format.php';
require __DIR__ . '/../includes/Parser.php';
require __DIR__ . '/../includes/Feed.php';

$fehler = 0;
function pruefe( string $name, $ist, $soll ): void {
	global $fehler;
	if ( $ist === $soll ) {
		echo "ok   $name\n";
		return;
	}
	++$fehler;
	echo "FEHL $name\n     ist:  " . var_export( $ist, true ) . "\n     soll: " . var_export( $soll, true ) . "\n";
	if ( getenv( 'GITHUB_ACTIONS' ) ) {
		// Als Annotation sichtbar, auch ohne Zugriff auf das Job-Log.
		echo '::error title=Test ' . $name . '::' . str_replace( array( "\n", "\r" ), ' ', 'ist ' . var_export( $ist, true ) . ' soll ' . var_export( $soll, true ) ) . "\n";
	}
}

$tz = Format::zeitzone();
$ts = static fn( string $s ) => ( new \DateTimeImmutable( $s, $tz ) )->getTimestamp();

// Datumsformat.
pruefe( 'mehrtägig im Monat', Format::zeitraum( $ts( '2027-04-02' ), $ts( '2027-04-06' ), true ), '2.–5.4.2027' );
pruefe( 'eintägig', Format::zeitraum( $ts( '2027-05-23' ), $ts( '2027-05-24' ), true ), '23.5.2027' );
pruefe( 'über Monatsgrenze', Format::zeitraum( $ts( '2027-04-30' ), $ts( '2027-05-03' ), true ), '30.4.–2.5.2027' );
pruefe( 'über Jahresgrenze', Format::zeitraum( $ts( '2026-12-31' ), $ts( '2027-01-03' ), true ), '31.12.2026–2.1.2027' );
pruefe( 'mit Uhrzeit', Format::zeitraum( $ts( '2027-05-23 10:00' ), $ts( '2027-05-23 14:00' ), false ), '23.5.2027, 10:00–14:00 Uhr' );
pruefe( 'Flagge', Format::flagge( 'NL' ), "\u{1F1F3}\u{1F1F1}" );

// Klasse je Kategorie.
pruefe( 'Klasse Wettkampf ohne Art', Format::klasse( 'wettkampf', '0-5' ), 'W | 0-5' );
pruefe( 'Klasse Wettkampf erzwingt W', Format::klasse( 'wettkampf', 'F | 5-10' ), 'W | 5-10' );
pruefe( 'Klasse Breitensport', Format::klasse( 'breitensport', 'a | 10-15' ), 'A | 10-15' );
pruefe( 'Klasse Breitensport nur Art', Format::klasse( 'breitensport', 'F' ), 'F | ?' );
pruefe( 'Klasse Ausbildung leer', Format::klasse( 'ausbildung', 'F | 0-5' ), '' );
pruefe( 'Klasse fehlt', Format::klasse( 'wettkampf', '' ), '' );

// Beschreibung.
$b = Parser::beschreibung( "Freier Text\n\n---\nAnmeldung: https://my.raceresult.com/392357/\n STATUS : abgesagt\nLand: CZ\nFoo: bar" );
pruefe( 'Text', $b['text'], 'Freier Text' );
pruefe( 'Felder', $b['felder'], array( 'anmeldung' => 'https://my.raceresult.com/392357/', 'status' => 'abgesagt', 'land' => 'CZ' ) );
pruefe( 'Warnung unbekannt', $b['warnungen'], array( 'Unbekannter Schlüssel „Foo“' ) );
$b = Parser::beschreibung( "Infos: kein Link\n---\nInfos: nichts" );
pruefe( 'Ungültige URL', array( $b['text'], $b['felder'], count( $b['warnungen'] ) ), array( 'Infos: kein Link', array(), 1 ) );

// Feed.
$feed    = Feed::lesen(
	file_get_contents( __DIR__ . '/fixtures/beispiel.ics' ),
	new \DateTimeImmutable( '2027-01-01', $tz ),
	new \DateTimeImmutable( '2028-01-01', $tz )
);
$termine = $feed['termine'];
pruefe( 'Kalendername', $feed['name'], 'Breitensport' );
$nach_titel = array();
foreach ( $termine as $t ) {
	$nach_titel[ $t['titel'] ][] = $t;
}
pruefe( 'Anzahl Termine', count( $termine ), 6 );

$o = $nach_titel['Osterchallenge'][0];
pruefe( 'Oster Datum', Format::zeitraum( $o['start'], $o['ende'], $o['ganztaegig'] ), '2.–5.4.2027' );
pruefe( 'Oster Felder', $o['felder'], array( 'infos' => 'https://dtrv.net/osterchallenge/', 'klasse' => 'F | 0-5' ) );
pruefe( 'Oster Text', $o['text'], 'Vier Tage Tretroller fahren.' );
pruefe( 'Oster Warnung', $o['warnungen'], array( 'Unbekannter Schlüssel „Farbe“' ) );
pruefe( 'Oster Bild-Anhang (PDF übersprungen)', $o['anhang'], 'https://drive.google.com/file/d/1AbC_dEf-123456/view?usp=drive_web' );
pruefe( 'Rhein ohne Anhang', $nach_titel['Rheinradeln'][0]['anhang'], '' );
pruefe( 'Rhein GEO', $nach_titel['Rheinradeln'][0]['geo'], array( 'lat' => 50.7151, 'lng' => 7.1393 ) );
pruefe( 'Oster ohne GEO', $o['geo'], null );

$r = $nach_titel['Rheinradeln'][0];
pruefe( 'Rhein Datum', Format::zeitraum( $r['start'], $r['ende'], $r['ganztaegig'] ), '23.5.2027, 10:00–14:00 Uhr' );
pruefe( 'Rhein Felder', $r['felder'], array( 'anmeldung' => 'https://www.example.org/rheinradeln', 'status' => 'vorlaeufig', 'land' => 'NL' ) );
pruefe( 'Rhein Status', $r['status'], 'vorlaeufig' );

pruefe( 'Serie: 2 reguläre + 1 Ausnahme', array( count( $nach_titel['Lauftreff'] ), count( $nach_titel['Lauftreff (später)'] ) ), array( 2, 1 ) );
$s = $nach_titel['Lauftreff (später)'][0];
pruefe( 'Ausnahme verschoben', Format::zeitraum( $s['start'], $s['ende'], false ), '13.1.2027, 20:00–21:30 Uhr' );
$schluessel = array_column( $termine, 'schluessel' );
pruefe( 'Schlüssel eindeutig', count( array_unique( $schluessel ) ), count( $schluessel ) );

$e = $nach_titel['Autofreies Eistal'][0];
pruefe( 'Eistal abgesagt (STATUS:CANCELLED)', $e['status'], 'abgesagt' );
pruefe( 'Eistal ohne DTEND', Format::zeitraum( $e['start'], $e['ende'], true ), '3.10.2027' );
pruefe( 'Eistal ohne Trenner', array( $e['text'], $e['felder'] ), array( 'Text ohne Trenner', array( 'detail' => 'beitrag' ) ) );

// Bilder: Drive-Links erkennen.
require __DIR__ . '/../includes/Bilder.php';
pruefe( 'Drive file/d', Bilder::drive_id( 'https://drive.google.com/file/d/1AbC_dEf-123456/view?usp=drive_web' ), '1AbC_dEf-123456' );
pruefe( 'Drive open?id', Bilder::drive_id( 'https://drive.google.com/open?id=1AbC_dEf-123456' ), '1AbC_dEf-123456' );
pruefe( 'Kein Drive', Bilder::drive_id( 'https://dtrv.net/wp-content/uploads/a.jpg?id=1234567890ab' ), '' );
pruefe( 'Drive-Download', Bilder::download_url( 'https://drive.google.com/file/d/1AbC_dEf-123456/view' ), 'https://drive.google.com/uc?export=download&id=1AbC_dEf-123456' );
pruefe( 'Bild-Zeile', Parser::beschreibung( "x\n---\nBild: https://dtrv.net/a.jpg" )['felder'], array( 'bild' => 'https://dtrv.net/a.jpg' ) );

// Koordinaten aus Text und Karten-Links.
require __DIR__ . '/../includes/Geo.php';
$bonn = array( 'lat' => 50.7151, 'lng' => 7.1393 );
pruefe( 'Geo Zahlen', Geo::lesen( '50.7151, 7.1393' ), $bonn );
pruefe( 'Geo Zahlen ohne Komma', Geo::lesen( '50.7151 7.1393' ), $bonn );
pruefe( 'Geo Google @', Geo::lesen( 'https://www.google.com/maps/@50.7151,7.1393,15z' ), $bonn );
pruefe( 'Geo Google Ort', Geo::lesen( 'https://www.google.com/maps/place/Bonn/@50.70,7.10,12z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d50.7151!4d7.1393' ), $bonn );
pruefe( 'Geo Google q=', Geo::lesen( 'https://maps.google.com/?q=50.7151,7.1393' ), $bonn );
pruefe( 'Geo OSM Marker', Geo::lesen( 'https://www.openstreetmap.org/?mlat=50.7151&mlon=7.1393#map=15/50.7/7.1' ), $bonn );
pruefe( 'Geo OSM Ausschnitt', Geo::lesen( 'https://www.openstreetmap.org/#map=15/50.7151/7.1393' ), $bonn );
pruefe( 'Geo ungültig', Geo::lesen( 'Marktplatz Bonn' ), null );
pruefe( 'Geo außerhalb', Geo::lesen( '95.0, 7.0' ), null );
pruefe( 'Anzeige-Zeile', Parser::beschreibung( "x\n---\nAnzeige: regelmäßig" )['felder'], array( 'anzeige' => 'regelmäßig' ) );
pruefe( 'Koordinaten-Zeile', Parser::beschreibung( "x\n---\nKoordinaten: 50.7151, 7.1393" )['felder'], array( 'koordinaten' => '50.7151, 7.1393' ) );

// Vorausgefüllter Google-Kalender-Link für Vorschläge.
require __DIR__ . '/../includes/Vorschlaege.php';
$v = array(
	'von'          => '2027-04-02',
	'bis'          => '2027-04-05',
	'zeit_von'     => '',
	'zeit_bis'     => '',
	'ort'          => 'Worms',
	'infos'        => 'https://dtrv.net/x/',
	'anmeldung'    => '',
	'beschreibung' => 'Tour',
);
parse_str( (string) parse_url( Vorschlaege::google_url( $v, 'Test & Tour' ), PHP_URL_QUERY ), $q );
pruefe( 'Google ganztägig', array( $q['text'], $q['dates'], $q['location'] ), array( 'Test & Tour', '20270402/20270406', 'Worms' ) );
pruefe( 'Google Schlüsselzeilen', $q['details'], "Tour\n\n---\nInfos: https://dtrv.net/x/" );
$v = array_merge( $v, array( 'bis' => '', 'zeit_von' => '10:00', 'zeit_bis' => '', 'infos' => '' ) );
parse_str( (string) parse_url( Vorschlaege::google_url( $v, 'T', 'https://dtrv.net/b.jpg' ), PHP_URL_QUERY ), $q );
pruefe( 'Google mit Uhrzeit', array( $q['dates'], $q['ctz'] ), array( '20270402T100000/20270402T120000', 'Europe/Berlin' ) );
pruefe( 'Google Bild im Text', $q['details'], "Tour\n\nBild vom Einreicher: https://dtrv.net/b.jpg" );
parse_str( (string) parse_url( Vorschlaege::google_url( array_merge( $v, array( 'klasse' => 'F | 0-5' ) ), 'T' ), PHP_URL_QUERY ), $q );
pruefe( 'Google Klasse als Schlüsselzeile', $q['details'], "Tour\n\n---\nKlasse: F | 0-5" );
parse_str( (string) parse_url( Vorschlaege::google_url( array_merge( $v, array( 'geo' => $bonn ) ), 'T' ), PHP_URL_QUERY ), $q );
pruefe( 'Google Koordinaten als Schlüsselzeile', $q['details'], "Tour\n\n---\nKoordinaten: 50.7151, 7.1393" );

// Zeiten und Kalendertext für „Annehmen“ (Google-API).
list( $zs, $ze, $zg ) = Vorschlaege::zeiten( array( 'von' => '2027-04-02', 'bis' => '2027-04-05', 'zeit_von' => '', 'zeit_bis' => '' ) );
pruefe( 'Zeiten ganztägig', array( $zs->format( 'Y-m-d' ), $ze->format( 'Y-m-d' ), $zg ), array( '2027-04-02', '2027-04-06', true ) );
list( $zs, $ze, $zg ) = Vorschlaege::zeiten( array( 'von' => '2027-05-23', 'bis' => '', 'zeit_von' => '10:00', 'zeit_bis' => '14:30' ) );
pruefe( 'Zeiten mit Uhrzeit', array( $zs->format( 'Y-m-d H:i' ), $ze->format( 'Y-m-d H:i' ), $zg ), array( '2027-05-23 10:00', '2027-05-23 14:30', false ) );
pruefe(
	'Kalendertext',
	Vorschlaege::kalender_text( array( 'beschreibung' => 'Tour', 'infos' => '', 'anmeldung' => 'https://a.de/', 'klasse' => 'F | 0-5', 'geo' => $bonn ) ),
	"Tour

---
Klasse: F | 0-5
Koordinaten: 50.7151, 7.1393
Anmeldung: https://a.de/"
);

// Google-Schlüssel prüfen (mit einem für den Test erzeugten Schlüssel).
require __DIR__ . '/../includes/Google.php';
$pk = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $pk, $pem );
$gut = json_encode( array( 'type' => 'service_account', 'client_email' => 'x@y.iam.gserviceaccount.com', 'private_key' => $pem ) );
pruefe( 'Schlüssel gültig', Google::pruefen( $gut )['problem'], '' );
pruefe( 'Schlüssel mit echten Zeilenumbrüchen repariert', Google::pruefen( str_replace( '\\n', "\n", $gut ) )['problem'], '' );
pruefe( 'Schlüssel kein JSON', str_starts_with( Google::pruefen( '{ kaputt' )['problem'], 'Der Schlüssel ist kein gültiges JSON' ), true );
pruefe( 'Schlüssel ohne client_email', str_contains( Google::pruefen( '{"private_key":"x"}' )['problem'], 'client_email' ), true );

// Vorschlag ↔ Kalendertermin: Titel muss genau gleich sein (nur Leerzeichen egal).
pruefe( 'Titel gleich', Vorschlaege::titel_gleich( 'Rheinradeln', 'Rheinradeln' ), true );
pruefe( 'Titel Leerzeichen', Vorschlaege::titel_gleich( ' Siegtal  Pur ', 'Siegtal Pur' ), true );
pruefe( 'Titel mit Jahr ist anders', Vorschlaege::titel_gleich( 'Osterchallenge', 'Osterchallenge 2027' ), false );
pruefe( 'Titel Groß/Klein ist anders', Vorschlaege::titel_gleich( 'rheinradeln', 'Rheinradeln' ), false );
pruefe( 'Titel leer', Vorschlaege::titel_gleich( '', '' ), false );

echo $fehler ? "\n$fehler Fehler\n" : "\nAlles ok\n";
exit( $fehler ? 1 : 0 );
