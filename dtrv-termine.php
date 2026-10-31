<?php
/**
 * Plugin Name:       DTRV-Termine
 * Description:       Liest die Termine aus den Google Kalendern des DTRV, spiegelt sie in WordPress und zeigt sie per Block oder Shortcode an.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Deutscher Tretroller Verband e.V.
 * License:           GPL-2.0-or-later
 * Text Domain:       dtrv-termine
 * Update URI:        https://github.com/BenediktKaltenbach/plugin_calenders
 */

namespace DTRV\Termine;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';
const DATEI   = __FILE__;
const REPO    = 'https://github.com/BenediktKaltenbach/plugin_calenders/';

$autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>DTRV-Termine:</strong> Der Ordner <code>vendor/</code> fehlt. Bitte das fertige ZIP aus den GitHub-Releases installieren (nicht den Quellcode).</p></div>';
		}
	);
	return;
}
require_once $autoload;

require_once __DIR__ . '/includes/Format.php';
require_once __DIR__ . '/includes/Parser.php';
require_once __DIR__ . '/includes/Feed.php';
require_once __DIR__ . '/includes/Plugin.php';
require_once __DIR__ . '/includes/PostType.php';
require_once __DIR__ . '/includes/Sync.php';
require_once __DIR__ . '/includes/Renderer.php';
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Rest.php';
require_once __DIR__ . '/includes/Vorschlaege.php';
require_once __DIR__ . '/includes/Formular.php';

Plugin::start();

register_activation_hook( __FILE__, array( Plugin::class, 'aktivieren' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deaktivieren' ) );

// Updates über GitHub-Releases (Asset dtrv-termine.zip, enthält vendor/).
$updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( REPO, __FILE__, 'dtrv-termine' );
$updater->getVcsApi()->enableReleaseAssets( '/dtrv-termine\.zip/' );
