<?php
/**
 * Öffentliches Formular „Termin vorschlagen“ (Block und Shortcode).
 *
 * Spam-Schutz ohne reCAPTCHA: Honigtopf-Feld, Mindest-Ausfüllzeit
 * (signierter Zeitstempel), höchstens 5 Vorschläge pro IP und Stunde.
 */

namespace DTRV\Termine;

final class Formular {

	public const OPTION_SPAM   = 'dtrv_termine_spam';
	/** Name des Fallenfelds: bewusst ohne Bedeutung, damit Browser-Autofill es nicht ausfüllt. */
	private const FALLE        = 'dtrv_xf7';
	private const MINDESTZEIT  = 5;
	private const HOECHSTALTER = DAY_IN_SECONDS;
	private const IP_LIMIT     = 5;
	/** Obergrenze für Bilder; kleiner, wenn der Server weniger erlaubt. */
	private const BILD_MAX_MB  = 15;
	private const BILD_TYPEN   = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/** Fehler und Eingaben nach einem fehlgeschlagenen Absenden (gleicher Request). */
	private static array $fehler = array();
	private static array $werte  = array();
	/** Zeitstempel des ersten Anzeigens, bleibt beim erneuten Anzeigen nach Fehlern erhalten. */
	private static string $token = '';
	/** Upload war größer als post_max_size: PHP liefert dann ein leeres $_POST. */
	private static bool $zu_gross = false;

	/** Art der Veranstaltung (nur Breitensport; Wettkampf ist immer „W“). */
	private const ARTEN = array(
		'F' => 'F – Freizeit',
		'A' => 'A – Ambitioniert',
	);

	/** Bergfaktor = Höhenmeter pro Kilometer (Wettkampf und Breitensport). */
	private const BERGFAKTOR = array(
		'0-5'   => '0–5 Höhenmeter pro km (flach)',
		'5-10'  => '5–10 Höhenmeter pro km',
		'10-15' => '10–15 Höhenmeter pro km',
		'15-20' => '15–20 Höhenmeter pro km',
		'20+'   => 'über 20 Höhenmeter pro km',
	);

	private const KATEGORIE_HINWEIS = array(
		'wettkampf'    => 'Rennen, Meisterschaften, Eurocups',
		'breitensport' => 'Touren, autofreie Tage, CTF/RTF, Challenges',
		'ausbildung'   => 'Kurse und Fortbildungen',
		'verband'      => 'Vereinsleben, Aktionen',
	);

	public static function shortcode(): string {
		return self::html();
	}

