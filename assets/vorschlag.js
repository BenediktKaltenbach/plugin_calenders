/**
 * Formular „Termin einreichen“ (Block DTRV-Terminvorschlag).
 *
 * Eigene Datei statt Inline-Skript: WordPress-Textfilter im Seiteninhalt machten aus
 * „&&“ ein „&#038;&#038;“ und brachen das Skript damit komplett ab.
 * Bewusst ES5 ohne neuere Syntax, damit es auch auf älteren iPhones/iPads läuft.
 */
( function () {
	'use strict';

	function start() {
		var box = document.getElementById( 'dtrv-vorschlag' );
		if ( ! box ) {
			return;
		}
		var form = box.querySelector( 'form' );
		var kategorie = box.querySelector( '[name=kategorie]' );
		var knopf = form ? form.querySelector( 'button[type=submit]' ) : null;
		var knopfText = knopf ? knopf.textContent : '';
		var daten = window.dtrvVorschlag || {};

		// Über einen Link mit #dtrv-vorschlag gekommen (z. B. „Termin einreichen“ unter der Karte):
		// Formular gleich aufklappen.
		if ( window.location.hash === '#dtrv-vorschlag' && 'open' in box ) {
			box.open = true;
		}

		// Felder je Kategorie ein-/ausblenden (Art nur Breitensport, Bergfaktor auch Wettkampf).
		function felder() {
			var wert = kategorie ? kategorie.value : '';
			var liste = box.querySelectorAll( '[data-kategorien]' );
			for ( var i = 0; i < liste.length; i++ ) {
				liste[ i ].hidden = liste[ i ].getAttribute( 'data-kategorien' ).split( ' ' ).indexOf( wert ) < 0;
			}
		}
		if ( kategorie ) {
			kategorie.addEventListener( 'change', felder );
		}
		felder();

		// Doppeltes Absenden verhindern; nach „Zurück“ (bfcache) Knopf und Felder wiederherstellen.
		if ( form && knopf ) {
			form.addEventListener( 'submit', function () {
				setTimeout( function () {
					knopf.disabled = true;
					knopf.textContent = 'Wird gesendet …';
				}, 0 );
			} );
		}
		window.addEventListener( 'pageshow', function () {
			if ( knopf ) {
				knopf.disabled = false;
				knopf.textContent = knopfText;
			}
			felder();
		} );

		// Ort suchen: bis zu fünf Treffer von OpenStreetMap zur Auswahl.
		// Keine Suche beim Tippen – das erlauben die Nominatim-Regeln nicht.
		var suchen = box.querySelector( '.dtrv-vorschlag__suchen' );
		var treffer = box.querySelector( '.dtrv-vorschlag__treffer' );
		var ort = box.querySelector( '[name=ort]' );
		var lat = box.querySelector( '[name=lat]' );
		var lng = box.querySelector( '[name=lng]' );
		if ( suchen && treffer && ort && lat && lng && window.fetch && daten.orte ) {
			suchen.hidden = false;
			ort.addEventListener( 'input', function () {
				lat.value = '';
				lng.value = '';
				treffer.textContent = '';
			} );
			suchen.addEventListener( 'click', function () {
				var suche = ort.value.trim();
				if ( suche.length < 3 ) {
					treffer.textContent = 'Bitte zuerst den Ort eingeben.';
					return;
				}
				treffer.textContent = 'Suche …';
				var trenner = daten.orte.indexOf( '?' ) < 0 ? '?' : '&';
				fetch( daten.orte + trenner + 'q=' + encodeURIComponent( suche ) )
					.then( function ( antwort ) {
						return antwort.json();
					} )
					.then( function ( liste ) {
						treffer.textContent = '';
						if ( ! Array.isArray( liste ) || ! liste.length ) {
							treffer.textContent = liste && liste.message ? liste.message : 'Nichts gefunden – bitte genauer angeben, z. B. mit Land.';
							return;
						}
						liste.forEach( function ( t ) {
							var label = document.createElement( 'label' );
							var radio = document.createElement( 'input' );
							radio.type = 'radio';
							radio.name = 'dtrv_treffer';
							radio.addEventListener( 'change', function () {
								lat.value = t.lat;
								lng.value = t.lng;
							} );
							label.appendChild( radio );
							label.appendChild( document.createTextNode( ' ' + t.name ) );
							treffer.appendChild( label );
						} );
						var quelle = document.createElement( 'small' );
						quelle.textContent = 'Daten © OpenStreetMap-Mitwirkende';
						treffer.appendChild( quelle );
					} )
					.catch( function () {
						treffer.textContent = 'Ortssuche gerade nicht erreichbar.';
					} );
			} );
		}

		// Zu große Bilder schon im Browser abfangen, bevor alles hochgeladen wird.
		var bild = box.querySelector( '[name=bild]' );
		if ( bild ) {
			bild.addEventListener( 'change', function () {
				var max = parseInt( bild.getAttribute( 'data-max-mb' ), 10 );
				if ( bild.files && bild.files[ 0 ] && max && bild.files[ 0 ].size > max * 1048576 ) {
					window.alert( 'Das Bild ist größer als ' + max + ' MB. Bitte ein kleineres wählen.' );
					bild.value = '';
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
