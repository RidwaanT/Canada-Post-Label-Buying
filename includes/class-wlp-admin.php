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
	private const DUMMY_PRODUCT_WEIGHT_GRAMS = 10.0;

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
		add_action( 'wp_ajax_wlp_send_customer_note', array( $this, 'ajax_send_customer_note' ) );
		add_action( 'wp_ajax_wlp_create_dummy_order', array( $this, 'ajax_create_dummy_order' ) );
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
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'wlp_logistics' ),
				'settingsUrl'       => admin_url( 'admin.php?page=wlp-logistics&view=settings' ),
				'bulkPrintUrl'      => admin_url( 'admin-post.php?action=wlp_bulk_print_labels' ),
				'bulkPrintNonce'    => wp_create_nonce( 'wlp_bulk_print_labels' ),
				'i18n'              => array(
					'loadingRates'     => __( 'Loading Canada Post rates...', 'woo-logistics-plugin' ),
					'buying'           => __( 'Buying...', 'woo-logistics-plugin' ),
					'buyLabel'         => __( 'Buy label', 'woo-logistics-plugin' ),
					'failedRates'      => __( 'Failed to load rates.', 'woo-logistics-plugin' ),
					'failedLabel'      => __( 'Failed to create label.', 'woo-logistics-plugin' ),
					'reprint'          => __( 'Reprint existing label', 'woo-logistics-plugin' ),
					'buyOverride'      => __( 'Buy replacement label', 'woo-logistics-plugin' ),
					'tracking'         => __( 'Tracking', 'woo-logistics-plugin' ),
					'printLabel'       => __( 'Print label', 'woo-logistics-plugin' ),
					'print'            => __( 'Print', 'woo-logistics-plugin' ),
					'sendNote'         => __( 'Send customer note', 'woo-logistics-plugin' ),
					'sendingNote'      => __( 'Sending note...', 'woo-logistics-plugin' ),
					'sentNote'         => __( 'Customer note sent.', 'woo-logistics-plugin' ),
					'failedNote'       => __( 'Failed to send customer note.', 'woo-logistics-plugin' ),
					'viewOptions'      => __( 'View options', 'woo-logistics-plugin' ),
					'confirm'          => __( 'This order already has a label. Buy a replacement label anyway?', 'woo-logistics-plugin' ),
					'quickBuying'      => __( 'Buying quick labels...', 'woo-logistics-plugin' ),
					'quickDone'        => __( 'Quick buy complete.', 'woo-logistics-plugin' ),
					'selectOrders'     => __( 'Select at least one order.', 'woo-logistics-plugin' ),
					'popupNotice'      => __( 'Allow popups for this site to bulk print labels.', 'woo-logistics-plugin' ),
					'creatingDummy'    => __( 'Creating test order...', 'woo-logistics-plugin' ),
					'dummyCreated'     => __( 'Test order created. Reloading...', 'woo-logistics-plugin' ),
					'dummyFailed'      => __( 'Failed to create test order.', 'woo-logistics-plugin' ),
					'requireSignature' => __( 'Require signature', 'woo-logistics-plugin' ),
					'cardForPickup'    => __( 'Card for pickup', 'woo-logistics-plugin' ),
				),
				'signatureRequired' => WLP_Settings::signature_required() ? 'yes' : 'no',
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

		if ( isset( $_GET['view'] ) && 'settings' === sanitize_key( wp_unslash( $_GET['view'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_settings_page();
			return;
		}

		$orders        = wc_get_orders(
			array(
				'limit'   => 200,
				'status'  => $this->logistics_query_statuses(),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$buckets       = $this->bucket_orders( $orders );
		$current_state = $this->current_logistics_state();

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
					<?php if ( $this->can_create_test_orders() ) : ?>
						<button class="button" type="button" data-wlp-create-dummy-order><?php echo esc_html__( 'Create test order', 'woo-logistics-plugin' ); ?></button>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wlp-logistics&view=settings' ) ); ?>"><?php echo esc_html__( 'Settings', 'woo-logistics-plugin' ); ?></a>
				</div>
			</div>
			<div class="wlp-bulk-status" data-wlp-bulk-status aria-live="polite"></div>
			<?php $this->render_logistics_tabs( $buckets, $current_state ); ?>
			<div class="wlp-board">
				<?php foreach ( $buckets[ $current_state ] as $order ) : ?>
					<?php if ( $order instanceof WC_Order ) : ?>
						<?php $this->render_order_card( $order ); ?>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( empty( $buckets[ $current_state ] ) ) : ?>
					<p class="wlp-empty"><?php echo esc_html__( 'No orders in this logistics state.', 'woo-logistics-plugin' ); ?></p>
				<?php endif; ?>
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
					<h1><?php echo esc_html__( 'Logistics Settings', 'woo-logistics-plugin' ); ?></h1>
					<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
						<div class="notice notice-success inline wlp-settings-notice">
							<p><?php echo esc_html__( 'Settings saved successfully.', 'woo-logistics-plugin' ); ?></p>
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
					<?php $this->field_checkbox( WLP_Settings::OPTION_SIGNATURE, __( 'Require signature on Canada Post labels', 'woo-logistics-plugin' ), 'no', __( 'When enabled, rates and purchased labels include Canada Post option SO - Signature. Priority may include this at no extra charge; other services may price it as an option.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_service_select( WLP_Settings::OPTION_DEFAULT_SERVICE, __( 'Default service for quick buy', 'woo-logistics-plugin' ), __( 'Quick buy uses this service when Canada Post returns it. If it is unavailable for an order, quick buy falls back to the cheapest returned rate.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_preset_select( WLP_Settings::OPTION_DEFAULT_PRESET, __( 'Default box for quick buy', 'woo-logistics-plugin' ), __( 'Quick buy uses this package preset. If it is unset or deleted, quick buy uses the first configured package preset.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_checkbox( WLP_Settings::OPTION_HIDE_REGULAR, __( 'Remove Regular Parcel as a label option', 'woo-logistics-plugin' ), 'no', __( 'When enabled, Regular Parcel is hidden from create-label choices and quick buy will not select it.', 'woo-logistics-plugin' ) ); ?>
				</table>

				<h2><?php echo esc_html__( 'Customer Order Notes', 'woo-logistics-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->field_checkbox( WLP_Settings::OPTION_CUSTOMER_NOTE, __( 'Send customer order note after label creation', 'woo-logistics-plugin' ), 'yes', __( 'Adds a customer-visible WooCommerce order note after a Canada Post label is purchased. WooCommerce sends its standard customer note email when enabled.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_textarea( WLP_Settings::OPTION_NOTE_TEMPLATE, __( 'Customer note template', 'woo-logistics-plugin' ), __( 'Available placeholders: {first_name}, {order_number}, {service_name}, {service_label}, {tracking_number}, {tracking_number_raw}, {tracking_url}.', 'woo-logistics-plugin' ) ); ?>
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
				<table class="form-table" role="presentation">
					<?php $this->field_checkbox( WLP_Settings::OPTION_PRODUCT_WEIGHT, __( 'Calculate shipment weight from WooCommerce products', 'woo-logistics-plugin' ), 'no', __( 'When enabled, Canada Post rates and labels use the order product weight total. Package presets still provide box dimensions and fallback weight for orders without shippable product lines.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_checkbox( WLP_Settings::OPTION_USE_BASE_WEIGHT, __( 'Add base package weight to product weights', 'woo-logistics-plugin' ), 'no', __( 'Use this for box, pouch, insert, and filler weight. It is added only when product-weight calculation is enabled.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_number( WLP_Settings::OPTION_BASE_WEIGHT, __( 'Base package weight (kg)', 'woo-logistics-plugin' ), '0.001', __( 'Example: 0.050 means 50 g is added before requesting Canada Post rates or labels.', 'woo-logistics-plugin' ) ); ?>
				</table>
				<?php $this->render_preset_fields(); ?>

				<h2><?php echo esc_html__( 'External Integrations', 'woo-logistics-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->field_checkbox( WLP_Settings::OPTION_EXTERNAL_META, __( 'Mirror label metadata for external logistics systems', 'woo-logistics-plugin' ), 'no', __( 'Writes additional hidden order metadata using the _medusa_logistics_* key format so external logistics dashboards can read labels created in WooCommerce.', 'woo-logistics-plugin' ) ); ?>
				</table>

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
			<p><button class="button" type="button" data-wlp-send-customer-note data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Send customer note', 'woo-logistics-plugin' ); ?></button></p>
		<?php endif; ?>
		<p><button class="button" type="button" data-wlp-create-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html( WLP_Order_Logistics::has_label( $order ) ? __( 'View label options', 'woo-logistics-plugin' ) : __( 'Create label', 'woo-logistics-plugin' ) ); ?></button></p>
		<?php $this->render_drawer(); ?>
		<?php
	}

	/**
	 * AJAX: fetches rates for each preset.
	 */
	public function ajax_get_rates(): void {
		$order              = $this->ajax_order();
		$signature_required = $this->ajax_signature_required();
		$payload            = array();

		foreach ( $this->client->get_presets() as $preset ) {
			try {
				$payload[] = array(
					'preset' => $preset,
					'rates'  => $this->client->get_rates( $order, $preset, $signature_required ),
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
				'orderId'           => $order->get_id(),
				'hasLabel'          => WLP_Order_Logistics::has_label( $order ),
				'printUrl'          => $this->print_url( $order ),
				'services'          => $this->client->get_services(),
				'presets'           => $payload,
				'signatureRequired' => $signature_required ? 'yes' : 'no',
			)
		);
	}

	/**
	 * AJAX: creates a Canada Post label for the selected order.
	 */
	public function ajax_create_label(): void {
		$order              = $this->ajax_order();
		$preset_id          = isset( $_POST['presetId'] ) ? sanitize_text_field( wp_unslash( $_POST['presetId'] ) ) : '';
		$service_code       = isset( $_POST['serviceCode'] ) ? sanitize_text_field( wp_unslash( $_POST['serviceCode'] ) ) : '';
		$override           = isset( $_POST['override'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['override'] ) );
		$signature_required = $this->ajax_signature_required();
		$card_for_pickup    = $this->ajax_card_for_pickup();

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
			$rates         = $this->client->get_rates( $order, $preset, $signature_required );
			$selected_rate = $this->find_rate( $rates, $service_code );

			if ( empty( $selected_rate ) ) {
				throw new RuntimeException( __( 'Selected service is not available for this order and package.', 'woo-logistics-plugin' ) );
			}

			$shipment_weight = $this->client->shipment_weight( $order, $preset );
			$shipment        = $this->client->create_shipment( $order, $preset, $service_code, $signature_required, $card_for_pickup );

			WLP_Order_Logistics::write_label(
				$order,
				array(
					'label_created_at'       => gmdate( 'c' ),
					'label_artifact_url'     => $shipment['label_artifact_url'],
					'tracking_number'        => $shipment['tracking_number'],
					'tracking_url'           => $shipment['tracking_url'],
					'service_code'           => $service_code,
					'service_name'           => $this->client->service_name( $service_code ),
					'shipping_cost'          => $selected_rate['due'] ?? '',
					'shipping_currency'      => 'CAD',
					'shipment_id'            => $shipment['shipment_id'],
					'expected_delivery_date' => $selected_rate['expected_delivery_date'] ?? '',
					'preset_id'              => $preset_id,
					'shipment_weight_kg'     => $shipment_weight,
					'shipped_at'             => 'completed' === $order->get_status() ? gmdate( 'c' ) : null,
				)
			);

			wp_send_json_success(
				array(
					'shipment' => $shipment,
					'printUrl' => $this->print_url( $order ),
					'package'  => $this->label_package_payload( $preset ),
					'rate'     => $selected_rate,
					'estimate' => $this->delivery_estimate_payload( $selected_rate['expected_delivery_date'] ?? '' ),
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
			$preset = WLP_Settings::default_preset();

			if ( ! is_array( $preset ) ) {
				throw new RuntimeException( __( 'No package presets are configured.', 'woo-logistics-plugin' ) );
			}

			$rates = $this->client->get_rates( $order, $preset );
			$rate  = $this->preferred_rate( $rates );

			if ( ! $rate || empty( $rate['service_code'] ) ) {
				throw new RuntimeException( __( 'Canada Post returned no rates for quick buy.', 'woo-logistics-plugin' ) );
			}

			$service_code    = $rate['service_code'];
			$shipment_weight = $this->client->shipment_weight( $order, $preset );
			$shipment        = $this->client->create_shipment( $order, $preset, $service_code );

			WLP_Order_Logistics::write_label(
				$order,
				array(
					'label_created_at'       => gmdate( 'c' ),
					'label_artifact_url'     => $shipment['label_artifact_url'],
					'tracking_number'        => $shipment['tracking_number'],
					'tracking_url'           => $shipment['tracking_url'],
					'service_code'           => $service_code,
					'service_name'           => $this->client->service_name( $service_code ),
					'shipping_cost'          => $rate['due'] ?? '',
					'shipping_currency'      => 'CAD',
					'shipment_id'            => $shipment['shipment_id'],
					'expected_delivery_date' => $rate['expected_delivery_date'] ?? '',
					'preset_id'              => (string) $preset['id'],
					'shipment_weight_kg'     => $shipment_weight,
					'shipped_at'             => 'completed' === $order->get_status() ? gmdate( 'c' ) : null,
				)
			);

			wp_send_json_success(
				array(
					'shipment' => $shipment,
					'printUrl' => $this->print_url( $order ),
					'package'  => $this->label_package_payload( $preset ),
					'rate'     => $rate,
					'estimate' => $this->delivery_estimate_payload( $rate['expected_delivery_date'] ?? '' ),
				)
			);
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 500 );
		}
	}

	/**
	 * AJAX: manually sends the configured customer tracking note.
	 */
	public function ajax_send_customer_note(): void {
		$order = $this->ajax_order();

		if ( ! WLP_Order_Logistics::has_label( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Create a label before sending the customer tracking note.', 'woo-logistics-plugin' ) ), 400 );
		}

		$sent = WLP_Order_Logistics::send_customer_label_note( $order, array(), true );
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'No tracking number is available for this order.', 'woo-logistics-plugin' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Customer note sent.', 'woo-logistics-plugin' ),
			)
		);
	}

	/**
	 * AJAX: creates one fake Canadian WooCommerce order for label testing.
	 */
	public function ajax_create_dummy_order(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woo-logistics-plugin' ) ), 403 );
		}

		check_ajax_referer( 'wlp_logistics', 'nonce' );

		if ( ! $this->can_create_test_orders() ) {
			wp_send_json_error( array( 'message' => __( 'Test order creation is disabled in production.', 'woo-logistics-plugin' ) ), 403 );
		}

		if ( ! function_exists( 'wc_create_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce order creation is unavailable.', 'woo-logistics-plugin' ) ), 500 );
		}

		try {
			$order = wc_create_order(
				array(
					'created_via' => 'woo-logistics-plugin-test-tool',
					'status'      => 'processing',
				)
			);

			if ( is_wp_error( $order ) ) {
				throw new RuntimeException( $order->get_error_message() );
			}

			if ( ! $order instanceof WC_Order ) {
				throw new RuntimeException( __( 'WooCommerce did not return an order.', 'woo-logistics-plugin' ) );
			}

			$address = $this->dummy_order_address();
			$order->set_address( $address, 'billing' );
			$order->set_address( $address, 'shipping' );
			$order->set_currency( 'CAD' );

			$products        = $this->dummy_products();
			$product_count   = random_int( 1, min( 5, count( $products ) ) );
			$product_indexes = array_rand( $products, $product_count );
			$product_indexes = is_array( $product_indexes ) ? $product_indexes : array( $product_indexes );
			$total_quantity  = 0;

			foreach ( $product_indexes as $product_index ) {
				$quantity = random_int( 1, 4 );
				$order->add_product( $products[ $product_index ], $quantity );
				$total_quantity += $quantity;
			}

			$order->update_meta_data( '_wlp_dummy_order', 'yes' );
			$order->update_meta_data( '_wlp_dummy_item_weight_g', (string) self::DUMMY_PRODUCT_WEIGHT_GRAMS );
			$order->update_meta_data( '_wlp_dummy_total_product_quantity', (string) $total_quantity );
			$order->add_order_note(
				sprintf(
					/* translators: 1: line item count, 2: product quantity, 3: grams per product unit. */
					__( 'Created by Woo Logistics Plugin test order tool with %1$d randomized product lines and %2$d total %3$s g product units.', 'woo-logistics-plugin' ),
					count( $product_indexes ),
					$total_quantity,
					$this->dummy_product_weight_grams_label()
				)
			);
			$order->calculate_totals();
			$order->save();

			wp_send_json_success(
				array(
					'orderId'     => $order->get_id(),
					'orderNumber' => $order->get_order_number(),
					'editUrl'     => $order->get_edit_order_url(),
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

		check_admin_referer( 'wlp_bulk_print_labels' );

		$order_ids = $this->request_order_ids();
		if ( empty( $order_ids ) ) {
			wp_die( esc_html__( 'Select at least one labeled order to print.', 'woo-logistics-plugin' ) );
		}

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
	 * Returns Woo statuses that can appear in the logistics tabs.
	 *
	 * @return array<int, string>
	 */
	private function logistics_query_statuses(): array {
		$statuses   = WLP_Settings::eligible_statuses();
		$statuses[] = 'completed';

		return array_values( array_unique( array_filter( $statuses ) ) );
	}

	/**
	 * Whether the current environment should expose test order tools.
	 */
	private function can_create_test_orders(): bool {
		return ! $this->is_production_environment();
	}

	/**
	 * Detects production using WordPress' environment type when available.
	 */
	private function is_production_environment(): bool {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return 'production' === wp_get_environment_type();
		}

		$environment = getenv( 'WP_ENVIRONMENT_TYPE' );
		if ( false === $environment || '' === $environment ) {
			return true;
		}

		return 'production' === strtolower( (string) $environment );
	}

	/**
	 * Returns valid logistics tab definitions.
	 *
	 * @return array<string, string>
	 */
	private function logistics_states(): array {
		return array(
			'to_be_shipped' => __( 'To be shipped', 'woo-logistics-plugin' ),
			'in_transit'    => __( 'In transit', 'woo-logistics-plugin' ),
			'delivered'     => __( 'Delivered', 'woo-logistics-plugin' ),
		);
	}

	/**
	 * Resolves the selected logistics tab from the request.
	 */
	private function current_logistics_state(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['wlp_status'] ) ? sanitize_key( wp_unslash( $_GET['wlp_status'] ) ) : 'to_be_shipped';

		return array_key_exists( $state, $this->logistics_states() ) ? $state : 'to_be_shipped';
	}

	/**
	 * Groups orders by logistics state.
	 *
	 * @param array<int, mixed> $orders WooCommerce order query results.
	 * @return array<string, array<int, WC_Order>>
	 */
	private function bucket_orders( array $orders ): array {
		$buckets = array_fill_keys( array_keys( $this->logistics_states() ), array() );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$buckets[ $this->order_logistics_state( $order ) ][] = $order;
		}

		return $buckets;
	}

	/**
	 * Renders the logistics state tabs.
	 *
	 * @param array<string, array<int, WC_Order>> $buckets Orders grouped by state.
	 */
	private function render_logistics_tabs( array $buckets, string $current_state ): void {
		?>
		<nav class="nav-tab-wrapper wlp-tabs" aria-label="<?php echo esc_attr__( 'Logistics status', 'woo-logistics-plugin' ); ?>">
			<?php foreach ( $this->logistics_states() as $state => $label ) : ?>
				<?php
				$count = count( $buckets[ $state ] ?? array() );
				$url   = add_query_arg(
					array(
						'page'       => 'wlp-logistics',
						'wlp_status' => $state,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a class="nav-tab <?php echo esc_attr( $state === $current_state ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $label ); ?>
					<span class="wlp-tab-count"><?php echo esc_html( (string) $count ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Classifies an order using the same Woo logistics state rules as the storefront hub.
	 */
	private function order_logistics_state( WC_Order $order ): string {
		$meta = WLP_Order_Logistics::read( $order );

		if ( '' !== $meta['delivered_at'] ) {
			return 'delivered';
		}

		if ( '' !== $meta['shipped_at'] || ( 'completed' === $order->get_status() && '' !== $meta['label_created_at'] ) ) {
			return 'in_transit';
		}

		if ( 'completed' === $order->get_status() && '' === $meta['label_created_at'] ) {
			return 'delivered';
		}

		return 'to_be_shipped';
	}

	/**
	 * Returns a display label for a logistics state.
	 */
	private function logistics_state_label( string $state ): string {
		$states = $this->logistics_states();

		return $states[ $state ] ?? $states['to_be_shipped'];
	}

	/**
	 * Renders one order card.
	 */
	private function render_order_card( WC_Order $order ): void {
		$meta            = WLP_Order_Logistics::read( $order );
		$has_label       = WLP_Order_Logistics::has_label( $order );
		$package_preview = $this->order_package_preview( $order, $meta, $has_label );
		$state           = $this->order_logistics_state( $order );
		$tracking_url    = $this->tracking_url( $meta );
		$estimate_date   = 'in_transit' === $state ? $this->current_delivery_estimate_date( $order, $meta ) : '';
		$detail_label    = 'in_transit' === $state ? __( 'Estimated delivery', 'woo-logistics-plugin' ) : __( 'Package', 'woo-logistics-plugin' );
		$detail_value    = 'in_transit' === $state ? $this->delivery_estimate_label( $estimate_date ) : $package_preview['preset'];
		$pill            = $this->logistics_state_label( $state );
		$pill_class      = match ( $state ) {
			'delivered'  => 'is-delivered',
			'in_transit' => 'is-ready',
			default      => 'is-pending',
		};
		$is_selectable = 'delivered' !== $state || $has_label;
		?>
		<section class="wlp-card" data-wlp-order-card data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-logistics-state="<?php echo esc_attr( $state ); ?>" data-has-label="<?php echo esc_attr( $has_label ? 'yes' : 'no' ); ?>" data-print-url="<?php echo esc_url( $has_label ? $this->print_url( $order ) : '' ); ?>">
			<?php if ( $is_selectable ) : ?>
				<label class="wlp-select">
					<input type="checkbox" data-wlp-order-select value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
					<span><?php echo esc_html__( 'Select order', 'woo-logistics-plugin' ); ?></span>
				</label>
			<?php endif; ?>
			<div class="wlp-card__top">
				<div>
					<h2>#<?php echo esc_html( $order->get_order_number() ); ?></h2>
					<div class="wlp-card__recipient"><?php echo esc_html( $this->recipient_line( $order ) ); ?></div>
				</div>
				<span class="wlp-pill <?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( $pill ); ?></span>
			</div>
			<dl class="wlp-facts">
				<div><dt><?php echo esc_html__( 'Status', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Logistics', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( $pill ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Service', 'woo-logistics-plugin' ); ?></dt><dd data-wlp-card-service><?php echo esc_html( $meta['service_name'] ?: '-' ); ?></dd></div>
				<div>
					<dt><?php echo esc_html__( 'Tracking', 'woo-logistics-plugin' ); ?></dt>
					<dd data-wlp-card-tracking>
						<?php if ( '' !== $meta['tracking_number'] && '' !== $tracking_url ) : ?>
							<a target="_blank" href="<?php echo esc_url( $tracking_url ); ?>"><?php echo esc_html( $meta['tracking_number'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $meta['tracking_number'] ?: '-' ); ?>
						<?php endif; ?>
					</dd>
				</div>
				<div><dt data-wlp-card-detail-label><?php echo esc_html( $detail_label ); ?></dt><dd data-wlp-card-detail><?php echo esc_html( $detail_value ); ?></dd></div>
			</dl>
			<div class="wlp-actions">
				<?php if ( 'delivered' !== $state || $has_label ) : ?>
					<button class="button button-primary" type="button" data-wlp-create-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html( $has_label ? __( 'View options', 'woo-logistics-plugin' ) : __( 'Create label', 'woo-logistics-plugin' ) ); ?></button>
				<?php endif; ?>
				<?php if ( $has_label ) : ?>
					<a class="button" target="_blank" data-wlp-print-label href="<?php echo esc_url( $this->print_url( $order ) ); ?>"><?php echo esc_html__( 'Print', 'woo-logistics-plugin' ); ?></a>
					<button class="button" type="button" data-wlp-send-customer-note data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Send customer note', 'woo-logistics-plugin' ); ?></button>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Builds the package summary for an order card.
	 *
	 * @param WC_Order              $order WooCommerce order.
	 * @param array<string, string> $meta Logistics metadata.
	 * @return array{preset: string}
	 */
	private function order_package_preview( WC_Order $order, array $meta, bool $has_label ): array {
		$preset       = null;
		$preset_label = '-';

		if ( $has_label ) {
			if ( '' !== $meta['preset_id'] ) {
				try {
					$preset       = $this->client->find_preset( $meta['preset_id'] );
					$preset_label = $this->preset_label( $preset );
				} catch ( Throwable $error ) {
					$preset_label = $meta['preset_id'];
				}
			}
		} else {
			$preset = WLP_Settings::default_preset();

			if ( is_array( $preset ) ) {
				$preset_label = $this->preset_label( $preset );
			}
		}

		return array(
			'preset' => $preset_label,
		);
	}

	/**
	 * Formats a package preset for admin display.
	 *
	 * @param array<string, float|string> $preset Package preset.
	 */
	private function preset_label( array $preset ): string {
		$name = trim( (string) ( $preset['name'] ?? '' ) );
		$id   = trim( (string) ( $preset['id'] ?? '' ) );

		if ( '' !== $name && '' !== $id ) {
			return sprintf( '%1$s (%2$s)', $name, $id );
		}

		return $name ?: $id ?: '-';
	}

	/**
	 * Builds package details returned after an AJAX label purchase.
	 *
	 * @param array<string, float|string> $preset Package preset.
	 * @return array{preset: string}
	 */
	private function label_package_payload( array $preset ): array {
		return array(
			'preset' => $this->preset_label( $preset ),
		);
	}

	/**
	 * Builds delivery estimate details returned after an AJAX label purchase.
	 *
	 * @return array{label: string, value: string, raw: string}
	 */
	private function delivery_estimate_payload( string $expected_delivery_date ): array {
		return array(
			'label' => __( 'Estimated delivery', 'woo-logistics-plugin' ),
			'value' => $this->delivery_estimate_label( $expected_delivery_date ),
			'raw'   => $expected_delivery_date,
		);
	}

	/**
	 * Formats an expected delivery date for admin cards.
	 */
	private function delivery_estimate_label( string $expected_delivery_date ): string {
		$expected_delivery_date = trim( $expected_delivery_date );
		if ( '' === $expected_delivery_date ) {
			return __( 'Not available', 'woo-logistics-plugin' );
		}

		$timestamp = strtotime( $expected_delivery_date );
		if ( false === $timestamp ) {
			return $expected_delivery_date;
		}

		return wp_date( (string) get_option( 'date_format', 'F j, Y' ), $timestamp );
	}

	/**
	 * Returns the best current delivery estimate, refreshing from Canada Post when missing.
	 *
	 * @param WC_Order              $order WooCommerce order.
	 * @param array<string, string> $meta Logistics metadata.
	 */
	private function current_delivery_estimate_date( WC_Order $order, array $meta ): string {
		if ( '' !== $meta['expected_delivery_date'] ) {
			return $meta['expected_delivery_date'];
		}

		if ( '' === $meta['tracking_number'] ) {
			return '';
		}

		try {
			$estimate = $this->client->get_tracking_estimate( $meta['tracking_number'], true );
			$metadata = array(
				'last_polled_at' => gmdate( 'c' ),
			);

			if ( '' !== $estimate['expected_delivery_date'] ) {
				$metadata['expected_delivery_date'] = $estimate['expected_delivery_date'];
			}

			if ( '' !== $estimate['actual_delivery_date'] ) {
				$metadata['delivered_at'] = $estimate['actual_delivery_date'];
			}

			WLP_Order_Logistics::write_tracking_estimate( $order, $metadata );

			return $estimate['expected_delivery_date'];
		} catch ( Throwable $error ) {
			return '';
		}
	}

	/**
	 * Returns a Canada Post tracking URL for card links.
	 *
	 * @param array<string, string> $meta Logistics metadata.
	 */
	private function tracking_url( array $meta ): string {
		if ( '' !== $meta['tracking_url'] ) {
			return $meta['tracking_url'];
		}

		if ( '' === $meta['tracking_number'] ) {
			return '';
		}

		return 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $meta['tracking_number'] );
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
	 * Renders a textarea setting row.
	 */
	private function field_textarea( string $key, string $label, string $description = '' ): void {
		$value = WLP_Settings::OPTION_NOTE_TEMPLATE === $key ? WLP_Settings::customer_label_note_template() : (string) get_option( $key, '' );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea class="large-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="11"><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders a number setting row.
	 */
	private function field_number( string $key, string $label, string $step, string $description = '' ): void {
		$value = (string) get_option( $key, '' );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" type="number" step="<?php echo esc_attr( $step ); ?>" min="0">
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders a Canada Post service selector.
	 */
	private function field_service_select( string $key, string $label, string $description = '' ): void {
		$value = WLP_Settings::default_service_code();
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
					<?php foreach ( WLP_Settings::service_options() as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>><?php echo esc_html( '' === $code ? $name : $code . ' - ' . $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders a package preset selector.
	 */
	private function field_preset_select( string $key, string $label, string $description = '' ): void {
		$value   = WLP_Settings::default_preset_id();
		$presets = WLP_Settings::presets();
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
					<option value=""><?php echo esc_html__( 'First configured package preset', 'woo-logistics-plugin' ); ?></option>
					<?php foreach ( $presets as $preset ) : ?>
						<option value="<?php echo esc_attr( (string) $preset['id'] ); ?>" <?php selected( $value, (string) $preset['id'] ); ?>><?php echo esc_html( $this->preset_label( $preset ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
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
	private function field_checkbox( string $key, string $label, string $default_value = 'no', string $description = '' ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="yes" type="checkbox" <?php checked( get_option( $key, $default_value ), 'yes' ); ?>>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
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
	 * Reads the per-request signature override sent by the label drawer.
	 */
	private function ajax_signature_required(): bool {
		$value = isset( $_POST['signatureRequired'] ) ? sanitize_text_field( wp_unslash( $_POST['signatureRequired'] ) ) : '';

		if ( '' === $value ) {
			return WLP_Settings::signature_required();
		}

		return 'yes' === $value;
	}

	/**
	 * Reads the per-request Card for Pickup option sent by the label drawer.
	 */
	private function ajax_card_for_pickup(): bool {
		$value = isset( $_POST['cardForPickup'] ) ? sanitize_text_field( wp_unslash( $_POST['cardForPickup'] ) ) : '';

		return 'yes' === $value;
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
	 * Returns the configured default service rate when available, otherwise cheapest.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 * @return array<string, string>
	 */
	private function preferred_rate( array $rates ): array {
		$default_service = WLP_Settings::default_service_code();

		if ( '' !== $default_service ) {
			$default_rate = $this->find_rate( $rates, $default_service );
			if ( ! empty( $default_rate ) ) {
				return $default_rate;
			}
		}

		return $this->cheapest_rate( $rates );
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
		// Nonce verification happens in the public action before this parser is called.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = isset( $_GET['order_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) : '';
		$ids   = array_map( 'absint', explode( ',', $value ) );
		$ids   = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Returns a fake Canadian order address suitable for Canada Post sandbox testing.
	 *
	 * @return array<string, string>
	 */
	private function dummy_order_address(): array {
		$addresses = array(
			array( 'Researcher', 'A', 'Test Lab East', '123 Test Lab Rd', 'Ottawa', 'ON', 'K1A 0B1', '6135550100' ),
			array( 'Researcher', 'B', 'Test Lab West', '456 Sample Ave', 'Toronto', 'ON', 'M5V 3L9', '4165550101' ),
			array( 'Researcher', 'C', 'Test Lab North', '789 Control St', 'Montreal', 'QC', 'H2Y 1C6', '5145550102' ),
			array( 'Researcher', 'D', 'Test Lab South', '321 Assay Blvd', 'Calgary', 'AB', 'T2P 1J9', '4035550103' ),
			array( 'Researcher', 'E', 'Test Lab Central', '654 Reference Way', 'Vancouver', 'BC', 'V6B 1A1', '6045550104' ),
		);
		$selected  = $addresses[ random_int( 0, count( $addresses ) - 1 ) ];

		return array(
			'first_name' => $selected[0],
			'last_name'  => $selected[1],
			'company'    => $selected[2],
			'email'      => 'lab@example.com',
			'phone'      => $selected[7],
			'address_1'  => $selected[3],
			'address_2'  => '',
			'city'       => $selected[4],
			'state'      => $selected[5],
			'postcode'   => $selected[6],
			'country'    => 'CA',
		);
	}

	/**
	 * Creates or reuses hidden weighted products for randomized dummy orders.
	 *
	 * @return array<int, WC_Product>
	 */
	private function dummy_products(): array {
		if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			throw new RuntimeException( esc_html__( 'WooCommerce product creation is unavailable.', 'woo-logistics-plugin' ) );
		}

		$products = array();
		$names    = array(
			'Reference Sample A',
			'Reference Sample B',
			'Control Sample C',
			'Assay Sample D',
			'Stability Sample E',
		);

		foreach ( $names as $index => $name ) {
			$sku        = 'wlp-test-' . $this->dummy_product_weight_grams_label() . 'g-' . ( $index + 1 );
			$product_id = wc_get_product_id_by_sku( $sku );
			$product    = $product_id ? wc_get_product( $product_id ) : null;

			if ( ! $product instanceof WC_Product_Simple ) {
				$product = new WC_Product_Simple();
				$product->set_sku( $sku );
			}

			$product->set_name( $name . ' - ' . $this->dummy_product_weight_grams_label() . ' g' );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( false );
			$product->set_description( __( 'For laboratory research use only.', 'woo-logistics-plugin' ) );
			$product->set_regular_price( '12.00' );
			$product->set_price( '12.00' );
			$product->set_weight( $this->dummy_product_weight_for_store_unit() );
			$product->update_meta_data( '_wlp_dummy_product', 'yes' );
			$product->save();
			$products[] = $product;
		}

		return $products;
	}

	/**
	 * Converts the fixed dummy product weight into the store's weight unit.
	 */
	private function dummy_product_weight_for_store_unit(): string {
		$grams = self::DUMMY_PRODUCT_WEIGHT_GRAMS;
		$unit  = strtolower( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
		$value = match ( $unit ) {
			'g'   => $grams,
			'lbs' => $grams * 0.00220462262185,
			'oz'  => $grams * 0.0352739619496,
			default => $grams / 1000,
		};

		return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
	}

	/**
	 * Formats the dummy product gram weight without unnecessary decimals.
	 */
	private function dummy_product_weight_grams_label(): string {
		return rtrim( rtrim( number_format( self::DUMMY_PRODUCT_WEIGHT_GRAMS, 3, '.', '' ), '0' ), '.' );
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
