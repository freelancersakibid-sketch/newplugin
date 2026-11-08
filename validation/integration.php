<?php
global $wpdb;
wp_set_current_user( 1 );
add_filter( 'pre_wp_mail', '__return_true' );
$GLOBALS['passed'] = 0;
function verify( $condition, $label ) {
	global $passed;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$passed;
	echo "PASS: {$label}\n";
}
function product( $stock = 10, $backorders = 'no' ) {
	$p = new WC_Product_Simple(); $p->set_name( 'Fixture ' . wp_generate_uuid4() ); $p->set_status( 'publish' ); $p->set_manage_stock( true ); $p->set_stock_quantity( $stock ); $p->set_backorders( $backorders ); $p->set_regular_price( '20' ); $p->save(); return $p;
}
function qty( $id ) { global $wpdb; return (float) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock'", $id ) ); }
function command( $p, $op, $qty, $key = null, $expected = null ) { return VSR_Stock_Service::apply( $p->get_id(), $op, $qty, $key ?: wp_generate_uuid4(), $expected ); }
$p = product();
$r = command( $p, 'set', 7, null, 10 ); verify( ! is_wp_error( $r ) && qty( $p->get_id() ) === 7.0, 'absolute set' );
$r = command( $p, 'set', 20, null, 10 ); verify( is_wp_error( $r ) && qty( $p->get_id() ) === 7.0, 'stale expected stock rejected' );
$r = command( $p, 'decrease', 8 ); verify( is_wp_error( $r ) && qty( $p->get_id() ) === 7.0, 'negative decrease rejected' );
$r = command( $p, 'set', -1 ); verify( is_wp_error( $r ), 'negative exact set rejected' );
$key = wp_generate_uuid4(); $r = command( $p, 'increase', 2, $key ); $again = command( $p, 'increase', 2, $key );
verify( ! is_wp_error( $again ) && $again['replayed'] && qty( $p->get_id() ) === 9.0, 'duplicate retry changes stock once' );
$r = command( $p, 'increase', 3, $key ); verify( is_wp_error( $r ) && qty( $p->get_id() ) === 9.0, 'request identifier payload mismatch rejected' );
$b = product( 1, 'yes' ); $r = command( $b, 'decrease', 3 ); verify( ! is_wp_error( $r ) && qty( $b->get_id() ) === -2.0, 'backorder enabled decrease' );
foreach ( array( 'NaN', '1e999', 'abc', array( 1 ) ) as $bad ) { verify( is_wp_error( command( $p, 'set', $bad ) ), 'invalid numeric quantity rejected' ); }
verify( is_wp_error( command( $p, 'increase', 1, 'short' ) ), 'invalid request identifier rejected' );
// Inject a real audit INSERT failure; stock must roll back.
$fail_insert = static function( $query ) { global $wpdb; return strpos( $query, 'INSERT INTO `' . VSR_DB::table() . '`' ) === 0 ? 'INSERT INTO vsr_deliberately_missing_table VALUES (1)' : $query; };
$wpdb->suppress_errors( true ); add_filter( 'query', $fail_insert );
$key = wp_generate_uuid4(); $r = command( $p, 'increase', 4, $key ); remove_filter( 'query', $fail_insert );
verify( is_wp_error( $r ) && qty( $p->get_id() ) === 9.0, 'audit database failure rolls stock back' );
$r = command( $p, 'increase', 4, $key ); verify( ! is_wp_error( $r ) && qty( $p->get_id() ) === 13.0, 'rolled-back request can be retried' );
// Failed stock, lookup or receipt writes must never produce a successful command.
$fail_stock = static function( $query ) { return 'UPDATE vsr_deliberately_missing_table SET missing=1'; };
add_filter( 'woocommerce_update_product_stock_query', $fail_stock );
$prior = qty( $p->get_id() ); $r = command( $p, 'increase', 1 ); remove_filter( 'woocommerce_update_product_stock_query', $fail_stock );
verify( is_wp_error( $r ) && qty( $p->get_id() ) === $prior, 'failed stock SQL never reports success' );
$fail_lookup = static function( $query ) { global $wpdb; return strpos( $query, 'REPLACE INTO `' . $wpdb->prefix . 'wc_product_meta_lookup`' ) === 0 ? 'INSERT INTO vsr_deliberately_missing_table VALUES (1)' : $query; };
add_filter( 'query', $fail_lookup ); $r = command( $p, 'increase', 1 ); remove_filter( 'query', $fail_lookup );
verify( is_wp_error( $r ) && qty( $p->get_id() ) === $prior, 'failed WooCommerce lookup write rolls stock back' );
$fail_receipt = static function( $query ) { global $wpdb; return strpos( $query, 'UPDATE `' . $wpdb->prefix . 'vsr_operations`' ) === 0 ? 'UPDATE vsr_deliberately_missing_table SET missing=1' : $query; };
add_filter( 'query', $fail_receipt ); $r = command( $p, 'increase', 1 ); remove_filter( 'query', $fail_receipt );
verify( is_wp_error( $r ) && qty( $p->get_id() ) === $prior, 'failed receipt write rolls stock and audit back' );
$method = new ReflectionMethod( 'VSR_Exporter', 'safe_csv_value' ); $method->setAccessible( true );
verify( $method->invoke( null, '=1+1' ) === "'=1+1", 'CSV spreadsheet formula text neutralized' );
VSR_DB::audit_failure( 'Intentional test failure notice' ); ob_start(); VSR_DB::audit_notice(); $notice = ob_get_clean();
verify( strpos( $notice, 'audit write failed' ) !== false, 'audit failure admin notification rendered' );
delete_option( 'vsr_audit_write_error' );
// Shared inventory: two variation sales must contribute to one physical owner's velocity.
$parent = new WC_Product_Variable(); $parent->set_name( 'Shared fixture' ); $parent->set_status( 'publish' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 30 ); $parent->set_regular_price( '12' ); $parent->save();
$vars = array();
foreach ( array( 10, 40 ) as $price ) { $v = new WC_Product_Variation(); $v->set_parent_id( $parent->get_id() ); $v->set_status( 'publish' ); $v->set_regular_price( $price ); $v->set_sku( 'VSR-' . wp_generate_uuid4() ); $v->save(); $vars[] = $v; }
$r = command( $vars[0], 'increase', 2 ); verify( ! is_wp_error( $r ) && qty( $parent->get_id() ) === 32.0, 'variation updates physical parent owner' );
$order = wc_create_order(); foreach ( $vars as $v ) { $order->add_product( $v, 3 ); } $order->set_status( 'completed' ); $order->set_date_completed( time() - 86400 ); $order->save();
$filters = VSR_Admin::get_filters_from_request();
$rows = VSR_DB::get_export_rows( $filters, 0, 500 );
$shared = array_values( array_filter( $rows, static function( $r ) use ( $parent ) { return (int) $r->stock_owner_id === $parent->get_id(); } ) );
verify( count( $shared ) === 2 && (float) $shared[0]->owner_sold_30d === 6.0 && $shared[0]->daily_velocity === 0.2 && $shared[1]->daily_velocity === 0.2, 'shared parent demand includes sibling sales' );
verify( (float) $shared[0]->stock_retail_value === (float) $shared[1]->stock_retail_value, 'shared retail valuation uses owner price' );

$filtered = $filters; $filtered['search'] = $vars[0]->get_sku(); $one = VSR_DB::get_summary( $filtered );
$filtered['search'] = $vars[1]->get_sku(); $two = VSR_DB::get_summary( $filtered );
verify( $one['matching_items'] === 1 && $two['matching_items'] === 1 && $one['stock_retail_value'] === $two['stock_retail_value'], 'filtering siblings cannot change shared inventory valuation' );
$filtered['search'] = "' OR 1=1 --"; verify( VSR_DB::get_summary( $filtered )['matching_items'] === 0, 'SQL injection search remains literal text' );
$filtered = $filters; $filtered['date_from'] = '2099-01-01'; $filtered['date_to'] = '2099-12-31'; verify( VSR_DB::get_summary( $filtered )['total_sold'] === 0.0, 'sales date range applies' );
$filtered = $filters; $filtered['not_selling_days'] = 'never'; verify( is_array( VSR_DB::get_export_rows( $filtered ) ), 'never sold latest-date filter executes' );
register_taxonomy( 'pa_vsr_color', array( 'product' ) ); $slug = 'vsr-red-' . wp_rand( 10000, 99999 );
wp_insert_term( 'Correct Red', 'pa_vsr_color', array( 'slug' => $slug ) );
wp_insert_term( 'Wrong Red', 'product_cat', array( 'slug' => $slug ) );
update_post_meta( $vars[0]->get_id(), 'attribute_pa_vsr_color', $slug );
$filtered = $filters; $filtered['search'] = $vars[0]->get_sku(); $attrs = VSR_DB::get_export_rows( $filtered );
verify( $attrs[0]->attribute_label === 'Correct Red', 'attribute lookup chooses term from correct taxonomy' );
$summary = VSR_DB::get_summary( $filters ); verify( $summary['matching_items'] >= 4, 'summary query executes' );
$log = VSR_DB::log( array( 'variation_id' => $vars[0]->get_id(), 'product_id' => $parent->get_id(), 'stock_owner_id' => $parent->get_id(), 'change_type' => 'sale', 'quantity' => -1, 'created_at' => current_time( 'mysql' ) ) );
$rows = VSR_DB::get_export_rows( $filters, 0, 500 ); $match = array_values( array_filter( $rows, static function( $r ) use ( $vars ) { return (int) $r->item_id === $vars[0]->get_id(); } ) );
verify( strtotime( $match[0]->last_sale_date ) > time() - 600, 'last sale selects latest audit or order date' );
// Stable audit cursor across an insertion, and identical allowlisted movement filter.
$first = VSR_DB::get_audit_logs( array( 'item_id' => $p->get_id(), 'limit' => 1, 'change_type' => 'restock' ) );
command( $p, 'increase', 1 );
$next = VSR_DB::get_audit_logs( array( 'item_id' => $p->get_id(), 'limit' => 500, 'change_type' => 'restock', 'before_id' => $first['next_id'], 'snapshot_id' => $first['snapshot_id'], 'count' => false ) );
verify( count( $first['rows'] ) === 1 && ! array_filter( $next['rows'], static function( $r ) use ( $first ) { return $r->id >= $first['next_id'] || 'restock' !== $r->change_type; } ), 'audit cursor does not repeat or include concurrent insertion' );
$badquery = static function( $query ) { return strpos( $query, 'SELECT * FROM (' ) === 0 ? 'SELECT * FROM vsr_deliberately_missing_table' : $query; };
add_filter( 'query', $badquery ); $thrown = false; try { VSR_DB::get_export_rows( $filters ); } catch ( Throwable $e ) { $thrown = true; } remove_filter( 'query', $badquery ); verify( $thrown, 'failed report query throws instead of empty success' );
// Courier-style status change plus repeated item and fallback hooks must share one row.
$ret = product( 10 ); $ro = wc_create_order(); $line = $ro->add_product( $ret, 2 ); $ro->set_status( 'processing' ); $ro->save();
$ri = $ro->get_item( $line ); $old = qty( $ret->get_id() );
VSR_Tracker::begin_order_status_watch( $ro->get_id(), 'processing', 'returned', $ro );
$new = wc_update_product_stock( $ret->get_id(), 2, 'increase' );
VSR_Tracker::record_order_restore( $ri, $new, $old, $ro );
VSR_Tracker::record_order_restore( $ri, $new, $old, $ro );
VSR_Tracker::end_order_status_watch( $ro->get_id(), 'processing', 'returned', $ro );
$returns = VSR_DB::get_audit_logs( array( 'item_id' => $ret->get_id(), 'change_type' => 'sale_reversal' ) );
verify( count( $returns['rows'] ) === 1 && (float) $returns['rows'][0]->quantity === 2.0, 'custom return and duplicate item hooks produce one audit row' );
// Test AJAX methods through WordPress nonce, permission and JSON layers.
class VSR_Test_Exit extends Exception {}
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', static function() { return static function( $message ) { throw new VSR_Test_Exit( (string) $message ); }; } );
function ajax( $callback, $fields, $nonce_action ) {
	$_POST = $fields; $_REQUEST = $fields; $_REQUEST['nonce'] = wp_create_nonce( $nonce_action ); $_POST['nonce'] = $_REQUEST['nonce'];
	ob_start(); try { call_user_func( $callback ); } catch ( VSR_Test_Exit $e ) {} $output = ob_get_clean(); return json_decode( $output, true );
}
$r = ajax( array( 'VSR_Admin', 'ajax_bulk_update_stock' ), array( 'item_ids' => $p->get_id() . ',' . $b->get_id(), 'operation' => 'decrease', 'quantity' => 999, 'request_id' => wp_generate_uuid4() ), 'vsr_bulk_update_stock' );
verify( ! $r['success'] && $r['data']['updated_count'] === 1 && $r['data']['failed_count'] === 1, 'bulk partial failure has explicit per-owner results and no success flag' );
$key = wp_generate_uuid4(); $r = ajax( array( 'VSR_Admin', 'ajax_bulk_update_stock' ), array( 'item_ids' => implode( ',', array_map( static function( $v ) { return $v->get_id(); }, $vars ) ), 'operation' => 'increase', 'quantity' => 1, 'request_id' => $key ), 'vsr_bulk_update_stock' );
verify( $r['success'] && $r['data']['updated_count'] === 1, 'bulk deduplicates shared owners' );
wp_set_current_user( 0 ); $r = ajax( array( 'VSR_Admin', 'ajax_update_stock' ), array( 'item_id' => $p->get_id(), 'new_stock' => 100, 'expected_stock' => qty( $p->get_id() ), 'request_id' => wp_generate_uuid4() ), 'vsr_update_stock' ); verify( ! $r['success'], 'unauthorized stock endpoint denied' );
wp_set_current_user( 1 ); $_REQUEST = array( 'nonce' => 'invalid' ); $denied = false; try { VSR_Admin::ajax_update_stock(); } catch ( VSR_Test_Exit $e ) { $denied = true; } verify( $denied, 'invalid nonce denied' );
// Historical import records sales evidence without changing stock, for managed and unmanaged products.
$u = new WC_Product_Simple(); $u->set_name( 'Unmanaged import fixture' ); $u->set_status( 'publish' ); $u->set_regular_price( 10 ); $u->save();
$o = wc_create_order(); $o->add_product( $u, 2 ); $o->set_status( 'completed' ); $o->save(); $before = qty( $parent->get_id() );
$r = ajax( array( 'VSR_Importer', 'ajax_import_batch' ), array( 'offset' => 0 ), 'vsr_import_batch' );
verify( $r['success'] && qty( $parent->get_id() ) === $before, 'historical import never changes physical stock' );
$evidence = VSR_DB::get_audit_logs( array( 'item_id' => $u->get_id(), 'change_type' => 'historical_sale' ) ); verify( count( $evidence['rows'] ) === 1 && null === $evidence['rows'][0]->stock_before, 'unmanaged historical sale explicitly unverified' );
$r = ajax( array( 'VSR_Importer', 'ajax_import_batch' ), array( 'offset' => 0 ), 'vsr_import_batch' ); $evidence = VSR_DB::get_audit_logs( array( 'item_id' => $u->get_id(), 'change_type' => 'historical_sale' ) ); verify( count( $evidence['rows'] ) === 1, 'historical import retry produces no duplicate evidence' );
$preserved = $wpdb->get_row( 'SELECT * FROM ' . VSR_DB::table() . ' ORDER BY id LIMIT 1', ARRAY_A );
$legacy_id = VSR_DB::log( array( 'variation_id' => $u->get_id(), 'product_id' => $u->get_id(), 'change_type' => 'sale', 'quantity' => -1, 'source' => 'historical_import' ) );
$legacy_history = VSR_DB::get_audit_logs( array( 'item_id' => $u->get_id(), 'change_type' => 'historical_sale' ) );
verify( in_array( $legacy_id, array_map( static function( $row ) { return (int) $row->id; }, $legacy_history['rows'] ), true ), 'legacy imported sale displayed and filtered as unverified evidence' );
verify( $wpdb->get_var( $wpdb->prepare( 'SELECT change_type FROM ' . VSR_DB::table() . ' WHERE id=%d', $legacy_id ) ) === 'sale', 'legacy audit row classification does not rewrite stored evidence' );
// Real migration failure must not advance schema version.
update_option( 'vsr_db_version', '2.1.0' );
$fail_schema = static function( $query ) { return strpos( $query, 'SHOW COLUMNS FROM `' . VSR_DB::table() . '`' ) === 0 ? 'SHOW COLUMNS FROM vsr_deliberately_missing_table' : $query; };
add_filter( 'query', $fail_schema ); $result = VSR_Installer::install(); remove_filter( 'query', $fail_schema ); verify( ! $result && get_option( 'vsr_db_version' ) === '2.1.0', 'failed migration never advances version' );
verify( VSR_Installer::install() && get_option( 'vsr_db_version' ) === VSR_DB_VERSION, 'migration retries successfully' );
verify( $preserved === $wpdb->get_row( 'SELECT * FROM ' . VSR_DB::table() . ' ORDER BY id LIMIT 1', ARRAY_A ), 'migration preserves existing audit row exactly' );
delete_option( 'vsr_audit_write_error' );
file_put_contents( '/tests/fixture.json', wp_json_encode( array( 'product' => $p->get_id(), 'parent' => $parent->get_id() ) ) );
echo "TOTAL: " . $GLOBALS['passed'] . " assertions passed\n";
