<?php
/**
 * Purpose: Cleans plugin-owned options on uninstall.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'wlp_cp_sandbox',
	'wlp_cp_api_user',
	'wlp_cp_api_password',
	'wlp_cp_customer_number',
	'wlp_cp_origin_name',
	'wlp_cp_origin_company',
	'wlp_cp_origin_email',
	'wlp_cp_origin_phone',
	'wlp_cp_origin_address_1',
	'wlp_cp_origin_address_2',
	'wlp_cp_origin_city',
	'wlp_cp_origin_province',
	'wlp_cp_origin_postal_code',
	'wlp_cp_customer_notifications',
	'wlp_cp_signature_required',
	'wlp_package_presets',
	'wlp_eligible_statuses',
	'wlp_calculate_product_weight',
	'wlp_base_package_weight_kg',
	'wlp_use_base_package_weight',
	'wlp_default_service_code',
	'wlp_hide_regular_parcel',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
