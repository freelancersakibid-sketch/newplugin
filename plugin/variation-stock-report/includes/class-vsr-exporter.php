<?php
/**
 * CSV export for Inventory Report and Stock Movement Audit Log.
 *
 * @package VariationStockReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stream the filtered report or audit log as an Excel-friendly CSV.
 */
class VSR_Exporter {

	/** Export rows per query. */
	const BATCH_SIZE = 500;

	/**
	 * Register export endpoints.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_vsr_export_csv', array( __CLASS__, 'safe_export_csv' ) );
		add_action( 'admin_post_vsr_export_audit_csv', array( __CLASS__, 'safe_export_audit_csv' ) );
	}

	public static function safe_export_csv() {
		self::guard_export( false );
	}
	public static function safe_export_audit_csv() {
		self::guard_export( true );
	}
	private static function guard_export( $audit ) {
		global $wpdb;
		try {
			if ( $audit ) {
				self::export_audit_csv();
			} else {
				self::export_csv();
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $error->getMessage(), array( 'source' => 'variation-stock-report' ) );
			}
			wp_die( esc_html__( 'Export failed. No CSV was delivered. Check WooCommerce logs and retry.', 'variation-stock-report' ), '', array( 'response' => 500 ) );
		}
	}

	private static function check_snapshot_engines() {
		global $wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->users, VSR_DB::table(), $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta' );
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$tables[] = $wpdb->prefix . 'wc_orders';
			$tables[] = $wpdb->prefix . 'wc_order_operational_data';
		}
		foreach ( $tables as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				throw new RuntimeException( 'Consistent CSV exports require InnoDB source tables.' );
			}
		}
	}

	/**
	 * Output Inventory Report CSV.
	 *
	 * @return void
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'variation-stock-report' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'vsr_export_csv' );

		$filters  = VSR_Admin::get_filters_from_request();
		$filename = 'variation-stock-report-' . current_time( 'Y-m-d-His' ) . '.csv';

		global $wpdb;
		self::check_snapshot_engines();
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
			wp_die( esc_html__( 'Could not start export snapshot.', 'variation-stock-report' ), '', array( 'response' => 500 ) );
		}

		$output = tmpfile(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disk-backed temporary export.
		if ( false === $output ) {
			wp_die( esc_html__( 'Unable to create the export stream.', 'variation-stock-report' ), '', array( 'response' => 500 ) );
		}

		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM for the temporary CSV.
		self::write_csv_row(
			$output,
			array(
				'Product ID',
				'Item ID',
				'Product',
				'Variation',
				'SKU',
				'Unit Price',
				'Inventory Mode',
				'Current Stock',
				'Stock Retail Value',
				'Total Sold',
				'30d Sold',
				'Daily Velocity (30d)',
				'Days of Stock Left',
				'Suggested Reorder Qty',
				'Total Restocked',
				'Manual Decrease',
				'Last Sale',
				'Last Restock',
				'Stock Status',
			)
		);

		$offset = 0;
		do {
			$rows = VSR_DB::get_export_rows( $filters, $offset, self::BATCH_SIZE );

			foreach ( $rows as $row ) {
				$variation_label = 'variation' === $row->row_type
					? VSR_Admin::format_variation_label( $row->attribute_label, $row->item_id )
					: __( 'Simple product', 'variation-stock-report' );

				self::write_csv_row(
					$output,
					array(
						absint( $row->product_id ),
						absint( $row->item_id ),
						$row->product_name,
						$variation_label,
						$row->sku ? $row->sku : '-',
						VSR_Admin::format_quantity( $row->unit_price ),
						$row->inventory_mode,
						$row->manages_stock ? VSR_Admin::format_quantity( $row->stock_quantity ) : __( 'Not managed', 'variation-stock-report' ),
						$row->manages_stock ? VSR_Admin::format_quantity( $row->stock_retail_value ) : '0',
						VSR_Admin::format_quantity( $row->total_sold ),
						VSR_Admin::format_quantity( $row->sold_30d ),
						VSR_Admin::format_quantity( $row->daily_velocity ),
						null !== $row->days_left ? (string) $row->days_left : '-',
						$row->reorder_qty > 0 ? VSR_Admin::format_quantity( $row->reorder_qty ) : '0',
						VSR_Admin::format_quantity( $row->total_restocked ),
						VSR_Admin::format_quantity( $row->total_adjusted ),
						VSR_Admin::format_gmt_date( $row->last_sale_date, 'Y-m-d H:i:s' ),
						VSR_Admin::format_local_date( $row->last_restock_date, 'Y-m-d H:i:s' ),
						$row->stock_status,
					)
				);
			}

			$offset += count( $rows );
		} while ( self::BATCH_SIZE === count( $rows ) );

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'Export snapshot commit failed.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		rewind( $output );
		fpassthru( $output );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the response stream.
		exit;
	}

	/**
	 * Output Stock Movement Audit Log CSV.
	 *
	 * @return void
	 */
	public static function export_audit_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the audit log.', 'variation-stock-report' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'vsr_export_audit_csv' );

