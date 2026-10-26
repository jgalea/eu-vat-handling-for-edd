<?php
/**
 * Plugin Name:       EU VAT Handling for EDD
 * Description:       Decides, applies and records EU VAT treatment on every Easy Digital Downloads order.
 * Version:           0.1.1
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Requires Plugins:  easy-digital-downloads
 * Author:            Jean Galea
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       eu-vat-handling-for-edd
 */

defined( 'ABSPATH' ) || exit;

define( 'VATRIGHT_EDD_VERSION', '0.1.1' );
define( 'VATRIGHT_EDD_FILE', __FILE__ );
define( 'VATRIGHT_EDD_DIR', plugin_dir_path( __FILE__ ) );
define( 'VATRIGHT_EDD_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( VATRIGHT_EDD_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'EU VAT Handling for EDD is missing its autoloader. Run "composer install" in the plugin folder.', 'eu-vat-handling-for-edd' ) . '</p></div>';
		}
	);
	return;
}

require_once VATRIGHT_EDD_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \VatrightEdd\Plugin::class, 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		\VatrightEdd\Scheduler::clear( \VatrightEdd\Rates\RateSync::HOOK );
		\VatrightEdd\Scheduler::clear( \VatrightEdd\Ledger\Backfill::HOOK );
		\VatrightEdd\Scheduler::clear( \VatrightEdd\Ledger\LedgerWriter::FX_RETRY_HOOK );
	}
);

add_action( 'plugins_loaded', array( \VatrightEdd\Plugin::class, 'boot' ), 20 );
