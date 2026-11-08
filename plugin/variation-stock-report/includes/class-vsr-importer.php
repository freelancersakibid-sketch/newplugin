<?php
/**
 * Historical audit importer.
 *
 * @package VariationStockReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch importer for historical completed-order audit movements.
 *
 * Sold quantity does not depend on this importer; the live report reads actual
 * completed order line quantities directly from WooCommerce.
 */
class VSR_Importer {

	/** Orders processed per AJAX request. */
	const BATCH_SIZE = 50;

	/**
	 * Register AJAX endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_vsr_import_batch', array( __CLASS__, 'safe_import_batch' ) );
	}

	public static function safe_import_batch() {
		try {
			self::ajax_import_batch();
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => __( 'Historical import failed. Check WooCommerce logs and retry.', 'variation-stock-report' ) ), 500 );
		}
	}

	/**
	 * Import one completed-order batch.
	 *
	 * @return void
	 */
	public static function ajax_import_batch() {
		check_ajax_referer( 'vsr_import_batch', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'variation-stock-report' ) ), 403 );
		}

		global $wpdb;
		$offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
		// Persist a high watermark per user. Offset now carries the last scanned order ID.
		$state_key = 'vsr_history_cursor_' . get_current_user_id();
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
		$id_column = $hpos ? 'id' : 'ID';
		$type_column = $hpos ? 'type' : 'post_type';
		$high = $offset ? (int) get_user_meta( get_current_user_id(), $state_key, true ) : 0;
		if ( ! $high ) {
			$high = (int) $wpdb->get_var( "SELECT MAX({$id_column}) FROM {$table} WHERE {$type_column}='shop_order'" );
			VSR_DB::assert_query();
			update_user_meta( get_current_user_id(), $state_key, $high );
		}
		$order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT {$id_column} FROM {$table} WHERE {$type_column}='shop_order' AND {$id_column}>%d AND {$id_column}<=%d ORDER BY {$id_column} ASC LIMIT %d", $offset, $high, self::BATCH_SIZE ) );
		VSR_DB::assert_query();

		$imported_orders = 0;
		$imported_lines  = 0;
		$skipped_orders  = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( absint( $order_id ) );
			if ( ! $order instanceof WC_Order || ! $order->has_status( 'completed' ) ) {
				++$skipped_orders;
				continue;
			}

			$created_at    = self::order_datetime( $order );
			$has_line      = false;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				if ( VSR_DB::order_item_has_sale( $order->get_id(), $item->get_id() ) ) {
					continue;
				}

				// Sales evidence only: remaining _reduced_stock does not prove original movement.
				$reduced_qty = (float) $item->get_quantity();
				if ( $reduced_qty <= 0 ) {
					continue;
				}

				$product    = $item->get_product();
				$item_id    = absint( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
				$product_id = absint( $item->get_product_id() );
				$owner_id   = $product && method_exists( $product, 'get_stock_managed_by_id' ) ? absint( $product->get_stock_managed_by_id() ) : $item_id;

				if ( ! $item_id || ! $product_id || ! $owner_id ) {
					continue;
				}

				$event_key   = 'historical-sale:' . $order->get_id() . ':' . $item->get_id();
				$inserted_id = VSR_DB::log(
					array(
						'variation_id'   => $item_id,
						'stock_owner_id' => $owner_id,
						'product_id'     => $product_id,
						'change_type'    => 'historical_sale',
						'quantity'       => -abs( $reduced_qty ),
						'order_id'       => $order->get_id(),
						'order_item_id'  => $item->get_id(),
						'user_id'        => null,
						'source'         => 'historical_import',
						'event_key'      => $event_key,
						'note'           => 'Historical sale evidence; physical stock movement unverified',
						'created_at'     => $created_at,
					)
				);

				if ( ! $inserted_id ) {
					wp_send_json_error( array( 'message' => __( 'Historical audit write failed. Retry this batch safely.', 'variation-stock-report' ) ), 500 );
				}
				if ( $inserted_id ) {
					++$imported_lines;
					$has_line = true;
				}
			}

			if ( $has_line ) {
				++$imported_orders;
			} else {
				++$skipped_orders;
			}
		}

		$processed = count( $order_ids );
		wp_send_json_success(
			array(
				'imported_orders' => $imported_orders,
				'imported_lines'  => $imported_lines,
				'skipped'         => $skipped_orders,
				'next_offset'     => $order_ids ? (int) end( $order_ids ) : $offset,
				'done'            => $processed < self::BATCH_SIZE,
			)
		);
	}

	/**
	 * Return a site-local audit timestamp, preferring completion time.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private static function order_datetime( $order ) {
		$date = $order->get_date_completed();
		if ( ! $date ) {
			$date = $order->get_date_paid();
		}
		if ( ! $date ) {
			$date = $order->get_date_created();
		}

		if ( ! $date ) {
			return current_time( 'mysql' );
		}

		return wp_date( 'Y-m-d H:i:s', $date->getTimestamp(), wp_timezone() );
	}
}
