<?php
/**
 * Detailseiten: Entwurf als Beitrag oder Seite zu einem Termin.
 *
 * Ausgelöst durch die Zeile „Detail:“ im Termin oder den Standard der Kategorie
 * (Termine › Einstellungen), oder per Knopf in der Terminliste. Immer nur als
 * Entwurf, nur für kommende Termine und automatisch nur einmal je Termin.
 */

namespace DTRV\Termine;

final class Detailseiten {

	public const ARTEN = array(
		'keine'   => 'keine',
		'beitrag' => 'Beitrag',
		'seite'   => 'Seite',
	);

	public static function admin(): void {
		add_action( 'admin_post_dtrv_detail_anlegen', array( self::class, 'anlegen_knopf' ) );
	}

	/** Welche Detailseite ein Termin bekommen soll: Zeile „Detail:“ vor Standard der Kategorie. */
	public static function art( array $daten ): string {
		$art = $daten['felder']['detail'] ?? '';
		if ( '' === $art ) {
			$art = Settings::einstellungen()['detail'][ $daten['kategorie'] ] ?? 'keine';
		}
		return isset( self::ARTEN[ $art ] ) ? $art : 'keine';
	}

	/** Beim Abgleich: legt bei Bedarf einmalig einen Entwurf an. */
	public static function pruefen( int $termin_id ): void {
		if ( ! $termin_id || get_post_meta( $termin_id, '_dtrv_detail_post', true ) || get_post_meta( $termin_id, '_dtrv_detail_auto', true ) ) {
			return;
		}
		$d = PostType::daten( $termin_id );
		if ( $d['entfernt'] || $d['ende'] < time() || 'abgesagt' === $d['status'] ) {
			return;
		}
		$art = self::art( $d );
		if ( 'keine' === $art ) {
			return;
		}
		// Merken, auch wenn der Entwurf später gelöscht wird: nie erneut automatisch anlegen.
		update_post_meta( $termin_id, '_dtrv_detail_auto', 1 );
		self::anlegen( $termin_id, $art );
	}

	/**
	 * Legt einen Entwurf an: Beschreibung als Einstieg plus Block „Termin-Infobox“.
	 *
	 * @return int|\WP_Error ID des neuen Beitrags bzw. der Seite.
	 */
	public static function anlegen( int $termin_id, string $art, int $autor = 0 ) {
		$termin = get_post( $termin_id );
		if ( ! $termin || PostType::NAME !== $termin->post_type || ! in_array( $art, array( 'beitrag', 'seite' ), true ) ) {
			return new \WP_Error( 'dtrv', 'Termin nicht gefunden.' );
		}
		$d = PostType::daten( $termin_id );
		$e = Settings::einstellungen();

		$inhalt = '';
		foreach ( preg_split( '/\n\s*\n/', trim( $termin->post_content ) ) as $absatz ) {
			if ( '' !== trim( $absatz ) ) {
				$inhalt .= "<!-- wp:paragraph -->\n<p>" . nl2br( esc_html( trim( $absatz ) ) ) . "</p>\n<!-- /wp:paragraph -->\n\n";
			}
		}
		$inhalt .= '<!-- wp:dtrv/infobox {"terminId":' . $termin_id . '} /-->';

		$daten = array(
			'post_type'    => 'seite' === $art ? 'page' : 'post',
			'post_status'  => 'draft',
			'post_title'   => $termin->post_title,
			'post_content' => $inhalt,
			'post_author'  => $autor ? $autor : self::autor(),
			'meta_input'   => array( '_dtrv_termin' => $termin_id ),
		);
		$kategorie = (int) ( $e['beitragskategorie'][ $d['kategorie'] ] ?? 0 );
		if ( 'beitrag' === $art && $kategorie ) {
			$daten['post_category'] = array( $kategorie );
		}

		$neu = wp_insert_post( wp_slash( $daten ), true );
		if ( is_wp_error( $neu ) ) {
			return $neu;
		}
		$bild = (int) ( $e['standardbild'][ $d['kategorie'] ] ?? 0 );
		if ( $bild ) {
			set_post_thumbnail( $neu, $bild );
		}
		update_post_meta( $termin_id, '_dtrv_detail_post', $neu );
		return $neu;
	}

