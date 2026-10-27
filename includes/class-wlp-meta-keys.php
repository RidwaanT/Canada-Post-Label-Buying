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
	public const PACKAGING_PRESET_ID    = '_wlp_packaging_preset_id';
	public const PACKAGING_PRESET_NAME  = '_wlp_packaging_preset_name';
	public const EXPECTED_DELIVERY_DATE = '_wlp_expected_delivery_date';
	public const LAST_POLLED_AT         = '_wlp_last_polled_at';
	public const SHIPPED_AT             = '_wlp_shipped_at';
	public const DELIVERED_AT           = '_wlp_delivered_at';
	public const PRESET_ID              = '_wlp_preset_id';
	public const SHIPMENT_WEIGHT_KG     = '_wlp_shipment_weight_kg';
	public const XP_UPGRADE_DAYS_SAVED  = '_wlp_xpresspost_upgrade_days_saved';
	public const CUSTOMER_NOTE_TRACKING = '_wlp_customer_note_tracking_number';
	public const INVOICE_PRINTED_AT     = '_wlp_invoice_printed_at';
	public const PACKED_AT              = '_wlp_packed_at';
	public const IS_WHOLESALE           = '_wlp_is_wholesale';
	public const WHOLESALE_MARKED_AT    = '_wlp_wholesale_marked_at';
	public const RETURN_LABEL_CREATED_AT = '_wlp_return_label_created_at';
	public const RETURN_LABEL_ARTIFACT_URL = '_wlp_return_label_artifact_url';
	public const RETURN_TRACKING_NUMBER = '_wlp_return_tracking_number';
	public const RETURN_TRACKING_URL    = '_wlp_return_tracking_url';
	public const RETURN_SERVICE_CODE    = '_wlp_return_service_code';
	public const RETURN_SERVICE_NAME    = '_wlp_return_service_name';
	public const RETURN_SHIPPING_COST   = '_wlp_return_shipping_cost';
	public const RETURN_SHIPMENT_ID     = '_wlp_return_shipment_id';
	public const RETURN_EXPECTED_DELIVERY_DATE = '_wlp_return_expected_delivery_date';
	public const RETURN_CUSTOMER_NOTE_TRACKING = '_wlp_return_customer_note_tracking_number';
	public const RETURN_LABEL_ACCESS_TOKEN = '_wlp_return_label_access_token';
	public const REORDER_FOLLOWUP_SENT_AT = '_wlp_reorder_followup_sent_at';
	public const PROFIT_ACTUAL_REVENUE    = '_wlp_profit_actual_revenue';
	public const PROFIT_SUPPLIER_COST     = '_wlp_profit_supplier_cost';
	public const PROFIT_SUPPLIER_COST_MODE = '_wlp_profit_supplier_cost_mode';
	public const PROFIT_SHIPPING_COST     = '_wlp_profit_shipping_cost';
	public const PROFIT_PAYMENT_FEES      = '_wlp_profit_payment_fees';
	public const PROFIT_TAX_COST          = '_wlp_profit_tax_cost';
	public const PROFIT_OTHER_COSTS       = '_wlp_profit_other_costs';
	public const PROFIT_NOTES             = '_wlp_profit_notes';
	public const PROFIT_UPDATED_AT        = '_wlp_profit_updated_at';

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
		'last_polled_at'         => '_medusa_logistics_last_polled_at',
		'shipped_at'             => '_medusa_logistics_shipped_at',
		'delivered_at'           => '_medusa_logistics_delivered_at',
	);
}
