<?php
/**
 * Database persistence and reporting queries.
 *
 * @package VariationStockReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persistence and reporting queries.
 */
class VSR_DB {

	/**
	 * Per-request cache for summary results keyed by filter hash.
	 *
	 * @var array<string,array>
	 */
	private static $summary_cache = array();

	public static function assert_query() {
		global $wpdb;
		if ( $wpdb->last_error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $wpdb->last_error, array( 'source' => 'variation-stock-report' ) );
			}
			throw new RuntimeException( 'Inventory database query failed. Check WooCommerce logs.' );
		}
	}

	public static function audit_failure( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'variation-stock-report' ) );
		}
		update_option( 'vsr_audit_write_error', current_time( 'mysql' ), false );
	}

	public static function audit_notice() {
		$failed_at = get_option( 'vsr_audit_write_error' );
		if ( $failed_at && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'An inventory audit write failed at %s. Report commands roll back on audit failure; stock changes made by other integrations require reconciliation. Check WooCommerce logs (variation-stock-report).', 'variation-stock-report' ), $failed_at ) ) . '</p></div>';
		}
	}

	/**
	 * Return the fully-prefixed log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . VSR_TABLE_NAME;
	}

	/**
	 * Insert one stock movement.
	 *
	 * @param array $data Movement data.
	 * @return int|false Inserted/existing row ID, or false on failure.
	 */
	public static function log( $data ) {
		global $wpdb;

		$defaults = array(
			'variation_id'   => 0,
			'stock_owner_id' => 0,
			'product_id'     => 0,
			'change_type'    => 'adjustment',
			'quantity'       => 0,
			'stock_before'   => null,
			'stock_after'    => null,
			'order_id'       => null,
			'order_item_id'  => null,
			'user_id'        => get_current_user_id() ? get_current_user_id() : null,
			'source'         => 'unknown',
			'event_key'      => null,
			'note'           => '',
			'created_at'     => current_time( 'mysql' ),
		);
		$data = wp_parse_args( $data, $defaults );

		$allowed_types = array( 'sale', 'sale_reversal', 'restock', 'adjustment', 'opening_balance', 'historical_sale' );
		if ( ! in_array( $data['change_type'], $allowed_types, true ) ) {
			return false;
		}

		$data['variation_id']   = absint( $data['variation_id'] );
		$data['stock_owner_id'] = absint( $data['stock_owner_id'] ? $data['stock_owner_id'] : $data['variation_id'] );
		$data['product_id']     = absint( $data['product_id'] );
		$data['order_id']       = $data['order_id'] ? absint( $data['order_id'] ) : null;
		$data['order_item_id']  = $data['order_item_id'] ? absint( $data['order_item_id'] ) : null;
		$data['user_id']        = $data['user_id'] ? absint( $data['user_id'] ) : null;
		$data['source']         = self::limit_text( sanitize_key( $data['source'] ), 40 );
		$data['event_key']      = $data['event_key'] ? self::limit_text( sanitize_text_field( $data['event_key'] ), 191 ) : null;
		$data['note']           = self::limit_text( sanitize_text_field( $data['note'] ), 191 );
		$data['created_at']     = self::sanitize_mysql_datetime( $data['created_at'] );
		$data['quantity']       = self::normalize_decimal( $data['quantity'] );
		$data['stock_before']   = self::normalize_decimal( $data['stock_before'], true );
		$data['stock_after']    = self::normalize_decimal( $data['stock_after'], true );

		if ( false === $data['quantity'] || false === $data['stock_before'] || false === $data['stock_after'] ) {
			return false;
		}

		if ( ! $data['variation_id'] || ! $data['stock_owner_id'] || ! $data['product_id'] ) {
			return false;
		}

		if ( $data['event_key'] ) {
			$existing_id = $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE event_key = %s LIMIT 1', $data['event_key'] ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			if ( $existing_id ) {
				return (int) $existing_id;
			}
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'variation_id'   => $data['variation_id'],
				'stock_owner_id' => $data['stock_owner_id'],
				'product_id'     => $data['product_id'],
				'change_type'    => $data['change_type'],
				'quantity'       => $data['quantity'],
				'stock_before'   => $data['stock_before'],
				'stock_after'    => $data['stock_after'],
				'order_id'       => $data['order_id'],
				'order_item_id'  => $data['order_item_id'],
				'user_id'        => $data['user_id'],
				'source'         => $data['source'],
				'event_key'      => $data['event_key'],
				'note'           => $data['note'],
				'created_at'     => $data['created_at'],
			),
			array( '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		self::$summary_cache = array();

		if ( false === $inserted ) {
			if ( $data['event_key'] ) {
				$existing_id = $wpdb->get_var(
					$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE event_key = %s LIMIT 1', $data['event_key'] ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				);
				if ( ! $existing_id ) {
					self::audit_failure( 'Inventory audit insert failed.' );
				}
				return $existing_id ? (int) $existing_id : false;
			}
			self::audit_failure( 'Inventory audit insert failed.' );
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Reclassify a provisional movement after WooCommerce provides order context.
	 *
	 * @param int   $log_id Row ID.
	 * @param array $data   Fields to update.
	 * @return bool
	 */
	public static function update_log( $log_id, $data ) {
		global $wpdb;

		$log_id = absint( $log_id );
		if ( ! $log_id || ! is_array( $data ) ) {
			return false;
		}

		$allowed_types = array( 'sale', 'sale_reversal', 'restock', 'adjustment', 'opening_balance', 'historical_sale' );
		$allowed       = array(
			'variation_id'   => '%d',
			'stock_owner_id' => '%d',
			'product_id'     => '%d',
			'change_type'    => '%s',
			'quantity'       => '%f',
			'stock_before'   => '%f',
			'stock_after'    => '%f',
			'order_id'       => '%d',
			'order_item_id'  => '%d',
			'user_id'        => '%d',
			'source'         => '%s',
			'event_key'      => '%s',
			'note'           => '%s',
			'created_at'     => '%s',
		);
		$update        = array();
		$formats       = array();

		foreach ( $allowed as $field => $format ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$value = $data[ $field ];

			switch ( $field ) {
				case 'variation_id':
				case 'stock_owner_id':
				case 'product_id':
					$value = absint( $value );
					break;

				case 'order_id':
				case 'order_item_id':
				case 'user_id':
					$value = $value ? absint( $value ) : null;
					break;

				case 'change_type':
					$value = sanitize_key( $value );
					if ( ! in_array( $value, $allowed_types, true ) ) {
						continue 2;
					}
					break;

				case 'quantity':
					$value = self::normalize_decimal( $value );
					if ( false === $value ) {
						continue 2;
					}
					break;

				case 'stock_before':
				case 'stock_after':
					$value = self::normalize_decimal( $value, true );
					if ( false === $value ) {
						continue 2;
					}
					break;

				case 'source':
					$value = self::limit_text( sanitize_key( $value ), 40 );
					break;

				case 'event_key':
					$value = $value ? self::limit_text( sanitize_text_field( $value ), 191 ) : null;
					break;

				case 'note':
					$value = self::limit_text( sanitize_text_field( $value ), 191 );
					break;

				case 'created_at':
					$value = self::sanitize_mysql_datetime( $value );
					break;
			}

			$update[ $field ] = $value;
			$formats[]         = $format;
		}

		if ( empty( $update ) ) {
			return false;
		}

		self::$summary_cache = array();

		$result = $wpdb->update(
			self::table(),
			$update,
			array( 'id' => $log_id ),
			$formats,
			array( '%d' )
		);
		if ( false === $result ) {
			self::audit_failure( 'Inventory audit reclassification failed.' );
		}
		return false !== $result;
	}

	/**
	 * Determine whether an order already has tracked sales.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function order_has_logs( $order_id ) {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE order_id = %d AND change_type IN ('sale','sale_reversal')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				absint( $order_id )
			)
		);
		self::assert_query();
		return (int) $count > 0;
	}

	/**
	 * Check whether one order line already has a recorded sale.
	 *
	 * @param int $order_id Order ID.
	 * @param int $order_item_id Order item ID.
	 * @return bool
	 */
	public static function order_item_has_sale( $order_id, $order_item_id ) {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE order_id = %d AND order_item_id = %d AND change_type IN ('sale','historical_sale')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				absint( $order_id ),
				absint( $order_item_id )
			)
		);
		self::assert_query();
		return (int) $count > 0;
	}

	/**
	 * Get a paginated report.
	 *
	 * Reuses the summary query's total item count so the heavy report SQL runs
	 * only twice per page load (once for summary+count, once for the page rows)
	 * instead of three times.
	 *
	 * @param array $filters Report filters.
	 * @param int   $page    Page number.
	 * @param int   $per_page Rows per page.
	 * @return array{rows:array,total:int,pages:int,page:int}
	 */
	public static function get_report_page( $filters, $page = 1, $per_page = 30 ) {
		global $wpdb;

		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 500, absint( $per_page ) ) );
		$summary  = self::get_summary( $filters );
		$total    = isset( $summary['matching_items'] ) ? (int) $summary['matching_items'] : 0;
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $page, $pages );
		$offset   = ( $page - 1 ) * $per_page;

		$params    = array();
		$base_sql  = self::build_report_sql( $filters, $params );
		$prepared  = self::prepare_sql( $base_sql, $params );
		$order_sql = self::get_order_sql( $filters );
		$rows_sql  = "SELECT * FROM ({$prepared}) vsr_report {$order_sql} LIMIT " . (int) $per_page . ' OFFSET ' . (int) $offset;
		$rows      = $wpdb->get_results( $rows_sql );
		self::assert_query(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? array_map( array( __CLASS__, 'enrich_report_row' ), $rows ) : array(),
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		);
	}

	/**
	 * Return dashboard totals for the active filters.
	 *
	 * @param array $filters Report filters.
	 * @return array
	 */
	public static function get_summary( $filters ) {
		global $wpdb;

		$cache_key = md5( wp_json_encode( $filters ) );
		if ( isset( self::$summary_cache[ $cache_key ] ) ) {
			return self::$summary_cache[ $cache_key ];
		}

		$params   = array();
		$base_sql = self::build_report_sql( $filters, $params );
		$prepared = self::prepare_sql( $base_sql, $params );

		$summary = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(item_count), 0) AS matching_items,
				COALESCE(SUM(total_sold), 0) AS total_sold,
				COALESCE(SUM(current_stock), 0) AS current_stock,
				COALESCE(SUM(stock_retail_value), 0) AS stock_retail_value,
				COALESCE(SUM(total_restocked), 0) AS total_restocked,
				COALESCE(SUM(total_adjusted), 0) AS total_adjusted
			FROM (
				SELECT
					stock_owner_id,
					COUNT(*) AS item_count,
					SUM(total_sold) AS total_sold,
					MAX(CASE WHEN manages_stock = 1 THEN stock_quantity ELSE 0 END) AS current_stock,
					MAX(CASE WHEN manages_stock = 1 AND stock_quantity > 0 THEN stock_quantity * owner_unit_price ELSE 0 END) AS stock_retail_value,
					MAX(CASE WHEN manages_stock = 1 THEN owner_restocked ELSE 0 END) AS total_restocked,
					MAX(CASE WHEN manages_stock = 1 THEN owner_adjusted ELSE 0 END) AS total_adjusted
				FROM ({$prepared}) vsr_summary_rows
				GROUP BY stock_owner_id
			) vsr_summary" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $prepared is generated exclusively by wpdb::prepare().
		);

		self::assert_query();
		$result = array(
			'matching_items'     => $summary ? (int) $summary->matching_items : 0,
			'current_stock'      => $summary ? (float) $summary->current_stock : 0,
			'stock_retail_value' => $summary ? (float) $summary->stock_retail_value : 0,
			'total_sold'         => $summary ? (float) $summary->total_sold : 0,
			'total_restocked'    => $summary ? (float) $summary->total_restocked : 0,
			'total_adjusted'     => $summary ? (float) $summary->total_adjusted : 0,
		);

		self::$summary_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Get one export batch without recalculating a report count for every chunk.
	 *
	 * @param array $filters  Report filters.
	 * @param int   $offset   Row offset.
	 * @param int   $per_page Rows per batch.
	 * @return array
	 */
	public static function get_export_rows( $filters, $offset = 0, $per_page = 500 ) {
		global $wpdb;

		$offset    = max( 0, absint( $offset ) );
		$per_page  = max( 1, min( 1000, absint( $per_page ) ) );
		$params    = array();
		$base_sql  = self::build_report_sql( $filters, $params );
		$prepared  = self::prepare_sql( $base_sql, $params );
		$order_sql = self::get_order_sql( $filters );
		$rows_sql  = "SELECT * FROM ({$prepared}) vsr_export {$order_sql} LIMIT " . (int) $per_page . ' OFFSET ' . (int) $offset;
		$rows      = $wpdb->get_results( $rows_sql );
		self::assert_query(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared report SQL plus integer LIMIT/OFFSET and allowlisted ORDER BY.

		return is_array( $rows ) ? array_map( array( __CLASS__, 'enrich_report_row' ), $rows ) : array();
	}

	/**
	 * Retrieve stock movement audit logs for an item or globally.
	 *
	 * @param array $args Query arguments (item_id, owner_id, change_type, limit, offset).
	 * @return array{rows:array,total:int}
	 */
	public static function get_audit_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'item_id'     => 0,
			'owner_id'    => 0,
			'change_type' => '',
			'limit'       => 50,
			'offset'      => 0,
			'before_id' => 0,
			'snapshot_id' => 0,
			'count' => true,
		);
		$args = wp_parse_args( $args, $defaults );

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		$item_id  = absint( $args['item_id'] );
		$owner_id = absint( $args['owner_id'] );

		if ( $item_id && $owner_id && $item_id !== $owner_id ) {
			$where[]  = '(l.variation_id = %d OR l.stock_owner_id = %d)';
			$params[] = $item_id;
			$params[] = $owner_id;
		} elseif ( $item_id ) {
			$where[]  = '(l.variation_id = %d OR l.stock_owner_id = %d)';
			$params[] = $item_id;
			$params[] = $item_id;
		} elseif ( $owner_id ) {
			$where[]  = 'l.stock_owner_id = %d';
			$params[] = $owner_id;
		}

		$allowed_types = array( 'sale', 'sale_reversal', 'restock', 'adjustment', 'opening_balance', 'historical_sale' );
		if ( ! empty( $args['change_type'] ) && in_array( $args['change_type'], $allowed_types, true ) ) {
			$where[] = "(CASE WHEN l.source='historical_import' AND l.change_type='sale' THEN 'historical_sale' ELSE l.change_type END) = %s";
			$params[] = $args['change_type'];
		}

		$snapshot = absint( $args['snapshot_id'] );
		if ( ! $snapshot ) {
			$snapshot = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
			self::assert_query();
		}
		$where[] = 'l.id <= %d';
		$params[] = $snapshot;
		if ( $args['before_id'] ) {
			$where[] = 'l.id < %d';
			$params[] = absint( $args['before_id'] );
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = max( 1, min( 500, absint( $args['limit'] ) ) );
		$offset    = max( 0, absint( $args['offset'] ) );

		$count_sql = "SELECT COUNT(*) FROM {$table} l WHERE {$where_sql}";
		$total = $args['count'] ? (int) $wpdb->get_var( self::prepare_sql( $count_sql, $params ) ) : 0;
		self::assert_query(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$query_sql = "
			SELECT
				l.*,
				CASE WHEN l.source='historical_import' AND l.change_type='sale' THEN 'historical_sale' ELSE l.change_type END AS change_type,
				COALESCE(p.post_title, CONCAT('#', l.product_id)) AS product_name,
				COALESCE(u.display_name, '') AS user_name
			FROM {$table} l
			LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
			LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
			WHERE {$where_sql}
			ORDER BY l.id DESC
			LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;

		$rows = $wpdb->get_results( self::prepare_sql( $query_sql, $params ) );
		self::assert_query(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'snapshot_id' => $snapshot,
			'next_id' => $rows ? (int) end( $rows )->id : 0,
		);
	}

	/**
	 * Compute velocity, days of stock left, and reorder suggestion on a report row.
	 *
	 * @param object $row Raw SQL row.
	 * @return object
	 */
	private static function enrich_report_row( $row ) {
		$sold_30d = 'shared' === $row->inventory_mode ? max( 0.0, (float) $row->owner_sold_30d ) : max( 0.0, (float) $row->sold_30d );
		$daily_velocity = $sold_30d / 30.0;
		$stock          = (float) $row->stock_quantity;
		$manages_stock  = (bool) $row->manages_stock;

		$row->daily_velocity = round( $daily_velocity, 2 );
		$row->days_left      = null;
		$row->reorder_qty    = 0.0;

		if ( $manages_stock && $daily_velocity > 0.0001 ) {
			$row->days_left   = $stock > 0 ? (int) floor( $stock / $daily_velocity ) : 0;
			$target_30d_stock = ceil( $daily_velocity * 30.0 );
			$row->reorder_qty = max( 0.0, $target_30d_stock - max( 0.0, $stock ) );
		}

		return $row;
	}

	/**
	 * Build the SQL source used for Sold / Last Sale.
	 *
	 * @return array{joins:string,created_date_expr:string,completed_date_expr:string}
	 */
	private static function completed_order_item_source() {
		global $wpdb;

		$order_items    = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$hpos_enabled   = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos_enabled ) {
			$orders_table      = $wpdb->prefix . 'wc_orders';
			$operational_table = $wpdb->prefix . 'wc_order_operational_data';
			$joins             = "
				FROM {$order_items} vsr_oi
				INNER JOIN {$orders_table} vsr_order
					ON vsr_order.id = vsr_oi.order_id
					AND vsr_order.type = 'shop_order'
					AND vsr_order.status = 'wc-completed'
				LEFT JOIN {$operational_table} vsr_operational
					ON vsr_operational.order_id = vsr_order.id
				INNER JOIN {$order_itemmeta} vsr_qty
					ON vsr_qty.order_item_id = vsr_oi.order_item_id
					AND vsr_qty.meta_key = '_qty'
				INNER JOIN {$order_itemmeta} vsr_product
					ON vsr_product.order_item_id = vsr_oi.order_item_id
					AND vsr_product.meta_key = '_product_id'
				LEFT JOIN {$order_itemmeta} vsr_variation
					ON vsr_variation.order_item_id = vsr_oi.order_item_id
					AND vsr_variation.meta_key = '_variation_id'
			";

			return array(
				'joins'               => $joins,
				'created_date_expr'   => 'vsr_order.date_created_gmt',
				'completed_date_expr' => 'COALESCE(vsr_operational.date_completed_gmt, vsr_order.date_created_gmt)',
			);
		}

		$joins = "
			FROM {$order_items} vsr_oi
			INNER JOIN {$wpdb->posts} vsr_order
				ON vsr_order.ID = vsr_oi.order_id
				AND vsr_order.post_type = 'shop_order'
				AND vsr_order.post_status = 'wc-completed'
			LEFT JOIN {$wpdb->postmeta} vsr_completed
				ON vsr_completed.post_id = vsr_order.ID
				AND vsr_completed.meta_key = '_date_completed'
			INNER JOIN {$order_itemmeta} vsr_qty
				ON vsr_qty.order_item_id = vsr_oi.order_item_id
				AND vsr_qty.meta_key = '_qty'
			INNER JOIN {$order_itemmeta} vsr_product
				ON vsr_product.order_item_id = vsr_oi.order_item_id
				AND vsr_product.meta_key = '_product_id'
			LEFT JOIN {$order_itemmeta} vsr_variation
				ON vsr_variation.order_item_id = vsr_oi.order_item_id
				AND vsr_variation.meta_key = '_variation_id'
		";

		$date_expr = "CASE
			WHEN CAST(COALESCE(vsr_completed.meta_value, '0') AS UNSIGNED) > 0
				THEN DATE_ADD('1970-01-01 00:00:00', INTERVAL CAST(vsr_completed.meta_value AS UNSIGNED) SECOND)
			WHEN vsr_order.post_date_gmt IS NOT NULL AND vsr_order.post_date_gmt <> '0000-00-00 00:00:00'
				THEN vsr_order.post_date_gmt
			ELSE vsr_order.post_date
		END";

		$created_date_expr = "CASE
			WHEN vsr_order.post_date_gmt IS NOT NULL AND vsr_order.post_date_gmt <> '0000-00-00 00:00:00'
				THEN vsr_order.post_date_gmt
			ELSE vsr_order.post_date
		END";

		return array(
			'joins'               => $joins,
			'created_date_expr'   => $created_date_expr,
			'completed_date_expr' => $date_expr,
		);
	}

	/**
	 * Build the report query without order or pagination.
	 *
	 * @param array $filters Filters.
	 * @param array $params  Prepared statement values, passed by reference.
	 * @return string
	 */
	private static function build_report_sql( $filters, &$params ) {
		global $wpdb;

		$table  = self::table();
		$params = array();

		$sales_source              = self::completed_order_item_source();
		$sales_joins               = $sales_source['joins'];
		$sales_created_date_expr   = $sales_source['created_date_expr'];
		$sales_completed_date_expr = $sales_source['completed_date_expr'];
		$sales_item_expr           = "CASE
			WHEN CAST(COALESCE(NULLIF(vsr_variation.meta_value,''), '0') AS UNSIGNED) > 0
				THEN CAST(vsr_variation.meta_value AS UNSIGNED)
			ELSE CAST(vsr_product.meta_value AS UNSIGNED)
		END";

		$date_clause_sales = '';
		$date_clause_stock = '';
		$sales_date_params = array();
		$stock_date_params = array();

		if ( ! empty( $filters['date_from'] ) ) {
			$sales_from          = get_gmt_from_date( $filters['date_from'] . ' 00:00:00' );
			$date_clause_sales  .= " AND {$sales_created_date_expr} >= %s";
			$date_clause_stock  .= ' AND created_at >= %s';
			$sales_date_params[] = $sales_from;
			$stock_date_params[] = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$sales_to            = get_gmt_from_date( $filters['date_to'] . ' 23:59:59' );
			$date_clause_sales  .= " AND {$sales_created_date_expr} <= %s";
			$date_clause_stock  .= ' AND created_at <= %s';
			$sales_date_params[] = $sales_to;
			$stock_date_params[] = $filters['date_to'] . ' 23:59:59';
		}

		$cutoff_30d_local  = current_datetime()->modify( '-30 days' )->format( 'Y-m-d H:i:s' );
		$cutoff_30d_gmt    = get_gmt_from_date( $cutoff_30d_local );
		$default_low       = (float) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$tz_offset_seconds = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

		// Aggregated postmeta per post_id prevents row multiplication when duplicate
		// postmeta keys exist in the database and replaces 10 separate postmeta joins.
		$meta_map_sql = "
			SELECT
				post_id,
				MAX(CASE WHEN meta_key = '_sku' THEN meta_value END) AS sku,
				MAX(CASE WHEN meta_key = '_manage_stock' THEN meta_value END) AS manage_stock,
				MAX(CASE WHEN meta_key = '_stock' THEN meta_value END) AS stock,
				MAX(CASE WHEN meta_key = '_stock_status' THEN meta_value END) AS stock_status,
				MAX(CASE WHEN meta_key = '_low_stock_amount' THEN meta_value END) AS low_stock_amount,
				MAX(CASE WHEN meta_key = '_price' THEN meta_value END) AS price,
				MAX(CASE WHEN meta_key = '_regular_price' THEN meta_value END) AS regular_price
			FROM {$wpdb->postmeta}
			WHERE meta_key IN ('_sku','_manage_stock','_stock','_stock_status','_low_stock_amount','_price','_regular_price')
			GROUP BY post_id
		";

		$inventory_sql = "
			SELECT
				v.ID AS item_id,
				v.post_parent AS product_id,
				p.post_title AS product_name,
				COALESCE(NULLIF(vmeta.sku,''), pmeta.sku, '') AS sku,
				CASE
					WHEN vmeta.manage_stock = 'yes' THEN v.ID
					WHEN pmeta.manage_stock = 'yes' THEN p.ID
					ELSE v.ID
				END AS stock_owner_id,
				CASE
					WHEN vmeta.manage_stock = 'yes' THEN CAST(vmeta.stock AS DECIMAL(19,4))
					WHEN pmeta.manage_stock = 'yes' THEN CAST(pmeta.stock AS DECIMAL(19,4))
					ELSE NULL
				END AS stock_quantity,
				CASE WHEN vmeta.manage_stock = 'yes' OR pmeta.manage_stock = 'yes' THEN 1 ELSE 0 END AS manages_stock,
				CASE WHEN vmeta.manage_stock = 'yes' THEN 'variation' WHEN pmeta.manage_stock = 'yes' THEN 'shared' ELSE 'disabled' END AS inventory_mode,
				CASE
					WHEN vmeta.manage_stock = 'yes' THEN COALESCE(NULLIF(vmeta.stock_status,''), 'instock')
					WHEN pmeta.manage_stock = 'yes' THEN COALESCE(NULLIF(pmeta.stock_status,''), 'instock')
					ELSE COALESCE(NULLIF(vmeta.stock_status,''), 'instock')
				END AS stock_status,
				CASE
					WHEN vmeta.manage_stock = 'yes' AND vmeta.low_stock_amount IS NOT NULL AND vmeta.low_stock_amount <> '' THEN CAST(vmeta.low_stock_amount AS DECIMAL(19,4))
					WHEN pmeta.manage_stock = 'yes' AND pmeta.low_stock_amount IS NOT NULL AND pmeta.low_stock_amount <> '' THEN CAST(pmeta.low_stock_amount AS DECIMAL(19,4))
					ELSE NULL
				END AS low_stock_amount,
				CAST(COALESCE(NULLIF(vmeta.price,''), NULLIF(vmeta.regular_price,''), NULLIF(pmeta.price,''), NULLIF(pmeta.regular_price,''), '0') AS DECIMAL(19,4)) AS unit_price,
				'variation' AS row_type
			FROM {$wpdb->posts} v
			INNER JOIN {$wpdb->posts} p ON p.ID = v.post_parent
			LEFT JOIN ({$meta_map_sql}) vmeta ON vmeta.post_id = v.ID
			LEFT JOIN ({$meta_map_sql}) pmeta ON pmeta.post_id = p.ID
			WHERE v.post_type = 'product_variation'
				AND v.post_status IN ('publish','private')
				AND p.post_status IN ('publish','private')

			UNION ALL

			SELECT
				sp.ID AS item_id,
				sp.ID AS product_id,
				sp.post_title AS product_name,
				COALESCE(smeta.sku, '') AS sku,
				sp.ID AS stock_owner_id,
				CASE WHEN smeta.manage_stock = 'yes' THEN CAST(smeta.stock AS DECIMAL(19,4)) ELSE NULL END AS stock_quantity,
				CASE WHEN smeta.manage_stock = 'yes' THEN 1 ELSE 0 END AS manages_stock,
				CASE WHEN smeta.manage_stock = 'yes' THEN 'simple' ELSE 'disabled' END AS inventory_mode,
				COALESCE(NULLIF(smeta.stock_status,''), 'instock') AS stock_status,
				CASE WHEN smeta.low_stock_amount IS NOT NULL AND smeta.low_stock_amount <> '' THEN CAST(smeta.low_stock_amount AS DECIMAL(19,4)) ELSE NULL END AS low_stock_amount,
				CAST(COALESCE(NULLIF(smeta.price,''), NULLIF(smeta.regular_price,''), '0') AS DECIMAL(19,4)) AS unit_price,
				'simple' AS row_type
			FROM {$wpdb->posts} sp
			LEFT JOIN ({$meta_map_sql}) smeta ON smeta.post_id = sp.ID
			WHERE sp.post_type = 'product'
				AND sp.post_status IN ('publish','private')
				AND EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} ptr
					INNER JOIN {$wpdb->term_taxonomy} ptt ON ptt.term_taxonomy_id = ptr.term_taxonomy_id AND ptt.taxonomy = 'product_type'
					INNER JOIN {$wpdb->terms} pt ON pt.term_id = ptt.term_id AND pt.slug = 'simple'
					WHERE ptr.object_id = sp.ID
				)
		";

		// Resolve taxonomy attribute slugs (e.g. attribute_pa_color -> Red) to human-readable
		// term names and skip empty wildcard attributes so labels never have dangling slashes.
		$attribute_sql = "
			SELECT
				attr_clean.post_id,
				GROUP_CONCAT(attr_clean.attr_display ORDER BY attr_clean.meta_key SEPARATOR ' / ') AS attribute_label,
				GROUP_CONCAT(attr_clean.attr_display ORDER BY attr_clean.meta_key SEPARATOR '||') AS attribute_sort_key
			FROM (
				SELECT
					pm.post_id,
					pm.meta_key,
					COALESCE(MAX(CASE WHEN tt.term_id IS NOT NULL THEN t.name END), MAX(pm.meta_value)) AS attr_display
				FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->terms} t ON t.slug = pm.meta_value AND pm.meta_key LIKE 'attribute\\_pa\\_%'
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = SUBSTRING(pm.meta_key, 11)
				WHERE pm.meta_key LIKE 'attribute\\_%'
					AND pm.meta_value <> ''
				GROUP BY pm.post_id, pm.meta_key
			) attr_clean
			GROUP BY attr_clean.post_id
		";

		$sql = "
			SELECT
				i.*,
				CAST(COALESCE(NULLIF(ometa.price,''), NULLIF(ometa.regular_price,''), '0') AS DECIMAL(19,4)) AS owner_unit_price,
				COALESCE(owner_velocity.sold_30d, 0) AS owner_sold_30d,
				CASE
					WHEN i.manages_stock = 1 AND i.stock_quantity > 0 THEN ROUND(i.stock_quantity * CAST(COALESCE(NULLIF(ometa.price,''), NULLIF(ometa.regular_price,''), '0') AS DECIMAL(19,4)), 4)
					ELSE 0
				END AS stock_retail_value,
				COALESCE(attrs.attribute_label, '') AS attribute_label,
				COALESCE(attrs.attribute_sort_key, '') AS attribute_sort_key,
				COALESCE(period_sales.total_sold, 0) AS total_sold,
				COALESCE(velocity_sales.sold_30d, 0) AS sold_30d,
				COALESCE(period_stock.total_restocked, 0) AS owner_restocked,
				COALESCE(period_stock.total_adjusted, 0) AS owner_adjusted,
				CASE
					WHEN i.inventory_mode = 'shared' THEN COALESCE(item_stock.total_restocked, 0)
					ELSE COALESCE(period_stock.total_restocked, 0)
				END AS total_restocked,
				CASE
					WHEN i.inventory_mode = 'shared' THEN COALESCE(item_stock.total_adjusted, 0)
					ELSE COALESCE(period_stock.total_adjusted, 0)
				END AS total_adjusted,
				CASE
					WHEN i.inventory_mode = 'shared' AND (COALESCE(period_stock.total_restocked, 0) > 0 OR COALESCE(period_stock.total_adjusted, 0) > 0) THEN 1
					ELSE 0
				END AS is_shared_pool_movement,
				NULLIF(GREATEST(COALESCE(lifetime_sales.last_sale_date, '1000-01-01 00:00:00'), COALESCE(lifetime_log_sales.last_log_sale_gmt, '1000-01-01 00:00:00')), '1000-01-01 00:00:00') AS last_sale_date,
				lifetime_stock.last_restock_date
			FROM ({$inventory_sql}) i
			LEFT JOIN ({$meta_map_sql}) ometa ON ometa.post_id = i.stock_owner_id
			LEFT JOIN (
				SELECT CASE WHEN soldpost.post_type = 'product_variation'
					AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} sm WHERE sm.post_id=soldpost.ID AND sm.meta_key='_manage_stock' AND sm.meta_value='yes')
					AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} sm WHERE sm.post_id=soldpost.post_parent AND sm.meta_key='_manage_stock' AND sm.meta_value='yes')
					THEN soldpost.post_parent ELSE soldpost.ID END AS owner_id,
					SUM(GREATEST(0, CAST(vsr_qty.meta_value AS DECIMAL(19,4)))) AS sold_30d
				{$sales_joins}
				INNER JOIN {$wpdb->posts} soldpost ON soldpost.ID = {$sales_item_expr}
				WHERE vsr_oi.order_item_type = 'line_item' AND {$sales_created_date_expr} >= %s
				GROUP BY owner_id
			) owner_velocity ON owner_velocity.owner_id = i.stock_owner_id
			LEFT JOIN ({$attribute_sql}) attrs ON attrs.post_id = i.item_id
			LEFT JOIN (
				SELECT
					{$sales_item_expr} AS variation_id,
					SUM(GREATEST(0, CAST(vsr_qty.meta_value AS DECIMAL(19,4)))) AS total_sold
				{$sales_joins}
				WHERE vsr_oi.order_item_type = 'line_item' {$date_clause_sales}
				GROUP BY variation_id
			) period_sales ON period_sales.variation_id = i.item_id
			LEFT JOIN (
				SELECT
					{$sales_item_expr} AS variation_id,
					SUM(GREATEST(0, CAST(vsr_qty.meta_value AS DECIMAL(19,4)))) AS sold_30d
				{$sales_joins}
				WHERE vsr_oi.order_item_type = 'line_item'
					AND {$sales_created_date_expr} >= %s
				GROUP BY variation_id
			) velocity_sales ON velocity_sales.variation_id = i.item_id
			LEFT JOIN (
				SELECT
					stock_owner_id,
					SUM(CASE WHEN change_type = 'restock' THEN quantity ELSE 0 END) AS total_restocked,
					ABS(SUM(CASE WHEN change_type = 'adjustment' THEN quantity ELSE 0 END)) AS total_adjusted
				FROM {$table}
				WHERE change_type IN ('restock','adjustment') {$date_clause_stock}
				GROUP BY stock_owner_id
			) period_stock ON period_stock.stock_owner_id = i.stock_owner_id
			LEFT JOIN (
				SELECT
					variation_id,
					SUM(CASE WHEN change_type = 'restock' THEN quantity ELSE 0 END) AS total_restocked,
					ABS(SUM(CASE WHEN change_type = 'adjustment' THEN quantity ELSE 0 END)) AS total_adjusted
				FROM {$table}
				WHERE change_type IN ('restock','adjustment') {$date_clause_stock}
				GROUP BY variation_id
			) item_stock ON item_stock.variation_id = i.item_id
			LEFT JOIN (
				SELECT
					{$sales_item_expr} AS variation_id,
					MAX({$sales_completed_date_expr}) AS last_sale_date
				{$sales_joins}
				WHERE vsr_oi.order_item_type = 'line_item'
					AND CAST(vsr_qty.meta_value AS DECIMAL(19,4)) > 0
				GROUP BY variation_id
			) lifetime_sales ON lifetime_sales.variation_id = i.item_id
			LEFT JOIN (
				SELECT
					variation_id,
					DATE_SUB(MAX(created_at), INTERVAL " . (int) $tz_offset_seconds . " SECOND) AS last_log_sale_gmt
				FROM {$table}
				WHERE change_type = 'sale'
				GROUP BY variation_id
			) lifetime_log_sales ON lifetime_log_sales.variation_id = i.item_id
			LEFT JOIN (
				SELECT stock_owner_id, MAX(created_at) AS last_restock_date
				FROM {$table}
				WHERE change_type = 'restock'
				GROUP BY stock_owner_id
			) lifetime_stock ON lifetime_stock.stock_owner_id = i.stock_owner_id
			WHERE 1=1
		";

		$params = array_merge(
			array( $cutoff_30d_gmt ),
			$sales_date_params,
			array( $cutoff_30d_gmt ),
			$stock_date_params,
			$stock_date_params
		);

		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$sql     .= ' AND (i.product_name LIKE %s OR i.sku LIKE %s OR attrs.attribute_label LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $filters['category'] ) ) {
			$term_ids = self::expand_category_ids( $filters['category'] );
			if ( $term_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
				$sql         .= " AND EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} ctr
					INNER JOIN {$wpdb->term_taxonomy} ctt ON ctt.term_taxonomy_id = ctr.term_taxonomy_id AND ctt.taxonomy = 'product_cat'
					WHERE ctr.object_id = i.product_id AND ctt.term_id IN ({$placeholders})
				)";
				$params       = array_merge( $params, $term_ids );
			}
		}

		if ( ! empty( $filters['health_filter'] ) ) {
			switch ( $filters['health_filter'] ) {
				case 'outofstock':
					$sql .= " AND i.manages_stock = 1 AND (i.stock_quantity <= 0 OR i.stock_status = 'outofstock')";
					break;
				case 'lowstock':
					$sql     .= " AND i.manages_stock = 1 AND i.stock_quantity > 0 AND i.stock_status <> 'outofstock' AND i.stock_quantity <= COALESCE(i.low_stock_amount, %f)";
					$params[] = $default_low;
					break;
				case 'instock':
					$sql     .= " AND i.manages_stock = 1 AND i.stock_status <> 'outofstock' AND i.stock_quantity > COALESCE(i.low_stock_amount, %f)";
					$params[] = $default_low;
					break;
				case 'shared':
					$sql .= " AND i.inventory_mode = 'shared'";
					break;
				case 'unmanaged':
					$sql .= ' AND i.manages_stock = 0';
					break;
			}
		}

		if ( 'never' === $filters['not_selling_days'] ) {
			$sql .= " AND NULLIF(GREATEST(COALESCE(lifetime_sales.last_sale_date, '1000-01-01 00:00:00'), COALESCE(lifetime_log_sales.last_log_sale_gmt, '1000-01-01 00:00:00')), '1000-01-01 00:00:00') IS NULL";
		} elseif ( is_numeric( $filters['not_selling_days'] ) && (int) $filters['not_selling_days'] > 0 ) {
			$local_cutoff = current_datetime()->modify( '-' . (int) $filters['not_selling_days'] . ' days' )->format( 'Y-m-d H:i:s' );
			$gmt_cutoff   = get_gmt_from_date( $local_cutoff );
			$sql         .= " AND (NULLIF(GREATEST(COALESCE(lifetime_sales.last_sale_date, '1000-01-01 00:00:00'), COALESCE(lifetime_log_sales.last_log_sale_gmt, '1000-01-01 00:00:00')), '1000-01-01 00:00:00') IS NULL OR NULLIF(GREATEST(COALESCE(lifetime_sales.last_sale_date, '1000-01-01 00:00:00'), COALESCE(lifetime_log_sales.last_log_sale_gmt, '1000-01-01 00:00:00')), '1000-01-01 00:00:00') < %s)";
			$params[]     = $gmt_cutoff;
		}

		if ( 'never' === $filters['restock_filter'] ) {
			$sql .= ' AND lifetime_stock.last_restock_date IS NULL';
		} elseif ( is_numeric( $filters['restock_filter'] ) && (int) $filters['restock_filter'] > 0 ) {
			$cutoff   = current_datetime()->modify( '-' . (int) $filters['restock_filter'] . ' days' )->format( 'Y-m-d H:i:s' );
			$sql     .= ' AND lifetime_stock.last_restock_date >= %s';
			$params[] = $cutoff;
		}

		return $sql;
	}

	/**
	 * Return a whitelist-only ORDER BY clause.
	 *
	 * @param array $filters Filters.
	 * @return string
	 */
	private static function get_order_sql( $filters ) {
		$allowed = array(
			'product_name'       => 'product_name',
			'attribute_sort_key' => 'attribute_sort_key',
			'sku'                => 'sku',
			'unit_price'         => 'unit_price',
			'stock_quantity'     => 'stock_quantity',
			'stock_retail_value' => 'stock_retail_value',
			'total_sold'         => 'total_sold',
			'sold_30d'           => 'sold_30d',
			'total_restocked'    => 'total_restocked',
			'total_adjusted'     => 'total_adjusted',
			'last_sale_date'     => 'last_sale_date',
			'last_restock_date'  => 'last_restock_date',
		);
		$field = isset( $allowed[ $filters['orderby'] ] ) ? $allowed[ $filters['orderby'] ] : 'product_name';
		$order = 'DESC' === strtoupper( $filters['order'] ) ? 'DESC' : 'ASC';

		return "ORDER BY {$field} {$order}, product_id ASC, attribute_sort_key ASC, item_id ASC";
	}

	/**
	 * Expand selected product categories to include descendants.
	 *
	 * @param array $category_ids Category IDs.
	 * @return array
	 */
	private static function expand_category_ids( $category_ids ) {
		$ids = array_filter( array_map( 'absint', (array) $category_ids ) );
		foreach ( $ids as $category_id ) {
			$children = get_term_children( $category_id, 'product_cat' );
			if ( ! is_wp_error( $children ) ) {
				$ids = array_merge( $ids, array_map( 'absint', $children ) );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Prepare SQL only when placeholders exist.
	 *
	 * @param string $sql SQL.
	 * @param array  $params Parameters.
	 * @return string
	 */
	private static function prepare_sql( $sql, $params ) {
		global $wpdb;
		return $params ? $wpdb->prepare( $sql, $params ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Normalize a decimal value to the range supported by the log schema.
	 *
	 * @param mixed $value    Raw value.
	 * @param bool  $nullable Whether null/empty is allowed.
	 * @return float|null|false
	 */
	private static function normalize_decimal( $value, $nullable = false ) {
		if ( $nullable && ( null === $value || '' === $value ) ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$value = (float) $value;
		if ( ! is_finite( $value ) || abs( $value ) > 999999999999999.0 ) {
			return false;
		}

		return $value;
	}

	/**
	 * Limit sanitized text without splitting multibyte text when mbstring exists.
	 *
	 * @param string $value  Text.
	 * @param int    $length Maximum character count.
	 * @return string
	 */
	private static function limit_text( $value, $length ) {
		$value  = (string) $value;
		$length = max( 0, absint( $length ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Normalize a MySQL datetime.
	 *
	 * @param string $datetime Datetime.
	 * @return string
	 */
	private static function sanitize_mysql_datetime( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime ) && false !== strtotime( $datetime ) ) {
			return $datetime;
		}

		$timestamp = strtotime( $datetime );
		return $timestamp ? wp_date( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql' );
	}
}