		$item_id  = isset( $_GET['item_id'] ) ? absint( wp_unslash( $_GET['item_id'] ) ) : 0;
		$owner_id = isset( $_GET['owner_id'] ) ? absint( wp_unslash( $_GET['owner_id'] ) ) : 0;
		$filename = 'variation-stock-audit-log-' . current_time( 'Y-m-d-His' ) . '.csv';

		global $wpdb;
		self::check_snapshot_engines();
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
			wp_die( esc_html__( 'Could not start export snapshot.', 'variation-stock-report' ), '', array( 'response' => 500 ) );
		}

		$output = tmpfile(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $output ) {
			wp_die( esc_html__( 'Unable to create the export stream.', 'variation-stock-report' ), '', array( 'response' => 500 ) );
		}

		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		self::write_csv_row(
			$output,
			array(
				'Log ID',
				'Date (Local)',
				'Product ID',
				'Product',
				'Item ID',
				'Stock Owner ID',
				'Movement Type',
				'Quantity Delta',
				'Stock Before',
				'Stock After',
				'Order ID',
				'Source',
				'User',
				'Note',
			)
		);

		$cursor = 0;
		$snapshot = 0;
		$type = isset( $_GET['change_type'] ) ? sanitize_key( wp_unslash( $_GET['change_type'] ) ) : '';
		do {
			$result = VSR_DB::get_audit_logs(
				array(
					'item_id'  => $item_id,
					'owner_id' => $owner_id,
					'limit'    => self::BATCH_SIZE,
					'before_id' => $cursor,
					'snapshot_id' => $snapshot,
					'count' => false,
					'change_type' => $type,
				)
			);
			$rows   = $result['rows'];

			foreach ( $rows as $row ) {
				self::write_csv_row(
					$output,
					array(
						absint( $row->id ),
						$row->created_at,
						absint( $row->product_id ),
						$row->product_name,
						absint( $row->variation_id ),
						absint( $row->stock_owner_id ),
						$row->change_type,
						VSR_Admin::format_quantity( $row->quantity ),
						null !== $row->stock_before ? VSR_Admin::format_quantity( $row->stock_before ) : '-',
						null !== $row->stock_after ? VSR_Admin::format_quantity( $row->stock_after ) : '-',
						$row->order_id ? '#' . absint( $row->order_id ) : '-',
						$row->source,
						$row->user_name ? $row->user_name : 'System / Customer',
						$row->note ? $row->note : '',
					)
				);
			}

			$cursor = $result['next_id'];
			$snapshot = $result['snapshot_id'];
		} while ( self::BATCH_SIZE === count( $rows ) );

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'Export snapshot commit failed.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		rewind( $output );
		fpassthru( $output );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Write one CSV row with an explicit empty escape character.
	 *
	 * @param resource $output Stream resource.
	 * @param array    $values Cell values.
	 * @return void
	 */
	private static function write_csv_row( $output, $values ) {
		$values = array_map( array( __CLASS__, 'safe_csv_value' ), $values );
		if ( false === fputcsv( $output, $values, ',', '"', '' ) ) {
			throw new RuntimeException( 'Could not write CSV export.' );
		}
	}

	/**
	 * Prevent spreadsheet formula injection from exported text fields.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private static function safe_csv_value( $value ) {
		$value = (string) $value;

		if ( preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}
}
