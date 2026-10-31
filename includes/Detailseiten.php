<?php
/**
 * Detailseiten zu Terminen.
 *
 * Jeder kommende Termin bekommt automatisch eine veröffentlichte Seite; sie ist das
 * Ziel von „Infos“ in allen Terminlisten. Ein Beitrag ist optional und dient nur dazu,
 * dass der Termin auf der Startseite erscheint; er wird immer als Entwurf angelegt.
 *
 * Steuerung über die Zeile „Detail:“ im Termin:
 *   keine   → weder Seite noch Beitrag
 *   seite   → nur Seite
 *   beitrag → Seite und Beitrag
 *   (fehlt) → Seite, Beitrag nur wenn für die Kategorie eingestellt
 *
 * Automatisch wird jede Art nur einmal je Termin angelegt; gelöschte kommen nicht wieder.
 */

namespace DTRV\Termine;

final class Detailseiten {

	public const ARTEN = array(
		'seite'   => 'Seite',
		'beitrag' => 'Beitrag',
	);

	private const SCHEMA = 2;

	public static function admin(): void {
		add_action( 'admin_post_dtrv_detail_anlegen', array( self::class, 'anlegen_knopf' ) );
	}

	/** Übernimmt Verknüpfungen aus 0.3.x (ein gemeinsames Feld für Seite oder Beitrag). */
	public static function migrieren(): void {
		if ( (int) get_option( 'dtrv_termine_schema', 0 ) >= self::SCHEMA ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => PostType::NAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array(
						'key'     => '_dtrv_detail_post',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_dtrv_detail_auto',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$post = (int) get_post_meta( $id, '_dtrv_detail_post', true );
			$typ  = $post ? get_post_type( $post ) : '';
			$art  = 'page' === $typ ? 'seite' : 'beitrag';
			if ( $post && $typ ) {
				update_post_meta( $id, '_dtrv_' . $art, $post );
			}
			update_post_meta( $id, '_dtrv_' . $art . '_auto', 1 );
			delete_post_meta( $id, '_dtrv_detail_post' );
			delete_post_meta( $id, '_dtrv_detail_auto' );
		}
		update_option( 'dtrv_termine_schema', self::SCHEMA, false );
	}

	/** @return array{seite: bool, beitrag: bool} */
	public static function plan( array $daten ): array {
		$detail = $daten['felder']['detail'] ?? '';
		if ( 'keine' === $detail ) {
			return array(
				'seite'   => false,
				'beitrag' => false,
			);
		}
		$beitrag = 'beitrag' === $detail
			|| ( 'seite' !== $detail && ! empty( Settings::einstellungen()['beitrag'][ $daten['kategorie'] ] ) );
		return array(
			'seite'   => true,
			'beitrag' => $beitrag,
		);
	}

	/** Beim Abgleich: legt fehlende Seite/Beitrag einmalig an. */
	public static function pruefen( int $termin_id ): void {
		if ( ! $termin_id ) {
			return;
		}
		$d = PostType::daten( $termin_id );
		if ( $d['entfernt'] || $d['ende'] < time() || 'abgesagt' === $d['status'] ) {
			return;
		}
		// Seite zuerst, damit die Infobox im Beitrag auf sie verlinken kann.
		foreach ( self::plan( $d ) as $art => $soll ) {
			if ( ! $soll || self::id( $termin_id, $art ) || get_post_meta( $termin_id, '_dtrv_' . $art . '_auto', true ) ) {
				continue;
			}
			update_post_meta( $termin_id, '_dtrv_' . $art . '_auto', 1 );
			self::anlegen( $termin_id, $art );
		}
	}

	/**
	 * Legt die Seite (veröffentlicht) oder den Beitrag (Entwurf) an:
	 * Beschreibung als Einstieg plus Block „Termin-Infobox“.
	 *
	 * @return int|\WP_Error ID der Seite bzw. des Beitrags.
	 */
	public static function anlegen( int $termin_id, string $art, int $autor = 0 ) {
		$termin = get_post( $termin_id );
		if ( ! $termin || PostType::NAME !== $termin->post_type || ! isset( self::ARTEN[ $art ] ) ) {
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
			'post_status'  => 'seite' === $art ? 'publish' : 'draft',
			'post_title'   => $termin->post_title,
			'post_content' => $inhalt,
			'post_author'  => $autor ? $autor : self::autor(),
			'meta_input'   => array( '_dtrv_termin' => $termin_id ),
		);
		if ( 'seite' === $art && ! empty( $e['seite_eltern'] ) ) {
			$daten['post_parent'] = (int) $e['seite_eltern'];
		}
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
		update_post_meta( $termin_id, '_dtrv_' . $art, $neu );
		return $neu;
	}

	/** Autor für automatisch angelegte Seiten/Beiträge: der erste Administrator. */
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

	public static function id( int $termin_id, string $art ): int {
		return (int) get_post_meta( $termin_id, '_dtrv_' . $art, true );
	}

	/** @return string keine | entwurf | live | geloescht */
	public static function status( int $termin_id, string $art ): string {
		$post = self::id( $termin_id, $art );
		if ( ! $post ) {
			return 'keine';
		}
		$status = get_post_status( $post );
		if ( ! $status || 'trash' === $status ) {
			return 'geloescht';
		}
		return 'publish' === $status ? 'live' : 'entwurf';
	}

	/** Veröffentlichte Detailseite für „Infos“: Seite, sonst Beitrag, sonst ''. */
	public static function link( int $termin_id ): string {
		foreach ( array_keys( self::ARTEN ) as $art ) {
			if ( 'live' === self::status( $termin_id, $art ) ) {
				return (string) get_permalink( self::id( $termin_id, $art ) );
			}
		}
		return '';
	}

	/** @return string '' (keine Seite) | eigen | standard | fehlt – bezogen auf die Seite. */
	public static function bild_status( int $termin_id ): string {
		if ( ! in_array( self::status( $termin_id, 'seite' ), array( 'entwurf', 'live' ), true ) ) {
			return '';
		}
		$bild  = (int) get_post_thumbnail_id( self::id( $termin_id, 'seite' ) );
		$kat   = (string) get_post_meta( $termin_id, '_dtrv_kategorie', true );
		$stand = (int) ( Settings::einstellungen()['standardbild'][ $kat ] ?? 0 );
		if ( ! $bild ) {
			return 'fehlt';
		}
		return $bild === $stand ? 'standard' : 'eigen';
	}

	/** Spalten „Seite“ und „Beitrag“ in der Terminliste. */
	public static function spalte( int $termin_id, string $art ): string {
		$post = self::id( $termin_id, $art );
		switch ( self::status( $termin_id, $art ) ) {
			case 'live':
				return '<a href="' . esc_url( get_permalink( $post ) ) . '">live</a> · <a href="' . esc_url( (string) get_edit_post_link( $post ) ) . '">bearbeiten</a>';
			case 'entwurf':
				return '<a href="' . esc_url( (string) get_edit_post_link( $post ) ) . '">Entwurf</a>';
			case 'geloescht':
				return '<span style="color:#996800">gelöscht</span>';
		}
		$plan = self::plan( PostType::daten( $termin_id ) );
		return $plan[ $art ] && ! get_post_meta( $termin_id, '_dtrv_' . $art . '_auto', true )
			? '<span title="wird beim nächsten Abgleich angelegt, wenn der Termin in der Zukunft liegt">geplant</span>'
			: '–';
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

	/** Zeilen-Aktionen: „Seite/Beitrag anlegen“ bzw. „… bearbeiten“. */
	public static function aktionen( int $termin_id ): array {
		$aktionen = array();
		foreach ( self::ARTEN as $art => $label ) {
			if ( in_array( self::status( $termin_id, $art ), array( 'entwurf', 'live' ), true ) ) {
				$aktionen[ 'dtrv_' . $art ] = '<a href="' . esc_url( (string) get_edit_post_link( self::id( $termin_id, $art ) ) ) . '">' . esc_html( $label ) . ' bearbeiten</a>';
				continue;
			}
			$url                        = wp_nonce_url( admin_url( 'admin-post.php?action=dtrv_detail_anlegen&termin=' . $termin_id . '&art=' . $art ), 'dtrv_detail_' . $termin_id );
			$aktionen[ 'dtrv_' . $art ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' anlegen</a>';
		}
		return $aktionen;
	}

	public static function anlegen_knopf(): void {
		$termin = absint( $_GET['termin'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'dtrv_detail_' . $termin );
		$art = sanitize_key( $_GET['art'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( 'seite' === $art ? 'publish_pages' : 'edit_posts' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$neu = self::anlegen( $termin, $art, get_current_user_id() );
		if ( is_wp_error( $neu ) ) {
			wp_die( esc_html( $neu->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . $neu . '&action=edit' ) );
		exit;
	}
}
