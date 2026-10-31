<?php
/**
 * Listenansicht für Block und Shortcode, in der Optik der bisherigen
 * Listen auf /wettkampf/ und /breitensport/ (Titel, „Datum, Ort“, Klasse, Infos).
 */

namespace DTRV\Termine;

final class Renderer {

	public const STATUS_TEXT = array(
		'abgesagt'   => 'abgesagt',
		'verschoben' => 'verschoben',
		'vorlaeufig' => 'vorläufig',
	);

	private const ICON_KLASSE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" aria-hidden="true"><path d="M503.2 393.8L280.1 44.25c-10.42-16.33-37.73-16.33-48.15 0L8.807 393.8c-11.11 17.41-11.75 39.42-1.666 57.45C17.07 468.1 35.92 480 56.31 480h399.4c20.39 0 39.24-11.03 49.18-28.77C514.9 433.2 514.3 411.2 503.2 393.8zM256 111.8L327.8 224H256L208 288L177.2 235.3L256 111.8z"/></svg>';

	private const ICON_PFEIL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" aria-hidden="true"><path d="M504.3 273.6l-112.1 104c-6.992 6.484-17.18 8.218-25.94 4.406c-8.758-3.812-14.42-12.45-14.42-21.1L351.9 288H32C14.33 288 .0002 273.7 .0002 255.1S14.33 224 32 224h319.9l0-72c0-9.547 5.66-18.19 14.42-22c8.754-3.809 18.95-2.075 25.94 4.41l112.1 104C514.6 247.9 514.6 264.1 504.3 273.6z"/></svg>';

