<?php
/**
 * Plugin Name:       MO Order Number
 * Plugin URI:        https://github.com/MarekTP/mo-order-number
 * Description:       Lidské číslování objednávek WooCommerce: YYYY+pořadí, YYYYMM+pořadí nebo globální sekvence.
 * Version:           1.0.0
 * Author:            Marek Olšavský
 * Author URI:        https://olsavsky.cz
 * Text Domain:       mo-order-number
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'MON_VERSION',     '1.0.0' );
define( 'MON_PLUGIN_FILE', __FILE__ );
define( 'MON_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MON_GITHUB_USER', 'MarekTP' );
define( 'MON_GITHUB_REPO', 'mo-order-number' );

require_once MOTOOLS_PATH . 'vendor/autoload.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/MarekTP/mo-order-number/',
    __FILE__,
    'mo-order-number'
);
$updateChecker->getVcsApi()->enableReleaseAssets();


/**
 * Kontrola dostupnosti WooCommerce za běhu.
 * `Requires Plugins` header (WP 6.5+) blokuje aktivaci bez WC,
 * ale WC může být deaktivováno dodatečně.
 */
function mon_woocommerce_active(): bool {
	return class_exists( 'WooCommerce' );
}

add_action( 'plugins_loaded', function (): void {

	load_plugin_textdomain(
		'mo-order-number',
		false,
		dirname( plugin_basename( MON_PLUGIN_FILE ) ) . '/languages'
	);

	if ( ! mon_woocommerce_active() ) {
		add_action( 'admin_notices', function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					/* translators: %s: odkaz na správu pluginů */
					esc_html__( 'MO Order Number vyžaduje aktivní plugin WooCommerce. %s', 'mo-order-number' ),
					'<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">'
					. esc_html__( 'Správa pluginů', 'mo-order-number' )
					. '</a>'
				)
			);
		} );
		return;
	}

	require_once MON_PLUGIN_DIR . 'includes/class-mon-core.php';
	require_once MON_PLUGIN_DIR . 'includes/class-mon-admin.php';
	require_once MON_PLUGIN_DIR . 'includes/class-mon-updater.php';

	MON_Core::init();
	MON_Admin::init();
	( new MON_Updater( MON_PLUGIN_FILE, MON_GITHUB_USER, MON_GITHUB_REPO, MON_VERSION ) )->init();

} );
