<?php
/**
 * Stock movement tracking.
 *
 * @package VariationStockReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reliable stock movement tracker.
 *
 * Rules enforced:
 * 1. New products, products in draft/auto-draft/pending/trash, or products transitioning
 *    from draft/pending/auto-draft to publish/private in the current request establish a
 *    stock baseline and are NEVER counted as Restocked or Manual Decrease.
 * 2. Products enabling stock management for the first time establish a baseline and are
 *    not counted as Restocked.
 * 3. Stock restored from cancelled, returned, refunded, failed, or trashed orders (whether
 *    via core WooCommerce hooks or custom courier/return status plugins) is classified as
 *    `sale_reversal` and NEVER counted as Restocked.
 * 4. Genuine manual/admin stock increases on already-published products are recorded as
 *    `restock`.
 */
class VSR_Tracker {

	/** Persistent marker used until a published product receives its first stock baseline. */
	const INITIAL_STOCK_PENDING_META = '_vsr_initial_stock_pending';

	/** @var array<int,array<int,array<string,mixed>>> */
	private static $before_stock = array();

	/** @var array<int,array<int,array<string,mixed>>> */
	private static $recent_logs = array();

	/** @var array<int,bool> */
	private static $baseline_product_ids = array();

	/** @var array<int,WC_Order> Active orders currently undergoing cancellation/return/refund. */
	private static $active_reversal_orders = array();

