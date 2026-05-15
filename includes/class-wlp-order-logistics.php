<?php
/**
 * Purpose: Reads and writes logistics metadata on WooCommerce orders.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order logistics metadata helpers.
 */
final class WLP_Order_Logistics {
	/**
	 * Returns normalized logistics metadata for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<string, string>
	 */
	public static function read( WC_Order $order ): array {
		return array(
			'label_created_at'       => self::string_meta( $order, WLP_Meta_Keys::LABEL_CREATED_AT, 'label_created_at' ),
			'label_artifact_url'     => self::string_meta( $order, WLP_Meta_Keys::LABEL_ARTIFACT_URL, 'label_artifact_url' ),
			'tracking_number'        => self::string_meta( $order, WLP_Meta_Keys::TRACKING_NUMBER, 'tracking_number' ),
			'tracking_url'           => self::string_meta( $order, WLP_Meta_Keys::TRACKING_URL, 'tracking_url' ),
			'service_code'           => self::string_meta( $order, WLP_Meta_Keys::SERVICE_CODE, 'service_code' ),
			'service_name'           => self::string_meta( $order, WLP_Meta_Keys::SERVICE_NAME, 'service_name' ),
			'shipping_cost'          => self::string_meta( $order, WLP_Meta_Keys::SHIPPING_COST, 'shipping_cost' ),
			'shipping_currency'      => self::string_meta( $order, WLP_Meta_Keys::SHIPPING_CURRENCY, 'shipping_currency' ),
			'shipment_id'            => self::string_meta( $order, WLP_Meta_Keys::SHIPMENT_ID, 'shipment_id' ),
			'expected_delivery_date' => self::string_meta( $order, WLP_Meta_Keys::EXPECTED_DELIVERY_DATE, 'expected_delivery_date' ),
			'shipped_at'             => self::string_meta( $order, WLP_Meta_Keys::SHIPPED_AT, 'shipped_at' ),
			'delivered_at'           => self::string_meta( $order, WLP_Meta_Keys::DELIVERED_AT, 'delivered_at' ),
			'preset_id'              => self::string_meta( $order, WLP_Meta_Keys::PRESET_ID ),
			'shipment_weight_kg'     => self::string_meta( $order, WLP_Meta_Keys::SHIPMENT_WEIGHT_KG ),
		);
	}

	/**
	 * Writes label metadata after a successful carrier purchase.
	 *
	 * @param WC_Order                         $order WooCommerce order.
	 * @param array<string, string|float|null> $metadata Label metadata values.
	 */
	public static function write_label( WC_Order $order, array $metadata ): void {
		$map = array(
			'label_created_at'       => WLP_Meta_Keys::LABEL_CREATED_AT,
			'label_artifact_url'     => WLP_Meta_Keys::LABEL_ARTIFACT_URL,
			'tracking_number'        => WLP_Meta_Keys::TRACKING_NUMBER,
			'tracking_url'           => WLP_Meta_Keys::TRACKING_URL,
			'service_code'           => WLP_Meta_Keys::SERVICE_CODE,
			'service_name'           => WLP_Meta_Keys::SERVICE_NAME,
			'shipping_cost'          => WLP_Meta_Keys::SHIPPING_COST,
			'shipping_currency'      => WLP_Meta_Keys::SHIPPING_CURRENCY,
			'shipment_id'            => WLP_Meta_Keys::SHIPMENT_ID,
			'expected_delivery_date' => WLP_Meta_Keys::EXPECTED_DELIVERY_DATE,
			'preset_id'              => WLP_Meta_Keys::PRESET_ID,
			'shipment_weight_kg'     => WLP_Meta_Keys::SHIPMENT_WEIGHT_KG,
			'shipped_at'             => WLP_Meta_Keys::SHIPPED_AT,
		);

		foreach ( $map as $source => $meta_key ) {
			if ( ! array_key_exists( $source, $metadata ) || null === $metadata[ $source ] ) {
				continue;
			}

			$order->update_meta_data( $meta_key, wc_clean( (string) $metadata[ $source ] ) );
		}

		$order->save();
	}

	/**
	 * Returns true when an order has a purchased label.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public static function has_label( WC_Order $order ): bool {
		$meta = self::read( $order );

		return '' !== $meta['tracking_number'] && '' !== $meta['label_artifact_url'];
	}

	/**
	 * Reads a string meta value, falling back to legacy Medusa keys for display.
	 *
	 * @param WC_Order    $order WooCommerce order.
	 * @param string      $key Primary meta key.
	 * @param string|null $legacy_slug Legacy Medusa slug.
	 */
	private static function string_meta( WC_Order $order, string $key, ?string $legacy_slug = null ): string {
		$value = $order->get_meta( $key, true );
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			return trim( (string) $value );
		}

		if ( $legacy_slug && isset( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $legacy_slug ] ) ) {
			$legacy = $order->get_meta( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $legacy_slug ], true );
			if ( is_scalar( $legacy ) ) {
				return trim( (string) $legacy );
			}
		}

		return '';
	}
}
