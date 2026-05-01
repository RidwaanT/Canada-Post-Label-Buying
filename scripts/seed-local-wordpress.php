<?php
/**
 * Seeds a local WordPress/WooCommerce install with demo orders for visual testing.
 *
 * This file is intended for local Docker setup only.
 *
 * @package WooLogisticsPlugin
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "WooCommerce is not active.\n" );
	return;
}

$existing = wc_get_orders(
	array(
		'limit'      => 1,
		'meta_key'   => '_wlp_demo_order',
		'meta_value' => 'yes',
	)
);

if ( ! empty( $existing ) ) {
	echo "Demo orders already exist.\n";
	return;
}

$product = new WC_Product_Simple();
$product->set_name( 'Demo Logistics Item' );
$product->set_regular_price( '29.00' );
$product->set_price( '29.00' );
$product->save();

$addresses = array(
	array(
		'first_name' => 'Avery',
		'last_name'  => 'Morgan',
		'address_1'  => '111 Wellington St',
		'city'       => 'Ottawa',
		'state'      => 'ON',
		'postcode'   => 'K1A0A9',
		'country'    => 'CA',
		'email'      => 'avery@example.com',
		'phone'      => '4165550100',
	),
	array(
		'first_name' => 'Sam',
		'last_name'  => 'Patel',
		'address_1'  => '100 Queen St W',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5H2N2',
		'country'    => 'CA',
		'email'      => 'sam@example.com',
		'phone'      => '6475550101',
	),
);

foreach ( $addresses as $address ) {
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->update_meta_data( '_wlp_demo_order', 'yes' );
	$order->calculate_totals();
	$order->set_status( 'processing' );
	$order->save();
}

echo "Created demo product and demo processing orders.\n";
