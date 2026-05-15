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

/**
 * Converts the demo product weight of 10 g into the store's weight unit.
 */
function wlp_demo_product_weight_for_store_unit(): string {
	$unit  = strtolower( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
	$grams = 10.0;
	$value = match ( $unit ) {
		'g'   => $grams,
		'lbs' => $grams * 0.00220462262185,
		'oz'  => $grams * 0.0352739619496,
		default => $grams / 1000,
	};

	return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
}

/**
 * Finds existing demo products by SKU or legacy title.
 *
 * @return array<int, WC_Product>
 */
function wlp_demo_products(): array {
	$products = array();
	$product_ids = array();
	$sku_id   = wc_get_product_id_by_sku( 'wlp-demo-logistics-item' );

	if ( $sku_id ) {
		$product = wc_get_product( $sku_id );
		if ( $product instanceof WC_Product ) {
			$products[] = $product;
			$product_ids[] = $product->get_id();
		}
	}

	foreach ( wc_get_products( array( 'limit' => -1 ) ) as $product ) {
		if ( $product instanceof WC_Product && 'Demo Logistics Item' === $product->get_name() && ! in_array( $product->get_id(), $product_ids, true ) ) {
			$products[] = $product;
			$product_ids[] = $product->get_id();
		}
	}

	return $products;
}

/**
 * Ensures demo products have a usable shipping weight.
 *
 * @return array<int, WC_Product>
 */
function wlp_repair_demo_products(): array {
	$products = wlp_demo_products();

	foreach ( $products as $product ) {
		$product->set_weight( wlp_demo_product_weight_for_store_unit() );
		$product->update_meta_data( '_wlp_demo_product', 'yes' );
		$product->save();
	}

	return $products;
}

$demo_products = wlp_repair_demo_products();

$existing = wc_get_orders(
	array(
		'limit'      => 1,
		'meta_key'   => '_wlp_demo_order',
		'meta_value' => 'yes',
	)
);

if ( ! empty( $existing ) ) {
	echo "Demo orders already exist; repaired demo product weights.\n";
	return;
}

$product = $demo_products[0] ?? new WC_Product_Simple();
$product->set_name( 'Demo Logistics Item' );
if ( '' === $product->get_sku() && ! wc_get_product_id_by_sku( 'wlp-demo-logistics-item' ) ) {
	$product->set_sku( 'wlp-demo-logistics-item' );
}
$product->set_regular_price( '29.00' );
$product->set_price( '29.00' );
$product->set_weight( wlp_demo_product_weight_for_store_unit() );
$product->update_meta_data( '_wlp_demo_product', 'yes' );
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
