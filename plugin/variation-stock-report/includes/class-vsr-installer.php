<?php
/**
 * Database installation and upgrade routines.
 *
 * @package VariationStockReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database installation and upgrade routines.
 */
class VSR_Installer {

	/**
	 * Database schema version option.
	 */
	const OPTION_DB_VERSION = 'vsr_db_version';

	/**
	 * Install or upgrade the plugin schema.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table           = $wpdb->prefix . VSR_TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$operations = $wpdb->prefix . 'vsr_operations';
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( require VSR_PLUGIN_DIR . 'migrations/2.2.0.php' as $sql ) {
			dbDelta( $sql );
		}

		// Verify actual schema; dbDelta's descriptive output is not proof of success.
		$required = array( $table => array( 'id', 'variation_id', 'stock_owner_id', 'product_id', 'change_type', 'quantity', 'stock_before', 'stock_after', 'order_id', 'order_item_id', 'user_id', 'source', 'event_key', 'note', 'created_at' ), $operations => array( 'operation_key', 'payload_hash', 'result', 'created_at' ) );
		foreach ( $required as $name => $columns ) {
			$actual = $wpdb->get_col( "SHOW COLUMNS FROM `{$name}`" );
			if ( $wpdb->last_error || array_diff( $columns, (array) $actual ) ) {
				return self::failure( 'Required inventory schema is unavailable.' );
			}
		}
		$indexes = $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 );
		if ( $wpdb->last_error || array_diff( array( 'event_key', 'created_id', 'owner_type_created' ), (array) $indexes ) ) {
			return self::failure( 'Inventory indexes could not be installed.' );
		}
		$unique = $wpdb->get_var( "SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND INDEX_NAME='event_key' LIMIT 1" );
		if ( $wpdb->last_error || null === $unique || 0 !== (int) $unique ) {
			return self::failure( 'Audit event identifier must have a unique index.' );
		}
		// Do not infer old shared-stock ownership: preserve existing audit data unchanged.
		update_option( self::OPTION_DB_VERSION, VSR_DB_VERSION, false );
		if ( $wpdb->last_error ) {
			return self::failure( 'Could not persist inventory schema version.' );
		}
		delete_option( 'vsr_schema_error' );
		return true;
	}

	private static function failure( $message ) {
		update_option( 'vsr_schema_error', $message, false );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'variation-stock-report' ) );
		}
		return false;
	}

	public static function error_notice() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Inventory reports are unavailable because the database upgrade failed. Check database permissions and WooCommerce logs, then reload to retry. Existing data has been preserved.', 'variation-stock-report' ) . '</p></div>';
		}
	}

	/**
	 * Upgrade once when plugin files are replaced without reactivation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( VSR_DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
			return true;
		}

		return self::install();
	}
}