	/**
	 * [dtrv_termine kategorie="wettkampf,breitensport" anzahl="10" monate="12" vergangene="0"]
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'kategorie'  => '',
				'anzahl'     => 0,
				'monate'     => 0,
				'vergangene' => '0',
			),
			$atts,
			'dtrv_termine'
		);
		wp_enqueue_style( 'dtrv-termine' );
		return self::liste(
			array(
				'kategorien' => array_filter( array_map( 'trim', explode( ',', $atts['kategorie'] ) ) ),
				'anzahl'     => (int) $atts['anzahl'],
				'monate'     => (int) $atts['monate'],
				'vergangene' => filter_var( $atts['vergangene'], FILTER_VALIDATE_BOOLEAN ),
			)
		);
	}

	/**
	 * @param array{kategorien?: string[], anzahl?: int, monate?: int, vergangene?: bool} $args
	 */
	public static function liste( array $args ): string {
		$kategorien = array_values( array_intersect( (array) ( $args['kategorien'] ?? array() ), array_keys( Plugin::kategorien() ) ) );
		$anzahl     = max( 0, (int) ( $args['anzahl'] ?? 0 ) );
		$monate     = max( 0, (int) ( $args['monate'] ?? 0 ) );

		$meta = array(
			array(
				'key'     => '_dtrv_entfernt',
				'compare' => 'NOT EXISTS',
			),
		);
		if ( $kategorien ) {
			$meta[] = array(
				'key'     => '_dtrv_kategorie',
				'value'   => $kategorien,
				'compare' => 'IN',
			);
		}
		if ( empty( $args['vergangene'] ) ) {
			$meta[] = array(
				'key'     => '_dtrv_ende',
				'value'   => time(),
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}
		if ( $monate ) {
			$meta[] = array(
				'key'     => '_dtrv_start',
				'value'   => strtotime( '+' . $monate . ' months' ),
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'publish',
				'posts_per_page' => $anzahl ? $anzahl : 200,
				'meta_key'       => '_dtrv_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		if ( ! $posts ) {
			return '<p class="dtrv-termine-leer">Derzeit sind keine Termine eingetragen.</p>';
		}

		$html = '<ul class="dtrv-termine">';
		foreach ( $posts as $post ) {
			$html .= self::eintrag( $post );
		}
		return $html . '</ul>';
	}

	private static function eintrag( \WP_Post $post ): string {
		$d      = PostType::daten( $post->ID );
		$felder = $d['felder'];
		$status = $d['status'];
		$tz     = Format::zeitzone();

		$klassen = array( 'dtrv-termin', 'dtrv-termin--' . $d['kategorie'] );
		if ( $status ) {
			$klassen[] = 'is-' . $status;
		}

		$land = '';
		if ( ! empty( $felder['land'] ) ) {
			$land = sprintf(
				'<abbr class="dtrv-termin__land" title="%1$s">%2$s</abbr> ',
				esc_attr( $felder['land'] ),
				esc_html( Format::flagge( $felder['land'] ) )
			);
		}

		$meta = sprintf(
			'<time datetime="%s">%s</time>',
			esc_attr( ( new \DateTimeImmutable( '@' . $d['start'] ) )->setTimezone( $tz )->format( $d['ganztaegig'] ? 'Y-m-d' : 'c' ) ),
			esc_html( Format::zeitraum( $d['start'], $d['ende'], $d['ganztaegig'] ) )
		);
		if ( '' !== $d['ort'] ) {
			$meta .= ', ' . esc_html( $d['ort'] );
		}
		if ( $status ) {
			$meta .= ' <span class="dtrv-termin__hinweis">' . esc_html( self::STATUS_TEXT[ $status ] ?? $status ) . '</span>';
		}

		$aktionen = '';
		if ( ! empty( $felder['klasse'] ) ) {
			$aktionen .= sprintf(
				'<a class="dtrv-termin__klasse" href="%s" target="_blank" rel="noopener" title="Klassifikation von Tretrollerveranstaltungen">%s%s</a>',
				esc_url( Settings::einstellungen()['klasse_link'] ),
				esc_html( $felder['klasse'] ),
				self::ICON_KLASSE
			);
		}

		$infos = self::infos_url( $post->ID, $felder );
		if ( $infos ) {
			$aktionen .= self::knopf( $infos, 'Infos', ! empty( $felder['anmeldung'] ) && 'abgesagt' !== $status );
		}
		if ( ! empty( $felder['anmeldung'] ) && 'abgesagt' !== $status ) {
			$aktionen .= self::knopf( $felder['anmeldung'], 'Anmelden', false );
		}
		if ( ! $infos && empty( $felder['anmeldung'] ) && 'abgesagt' !== $status ) {
			$aktionen .= '<span class="dtrv-termin__knopf dtrv-termin__knopf--leer">Infos folgen</span>';
		}

		return sprintf(
			'<li class="%1$s"><div class="dtrv-termin__text"><h3 class="dtrv-termin__titel">%2$s%3$s</h3><p class="dtrv-termin__meta">%4$s</p></div><div class="dtrv-termin__aktionen">%5$s</div></li>',
			esc_attr( implode( ' ', $klassen ) ),
			$land,
			esc_html( get_the_title( $post ) ),
			$meta,
			$aktionen
		);
	}

	/**
	 * Block „Termin-Infobox“ auf einer Detailseite. Bleibt mit dem Termin verbunden,
	 * Änderungen im Kalender erscheinen nach dem nächsten Abgleich.
	 */
	public static function infobox( int $termin_id ): string {
		$post = $termin_id ? get_post( $termin_id ) : null;
		if ( ! $post || PostType::NAME !== $post->post_type ) {
			return '<div class="dtrv-infobox is-entfernt"><p>Kein Termin verknüpft.</p></div>';
		}
		$d = PostType::daten( $termin_id );
		if ( $d['entfernt'] ) {
			return '<div class="dtrv-infobox is-entfernt"><p><strong>Dieser Termin wurde abgesagt oder aus dem Kalender entfernt.</strong></p></div>';
		}

		$felder  = $d['felder'];
		$status  = $d['status'];
		$vorbei  = $d['ende'] < time();
		$klassen = 'dtrv-infobox' . ( $status ? ' is-' . $status : '' ) . ( $vorbei ? ' is-vorbei' : '' );

		$datum = esc_html( Format::zeitraum( $d['start'], $d['ende'], $d['ganztaegig'] ) );
		if ( $status ) {
			$datum = '<span class="dtrv-infobox__datum">' . $datum . '</span> <span class="dtrv-termin__hinweis">' . esc_html( self::STATUS_TEXT[ $status ] ?? $status ) . '</span>';
		}
		$zeilen = array( 'Datum' => $datum );
		if ( '' !== $d['ort'] ) {
			$land            = ! empty( $felder['land'] ) ? '<abbr class="dtrv-termin__land" title="' . esc_attr( $felder['land'] ) . '">' . esc_html( Format::flagge( $felder['land'] ) ) . '</abbr> ' : '';
			$zeilen['Ort'] = $land . esc_html( $d['ort'] );
		}
		if ( ! empty( $felder['klasse'] ) ) {
			$zeilen['Klasse'] = sprintf(
				'<a class="dtrv-termin__klasse" href="%s" target="_blank" rel="noopener">%s%s</a>',
				esc_url( Settings::einstellungen()['klasse_link'] ),
				esc_html( $felder['klasse'] ),
				self::ICON_KLASSE
			);
		}

		$html = '<div class="' . esc_attr( $klassen ) . '"><dl class="dtrv-infobox__liste">';
		foreach ( $zeilen as $label => $wert ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $wert . '</dd>';
		}
		$html .= '</dl>';

		$knoepfe = '';
		if ( ! empty( $felder['anmeldung'] ) && 'abgesagt' !== $status && ! $vorbei ) {
			$knoepfe .= self::knopf( $felder['anmeldung'], 'Anmelden', false );
		}
		$hier = untrailingslashit( (string) get_permalink() );
		if ( ! empty( $felder['infos'] ) && untrailingslashit( $felder['infos'] ) !== $hier ) {
			$knoepfe .= self::knopf( $felder['infos'], 'Weitere Infos', ! empty( $knoepfe ) );
		}
		if ( $knoepfe ) {
			$html .= '<div class="dtrv-termin__aktionen dtrv-infobox__aktionen">' . $knoepfe . '</div>';
		}
		return $html . '</div>';
	}

	/** Veröffentlichte Detailseite hat Vorrang vor dem Link aus „Infos:“. */
	private static function infos_url( int $post_id, array $felder ): string {
		$detail = (int) get_post_meta( $post_id, '_dtrv_detail_post', true );
		if ( $detail && 'publish' === get_post_status( $detail ) ) {
			return (string) get_permalink( $detail );
		}
		return $felder['infos'] ?? '';
	}

	private static function knopf( string $url, string $text, bool $zweitrangig ): string {
		$extern = ! str_starts_with( $url, home_url() );
		return sprintf(
			'<a class="dtrv-termin__knopf%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
			$zweitrangig ? ' dtrv-termin__knopf--zweit' : '',
			esc_url( $url ),
			$extern ? ' target="_blank" rel="noopener"' : '',
			esc_html( $text ),
			self::ICON_PFEIL
		);
	}
}