	/** Verarbeitet das Absenden (template_redirect, vor der Ausgabe der Seite). */
	public static function verarbeiten(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_POST ) && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 && str_contains( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ), 'multipart/form-data' ) ) {
			self::$zu_gross = true;
			return;
		}
		if ( empty( $_POST['dtrv_vorschlag_formular'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$zurueck = is_singular() ? (string) get_permalink( get_queried_object_id() ) : home_url( '/' );
		$danke   = add_query_arg( 'vorschlag', 'danke', $zurueck ) . '#dtrv-vorschlag';

		// Bots füllen das versteckte Feld aus oder sind zu schnell: so tun, als hätte es geklappt.
		$zeit  = self::zeitstempel_alter( (string) wp_unslash( $_POST['dtrv_t'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$grund = '';
		if ( '' !== (string) ( $_POST[ self::FALLE ] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$grund = 'falle';
		} elseif ( null === $zeit ) {
			$grund = 'ungueltig';
		} elseif ( $zeit < self::MINDESTZEIT ) {
			$grund = 'zu_schnell';
		}
		if ( $grund ) {
			$spam             = (array) get_option( self::OPTION_SPAM, array() );
			$spam[ $grund ]   = (int) ( $spam[ $grund ] ?? 0 ) + 1;
			$spam['zuletzt']  = time();
			update_option( self::OPTION_SPAM, $spam, false );
			wp_safe_redirect( $danke );
			exit;
		}

		self::$werte  = self::werte();
		self::$fehler = self::pruefen( self::$werte );
		if ( $zeit > self::HOECHSTALTER ) {
			self::$fehler['_'] = 'Das Formular war zu lange geöffnet. Bitte noch einmal absenden.';
		} else {
			// Nach einem Fehler nicht erneut 5 Sekunden Mindestzeit verlangen.
			self::$token = (string) wp_unslash( $_POST['dtrv_t'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! self::$fehler && self::ip_zaehler() >= self::IP_LIMIT ) {
			self::$fehler['_'] = 'Es wurden in kurzer Zeit zu viele Vorschläge gesendet. Bitte später erneut versuchen.';
		}
		if ( self::$fehler ) {
			return;
		}

		$id = self::speichern( self::$werte );
		if ( is_wp_error( $id ) ) {
			self::$fehler['bild'] = $id->get_error_message();
			return;
		}

		self::ip_zaehler( true );
		Vorschlaege::mail_neu( $id );
		wp_safe_redirect( $danke );
		exit;
	}

	private static function werte(): array {
		// phpcs:disable WordPress.Security.NonceVerification
		$text = static fn( string $k ) => sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );
		return array(
			'titel'        => $text( 'titel' ),
			'kategorie'    => sanitize_key( $_POST['kategorie'] ?? '' ),
			'von'          => $text( 'von' ),
			'bis'          => $text( 'bis' ),
			'zeit_von'     => $text( 'zeit_von' ),
			'zeit_bis'     => $text( 'zeit_bis' ),
			'ort'          => $text( 'ort' ),
			'art'          => strtoupper( $text( 'art' ) ),
			'bergfaktor'   => $text( 'bergfaktor' ),
			'infos'        => esc_url_raw( $text( 'infos' ), array( 'http', 'https' ) ),
			'anmeldung'    => esc_url_raw( $text( 'anmeldung' ), array( 'http', 'https' ) ),
			'beschreibung' => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
			'name'         => $text( 'name' ),
			'email'        => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'datenschutz'  => ! empty( $_POST['datenschutz'] ),
		);
		// phpcs:enable
	}

	private static function pruefen( array $w ): array {
		$f     = array();
		$datum = static fn( string $s ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) && checkdate( (int) substr( $s, 5, 2 ), (int) substr( $s, 8, 2 ), (int) substr( $s, 0, 4 ) );
		$uhr   = static fn( string $s ) => (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $s );
		$heute = ( new \DateTimeImmutable( 'now', Format::zeitzone() ) )->format( 'Y-m-d' );

		if ( '' === $w['titel'] || mb_strlen( $w['titel'] ) > 150 ) {
			$f['titel'] = 'Bitte einen Namen der Veranstaltung angeben.';
		}
		if ( ! isset( Plugin::kategorien()[ $w['kategorie'] ] ) ) {
			$f['kategorie'] = 'Bitte eine Kategorie wählen.';
		}
		if ( ! $datum( $w['von'] ) ) {
			$f['von'] = 'Bitte ein gültiges Datum angeben.';
		} elseif ( $w['von'] < $heute ) {
			$f['von'] = 'Das Datum liegt in der Vergangenheit.';
		}
		if ( '' !== $w['bis'] && ( ! $datum( $w['bis'] ) || $w['bis'] < $w['von'] ) ) {
			$f['bis'] = 'Das Enddatum muss am oder nach dem Startdatum liegen.';
		}
		if ( '' !== $w['zeit_von'] && ! $uhr( $w['zeit_von'] ) ) {
			$f['zeit_von'] = 'Bitte eine Uhrzeit wie 10:00 angeben.';
		}
		if ( '' !== $w['zeit_bis'] && ( ! $uhr( $w['zeit_bis'] ) || '' === $w['zeit_von'] ) ) {
			$f['zeit_bis'] = 'Eine Endzeit geht nur zusammen mit einer Startzeit.';
		}
		if ( '' === $w['ort'] ) {
			$f['ort'] = 'Bitte einen Ort angeben.';
		}
		if ( 'breitensport' === $w['kategorie'] && ! isset( self::ARTEN[ $w['art'] ] ) ) {
			$f['art'] = 'Bitte wählen: Freizeit oder Ambitioniert.';
		}
		if ( '' !== $w['bergfaktor'] && ! isset( self::BERGFAKTOR[ $w['bergfaktor'] ] ) ) {
			$f['bergfaktor'] = 'Bitte einen Bergfaktor aus der Liste wählen.';
		}
		foreach ( array( 'infos', 'anmeldung' ) as $feld ) {
			if ( '' !== trim( (string) ( $_POST[ $feld ] ?? '' ) ) && '' === $w[ $feld ] ) { // phpcs:ignore WordPress.Security.NonceVerification
				$f[ $feld ] = 'Bitte eine vollständige Adresse mit https:// angeben.';
			}
		}
		if ( mb_strlen( $w['beschreibung'] ) > 3000 ) {
			$f['beschreibung'] = 'Die Beschreibung ist zu lang (höchstens 3000 Zeichen).';
		}
		if ( '' === $w['name'] ) {
			$f['name'] = 'Bitte deinen Namen angeben.';
		}
		if ( ! is_email( $w['email'] ) ) {
			$f['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
		}
		if ( ! $w['datenschutz'] ) {
			$f['datenschutz'] = 'Bitte der Verarbeitung zustimmen.';
		}

		$bild = $_FILES['bild'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $bild && UPLOAD_ERR_NO_FILE !== (int) $bild['error'] ) {
			$typ = wp_check_filetype( (string) $bild['name'], self::BILD_TYPEN );
			if ( UPLOAD_ERR_OK !== (int) $bild['error'] || (int) $bild['size'] > self::bild_max_mb() * MB_IN_BYTES ) {
				$f['bild'] = 'Das Bild konnte nicht hochgeladen werden (höchstens ' . self::bild_max_mb() . ' MB).';
			} elseif ( ! $typ['type'] ) {
				$f['bild'] = 'Bitte ein Bild als JPG, PNG oder WebP hochladen.';
			}
		}
		return $f;
	}

	/** Klasse wie im Kalender, z. B. „F | 0-5“; Wettkampf automatisch „W“, sonst leer. */
	private static function klasse( array $w ): string {
		$berg = '' !== $w['bergfaktor'] ? $w['bergfaktor'] : '?';
		switch ( $w['kategorie'] ) {
			case 'wettkampf':
				return 'W | ' . $berg;
			case 'breitensport':
				return $w['art'] . ' | ' . $berg;
		}
		return '';
	}

	/** Erlaubte Bildgröße in MB: höchstens BILD_MAX_MB, aber nie mehr als der Server annimmt. */
	private static function bild_max_mb(): int {
		return max( 1, min( self::BILD_MAX_MB, (int) floor( wp_max_upload_size() / MB_IN_BYTES ) ) );
	}

	/** @return int|\WP_Error ID des Vorschlags. */
	private static function speichern( array $w ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => Vorschlaege::NAME,
					'post_status'  => 'publish',
					'post_title'   => $w['titel'],
					'post_content' => $w['beschreibung'],
					'meta_input'   => array(
						'_dtrv_kategorie' => $w['kategorie'],
						'_dtrv_von'       => $w['von'],
						'_dtrv_bis'       => $w['bis'] === $w['von'] ? '' : $w['bis'],
						'_dtrv_zeit_von'  => $w['zeit_von'],
						'_dtrv_zeit_bis'  => $w['zeit_bis'],
						'_dtrv_ort'       => $w['ort'],
						'_dtrv_klasse'    => self::klasse( $w ),
						'_dtrv_infos'     => $w['infos'],
						'_dtrv_anmeldung' => $w['anmeldung'],
						'_dtrv_name'      => $w['name'],
						'_dtrv_email'     => $w['email'],
						'_dtrv_vstatus'   => 'neu',
						'_dtrv_token'     => wp_generate_password( 32, false ),
					),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$bild = $_FILES['bild'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $bild && UPLOAD_ERR_NO_FILE !== (int) $bild['error'] ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$anhang = media_handle_upload(
				'bild',
				$id,
				array( 'post_title' => $w['titel'] ),
				array(
					'test_form' => false,
					'mimes'     => self::BILD_TYPEN,
				)
			);
			if ( is_wp_error( $anhang ) ) {
				wp_delete_post( $id, true );
				return new \WP_Error( 'bild', 'Das Bild konnte nicht gespeichert werden: ' . $anhang->get_error_message() );
			}
			update_post_meta( $id, '_dtrv_bild', $anhang );
		}
		return $id;
	}

	/** Zählt Vorschläge je IP (gehasht, 1 Stunde). */
	private static function ip_zaehler( bool $erhoehen = false ): int {
		$ip     = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key    = 'dtrv_vorschlag_' . md5( $ip . wp_salt( 'nonce' ) );
		$anzahl = (int) get_transient( $key );
		if ( $erhoehen ) {
			set_transient( $key, ++$anzahl, HOUR_IN_SECONDS );
		}
		return $anzahl;
	}

	private static function zeitstempel(): string {
		$t = (string) time();
		return $t . '.' . hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) );
	}

	/** Sekunden seit dem Anzeigen des Formulars, null bei ungültigem Wert. */
	private static function zeitstempel_alter( string $wert ): ?int {
		[ $t, $sig ] = array_pad( explode( '.', $wert, 2 ), 2, '' );
		if ( ! ctype_digit( $t ) || ! hash_equals( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), $sig ) ) {
			return null;
		}
		return time() - (int) $t;
	}

	public static function html(): string {
		wp_enqueue_style( 'dtrv-termine' );

		if ( 'danke' === ( $_GET['vorschlag'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<div id="dtrv-vorschlag" class="dtrv-vorschlag dtrv-vorschlag--danke" role="status"><p><strong>Vielen Dank!</strong> Dein Vorschlag ist bei uns eingegangen. Wir prüfen ihn und tragen ihn dann in den Kalender ein. Sobald er online ist, bekommst du eine kurze E-Mail.</p></div>';
		}

		$w = self::$werte + array_fill_keys( array( 'titel', 'kategorie', 'von', 'bis', 'zeit_von', 'zeit_bis', 'ort', 'art', 'bergfaktor', 'infos', 'anmeldung', 'beschreibung', 'name', 'email' ), '' ) + array( 'datenschutz' => false );
		$f = self::$fehler;
		if ( self::$zu_gross ) {
			$f['_'] = 'Die Daten waren zu groß (meist ein sehr großes Foto). Bitte ein kleineres Bild wählen und erneut absenden – die übrigen Angaben müssen leider neu eingegeben werden.';
		}

		$feld = static function ( string $name, string $label, string $input, bool $pflicht = false, string $hilfe = '' ) use ( $f ): string {
			$fehler = isset( $f[ $name ] ) ? '<span class="dtrv-vorschlag__fehler" id="dtrv-f-' . $name . '-fehler">' . esc_html( $f[ $name ] ) . '</span>' : '';
			$hilfe  = $hilfe ? '<span class="dtrv-vorschlag__hilfe">' . esc_html( $hilfe ) . '</span>' : '';
			return sprintf(
				'<p class="dtrv-vorschlag__feld%s"><label for="dtrv-f-%s">%s%s</label>%s%s%s</p>',
				$fehler ? ' has-fehler' : '',
				esc_attr( $name ),
				esc_html( $label ),
				$pflicht ? ' <span aria-hidden="true">*</span>' : '',
				$input,
				$hilfe,
				$fehler
			);
		};
		$input = static function ( string $name, string $typ, bool $pflicht = false, string $extra = '' ) use ( $w, $f ): string {
			return sprintf(
				'<input type="%s" id="dtrv-f-%s" name="%s" value="%s"%s%s%s>',
				esc_attr( $typ ),
				esc_attr( $name ),
				esc_attr( $name ),
				esc_attr( $w[ $name ] ),
				$pflicht ? ' required' : '',
				isset( $f[ $name ] ) ? ' aria-invalid="true" aria-describedby="dtrv-f-' . esc_attr( $name ) . '-fehler"' : '',
				$extra
			);
		};

		$optionen = '<option value="">– bitte wählen –</option>';
		foreach ( Plugin::kategorien() as $slug => $label ) {
			$optionen .= sprintf( '<option value="%s"%s>%s (%s)</option>', esc_attr( $slug ), selected( $w['kategorie'], $slug, false ), esc_html( $label ), esc_html( self::KATEGORIE_HINWEIS[ $slug ] ) );
		}

		$heute       = ( new \DateTimeImmutable( 'now', Format::zeitzone() ) )->format( 'Y-m-d' );
		$datenschutz = get_privacy_policy_url();

		// Klein starten: nur ein Knopf, das Formular klappt auf (nach Fehlern bleibt es offen).
		$html  = '<details id="dtrv-vorschlag" class="dtrv-vorschlag-box"' . ( $f ? ' open' : '' ) . '>';
		$html .= '<summary class="dtrv-termin__knopf">Termin vorschlagen</summary>';
		$html .= '<form class="dtrv-vorschlag" method="post" enctype="multipart/form-data" action="#dtrv-vorschlag">';
		if ( isset( $f['_'] ) ) {
			$html .= '<div class="dtrv-vorschlag__meldung" role="alert">' . esc_html( $f['_'] ) . '</div>';
		} elseif ( $f ) {
			$html .= '<div class="dtrv-vorschlag__meldung" role="alert">Bitte die markierten Felder prüfen.</div>';
		}

		$html .= $feld( 'titel', 'Name der Veranstaltung', $input( 'titel', 'text', true, ' maxlength="150"' ), true );
		$html .= $feld( 'kategorie', 'Kategorie', '<select id="dtrv-f-kategorie" name="kategorie" required>' . $optionen . '</select>', true );
		$html .= '<div class="dtrv-vorschlag__reihe">'
			. $feld( 'von', 'Datum', $input( 'von', 'date', true, ' min="' . $heute . '"' ), true )
			. $feld( 'bis', 'bis (bei mehreren Tagen)', $input( 'bis', 'date', false, ' min="' . $heute . '"' ) )
			. '</div>';
		$html .= '<div class="dtrv-vorschlag__reihe">'
			. $feld( 'zeit_von', 'Uhrzeit von', $input( 'zeit_von', 'time' ), false, 'leer lassen, wenn ganztägig' )
			. $feld( 'zeit_bis', 'Uhrzeit bis', $input( 'zeit_bis', 'time' ) )
			. '</div>';
		$html .= $feld( 'ort', 'Ort', $input( 'ort', 'text', true, ' maxlength="150"' ), true, 'z. B. „Worms“ oder „Budel (NL)“' );

		// Klasse: Art nur bei Breitensport (Wettkampf ist automatisch „W“), Bergfaktor bei Wettkampf und Breitensport.
		$auswahl = static function ( string $name, array $optionen, string $leer ) use ( $w ): string {
			$html = '<select id="dtrv-f-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $leer ) . '</option>';
			foreach ( $optionen as $wert => $label ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $wert ), selected( $w[ $name ], (string) $wert, false ), esc_html( $label ) );
			}
			return $html . '</select>';
		};
		$klasse_link = esc_url( Settings::einstellungen()['klasse_link'] );
		$html       .= '<div class="dtrv-vorschlag__reihe">'
			. '<div data-kategorien="breitensport">' . $feld( 'art', 'Art der Veranstaltung', $auswahl( 'art', self::ARTEN, '– bitte wählen –' ), true, 'nur bei Breitensport' ) . '</div>'
			. '<div data-kategorien="wettkampf breitensport">' . $feld( 'bergfaktor', 'Bergfaktor', $auswahl( 'bergfaktor', self::BERGFAKTOR, '– weiß ich nicht –' ), false, 'Höhenmeter pro Kilometer, nur bei Wettkampf und Breitensport' ) . '</div>'
			. '</div>';
		$html       .= '<p class="dtrv-vorschlag__hilfe" data-kategorien="wettkampf breitensport">Erklärung der Klassen: <a href="' . $klasse_link . '" target="_blank" rel="noopener">Klassifikation von Tretrollerveranstaltungen</a>. Wettkämpfe sind immer „W“.</p>';
		$html .= $feld( 'infos', 'Link zu weiteren Infos', $input( 'infos', 'url', false, ' placeholder="https://"' ) );
		$html .= $feld( 'anmeldung', 'Link zur Anmeldung', $input( 'anmeldung', 'url', false, ' placeholder="https://"' ) );
		$html .= $feld( 'beschreibung', 'Beschreibung', '<textarea id="dtrv-f-beschreibung" name="beschreibung" rows="5" maxlength="3000">' . esc_textarea( $w['beschreibung'] ) . '</textarea>', false, 'Was erwartet die Teilnehmenden? Strecke, Länge, für wen geeignet …' );
		$html .= $feld( 'bild', 'Bild (optional)', '<input type="file" id="dtrv-f-bild" name="bild" accept="image/jpeg,image/png,image/webp" data-max-mb="' . self::bild_max_mb() . '">', false, 'JPG, PNG oder WebP, höchstens ' . self::bild_max_mb() . ' MB. Nur eigene Bilder oder solche, die du verwenden darfst.' );
		$html .= '<div class="dtrv-vorschlag__reihe">'
			. $feld( 'name', 'Dein Name', $input( 'name', 'text', true, ' autocomplete="name"' ), true )
			. $feld( 'email', 'Deine E-Mail-Adresse', $input( 'email', 'email', true, ' autocomplete="email"' ), true, 'nur für Rückfragen, wird nicht veröffentlicht' )
			. '</div>';

		$html .= sprintf(
			'<p class="dtrv-vorschlag__feld dtrv-vorschlag__check%s"><input type="checkbox" id="dtrv-f-datenschutz" name="datenschutz" value="1"%s required><label for="dtrv-f-datenschutz">Ich bin einverstanden, dass meine Angaben zur Bearbeitung des Vorschlags gespeichert werden. Meine E-Mail-Adresse wird nach der Bearbeitung gelöscht.%s *</label>%s</p>',
			isset( $f['datenschutz'] ) ? ' has-fehler' : '',
			checked( $w['datenschutz'], true, false ),
			$datenschutz ? ' <a href="' . esc_url( $datenschutz ) . '" target="_blank" rel="noopener">Datenschutzerklärung</a>' : '',
			isset( $f['datenschutz'] ) ? '<span class="dtrv-vorschlag__fehler">' . esc_html( $f['datenschutz'] ) . '</span>' : ''
		);

		// Honigtopf: für Menschen unsichtbar, Bots füllen es aus.
		$html .= '<p class="dtrv-vorschlag__honig" aria-hidden="true"><label for="dtrv-f-falle">Dieses Feld bitte leer lassen</label><input type="text" id="dtrv-f-falle" name="' . self::FALLE . '" value="" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore></p>';
		$html .= '<input type="hidden" name="dtrv_t" value="' . esc_attr( '' !== self::$token ? self::$token : self::zeitstempel() ) . '">';
		$html .= '<input type="hidden" name="dtrv_vorschlag_formular" value="1">';
		$html .= '<p><button type="submit" class="dtrv-termin__knopf">Termin vorschlagen</button></p>';
		$html .= '<p class="dtrv-vorschlag__hilfe">* Pflichtfeld</p>';
		// Felder je Kategorie ein-/ausblenden (ohne JavaScript bleiben alle sichtbar).
		$html .= '<script>(function(){var f=document.getElementById("dtrv-vorschlag");if(!f){return;}var k=f.querySelector("[name=kategorie]");'
			. 'function z(){f.querySelectorAll("[data-kategorien]").forEach(function(e){e.hidden=e.getAttribute("data-kategorien").split(" ").indexOf(k.value)<0;});}'
			. 'k.addEventListener("change",z);z();'
			// Zu große Bilder schon im Browser abfangen, bevor alles hochgeladen wird.
			. 'var b=f.querySelector("[name=bild]");if(b){b.addEventListener("change",function(){var m=parseInt(b.getAttribute("data-max-mb"),10);'
			. 'if(b.files&&b.files[0]&&b.files[0].size>m*1048576){alert("Das Bild ist größer als "+m+" MB. Bitte ein kleineres wählen.");b.value="";}});}'
			. '})();</script>';
		return $html . '</form></details>';
	}
}
