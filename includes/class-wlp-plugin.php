<?php
/**
 * Purpose: Wires Woo Logistics Plugin services into WordPress hooks.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin coordinator.
 */
final class WLP_Plugin {
	private static ?self $instance = null;

	private WLP_Settings $settings;

	private WLP_Admin $admin;

	/**
	 * Returns the singleton plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers WordPress hooks for the plugin.
	 */
	public function boot(): void {
		$this->settings = new WLP_Settings();
		$this->admin    = new WLP_Admin();

		$this->settings->register();
		$this->admin->register();
	}

	/**
	 * Prevents external construction.
	 */
	private function __construct() {
	}
}
