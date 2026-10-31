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
		add_action( 'rest_api_init', array( Rest::class, 'routen' ) );
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
		register_block_type( dirname( DATEI ) . '/blocks/termine' );
		register_block_type( dirname( DATEI ) . '/blocks/vorschlag' );
		register_block_type( dirname( DATEI ) . '/blocks/infobox' );
		add_shortcode( 'dtrv_termine', array( Renderer::class, 'shortcode' ) );
		add_shortcode( 'dtrv_termin_vorschlag', array( Formular::class, 'shortcode' ) );
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
