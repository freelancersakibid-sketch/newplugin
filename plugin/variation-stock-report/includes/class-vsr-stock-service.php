<?php
/** Transactional stock commands. @package VariationStockReport */
defined( 'ABSPATH' ) || exit;

class VSR_Stock_Service {
	/**
	 * Apply one command per physical stock owner. Callers must authorize the user.
	 * Keys must remain identical on retries. Failures roll back stock, audit and receipt.
	 * External hooks must not commit transactions or perform irreversible side effects.
	 */
	public static function apply( $item_id, $operation, $quantity, $request_id, $expected = null, $note = '', $source = 'stock_report' ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{16,100}$/D', (string) $request_id ) || ! in_array( $operation, array( 'set', 'increase', 'decrease' ), true ) || ! is_numeric( $quantity ) || ! is_finite( (float) $quantity ) || abs( (float) $quantity ) > 999999999 || ( 'set' !== $operation && (float) $quantity <= 0 ) || ( null !== $expected && ( ! is_numeric( $expected ) || ! is_finite( (float) $expected ) ) ) ) {
			return new WP_Error( 'invalid_command', __( 'Invalid stock command or request identifier.', 'variation-stock-report' ) );
		}
		$item_id = absint( $item_id );
		$quantity = (float) wc_stock_amount( $quantity );
		$product = wc_get_product( $item_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'missing_product', __( 'Product not found.', 'variation-stock-report' ) );
		}
		$owner_id = (int) $product->get_stock_managed_by_id();
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'wc_product_meta_lookup', VSR_DB::table(), $wpdb->prefix . 'vsr_operations' );
		foreach ( $tables as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				return new WP_Error( 'unsafe_engine', __( 'Stock commands require InnoDB for WordPress, WooCommerce lookup, and inventory tables. Ask your database administrator to convert them after a backup.', 'variation-stock-report' ) );
			}
		}
		$key = hash( 'sha256', $source . ':' . $request_id . ':' . $owner_id );
		$hash = hash( 'sha256', wp_json_encode( array( $item_id, $owner_id, $operation, $quantity, $expected, $note, get_current_user_id() ) ) );
		$ops = $wpdb->prefix . 'vsr_operations';
		$committed = false;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'transaction_failed', __( 'Could not start stock transaction.', 'variation-stock-report' ) );
		}
		try {
			// INSERT ON DUPLICATE waits on an in-flight receipt before checking its result.
			self::check( $wpdb->query( $wpdb->prepare( "INSERT INTO {$ops} (operation_key,payload_hash,result,created_at) VALUES (%s,%s,'',%s) ON DUPLICATE KEY UPDATE operation_key=operation_key", $key, $hash, current_time( 'mysql', true ) ) ) );
			$receipt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ops} WHERE operation_key=%s FOR UPDATE", $key ) );
			if ( ! $receipt || $wpdb->last_error ) {
				throw new RuntimeException( 'Could not read operation receipt.' );
			}
			if ( ! hash_equals( $receipt->payload_hash, $hash ) ) {
				throw new RuntimeException( 'Request identifier was already used for a different command.' );
			}
			if ( '' !== $receipt->result ) {
				$result = json_decode( $receipt->result, true );
				if ( ! is_array( $result ) ) {
					throw new RuntimeException( 'Invalid operation receipt.' );
				}
				self::check( $wpdb->query( 'COMMIT' ) );
				$committed = true;
				$result['replayed'] = true;
				return $result;
			}
			// WooCommerce order decrements update these same rows and wait on this lock.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE", $owner_id ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Could not lock stock.' );
			}
			$stock_rows = array_values( array_filter( $rows, static function ( $row ) { return '_stock' === $row->meta_key; } ) );
			if ( 1 !== count( $stock_rows ) || ! is_numeric( $stock_rows[0]->meta_value ) ) {
				throw new RuntimeException( 'Stock metadata is missing, duplicated or invalid; repair the product before updating.' );
			}
			self::clear_cache( $owner_id );
			self::clear_cache( $item_id );
			$target = wc_get_product( $owner_id );
			$product = wc_get_product( $item_id );
			if ( ! $target || ! $product || ! $target->managing_stock() || $owner_id !== (int) $product->get_stock_managed_by_id() ) {
				throw new RuntimeException( 'Stock ownership or management changed. Refresh and try again.' );
			}
			$before = (float) $stock_rows[0]->meta_value;
			if ( null !== $expected && abs( $before - (float) $expected ) >= 0.0001 ) {
				throw new RuntimeException( 'Stock changed elsewhere. Refresh and try again.' );
			}
			$after = 'set' === $operation ? $quantity : $before + ( 'increase' === $operation ? $quantity : -$quantity );
			if ( abs( $after ) > 999999999 || ( $after < 0 && ! $target->backorders_allowed() ) ) {
				throw new RuntimeException( 'This command would create negative stock with backorders disabled, or exceed the stock limit.' );
			}
			VSR_Tracker::set_context( 'stock_report_atomic' );
			$result = wc_update_product_stock( $target, $after, 'set' );
			if ( is_wp_error( $result ) || ! is_numeric( $result ) || abs( (float) $result - $after ) >= 0.0001 || $wpdb->last_error ) {
				throw new RuntimeException( 'WooCommerce stock update failed.' );
			}
			$actual = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d", $stock_rows[0]->meta_id ) );
			if ( $wpdb->last_error || ! is_numeric( $actual ) || abs( (float) $actual - $after ) >= 0.0001 ) {
				throw new RuntimeException( 'Stock verification failed.' );
			}
			$lookup = $wpdb->get_row( $wpdb->prepare( "SELECT stock_quantity,stock_status FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d", $owner_id ) );
			if ( $wpdb->last_error || ! $lookup || ! is_numeric( $lookup->stock_quantity ) || abs( (float) $lookup->stock_quantity - $after ) >= 0.0001 || $lookup->stock_status !== $target->get_stock_status() ) {
				throw new RuntimeException( 'WooCommerce inventory lookup verification failed.' );
			}

			$delta = $after - $before;
			$log_id = VSR_DB::log( array(
				'variation_id' => $item_id, 'stock_owner_id' => $owner_id,
				'product_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $item_id,
				'change_type' => $delta > 0 ? 'restock' : 'adjustment', 'quantity' => $delta,
				'stock_before' => $before, 'stock_after' => $after, 'event_key' => 'command:' . $key,
				'source' => $source, 'note' => $note,
			) );
			if ( ! $log_id ) {
				throw new RuntimeException( 'Audit write failed. Stock command rolled back.' );
			}
			$response = array( 'item_id' => $item_id, 'owner_id' => $owner_id, 'new_stock' => $after, 'new_stock_formatted' => VSR_Admin::format_quantity( $after ), 'stock_status' => $target->get_stock_status(), 'log_id' => $log_id, 'replayed' => false );
			self::check( $wpdb->update( $ops, array( 'result' => wp_json_encode( $response ) ), array( 'operation_key' => $key ), array( '%s' ), array( '%s' ) ) );
			self::check( $wpdb->query( 'COMMIT' ) );
			$committed = true;
			return $response;
		} catch ( Throwable $error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $error->getMessage(), array( 'source' => 'variation-stock-report', 'owner_id' => $owner_id ) );
			}
			return new WP_Error( 'stock_command_failed', $error->getMessage() );
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
			VSR_Tracker::clear_context();
			self::clear_cache( $owner_id );
			self::clear_cache( $item_id );
		}
	}

	private static function check( $result ) {
		if ( false === $result ) {
			throw new RuntimeException( 'Inventory database write failed.' );
		}
	}

	private static function clear_cache( $id ) {
		wp_cache_delete( 'lookup_table', 'object_' . $id );
		clean_post_cache( $id );
		wc_delete_product_transients( $id );
		wp_cache_delete( 'product-' . $id, 'products' );
	}
}
