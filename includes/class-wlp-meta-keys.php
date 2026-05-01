<?php
/**
 * Purpose: Defines Woo order meta keys used by Woo Logistics Plugin.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical order meta keys for label state.
 */
final class WLP_Meta_Keys {
	public const LABEL_CREATED_AT       = '_wlp_label_created_at';
	public const LABEL_ARTIFACT_URL     = '_wlp_label_artifact_url';
	public const TRACKING_NUMBER        = '_wlp_tracking_number';
	public const TRACKING_URL           = '_wlp_tracking_url';
	public const SERVICE_CODE           = '_wlp_service_code';
	public const SERVICE_NAME           = '_wlp_service_name';
	public const SHIPPING_COST          = '_wlp_shipping_cost';
	public const SHIPPING_CURRENCY      = '_wlp_shipping_currency';
	public const SHIPMENT_ID            = '_wlp_shipment_id';
	public const EXPECTED_DELIVERY_DATE = '_wlp_expected_delivery_date';
	public const SHIPPED_AT             = '_wlp_shipped_at';
	public const DELIVERED_AT           = '_wlp_delivered_at';
	public const PRESET_ID              = '_wlp_preset_id';

	public const LEGACY_MEDUSA_KEYS = array(
		'label_created_at'       => '_medusa_logistics_label_created_at',
		'label_artifact_url'     => '_medusa_logistics_label_artifact_url',
		'tracking_number'        => '_medusa_logistics_tracking_number',
		'tracking_url'           => '_medusa_logistics_tracking_url',
		'service_code'           => '_medusa_logistics_service_code',
		'service_name'           => '_medusa_logistics_service_name',
		'shipping_cost'          => '_medusa_logistics_shipping_cost',
		'shipping_currency'      => '_medusa_logistics_shipping_currency',
		'shipment_id'            => '_medusa_logistics_shipment_id',
		'expected_delivery_date' => '_medusa_logistics_expected_delivery_date',
		'shipped_at'             => '_medusa_logistics_shipped_at',
		'delivered_at'           => '_medusa_logistics_delivered_at',
	);
}