	/** @var string */
	private static $context = '';
	private static $observed_order_movements = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Track newly inserted posts and draft->publish transitions so initial or draft stock is never counted as Restocked.
		add_action( 'wp_insert_post', array( __CLASS__, 'mark_new_product' ), 1, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'track_status_transition' ), 1, 3 );

		add_action( 'woocommerce_product_before_set_stock', array( __CLASS__, 'capture_before_stock' ), 1 );
		add_action( 'woocommerce_variation_before_set_stock', array( __CLASS__, 'capture_before_stock' ), 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'record_after_stock' ), 999 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'record_after_stock' ), 999 );

		add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'record_order_sale' ), 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'record_order_restore' ), 10, 4 );
		add_action( 'woocommerce_restock_refunded_item', array( __CLASS__, 'record_refund_restore' ), 10, 5 );

		// Compatibility fallbacks for WooCommerce versions/extensions that only fire whole-order hooks.
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'record_order_sale_fallback' ), 999, 1 );
		add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'record_order_restore_fallback' ), 999, 1 );

		// Catch custom "Returned" / "Cancelled" / "Refunded" / "Failed" order status transitions
		// even if a third-party courier/return plugin restores stock directly without firing WC's item restore hook.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'begin_order_status_watch' ), 1, 4 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'end_order_status_watch' ), 9999, 4 );
	}

	/**
	 * Mark a newly inserted or non-published product/variation so its stock is a baseline,
	 * not a restock/manual-decrease movement.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post update.
	 * @return void
	 */
	public static function mark_new_product( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		if ( ! $update || ! self::is_published_in_db( $post ) ) {
			self::$baseline_product_ids[ $post_id ] = true;
			update_post_meta( $post_id, self::INITIAL_STOCK_PENDING_META, 'yes' );
		}
	}

	/**
	 * Detect when a product transitions from draft/auto-draft/pending to publish/private.
	 *
	 * Any stock set during the request that publishes a draft product for the first time
	 * is treated as its initial baseline rather than a restock.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public static function track_status_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$post_id       = absint( $post->ID );
		$live_statuses = array( 'publish', 'private' );
		$was_live      = in_array( $old_status, $live_statuses, true );
		$is_now_live   = in_array( $new_status, $live_statuses, true );

		// Still in draft/pending/auto-draft, or just transitioning from draft -> publish in this request.
		if ( ! $is_now_live || ! $was_live ) {
			self::$baseline_product_ids[ $post_id ] = true;
			update_post_meta( $post_id, self::INITIAL_STOCK_PENDING_META, 'yes' );
		}
	}

	/**
	 * Check whether a product (and its parent if it is a variation) has publish/private status in DB.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return bool
	 */
	private static function is_published_in_db( $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$live_statuses = array( 'publish', 'private' );
		if ( ! in_array( $post->post_status, $live_statuses, true ) ) {
			return false;
		}

		if ( 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent instanceof WP_Post || ! in_array( $parent->post_status, $live_statuses, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a product (and its parent if it is a variation) was ALREADY published
	 * prior to the current request (i.e. not currently in draft and not transitioning from draft).
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return bool
	 */
	private static function is_already_live_product( $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post );
		if ( ! $post instanceof WP_Post || ! self::is_published_in_db( $post ) ) {
			return false;
		}

		if ( isset( self::$baseline_product_ids[ (int) $post->ID ] ) ) {
			return false;
		}

		if ( 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			if ( isset( self::$baseline_product_ids[ (int) $post->post_parent ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Watch order status transitions to return/cancel/refund/failed states.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status slug (without wc-).
	 * @param string   $new_status New status slug (without wc-).
	 * @param WC_Order $order      Order object.
	 * @return void
	 */
	public static function begin_order_status_watch( $order_id, $old_status, $new_status, $order = null ) {
		if ( ! self::is_reversal_order_status( $new_status ) ) {
			return;
		}

		$order_obj = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order_obj instanceof WC_Order ) {
			self::$active_reversal_orders[ (int) $order_obj->get_id() ] = $order_obj;
		}
	}

	/**
	 * Reclassify any unclaimed positive stock movements that happened while an order
	 * transitioned to a cancelled, returned, refunded, or failed status.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status slug.
	 * @param string   $new_status New status slug.
	 * @param WC_Order $order      Order object.
	 * @return void
	 */
	public static function end_order_status_watch( $order_id, $old_status, $new_status, $order = null ) {
		$order_id = absint( $order_id );
		if ( ! isset( self::$active_reversal_orders[ $order_id ] ) ) {
			return;
		}

		$order_obj = self::$active_reversal_orders[ $order_id ];
		unset( self::$active_reversal_orders[ $order_id ] );

		// Reclassify any positive stock movements triggered by custom return/cancel plugins during this transition.
		self::record_order_restore_fallback( $order_obj );
	}

	/**
	 * Check whether an order status represents a return, cancellation, refund, or failure.
	 *
	 * Supports standard WooCommerce statuses as well as custom courier/return statuses
	 * such as `returned`, `return`, `rts`, `partially-returned`, `order-returned`, `cancelled`, `refunded`, `failed`.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	private static function is_reversal_order_status( $status ) {
		$status = strtolower( trim( (string) $status ) );
		$status = preg_replace( '/^wc-/', '', $status );

		$exact_matches = array(
			'cancelled',
			'canceled',
			'refunded',
			'failed',
			'trash',
			'pending',
			'returned',
			'return',
			'rts',
			'delivery-failed',
			'order-returned',
			'partially-returned',
		);

		if ( in_array( $status, $exact_matches, true ) ) {
			return true;
		}

		return (bool) preg_match( '/(return|cancel|refund|fail|rts|reject)/i', $status );
	}

	/**
	 * Set a temporary explicit source for the next stock operation.
	 *
	 * @param string $context Context key.
	 * @return void
	 */
	public static function set_context( $context ) {
		self::$context = sanitize_key( $context );
	}

	/**
	 * Clear an explicit source.
	 *
	 * @return void
	 */
	public static function clear_context() {
		self::$context = '';
	}

	/**
	 * Capture the authoritative database value before WooCommerce writes.
	 *
	 * @param WC_Product $product Product whose stock will change.
	 * @return void
	 */
	public static function capture_before_stock( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->get_id() ) {
			return;
		}

		$product_id       = absint( $product->get_id() );
		$has_stock_meta   = metadata_exists( 'post', $product_id, '_stock' );
		$raw_old          = $has_stock_meta ? get_post_meta( $product_id, '_stock', true ) : '';
		$raw_manage_old   = metadata_exists( 'post', $product_id, '_manage_stock' ) ? get_post_meta( $product_id, '_manage_stock', true ) : '';
		$has_numeric_old  = $has_stock_meta && '' !== $raw_old && null !== $raw_old && is_numeric( $raw_old );
		$was_managing     = 'yes' === $raw_manage_old && $has_numeric_old;
		$old_stock        = $has_numeric_old ? wc_stock_amount( $raw_old ) : 0;
		$pending_baseline = 'yes' === get_post_meta( $product_id, self::INITIAL_STOCK_PENDING_META, true );
		$is_already_live  = self::is_already_live_product( $product_id );

		// If the product was already live prior to this request and already had managed numeric stock
		// in the database, clear any stale pending baseline marker from earlier draft saves.
		if ( $pending_baseline && $was_managing && $is_already_live ) {
			delete_post_meta( $product_id, self::INITIAL_STOCK_PENDING_META );
			$pending_baseline = false;
		}

		if ( ! isset( self::$before_stock[ $product_id ] ) ) {
			self::$before_stock[ $product_id ] = array();
		}

		// Treat as baseline (do NOT count as Restocked or Manual Decrease) if:
		// - Product (or its parent) is in draft/auto-draft/pending or transitioning from draft->publish in this request
		// - Product still has the pending initial baseline marker
		// - Product did not previously have managed numeric stock in the database
		$is_initial_write = ! $is_already_live || $pending_baseline || ! $was_managing;

		self::$before_stock[ $product_id ][] = array(
			'old_stock'        => (float) $old_stock,
			'is_initial_write' => $is_initial_write,
			'source'           => self::detect_source(),
		);
	}

	/**
	 * Record an exact provisional manual movement after stock changed.
	 *
	 * @param WC_Product $product Product whose stock changed.
	 * @return void
	 */
	public static function record_after_stock( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->get_id() ) {
			return;
		}

		$owner_id = absint( $product->get_id() );
		$before   = null;

		if ( ! empty( self::$before_stock[ $owner_id ] ) ) {
			$before = array_shift( self::$before_stock[ $owner_id ] );
			if ( empty( self::$before_stock[ $owner_id ] ) ) {
				unset( self::$before_stock[ $owner_id ] );
			}
		}

		$new_stock = $product->get_stock_quantity();
		if ( null === $new_stock ) {
			$raw_new   = get_post_meta( $owner_id, '_stock', true );
			$new_stock = ( '' === $raw_new || null === $raw_new || ! is_numeric( $raw_new ) ) ? null : wc_stock_amount( $raw_new );
		}

		if ( null === $new_stock ) {
			return;
		}

		if ( ! is_array( $before ) ) {
			return;
		}

		// If the product is in draft/pending or this is its initial published stock baseline,
		// do not record a Restocked or Manual Decrease entry.
		if ( ! empty( $before['is_initial_write'] ) || ! self::is_already_live_product( $owner_id ) ) {
			if ( self::is_published_in_db( $owner_id ) ) {
				delete_post_meta( $owner_id, self::INITIAL_STOCK_PENDING_META );
				unset( self::$baseline_product_ids[ $owner_id ] );
			}
			return;
		}

		$old_stock = (float) $before['old_stock'];
		$new_stock = (float) $new_stock;
		$delta     = $new_stock - $old_stock;

		if ( abs( $delta ) < 0.0001 ) {
			return;
		}

		$source = isset( $before['source'] ) ? sanitize_key( $before['source'] ) : self::detect_source();

		// The report's Quick Update and Bulk Update use atomic delta operations and write
		// their own exact movement after WooCommerce returns the authoritative final stock.
		if ( 'stock_report_atomic' === $source ) {
			return;
		}

		$product_id = $product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : $owner_id;

		// Keep one provisional row; item/status hooks claim and reclassify it.

		$type = $delta > 0 ? 'restock' : 'adjustment';
		$note = $delta > 0 ? 'Stock increased' : 'Stock manually decreased';

		$log_id = VSR_DB::log(
			array(
				'variation_id'   => $owner_id,
				'stock_owner_id' => $owner_id,
				'product_id'     => $product_id,
				'change_type'    => $type,
				'quantity'       => $delta,
				'stock_before'   => $old_stock,
				'stock_after'    => $new_stock,
				'source'         => $source,
				'note'           => $note,
			)
		);

		if ( ! $log_id ) {
			return;
		}

		if ( ! isset( self::$recent_logs[ $owner_id ] ) ) {
			self::$recent_logs[ $owner_id ] = array();
		}
		self::$recent_logs[ $owner_id ][] = array(
			'log_id'  => $log_id,
			'from'    => $old_stock,
			'to'      => $new_stock,
			'claimed' => false,
		);
	}

	/**
	 * Reclassify a stock reduction as a sale using actual line-item data.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param array                 $change Stock change, with from/to/product.
	 * @param WC_Order              $order Order.
	 * @return void
	 */
	public static function record_order_sale( $item, $change, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order || empty( $change['product'] ) ) {
			return;
		}

		$product  = $change['product'];
		$owner_id = self::stock_owner_id( $product );
		$from     = isset( $change['from'] ) ? (float) $change['from'] : null;
		$to       = isset( $change['to'] ) ? (float) $change['to'] : null;
		if ( null === $from || null === $to || $from <= $to ) {
			return;
		}

		self::reclassify_or_insert(
			$owner_id,
			$from,
			$to,
			array(
				'variation_id'  => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
				'product_id'    => $item->get_product_id(),
				'change_type'   => 'sale',
				'quantity'      => -abs( $from - $to ),
				'order_id'      => $order->get_id(),
				'order_item_id' => $item->get_id(),
				'user_id'       => null,
				'source'        => 'order',
				'note'          => 'Stock reduced for order',
			)
		);
	}

	/**
	 * Reclassify stock restored after cancellation/pending/returned status.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param float                 $new_stock New stock.
	 * @param float                 $old_stock Old stock.
	 * @param WC_Order              $order Order.
	 * @return void
	 */
	public static function record_order_restore( $item, $new_stock, $old_stock, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order ) {
			return;
		}
		$product = $item->get_product();
		if ( ! $product ) {
			return;
		}

		$owner_id = self::stock_owner_id( $product );
		$from     = (float) $old_stock;
		$to       = (float) $new_stock;
		if ( $to <= $from ) {
			return;
		}

		self::reclassify_or_insert(
			$owner_id,
			$from,
			$to,
			array(
				'variation_id'  => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
				'product_id'    => $item->get_product_id(),
				'change_type'   => 'sale_reversal',
				'quantity'      => abs( $to - $from ),
				'order_id'      => $order->get_id(),
				'order_item_id' => $item->get_id(),
				'user_id'       => null,
				'source'        => 'order_restore',
				'note'          => 'Order stock restored',
			)
		);
	}

	/**
	 * Reclassify stock added through WooCommerce's refund-restock checkbox.
	 *
	 * @param int        $product_id Product ID from WooCommerce.
	 * @param float      $old_stock Old stock.
	 * @param float      $new_stock New stock.
	 * @param WC_Order   $order Order.
	 * @param WC_Product $product Product object.
	 * @return void
	 */
	public static function record_refund_restore( $product_id, $old_stock, $new_stock, $order, $product ) {
		if ( ! $order instanceof WC_Order || ! $product instanceof WC_Product ) {
			return;
		}

		$owner_id = self::stock_owner_id( $product );
		$from     = (float) $old_stock;
		$to       = (float) $new_stock;
		if ( $to <= $from ) {
			return;
		}

		$order_item_id = self::find_order_item_id( $order, $product->get_id() );
		$item_id       = $product->get_id();
		$parent_id     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		self::reclassify_or_insert(
			$owner_id,
			$from,
			$to,
			array(
				'variation_id'  => $item_id,
				'product_id'    => $parent_id,
				'change_type'   => 'sale_reversal',
				'quantity'      => abs( $to - $from ),
				'order_id'      => $order->get_id(),
				'order_item_id' => $order_item_id,
				'user_id'       => null,
				'source'        => 'refund_restock',
				'note'          => 'Refunded item returned to stock',
			)
		);
	}

	/**
	 * Whole-order fallback for older/custom WooCommerce stock reducers.
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return void
	 */
	public static function record_order_sale_fallback( $order ) {
		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$owner_id  = self::stock_owner_id( $product );
			$reduced   = (float) $item->get_meta( '_reduced_stock', true );
			$candidate = self::claim_recent_direction( $owner_id, 'decrease', $reduced > 0 ? $reduced : null );
			if ( ! $candidate ) {
				$candidate = self::claim_recent_direction( $owner_id, 'decrease', null );
			}

			if ( ! $candidate ) {
				continue;
			}

			VSR_DB::update_log(
				$candidate['log_id'],
				array(
					'variation_id'   => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					'stock_owner_id' => $owner_id,
					'product_id'     => $item->get_product_id(),
					'change_type'    => 'sale',
					'quantity'       => -abs( $candidate['from'] - $candidate['to'] ),
					'stock_before'   => $candidate['from'],
					'stock_after'    => $candidate['to'],
					'order_id'       => $order->get_id(),
					'order_item_id'  => $item->get_id(),
					'user_id'        => null,
					'source'         => 'order',
					'note'           => 'Stock reduced for order',
				)
			);
		}
	}

	/**
	 * Whole-order fallback for older/custom WooCommerce stock restorers.
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return void
	 */
	public static function record_order_restore_fallback( $order ) {
		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$owner_id  = self::stock_owner_id( $product );
			$candidate = self::claim_recent_direction( $owner_id, 'increase', null );
			if ( ! $candidate ) {
				continue;
			}

			VSR_DB::update_log(
				$candidate['log_id'],
				array(
					'variation_id'   => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					'stock_owner_id' => $owner_id,
					'product_id'     => $item->get_product_id(),
					'change_type'    => 'sale_reversal',
					'quantity'       => abs( $candidate['to'] - $candidate['from'] ),
					'stock_before'   => $candidate['from'],
					'stock_after'    => $candidate['to'],
					'order_id'       => $order->get_id(),
					'order_item_id'  => $item->get_id(),
					'user_id'        => null,
					'source'         => 'order_restore',
					'note'           => 'Order stock restored',
				)
			);
		}
	}

	/**
	 * Update a matching provisional row, or insert a fallback row.
	 *
	 * @param int   $owner_id Stock owner ID.
	 * @param float $from Old stock.
	 * @param float $to New stock.
	 * @param array $data Order movement fields.
	 * @return void
	 */
	private static function reclassify_or_insert( $owner_id, $from, $to, $data ) {
		$signature = hash( 'sha256', wp_json_encode( array( $owner_id, $from, $to, $data['order_id'], $data['order_item_id'], $data['change_type'] ) ) );
		if ( isset( self::$observed_order_movements[ $signature ] ) ) {
			return;
		}
		$candidate = self::claim_recent_log( $owner_id, $from, $to );

		$data['stock_owner_id'] = $owner_id;
		$data['stock_before']   = $from;
		$data['stock_after']    = $to;

		if ( $candidate ) {
			if ( VSR_DB::update_log( $candidate['log_id'], $data ) ) {
				self::$observed_order_movements[ $signature ] = true;
			}
			return;
		}

		if ( VSR_DB::log( $data ) ) {
			self::$observed_order_movements[ $signature ] = true;
		}
	}

	/**
	 * Claim the most recent exact from/to movement for an order hook.
	 *
	 * @param int   $owner_id Stock owner ID.
	 * @param float $from Old stock.
	 * @param float $to New stock.
	 * @return array|null
	 */
	private static function claim_recent_log( $owner_id, $from, $to ) {
		if ( empty( self::$recent_logs[ $owner_id ] ) ) {
			return null;
		}

		for ( $i = count( self::$recent_logs[ $owner_id ] ) - 1; $i >= 0; $i-- ) {
			$event = self::$recent_logs[ $owner_id ][ $i ];
			if ( $event['claimed'] ) {
				continue;
			}
			if ( abs( $event['from'] - $from ) < 0.0001 && abs( $event['to'] - $to ) < 0.0001 ) {
				self::$recent_logs[ $owner_id ][ $i ]['claimed'] = true;
				return $event;
			}
		}
		return null;
	}

	/**
	 * Claim a recent movement by direction and, when known, amount.
	 *
	 * @param int        $owner_id Stock owner ID.
	 * @param string     $direction increase|decrease.
	 * @param float|null $amount Optional absolute quantity.
	 * @return array|null
	 */
	private static function claim_recent_direction( $owner_id, $direction, $amount = null ) {
		if ( empty( self::$recent_logs[ $owner_id ] ) ) {
			return null;
		}

		for ( $i = count( self::$recent_logs[ $owner_id ] ) - 1; $i >= 0; $i-- ) {
			$event = self::$recent_logs[ $owner_id ][ $i ];
			if ( $event['claimed'] ) {
				continue;
			}

			$delta = (float) $event['to'] - (float) $event['from'];
			if ( 'decrease' === $direction && $delta >= -0.0001 ) {
				continue;
			}
			if ( 'increase' === $direction && $delta <= 0.0001 ) {
				continue;
			}
			if ( null !== $amount && $amount > 0 && abs( abs( $delta ) - (float) $amount ) >= 0.0001 ) {
				continue;
			}

			self::$recent_logs[ $owner_id ][ $i ]['claimed'] = true;
			return $event;
		}

		return null;
	}

	/**
	 * Get the product object that actually owns stock.
	 *
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private static function stock_owner_id( $product ) {
		if ( method_exists( $product, 'get_stock_managed_by_id' ) ) {
			return absint( $product->get_stock_managed_by_id() );
		}
		return absint( $product->get_id() );
	}

	/**
	 * Find an order line for a product when the refund hook omits line ID.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $product_id Product/variation ID.
	 * @return int|null
	 */
	private static function find_order_item_id( $order, $product_id ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$item_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			if ( (int) $item_product_id === (int) $product_id ) {
				return $item->get_id();
			}
		}
		return null;
	}

	/**
	 * Describe where a generic stock update came from.
	 *
	 * @return string
	 */
	private static function detect_source() {
		if ( self::$context ) {
			return self::$context;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest_api';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp_cli';
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}
		if ( is_admin() ) {
			return 'product_editor';
		}
		return 'integration';
	}
}
