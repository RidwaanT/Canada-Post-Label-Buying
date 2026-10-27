<?php
/**
 * Plugin Name: Woo Logistics Plugin
 * Description: Adds a WooCommerce logistics desk for Canada Post label creation, printing, and tracking metadata.
 * Version: 0.1.236
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

if ( ! defined( 'WLP_VERSION' ) ) {
	define( 'WLP_VERSION', '0.1.236' );
}

if ( ! defined( 'WLP_FILE' ) ) {
	define( 'WLP_FILE', __FILE__ );
}

if ( ! defined( 'WLP_PATH' ) ) {
	define( 'WLP_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WLP_URL' ) ) {
	define( 'WLP_URL', plugin_dir_url( __FILE__ ) );
}

$GLOBALS['wlp_bootstrap_failed'] = false;
$GLOBALS['wlp_bootstrapping']    = false;

if ( ! function_exists( 'wlp_load_plugin_api' ) ) {
	/**
	 * Loads WordPress plugin management functions when available.
	 */
	function wlp_load_plugin_api(): void {
		if ( function_exists( 'deactivate_plugins' ) ) {
			return;
		}

		$plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
		if ( file_exists( $plugin_api ) ) {
			require_once $plugin_api;
		}
	}
}

if ( ! function_exists( 'wlp_disable_self' ) ) {
	/**
	 * Disables this plugin after a startup failure.
	 */
	function wlp_disable_self(): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( 'wlp_bootstrap_disabled', 'yes', false );
		}

		wlp_load_plugin_api();

		if ( function_exists( 'deactivate_plugins' ) && function_exists( 'plugin_basename' ) ) {
			deactivate_plugins( plugin_basename( WLP_FILE ), true );
		}
	}
}

if ( ! function_exists( 'wlp_record_bootstrap_failure' ) ) {
	/**
	 * Records a startup failure and disables the plugin when WordPress can do it safely.
	 */
	function wlp_record_bootstrap_failure( Throwable $error ): void {
		$GLOBALS['wlp_bootstrap_failed'] = true;

		error_log( 'Woo Logistics Plugin bootstrap failed: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		wlp_disable_self();

		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'add_action' ) ) {
			add_action(
				'admin_notices',
				static function () use ( $error ): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
							/* translators: %s: startup error message. */
							__( 'Woo Logistics Plugin disabled itself after a startup error: %s', 'woo-logistics-plugin' ),
							$error->getMessage()
						)
					);
					echo '</p></div>';
				}
			);
		}
	}
}

if ( ! function_exists( 'wlp_shutdown_guard' ) ) {
	/**
	 * Prevents a startup fatal from keeping the whole WordPress site down.
	 */
	function wlp_shutdown_guard(): void {
		if ( empty( $GLOBALS['wlp_bootstrapping'] ) || ! empty( $GLOBALS['wlp_bootstrap_failed'] ) ) {
			return;
		}

		$error = error_get_last();
		if ( ! is_array( $error ) ) {
			return;
		}

		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( ! in_array( $error['type'] ?? 0, $fatal_types, true ) ) {
			return;
		}

		wlp_disable_self();
	}
}

register_shutdown_function( 'wlp_shutdown_guard' );

// Background verification runs independently of the admin bootstrap.
require_once __DIR__ . '/includes/class-wlp-manifest-verifier.php';
add_action('plugins_loaded', static function(): void {
 if (class_exists('WooCommerce') && 'yes' !== get_option('wlp_bootstrap_disabled', 'no')) { WLP_Manifest_Verifier::register(); }
});
register_deactivation_hook(__FILE__, static function(): void {
 if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('wlp_verify_manifests', array(), 'wlp-manifests'); }
});

// Customer portal hooks stay separate from the admin-only Logistics bootstrap.
add_action( 'plugins_loaded', static function(): void {
	if ( ! class_exists( 'WooCommerce' ) || 'yes' === get_option( 'wlp_bootstrap_disabled', 'no' ) ) { return; }
	require_once __DIR__ . '/includes/class-wlp-customer-portal.php';
	WLP_Customer_Portal::register();
	require_once __DIR__ . '/includes/class-wlp-retail-tracking.php';
	WLP_Retail_Tracking::register();
	require_once __DIR__ . '/includes/class-wlp-wholesale-reviews.php';
	WLP_Wholesale_Reviews::register();
}, 11 );

if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Woo Logistics Plugin requires PHP 8.1 or newer and has not loaded.', 'woo-logistics-plugin' );
			echo '</p></div>';
		}
	);

	wlp_disable_self();
	return;
}

