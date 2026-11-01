<?php
/**
 * Terminvorschläge: Inhaltstyp, Prüfliste im Admin, Freigabe-Links aus der Mail.
 *
 * Freigeben markiert nur. Eingetragen wird im Google Kalender (per Hand über den
 * vorausgefüllten Google-Link oder durch Claude); das Plugin schreibt nie in Google.
 */

namespace DTRV\Termine;

final class Vorschlaege {

	public const NAME = 'dtrv_vorschlag';

	public const STATUS = array(
		'neu'         => 'Neu',
		'freigegeben' => 'Angenommen',
		'eingetragen' => 'Eingetragen',
		'abgelehnt'   => 'Abgelehnt',
	);

	public static function registrieren(): void {
		register_post_type(
			self::NAME,
			array(
				'labels'          => array(
					'name'          => 'Terminvorschläge',
					'singular_name' => 'Terminvorschlag',
					'menu_name'     => 'Vorschläge',
					'all_items'     => 'Vorschläge',
					'edit_item'     => 'Vorschlag ansehen',
					'search_items'  => 'Vorschläge durchsuchen',
					'not_found'     => 'Keine Vorschläge.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . PostType::NAME,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	public static function admin(): void {
		add_filter( 'manage_' . self::NAME . '_posts_columns', array( self::class, 'spalten' ) );
		add_action( 'manage_' . self::NAME . '_posts_custom_column', array( self::class, 'spalte' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'zeilen_aktionen' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::NAME, array( self::class, 'meta_box' ) );
		add_action( 'admin_post_dtrv_vorschlag_status', array( self::class, 'status_knopf' ) );
		add_action( 'admin_menu', array( self::class, 'zaehler' ), 99 );
		add_filter( 'views_edit-' . self::NAME, array( self::class, 'ansichten' ) );
		add_action( 'pre_get_posts', array( self::class, 'admin_abfrage' ) );
	}

	/** Reiter über der Liste: Offen (Standard) · Eingetragen · Abgelehnt · Alle. */
	private const ANSICHTEN = array(
		'offen'       => array( 'Offen', array( 'neu', 'freigegeben' ) ),
		'eingetragen' => array( 'Eingetragen', array( 'eingetragen' ) ),
		'abgelehnt'   => array( 'Abgelehnt', array( 'abgelehnt' ) ),
		'alle'        => array( 'Alle', array() ),
	);

	private static function ansicht(): string {
		$a = sanitize_key( $_GET['ansicht'] ?? 'offen' ); // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::ANSICHTEN[ $a ] ) ? $a : 'offen';
	}

	private static function status_abfrage( array $status ): array {
		return $status ? array(
			array(
				'key'     => '_dtrv_vstatus',
				'value'   => $status,
				'compare' => 'IN',
			),
		) : array();
	}

	public static function ansichten( array $views ): array {
		$aktiv = self::ansicht();
		$neu   = array();
		foreach ( self::ANSICHTEN as $schluessel => list( $label, $status ) ) {
			$anzahl = count(
				get_posts(
					array(
						'post_type'      => self::NAME,
						'post_status'    => 'publish',
						'posts_per_page' => 500,
						'fields'         => 'ids',
						'meta_query'     => self::status_abfrage( $status ), // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				)
			);
			$neu[ $schluessel ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( array( 'post_type' => self::NAME, 'ansicht' => $schluessel ), admin_url( 'edit.php' ) ) ),
				$schluessel === $aktiv ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$anzahl
			);
		}
		return $neu;
	}

	public static function admin_abfrage( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || self::NAME !== $q->get( 'post_type' ) ) {
			return;
		}
		$q->set( 'meta_query', self::status_abfrage( self::ANSICHTEN[ self::ansicht() ][1] ) );
	}

	/**
	 * Nach dem Abgleich: Offene Vorschläge (neu oder freigegeben), die inzwischen im
	 * Kalender stehen, werden „eingetragen“ – mit Dankes-Mail. Der Tag muss immer stimmen;
	 * dazu entweder genau derselbe Titel oder der einzige Termin der Kategorie an diesem Tag.
	 */
	public static function abgleichen(): void {
		$offen = get_posts(
			array(
				'post_type'      => self::NAME,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => self::status_abfrage( array( 'neu', 'freigegeben' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$tz = Format::zeitzone();
		foreach ( $offen as $id ) {
			$uid = (string) get_post_meta( $id, '_dtrv_ical_uid', true );
			if ( '' !== $uid ) {
				$genau = get_posts(
					array(
						'post_type'      => PostType::NAME,
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array(
								'key'     => '_dtrv_schluessel',
								'value'   => $uid . '|',
								'compare' => 'LIKE',
							),
						),
					)
				);
				if ( $genau ) {
					self::online( $id, (int) $genau[0] );
				}
				continue; // vom Plugin angelegt: nur über die UID zuordnen
			}
			$von = (string) get_post_meta( $id, '_dtrv_von', true );
			try {
				$tag = new \DateTimeImmutable( $von, $tz );
			} catch ( \Exception $e ) {
				continue;
			}
			$termine = get_posts(
				array(
					'post_type'      => PostType::NAME,
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => '_dtrv_start',
							'value'   => array( $tag->getTimestamp(), $tag->modify( '+1 day' )->getTimestamp() - 1 ),
							'compare' => 'BETWEEN',
							'type'    => 'NUMERIC',
						),
					),
				)
			);
			$treffer   = 0;
			$kategorie = (string) get_post_meta( $id, '_dtrv_kategorie', true );
			$gleiche   = array();
			foreach ( $termine as $termin_id ) {
				if ( self::titel_gleich( get_post_field( 'post_title', $id ), get_post_field( 'post_title', $termin_id ) ) ) {
					$treffer = $termin_id;
					break;
				}
				if ( get_post_meta( $termin_id, '_dtrv_kategorie', true ) === $kategorie ) {
					$gleiche[] = $termin_id;
				}
			}
			// Kein gleicher Titel: nur wenn es an dem Tag genau einen Termin dieser Kategorie gibt.
			if ( ! $treffer && 1 === count( $gleiche ) ) {
				$treffer = $gleiche[0];
			}
			if ( $treffer ) {
				self::online( $id, $treffer );
			}
		}
	}

	/** Genau derselbe Titel; nur zusätzliche Leerzeichen zählen nicht. Ohne WordPress-Abhängigkeit. */
	public static function titel_gleich( string $a, string $b ): bool {
		$norm = static fn( string $s ): string => trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return '' !== $norm( $a ) && $norm( $a ) === $norm( $b );
	}

	public static function daten( int $id ): array {
		$m = static fn( string $k ) => (string) get_post_meta( $id, '_dtrv_' . $k, true );
		return array(
			'kategorie'    => $m( 'kategorie' ),
			'von'          => $m( 'von' ),
			'bis'          => $m( 'bis' ),
			'zeit_von'     => $m( 'zeit_von' ),
			'zeit_bis'     => $m( 'zeit_bis' ),
			'ort'          => $m( 'ort' ),
			'klasse'       => $m( 'klasse' ),
			'karte'        => $m( 'karte' ),
			'geo'          => is_array( get_post_meta( $id, '_dtrv_geo', true ) ) ? get_post_meta( $id, '_dtrv_geo', true ) : null,
			'infos'        => $m( 'infos' ),
			'anmeldung'    => $m( 'anmeldung' ),
			'name'         => $m( 'name' ),
			'email'        => $m( 'email' ),
			'status'       => $m( 'vstatus' ) ? $m( 'vstatus' ) : 'neu',
			'bild'         => (int) get_post_meta( $id, '_dtrv_bild', true ),
			'beschreibung' => (string) get_post_field( 'post_content', $id ),
		);
	}

	/** Datum eines Vorschlags im üblichen Format, z. B. „2.–5.4.2027“. */
	public static function datum_text( array $d ): string {
		$tz = Format::zeitzone();
		try {
			if ( $d['zeit_von'] ) {
				$s = new \DateTimeImmutable( $d['von'] . ' ' . $d['zeit_von'], $tz );
				$e = $d['zeit_bis'] ? new \DateTimeImmutable( ( $d['bis'] ? $d['bis'] : $d['von'] ) . ' ' . $d['zeit_bis'], $tz ) : $s;
				return Format::zeitraum( $s->getTimestamp(), $e->getTimestamp(), false );
			}
			$s = new \DateTimeImmutable( $d['von'], $tz );
			$e = ( new \DateTimeImmutable( $d['bis'] ? $d['bis'] : $d['von'], $tz ) )->modify( '+1 day' );
			return Format::zeitraum( $s->getTimestamp(), $e->getTimestamp(), true );
		} catch ( \Exception $e ) {
			return $d['von'];
		}
	}

	/**
	 * Link, der Google Kalender mit einem vorausgefüllten Termin öffnet
	 * (inkl. Schlüsselzeilen in der Beschreibung). Ohne WordPress-Abhängigkeit.
	 *
	 * @param array  $d       Daten wie aus daten().
	 * @param string $titel   Titel des Termins.
	 * @param string $bild    Optional: Bild-URL, landet im freien Text.
	 */
	public static function google_url( array $d, string $titel, string $bild = '' ): string {
		list( $s, $e, $ganztaegig ) = self::zeiten( $d );
		$dates = $ganztaegig
			? $s->format( 'Ymd' ) . '/' . $e->format( 'Ymd' )
			: $s->format( 'Ymd\THis' ) . '/' . $e->format( 'Ymd\THis' );

		return 'https://calendar.google.com/calendar/render?' . http_build_query(
			array(
				'action'   => 'TEMPLATE',
				'text'     => $titel,
				'dates'    => $dates,
				'ctz'      => Format::ZEITZONE,
				'location' => $d['ort'],
				'details'  => self::kalender_text( $d, $bild ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Beginn, Ende (bei ganztägig exklusiv) und ganztägig ja/nein. Ohne WordPress-Abhängigkeit.
	 * Nur Startzeit angegeben: Ende = Start, dann zeigt die Website nur „10:00 Uhr“ statt eines erfundenen Zeitraums.
	 *
	 * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: bool}
	 */
	public static function zeiten( array $d ): array {
		$tz = Format::zeitzone();
		if ( $d['zeit_von'] ) {
			$s = new \DateTimeImmutable( $d['von'] . ' ' . $d['zeit_von'], $tz );
			$e = $d['zeit_bis']
				? new \DateTimeImmutable( ( $d['bis'] ? $d['bis'] : $d['von'] ) . ' ' . $d['zeit_bis'], $tz )
				: $s;
			return array( $s, $e, false );
		}
		$s = new \DateTimeImmutable( $d['von'], $tz );
		$e = ( new \DateTimeImmutable( $d['bis'] ? $d['bis'] : $d['von'], $tz ) )->modify( '+1 day' );
		return array( $s, $e, true );
	}

	/** Beschreibung für den Kalender: freier Text, dann „---“ und Schlüsselzeilen. Ohne WordPress-Abhängigkeit. */
	public static function kalender_text( array $d, string $bild = '' ): string {
		$text = trim( $d['beschreibung'] );
		if ( '' !== $bild ) {
			$text .= ( '' !== $text ? "\n\n" : '' ) . 'Bild vom Einreicher: ' . $bild;
		}
		$zeilen = array();
		if ( '' !== $d['infos'] ) {
			$zeilen[] = 'Infos: ' . $d['infos'];
		}
		if ( '' !== ( $d['klasse'] ?? '' ) ) {
			$zeilen[] = 'Klasse: ' . $d['klasse'];
		}
		if ( ! empty( $d['geo'] ) ) {
			$zeilen[] = 'Koordinaten: ' . $d['geo']['lat'] . ', ' . $d['geo']['lng'];
		}
		if ( '' !== $d['anmeldung'] ) {
			$zeilen[] = 'Anmeldung: ' . $d['anmeldung'];
		}
		if ( $zeilen ) {
			$text .= "\n\n---\n" . implode( "\n", $zeilen );
		}
		return $text;
	}

	/**
	 * „Annehmen“: legt den Termin per Dienstkonto direkt im Google Kalender an.
	 * Die Dankes-Mail folgt erst, wenn der Abgleich den Termin auf die Website geholt hat.
	 *
	 * @return true|\WP_Error
	 */
	public static function annehmen( int $id ) {
		$d = self::daten( $id );
		if ( get_post_meta( $id, '_dtrv_google_id', true ) ) {
			return true; // schon angelegt (Doppelklick, zweiter Vorstand)
		}
		if ( ! Google::konfiguriert() ) {
			return new \WP_Error( 'dtrv', 'Google-Schreibzugriff ist nicht eingerichtet.' );
		}
		list( $s, $e, $ganztaegig ) = self::zeiten( $d );
		$bild     = $d['bild'] ? (string) wp_get_attachment_url( $d['bild'] ) : '';
		$ergebnis = Google::termin_anlegen(
			$d['kategorie'],
			array(
				'titel'      => get_post_field( 'post_title', $id ),
				'ort'        => $d['ort'],
				'text'       => self::kalender_text( $d, $bild ),
				'start'      => $s,
				'ende'       => $e,
				'ganztaegig' => $ganztaegig,
			)
		);
		if ( is_wp_error( $ergebnis ) ) {
			return $ergebnis;
		}
		update_post_meta( $id, '_dtrv_google_id', $ergebnis['id'] );
		update_post_meta( $id, '_dtrv_ical_uid', $ergebnis['ical_uid'] );
		update_post_meta( $id, '_dtrv_google_link', $ergebnis['link'] );
		self::status_setzen( $id, 'freigegeben' );
		Sync::bald();
		return true;
	}

	public static function google_link( int $id ): string {
		$d    = self::daten( $id );
		$bild = $d['bild'] ? (string) wp_get_attachment_url( $d['bild'] ) : '';
		return self::google_url( $d, get_post_field( 'post_title', $id ), $bild );
	}

	/** Freigabe-Link für die Mail (geheimer Token je Vorschlag, keine Anmeldung nötig). */
	public static function link( int $id, string $aktion ): string {
		return add_query_arg(
			array(
				'dtrv_vorschlag' => $id,
				'aktion'         => $aktion,
				'token'          => get_post_meta( $id, '_dtrv_token', true ),
			),
			home_url( '/' )
		);
	}

	public static function status_setzen( int $id, string $status ): void {
		if ( ! isset( self::STATUS[ $status ] ) ) {
			return;
		}
		update_post_meta( $id, '_dtrv_vstatus', $status );

		if ( 'abgelehnt' === $status ) {
			$bild = (int) get_post_meta( $id, '_dtrv_bild', true );
			if ( $bild ) {
				wp_delete_attachment( $bild, true );
				delete_post_meta( $id, '_dtrv_bild' );
			}
			delete_post_meta( $id, '_dtrv_email' );
		}
	}

	/** Termin ist auf der Website: Status „eingetragen“ und Dankes-Mail mit Link zur Termin-Seite. */
	private static function online( int $id, int $termin_id ): void {
		update_post_meta( $id, '_dtrv_termin_id', $termin_id );
		self::status_setzen( $id, 'eingetragen' );
		// Dankes-Mail nur einmal, auch wenn zwei Abgleiche gleichzeitig laufen.
		if ( add_post_meta( $id, '_dtrv_dank_gesendet', time(), true ) ) {
			$link = Detailseiten::infos_ziel( $termin_id, (array) get_post_meta( $termin_id, '_dtrv_felder', true ) );
			if ( self::dankes_mail( $id, '' !== $link ? $link : home_url( '/' ) ) ) {
				delete_post_meta( $id, '_dtrv_email' );
			} else {
				delete_post_meta( $id, '_dtrv_dank_gesendet' ); // beim nächsten Abgleich erneut
			}
		}
	}

	public static function mail_neu( int $id ): void {
		$d     = self::daten( $id );
		$titel = get_post_field( 'post_title', $id );
		$zeilen = array(
			'Neuer Terminvorschlag über ' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'',
			'Titel:      ' . $titel,
			'Kategorie:  ' . ( Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'] ),
			'Datum:      ' . self::datum_text( $d ),
			'Ort:        ' . $d['ort'],
		);
		if ( $d['klasse'] ) {
			$zeilen[] = 'Klasse:     ' . $d['klasse'] . ' (Art | Höhenmeter pro km)';
		}
		if ( $d['geo'] ) {
			$zeilen[] = 'Karte:      ' . Geo::osm_link( $d['geo']['lat'], $d['geo']['lng'] );
		}
		if ( $d['karte'] ) {
			$zeilen[] = 'Kartenlink: ' . $d['karte'];
		}
		if ( $d['infos'] ) {
			$zeilen[] = 'Infos:      ' . $d['infos'];
		}
		if ( $d['anmeldung'] ) {
			$zeilen[] = 'Anmeldung:  ' . $d['anmeldung'];
		}
		if ( $d['bild'] ) {
			$zeilen[] = 'Bild:       ' . wp_get_attachment_url( $d['bild'] );
		}
		if ( '' !== trim( $d['beschreibung'] ) ) {
			$zeilen[] = '';
			$zeilen[] = 'Beschreibung:';
			$zeilen[] = $d['beschreibung'];
		}
		array_push(
			$zeilen,
			'',
			'Eingereicht von: ' . $d['name'] . ' <' . $d['email'] . '>',
			'',
			'Annehmen:  ' . self::link( $id, 'freigeben' ),
			'Ablehnen:  ' . self::link( $id, 'ablehnen' ),
			'',
			'Im WordPress-Admin: ' . admin_url( 'post.php?post=' . $id . '&action=edit' )
		);

		$kopf = array();
		if ( is_email( $d['email'] ) ) {
			$kopf[] = 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>', '"' ), '', $d['name'] ) . ' <' . $d['email'] . '>';
		}
		$ergebnis = self::senden( Settings::vorschlag_email(), '[DTRV] Neuer Terminvorschlag: ' . $titel, implode( "\n", $zeilen ), $kopf );
		update_post_meta( $id, '_dtrv_mail', $ergebnis );
	}

	/**
	 * wp_mail mit Fehlermeldung. „ok“ heißt nur, dass WordPress die Mail an den
	 * Server übergeben hat – nicht, dass sie angekommen ist (Spam-Ordner!).
	 *
	 * @return string „ok“ oder die Fehlermeldung.
	 */
	public static function senden( string $an, string $betreff, string $text, array $kopf = array() ): string {
		$fehler = '';
		$merken = static function ( \WP_Error $e ) use ( &$fehler ) {
			$fehler = $e->get_error_message();
		};
		add_action( 'wp_mail_failed', $merken );
		$ok = wp_mail( $an, $betreff, $text, $kopf );
		remove_action( 'wp_mail_failed', $merken );
		if ( $ok ) {
			return 'ok';
		}
		return '' !== $fehler ? $fehler : 'wp_mail() meldet einen Fehler.';
	}

	/** @return bool true, wenn versendet oder keine Adresse vorhanden ist. */
	private static function dankes_mail( int $id, string $link ): bool {
		$d = self::daten( $id );
		if ( ! is_email( $d['email'] ) ) {
			return true;
		}
		$text = sprintf(
			"Hallo %s,\n\nvielen Dank für deinen Terminvorschlag „%s“ (%s). Er ist jetzt auf der Website des Deutschen Tretroller Verbands zu finden:\n%s\n\nSportliche Grüße\nDeutscher Tretroller Verband e.V.",
			$d['name'],
			get_post_field( 'post_title', $id ),
			self::datum_text( $d ),
			$link
		);
		return 'ok' === self::senden( $d['email'], 'Dein Termin ist online', $text );
	}

	/**
	 * Aufruf eines Freigabe-Links aus der Mail. GET zeigt nur eine Bestätigungsseite,
	 * erst der Knopf (POST) ändert den Status – Mail-Scanner rufen Links automatisch auf.
	 */
	public static function link_aufruf(): void {
		if ( ! isset( $_GET['dtrv_vorschlag'], $_GET['aktion'], $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id     = absint( $_GET['dtrv_vorschlag'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$aktion = sanitize_key( $_GET['aktion'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$token  = (string) wp_unslash( $_GET['token'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$soll   = (string) get_post_meta( $id, '_dtrv_token', true );
		$ziele  = array(
			'freigeben' => 'freigegeben',
			'ablehnen'  => 'abgelehnt',
		);

		if ( self::NAME !== get_post_type( $id ) || '' === $soll || ! hash_equals( $soll, $token ) || ! isset( $ziele[ $aktion ] ) ) {
			wp_die( 'Dieser Link ist ungültig.', 'Terminvorschlag', array( 'response' => 403 ) );
		}

		$titel = esc_html( get_post_field( 'post_title', $id ) );
		$d     = self::daten( $id );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( 'freigeben' === $aktion && Google::konfiguriert() ) {
				$ergebnis = self::annehmen( $id );
				$html     = is_wp_error( $ergebnis )
					? '<h1>Nicht angelegt</h1><p>' . esc_html( $ergebnis->get_error_message() ) . '</p>'
					: '<h1>Angenommen</h1><p>„' . $titel . '“ steht jetzt im Kalender <strong>' . esc_html( Plugin::kategorien()[ $d['kategorie'] ] ?? '' ) . '</strong>.</p>'
						. '<p>Sobald der Abgleich ihn auf die Website geholt hat, bekommt der Einreicher automatisch eine Dankes-Mail mit Link zum Termin.</p>';
			} elseif ( 'freigeben' === $aktion ) {
				self::status_setzen( $id, 'freigegeben' );
				$html = '<h1>Angenommen</h1><p>„' . $titel . '“ ist als angenommen markiert.</p>'
					. '<p>Jetzt noch in den Kalender <strong>' . esc_html( Plugin::kategorien()[ $d['kategorie'] ] ?? '' ) . '</strong> eintragen (oben links den Kalender auswählen, Titel nicht ändern):</p>'
					. '<p><a class="button" href="' . esc_url( self::google_link( $id ) ) . '" target="_blank" rel="noopener">Eintragen</a></p>'
					. '<p>Sobald der Termin auf der Website steht, bekommt der Einreicher automatisch eine Dankes-Mail.</p>';
			} else {
				self::status_setzen( $id, $ziele[ $aktion ] );
				$html = '<h1>Abgelehnt</h1><p>„' . $titel . '“ ist abgelehnt und archiviert. Kontaktdaten und Bild wurden gelöscht.</p>';
			}
			wp_die( $html, 'Terminvorschlag', array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$frage  = 'freigeben' === $aktion ? 'Vorschlag annehmen?' : 'Vorschlag ablehnen?';
		$knopf  = 'freigeben' === $aktion ? 'Ja, annehmen' : 'Ja, ablehnen';
		$hinweis = 'neu' !== $d['status'] ? '<p><em>Aktueller Status: ' . esc_html( self::STATUS[ $d['status'] ] ?? $d['status'] ) . '</em></p>' : '';
		$html   = '<h1>' . esc_html( $frage ) . '</h1>' . $hinweis . self::zusammenfassung( $id )
			. '<form method="post"><p><button type="submit" class="button">' . esc_html( $knopf ) . '</button></p></form>';
		wp_die( $html, 'Terminvorschlag', array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Tabelle mit allen Angaben (für Bestätigungsseite und Admin). */
	public static function zusammenfassung( int $id, bool $kontakt = false ): string {
		$d      = self::daten( $id );
		$zeilen = array(
			'Titel'     => esc_html( get_post_field( 'post_title', $id ) ),
			'Kategorie' => esc_html( Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'] ),
			'Datum'     => esc_html( self::datum_text( $d ) ),
			'Ort'       => esc_html( $d['ort'] ),
		);
		if ( $d['klasse'] ) {
			$zeilen['Klasse'] = esc_html( $d['klasse'] );
		}
		if ( $d['geo'] ) {
			$zeilen['Karte'] = '<a href="' . esc_url( Geo::osm_link( $d['geo']['lat'], $d['geo']['lng'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $d['geo']['lat'] . ', ' . $d['geo']['lng'] ) . '</a>';
		} else {
			$zeilen['Karte'] = 'keine Koordinaten – beim Eintragen wird der Ort automatisch gesucht';
		}
		if ( $d['karte'] ) {
			$zeilen['Kartenlink'] = '<a href="' . esc_url( $d['karte'] ) . '" target="_blank" rel="noopener">' . esc_html( $d['karte'] ) . '</a>';
		}
		foreach ( array( 'infos' => 'Infos', 'anmeldung' => 'Anmeldung' ) as $feld => $label ) {
			if ( $d[ $feld ] ) {
				$zeilen[ $label ] = '<a href="' . esc_url( $d[ $feld ] ) . '" target="_blank" rel="noopener">' . esc_html( $d[ $feld ] ) . '</a>';
			}
		}
		if ( '' !== trim( $d['beschreibung'] ) ) {
			$zeilen['Beschreibung'] = nl2br( esc_html( $d['beschreibung'] ) );
		}
		if ( $d['bild'] ) {
			$zeilen['Bild'] = '<a href="' . esc_url( wp_get_attachment_url( $d['bild'] ) ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $d['bild'], 'medium' ) . '</a>';
		}
		if ( $kontakt ) {
			$zeilen['Eingereicht von'] = esc_html( $d['name'] ) . ( $d['email'] ? ' &lt;<a href="mailto:' . esc_attr( $d['email'] ) . '">' . esc_html( $d['email'] ) . '</a>&gt;' : '' );
		}

		$html = '<table class="widefat striped" style="max-width:50rem"><tbody>';
		foreach ( $zeilen as $label => $wert ) {
			$html .= '<tr><th style="width:9em;text-align:left;vertical-align:top">' . esc_html( $label ) . '</th><td>' . $wert . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	public static function spalten( array $spalten ): array {
		return array(
			'cb'         => $spalten['cb'],
			'title'      => 'Titel',
			'termin'     => 'Termin',
			'kategorie'  => 'Kategorie',
			'einreicher' => 'Eingereicht von',
			'mail'       => 'Mail',
			'vstatus'    => 'Status',
			'date'       => 'Eingang',
		);
	}

	public static function spalte( string $spalte, int $id ): void {
		$d = self::daten( $id );
		switch ( $spalte ) {
			case 'termin':
				echo esc_html( self::datum_text( $d ) . ( $d['ort'] ? ', ' . $d['ort'] : '' ) );
				echo $d['bild'] ? ' <span title="mit Bild">🖼</span>' : '';
				break;
			case 'kategorie':
				echo esc_html( Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'] );
				break;
			case 'einreicher':
				echo esc_html( $d['name'] );
				break;
			case 'mail':
				$mail = (string) get_post_meta( $id, '_dtrv_mail', true );
				if ( 'ok' === $mail ) {
					echo '<span title="WordPress hat die Mail an den Mailserver übergeben">versendet</span>';
				} elseif ( '' !== $mail ) {
					echo '<strong style="color:#b32d2e" title="' . esc_attr( $mail ) . '">Fehler</strong><br><small>' . esc_html( $mail ) . '</small>';
				} else {
					echo '–';
				}
				break;
			case 'vstatus':
				echo esc_html( self::STATUS[ $d['status'] ] ?? $d['status'] );
				break;
		}
	}

	private static function status_url( int $id, string $status ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=dtrv_vorschlag_status&id=' . $id . '&status=' . $status ),
			'dtrv_vorschlag_status_' . $id
		);
	}

	/**
	 * Aktionen eines Vorschlags in fester Reihenfolge:
	 * Eintragen (öffnet Google Kalender, markiert als freigegeben) · Erledigt · Ablehnen.
	 *
	 * @return array<string, array{label: string, url: string, neues_fenster: bool}>
	 */
	private static function aktionen( int $id ): array {
		$status   = self::daten( $id )['status'];
		$aktionen = array();
		$angelegt = (bool) get_post_meta( $id, '_dtrv_google_id', true );
		if ( Google::konfiguriert() ) {
			if ( 'neu' === $status || ( 'freigegeben' === $status && ! $angelegt ) ) {
				$aktionen['annehmen'] = array(
					'label'         => 'Annehmen',
					'url'           => self::status_url( $id, 'annehmen' ),
					'neues_fenster' => false,
				);
			}
		} elseif ( in_array( $status, array( 'neu', 'freigegeben' ), true ) ) {
			$aktionen['google'] = array(
				'label'         => 'Eintragen',
				'url'           => self::status_url( $id, 'google' ),
				'neues_fenster' => true,
			);
		}
		// Nur für Sonderfälle (Titel in Google stark geändert): ohne Dankes-Mail.
		if ( 'freigegeben' === $status && ! $angelegt ) {
			$aktionen['eingetragen'] = array(
				'label'         => 'Erledigt (ohne Dankes-Mail)',
				'url'           => self::status_url( $id, 'eingetragen' ),
				'neues_fenster' => false,
			);
		}
		if ( 'abgelehnt' !== $status ) {
			$aktionen['abgelehnt'] = array(
				'label'         => 'Ablehnen',
				'url'           => self::status_url( $id, 'abgelehnt' ),
				'neues_fenster' => false,
			);
		}
		return $aktionen;
	}

	public static function zeilen_aktionen( array $aktionen, \WP_Post $post ): array {
		if ( self::NAME !== $post->post_type ) {
			return $aktionen;
		}
		unset( $aktionen['inline hide-if-no-js'] );
		foreach ( self::aktionen( $post->ID ) as $schluessel => $a ) {
			$aktionen[ 'dtrv_' . $schluessel ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $a['url'] ),
				$a['neues_fenster'] ? ' target="_blank" rel="noopener" onclick="setTimeout(function(){location.reload();},1500)"' : '',
				esc_html( $a['label'] )
			);
		}
		$aktionen['dtrv_mail'] = '<a href="' . esc_url( self::status_url( $post->ID, 'mail' ) ) . '">Mail erneut senden</a>';
		return $aktionen;
	}

	public static function status_knopf(): void {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'dtrv_vorschlag_status_' . $id );
		if ( self::NAME !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$status = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'annehmen' === $status ) {
			$ergebnis = self::annehmen( $id );
			if ( is_wp_error( $ergebnis ) ) {
				wp_die( esc_html( $ergebnis->get_error_message() ), 'Annehmen fehlgeschlagen', array( 'back_link' => true ) );
			}
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . self::NAME ) );
			exit;
		}
		if ( 'google' === $status ) {
			// „Eintragen“ (ohne Google-Zugang): als angenommen markieren und Google vorausgefüllt öffnen.
			if ( 'neu' === self::daten( $id )['status'] ) {
				self::status_setzen( $id, 'freigegeben' );
			}
			wp_redirect( self::google_link( $id ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- feste Google-Adresse
			exit;
		}
		if ( 'mail' === $status ) {
			self::mail_neu( $id );
		} else {
			self::status_setzen( $id, $status );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . self::NAME ) );
		exit;
	}

	public static function meta_box(): void {
		remove_meta_box( 'submitdiv', self::NAME, 'side' );
		add_meta_box(
			'dtrv-vorschlag',
			'Vorschlag',
			static function ( \WP_Post $post ) {
				$d = self::daten( $post->ID );
				echo '<p>Status: <strong>' . esc_html( self::STATUS[ $d['status'] ] ?? $d['status'] ) . '</strong></p>';
				echo self::zusammenfassung( $post->ID, true ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p style="margin-top:1em">';
				foreach ( self::aktionen( $post->ID ) as $schluessel => $a ) {
					printf(
						'<a class="button%s" href="%s"%s>%s</a> ',
						'google' === $schluessel ? ' button-primary' : '',
						esc_url( $a['url'] ),
						$a['neues_fenster'] ? ' target="_blank" rel="noopener" onclick="setTimeout(function(){location.reload();},1500)"' : '',
						esc_html( $a['label'] )
					);
				}
				$hilfe = Google::konfiguriert()
					? '<strong>Annehmen</strong> legt den Termin direkt im passenden Google Kalender an. '
					: '<strong>Eintragen</strong> öffnet Google Kalender mit allen Angaben (Kalender oben links wählen, Titel nicht ändern, speichern). ';
				echo '</p><p class="description">' . $hilfe // phpcs:ignore WordPress.Security.EscapeOutput
					. 'Sobald der Abgleich den Termin auf die Website geholt hat, bekommt der Einreicher automatisch eine Dankes-Mail mit Link zum Termin; danach wird seine E-Mail-Adresse gelöscht. '
					. '<strong>Ablehnen</strong> löscht E-Mail-Adresse und Bild.</p>';
			},
			self::NAME,
			'normal',
			'high'
		);
	}

	/** Zahl neuer Vorschläge als Blase im Menü. */
	public static function zaehler(): void {
		global $submenu;
		$eltern = 'edit.php?post_type=' . PostType::NAME;
		if ( empty( $submenu[ $eltern ] ) ) {
			return;
		}
		$neu = count(
			get_posts(
				array(
					'post_type'      => self::NAME,
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'meta_key'       => '_dtrv_vstatus', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => 'neu', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
		if ( ! $neu ) {
			return;
		}
		foreach ( $submenu[ $eltern ] as &$eintrag ) {
			if ( 'edit.php?post_type=' . self::NAME === $eintrag[2] ) {
				$eintrag[0] .= ' <span class="awaiting-mod">' . $neu . '</span>';
			}
		}
	}
}
