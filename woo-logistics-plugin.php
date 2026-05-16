<?php
/**
 * Plugin Name: Woo Logistics Plugin
 * Description: Adds a WooCommerce logistics desk for Canada Post label creation, printing, and tracking metadata.
 * Version: 0.1.1
 * Author: North End Tech
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: woo-logistics-plugin
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WLP_VERSION', '0.1.1' );
define( 'WLP_FILE', __FILE__ );
define( 'WLP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WLP_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( WLP_PATH . 'vendor/autoload.php' ) ) {
	require_once WLP_PATH . 'vendor/autoload.php';
}

require_once WLP_PATH . 'includes/class-wlp-meta-keys.php';
require_once WLP_PATH . 'includes/class-wlp-settings.php';
require_once WLP_PATH . 'includes/class-wlp-order-logistics.php';
require_once WLP_PATH . 'includes/class-wlp-label-normalizer.php';
require_once WLP_PATH . 'includes/class-wlp-canada-post-client.php';
require_once WLP_PATH . 'includes/class-wlp-admin.php';
require_once WLP_PATH . 'includes/class-wlp-plugin.php';

register_activation_hook(
	WLP_FILE,
	static function (): void {
		add_option( WLP_Settings::OPTION_SANDBOX, 'yes' );
		add_option( WLP_Settings::OPTION_NOTIFY, 'yes' );
		add_option( WLP_Settings::OPTION_PRESETS, WLP_Settings::default_presets() );
		add_option( WLP_Settings::OPTION_STATUSES, array( 'processing' ) );
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WLP_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Woo Logistics Plugin requires WooCommerce to be active.', 'woo-logistics-plugin' );
					echo '</p></div>';
				}
			);

			return;
		}

		WLP_Plugin::instance()->boot();
	}
);