	/** Autor für automatisch angelegte Entwürfe: der erste Administrator. */
	private static function autor(): int {
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		return (int) ( $ids[0] ?? 0 );
	}

	/** @return string keine | entwurf | live | geloescht */
	public static function status( int $termin_id ): string {
		$post = (int) get_post_meta( $termin_id, '_dtrv_detail_post', true );
		if ( ! $post ) {
			return 'keine';
		}
		$status = get_post_status( $post );
		if ( ! $status || 'trash' === $status ) {
			return 'geloescht';
		}
		return 'publish' === $status ? 'live' : 'entwurf';
	}

	/** @return string '' (keine Detailseite) | eigen | standard | fehlt */
	public static function bild_status( int $termin_id ): string {
		if ( ! in_array( self::status( $termin_id ), array( 'entwurf', 'live' ), true ) ) {
			return '';
		}
		$post  = (int) get_post_meta( $termin_id, '_dtrv_detail_post', true );
		$bild  = (int) get_post_thumbnail_id( $post );
		$kat   = (string) get_post_meta( $termin_id, '_dtrv_kategorie', true );
		$stand = (int) ( Settings::einstellungen()['standardbild'][ $kat ] ?? 0 );
		if ( ! $bild ) {
			return 'fehlt';
		}
		return $bild === $stand ? 'standard' : 'eigen';
	}

	/** Spalte „Detailseite“ in der Terminliste. */
	public static function spalte_detail( int $termin_id ): string {
		$post = (int) get_post_meta( $termin_id, '_dtrv_detail_post', true );
		switch ( self::status( $termin_id ) ) {
			case 'live':
				return '<a href="' . esc_url( get_permalink( $post ) ) . '">live</a> · <a href="' . esc_url( get_edit_post_link( $post ) ) . '">bearbeiten</a>';
			case 'entwurf':
				return '<a href="' . esc_url( get_edit_post_link( $post ) ) . '">Entwurf</a>';
			case 'geloescht':
				return '<span style="color:#996800">gelöscht</span>';
		}
		$art = self::art( PostType::daten( $termin_id ) );
		return 'keine' === $art ? '–' : '<span title="wird beim nächsten Abgleich angelegt, wenn der Termin in der Zukunft liegt">geplant: ' . esc_html( self::ARTEN[ $art ] ) . '</span>';
	}

	/** Spalte „Bild“ in der Terminliste. */
	public static function spalte_bild( int $termin_id ): string {
		$texte = array(
			'eigen'    => 'ok',
			'standard' => 'Standardbild',
			'fehlt'    => '<strong style="color:#b32d2e">fehlt</strong>',
		);
		return $texte[ self::bild_status( $termin_id ) ] ?? '–';
	}

	/** Zeilen-Aktionen „Beitrag anlegen“ / „Seite anlegen“. */
	public static function aktionen( int $termin_id ): array {
		if ( in_array( self::status( $termin_id ), array( 'entwurf', 'live' ), true ) ) {
			$post = (int) get_post_meta( $termin_id, '_dtrv_detail_post', true );
			return array( 'dtrv_detail' => '<a href="' . esc_url( get_edit_post_link( $post ) ) . '">Detailseite bearbeiten</a>' );
		}
		$aktionen = array();
		foreach ( array( 'beitrag' => 'Beitrag anlegen', 'seite' => 'Seite anlegen' ) as $art => $label ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=dtrv_detail_anlegen&termin=' . $termin_id . '&art=' . $art ), 'dtrv_detail_' . $termin_id );
			$aktionen[ 'dtrv_detail_' . $art ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		return $aktionen;
	}

	public static function anlegen_knopf(): void {
		$termin = absint( $_GET['termin'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'dtrv_detail_' . $termin );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$neu = self::anlegen( $termin, sanitize_key( $_GET['art'] ?? '' ), get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $neu ) ) {
			wp_die( esc_html( $neu->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . $neu . '&action=edit' ) );
		exit;
	}
}
