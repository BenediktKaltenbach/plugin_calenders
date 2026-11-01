<?php
/**
 * Termine auf der Tretrollerkarte (Plugin „My Map Plugin“, Filter mmp_categories/mmp_markers).
 *
 * Kommende Termine mit Koordinaten erscheinen als eigene Ebene „Termine“ mit einer
 * Kategorie je Kalender. Es werden keine Kopien angelegt: Was im Kalender endet oder
 * sich ändert, ist nach dem nächsten Abgleich auch auf der Karte aktuell.
 * Ist das Karten-Plugin nicht aktiv, passiert nichts.
 */

namespace DTRV\Termine;

final class Karte {

	public const FARBEN = array(
		'wettkampf'    => '#006666',
		'breitensport' => '#2e7d32',
		'ausbildung'   => '#6a1b9a',
		'verband'      => '#c75b00',
	);

	/** @var array<int, array<string, mixed>>|null */
	private static ?array $marker = null;

	public static function start(): void {
		add_filter( 'mmp_categories', array( self::class, 'kategorien' ) );
		add_filter( 'mmp_markers', array( self::class, 'marker' ) );
		add_filter( 'mmp_termin_url', array( self::class, 'formular_url' ) );
		add_action(
			'save_post',
			static function () {
				delete_transient( 'dtrv_formular_url' );
				delete_transient( 'dtrv_karten_url' );
			}
		);
	}

	/**
	 * Knopf „Zur Tretrollerkarte“ neben „Termin einreichen“: veröffentlichte Seite mit dem
	 * Shortcode [my_map]. Leer, wenn das Karten-Plugin fehlt oder keine Seite die Karte zeigt.
	 */
	public static function karten_url(): string {
		if ( ! shortcode_exists( 'my_map' ) ) {
			return '';
		}
		$gefunden = get_transient( 'dtrv_karten_url' );
		if ( false === $gefunden ) {
			$ids      = get_posts(
				array(
					'post_type'      => array( 'page', 'post' ),
					'post_status'    => 'publish',
					's'              => '[my_map',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$gefunden = $ids ? (string) get_permalink( $ids[0] ) : '';
			set_transient( 'dtrv_karten_url', $gefunden, DAY_IN_SECONDS );
		}
		return (string) apply_filters( 'dtrv_termine_karten_url', $gefunden );
	}

	/**
	 * Knopf „Termin einreichen“ unter der Karte: veröffentlichte Seite mit dem Formular-Block
	 * (oder Shortcode). Eine in my-map eingetragene Adresse hat Vorrang.
	 */
	public static function formular_url( string $url ): string {
		if ( '' !== $url ) {
			return $url;
		}
		$gefunden = get_transient( 'dtrv_formular_url' );
		if ( false === $gefunden ) {
			$gefunden = '';
			foreach ( array( 'wp:dtrv/vorschlag', '[dtrv_termin_vorschlag' ) as $suche ) {
				$ids = get_posts(
					array(
						'post_type'      => array( 'page', 'post' ),
						'post_status'    => 'publish',
						's'              => $suche,
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);
				if ( $ids ) {
					$gefunden = get_permalink( $ids[0] ) . '#dtrv-vorschlag';
					break;
				}
			}
			set_transient( 'dtrv_formular_url', $gefunden, DAY_IN_SECONDS );
		}
		return (string) $gefunden;
	}

	/** Nur Kategorien, die gerade Termine auf der Karte haben. */
	public static function kategorien( array $kategorien ): array {
		$vorhanden = array_unique( array_column( self::termine(), 'category_id' ) );
		foreach ( Plugin::kategorien() as $slug => $label ) {
			if ( in_array( 'termine-' . $slug, $vorhanden, true ) ) {
				$kategorien[] = array(
					'id'      => 'termine-' . $slug,
					'name'    => $label,
					'color'   => self::FARBEN[ $slug ],
					'icon'    => '📅',
					'group'   => 'Termine',
					'virtual' => true,
				);
			}
		}
		return $kategorien;
	}

	public static function marker( array $marker ): array {
		return array_merge( $marker, self::termine() );
	}

	/** Kommende, nicht abgesagte Termine mit Koordinaten; bei Serien nur der nächste Termin. */
	private static function termine(): array {
		if ( null !== self::$marker ) {
			return self::$marker;
		}
		$aktiv = Settings::einstellungen()['karte'];
		$ids   = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_key'       => '_dtrv_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_dtrv_ende',
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_dtrv_entfernt',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		update_meta_cache( 'post', $ids );

		self::$marker = array();
		$serien       = array();
		foreach ( $ids as $id ) {
			$d   = PostType::daten( $id );
			$geo = Geo::von_termin( $id );
			if ( ! $geo || 'abgesagt' === $d['status'] || empty( $aktiv[ $d['kategorie'] ] ) ) {
				continue;
			}
			// Wiederkehrende Termine: nur der nächste (gleiche UID vor dem „|“).
			$serie = strtok( (string) get_post_meta( $id, '_dtrv_schluessel', true ), '|' );
			if ( isset( $serien[ $serie ] ) ) {
				continue;
			}
			$serien[ $serie ] = true;

			self::$marker[] = array(
				'id'            => 'termin-' . $id,
				'title'         => get_post_field( 'post_title', $id ),
				'popup_html'    => self::popup( $id, $d ),
				'lat'           => $geo['lat'],
				'lng'           => $geo['lng'],
				'color'         => self::FARBEN[ $d['kategorie'] ] ?? '#006666',
				'icon'          => '📅',
				'type'          => 'event',
				'category_id'   => 'termine-' . $d['kategorie'],
				'category_name' => Plugin::kategorien()[ $d['kategorie'] ] ?? $d['kategorie'],
				'images'        => array(),
			);
		}
		return self::$marker;
	}

	/** Inhalt der Sprechblase (bereits escaped). */
	private static function popup( int $id, array $d ): string {
		$zeilen = array( '<strong>' . esc_html( Format::zeitraum( $d['start'], $d['ende'], $d['ganztaegig'] ) ) . '</strong>' );
		if ( '' !== $d['ort'] ) {
			$zeilen[] = esc_html( $d['ort'] );
		}
		$klasse = Format::klasse( $d['kategorie'], $d['felder']['klasse'] ?? '' );
		if ( '' !== $klasse ) {
			$zeilen[] = 'Klasse: ' . esc_html( $klasse );
		}
		if ( $d['status'] ) {
			$zeilen[] = '<em>' . esc_html( Renderer::STATUS_TEXT[ $d['status'] ] ?? $d['status'] ) . '</em>';
		}

		$links = array();
		$infos = Detailseiten::infos_ziel( $id, $d['felder'] );
		if ( '' !== $infos ) {
			$links[] = '<a href="' . esc_url( $infos ) . '">Infos</a>';
		}
		if ( ! empty( $d['felder']['anmeldung'] ) ) {
			$links[] = '<a href="' . esc_url( $d['felder']['anmeldung'] ) . '">Anmelden</a>';
		}
		if ( $links ) {
			$zeilen[] = implode( ' · ', $links );
		}
		return implode( '<br>', $zeilen );
	}
}