if ( ! function_exists( 'wlp_load_class_files' ) ) {
	/**
	 * Loads plugin classes after WordPress has finished activating the plugin.
	 */
	function wlp_load_class_files(): void {
		$stock_file = WLP_PATH . 'includes/class-wlp-supplier-stock.php';
		if ( is_admin() && is_readable( $stock_file ) && ! class_exists( 'WLP_Supplier_Stock', false ) ) {
			require_once $stock_file;
			WLP_Supplier_Stock::register();
		}
		$wlp_class_files = array(
			'WLP_Meta_Keys'          => 'includes/class-wlp-meta-keys.php',
			'WLP_Supplier_Batches'   => 'includes/class-wlp-supplier-batches.php',
			'WLP_Split_Shipments'    => 'includes/class-wlp-split-shipments.php',
			'WLP_Settings'           => 'includes/class-wlp-settings.php',
			'WLP_Meta_CAPI'          => 'includes/class-wlp-meta-capi.php',
			'WLP_Order_Logistics'    => 'includes/class-wlp-order-logistics.php',
			'WLP_Label_Normalizer'   => 'includes/class-wlp-label-normalizer.php',
			'WLP_Canada_Post_Client' => 'includes/class-wlp-canada-post-client.php',
			'WLP_Admin'              => 'includes/class-wlp-admin.php',
			'WLP_Wholesale_Ops_API'  => 'includes/class-wlp-wholesale-ops-api.php',
			'WLP_Plugin'             => 'includes/class-wlp-plugin.php',
		);

		foreach ( $wlp_class_files as $wlp_class => $wlp_file ) {
			if ( ! class_exists( $wlp_class, false ) ) {
				$wlp_path = WLP_PATH . $wlp_file;
				if ( ! is_readable( $wlp_path ) ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %s: plugin include path. */
							esc_html__( 'Required plugin file is missing or unreadable: %s', 'woo-logistics-plugin' ),
							esc_html( $wlp_file )
						)
					);
				}

				require_once $wlp_path;
			}
		}
	}
}

// Register on every request: Action Scheduler's async runner is not a wlp_* AJAX action.
add_action( 'wlp_manifest_quick_worker', static function ( string $id, int $attempt = 0 ): void {
	if ( ! class_exists( 'WooCommerce' ) ) { return; }
	wlp_load_class_files();
	require_once WLP_PATH . 'includes/class-wlp-contract-shipping.php';
	WLP_Contract_Shipping::quick_worker( $id, $attempt );
}, 10, 2 );

if ( ! function_exists( 'wlp_should_boot_admin' ) ) {
	/**
	 * Returns true only for admin surfaces owned by this plugin.
	 */
	function wlp_should_boot_admin(): bool {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		if ( function_exists( 'is_admin' ) && ! is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) && ! str_starts_with( $action, 'wlp_' ) ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return str_starts_with( $action, 'wlp_' );
		}

		if ( '' !== $action && str_starts_with( $action, 'wlp_' ) ) {
			return true;
		}

		return function_exists( 'is_admin' ) && is_admin();
	}
}

if ( ! function_exists( 'wlp_activate' ) ) {
	/**
	 * Add default settings when the plugin is activated.
	 */
	function wlp_activate(): void {
		wlp_load_class_files();

		add_option( WLP_Settings::OPTION_SANDBOX, 'yes' );
		add_option( WLP_Settings::OPTION_NOTIFY, 'yes' );
		delete_option( 'wlp_bootstrap_disabled' );
		add_option( WLP_Settings::OPTION_PRESETS, WLP_Settings::default_presets() );
		add_option( WLP_Settings::OPTION_STATUSES, array( 'processing' ) );
		add_option( WLP_Settings::OPTION_EXTERNAL_META, 'no' );
		add_option( WLP_Settings::OPTION_CUSTOMER_NOTE, 'yes' );
		add_option( WLP_Settings::OPTION_NOTE_TEMPLATE, WLP_Settings::default_customer_label_note_template() );

		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && false === wp_next_scheduled( 'wlp_reorder_followup_cron' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wlp_reorder_followup_cron' );
		}
	}
}

register_activation_hook( WLP_FILE, 'wlp_activate' );

if ( 'yes' === get_option( 'wlp_bootstrap_disabled', 'no' ) ) {
	return;
}

// Order transitions can originate from REST, checkout, cron or the admin.
add_action( 'plugins_loaded', static function (): void {
	if ( class_exists( 'WooCommerce' ) ) {
		require_once WLP_PATH . 'includes/class-wlp-wholesale-purchase.php';
		WLP_Wholesale_Purchase::boot();
	}
}, 5 );

add_action(
	'rest_api_init',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		try {
			wlp_load_class_files();
			$admin = new WLP_Admin();
			if ( ! wlp_should_boot_admin() ) {
				$admin->register_processing_email_hooks();
			}
			( new WLP_Wholesale_Ops_API( $admin ) )->register_routes();
		} catch ( Throwable $error ) {
			error_log( 'Woo Logistics wholesale operations API failed to register: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
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

		if ( ! wlp_should_boot_admin() ) {
			return;
		}

		$GLOBALS['wlp_bootstrapping'] = true;

		try {
			wlp_load_class_files();

			if ( class_exists( 'WLP_Plugin', false ) ) {
				WLP_Plugin::instance()->boot();
			}
		} catch ( Throwable $error ) {
			wlp_record_bootstrap_failure( $error );
		} finally {
			$GLOBALS['wlp_bootstrapping'] = false;
		}
	}
);
