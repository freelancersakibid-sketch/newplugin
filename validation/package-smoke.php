<?php
wp_set_current_user(1);
global $wpdb;
if ( ! class_exists('VSR_Stock_Service') || get_option('vsr_db_version') !== VSR_DB_VERSION ) { throw new RuntimeException('ZIP activation/schema failure'); }
$p=new WC_Product_Simple();$p->set_name('ZIP installation stock fixture');$p->set_status('publish');$p->set_manage_stock(true);$p->set_stock_quantity(10);$p->set_regular_price(25);$p->save();
$result=VSR_Stock_Service::apply($p->get_id(),'set',6,wp_generate_uuid4(),10);
if (is_wp_error($result) || (float)get_post_meta($p->get_id(),'_stock',true)!==6.0) { throw new RuntimeException('ZIP stock smoke test failed'); }
$report=VSR_DB::get_report_page(VSR_Admin::get_filters_from_request());
if($report['total']!==1 || count($report['rows'])!==1) { throw new RuntimeException('ZIP report smoke test failed'); }
echo "PASS: installed ZIP activates, migrates, sets stock and renders report data on a clean database\n";
