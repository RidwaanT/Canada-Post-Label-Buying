<?php
/**
 * Purpose: Cleans plugin-owned options on uninstall when explicitly requested.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( 'yes' !== get_option( 'wlp_delete_data_on_uninstall', 'no' ) ) {
	return;
}

$options = array(
	'wlp_delete_data_on_uninstall',
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
	'wlp_default_package_preset',
	'wlp_hide_regular_parcel',
	'wlp_external_logistics_meta_mirror',
	'wlp_label_customer_note',
	'wlp_label_customer_note_template',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
