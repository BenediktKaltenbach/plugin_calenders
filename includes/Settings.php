<?php
/**
 * Einstellungsseite unter „Termine › Einstellungen“:
 * geheime iCal-Adressen je Kategorie, Abgleich-Status, „Jetzt abgleichen“.
 */

namespace DTRV\Termine;

final class Settings {

	public const OPTION            = 'dtrv_termine_einstellungen';
	public const OPTION_SCHLUESSEL = 'dtrv_termine_cron_schluessel';
	private const SEITE            = 'dtrv-termine';

	private static string $hook = '';

	public static function einstellungen(): array {
		$werte = (array) get_option( self::OPTION, array() );
		return array(
			'ical'              => (array) ( $werte['ical'] ?? array() ),
			'klasse_link'       => ! empty( $werte['klasse_link'] ) ? $werte['klasse_link'] : home_url( '/klassifikation-von-tretrollerveranstaltungen/' ),
			'vorschlag_email'   => ! empty( $werte['vorschlag_email'] ) ? $werte['vorschlag_email'] : (string) get_option( 'admin_email' ),
			'beitrag'           => (array) ( $werte['beitrag'] ?? array() ),
			'seite_eltern'      => (int) ( $werte['seite_eltern'] ?? 0 ),
			'beitragskategorie' => (array) ( $werte['beitragskategorie'] ?? array() ),
			'standardbild'      => (array) ( $werte['standardbild'] ?? array() ),
		);
	}

	/** Empfänger neuer Terminvorschläge (mehrere durch Komma getrennt). */
	public static function vorschlag_email(): string {
		return self::einstellungen()['vorschlag_email'];
	}

	public static function ical_url( string $kategorie ): string {
		return (string) ( self::einstellungen()['ical'][ $kategorie ] ?? '' );
	}

	public static function admin(): void {
		add_action( 'admin_menu', array( self::class, 'menue' ) );
		add_action( 'admin_init', array( self::class, 'registrieren' ) );
		add_action( 'admin_post_dtrv_termine_abgleich', array( self::class, 'abgleich_knopf' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'skripte' ) );
		add_action( 'admin_post_dtrv_termine_testmail', array( self::class, 'testmail_knopf' ) );
	}

