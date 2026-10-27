<?php
/** Read-only, versioned sourcing items for the existing private wholesale API. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WLP_Wholesale_Intake {
    /** Never infer wholesale classification from a fee name or order status. */
    public static function is_wholesale( WC_Order $order ): bool {
        return 'yes' === $order->get_meta( '_pg_is_wholesale', true ) || 'yes' === $order->get_meta( '_wlp_is_wholesale', true ) || 'wholesale_quote' === $order->get_meta( '_pg_order_source', true );
    }

    /** Projects only this order's items; joined shipment groups remain separate orders. */
    public static function project( WC_Order $order ): array {
        $items = array();
        $review = false;
        foreach ( $order->get_items( 'line_item' ) as $id => $line ) {
            if ( ! $line instanceof WC_Order_Item_Product ) { continue; }
            $quantity = (float) $line->get_quantity();
            if ( $quantity <= 0 || floor( $quantity ) !== $quantity || $quantity > 1000000 ) { $review = true; break; }
            $product = $line->get_product();
            $items[] = array( 'id' => (string) $id, 'title' => $line->get_name(), 'sku' => $product ? (string) $product->get_sku() : '', 'quantity' => (int) $quantity, 'unit_price' => round( (float) $line->get_total() / $quantity, 2 ) );
        }
        $raw = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
        if ( '' !== $raw ) {
            // Do not merge potentially overlapping native and legacy quote representations.
            if ( ! empty( $items ) ) { $review = true; }
            $items = array();
            $seen = array();
            foreach ( array_filter( array_map( 'trim', explode( ';', $raw ) ) ) as $text ) {
                if ( ! preg_match( '/^(.+?)\s*-\s*([0-9][0-9,]*)\s+vials?(?:\s+complimentary)?$/i', $text, $match ) ) { $review = true; break; }
                $title = trim( $match[1] );
                $quantity = (float) str_replace( ',', '', $match[2] );
                $id = 'quote_' . substr( hash( 'sha256', $title ), 0, 20 );
                if ( isset( $seen[$id] ) || $quantity <= 0 || $quantity > 1000000 || floor( $quantity ) !== $quantity ) { $review = true; break; }
                $seen[$id] = true;
                $items[] = array( 'id' => $id, 'title' => $title, 'sku' => '', 'quantity' => (int) $quantity, 'unit_price' => null );
            }
        }
        foreach ( $items as $item ) {
            if ( '' === trim( $item['title'] ) || strlen( $item['title'] ) > 250 || strlen( $item['sku'] ) > 150 || ( null !== $item['unit_price'] && ( ! is_finite( $item['unit_price'] ) || $item['unit_price'] < 0 ) ) ) { $review = true; }
        }
        $review = $review || empty( $items ) || count( $items ) > 200;
        return array( 'version' => 1, 'is_wholesale' => self::is_wholesale( $order ), 'items' => $review ? array() : $items, 'review_required' => $review );
    }
}
