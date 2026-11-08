<?php
/**
 * Plugin Name: Variation Stock Report Pro
 * Description: Professional WooCommerce inventory reporting with variation-level completed sales, stock valuation, velocity forecasting, audit history viewer, bulk & inline stock updates, and CSV export.
 * Version:     2.2.0-rc.1
 * Author:      Tabaz Mart
 * License:     GPL-2.0-or-later
 * Text Domain: variation-stock-report
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.6
 * WC tested up to: 11.2.2
 * Requires Plugins: woocommerce
 *
 * @package VariationStockReport
 */

defined( 'ABSPATH' ) || exit;

define( 'VSR_VERSION', '2.2.0-rc.1' );
define( 'VSR_DB_VERSION', '2.2.0-rc.1' );
define( 'VSR_PLUGIN_FILE', __FILE__ );
define( 'VSR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VSR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSR_TABLE_NAME', 'vsr_stock_log' );

register_activation_hook( __FILE__, 'vsr_activate_plugin' );

/**
 * Create or upgrade the database table on activation.
 *
 * @return void
 */
function vsr_activate_plugin() {
	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-installer.php';
	if ( ! VSR_Installer::install() ) {
		wp_die( esc_html__( 'Inventory database installation failed. Existing data is preserved. Check database permissions and retry activation.', 'variation-stock-report' ), '', array( 'back_link' => true ) );
	}
}

/**
 * Declare compatibility with WooCommerce features used by this plugin.
 *
 * @return void
 */
function vsr_declare_woocommerce_compatibility() {
	if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VSR_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VSR_PLUGIN_FILE, true );
}
add_action( 'before_woocommerce_init', 'vsr_declare_woocommerce_compatibility' );

/**
 * Load the plugin after all plugins are available.
 *
 * @return void
 */
function vsr_init_plugin() {
	load_plugin_textdomain( 'variation-stock-report', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'vsr_woocommerce_missing_notice' );
		return;
	}

	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-installer.php';
	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-db.php';
	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-tracker.php';
	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-admin.php';
	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-exporter.php';
	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-importer.php';

	require_once VSR_PLUGIN_DIR . 'includes/class-vsr-stock-service.php';
	if ( ! VSR_Installer::maybe_upgrade() ) {
		add_action( 'admin_notices', array( 'VSR_Installer', 'error_notice' ) );
		return;
	}
	add_action( 'admin_notices', array( 'VSR_DB', 'audit_notice' ) );
	VSR_Tracker::init();
	VSR_Admin::init();
	VSR_Exporter::init();
	VSR_Importer::init();
}
add_action( 'plugins_loaded', 'vsr_init_plugin' );

/**
 * Show an actionable dependency notice.
 *
 * @return void
 */
function vsr_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Variation Stock Report Pro', 'variation-stock-report' ); ?></strong>
			<?php esc_html_e( 'requires WooCommerce to be installed and active.', 'variation-stock-report' ); ?>
		</p>
	</div>
	<?php
}
