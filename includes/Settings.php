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

	public static function einstellungen(): array {
		$werte = (array) get_option( self::OPTION, array() );
		return array(
			'ical'        => (array) ( $werte['ical'] ?? array() ),
			'klasse_link' => ! empty( $werte['klasse_link'] ) ? $werte['klasse_link'] : home_url( '/klassifikation-von-tretrollerveranstaltungen/' ),
		);
	}

	public static function ical_url( string $kategorie ): string {
		return (string) ( self::einstellungen()['ical'][ $kategorie ] ?? '' );
	}

	public static function admin(): void {
		add_action( 'admin_menu', array( self::class, 'menue' ) );
		add_action( 'admin_init', array( self::class, 'registrieren' ) );
		add_action( 'admin_post_dtrv_termine_abgleich', array( self::class, 'abgleich_knopf' ) );
	}

	public static function menue(): void {
		add_submenu_page(
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
				<?php submit_button( 'Speichern' ); ?>
			</form>

			<h2>Abgleich</h2>
			<table class="widefat striped" style="max-width:60rem">
				<thead><tr><th>Kategorie</th><th>Letzter Abgleich</th><th>Termine</th><th>Änderungen</th><th>Meldung</th></tr></thead>
				<tbody>
				<?php foreach ( Plugin::kategorien() as $slug => $label ) : ?>
					<?php $s = $status[ $slug ] ?? array(); ?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
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