	public static function testmail_knopf(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'dtrv_termine_testmail' );
		$ergebnis = Vorschlaege::senden(
			self::vorschlag_email(),
			'[DTRV] Test-Mail des Termine-Plugins',
			"Diese Test-Mail kommt vom Plugin DTRV-Termine auf " . home_url( '/' ) . ".\n\nWenn du sie liest, funktioniert der Mailversand für Terminvorschläge."
		);
		set_transient( 'dtrv_termine_testmail', $ergebnis, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . PostType::NAME . '&page=' . self::SEITE ) . '#vorschlaege' );
		exit;
	}

	/** Mediathek für die Auswahl der Standardbilder. */
	public static function skripte( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script(
			'media-editor',
			"document.addEventListener('click', function (e) {
				var w = e.target.closest('.dtrv-bild-waehlen'), r = e.target.closest('.dtrv-bild-entfernen');
				if (!w && !r) { return; }
				e.preventDefault();
				var b = w || r, feld = document.getElementById(b.dataset.feld), vorschau = document.getElementById(b.dataset.vorschau);
				if (r) { feld.value = ''; vorschau.innerHTML = ''; return; }
				var rahmen = wp.media({ title: 'Standardbild wählen', library: { type: 'image' }, multiple: false });
				rahmen.on('select', function () {
					var a = rahmen.state().get('selection').first().toJSON();
					feld.value = a.id;
					vorschau.innerHTML = '<img src=\"' + (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url) + '\" style=\"width:60px;height:60px;object-fit:cover\">';
				});
				rahmen.open();
			});"
		);
	}

	public static function menue(): void {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . PostType::NAME,
			'DTRV-Termine – Einstellungen',
			'Einstellungen',
			'manage_options',
			self::SEITE,
			array( self::class, 'seite' )
		);
	}

	public static function registrieren(): void {
		register_setting(
			self::SEITE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'bereinigen' ),
			)
		);
	}

	public static function bereinigen( $eingabe ): array {
		$sauber = array(
			'ical'        => array(),
			'klasse_link' => esc_url_raw( trim( $eingabe['klasse_link'] ?? '' ) ),
		);

		$adressen = array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $eingabe['vorschlag_email'] ?? '' ) ) ) );
		if ( count( $adressen ) !== count( array_filter( array_map( 'trim', explode( ',', (string) ( $eingabe['vorschlag_email'] ?? '' ) ) ) ) ) ) {
			add_settings_error( self::OPTION, 'vorschlag-email', 'Mindestens eine E-Mail-Adresse für Vorschläge war ungültig und wurde entfernt.' );
		}
		$sauber['vorschlag_email'] = implode( ', ', $adressen );
		foreach ( array_keys( Plugin::kategorien() ) as $slug ) {
			$sauber['beitrag'][ $slug ]           = ! empty( $eingabe['beitrag'][ $slug ] );
			$sauber['beitragskategorie'][ $slug ] = absint( $eingabe['beitragskategorie'][ $slug ] ?? 0 );
			$sauber['standardbild'][ $slug ]      = absint( $eingabe['standardbild'][ $slug ] ?? 0 );
		}
		foreach ( array_keys( Plugin::kategorien() ) as $slug ) {
			$url = trim( (string) ( $eingabe['ical'][ $slug ] ?? '' ) );
			$url = preg_replace( '#^webcals?://#i', 'https://', $url );
			if ( '' !== $url && ! str_starts_with( strtolower( $url ), 'https://' ) ) {
				add_settings_error( self::OPTION, 'ical-' . $slug, 'Die iCal-Adresse für „' . Plugin::kategorien()[ $slug ] . '“ muss mit https:// beginnen.' );
				$url = self::ical_url( $slug );
			}
			$sauber['ical'][ $slug ] = esc_url_raw( $url, array( 'https' ) );
		}
		return $sauber;
	}

	public static function abgleich_knopf(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'dtrv_termine_abgleich' );
		Sync::alle();
		wp_safe_redirect( add_query_arg( 'abgeglichen', 1, admin_url( 'edit.php?post_type=' . PostType::NAME . '&page=' . self::SEITE ) ) );
		exit;
	}

	public static function seite(): void {
		$e      = self::einstellungen();
		$status = (array) get_option( Sync::OPTION_STATUS, array() );
		$cron   = add_query_arg( 'schluessel', get_option( self::OPTION_SCHLUESSEL, '' ), rest_url( 'dtrv-termine/v1/abgleich' ) );
		?>
		<div class="wrap">
			<h1>DTRV-Termine – Einstellungen</h1>
			<?php if ( isset( $_GET['abgeglichen'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Abgleich ausgeführt.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::SEITE ); ?>
				<h2>Google Kalender</h2>
				<p>Je Kategorie die <strong>geheime Adresse im iCal-Format</strong> eintragen (Google Kalender › Einstellungen des Kalenders › „Kalender integrieren“). Die Adresse niemals weitergeben oder ins Repository schreiben.</p>
				<table class="form-table" role="presentation">
					<?php foreach ( Plugin::kategorien() as $slug => $label ) : ?>
						<tr>
							<th scope="row"><label for="dtrv-ical-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="url" class="large-text code" autocomplete="off" spellcheck="false"
								id="dtrv-ical-<?php echo esc_attr( $slug ); ?>"
								name="<?php echo esc_attr( self::OPTION ); ?>[ical][<?php echo esc_attr( $slug ); ?>]"
								value="<?php echo esc_attr( $e['ical'][ $slug ] ?? '' ); ?>"
								placeholder="https://calendar.google.com/calendar/ical/…/private-…/basic.ics"></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="dtrv-klasse-link">Link der Klasse</label></th>
						<td><input type="url" class="large-text" id="dtrv-klasse-link"
							name="<?php echo esc_attr( self::OPTION ); ?>[klasse_link]"
							value="<?php echo esc_attr( $e['klasse_link'] ); ?>">
							<p class="description">Erklärseite, auf die die Klasse (z. B. „F | 0-5“) verlinkt.</p></td>
					</tr>
				</table>
				<h2>Detailseiten</h2>
				<p>Jeder kommende Termin bekommt beim Abgleich automatisch eine <strong>veröffentlichte Seite</strong> mit Beschreibung und Block „Termin-Infobox“. Sie ist das Ziel von „Infos“ in allen Terminlisten.</p>
				<p>Ein <strong>Beitrag</strong> ist optional und sorgt nur dafür, dass der Termin auf der Startseite erscheint. Er wird immer als Entwurf angelegt: automatisch, wenn unten angehakt, per <code>Detail: Beitrag</code> im Termin oder per „Beitrag anlegen“ in der Terminliste. Mit <code>Detail: keine</code> bekommt ein Termin weder Seite noch Beitrag, mit <code>Detail: Seite</code> nur die Seite.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dtrv-seite-eltern">Übergeordnete Seite</label></th>
						<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => self::OPTION . '[seite_eltern]',
								'id'                => 'dtrv-seite-eltern',
								'selected'          => (int) $e['seite_eltern'],
								'show_option_none'  => '– keine (oberste Ebene) –',
								'option_none_value' => 0,
							)
						);
						?>
						<p class="description">Neue Termin-Seiten werden darunter angelegt, z. B. /termine/deutsche-meisterschaft-2027/.</p></td>
					</tr>
				</table>
				<table class="widefat striped" style="max-width:60rem">
					<thead><tr><th>Kategorie</th><th>Beitrag automatisch (Entwurf)</th><th>Beitragskategorie</th><th>Standardbild (Beitragsbild)</th></tr></thead>
					<tbody>
					<?php foreach ( Plugin::kategorien() as $slug => $label ) : ?>
						<?php $bild = (int) ( $e['standardbild'][ $slug ] ?? 0 ); ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[beitrag][<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( ! empty( $e['beitrag'][ $slug ] ) ); ?>> ja</label></td>
							<td>
							<?php
							wp_dropdown_categories(
								array(
									'name'              => self::OPTION . '[beitragskategorie][' . $slug . ']',
									'selected'          => (int) ( $e['beitragskategorie'][ $slug ] ?? 0 ),
									'show_option_none'  => '– Standard –',
									'option_none_value' => 0,
									'hide_empty'        => false,
									'hierarchical'      => true,
								)
							);
							?>
							</td>
							<td>
								<input type="hidden" id="dtrv-bild-<?php echo esc_attr( $slug ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[standardbild][<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $bild ? (string) $bild : '' ); ?>">
								<span id="dtrv-bild-vorschau-<?php echo esc_attr( $slug ); ?>" style="display:inline-block;vertical-align:middle;margin-right:.5em"><?php echo $bild ? wp_get_attachment_image( $bild, array( 60, 60 ) ) : ''; ?></span>
								<button type="button" class="button dtrv-bild-waehlen" data-feld="dtrv-bild-<?php echo esc_attr( $slug ); ?>" data-vorschau="dtrv-bild-vorschau-<?php echo esc_attr( $slug ); ?>">Wählen</button>
								<button type="button" class="button-link dtrv-bild-entfernen" data-feld="dtrv-bild-<?php echo esc_attr( $slug ); ?>" data-vorschau="dtrv-bild-vorschau-<?php echo esc_attr( $slug ); ?>">Entfernen</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2 id="vorschlaege">Terminvorschläge</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dtrv-vorschlag-email">E-Mail für neue Vorschläge</label></th>
						<td><input type="text" class="regular-text" id="dtrv-vorschlag-email"
							name="<?php echo esc_attr( self::OPTION ); ?>[vorschlag_email]"
							value="<?php echo esc_attr( $e['vorschlag_email'] ); ?>">
							<p class="description">Jeder neue Vorschlag aus dem Block „DTRV-Terminvorschlag“ geht mit Freigabe-Link an diese Adresse. Mehrere Adressen durch Komma trennen. Ohne Eintrag geht die Mail an die Admin-E-Mail von WordPress.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Speichern' ); ?>
			</form>

			<h2>Mailversand prüfen</h2>
			<?php
			$test = get_transient( 'dtrv_termine_testmail' );
			if ( false !== $test ) {
				delete_transient( 'dtrv_termine_testmail' );
				if ( 'ok' === $test ) {
					echo '<div class="notice notice-success inline"><p>WordPress hat die Test-Mail an <strong>' . esc_html( self::vorschlag_email() ) . '</strong> übergeben. Kommt sie nicht an, bitte den Spam-Ordner prüfen. Hilft das nicht, liegt es am Mailversand der Installation; dann ein SMTP-Plugin (z. B. „WP Mail SMTP“) mit einem echten Postfach einrichten.</p></div>';
				} else {
					echo '<div class="notice notice-error inline"><p>Versand fehlgeschlagen: <code>' . esc_html( $test ) . '</code></p></div>';
				}
			}
			$spam = (array) get_option( Formular::OPTION_SPAM, array() );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dtrv_termine_testmail">
				<?php wp_nonce_field( 'dtrv_termine_testmail' ); ?>
				<p>Schickt eine Test-Mail an die Adresse für neue Vorschläge (<?php echo esc_html( self::vorschlag_email() ); ?>).
				<?php submit_button( 'Test-Mail senden', 'secondary', 'submit', false ); ?></p>
			</form>
			<p>Vom Spam-Schutz verworfen:
				<?php
				printf(
					'%d zu schnell abgeschickt, %d Fallenfeld ausgefüllt, %d ungültig%s',
					(int) ( $spam['zu_schnell'] ?? 0 ),
					(int) ( $spam['falle'] ?? 0 ),
					(int) ( $spam['ungueltig'] ?? 0 ),
					! empty( $spam['zuletzt'] ) ? esc_html( ' (zuletzt ' . wp_date( 'j.n.Y, H:i', (int) $spam['zuletzt'] ) . ')' ) : ''
				);
				?>
			</p>

			<h2>Abgleich</h2>
			<table class="widefat striped" style="max-width:60rem">
				<thead><tr><th>Kategorie</th><th>Google-Kalender</th><th>Letzter Abgleich</th><th>Termine</th><th>Änderungen</th><th>Meldung</th></tr></thead>
				<tbody>
				<?php foreach ( Plugin::kategorien() as $slug => $label ) : ?>
					<?php
					$s    = $status[ $slug ] ?? array();
					$name = (string) ( $s['name'] ?? '' );
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo esc_html( $name ); ?>
							<?php if ( '' !== $name && false === mb_stripos( $name, $label ) ) : ?>
								<br><strong style="color:#b32d2e">⚠ Passt nicht zur Kategorie – richtige Adresse eingetragen?</strong>
							<?php endif; ?></td>
						<td><?php echo ! empty( $s['zeit'] ) ? esc_html( wp_date( 'j.n.Y, H:i', $s['zeit'] ) ) : '–'; ?>
							<?php echo isset( $s['ok'] ) ? ( $s['ok'] ? ' ✅' : ' ❌' ) : ''; ?></td>
						<td><?php echo esc_html( (string) ( $s['anzahl'] ?? '–' ) ); ?></td>
						<td><?php echo ! empty( $s['ok'] ) ? esc_html( sprintf( '%d neu, %d geändert, %d gelöscht', $s['neu'], $s['geaendert'], $s['entfernt'] ) ) : '–'; ?></td>
						<td><?php echo esc_html( $s['meldung'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
				<input type="hidden" name="action" value="dtrv_termine_abgleich">
				<?php wp_nonce_field( 'dtrv_termine_abgleich' ); ?>
				<?php submit_button( 'Jetzt abgleichen', 'secondary', 'submit', false ); ?>
			</form>

			<h2>Cronjob (All-Inkl / KAS)</h2>
			<p>Für einen stündlichen Abgleich im KAS unter „Tools › Cronjobs“ diese Adresse eintragen. WP-Cron gleicht nur als Rückfall ab, wenn der Cronjob länger als 50 Minuten nicht lief.</p>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $cron ); ?>" onclick="this.select()"></p>
		</div>
		<?php
	}
}
