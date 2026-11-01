<?php
/**
 * Zentrale Hooks: Inhaltstyp, Block, Shortcode, Cron.
 */

namespace DTRV\Termine;

final class Plugin {

	public const CRON_HOOK = 'dtrv_termine_abgleich';

	/** Kategorie-Slug → Bezeichnung. Jede Kategorie ist ein eigener Google Kalender. */
	public static function kategorien(): array {
		return array(
			'wettkampf'    => 'Wettkampf',
			'breitensport' => 'Breitensport',
			'ausbildung'   => 'Ausbildung',
			'verband'      => 'Verband',
		);
	}

	public static function start(): void {
		add_action( 'init', array( PostType::class, 'registrieren' ) );
		add_action( 'init', array( Vorschlaege::class, 'registrieren' ) );
		add_action( 'init', array( self::class, 'block_und_shortcode' ) );
		add_action( self::CRON_HOOK, array( Sync::class, 'wenn_faellig' ) );
		add_action( self::CRON_HOOK, array( self::class, 'tokens_aufraeumen' ) );
		add_action( 'dtrv_termine_abgleich_bald', array( Sync::class, 'alle' ) );
		add_action( 'rest_api_init', array( Rest::class, 'routen' ) );
		Karte::start();
		add_action( 'template_redirect', array( Formular::class, 'verarbeiten' ), 1 );
		add_action( 'template_redirect', array( Vorschlaege::class, 'link_aufruf' ), 1 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( Detailseiten::class, 'migrieren' ) );
			PostType::admin();
			Vorschlaege::admin();
			Detailseiten::admin();
			Settings::admin();
		}
	}

	public static function block_und_shortcode(): void {
		wp_register_style( 'dtrv-termine', plugins_url( 'assets/termine.css', DATEI ), array(), VERSION );
		// Formular-Skript als eigene Datei: Inhaltsfilter verändern Inline-Skripte (z. B. && → &#038;&#038;).
		wp_register_script( 'dtrv-vorschlag', plugins_url( 'assets/vorschlag.js', DATEI ), array(), VERSION, array( 'in_footer' => true ) );
		wp_localize_script( 'dtrv-vorschlag', 'dtrvVorschlag', array( 'orte' => rest_url( 'dtrv-termine/v1/orte' ) ) );
		register_block_type( dirname( DATEI ) . '/blocks/termine' );
		register_block_type( dirname( DATEI ) . '/blocks/vorschlag' );
		register_block_type( dirname( DATEI ) . '/blocks/infobox' );
		add_shortcode( 'dtrv_termine', array( Renderer::class, 'shortcode' ) );
		add_shortcode( 'dtrv_termin_vorschlag', array( Formular::class, 'shortcode' ) );
	}

	/**
	 * Legt eine Option nur an, wenn es sie noch nicht gibt – atomar per INSERT IGNORE
	 * (add_option prüft erst und schreibt dann, das ist nicht atomar).
	 *
	 * @return bool true, wenn dieser Aufruf die Option angelegt hat.
	 */
	public static function einmalig( string $name, string $wert ): bool {
		global $wpdb;
		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$name,
				$wert
			)
		);
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $ok;
	}

	/** Räumt einmalige Formular-Tokens auf, die älter als zwei Tage sind. */
	public static function tokens_aufraeumen(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( 'dtrv_t_' ) . '%',
				time() - 2 * DAY_IN_SECONDS
			)
		);
	}

	public static function aktivieren(): void {
		PostType::registrieren();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
		if ( ! get_option( Settings::OPTION_SCHLUESSEL ) ) {
			update_option( Settings::OPTION_SCHLUESSEL, wp_generate_password( 32, false ), false );
		}
	}

	public static function deaktivieren(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
