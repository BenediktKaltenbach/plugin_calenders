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
		'freigegeben' => 'Freigegeben',
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
		$tz = Format::zeitzone();
		if ( $d['zeit_von'] ) {
			$s     = new \DateTimeImmutable( $d['von'] . ' ' . $d['zeit_von'], $tz );
			$e     = $d['zeit_bis']
				? new \DateTimeImmutable( ( $d['bis'] ? $d['bis'] : $d['von'] ) . ' ' . $d['zeit_bis'], $tz )
				: $s->modify( '+2 hours' );
			$dates = $s->format( 'Ymd\THis' ) . '/' . $e->format( 'Ymd\THis' );
		} else {
			$s     = new \DateTimeImmutable( $d['von'], $tz );
			$e     = ( new \DateTimeImmutable( $d['bis'] ? $d['bis'] : $d['von'], $tz ) )->modify( '+1 day' );
			$dates = $s->format( 'Ymd' ) . '/' . $e->format( 'Ymd' );
		}

		$text = trim( $d['beschreibung'] );
		if ( '' !== $bild ) {
			$text .= ( '' !== $text ? "\n\n" : '' ) . 'Bild vom Einreicher: ' . $bild;
		}
		$zeilen = array();
		if ( '' !== $d['infos'] ) {
			$zeilen[] = 'Infos: ' . $d['infos'];
		}
		if ( '' !== $d['anmeldung'] ) {
			$zeilen[] = 'Anmeldung: ' . $d['anmeldung'];
		}
		if ( $zeilen ) {
			$text .= "\n\n---\n" . implode( "\n", $zeilen );
		}

		return 'https://calendar.google.com/calendar/render?' . http_build_query(
			array(
				'action'   => 'TEMPLATE',
				'text'     => $titel,
				'dates'    => $dates,
				'ctz'      => Format::ZEITZONE,
				'location' => $d['ort'],
				'details'  => $text,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public static function google_link( int $id ): string {
		$d    = self::daten( $id );
		$bild = $d['bild'] ? (string) wp_get_attachment_url( $d['bild'] ) : '';
		return self::google_url( $d, get_the_title( $id ), $bild );
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
		if ( 'eingetragen' === $status ) {
			self::dankes_mail( $id );
			delete_post_meta( $id, '_dtrv_email' );
		}
	}

	public static function mail_neu( int $id ): void {
		$d     = self::daten( $id );
		$titel = get_the_title( $id );
		$zeilen = array(
			'Neuer Terminvorschlag über ' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'',
			'Titel:      ' . $titel,
			'Kategorie:  ' . ( Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'] ),
			'Datum:      ' . self::datum_text( $d ),
			'Ort:        ' . $d['ort'],
		);
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
			'Freigeben: ' . self::link( $id, 'freigeben' ),
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

	private static function dankes_mail( int $id ): void {
		$d = self::daten( $id );
		if ( ! is_email( $d['email'] ) ) {
			return;
		}
		$text = sprintf(
			"Hallo %s,\n\nvielen Dank für deinen Terminvorschlag „%s“ (%s). Er ist jetzt im Kalender des Deutschen Tretroller Verbands eingetragen und erscheint in Kürze auf %s.\n\nSportliche Grüße\nDeutscher Tretroller Verband e.V.",
			$d['name'],
			get_the_title( $id ),
			self::datum_text( $d ),
			home_url( '/' )
		);
		self::senden( $d['email'], 'Dein Terminvorschlag ist eingetragen', $text );
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

		$titel = esc_html( get_the_title( $id ) );
		$d     = self::daten( $id );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::status_setzen( $id, $ziele[ $aktion ] );
			if ( 'freigeben' === $aktion ) {
				$html = '<h1>Freigegeben</h1><p>„' . $titel . '“ ist als freigegeben markiert.</p>'
					. '<p>Jetzt noch in den Kalender <strong>' . esc_html( Plugin::kategorien()[ $d['kategorie'] ] ?? '' ) . '</strong> eintragen (oben links den Kalender auswählen):</p>'
					. '<p><a class="button" href="' . esc_url( self::google_link( $id ) ) . '" target="_blank" rel="noopener">In Google Kalender anlegen</a></p>'
					. '<p>Danach im WordPress-Admin unter Termine › Vorschläge „Eingetragen“ wählen, dann bekommt der Einreicher eine Dankes-Mail.</p>';
			} else {
				$html = '<h1>Abgelehnt</h1><p>„' . $titel . '“ ist abgelehnt und archiviert. Kontaktdaten und Bild wurden gelöscht.</p>';
			}
			wp_die( $html, 'Terminvorschlag', array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$frage  = 'freigeben' === $aktion ? 'Vorschlag freigeben?' : 'Vorschlag ablehnen?';
		$knopf  = 'freigeben' === $aktion ? 'Ja, freigeben' : 'Ja, ablehnen';
		$hinweis = 'neu' !== $d['status'] ? '<p><em>Aktueller Status: ' . esc_html( self::STATUS[ $d['status'] ] ?? $d['status'] ) . '</em></p>' : '';
		$html   = '<h1>' . esc_html( $frage ) . '</h1>' . $hinweis . self::zusammenfassung( $id )
			. '<form method="post"><p><button type="submit" class="button">' . esc_html( $knopf ) . '</button></p></form>';
		wp_die( $html, 'Terminvorschlag', array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Tabelle mit allen Angaben (für Bestätigungsseite und Admin). */
	public static function zusammenfassung( int $id, bool $kontakt = false ): string {
		$d      = self::daten( $id );
		$zeilen = array(
			'Titel'     => esc_html( get_the_title( $id ) ),
			'Kategorie' => esc_html( Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'] ),
			'Datum'     => esc_html( self::datum_text( $d ) ),
			'Ort'       => esc_html( $d['ort'] ),
		);
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

	public static function zeilen_aktionen( array $aktionen, \WP_Post $post ): array {
		if ( self::NAME !== $post->post_type ) {
			return $aktionen;
		}
		unset( $aktionen['inline hide-if-no-js'] );
		$status = self::daten( $post->ID )['status'];
		foreach ( array( 'freigegeben' => 'Freigeben', 'eingetragen' => 'Eingetragen', 'abgelehnt' => 'Ablehnen' ) as $ziel => $label ) {
			if ( $ziel !== $status ) {
				$aktionen[ 'dtrv_' . $ziel ] = '<a href="' . esc_url( self::status_url( $post->ID, $ziel ) ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		$aktionen['dtrv_google'] = '<a href="' . esc_url( self::google_link( $post->ID ) ) . '" target="_blank" rel="noopener">In Google Kalender anlegen</a>';
		$aktionen['dtrv_mail']   = '<a href="' . esc_url( self::status_url( $post->ID, 'mail' ) ) . '">Mail erneut senden</a>';
		return $aktionen;
	}

	public static function status_knopf(): void {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'dtrv_vorschlag_status_' . $id );
		if ( self::NAME !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$status = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
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
				printf( '<a class="button button-primary" href="%s" target="_blank" rel="noopener">In Google Kalender anlegen</a> ', esc_url( self::google_link( $post->ID ) ) );
				foreach ( array( 'freigegeben' => 'Freigeben', 'eingetragen' => 'Eingetragen (Dankes-Mail senden)', 'abgelehnt' => 'Ablehnen' ) as $ziel => $label ) {
					if ( $ziel !== $d['status'] ) {
						printf( '<a class="button" href="%s">%s</a> ', esc_url( self::status_url( $post->ID, $ziel ) ), esc_html( $label ) );
					}
				}
				echo '</p><p class="description">„Eingetragen“ und „Ablehnen“ löschen die E-Mail-Adresse des Einreichers. Ablehnen löscht auch das Bild.</p>';
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
