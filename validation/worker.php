<?php
wp_set_current_user( 1 );
$config = json_decode( file_get_contents( '/tests/' . $args[0] ), true );
if ( ! empty( $config['hold'] ) ) {
	add_action( 'woocommerce_product_before_set_stock', static function() { file_put_contents( '/tests/locked', 'locked' ); usleep( 800000 ); }, 99 );
}
if ( 'core' === $config['op'] ) {
	$result = wc_update_product_stock( $config['id'], $config['qty'], 'decrease' );
} else {
	$result = VSR_Stock_Service::apply( $config['id'], $config['op'], $config['qty'], $config['key'], isset( $config['expected'] ) ? $config['expected'] : null );
}
echo wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'result' => $result ) );
