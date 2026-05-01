<?php
/**
 * Purpose: Renders WooCommerce logistics admin screens and handles label actions.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce logistics admin page.
 */
final class WLP_Admin {
	private WLP_Canada_Post_Client $client;

	/**
	 * Creates the admin coordinator.
	 */
	public function __construct() {
		$this->client = new WLP_Canada_Post_Client();
	}

	/**
	 * Registers admin hooks and actions.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wlp_get_rates', array( $this, 'ajax_get_rates' ) );
		add_action( 'wp_ajax_wlp_create_label', array( $this, 'ajax_create_label' ) );
		add_action( 'wp_ajax_wlp_quick_buy_label', array( $this, 'ajax_quick_buy_label' ) );
		add_action( 'admin_post_wlp_print_label', array( $this, 'print_label' ) );
		add_action( 'admin_post_wlp_bulk_print_labels', array( $this, 'bulk_print_labels' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_order_box' ) );
	}

	/**
	 * Adds the logistics menu under WooCommerce.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Logistics', 'woo-logistics-plugin' ),
			__( 'Logistics', 'woo-logistics-plugin' ),
			'manage_woocommerce',
			'wlp-logistics',
			array( $this, 'render_logistics_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Logistics Settings', 'woo-logistics-plugin' ),
			__( 'Logistics Settings', 'woo-logistics-plugin' ),
			'manage_woocommerce',
			'wlp-logistics-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueues logistics admin assets.
	 */
	public function enqueue_assets( string $hook ): void {
		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_order_screen = $screen && in_array( $screen->id, $this->order_screen_ids(), true );

		if ( ! str_contains( $hook, 'wlp-logistics' ) && ! $is_order_screen ) {
			return;
		}

		wp_enqueue_style( 'wlp-admin', WLP_URL . 'assets/css/admin.css', array(), WLP_VERSION );
		wp_enqueue_script( 'wlp-admin', WLP_URL . 'assets/js/admin.js', array(), WLP_VERSION, true );
		wp_localize_script(
			'wlp-admin',
			'wlpAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wlp_logistics' ),
				'settingsUrl' => admin_url( 'admin.php?page=wlp-logistics-settings' ),
				'bulkPrintUrl' => admin_url( 'admin-post.php?action=wlp_bulk_print_labels' ),
				'bulkPrintNonce' => wp_create_nonce( 'wlp_bulk_print_labels' ),
				'i18n'        => array(
					'loadingRates' => __( 'Loading Canada Post rates...', 'woo-logistics-plugin' ),
					'buying'       => __( 'Buying...', 'woo-logistics-plugin' ),
					'buyLabel'     => __( 'Buy label', 'woo-logistics-plugin' ),
					'failedRates'  => __( 'Failed to load rates.', 'woo-logistics-plugin' ),
					'failedLabel'  => __( 'Failed to create label.', 'woo-logistics-plugin' ),
					'reprint'      => __( 'Reprint existing label', 'woo-logistics-plugin' ),
					'buyOverride'  => __( 'Buy replacement label', 'woo-logistics-plugin' ),
					'tracking'     => __( 'Tracking', 'woo-logistics-plugin' ),
					'printLabel'   => __( 'Print label', 'woo-logistics-plugin' ),
					'confirm'      => __( 'This order already has a label. Buy a replacement label anyway?', 'woo-logistics-plugin' ),
					'quickBuying'  => __( 'Buying quick labels...', 'woo-logistics-plugin' ),
					'quickDone'    => __( 'Quick buy complete.', 'woo-logistics-plugin' ),
					'selectOrders' => __( 'Select at least one order.', 'woo-logistics-plugin' ),
					'popupNotice'  => __( 'Allow popups for this site to bulk print labels.', 'woo-logistics-plugin' ),
				),
			)
		);
	}

	/**
	 * Renders the logistics desk.
	 */
	public function render_logistics_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage logistics.', 'woo-logistics-plugin' ) );
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 50,
				'status'  => WLP_Settings::eligible_statuses(),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		?>
		<div class="wrap wlp-wrap">
			<div class="wlp-header">
				<div>
					<h1><?php echo esc_html__( 'Logistics', 'woo-logistics-plugin' ); ?></h1>
					<p><?php echo esc_html__( 'Create Canada Post labels, print labels, and keep tracking metadata on WooCommerce orders.', 'woo-logistics-plugin' ); ?></p>
				</div>
				<div class="wlp-header__actions">
					<div class="wlp-selection-tools" aria-label="<?php echo esc_attr__( 'Order selection tools', 'woo-logistics-plugin' ); ?>">
						<button class="button" type="button" data-wlp-select-all><?php echo esc_html__( 'Select all', 'woo-logistics-plugin' ); ?></button>
						<button class="button" type="button" data-wlp-select-labeled><?php echo esc_html__( 'Select labeled', 'woo-logistics-plugin' ); ?></button>
						<button class="button" type="button" data-wlp-clear-selection><?php echo esc_html__( 'Clear', 'woo-logistics-plugin' ); ?></button>
						<span class="wlp-selection-count" data-wlp-selection-count><?php echo esc_html__( '0 selected', 'woo-logistics-plugin' ); ?></span>
					</div>
					<button class="button button-primary" type="button" data-wlp-quick-buy-selected><?php echo esc_html__( 'Quick buy selected', 'woo-logistics-plugin' ); ?></button>
					<button class="button" type="button" data-wlp-bulk-print-selected><?php echo esc_html__( 'Bulk print selected', 'woo-logistics-plugin' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wlp-logistics-settings' ) ); ?>"><?php echo esc_html__( 'Settings', 'woo-logistics-plugin' ); ?></a>
				</div>
			</div>
			<div class="wlp-bulk-status" data-wlp-bulk-status aria-live="polite"></div>
			<div class="wlp-board">
				<?php foreach ( $orders as $order ) : ?>
					<?php if ( $order instanceof WC_Order ) : ?>
						<?php $this->render_order_card( $order ); ?>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php $this->render_drawer(); ?>
		<?php
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage logistics settings.', 'woo-logistics-plugin' ) );
		}

		?>
		<div class="wrap wlp-wrap">
			<div class="wlp-header">
				<div>
					<h1><?php echo esc_html__( 'Woo Logistics Settings', 'woo-logistics-plugin' ); ?></h1>
					<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
						<div class="notice notice-success inline wlp-settings-notice">
							<p><?php echo esc_html__( 'Logistics settings saved.', 'woo-logistics-plugin' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
				<div class="wlp-header__actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wlp-logistics' ) ); ?>"><?php echo esc_html__( 'Back to Logistics', 'woo-logistics-plugin' ); ?></a>
				</div>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( 'wlp_settings' ); ?>
				<h2><?php echo esc_html__( 'Canada Post', 'woo-logistics-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->field_checkbox( WLP_Settings::OPTION_SANDBOX, __( 'Use Canada Post sandbox', 'woo-logistics-plugin' ), 'yes' ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_API_USER, __( 'API user', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_password( WLP_Settings::OPTION_API_PASSWORD, __( 'API password', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_CUSTOMER, __( 'Customer number', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_checkbox( WLP_Settings::OPTION_NOTIFY, __( 'Send Canada Post customer notifications', 'woo-logistics-plugin' ), 'yes' ); ?>
				</table>

				<h2><?php echo esc_html__( 'Origin', 'woo-logistics-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->field_text( WLP_Settings::OPTION_ORIGIN_NAME, __( 'Contact name', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_ORIGIN_COMPANY, __( 'Company', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_ORIGIN_EMAIL, __( 'Email', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_phone( WLP_Settings::OPTION_ORIGIN_PHONE, __( 'Phone', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_ORIGIN_ADDR_1, __( 'Address line 1', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_ORIGIN_ADDR_2, __( 'Address line 2', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_ORIGIN_CITY, __( 'City', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_province( WLP_Settings::OPTION_ORIGIN_PROV, __( 'Province', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_ORIGIN_POSTAL, __( 'Postal code', 'woo-logistics-plugin' ) ); ?>
				</table>

				<h2><?php echo esc_html__( 'Eligible Statuses', 'woo-logistics-plugin' ); ?></h2>
				<?php $this->render_status_fields(); ?>

				<h2><?php echo esc_html__( 'Package Presets', 'woo-logistics-plugin' ); ?></h2>
				<?php $this->render_preset_fields(); ?>

				<p class="submit wlp-settings-actions">
					<?php submit_button( __( 'Save Changes', 'woo-logistics-plugin' ), 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wlp-logistics' ) ); ?>"><?php echo esc_html__( 'Back to Logistics', 'woo-logistics-plugin' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Registers order detail boxes for HPOS and legacy screens.
	 */
	public function register_order_box(): void {
		foreach ( $this->order_screen_ids() as $screen_id ) {
			add_meta_box(
				'wlp-order-logistics',
				__( 'Logistics', 'woo-logistics-plugin' ),
				array( $this, 'render_order_meta_box' ),
				$screen_id,
				'side',
				'high'
			);
		}
	}

	/**
	 * Renders label metadata on the order detail page.
	 *
	 * @param WP_Post|WC_Order $object Current screen object.
	 */
	public function render_order_meta_box( $screen_object ): void {
		$order = $screen_object instanceof WC_Order ? $screen_object : wc_get_order( $screen_object->ID ?? 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$meta = WLP_Order_Logistics::read( $order );
		?>
		<p><strong><?php echo esc_html__( 'Tracking', 'woo-logistics-plugin' ); ?>:</strong> <?php echo esc_html( $meta['tracking_number'] ?: '-' ); ?></p>
		<p><strong><?php echo esc_html__( 'Service', 'woo-logistics-plugin' ); ?>:</strong> <?php echo esc_html( $meta['service_name'] ?: '-' ); ?></p>
		<?php if ( $meta['label_artifact_url'] ) : ?>
			<p><a class="button" target="_blank" href="<?php echo esc_url( $this->print_url( $order ) ); ?>"><?php echo esc_html__( 'Print label', 'woo-logistics-plugin' ); ?></a></p>
		<?php endif; ?>
		<p><button class="button" type="button" data-wlp-create-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html( WLP_Order_Logistics::has_label( $order ) ? __( 'View label options', 'woo-logistics-plugin' ) : __( 'Create label', 'woo-logistics-plugin' ) ); ?></button></p>
		<?php $this->render_drawer(); ?>
		<?php
	}

	/**
	 * AJAX: fetches rates for each preset.
	 */
	public function ajax_get_rates(): void {
		$order   = $this->ajax_order();
		$payload = array();

		foreach ( $this->client->get_presets() as $preset ) {
			try {
				$payload[] = array(
					'preset' => $preset,
					'rates'  => $this->client->get_rates( $order, $preset ),
				);
			} catch ( Throwable $error ) {
				$payload[] = array(
					'preset' => $preset,
					'rates'  => array(),
					'error'  => $error->getMessage(),
				);
			}
		}

		wp_send_json_success(
			array(
				'orderId'  => $order->get_id(),
				'hasLabel' => WLP_Order_Logistics::has_label( $order ),
				'printUrl' => $this->print_url( $order ),
				'services' => $this->client->get_services(),
				'presets'  => $payload,
			)
		);
	}

	/**
	 * AJAX: creates a Canada Post label for the selected order.
	 */
	public function ajax_create_label(): void {
		$order        = $this->ajax_order();
		$preset_id    = isset( $_POST['presetId'] ) ? sanitize_text_field( wp_unslash( $_POST['presetId'] ) ) : '';
		$service_code = isset( $_POST['serviceCode'] ) ? sanitize_text_field( wp_unslash( $_POST['serviceCode'] ) ) : '';
		$override     = isset( $_POST['override'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['override'] ) );

		if ( '' === $preset_id || '' === $service_code ) {
			wp_send_json_error( array( 'message' => __( 'Package preset and service are required.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( WLP_Order_Logistics::has_label( $order ) && ! $override ) {
			wp_send_json_error(
				array(
					'code'     => 'label_exists',
					'message'  => __( 'This order already has a label. Reprint it or explicitly buy a replacement.', 'woo-logistics-plugin' ),
					'printUrl' => $this->print_url( $order ),
				),
				409
			);
		}

		try {
			$preset        = $this->client->find_preset( $preset_id );
			$rates         = $this->client->get_rates( $order, $preset );
			$selected_rate = $this->find_rate( $rates, $service_code );
			$shipment      = $this->client->create_shipment( $order, $preset, $service_code );

			WLP_Order_Logistics::write_label(
				$order,
				array(
					'label_created_at'   => gmdate( 'c' ),
					'label_artifact_url' => $shipment['label_artifact_url'],
					'tracking_number'    => $shipment['tracking_number'],
					'tracking_url'       => $shipment['tracking_url'],
					'service_code'       => $service_code,
					'service_name'       => $this->client->service_name( $service_code ),
					'shipping_cost'      => $selected_rate['due'] ?? '',
					'shipping_currency'  => 'CAD',
					'shipment_id'        => $shipment['shipment_id'],
					'preset_id'          => $preset_id,
					'shipped_at'         => 'completed' === $order->get_status() ? gmdate( 'c' ) : null,
				)
			);

			wp_send_json_success(
				array(
					'shipment' => $shipment,
					'printUrl' => $this->print_url( $order ),
				)
			);
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 500 );
		}
	}

	/**
	 * AJAX: buys a label using the first preset and cheapest rate.
	 */
	public function ajax_quick_buy_label(): void {
		$order = $this->ajax_order();

		if ( WLP_Order_Logistics::has_label( $order ) ) {
			wp_send_json_success(
				array(
					'skipped'  => true,
					'message'  => __( 'Order already has a label.', 'woo-logistics-plugin' ),
					'printUrl' => $this->print_url( $order ),
				)
			);
		}

		try {
			$presets = $this->client->get_presets();
			$preset  = $presets[0] ?? null;

			if ( ! is_array( $preset ) ) {
				throw new RuntimeException( __( 'No package presets are configured.', 'woo-logistics-plugin' ) );
			}

			$rates = $this->client->get_rates( $order, $preset );
			$rate  = $this->cheapest_rate( $rates );

			if ( ! $rate || empty( $rate['service_code'] ) ) {
				throw new RuntimeException( __( 'Canada Post returned no rates for quick buy.', 'woo-logistics-plugin' ) );
			}

			$service_code = $rate['service_code'];
			$shipment     = $this->client->create_shipment( $order, $preset, $service_code );

			WLP_Order_Logistics::write_label(
				$order,
				array(
					'label_created_at'   => gmdate( 'c' ),
					'label_artifact_url' => $shipment['label_artifact_url'],
					'tracking_number'    => $shipment['tracking_number'],
					'tracking_url'       => $shipment['tracking_url'],
					'service_code'       => $service_code,
					'service_name'       => $this->client->service_name( $service_code ),
					'shipping_cost'      => $rate['due'] ?? '',
					'shipping_currency'  => 'CAD',
					'shipment_id'        => $shipment['shipment_id'],
					'preset_id'          => (string) $preset['id'],
					'shipped_at'         => 'completed' === $order->get_status() ? gmdate( 'c' ) : null,
				)
			);

			wp_send_json_success(
				array(
					'shipment' => $shipment,
					'printUrl' => $this->print_url( $order ),
				)
			);
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 500 );
		}
	}

	/**
	 * Streams a stored label PDF from Canada Post for printing.
	 */
	public function print_label(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to print labels.', 'woo-logistics-plugin' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'wlp_print_label_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'woo-logistics-plugin' ) );
		}

		$meta = WLP_Order_Logistics::read( $order );
		if ( '' === $meta['label_artifact_url'] ) {
			wp_die( esc_html__( 'No printable label is stored for this order.', 'woo-logistics-plugin' ) );
		}

		try {
			$artifact = $this->client->download_artifact( $meta['label_artifact_url'] );
			nocache_headers();
			header( 'Content-Type: ' . ( $artifact['content_type'] ?: 'application/pdf' ) );
			header( 'Content-Disposition: inline; filename="canada-post-' . sanitize_file_name( $meta['tracking_number'] ?: (string) $order->get_id() ) . '.pdf"' );
			echo $artifact['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
	}

	/**
	 * Streams one merged PDF containing stored labels for selected orders.
	 */
	public function bulk_print_labels(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to print labels.', 'woo-logistics-plugin' ) );
		}

		$order_ids = $this->request_order_ids();
		if ( empty( $order_ids ) ) {
			wp_die( esc_html__( 'Select at least one labeled order to print.', 'woo-logistics-plugin' ) );
		}

		check_admin_referer( 'wlp_bulk_print_labels' );

		$labels = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$meta = WLP_Order_Logistics::read( $order );
			if ( '' === $meta['label_artifact_url'] ) {
				continue;
			}

			$artifact = $this->client->download_artifact( $meta['label_artifact_url'] );
			$labels[] = $artifact['body'];
		}

		if ( empty( $labels ) ) {
			wp_die( esc_html__( 'No printable labels were found for the selected orders.', 'woo-logistics-plugin' ) );
		}

		try {
			$pdf = WLP_Label_Normalizer::merge_pdfs( $labels );
			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: inline; filename="woo-logistics-labels-' . gmdate( 'Ymd-His' ) . '.pdf"' );
			echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
	}

	/**
	 * Renders one order card.
	 */
	private function render_order_card( WC_Order $order ): void {
		$meta      = WLP_Order_Logistics::read( $order );
		$has_label = WLP_Order_Logistics::has_label( $order );
		?>
		<section class="wlp-card" data-wlp-order-card data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-has-label="<?php echo esc_attr( $has_label ? 'yes' : 'no' ); ?>" data-print-url="<?php echo esc_url( $has_label ? $this->print_url( $order ) : '' ); ?>">
			<label class="wlp-select">
				<input type="checkbox" data-wlp-order-select value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
				<span><?php echo esc_html__( 'Select order', 'woo-logistics-plugin' ); ?></span>
			</label>
			<div class="wlp-card__top">
				<div>
					<h2>#<?php echo esc_html( $order->get_order_number() ); ?></h2>
					<p><?php echo esc_html( $this->recipient_line( $order ) ); ?></p>
				</div>
				<span class="wlp-pill <?php echo esc_attr( $has_label ? 'is-ready' : 'is-pending' ); ?>"><?php echo esc_html( $has_label ? __( 'Labeled', 'woo-logistics-plugin' ) : __( 'Needs label', 'woo-logistics-plugin' ) ); ?></span>
			</div>
			<dl class="wlp-facts">
				<div><dt><?php echo esc_html__( 'Status', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Service', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( $meta['service_name'] ?: '-' ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Tracking', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( $meta['tracking_number'] ?: '-' ); ?></dd></div>
			</dl>
			<div class="wlp-actions">
				<button class="button button-primary" type="button" data-wlp-create-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html( $has_label ? __( 'View label options', 'woo-logistics-plugin' ) : __( 'Create label', 'woo-logistics-plugin' ) ); ?></button>
				<?php if ( $has_label ) : ?>
					<a class="button" target="_blank" href="<?php echo esc_url( $this->print_url( $order ) ); ?>"><?php echo esc_html__( 'Print', 'woo-logistics-plugin' ); ?></a>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders the shared label drawer.
	 */
	private function render_drawer(): void {
		?>
		<div class="wlp-drawer" id="wlp-label-drawer" aria-hidden="true">
			<div class="wlp-drawer__panel">
				<button class="button-link wlp-drawer__close" type="button" data-wlp-close>&times;</button>
				<h2><?php echo esc_html__( 'Create Canada Post Label', 'woo-logistics-plugin' ); ?></h2>
				<div id="wlp-drawer-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a text setting row.
	 */
	private function field_text( string $key, string $label ): void {
		$value = (string) get_option( $key, '' );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" type="text"></td>
		</tr>
		<?php
	}

	/**
	 * Renders a phone setting row.
	 */
	private function field_phone( string $key, string $label ): void {
		$value  = (string) get_option( $key, '' );
		$digits = preg_replace( '/\D+/', '', $value ) ?? '';
		if ( 10 === strlen( $digits ) ) {
			$value = substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
		}
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" type="tel" inputmode="tel" pattern="[0-9]{3}-?[0-9]{3}-?[0-9]{4}" placeholder="222-222-2222" autocomplete="tel-national">
				<p class="description"><?php echo esc_html__( 'Use a 10-digit Canadian phone number. A leading +1 is stripped before sending to Canada Post.', 'woo-logistics-plugin' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders a province selector.
	 */
	private function field_province( string $key, string $label ): void {
		$value = (string) get_option( $key, '' );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
					<option value=""><?php echo esc_html__( 'Select province or territory', 'woo-logistics-plugin' ); ?></option>
					<?php foreach ( WLP_Settings::province_options() as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>><?php echo esc_html( $code . ' - ' . $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Canada Post expects the two-letter province or territory code.', 'woo-logistics-plugin' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders a password setting row.
	 */
	private function field_password( string $key, string $label ): void {
		$value = (string) get_option( $key, '' );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" type="password" autocomplete="new-password"></td>
		</tr>
		<?php
	}

	/**
	 * Renders a checkbox setting row.
	 */
	private function field_checkbox( string $key, string $label, string $default_value = 'no' ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td><input id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="yes" type="checkbox" <?php checked( get_option( $key, $default_value ), 'yes' ); ?>></td>
		</tr>
		<?php
	}

	/**
	 * Renders status checkboxes.
	 */
	private function render_status_fields(): void {
		$selected = WLP_Settings::eligible_statuses();
		?>
		<div class="wlp-settings-box">
			<?php foreach ( wc_get_order_statuses() as $status => $label ) : ?>
				<?php $slug = preg_replace( '/^wc-/', '', $status ); ?>
				<label class="wlp-check">
					<input type="checkbox" name="<?php echo esc_attr( WLP_Settings::OPTION_STATUSES ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders package preset rows.
	 */
	private function render_preset_fields(): void {
		$presets = WLP_Settings::presets();
		?>
		<table class="widefat striped wlp-presets">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'ID', 'woo-logistics-plugin' ); ?></th>
					<th><?php echo esc_html__( 'Name', 'woo-logistics-plugin' ); ?></th>
					<th><?php echo esc_html__( 'Weight kg', 'woo-logistics-plugin' ); ?></th>
					<th><?php echo esc_html__( 'Length cm', 'woo-logistics-plugin' ); ?></th>
					<th><?php echo esc_html__( 'Width cm', 'woo-logistics-plugin' ); ?></th>
					<th><?php echo esc_html__( 'Height cm', 'woo-logistics-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $presets as $index => $preset ) : ?>
					<tr>
						<td><input name="<?php echo esc_attr( WLP_Settings::OPTION_PRESETS ); ?>[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( (string) $preset['id'] ); ?>" type="text"></td>
						<td><input name="<?php echo esc_attr( WLP_Settings::OPTION_PRESETS ); ?>[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( (string) $preset['name'] ); ?>" type="text"></td>
						<td><input name="<?php echo esc_attr( WLP_Settings::OPTION_PRESETS ); ?>[<?php echo esc_attr( (string) $index ); ?>][weight]" value="<?php echo esc_attr( (string) $preset['weight'] ); ?>" type="number" step="0.01" min="0.01"></td>
						<td><input name="<?php echo esc_attr( WLP_Settings::OPTION_PRESETS ); ?>[<?php echo esc_attr( (string) $index ); ?>][length]" value="<?php echo esc_attr( (string) $preset['length'] ); ?>" type="number" step="0.01" min="0.01"></td>
						<td><input name="<?php echo esc_attr( WLP_Settings::OPTION_PRESETS ); ?>[<?php echo esc_attr( (string) $index ); ?>][width]" value="<?php echo esc_attr( (string) $preset['width'] ); ?>" type="number" step="0.01" min="0.01"></td>
						<td><input name="<?php echo esc_attr( WLP_Settings::OPTION_PRESETS ); ?>[<?php echo esc_attr( (string) $index ); ?>][height]" value="<?php echo esc_attr( (string) $preset['height'] ); ?>" type="number" step="0.01" min="0.01"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Resolves and authorizes an AJAX order.
	 */
	private function ajax_order(): WC_Order {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woo-logistics-plugin' ) ), 403 );
		}

		check_ajax_referer( 'wlp_logistics', 'nonce' );
		$order_id = isset( $_POST['orderId'] ) ? absint( $_POST['orderId'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'woo-logistics-plugin' ) ), 404 );
		}

		return $order;
	}

	/**
	 * Finds a selected rate record.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 * @return array<string, string>
	 */
	private function find_rate( array $rates, string $service_code ): array {
		foreach ( $rates as $rate ) {
			if ( ( $rate['service_code'] ?? '' ) === $service_code ) {
				return $rate;
			}
		}

		return array();
	}

	/**
	 * Returns the cheapest numeric rate.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 * @return array<string, string>
	 */
	private function cheapest_rate( array $rates ): array {
		$cheapest = array();
		$amount   = null;

		foreach ( $rates as $rate ) {
			if ( empty( $rate['service_code'] ) || ! is_numeric( $rate['due'] ?? null ) ) {
				continue;
			}

			$rate_amount = (float) $rate['due'];
			if ( null === $amount || $rate_amount < $amount ) {
				$amount   = $rate_amount;
				$cheapest = $rate;
			}
		}

		return $cheapest;
	}

	/**
	 * Builds the printable label URL.
	 */
	private function print_url( WC_Order $order ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wlp_print_label&order_id=' . $order->get_id() ),
			'wlp_print_label_' . $order->get_id()
		);
	}

	/**
	 * Builds the printable bulk label URL.
	 *
	 * @param array<int, int> $order_ids Order IDs.
	 */
	private function bulk_print_url( array $order_ids ): string {
		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		sort( $order_ids, SORT_NUMERIC );

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wlp_bulk_print_labels&order_ids=' . rawurlencode( implode( ',', $order_ids ) ) ),
			'wlp_bulk_print_labels'
		);
	}

	/**
	 * Reads order IDs from a request.
	 *
	 * @return array<int, int>
	 */
	private function request_order_ids(): array {
		$value = isset( $_GET['order_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) : '';
		$ids   = array_map( 'absint', explode( ',', $value ) );
		$ids   = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Builds a compact recipient line.
	 */
	private function recipient_line( WC_Order $order ): string {
		$parts = array_filter(
			array(
				trim( (string) $order->get_formatted_shipping_full_name() ),
				trim( (string) $order->get_shipping_city() ),
				trim( (string) $order->get_shipping_state() ),
				trim( (string) $order->get_shipping_postcode() ),
			)
		);

		return implode( ' - ', $parts );
	}

	/**
	 * Returns possible order screen ids for HPOS and legacy screens.
	 *
	 * @return array<int, string>
	 */
	private function order_screen_ids(): array {
		$ids = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
