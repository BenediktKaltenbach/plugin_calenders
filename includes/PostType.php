<?php
/**
 * Inhaltstyp „Termin“: Spiegel der Google-Kalender-Termine.
 * Wird nur vom Abgleich beschrieben; Änderungen gehören in den Kalender.
 */

namespace DTRV\Termine;

final class PostType {

	public const NAME = 'dtrv_termin';

	public static function registrieren(): void {
		register_post_type(
			self::NAME,
			array(
				'labels'          => array(
					'name'          => 'Termine',
					'singular_name' => 'Termin',
					'menu_name'     => 'Termine',
					'all_items'     => 'Alle Termine',
					'edit_item'     => 'Termin ansehen',
					'search_items'  => 'Termine durchsuchen',
					'not_found'     => 'Noch keine Termine abgeglichen.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-calendar-alt',
				'menu_position'   => 21,
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
		add_action( 'restrict_manage_posts', array( self::class, 'filter_auswahl' ) );
		add_action( 'pre_get_posts', array( self::class, 'admin_abfrage' ) );
		add_action( 'add_meta_boxes_' . self::NAME, array( self::class, 'meta_box' ) );
		add_filter( 'post_row_actions', array( self::class, 'zeilen_aktionen' ), 10, 2 );
	}

	/** Gespeicherte Daten eines Termins. */
	public static function daten( int $post_id ): array {
		return array(
			'kategorie'  => (string) get_post_meta( $post_id, '_dtrv_kategorie', true ),
			'start'      => (int) get_post_meta( $post_id, '_dtrv_start', true ),
			'ende'       => (int) get_post_meta( $post_id, '_dtrv_ende', true ),
			'ganztaegig' => (bool) get_post_meta( $post_id, '_dtrv_ganztaegig', true ),
			'ort'        => (string) get_post_meta( $post_id, '_dtrv_ort', true ),
			'status'     => (string) get_post_meta( $post_id, '_dtrv_status', true ),
			'felder'     => (array) get_post_meta( $post_id, '_dtrv_felder', true ),
			'warnungen'  => (array) get_post_meta( $post_id, '_dtrv_warnungen', true ),
			'entfernt'   => (int) get_post_meta( $post_id, '_dtrv_entfernt', true ),
		);
	}

	public static function spalten( array $spalten ): array {
		return array(
			'cb'        => $spalten['cb'],
			'title'     => 'Titel',
			'datum'     => 'Datum',
			'kategorie' => 'Kategorie',
			'ort'       => 'Ort',
			'felder'    => 'Angaben',
			'seite'     => 'Seite',
			'beitrag'   => 'Beitrag',
			'bild'      => 'Bild',
			'hinweise'  => 'Hinweise',
		);
	}

	public static function spalte( string $spalte, int $post_id ): void {
		$d = self::daten( $post_id );
		switch ( $spalte ) {
			case 'datum':
				echo esc_html( Format::zeitraum( $d['start'], $d['ende'], $d['ganztaegig'] ) );
				break;
			case 'kategorie':
				echo esc_html( Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'] );
				break;
			case 'ort':
				echo esc_html( $d['ort'] );
				break;
			case 'felder':
				$teile = array();
				foreach ( $d['felder'] as $feld => $wert ) {
					$teile[] = '<code>' . esc_html( $feld ) . '</code> ' . esc_html( $wert );
				}
				echo wp_kses_post( implode( '<br>', $teile ) );
				break;
			case 'seite':
			case 'beitrag':
				echo wp_kses_post( Detailseiten::spalte( $post_id, $spalte ) );
				break;
			case 'bild':
				echo wp_kses_post( Detailseiten::spalte_bild( $post_id ) );
				break;
			case 'hinweise':
				$hinweise = array();
				if ( $d['entfernt'] ) {
					$hinweise[] = '<strong style="color:#b32d2e">Im Kalender gelöscht</strong>';
				}
				if ( $d['status'] ) {
					$hinweise[] = esc_html( Renderer::STATUS_TEXT[ $d['status'] ] ?? $d['status'] );
				}
				foreach ( $d['warnungen'] as $w ) {
					$hinweise[] = '<span style="color:#996800">⚠ ' . esc_html( $w ) . '</span>';
				}
				echo wp_kses_post( implode( '<br>', $hinweise ) );
				break;
		}
	}

	public static function filter_auswahl( string $post_type ): void {
		if ( self::NAME !== $post_type ) {
			return;
		}
		$aktiv = sanitize_key( $_GET['dtrv_kategorie'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="dtrv_kategorie"><option value="">Alle Kategorien</option>';
		foreach ( Plugin::kategorien() as $slug => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $aktiv, $slug, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public static function admin_abfrage( \WP_Query $q ): void {
		if ( ! $q->is_main_query() || self::NAME !== $q->get( 'post_type' ) ) {
			return;
		}
		$kategorie = sanitize_key( $_GET['dtrv_kategorie'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $kategorie ) {
			$q->set( 'meta_query', array( array( 'key' => '_dtrv_kategorie', 'value' => $kategorie ) ) );
		}
		if ( ! $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', '_dtrv_start' );
			$q->set( 'orderby', 'meta_value_num' );
			$q->set( 'order', 'ASC' );
		}
	}

	public static function zeilen_aktionen( array $aktionen, \WP_Post $post ): array {
		if ( self::NAME === $post->post_type ) {
			unset( $aktionen['inline hide-if-no-js'] );
			$aktionen += Detailseiten::aktionen( $post->ID );
		}
		return $aktionen;
	}

	public static function meta_box(): void {
		remove_meta_box( 'submitdiv', self::NAME, 'side' );
		add_meta_box(
			'dtrv-termin-daten',
			'Daten aus Google Kalender',
			static function ( \WP_Post $post ) {
				$d = self::daten( $post->ID );
				echo '<p><em>Dieser Termin wird aus Google Kalender gespiegelt. Änderungen bitte im Kalender vornehmen; sie erscheinen beim nächsten Abgleich.</em></p>';
				echo '<table class="widefat striped"><tbody>';
				$zeilen = array(
					'Datum'        => Format::zeitraum( $d['start'], $d['ende'], $d['ganztaegig'] ),
					'Kategorie'    => Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'],
					'Ort'          => $d['ort'],
					'Beschreibung' => $post->post_content,
					'Bildquelle'   => Bilder::quelle( $post->ID ),
				);
				foreach ( $d['felder'] as $feld => $wert ) {
					$zeilen[ ucfirst( $feld ) ] = $wert;
				}
				foreach ( $zeilen as $label => $wert ) {
					printf( '<tr><th style="width:10em">%s</th><td>%s</td></tr>', esc_html( $label ), nl2br( esc_html( $wert ) ) );
				}
				echo '</tbody></table>';
			},
			self::NAME,
			'normal',
			'high'
		);
	}
}
