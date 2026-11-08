<?php
/**
 * WooCommerce admin report, audit history modal, bulk stock update, and inline controls.
 *
 * @package VariationStockReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce admin report and inline stock controls.
 */
class VSR_Admin {

	/** Admin page slug. */
	const PAGE_SLUG = 'vsr-stock-report';

	/** Rows displayed per report page. */
	const PER_PAGE = 30;

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_vsr_update_stock', array( __CLASS__, 'ajax_update_stock' ) );
		add_action( 'wp_ajax_vsr_bulk_update_stock', array( __CLASS__, 'ajax_bulk_update_stock' ) );
		add_action( 'wp_ajax_vsr_get_audit_logs', array( __CLASS__, 'ajax_get_audit_logs' ) );
	}

	/**
	 * Add the WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Inventory Stock Report', 'variation-stock-report' ),
			__( 'Stock Report', 'variation-stock-report' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Load assets only on this plugin's admin page.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'vsr-admin',
			VSR_PLUGIN_URL . 'assets/admin.css',
			array(),
			VSR_VERSION
		);
		wp_enqueue_script(
			'vsr-admin',
			VSR_PLUGIN_URL . 'assets/admin.js',
			array(),
			VSR_VERSION,
			true
		);

		wp_localize_script(
			'vsr-admin',
			'vsrAdmin',
			array(
				'userId' => get_current_user_id(),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'adminPostUrl'   => admin_url( 'admin-post.php' ),
				'updateNonce'    => wp_create_nonce( 'vsr_update_stock' ),
				'bulkNonce'      => wp_create_nonce( 'vsr_bulk_update_stock' ),
				'importNonce'    => wp_create_nonce( 'vsr_import_batch' ),
				'auditNonce'     => wp_create_nonce( 'vsr_get_audit_logs' ),
				'exportAuditUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=vsr_export_audit_csv' ), 'vsr_export_audit_csv' ),
				'messages'       => array(
					'allCategories'      => __( 'All categories', 'variation-stock-report' ),
					'categoriesSelected' => __( '%d categories selected', 'variation-stock-report' ),
					'confirmBulk' => __( 'Apply this stock command to the selected physical inventories? Shared parent stock will be updated once.', 'variation-stock-report' ),
					'confirmImport'      => __( 'Scan historical completed orders as unverified sales evidence? This never changes physical stock. Existing tracked lines are skipped.', 'variation-stock-report' ),
					'resumeImportPrompt' => __( 'An unfinished historical import was found after order ID %d. Click OK to resume where it stopped, or Cancel to start fresh from the beginning.', 'variation-stock-report' ),
					'importComplete'     => __( 'Historical audit import complete. Reloading report…', 'variation-stock-report' ),
					'importFailed'       => __( 'Historical import failed.', 'variation-stock-report' ),
					'importProgress'     => __( 'Through order ID %1$d · %2$d orders imported · %3$d lines · %4$d skipped', 'variation-stock-report' ),
					'networkError'       => __( 'Network request failed. Please try again.', 'variation-stock-report' ),
					'savedReloading'     => __( 'Saved. Reloading totals…', 'variation-stock-report' ),
					'updating'           => __( 'Saving…', 'variation-stock-report' ),
					'updateFailed'       => __( 'Stock update failed.', 'variation-stock-report' ),
					'bulkSelectItems'    => __( 'Select at least one inventory item first.', 'variation-stock-report' ),
					'bulkInvalidQty'     => __( 'Enter a valid quantity for the bulk stock update.', 'variation-stock-report' ),
					'bulkUpdating'       => __( 'Applying bulk update…', 'variation-stock-report' ),
					'bulkSuccess'        => __( 'Updated %d items. Reloading report…', 'variation-stock-report' ),
					'auditLoading'       => __( 'Loading stock movement history…', 'variation-stock-report' ),
					'auditEmpty'         => __( 'No stock movements recorded for this selection yet.', 'variation-stock-report' ),
					'auditGlobalTitle'   => __( 'All Recent Stock Movements', 'variation-stock-report' ),
				),
			)
		);
	}

	/**
	 * Parse, sanitize, and whitelist report filters from the request.
	 *
	 * @return array
	 */
	public static function get_filters_from_request() {
		$category = isset( $_GET['vsr_category'] ) ? wp_unslash( $_GET['vsr_category'] ) : array();
		$category = is_array( $category ) ? $category : array( $category );

		$allowed_orderby = array(
			'product_name',
			'attribute_sort_key',
			'sku',
			'unit_price',
			'stock_quantity',
			'stock_retail_value',
			'total_sold',
			'sold_30d',
			'total_restocked',
			'total_adjusted',
			'last_sale_date',
			'last_restock_date',
		);
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'product_name';
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'product_name';
		}

		$not_selling = isset( $_GET['vsr_not_selling'] ) ? sanitize_key( wp_unslash( $_GET['vsr_not_selling'] ) ) : '';
		$restock     = isset( $_GET['vsr_restock'] ) ? sanitize_key( wp_unslash( $_GET['vsr_restock'] ) ) : '';
		$health      = isset( $_GET['vsr_health'] ) ? sanitize_key( wp_unslash( $_GET['vsr_health'] ) ) : '';

		if ( ! in_array( $not_selling, array( '', 'never', '30', '60', '90', '180' ), true ) ) {
			$not_selling = '';
		}
		if ( ! in_array( $restock, array( '', 'never', '7', '30', '90', '180' ), true ) ) {
			$restock = '';
		}
		if ( ! in_array( $health, array( '', 'outofstock', 'lowstock', 'instock', 'shared', 'unmanaged' ), true ) ) {
			$health = '';
		}

		$order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'ASC';
		$order = 'DESC' === $order ? 'DESC' : 'ASC';

		return array(
			'search'           => isset( $_GET['vsr_search'] ) ? sanitize_text_field( wp_unslash( $_GET['vsr_search'] ) ) : '',
			'category'         => array_values( array_unique( array_filter( array_map( 'absint', $category ) ) ) ),
			'health_filter'    => $health,
			'not_selling_days' => $not_selling,
			'restock_filter'   => $restock,
			'date_from'        => self::sanitize_date( isset( $_GET['vsr_from'] ) ? wp_unslash( $_GET['vsr_from'] ) : '' ),
			'date_to'          => self::sanitize_date( isset( $_GET['vsr_to'] ) ? wp_unslash( $_GET['vsr_to'] ) : '' ),
			'orderby'          => $orderby,
			'order'            => $order,
		);
	}

	/**
	 * Render the report page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'variation-stock-report' ) );
		}

		$filters = self::get_filters_from_request();
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		try {
			$summary = VSR_DB::get_summary( $filters );
			$report = VSR_DB::get_report_page( $filters, $page, self::PER_PAGE );
		} catch ( Throwable $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Inventory report unavailable due to a database error. Check WooCommerce logs and retry.', 'variation-stock-report' ) . '</p></div>';
			return;
		}

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		?>
		<div class="wrap vsr-wrap">
			<div class="vsr-header">
				<div>
					<h1><?php esc_html_e( 'Inventory Stock Report Pro', 'variation-stock-report' ); ?></h1>
					<p><?php esc_html_e( 'Live WooCommerce inventory with completed-order sales, stock valuation, 30-day velocity forecasting, bulk updates, and a full movement audit trail.', 'variation-stock-report' ); ?></p>
				</div>
				<div class="vsr-header-right">
					<button type="button" class="button" data-vsr-open-audit data-item-id="0" data-owner-id="0" data-item-title="<?php esc_attr_e( 'All Recent Stock Movements', 'variation-stock-report' ); ?>">
						<span class="dashicons dashicons-backup"></span>
						<?php esc_html_e( 'View Audit Log', 'variation-stock-report' ); ?>
					</button>
					<span class="vsr-sync-pill"><span></span><?php echo esc_html( sprintf( __( 'Loaded %s', 'variation-stock-report' ), current_time( 'H:i:s' ) ) ); ?></span>
				</div>
			</div>

			<div class="vsr-cards">
				<?php
				self::summary_card(
					__( 'Current stock', 'variation-stock-report' ),
					self::format_quantity( $summary['current_stock'] ),
					__( 'Unique managed inventories', 'variation-stock-report' ),
					'dashicons-archive'
				);
				self::summary_card(
					__( 'Retail stock value', 'variation-stock-report' ),
					wp_strip_all_tags( wc_price( $summary['stock_retail_value'] ) ),
					__( 'Unique owner stock × owner retail price', 'variation-stock-report' ),
					'dashicons-tag'
				);
				self::summary_card(
					__( 'Sold', 'variation-stock-report' ),
					self::format_quantity( $summary['total_sold'] ),
					self::period_label( $filters ),
					'dashicons-cart'
				);
				self::summary_card(
					__( 'Restocked', 'variation-stock-report' ),
					self::format_quantity( $summary['total_restocked'] ),
					self::period_label( $filters ),
					'dashicons-update'
				);
				self::summary_card(
					__( 'Manual decrease', 'variation-stock-report' ),
					self::format_quantity( $summary['total_adjusted'] ),
					self::period_label( $filters ),
					'dashicons-warning'
				);
				?>
			</div>

			<div class="vsr-actions-card">
				<div>
					<strong><?php esc_html_e( 'Historical audit import', 'variation-stock-report' ); ?></strong>
					<span><?php esc_html_e( 'Optional historical sales evidence import. Physical stock movements remain unverified. Sold quantity is read directly from WooCommerce and does not require this import.', 'variation-stock-report' ); ?></span>
				</div>
				<button type="button" class="button" id="vsr-import-btn"><?php esc_html_e( 'Import past orders', 'variation-stock-report' ); ?></button>
				<div class="vsr-import-progress" id="vsr-import-progress" hidden><span></span></div>
				<p id="vsr-import-status" class="vsr-import-status" aria-live="polite"></p>
			</div>

			<?php self::render_filters( $filters, $categories ); ?>

			<div class="vsr-table-card">
				<div class="vsr-table-toolbar">
					<div class="vsr-toolbar-left">
						<div>
							<strong><?php echo esc_html( number_format_i18n( $report['total'] ) ); ?></strong>
							<?php esc_html_e( 'matching items', 'variation-stock-report' ); ?>
						</div>
						<div class="vsr-bulk-bar" id="vsr-bulk-bar">
							<select id="vsr-bulk-operation" aria-label="<?php esc_attr_e( 'Bulk stock action', 'variation-stock-report' ); ?>">
								<option value="increase"><?php esc_html_e( 'Increase stock by (+)', 'variation-stock-report' ); ?></option>
								<option value="decrease"><?php esc_html_e( 'Decrease stock by (-)', 'variation-stock-report' ); ?></option>
								<option value="set"><?php esc_html_e( 'Set exact stock to (=)', 'variation-stock-report' ); ?></option>
							</select>
							<input type="number" step="any" id="vsr-bulk-qty" placeholder="<?php esc_attr_e( 'Qty', 'variation-stock-report' ); ?>" aria-label="<?php esc_attr_e( 'Bulk quantity', 'variation-stock-report' ); ?>">
							<input type="text" id="vsr-bulk-note" maxlength="140" placeholder="<?php esc_attr_e( 'Reason / note (optional)', 'variation-stock-report' ); ?>" aria-label="<?php esc_attr_e( 'Bulk update note', 'variation-stock-report' ); ?>">
							<button type="button" class="button" id="vsr-bulk-apply"><?php esc_html_e( 'Apply to selected', 'variation-stock-report' ); ?></button>
							<span id="vsr-bulk-status" class="vsr-bulk-status" aria-live="polite"></span>
						</div>
					</div>
					<div class="vsr-toolbar-right">
						<?php self::render_export_form( $filters ); ?>
					</div>
				</div>

				<div class="vsr-table-scroll">
					<table class="widefat fixed striped vsr-table">
						<thead>
							<tr>
								<th class="column-cb"><input type="checkbox" id="vsr-select-all" aria-label="<?php esc_attr_e( 'Select all managed items on this page', 'variation-stock-report' ); ?>"></th>
								<th class="column-product"><?php echo wp_kses_post( self::sort_link( 'product_name', __( 'Product', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'attribute_sort_key', __( 'Variation', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'sku', __( 'SKU / Price', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'stock_quantity', __( 'Current stock', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'stock_retail_value', __( 'Retail value', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'total_sold', __( 'Sold & velocity', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'total_restocked', __( 'Restocked', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'total_adjusted', __( 'Decrease', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'last_sale_date', __( 'Last sale', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php echo wp_kses_post( self::sort_link( 'last_restock_date', __( 'Last restock', 'variation-stock-report' ), $filters ) ); ?></th>
								<th><?php esc_html_e( 'Health & forecast', 'variation-stock-report' ); ?></th>
								<th class="column-update"><?php esc_html_e( 'Quick update & audit', 'variation-stock-report' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $report['rows'] ) ) : ?>
								<tr>
									<td colspan="13" class="vsr-empty"><?php esc_html_e( 'No inventory items match these filters.', 'variation-stock-report' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $report['rows'] as $row ) : ?>
									<?php self::render_row( $row ); ?>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php self::render_pagination( $report, $filters ); ?>
			</div>

			<div class="vsr-footnote">
				<strong><?php esc_html_e( 'Report definitions:', 'variation-stock-report' ); ?></strong>
				<?php esc_html_e( 'Sold is the gross sum of actual WooCommerce line-item quantities from orders whose current status is Completed. Processing, On-hold, Pending, Cancelled, Refunded, Returned and Failed orders are excluded. Partial refunds do not reduce Sold while the order remains Completed. Sold date filters use the order-created date to match Sales & Return Analytics Completed Qty; Last sale uses the later of order completion and recorded sale time. Shared-pool forecasts include every sibling variation; shared retail values use the owner price and must not be summed across variation rows. 30-day velocity and reorder suggestions are based on the last 30 days of completed sales.', 'variation-stock-report' ); ?>
			</div>

			<?php self::render_audit_modal(); ?>
		</div>
		<?php
	}

	/**
	 * Update stock from the report using an atomic relative WooCommerce operation.
	 *
	 * @return void
	 */
	public static function ajax_update_stock() {
		check_ajax_referer( 'vsr_update_stock', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'variation-stock-report' ) ), 403 );
		}
		$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$expected = isset( $_POST['expected_stock'] ) ? wp_unslash( $_POST['expected_stock'] ) : '';
		$quantity = isset( $_POST['new_stock'] ) ? wp_unslash( $_POST['new_stock'] ) : '';
		$request = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = VSR_Stock_Service::apply( $item_id, 'set', $quantity, $request, $expected, $note );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Bulk update stock for multiple selected inventory rows.
	 *
	 * Deduplicates by stock_owner_id when setting or adjusting shared parent stock
	 * so a parent pool is never modified multiple times in the same batch.
	 *
	 * @return void
	 */
	public static function ajax_bulk_update_stock() {
		check_ajax_referer( 'vsr_bulk_update_stock', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'variation-stock-report' ) ), 403 );
		}
		$raw_ids = isset( $_POST['item_ids'] ) ? wp_unslash( $_POST['item_ids'] ) : '';
		$operation = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		$qty = isset( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : '';
		$request = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( ! is_string( $raw_ids ) || ! is_numeric( $qty ) || (float) $qty < 0 || ! in_array( $operation, array( 'set', 'increase', 'decrease' ), true ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,100}$/D', $request ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid bulk command.', 'variation-stock-report' ) ), 400 );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) ) );
		sort( $ids, SORT_NUMERIC );
		if ( ! $ids || count( $ids ) > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Select between 1 and 100 items.', 'variation-stock-report' ) ), 400 );
		}
		$owners = array();
		$results = array();
		$updated = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$owner = $product ? (int) $product->get_stock_managed_by_id() : 0;
			if ( $owner && isset( $owners[ $owner ] ) ) {
				$results[] = array( 'item_id' => $id, 'owner_id' => $owner, 'status' => 'shared_owner', 'message' => __( 'Same physical inventory as another selected row; see that row result.', 'variation-stock-report' ) );
				continue;
			}
			if ( $owner ) {
				$owners[ $owner ] = true;
			}
			$result = VSR_Stock_Service::apply( $id, $operation, $qty, $request, null, $note, 'bulk_report' );
			if ( is_wp_error( $result ) ) {
				++$failed;
				$results[] = array( 'item_id' => $id, 'status' => 'failed', 'message' => $result->get_error_message() );
			} else {
				++$updated;
				$results[] = array_merge( $result, array( 'status' => 'applied' ) );
			}
		}
		$response = array( 'updated_count' => $updated, 'failed_count' => $failed, 'results' => $results, 'message' => sprintf( __( '%1$d inventories updated; %2$d failed. Retry unchanged requests safely, or refresh before a new command.', 'variation-stock-report' ), $updated, $failed ) );
		if ( $failed ) {
			wp_send_json_error( $response, 409 );
		}
		wp_send_json_success( $response );
	}

	/**
	 * Return paginated audit logs for the modal viewer.
	 *
	 * @return void
	 */
	public static function ajax_get_audit_logs() {
		check_ajax_referer( 'vsr_get_audit_logs', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'variation-stock-report' ) ), 403 );
		}

		$item_id     = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;
		$owner_id    = isset( $_POST['owner_id'] ) ? absint( wp_unslash( $_POST['owner_id'] ) ) : 0;
		$change_type = isset( $_POST['change_type'] ) ? sanitize_key( wp_unslash( $_POST['change_type'] ) ) : '';
		$limit       = 40;

		try {
		$result = VSR_DB::get_audit_logs(
			array(
				'item_id'     => $item_id,
				'owner_id'    => $owner_id,
				'change_type' => $change_type,
				'limit'       => $limit,
				'before_id' => isset( $_POST['before_id'] ) ? absint( $_POST['before_id'] ) : 0,
				'snapshot_id' => isset( $_POST['snapshot_id'] ) ? absint( $_POST['snapshot_id'] ) : 0,
			)
		);
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => __( 'Audit query failed. Check WooCommerce logs.', 'variation-stock-report' ) ), 500 );
		}

		$formatted_rows = array();
		foreach ( $result['rows'] as $row ) {
			$order_url = '';
			if ( $row->order_id ) {
				$order_obj = wc_get_order( absint( $row->order_id ) );
				$order_url = $order_obj ? $order_obj->get_edit_order_url() : admin_url( 'post.php?post=' . absint( $row->order_id ) . '&action=edit' );
			}

			$qty_num = (float) $row->quantity;
			$qty_str = ( $qty_num > 0 ? '+' : '' ) . self::format_quantity( $qty_num );

			$formatted_rows[] = array(
				'id'           => absint( $row->id ),
				'date'         => self::format_local_date( $row->created_at, 'Y-m-d H:i:s' ),
				'product_name' => $row->product_name,
				'item_id'      => absint( $row->variation_id ),
				'owner_id'     => absint( $row->stock_owner_id ),
				'change_type'  => $row->change_type,
				'type_label'   => self::movement_type_label( $row->change_type ),
				'quantity'     => $qty_str,
				'is_positive'  => $qty_num > 0,
				'stock_before' => null !== $row->stock_before ? self::format_quantity( $row->stock_before ) : '—',
				'stock_after'  => null !== $row->stock_after ? self::format_quantity( $row->stock_after ) : '—',
				'order_id'     => $row->order_id ? absint( $row->order_id ) : 0,
				'order_url'    => $order_url ? esc_url_raw( $order_url ) : '',
				'source'       => self::source_label( $row->source ),
				'user'         => $row->user_name ? $row->user_name : __( 'System / Customer', 'variation-stock-report' ),
				'note'         => $row->note ? $row->note : '—',
			);
		}

		wp_send_json_success(
			array(
				'rows'        => $formatted_rows,
				'total'       => $result['total'],
				'next_id' => $result['next_id'],
				'snapshot_id' => $result['snapshot_id'],
				'has_more' => count( $formatted_rows ) === $limit,
			)
		);
	}

	/**
	 * Format a variation attribute label cleanly, decoding any URL-encoded Unicode/Bangla slugs.
	 *
	 * @param string $raw_label Raw attribute label from SQL.
	 * @param int    $item_id   Variation ID fallback.
	 * @return string
	 */
	public static function format_variation_label( $raw_label, $item_id ) {
		$raw_label = trim( (string) $raw_label );
		if ( '' === $raw_label ) {
			return '#' . absint( $item_id );
		}

		$parts = array_filter( array_map( 'trim', explode( ' / ', $raw_label ) ) );
		if ( empty( $parts ) ) {
			return '#' . absint( $item_id );
		}

		$decoded = array_map(
			static function ( $part ) {
				return rawurldecode( $part );
			},
			$parts
		);

		return implode( ' / ', $decoded );
	}

	/**
	 * Format a stock quantity without unnecessary decimals.
	 *
	 * @param mixed $quantity Quantity.
	 * @return string
	 */
	public static function format_quantity( $quantity ) {
		$quantity = (float) $quantity;
		if ( abs( $quantity - round( $quantity ) ) < 0.0001 ) {
			return number_format_i18n( $quantity, 0 );
		}

		return rtrim( rtrim( number_format_i18n( $quantity, 4 ), '0' ), wc_get_price_decimal_separator() );
	}

	/**
	 * Format a UTC MySQL datetime in the site's timezone.
	 *
	 * @param string $date   UTC MySQL datetime.
	 * @param string $format Optional display format.
	 * @return string
	 */
	public static function format_gmt_date( $date, $format = '' ) {
		if ( ! $date ) {
			return __( 'Never', 'variation-stock-report' );
		}

		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $date, new DateTimeZone( 'UTC' ) );
		if ( ! $datetime ) {
			return __( 'Never', 'variation-stock-report' );
		}

		$format = $format ? $format : get_option( 'date_format' );
		return wp_date( $format, $datetime->getTimestamp(), wp_timezone() );
	}

	/**
	 * Format a site-local MySQL datetime.
	 *
	 * @param string $date   Site-local MySQL datetime.
	 * @param string $format Optional display format.
	 * @return string
	 */
	public static function format_local_date( $date, $format = '' ) {
		if ( ! $date ) {
			return __( 'Never', 'variation-stock-report' );
		}

		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $date, wp_timezone() );
		if ( ! $datetime ) {
			return __( 'Never', 'variation-stock-report' );
		}

		$format = $format ? $format : get_option( 'date_format' );
		return wp_date( $format, $datetime->getTimestamp(), wp_timezone() );
	}

	/**
	 * Render one summary card.
	 *
	 * @param string $label  Card label.
	 * @param string $value  Card value.
	 * @param string $detail Card detail.
	 * @param string $icon   Dashicon class.
	 * @return void
	 */
	private static function summary_card( $label, $value, $detail, $icon ) {
		?>
		<div class="vsr-card">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
			<div>
				<small><?php echo esc_html( $label ); ?></small>
				<strong><?php echo esc_html( $value ); ?></strong>
				<em><?php echo esc_html( $detail ); ?></em>
			</div>
		</div>
		<?php
	}

	/**
	 * Render report filters.
	 *
	 * @param array          $filters    Current filters.
	 * @param array|WP_Error $categories Product categories.
	 * @return void
	 */
	private static function render_filters( $filters, $categories ) {
		?>
		<form class="vsr-filter-card" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<div class="vsr-filter-grid">
				<label>
					<span><?php esc_html_e( 'Search', 'variation-stock-report' ); ?></span>
					<input type="search" name="vsr_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Product, SKU or variation', 'variation-stock-report' ); ?>">
				</label>

				<div class="vsr-multiselect vsr-field" id="vsr-category-select">
					<span class="vsr-field-label"><?php esc_html_e( 'Category', 'variation-stock-report' ); ?></span>
					<button class="button vsr-multiselect-toggle" type="button" aria-expanded="false">
						<?php echo esc_html( self::category_label( $filters['category'], $categories ) ); ?>
					</button>
					<div class="vsr-multiselect-panel" hidden>
						<div class="vsr-category-list">
							<?php self::render_category_tree( $categories, 0, 0, $filters['category'] ); ?>
						</div>
						<div class="vsr-multiselect-actions vsr-category-actions">
							<button type="button" class="button-link" data-vsr-clear><?php esc_html_e( 'Clear', 'variation-stock-report' ); ?></button>
							<button type="button" class="button" data-vsr-done><?php esc_html_e( 'Done', 'variation-stock-report' ); ?></button>
						</div>
					</div>
				</div>

				<label>
					<span><?php esc_html_e( 'Stock health', 'variation-stock-report' ); ?></span>
					<select name="vsr_health">
						<option value=""><?php esc_html_e( 'All stock states', 'variation-stock-report' ); ?></option>
						<option value="outofstock" <?php selected( $filters['health_filter'], 'outofstock' ); ?>><?php esc_html_e( 'Out of stock', 'variation-stock-report' ); ?></option>
						<option value="lowstock" <?php selected( $filters['health_filter'], 'lowstock' ); ?>><?php esc_html_e( 'Low stock alert', 'variation-stock-report' ); ?></option>
						<option value="instock" <?php selected( $filters['health_filter'], 'instock' ); ?>><?php esc_html_e( 'Healthy in stock', 'variation-stock-report' ); ?></option>
						<option value="shared" <?php selected( $filters['health_filter'], 'shared' ); ?>><?php esc_html_e( 'Shared parent stock', 'variation-stock-report' ); ?></option>
						<option value="unmanaged" <?php selected( $filters['health_filter'], 'unmanaged' ); ?>><?php esc_html_e( 'Unmanaged stock', 'variation-stock-report' ); ?></option>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Sales activity', 'variation-stock-report' ); ?></span>
					<select name="vsr_not_selling">
						<option value=""><?php esc_html_e( 'All items', 'variation-stock-report' ); ?></option>
						<option value="never" <?php selected( $filters['not_selling_days'], 'never' ); ?>><?php esc_html_e( 'Never sold', 'variation-stock-report' ); ?></option>
						<option value="30" <?php selected( $filters['not_selling_days'], '30' ); ?>><?php esc_html_e( 'No sale in 30 days', 'variation-stock-report' ); ?></option>
						<option value="60" <?php selected( $filters['not_selling_days'], '60' ); ?>><?php esc_html_e( 'No sale in 60 days', 'variation-stock-report' ); ?></option>
						<option value="90" <?php selected( $filters['not_selling_days'], '90' ); ?>><?php esc_html_e( 'No sale in 90 days', 'variation-stock-report' ); ?></option>
						<option value="180" <?php selected( $filters['not_selling_days'], '180' ); ?>><?php esc_html_e( 'No sale in 180 days', 'variation-stock-report' ); ?></option>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Restock activity', 'variation-stock-report' ); ?></span>
					<select name="vsr_restock">
						<option value=""><?php esc_html_e( 'All items', 'variation-stock-report' ); ?></option>
						<option value="never" <?php selected( $filters['restock_filter'], 'never' ); ?>><?php esc_html_e( 'Never restocked', 'variation-stock-report' ); ?></option>
						<option value="7" <?php selected( $filters['restock_filter'], '7' ); ?>><?php esc_html_e( 'Restocked in 7 days', 'variation-stock-report' ); ?></option>
						<option value="30" <?php selected( $filters['restock_filter'], '30' ); ?>><?php esc_html_e( 'Restocked in 30 days', 'variation-stock-report' ); ?></option>
						<option value="90" <?php selected( $filters['restock_filter'], '90' ); ?>><?php esc_html_e( 'Restocked in 90 days', 'variation-stock-report' ); ?></option>
						<option value="180" <?php selected( $filters['restock_filter'], '180' ); ?>><?php esc_html_e( 'Restocked in 180 days', 'variation-stock-report' ); ?></option>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Totals from', 'variation-stock-report' ); ?></span>
					<input type="date" name="vsr_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
				</label>

				<label>
					<span><?php esc_html_e( 'Totals to', 'variation-stock-report' ); ?></span>
					<input type="date" name="vsr_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
				</label>

				<div class="vsr-filter-actions">
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'variation-stock-report' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Reset', 'variation-stock-report' ); ?></a>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the CSV export form.
	 *
	 * @param array $filters Current filters.
	 * @return void
	 */
	private static function render_export_form( $filters ) {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="vsr_export_csv">
			<?php wp_nonce_field( 'vsr_export_csv' ); ?>
			<?php foreach ( self::base_query_args( $filters ) as $key => $value ) : ?>
				<?php if ( 'page' === $key ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<?php if ( is_array( $value ) ) : ?>
					<?php foreach ( $value as $single ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>[]" value="<?php echo esc_attr( $single ); ?>">
					<?php endforeach; ?>
				<?php else : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php endif; ?>
			<?php endforeach; ?>
			<button type="submit" class="button">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Export Report CSV', 'variation-stock-report' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Render one report row.
	 *
	 * @param object $row Report row.
	 * @return void
	 */
	private static function render_row( $row ) {
		$edit_link = get_edit_post_link( $row->product_id );
		$variation = 'variation' === $row->row_type
			? self::format_variation_label( $row->attribute_label, $row->item_id )
			: __( 'Simple product', 'variation-stock-report' );
		$stock     = $row->manages_stock ? self::format_quantity( $row->stock_quantity ) : '—';
		$thumb_id  = get_post_thumbnail_id( $row->item_id );
		if ( ! $thumb_id && $row->product_id !== $row->item_id ) {
			$thumb_id = get_post_thumbnail_id( $row->product_id );
		}
		$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';

		if ( 'shared' === $row->inventory_mode ) {
			$mode_label = __( 'Shared parent stock', 'variation-stock-report' );
		} elseif ( 'disabled' === $row->inventory_mode ) {
			$mode_label = __( 'Not managed', 'variation-stock-report' );
		} else {
			$mode_label = __( 'Own stock', 'variation-stock-report' );
		}

		$item_title = $row->product_name . ( 'variation' === $row->row_type ? ' — ' . $variation : '' );
		?>
		<tr data-vsr-row="<?php echo esc_attr( $row->item_id ); ?>">
			<td class="column-cb">
				<?php if ( $row->manages_stock ) : ?>
					<input type="checkbox" class="vsr-row-check" value="<?php echo esc_attr( $row->item_id ); ?>" aria-label="<?php echo esc_attr( $item_title ); ?>">
				<?php endif; ?>
			</td>
			<td class="column-product">
				<div class="vsr-product-cell">
					<?php if ( $thumb_url ) : ?>
						<img class="vsr-thumb" src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy">
					<?php else : ?>
						<span class="vsr-thumb vsr-thumb-placeholder"><span class="dashicons dashicons-format-image"></span></span>
					<?php endif; ?>
					<div>
						<a class="vsr-product-link" href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $row->product_name ); ?></a>
						<small>#<?php echo esc_html( $row->product_id ); ?></small>
					</div>
				</div>
			</td>
			<td>
				<strong><?php echo esc_html( $variation ); ?></strong>
				<small>
					<?php echo esc_html( $mode_label ); ?>
					<?php if ( 'variation' === $row->row_type ) : ?>
						· #<?php echo esc_html( $row->item_id ); ?>
					<?php endif; ?>
				</small>
			</td>
			<td>
				<span><?php echo esc_html( $row->sku ? $row->sku : '—' ); ?></span>
				<small><?php echo wp_kses_post( wc_price( $row->unit_price ) ); ?></small>
			</td>
			<td><span class="vsr-stock-value" data-vsr-stock-value><?php echo esc_html( $stock ); ?></span></td>
			<td>
				<?php if ( $row->manages_stock ) : ?>
					<strong><?php echo wp_kses_post( wc_price( $row->stock_retail_value ) ); ?></strong>
				<?php else : ?>
					<span class="vsr-muted">—</span>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( self::format_quantity( $row->total_sold ) ); ?></strong>
				<?php if ( 'shared' === $row->inventory_mode ) : ?><small><?php esc_html_e( 'Velocity and forecast use the whole parent pool.', 'variation-stock-report' ); ?></small><?php endif; ?>
				<?php if ( $row->sold_30d > 0 ) : ?>
					<small>
						<?php
						printf(
							/* translators: 1: 30-day sold quantity, 2: daily sales rate */
							esc_html__( '30d: %1$s (%2$s/day)', 'variation-stock-report' ),
							esc_html( self::format_quantity( $row->sold_30d ) ),
							esc_html( self::format_quantity( $row->daily_velocity ) )
						);
						?>
					</small>
				<?php endif; ?>
			</td>
			<td>
				<?php echo esc_html( self::format_quantity( $row->total_restocked ) ); ?>
				<?php if ( ! empty( $row->is_shared_pool_movement ) && $row->owner_restocked > 0 ) : ?>
					<small><?php echo esc_html( sprintf( __( 'Pool: %s', 'variation-stock-report' ), self::format_quantity( $row->owner_restocked ) ) ); ?></small>
				<?php endif; ?>
			</td>
			<td>
				<?php echo esc_html( self::format_quantity( $row->total_adjusted ) ); ?>
				<?php if ( ! empty( $row->is_shared_pool_movement ) && $row->owner_adjusted > 0 ) : ?>
					<small><?php echo esc_html( sprintf( __( 'Pool: %s', 'variation-stock-report' ), self::format_quantity( $row->owner_adjusted ) ) ); ?></small>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( self::format_gmt_date( $row->last_sale_date ) ); ?></td>
			<td><?php echo esc_html( self::format_local_date( $row->last_restock_date ) ); ?></td>
			<td><?php echo wp_kses_post( self::health_badge( $row ) ); ?></td>
			<td class="column-update">
				<?php if ( $row->manages_stock ) : ?>
					<div class="vsr-quick-update">
						<input type="number" step="any" class="vsr-qty-input" value="<?php echo esc_attr( (float) $row->stock_quantity ); ?>" aria-label="<?php esc_attr_e( 'New stock quantity', 'variation-stock-report' ); ?>">
						<input type="text" class="vsr-note-input" maxlength="140" placeholder="<?php esc_attr_e( 'Note (optional)', 'variation-stock-report' ); ?>" aria-label="<?php esc_attr_e( 'Adjustment note', 'variation-stock-report' ); ?>">
						<button type="button" class="button button-small button-primary" data-vsr-update data-item-id="<?php echo esc_attr( $row->item_id ); ?>" data-expected-stock="<?php echo esc_attr( (float) $row->stock_quantity ); ?>"><?php esc_html_e( 'Save', 'variation-stock-report' ); ?></button>
						<button type="button" class="button button-small" data-vsr-open-audit data-item-id="<?php echo esc_attr( $row->item_id ); ?>" data-owner-id="<?php echo esc_attr( $row->stock_owner_id ); ?>" data-item-title="<?php echo esc_attr( $item_title ); ?>" title="<?php esc_attr_e( 'View stock movement history', 'variation-stock-report' ); ?>">
							<span class="dashicons dashicons-backup"></span>
						</button>
					</div>
					<span class="vsr-row-message" aria-live="polite"></span>
				<?php else : ?>
					<div class="vsr-unmanaged-actions">
						<span class="vsr-muted"><?php esc_html_e( 'Enable stock management in editor', 'variation-stock-report' ); ?></span>
						<button type="button" class="button button-small" data-vsr-open-audit data-item-id="<?php echo esc_attr( $row->item_id ); ?>" data-owner-id="<?php echo esc_attr( $row->stock_owner_id ); ?>" data-item-title="<?php echo esc_attr( $item_title ); ?>" title="<?php esc_attr_e( 'View stock movement history', 'variation-stock-report' ); ?>">
							<span class="dashicons dashicons-backup"></span>
						</button>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Build a stock-health badge and forecast subtext.
	 *
	 * @param object $row Report row.
	 * @return string
	 */
	private static function health_badge( $row ) {
		if ( ! $row->manages_stock ) {
			return '<span class="vsr-badge neutral">' . esc_html__( 'Not managed', 'variation-stock-report' ) . '</span>';
		}

		$stock = (float) $row->stock_quantity;
		$low   = null !== $row->low_stock_amount
			? (float) $row->low_stock_amount
			: (float) get_option( 'woocommerce_notify_low_stock_amount', 2 );

		if ( $stock <= 0 || 'outofstock' === $row->stock_status ) {
			$badge = '<span class="vsr-badge danger">' . esc_html__( 'Out of stock', 'variation-stock-report' ) . '</span>';
		} elseif ( $stock <= $low ) {
			$badge = '<span class="vsr-badge warning">' . esc_html__( 'Low stock', 'variation-stock-report' ) . '</span>';
		} elseif ( ! $row->last_sale_date ) {
			$badge = '<span class="vsr-badge neutral">' . esc_html__( 'Never sold', 'variation-stock-report' ) . '</span>';
		} else {
			$badge = '<span class="vsr-badge success">' . esc_html__( 'Healthy', 'variation-stock-report' ) . '</span>';
		}

		$forecast = '';
		if ( null !== $row->days_left && $row->days_left >= 0 ) {
			$days_text = sprintf(
				/* translators: %d: estimated days of stock remaining */
				_n( '~%d day left', '~%d days left', (int) $row->days_left, 'variation-stock-report' ),
				(int) $row->days_left
			);
			if ( $row->reorder_qty > 0 ) {
				$days_text .= ' · ' . sprintf(
					/* translators: %s: suggested reorder quantity */
					__( 'Reorder +%s', 'variation-stock-report' ),
					self::format_quantity( $row->reorder_qty )
				);
			}
			$forecast = '<small class="vsr-forecast">' . esc_html( $days_text ) . '</small>';
		}

		return $badge . $forecast;
	}

	/**
	 * Render the Audit Log modal dialog container.
	 *
	 * @return void
	 */
	private static function render_audit_modal() {
		?>
		<div class="vsr-modal-backdrop" id="vsr-audit-modal" hidden>
			<div class="vsr-modal" role="dialog" aria-modal="true" aria-labelledby="vsr-audit-title">
				<div class="vsr-modal-header">
					<div>
						<h2 id="vsr-audit-title"><?php esc_html_e( 'Stock Movement History', 'variation-stock-report' ); ?></h2>
						<p id="vsr-audit-subtitle" class="vsr-modal-subtitle"></p>
					</div>
					<div class="vsr-modal-controls">
						<select id="vsr-audit-type-filter" aria-label="<?php esc_attr_e( 'Filter movement type', 'variation-stock-report' ); ?>">
							<option value=""><?php esc_html_e( 'All movement types', 'variation-stock-report' ); ?></option>
							<option value="restock"><?php esc_html_e( 'Restocked (+)', 'variation-stock-report' ); ?></option>
							<option value="adjustment"><?php esc_html_e( 'Manual Decrease (-)', 'variation-stock-report' ); ?></option>
							<option value="historical_sale"><?php esc_html_e( 'Historical sale (unverified movement)', 'variation-stock-report' ); ?></option>
							<option value="sale"><?php esc_html_e( 'Order Sale (-)', 'variation-stock-report' ); ?></option>
							<option value="sale_reversal"><?php esc_html_e( 'Cancel / Refund Restore (+)', 'variation-stock-report' ); ?></option>
						</select>
						<a id="vsr-audit-export-link" class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vsr_export_audit_csv' ), 'vsr_export_audit_csv' ) ); ?>">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Export Audit CSV', 'variation-stock-report' ); ?>
						</a>
						<button type="button" class="button" id="vsr-audit-close" aria-label="<?php esc_attr_e( 'Close modal', 'variation-stock-report' ); ?>">✕</button>
					</div>
				</div>
				<div class="vsr-modal-body">
					<table class="widefat striped vsr-audit-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'variation-stock-report' ); ?></th>
								<th><?php esc_html_e( 'Product / Item', 'variation-stock-report' ); ?></th>
								<th><?php esc_html_e( 'Type', 'variation-stock-report' ); ?></th>
								<th><?php esc_html_e( 'Delta', 'variation-stock-report' ); ?></th>
								<th><?php esc_html_e( 'Before → After', 'variation-stock-report' ); ?></th>
								<th><?php esc_html_e( 'Source / Order', 'variation-stock-report' ); ?></th>
								<th><?php esc_html_e( 'User', 'variation-stock-report' ); ?></th>
								<th><?php esc_html_e( 'Note', 'variation-stock-report' ); ?></th>
							</tr>
						</thead>
						<tbody id="vsr-audit-tbody"></tbody>
					</table>
					<p id="vsr-audit-status" class="vsr-audit-status"></p>
					<div class="vsr-modal-footer">
						<button type="button" class="button" id="vsr-audit-load-more" hidden><?php esc_html_e( 'Load older movements', 'variation-stock-report' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Human-readable movement type label.
	 *
	 * @param string $type Change type key.
	 * @return string
	 */
	private static function movement_type_label( $type ) {
		$map = array(
			'historical_sale' => __( 'Historical sale (unverified movement)', 'variation-stock-report' ),
			'sale'            => __( 'Order Sale', 'variation-stock-report' ),
			'sale_reversal'   => __( 'Cancel / Refund Restore', 'variation-stock-report' ),
			'restock'         => __( 'Restocked', 'variation-stock-report' ),
			'adjustment'      => __( 'Manual Decrease', 'variation-stock-report' ),
			'opening_balance' => __( 'Opening Balance', 'variation-stock-report' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/**
	 * Human-readable movement source label.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	private static function source_label( $source ) {
		$map = array(
			'stock_report'      => __( 'Quick Update', 'variation-stock-report' ),
			'bulk_report'       => __( 'Bulk Update', 'variation-stock-report' ),
			'product_editor'    => __( 'Product Editor', 'variation-stock-report' ),
			'order'             => __( 'Order Checkout', 'variation-stock-report' ),
			'order_restore'     => __( 'Order Cancelled/Restored', 'variation-stock-report' ),
			'refund_restock'    => __( 'Order Refund Restock', 'variation-stock-report' ),
			'historical_import' => __( 'Historical Import', 'variation-stock-report' ),
			'rest_api'          => __( 'REST API', 'variation-stock-report' ),
			'wp_cli'            => __( 'WP-CLI', 'variation-stock-report' ),
			'cron'              => __( 'Scheduled Cron', 'variation-stock-report' ),
			'integration'       => __( 'Store Integration', 'variation-stock-report' ),
		);
		return isset( $map[ $source ] ) ? $map[ $source ] : $source;
	}

	/**
	 * Render pagination.
	 *
	 * @param array $report  Report result.
	 * @param array $filters Current filters.
	 * @return void
	 */
	private static function render_pagination( $report, $filters ) {
		if ( $report['pages'] <= 1 ) {
			return;
		}

		$args = self::base_query_args( $filters );
		unset( $args['paged'] );

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => $report['page'],
				'total'     => $report['pages'],
				'type'      => 'list',
				'add_args'  => $args,
				'prev_text' => '‹',
				'next_text' => '›',
			)
		);

		if ( $links ) {
			echo '<nav class="vsr-pagination" aria-label="' . esc_attr__( 'Report pages', 'variation-stock-report' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	/**
	 * Build one sortable table header link.
	 *
	 * @param string $field   Sort field.
	 * @param string $label   Header label.
	 * @param array  $filters Current filters.
	 * @return string
	 */
	private static function sort_link( $field, $label, $filters ) {
		$current = $filters['orderby'] === $field;
		$order   = $current && 'ASC' === $filters['order'] ? 'DESC' : 'ASC';
		$args    = self::base_query_args( $filters );

		$args['orderby'] = $field;
		$args['order']   = $order;
		$args['paged']   = 1;
		$icon            = $current ? ( 'ASC' === $filters['order'] ? '↑' : '↓' ) : '';
		$url             = add_query_arg( $args, admin_url( 'admin.php' ) );

		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<span>' . esc_html( $icon ) . '</span></a>';
	}

	/**
	 * Build report query arguments for sorting/export/pagination.
	 *
	 * @param array $filters Current filters.
	 * @return array
	 */
	private static function base_query_args( $filters ) {
		return array(
			'page'            => self::PAGE_SLUG,
			'vsr_search'      => $filters['search'],
			'vsr_category'    => $filters['category'],
			'vsr_health'      => $filters['health_filter'],
			'vsr_not_selling' => $filters['not_selling_days'],
			'vsr_restock'     => $filters['restock_filter'],
			'vsr_from'        => $filters['date_from'],
			'vsr_to'          => $filters['date_to'],
			'orderby'         => $filters['orderby'],
			'order'           => $filters['order'],
		);
	}

	/**
	 * Render a recursive product-category checkbox tree.
	 *
	 * @param array|WP_Error $categories Category terms.
	 * @param int            $parent_id  Parent term ID.
	 * @param int            $depth      Tree depth.
	 * @param array          $selected   Selected term IDs.
	 * @return void
	 */
	private static function render_category_tree( $categories, $parent_id, $depth, $selected ) {
		if ( is_wp_error( $categories ) ) {
			return;
		}

		foreach ( $categories as $category ) {
			if ( (int) $category->parent !== (int) $parent_id ) {
				continue;
			}
			?>
			<label style="--vsr-depth:<?php echo esc_attr( $depth ); ?>">
				<input type="checkbox" name="vsr_category[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, $selected, true ) ); ?>>
				<span><?php echo esc_html( $category->name ); ?></span>
			</label>
			<?php
			self::render_category_tree( $categories, $category->term_id, $depth + 1, $selected );
		}
	}

	/**
	 * Build the current category selector label.
	 *
	 * @param array          $selected   Selected category IDs.
	 * @param array|WP_Error $categories Category terms.
	 * @return string
	 */
	private static function category_label( $selected, $categories ) {
		if ( empty( $selected ) ) {
			return __( 'All categories', 'variation-stock-report' );
		}

		if ( 1 === count( $selected ) && ! is_wp_error( $categories ) ) {
			foreach ( $categories as $category ) {
				if ( (int) $category->term_id === (int) $selected[0] ) {
					return $category->name;
				}
			}
		}

		return sprintf(
			/* translators: %s: number of selected categories. */
			_n( '%s category selected', '%s categories selected', count( $selected ), 'variation-stock-report' ),
			number_format_i18n( count( $selected ) )
		);
	}

	/**
	 * Return a label for the active totals period.
	 *
	 * @param array $filters Current filters.
	 * @return string
	 */
	private static function period_label( $filters ) {
		if ( $filters['date_from'] || $filters['date_to'] ) {
			return __( 'Selected date range', 'variation-stock-report' );
		}

		return __( 'All tracked time', 'variation-stock-report' );
	}

	/**
	 * Sanitize a YYYY-MM-DD date.
	 *
	 * @param mixed $date Raw date.
	 * @return string
	 */
	private static function sanitize_date( $date ) {
		$date = sanitize_text_field( (string) $date );
		if ( '' === $date ) {
			return '';
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date ? $date : '';
	}

}
