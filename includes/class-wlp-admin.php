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
	private const ALCOHOL_PADS_MIN_SUBTOTAL = 299.0;
	private const COMPLIMENTARY_BAC_MIN_SUBTOTAL = 299.0;
	private const DUMMY_PRODUCT_WEIGHT_GRAMS = 10.0;
	private const WHOLESALE_STAGE_META = '_wlp_wholesale_followup_stage';
	private const WHOLESALE_STAGE_AT_META = '_wlp_wholesale_followup_stage_at';
	private const WHOLESALE_NOTES_META = '_wlp_wholesale_internal_notes';
	private const WHOLESALE_NOTES_AT_META = '_wlp_wholesale_internal_notes_at';
	private const WHOLESALE_WORKFLOW_META = '_wlp_wholesale_workflow_stage';
	private const WHOLESALE_WORKFLOW_AT_META = '_wlp_wholesale_workflow_stage_at';
	private const WHOLESALE_INBOUND_CARRIER_META = '_wlp_wholesale_inbound_carrier';
	private const WHOLESALE_INBOUND_TRACKING_META = '_wlp_wholesale_inbound_tracking_number';
	private const WHOLESALE_INBOUND_TRACKING_URL_META = '_wlp_wholesale_inbound_tracking_url';
	private const WHOLESALE_INSPECTED_AT_META = '_wlp_wholesale_inspected_at';
	private const WHOLESALE_AMOUNT_PAID_META = '_wlp_wholesale_amount_paid';
	private const WHOLESALE_PAYMENT_BALANCE_META = '_wlp_wholesale_payment_balance';
	private const WHOLESALE_PARTIAL_PAYMENT_AT_META = '_wlp_wholesale_partial_payment_at';
	private const WHOLESALE_PARTIAL_PAYMENTS_META = '_wlp_wholesale_partial_payments';
	private const REORDER_FOLLOWUP_HOOK = 'wlp_reorder_followup_cron';
	private const SUPPLIER_COST_OPTION = 'wlp_supplier_cost_catalog';
	private const WHOLESALE_SOURCING_META = '_wlp_wholesale_sourcing_v1';

	private WLP_Canada_Post_Client $client;
	private bool $suppress_wholesale_processing_email = false;
	/** @var array<int, bool> */
	private array $wholesale_processing_email_handled = array();

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
		WLP_Split_Shipments::register();
		require_once WLP_PATH . 'includes/class-wlp-contract-shipping.php';
		WLP_Contract_Shipping::register();
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wlp_get_rates', array( $this, 'ajax_get_rates' ) );
		add_action( 'wp_ajax_wlp_create_label', array( $this, 'ajax_create_label' ) );
		add_action( 'wp_ajax_wlp_get_return_rates', array( $this, 'ajax_get_return_rates' ) );
		add_action( 'wp_ajax_wlp_create_return_label', array( $this, 'ajax_create_return_label' ) );
		add_action( 'wp_ajax_wlp_send_return_note', array( $this, 'ajax_send_return_note' ) );
		add_action( 'wp_ajax_wlp_quick_buy_preview', array( $this, 'ajax_quick_buy_preview' ) );
		add_action( 'wp_ajax_wlp_quick_buy_label', array( $this, 'ajax_quick_buy_label' ) );
		add_action( 'wp_ajax_wlp_quick_view_order', array( $this, 'ajax_quick_view_order' ) );
		add_action( 'wp_ajax_wlp_save_profit', array( $this, 'ajax_save_profit' ) );
		add_action( 'wp_ajax_wlp_save_supplier_costs', array( $this, 'ajax_save_supplier_costs' ) );
		add_action( 'wp_ajax_wlp_save_supplier_cost_catalog', array( $this, 'ajax_save_supplier_cost_catalog' ) );
		add_action( 'wp_ajax_wlp_wholesale_save_notes', array( $this, 'ajax_wholesale_save_notes' ) );
		add_action( 'wp_ajax_wlp_wholesale_save_sources', array( $this, 'ajax_wholesale_save_sources' ) );
		add_action( 'wp_ajax_wlp_wholesale_supplier_status', array( $this, 'ajax_wholesale_supplier_status' ) );
		add_action( 'wp_ajax_wlp_wholesale_batch_supplier_status', array( $this, 'ajax_wholesale_batch_supplier_status' ) );
		add_action( 'wp_ajax_wlp_send_customer_note', array( $this, 'ajax_send_customer_note' ) );
		add_action( 'wp_ajax_wlp_send_delivery_delay_note', array( $this, 'ajax_send_delivery_delay_note' ) );
		add_action( 'wp_ajax_wlp_prepare_reship', array( $this, 'ajax_prepare_reship' ) );
		add_action( 'wp_ajax_wlp_mark_packed', array( $this, 'ajax_mark_packed' ) );
			add_action( 'wp_ajax_wlp_mark_shipped', array( $this, 'ajax_mark_shipped' ) );
			add_action( 'wp_ajax_wlp_mark_delivered', array( $this, 'ajax_mark_delivered' ) );
			add_action( 'wp_ajax_wlp_wholesale_followup_preview', array( $this, 'ajax_wholesale_followup_preview' ) );
			add_action( 'wp_ajax_wlp_wholesale_followup', array( $this, 'ajax_wholesale_followup' ) );
			add_action( 'wp_ajax_wlp_wholesale_advance', array( $this, 'ajax_wholesale_advance' ) );
			add_action( 'wp_ajax_wlp_wholesale_record_partial_payment', array( $this, 'ajax_wholesale_record_partial_payment' ) );
			add_action( 'wp_ajax_wlp_wholesale_set_workflow', array( $this, 'ajax_wholesale_set_workflow' ) );
			add_action( 'wp_ajax_wlp_wholesale_save_inbound_tracking', array( $this, 'ajax_wholesale_save_inbound_tracking' ) );
			add_action( 'wp_ajax_wlp_wholesale_tracking_preview', array( $this, 'ajax_wholesale_tracking_preview' ) );
			add_action( 'wp_ajax_wlp_wholesale_save_tracking', array( $this, 'ajax_wholesale_save_tracking' ) );
			add_action( 'wp_ajax_wlp_cancel_order', array( $this, 'ajax_cancel_order' ) );
		add_action( 'wp_ajax_wlp_create_dummy_order', array( $this, 'ajax_create_dummy_order' ) );
		add_action( 'admin_post_wlp_print_label', array( $this, 'print_label' ) );
		add_action( 'admin_post_nopriv_wlp_print_label', 'auth_redirect' );
		add_action( 'admin_post_wlp_print_return_label', array( $this, 'print_return_label' ) );
		add_action( 'admin_post_wlp_customer_return_label', array( $this, 'customer_return_label' ) );
		add_action( 'admin_post_nopriv_wlp_customer_return_label', array( $this, 'customer_return_label' ) );
		add_action( 'admin_post_wlp_print_invoice', array( $this, 'print_invoice' ) );
		add_action( 'admin_post_wlp_bulk_print_labels', array( $this, 'bulk_print_labels' ) );
		add_action( 'admin_post_wlp_manual_counter_labels', array( $this, 'manual_counter_labels' ) );
		add_action( 'admin_post_wlp_bulk_print_invoices', array( $this, 'bulk_print_invoices' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_order_box' ) );
		add_action( self::REORDER_FOLLOWUP_HOOK, array( $this, 'send_due_reorder_followups' ) );
		$this->register_processing_email_hooks();

		$this->schedule_reorder_followup_cron();
	}

	/** Register wholesale email handling for both admin and REST order updates. */
	public function register_processing_email_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_send_wholesale_processing_email' ), 20, 4 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( $this, 'disable_standard_processing_email_for_wholesale' ), 10, 2 );
	}

	/**
	 * Sends the small daily batch of due reorder follow-ups.
	 */
	public function send_due_reorder_followups(): void {
		WLP_Order_Logistics::send_due_reorder_followups( 20 );
	}

	/**
	 * Uses the wholesale payment-received email instead of Woo's generic processing email.
	 *
	 * @param bool  $enabled Whether the standard Woo email is enabled.
	 * @param mixed $order   Order supplied by WooCommerce when available.
	 */
	public function disable_standard_processing_email_for_wholesale( bool $enabled, $order ): bool {
		if ( $order instanceof WC_Order && $this->is_wholesale_order( $order ) ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Sends one wholesale payment-received email when an order moves to Processing.
	 */
	public function maybe_send_wholesale_processing_email( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
		if (
			$this->suppress_wholesale_processing_email
			|| 'processing' !== $new_status
			|| 'processing' === $old_status
			|| ! $this->is_wholesale_order( $order )
			|| isset( $this->wholesale_processing_email_handled[ $order_id ] )
		) {
			return;
		}

		$this->wholesale_processing_email_handled[ $order_id ] = true;
		$message = $this->wholesale_followup_message( $order, 'payment_received', WLP_Order_Logistics::read( $order ) );

		if ( empty( $message ) || ! $this->send_wholesale_followup_email( $order, $message['subject'], $message['body'] ) ) {
			$order->add_order_note(
				__( 'Wholesale order changed to Processing, but WordPress could not send the automatic payment-received email.', 'woo-logistics-plugin' ),
				false,
				true
			);
			return;
		}

		$now = current_time( 'mysql' );
		$order->update_meta_data( self::WHOLESALE_STAGE_META, 'payment_received' );
		$order->update_meta_data( self::WHOLESALE_STAGE_AT_META, $now );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_META, 'supplier_order' );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_AT_META, $now );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: %s: previous WooCommerce order status. */
				__( 'Automatic wholesale payment-received email sent after status changed from %s to Processing.', 'woo-logistics-plugin' ),
				wc_get_order_status_name( $old_status )
			),
			false,
			true
		);
	}

	/**
	 * Keeps the daily reorder follow-up job scheduled.
	 */
	private function schedule_reorder_followup_cron(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		if ( false === wp_next_scheduled( self::REORDER_FOLLOWUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::REORDER_FOLLOWUP_HOOK );
		}
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
		wp_enqueue_script( 'wlp-split', WLP_URL . 'assets/js/split-shipments.js', array( 'wlp-admin' ), WLP_VERSION, true );
		wp_enqueue_style( 'wlp-split', WLP_URL . 'assets/css/split-shipments.css', array( 'wlp-admin' ), WLP_VERSION );
		wp_localize_script(
			'wlp-admin',
			'wlpAdmin',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'manifestsUrl'      => admin_url('admin.php?page=wlp-contract-manifests'),
				'nonce'             => wp_create_nonce( 'wlp_logistics' ),
				'settingsUrl'       => admin_url( 'admin.php?page=wlp-logistics&view=settings' ),
				'manualCounterUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=wlp_manual_counter_labels' ), 'wlp_manual_counter_labels' ),
				'bulkPrintUrl'      => admin_url( 'admin-post.php?action=wlp_bulk_print_labels' ),
				'bulkPrintNonce'    => wp_create_nonce( 'wlp_bulk_print_labels' ),
				'bulkInvoiceUrl'    => admin_url( 'admin-post.php?action=wlp_bulk_print_invoices' ),
				'bulkInvoiceNonce'  => wp_create_nonce( 'wlp_bulk_print_invoices' ),
				'i18n'              => array(
					'loadingRates'     => __( 'Loading Canada Post rates...', 'woo-logistics-plugin' ),
					'buying'           => __( 'Buying...', 'woo-logistics-plugin' ),
					'buyLabel'         => __( 'Buy label', 'woo-logistics-plugin' ),
					'failedRates'      => __( 'Failed to load rates.', 'woo-logistics-plugin' ),
					'failedLabel'      => __( 'Failed to create label.', 'woo-logistics-plugin' ),
					'createReturnLabel' => __( 'Create return label', 'woo-logistics-plugin' ),
					'returnLabelTitle' => __( 'Create Return Label', 'woo-logistics-plugin' ),
					'loadingReturnRates' => __( 'Loading return rates...', 'woo-logistics-plugin' ),
					'buyReturnLabel'  => __( 'Buy return label', 'woo-logistics-plugin' ),
					'buyReturnOverride' => __( 'Buy replacement return label', 'woo-logistics-plugin' ),
					'printReturnLabel' => __( 'Print return label', 'woo-logistics-plugin' ),
					'sendReturnNote'  => __( 'Email return label + instructions', 'woo-logistics-plugin' ),
					'returnNoteSent'  => __( 'Return label email sent.', 'woo-logistics-plugin' ),
					'failedReturnNote' => __( 'Failed to email return label.', 'woo-logistics-plugin' ),
					'confirmReturnLabel' => __( 'Buy a Canada Post return label that sends this customer package back to your configured return address?', 'woo-logistics-plugin' ),
					'confirmReturnOverride' => __( 'This order already has a return label. Buy a replacement return label anyway?', 'woo-logistics-plugin' ),
					'loadingOrder'     => __( 'Loading order...', 'woo-logistics-plugin' ),
					'quickView'        => __( 'Quick view', 'woo-logistics-plugin' ),
					'failedQuickView'  => __( 'Failed to load order quick view.', 'woo-logistics-plugin' ),
					'reprint'          => __( 'Reprint existing label', 'woo-logistics-plugin' ),
					'buyOverride'      => __( 'Review existing label', 'woo-logistics-plugin' ),
					'tracking'         => __( 'Tracking', 'woo-logistics-plugin' ),
					'printLabel'       => __( 'Print label', 'woo-logistics-plugin' ),
					'printInvoice'     => __( 'Print invoice', 'woo-logistics-plugin' ),
					'print'            => __( 'Print', 'woo-logistics-plugin' ),
					'sendNote'         => __( 'Send customer note', 'woo-logistics-plugin' ),
					'sendingNote'      => __( 'Sending note...', 'woo-logistics-plugin' ),
					'sentNote'         => __( 'Customer note sent.', 'woo-logistics-plugin' ),
					'failedNote'       => __( 'Failed to send customer note.', 'woo-logistics-plugin' ),
					'sendDelayNote'    => __( 'Send delay follow-up', 'woo-logistics-plugin' ),
					'delayNoteSent'    => __( 'Delivery delay follow-up sent.', 'woo-logistics-plugin' ),
					'failedDelayNote'  => __( 'Failed to send delivery delay follow-up.', 'woo-logistics-plugin' ),
					'confirmDelayNote' => __( 'Send this customer a delivery delay follow-up email?', 'woo-logistics-plugin' ),
					'prepareReship'    => __( 'Prepare reship', 'woo-logistics-plugin' ),
					'preparingReship'  => __( 'Preparing reship...', 'woo-logistics-plugin' ),
					'preparedReship'   => __( 'Order prepared for reship.', 'woo-logistics-plugin' ),
					'failedReship'     => __( 'Failed to prepare reship.', 'woo-logistics-plugin' ),
					'confirmPrepareReship' => __( "Prepare this order for a reship?\n\nThis clears the current label/tracking, invoice printed state, and packed state, then keeps the previous tracking in the order notes. Use this when you need to buy a fresh replacement label.", 'woo-logistics-plugin' ),
					'markPacked'       => __( 'Mark packed', 'woo-logistics-plugin' ),
					'markingPacked'    => __( 'Marking packed...', 'woo-logistics-plugin' ),
					'packed'           => __( 'Packed', 'woo-logistics-plugin' ),
					'failedPacked'     => __( 'Failed to mark packed.', 'woo-logistics-plugin' ),
					'markShipped'      => __( 'Mark shipped', 'woo-logistics-plugin' ),
					'markingShipped'   => __( 'Marking shipped...', 'woo-logistics-plugin' ),
					'shipped'          => __( 'Shipped', 'woo-logistics-plugin' ),
					'failedShipped'    => __( 'Failed to mark shipped.', 'woo-logistics-plugin' ),
					'confirmShipped'   => __( 'Mark this order shipped and move it to In transit? This does not complete the WooCommerce order.', 'woo-logistics-plugin' ),
					'markDelivered'    => __( 'Mark delivered', 'woo-logistics-plugin' ),
					'markingDelivered' => __( 'Marking delivered...', 'woo-logistics-plugin' ),
					'delivered'        => __( 'Delivered', 'woo-logistics-plugin' ),
					'failedDelivered'  => __( 'Failed to mark delivered.', 'woo-logistics-plugin' ),
					'confirmDelivered' => __( 'Mark this order delivered and complete it in WooCommerce?', 'woo-logistics-plugin' ),
					'cancelOrder'      => __( 'Cancel order', 'woo-logistics-plugin' ),
					'cancellingOrder'  => __( 'Cancelling...', 'woo-logistics-plugin' ),
					'cancelledOrder'   => __( 'Order cancelled.', 'woo-logistics-plugin' ),
					'failedCancel'     => __( 'Failed to cancel order.', 'woo-logistics-plugin' ),
					'confirmCancel'    => __( 'Cancel this WooCommerce order silently? No notification will be sent. This removes it from the active logistics board.', 'woo-logistics-plugin' ),
					'confirmMarkPackedTitle' => __( 'Mark order as packed?', 'woo-logistics-plugin' ),
					'confirmMarkPackedContinue' => __( 'Mark packed', 'woo-logistics-plugin' ),
					'confirmMarkPackedCancel' => __( 'Cancel', 'woo-logistics-plugin' ),
					'alcoholPadsReminder' => __( 'Fulfillment reminder: add 20 complimentary alcohol pads.', 'woo-logistics-plugin' ),
					'confirmMarkPacked' => __( "Mark this order as packed?\n\nOnly continue if the items were checked, packed, and the packing slip/reconstitution guide was included.", 'woo-logistics-plugin' ),
					'confirmMarkPackedNoInvoiceNoLabel' => __( "Packing slip + reconstitution guide has not been marked printed, and this order does not have a shipping label yet.\n\nIf you continue, this order will be marked packed and the invoice/guide will be marked printed.", 'woo-logistics-plugin' ),
					'confirmMarkPackedNoInvoice' => __( "Packing slip + reconstitution guide has not been marked printed yet.\n\nIf you continue, this order will be marked packed and the invoice/guide will be marked printed.", 'woo-logistics-plugin' ),
					'confirmMarkPackedNoLabel' => __( "This order does not have a shipping label yet.\n\nMark as packed anyway?", 'woo-logistics-plugin' ),
					'confirmLeaveUnpackedTitle' => __( 'Order not marked packed', 'woo-logistics-plugin' ),
					'confirmLeaveUnpacked' => __( "Did you forget to mark this order packed?\n\nThis order is still showing as unpacked. Leave this order anyway?", 'woo-logistics-plugin' ),
					'confirmLeaveUnpackedContinue' => __( 'Leave anyway', 'woo-logistics-plugin' ),
					'confirmLeaveUnpackedCancel' => __( 'Go back', 'woo-logistics-plugin' ),
					'confirmPackedInvoice' => __( 'This order is already marked packed. Are you sure you want to print the invoice/guide?', 'woo-logistics-plugin' ),
					'confirmPackedLabel' => __( 'This order is already marked packed. Are you sure you want to print or create a label?', 'woo-logistics-plugin' ),
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
						'wholesaleSending' => __( 'Sending wholesale update...', 'woo-logistics-plugin' ),
						'wholesaleSent'    => __( 'Wholesale update sent.', 'woo-logistics-plugin' ),
						'wholesaleFailed'  => __( 'Failed to send wholesale update.', 'woo-logistics-plugin' ),
						'sendWholesaleUpdate' => __( 'Send wholesale update', 'woo-logistics-plugin' ),
						'confirmWholesaleShippingDetails' => __( "Ask this wholesale customer for shipping name, shipping address, and phone number?", 'woo-logistics-plugin' ),
						'confirmWholesalePaymentInstructions' => __( "Send the wholesale quote/payment-instructions email to this customer?", 'woo-logistics-plugin' ),
						'confirmWholesalePaymentReceived' => __( "Confirm payment received and email the customer that fulfillment is starting?", 'woo-logistics-plugin' ),
						'confirmWholesaleHub' => __( "Email the customer that fulfillment is in progress?", 'woo-logistics-plugin' ),
						'confirmWholesaleInspected' => __( "Email the customer that tracking is ready?", 'woo-logistics-plugin' ),
						'confirmWholesaleDelivered' => __( "Mark this wholesale order delivered, complete WooCommerce, and email the customer?", 'woo-logistics-plugin' ),
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
		$orders        = array_values(
			array_filter(
				$orders,
				function ( $order ): bool {
					return $order instanceof WC_Order
						&& (
							! $order->has_status( 'pending' )
							|| $this->is_wholesale_order( $order )
							|| in_array( 'pending', WLP_Settings::eligible_statuses(), true )
						);
				}
			)
		);
		$buckets       = $this->bucket_orders( WLP_Split_Shipments::fulfillment_cards( $orders ) );
		$current_state = $this->current_logistics_state();
		$current_order_ids = array();

		foreach ( $buckets[ $current_state ] ?? array() as $order ) {
			if ( $order instanceof WC_Order ) {
				$current_order_ids = array_merge( $current_order_ids, WLP_Split_Shipments::joined_ids( $order ) );
			}
		}

		?>
		<div class="wrap wlp-wrap">
			<div class="wlp-header">
				<div>
					<h1><?php echo esc_html__( 'Logistics', 'woo-logistics-plugin' ); ?></h1>
					<p><?php echo esc_html__( 'Create Canada Post labels, print labels, and keep tracking metadata on WooCommerce orders.', 'woo-logistics-plugin' ); ?></p>
				</div>
				<div class="wlp-header__actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wlp-contract-manifests' ) ); ?>">Electronic manifests</a>
					<button class="button" type="button" data-wlp-open-pricing><?php echo esc_html__( 'Supplier costs + pricing', 'woo-logistics-plugin' ); ?></button>
					<button class="button button-primary wlp-start-batch" type="button" data-wlp-start-unpacked-batch><?php echo esc_html__( 'Prepare unpacked batch', 'woo-logistics-plugin' ); ?></button>
					<details class="wlp-toolbar-menu">
						<summary class="button"><?php echo esc_html__( 'Print batch', 'woo-logistics-plugin' ); ?></summary>
						<div class="wlp-toolbar-menu__panel">
							<button class="button button-primary" type="button" data-wlp-print-batch><?php echo esc_html__( 'Labels + invoices', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-manual-counter>Manual counter labels</button>
							<button class="button" type="button" data-wlp-bulk-print-selected><?php echo esc_html__( 'Labels only', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-bulk-invoice-selected><?php echo esc_html__( 'Invoices only', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-bulk-invoice-view data-order-ids="<?php echo esc_attr( implode( ',', $current_order_ids ) ); ?>"><?php echo esc_html__( 'All invoices in tab', 'woo-logistics-plugin' ); ?></button>
						</div>
					</details>
					<details class="wlp-toolbar-menu">
						<summary class="button"><?php echo esc_html__( 'Select orders', 'woo-logistics-plugin' ); ?></summary>
						<div class="wlp-toolbar-menu__panel" aria-label="<?php echo esc_attr__( 'Order selection tools', 'woo-logistics-plugin' ); ?>">
							<button class="button" type="button" data-wlp-select-all><?php echo esc_html__( 'All', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-select-labeled><?php echo esc_html__( 'Labeled', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-select-packed><?php echo esc_html__( 'Packed', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-select-unpacked><?php echo esc_html__( 'Unpacked', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-select-unpacked-labeled><?php echo esc_html__( 'Unpacked + labeled', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-select-packed-labeled><?php echo esc_html__( 'Packed + labeled', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-clear-selection><?php echo esc_html__( 'Clear selection', 'woo-logistics-plugin' ); ?></button>
						</div>
					</details>
					<details class="wlp-toolbar-menu">
						<summary class="button" aria-label="<?php echo esc_attr__( 'More logistics actions', 'woo-logistics-plugin' ); ?>">&#8943;</summary>
						<div class="wlp-toolbar-menu__panel is-compact">
							<button class="button" type="button" data-wlp-quick-buy-selected><?php echo esc_html__( 'Buy labels for selection', 'woo-logistics-plugin' ); ?></button>
							<?php if ( $this->can_create_test_orders() ) : ?>
								<button class="button" type="button" data-wlp-create-dummy-order><?php echo esc_html__( 'Create test order', 'woo-logistics-plugin' ); ?></button>
							<?php endif; ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wlp-logistics&view=settings' ) ); ?>"><?php echo esc_html__( 'Settings', 'woo-logistics-plugin' ); ?></a>
						</div>
					</details>
					<button class="button" type="button" data-wlp-batch-sourcing hidden disabled><?php echo esc_html__( 'Batch supplier request', 'woo-logistics-plugin' ); ?></button>
					<span class="wlp-selection-count" data-wlp-selection-count><?php echo esc_html__( '0 selected', 'woo-logistics-plugin' ); ?></span>
				</div>
			</div>
			<div class="wlp-bulk-status" data-wlp-bulk-status aria-live="polite"></div>
			<?php $this->render_logistics_tabs( $buckets, $current_state ); ?>
			<?php $this->render_wholesale_workload_filters( $buckets[ $current_state ] ?? array(), $current_state ); ?>
			<?php $this->render_prelabel_summary( $buckets[ $current_state ] ?? array(), $current_state ); ?>
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
		<?php $this->render_pricing_comparison_modal(); ?>
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
					<?php $this->field_text( WLP_Settings::OPTION_CONTRACT, 'Commercial agreement / contract number (required)' ); ?>
					<?php $this->field_checkbox( WLP_Settings::OPTION_PICKUP, 'Provisional label shipping point: pickup at origin', 'no', 'Used when creating labels. Choose the actual pickup or drop-off location on Shipping manifests before transmission.' ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_DEPOSIT_POSTAL, 'Provisional label drop-off postal code (required when pickup above is off)' ); ?>
					<tr><td colspan="2">Commercial labels require a confirmed electronic manifest before handoff. Domestic paper manifests are optional when a parcel can be scanned. Billing uses your Canada Post agreement and follows a physical scan.</td></tr>
					<?php $this->field_checkbox( WLP_Settings::OPTION_NOTIFY, __( 'Send Canada Post customer notifications', 'woo-logistics-plugin' ), 'yes' ); ?>
					<?php $this->field_checkbox( WLP_Settings::OPTION_SIGNATURE, __( 'Require signature on Canada Post labels', 'woo-logistics-plugin' ), 'no', __( 'When enabled, rates and purchased labels include Canada Post option SO - Signature. Priority may include this at no extra charge; other services may price it as an option.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_service_select( WLP_Settings::OPTION_DEFAULT_SERVICE, __( 'Default service for quick buy', 'woo-logistics-plugin' ), __( 'Quick buy first matches the customer-selected shipping method when possible. If that service is unavailable, it uses this default service, then the cheapest returned rate.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_preset_select( WLP_Settings::OPTION_DEFAULT_PRESET, __( 'Default box for quick buy', 'woo-logistics-plugin' ), __( 'Quick buy uses this package preset unless the order contains 6 or more peptide vials, or 4 or more peptide vials plus BAC water, which automatically uses the Medium Box preset.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_checkbox( WLP_Settings::OPTION_HIDE_REGULAR, __( 'Remove Regular Parcel as a label option', 'woo-logistics-plugin' ), 'no', __( 'When enabled, Regular Parcel is hidden from create-label choices and quick buy will not select it.', 'woo-logistics-plugin' ) ); ?>
				</table>

				<h2><?php echo esc_html__( 'Customer Order Notes', 'woo-logistics-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->field_checkbox( WLP_Settings::OPTION_CUSTOMER_NOTE, __( 'Send customer order note after label creation', 'woo-logistics-plugin' ), 'yes', __( 'Adds a customer-visible WooCommerce order note after a Canada Post label is purchased. WooCommerce sends its standard customer note email when enabled.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_textarea( WLP_Settings::OPTION_NOTE_TEMPLATE, __( 'Customer note template', 'woo-logistics-plugin' ), __( 'Available placeholders: {first_name}, {order_number}, {service_name}, {service_label}, {tracking_number}, {tracking_number_raw}, {tracking_url}, {estimated_delivery_date}, {guaranteed_delivery_date}.', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_textarea( WLP_Settings::OPTION_RETURN_TEMPLATE, __( 'Return label note template', 'woo-logistics-plugin' ), __( 'Available placeholders: {first_name}, {order_number}, {return_tracking_number}, {return_tracking_raw}, {return_tracking_url}, {return_label_url}, {return_service_name}, {return_address}.', 'woo-logistics-plugin' ) ); ?>
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

				<h2><?php echo esc_html__( 'Return Address', 'woo-logistics-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->field_text( WLP_Settings::OPTION_RETURN_NAME, __( 'Return contact name', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_RETURN_COMPANY, __( 'Return company', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_RETURN_EMAIL, __( 'Return email', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_phone( WLP_Settings::OPTION_RETURN_PHONE, __( 'Return phone', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_RETURN_ADDR_1, __( 'Return address line 1', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_RETURN_ADDR_2, __( 'Return address line 2', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_RETURN_CITY, __( 'Return city', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_province( WLP_Settings::OPTION_RETURN_PROV, __( 'Return province', 'woo-logistics-plugin' ) ); ?>
					<?php $this->field_text( WLP_Settings::OPTION_RETURN_POSTAL, __( 'Return postal code', 'woo-logistics-plugin' ) ); ?>
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
		$return_meta = WLP_Order_Logistics::read_return( $order );
		$invoice_printed = '' !== $meta['invoice_printed_at'];
		$is_packed       = '' !== $meta['packed_at'];
		$has_label       = WLP_Order_Logistics::has_label( $order );
		$alcohol_pads_due = $this->order_qualifies_for_alcohol_pads( $order );
		$delivery_protection = $this->order_delivery_protection_defaults( $order );
		?>
		<div data-wlp-order-box data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-has-label="<?php echo esc_attr( $has_label ? 'yes' : 'no' ); ?>" data-invoice-printed="<?php echo esc_attr( $invoice_printed ? 'yes' : 'no' ); ?>" data-packed="<?php echo esc_attr( $is_packed ? 'yes' : 'no' ); ?>" data-alcohol-pads-due="<?php echo esc_attr( $alcohol_pads_due ? 'yes' : 'no' ); ?>" data-wlp-signature-default="<?php echo esc_attr( $delivery_protection['signature'] ? 'yes' : 'no' ); ?>" data-wlp-card-for-pickup-default="<?php echo esc_attr( $delivery_protection['card_for_pickup'] ? 'yes' : 'no' ); ?>">
			<?php if ( $alcohol_pads_due ) : ?>
				<div class="wlp-fulfillment-reminder"><?php echo esc_html__( 'Add 20 complimentary alcohol pads', 'woo-logistics-plugin' ); ?></div>
			<?php endif; ?>
			<p><strong><?php echo esc_html__( 'Tracking', 'woo-logistics-plugin' ); ?>:</strong> <span data-wlp-card-tracking><?php echo esc_html( $meta['tracking_number'] ?: '-' ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Service', 'woo-logistics-plugin' ); ?>:</strong> <span data-wlp-card-service><?php echo esc_html( $meta['service_name'] ?: '-' ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Invoice', 'woo-logistics-plugin' ); ?>:</strong> <span data-wlp-card-invoice><?php echo esc_html( $invoice_printed ? __( 'Printed', 'woo-logistics-plugin' ) : __( 'Not printed', 'woo-logistics-plugin' ) ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Packed', 'woo-logistics-plugin' ); ?>:</strong> <span data-wlp-card-packed><?php echo esc_html( $is_packed ? __( 'Yes', 'woo-logistics-plugin' ) : __( 'No', 'woo-logistics-plugin' ) ); ?></span></p>
			<p><a class="button" target="_blank" data-wlp-print-invoice href="<?php echo esc_url( $this->invoice_url( $order ) ); ?>"><?php echo esc_html__( 'Print invoice + guide', 'woo-logistics-plugin' ); ?></a></p>
			<?php if ( $meta['label_artifact_url'] ) : ?>
				<p><a class="button" target="_blank" data-wlp-print-label href="<?php echo esc_url( $this->print_url( $order ) ); ?>"><?php echo esc_html__( 'Print label', 'woo-logistics-plugin' ); ?></a></p>
				<p><button class="button" type="button" data-wlp-send-customer-note data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Send customer note', 'woo-logistics-plugin' ); ?></button></p>
				<?php endif; ?>
			<p><button class="button" type="button" data-wlp-prepare-reship data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Prepare reship', 'woo-logistics-plugin' ); ?></button></p>
			<p><button class="button" type="button" data-wlp-mark-packed data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" <?php disabled( $is_packed ); ?>><?php echo esc_html( $is_packed ? __( 'Packed', 'woo-logistics-plugin' ) : __( 'Mark packed', 'woo-logistics-plugin' ) ); ?></button></p>
			<?php if ( '' === $meta['shipped_at'] && '' === $meta['delivered_at'] ) : ?>
				<p><button class="button" type="button" data-wlp-mark-shipped data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Mark shipped', 'woo-logistics-plugin' ); ?></button></p>
			<?php endif; ?>
			<p><button class="button" type="button" data-wlp-mark-delivered data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Mark delivered', 'woo-logistics-plugin' ); ?></button></p>
			<p><button class="button wlp-button-danger" type="button" data-wlp-cancel-order data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" aria-label="<?php echo esc_attr__( 'Cancel order', 'woo-logistics-plugin' ); ?>">&times; <?php echo esc_html__( 'Cancel order', 'woo-logistics-plugin' ); ?></button></p>
			<p><button class="button" type="button" data-wlp-create-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html( WLP_Order_Logistics::has_label( $order ) ? __( 'View label options', 'woo-logistics-plugin' ) : __( 'Create label', 'woo-logistics-plugin' ) ); ?></button></p>
			<p><button class="button" type="button" data-wlp-create-return-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Create return label', 'woo-logistics-plugin' ); ?></button></p>
			<?php if ( WLP_Order_Logistics::has_return_label( $order ) ) : ?>
				<p><strong><?php echo esc_html__( 'Return tracking', 'woo-logistics-plugin' ); ?>:</strong> <span data-wlp-card-return-tracking><?php echo wp_kses_post( '' !== $return_meta['tracking_url'] ? '<a target="_blank" href="' . esc_url( $return_meta['tracking_url'] ) . '">' . esc_html( $return_meta['tracking_number'] ) . '</a>' : esc_html( $return_meta['tracking_number'] ) ); ?></span></p>
				<p><a class="button" target="_blank" data-wlp-print-return-label href="<?php echo esc_url( $this->return_print_url( $order ) ); ?>"><?php echo esc_html__( 'Print return label', 'woo-logistics-plugin' ); ?></a></p>
				<p><button class="button" type="button" data-wlp-send-return-note data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Email return label + instructions', 'woo-logistics-plugin' ); ?></button></p>
			<?php endif; ?>
			<?php $this->render_drawer(); ?>
		</div>
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
					'rates'  => $this->wholesale_customer_delivery_rate_estimates( $order, $this->client->get_rates( $order, $preset, $signature_required ) ),
				);
			} catch ( Throwable $error ) {
				$payload[] = array(
					'preset' => $preset,
					'rates'  => array(),
					'error'  => $error->getMessage(),
				);
			}
		}

		try {
			$custom_package = $this->ajax_custom_package( false );
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		}

		if ( null !== $custom_package ) {
			try {
				$payload[] = array(
					'preset' => $custom_package,
					'rates'  => $this->wholesale_customer_delivery_rate_estimates( $order, $this->client->get_rates( $order, $custom_package, $signature_required ) ),
				);
			} catch ( Throwable $error ) {
				$payload[] = array(
					'preset' => $custom_package,
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
				'orderShipping'     => $this->order_shipping_payload( $order ),
				'packageSuggestion' => $this->package_suggestion_payload( $order ),
				'suggestedRate'     => $this->suggested_rate_payload( $payload, $order ),
				'signatureRequired' => $signature_required ? 'yes' : 'no',
			)
		);
	}

	/**
	 * AJAX: creates a Canada Post label for the selected order.
	 */
	public function ajax_create_label(): void {
		$order              = $this->ajax_order();
		$is_wholesale       = $this->is_wholesale_order( $order );
		$preset_id          = isset( $_POST['presetId'] ) ? sanitize_text_field( wp_unslash( $_POST['presetId'] ) ) : '';
		$service_code       = isset( $_POST['serviceCode'] ) ? sanitize_text_field( wp_unslash( $_POST['serviceCode'] ) ) : '';
		$override           = isset( $_POST['override'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['override'] ) );
		$signature_required = $this->ajax_signature_required();
		$card_for_pickup    = $this->ajax_card_for_pickup();

		if ( '' === $preset_id || '' === $service_code ) {
			wp_send_json_error( array( 'message' => __( 'Package preset and service are required.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( WLP_Order_Logistics::has_label( $order ) ) {
			wp_send_json_error(
				array(
					'code'     => 'label_exists',
					'message'  => __( 'This order already has a label. Reprint it, void an unused label in Shipping manifests, or use Prepare for reship after handoff.', 'woo-logistics-plugin' ),
					'printUrl' => $this->print_url( $order ),
				),
				409
			);
		}

		try {
			$preset        = 'custom-box' === $preset_id ? $this->ajax_custom_package( true ) : $this->client->find_preset( $preset_id );
			$rates         = $this->client->get_rates( $order, $preset, $signature_required );
			$selected_rate = $this->find_rate( $rates, $service_code );

			if ( empty( $selected_rate ) ) {
				throw new RuntimeException( __( 'Selected service is not available for this order and package.', 'woo-logistics-plugin' ) );
			}

			$selected_rate['expected_delivery_date'] = $this->wholesale_customer_delivery_estimate_date( $order, (string) ( $selected_rate['expected_delivery_date'] ?? '' ) );

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
					'shipped_at'             => ! $is_wholesale && 'completed' === $order->get_status() ? gmdate( 'c' ) : null,
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
	 * AJAX: fetches return-label rates for each preset.
	 */
	public function ajax_get_return_rates(): void {
		$order              = $this->ajax_order();
		$signature_required = $this->ajax_signature_required();
		$payload            = array();

		foreach ( $this->client->get_presets() as $preset ) {
			try {
				$payload[] = array(
					'preset' => $preset,
					'rates'  => $this->client->get_return_rates( $order, $preset, $signature_required ),
				);
			} catch ( Throwable $error ) {
				$payload[] = array(
					'preset' => $preset,
					'rates'  => array(),
					'error'  => $error->getMessage(),
				);
			}
		}

		try {
			$custom_package = $this->ajax_custom_package( false );
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		}

		if ( null !== $custom_package ) {
			try {
				$payload[] = array(
					'preset' => $custom_package,
					'rates'  => $this->client->get_return_rates( $order, $custom_package, $signature_required ),
				);
			} catch ( Throwable $error ) {
				$payload[] = array(
					'preset' => $custom_package,
					'rates'  => array(),
					'error'  => $error->getMessage(),
				);
			}
		}

		$package_suggestion = $this->return_package_suggestion_payload( $order );

		wp_send_json_success(
			array(
				'orderId'           => $order->get_id(),
				'hasLabel'          => WLP_Order_Logistics::has_return_label( $order ),
				'printUrl'          => $this->return_print_url( $order ),
				'services'          => $this->client->get_services(),
				'presets'           => $payload,
				'packageSuggestion' => $package_suggestion,
				'suggestedRate'     => $this->suggested_rate_payload( $payload, $order, (string) ( $package_suggestion['id'] ?? '' ) ),
				'returnAddress'     => $this->return_address_payload(),
				'signatureRequired' => $signature_required ? 'yes' : 'no',
			)
		);
	}

	/**
	 * AJAX: creates a Canada Post return label for the selected order.
	 */
	public function ajax_create_return_label(): void {
		$order              = $this->ajax_order();
		$preset_id          = isset( $_POST['presetId'] ) ? sanitize_text_field( wp_unslash( $_POST['presetId'] ) ) : '';
		$service_code       = isset( $_POST['serviceCode'] ) ? sanitize_text_field( wp_unslash( $_POST['serviceCode'] ) ) : '';
		$override           = isset( $_POST['override'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['override'] ) );
		$signature_required = $this->ajax_signature_required();

		if ( '' === $preset_id || '' === $service_code ) {
			wp_send_json_error( array( 'message' => __( 'Package preset and service are required.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( WLP_Order_Logistics::has_return_label( $order ) && ! $override ) {
			wp_send_json_error(
				array(
					'code'     => 'return_label_exists',
					'message'  => __( 'This order already has a return label. Reprint it or explicitly buy a replacement return label.', 'woo-logistics-plugin' ),
					'printUrl' => $this->return_print_url( $order ),
				),
				409
			);
		}

		try {
			$preset        = 'custom-box' === $preset_id ? $this->ajax_custom_package( true ) : $this->client->find_preset( $preset_id );
			$rates         = $this->client->get_return_rates( $order, $preset, $signature_required );
			$selected_rate = $this->find_rate( $rates, $service_code );

			if ( empty( $selected_rate ) ) {
				throw new RuntimeException( __( 'Selected service is not available for this return label and package.', 'woo-logistics-plugin' ) );
			}

			$shipment_weight = $this->client->shipment_weight( $order, $preset );
			$shipment        = $this->client->create_return_shipment( $order, $preset, $service_code, $signature_required );

			WLP_Order_Logistics::write_return_label(
				$order,
				array(
					'label_created_at'       => gmdate( 'c' ),
					'label_artifact_url'     => $shipment['label_artifact_url'],
					'tracking_number'        => $shipment['tracking_number'],
					'tracking_url'           => $shipment['tracking_url'],
					'service_code'           => $service_code,
					'service_name'           => $this->client->service_name( $service_code ),
					'shipping_cost'          => $selected_rate['due'] ?? '',
					'shipment_id'            => $shipment['shipment_id'],
					'expected_delivery_date' => $selected_rate['expected_delivery_date'] ?? '',
					'shipment_weight_kg'     => $shipment_weight,
				)
			);

			wp_send_json_success(
				array(
					'shipment' => $shipment,
					'printUrl' => $this->return_print_url( $order ),
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
	 * AJAX: buys a label using the order-selected service and package rules.
	 */
	public function ajax_quick_buy_preview(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woo-logistics-plugin' ) ), 403 );
		}

		$order_ids = isset( $_POST['orderIds'] ) ? sanitize_text_field( wp_unslash( $_POST['orderIds'] ) ) : '';
		$preset_id = isset( $_POST['presetId'] ) ? sanitize_text_field( wp_unslash( $_POST['presetId'] ) ) : '';
		$thresholds = isset( $_POST['thresholds'] ) ? sanitize_text_field( wp_unslash( $_POST['thresholds'] ) ) : '1,10';
		$ids = array_values(
			array_filter(
				array_map( 'absint', explode( ',', $order_ids ) )
			)
		);
		$limits = array_values(
			array_unique(
				array_map(
					static function ( string $value ): float {
						return min( 50.0, max( 0.0, is_numeric( $value ) ? (float) $value : 0.0 ) );
					},
					array_filter( array_map( 'trim', explode( ',', $thresholds ) ), 'strlen' )
				)
			)
		);

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one order.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( empty( $limits ) ) {
			$limits = array( 1.0 );
		}

		$summary = array();
		foreach ( $limits as $limit ) {
			$summary[ number_format( $limit, 2, '.', '' ) ] = array(
				'threshold'        => $limit,
				'ordersChecked'    => 0,
				'upgradedOrders'   => 0,
				'daysSaved'        => 0,
				'extraCost'        => 0.0,
				'unavailableCount' => 0,
				'orders'           => array(),
			);
		}

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || WLP_Order_Logistics::has_label( $order ) ) {
				continue;
			}

			try {
				$delivery_protection = $this->order_delivery_protection_defaults( $order );
				$preset = '' !== $preset_id ? $this->client->find_preset( $preset_id ) : $this->quick_buy_preset( $order );
				if ( ! is_array( $preset ) ) {
					throw new RuntimeException( __( 'No package preset available.', 'woo-logistics-plugin' ) );
				}

				$rates = $this->client->get_rates( $order, $preset, $delivery_protection['signature'] );
				$base_rate = $this->base_quick_buy_rate( $rates, $order );
				$xpresspost = $this->find_rate( $rates, 'DOM.XP' );
				$base_days = $this->rate_transit_days( $base_rate );
				$xp_days = $this->rate_transit_days( $xpresspost );
				$extra_cost = ( is_numeric( $base_rate['due'] ?? null ) && is_numeric( $xpresspost['due'] ?? null ) )
					? max( 0.0, (float) $xpresspost['due'] - (float) $base_rate['due'] )
					: null;
				$days_saved = ( null !== $base_days && null !== $xp_days ) ? max( 0, $base_days - $xp_days ) : 0;

				foreach ( $summary as $key => $bucket ) {
					$summary[ $key ]['ordersChecked']++;
					$upgraded = ! empty( $base_rate )
						&& ! empty( $xpresspost )
						&& 'DOM.XP' !== ( $base_rate['service_code'] ?? '' )
						&& null !== $extra_cost
						&& $this->xpresspost_is_worth_upgrade( $base_rate, $xpresspost, (float) $bucket['threshold'] );

					if ( $upgraded ) {
						$summary[ $key ]['upgradedOrders']++;
						$summary[ $key ]['daysSaved'] += $days_saved;
						$summary[ $key ]['extraCost'] += $extra_cost;
						$summary[ $key ]['orders'][] = array(
							'orderId'     => $order->get_id(),
							'orderNumber' => $order->get_order_number(),
							'baseService' => (string) ( $base_rate['service_name'] ?? $this->client->service_name( (string) ( $base_rate['service_code'] ?? '' ) ) ),
							'baseCost'    => is_numeric( $base_rate['due'] ?? null ) ? (float) $base_rate['due'] : null,
							'baseDays'    => $base_days,
							'xpCost'      => is_numeric( $xpresspost['due'] ?? null ) ? (float) $xpresspost['due'] : null,
							'xpDays'      => $xp_days,
							'extraCost'   => $extra_cost,
							'daysSaved'   => $days_saved,
						);
					}
				}
			} catch ( Throwable $error ) {
				foreach ( $summary as $key => $bucket ) {
					$summary[ $key ]['unavailableCount']++;
				}
			}
		}

		wp_send_json_success(
			array(
				'summary' => array_values( $summary ),
			)
		);
	}

	/**
	 * AJAX: buys a label using the order-selected service and package rules.
	 */
	public function ajax_quick_buy_label(): void {
		$order        = $this->ajax_order();
		$preset_id    = isset( $_POST['presetId'] ) ? sanitize_text_field( wp_unslash( $_POST['presetId'] ) ) : '';
		$service_code = isset( $_POST['serviceCode'] ) ? sanitize_text_field( wp_unslash( $_POST['serviceCode'] ) ) : '';
		$upgrade_limit = $this->ajax_xpresspost_upgrade_limit();

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
			$delivery_protection = $this->order_delivery_protection_defaults( $order );
			$preset = '' !== $preset_id ? $this->client->find_preset( $preset_id ) : $this->quick_buy_preset( $order );

			if ( ! is_array( $preset ) ) {
				throw new RuntimeException( __( 'No package presets are configured.', 'woo-logistics-plugin' ) );
			}

			$rates                  = $this->client->get_rates( $order, $preset, $delivery_protection['signature'] );
			$xp_upgrade_days_saved = 0;

			if ( '' !== $service_code ) {
				$rate = $this->find_rate( $rates, $service_code );
			} else {
				$base_rate = $this->base_quick_buy_rate( $rates, $order );
				$rate      = $this->maybe_upgrade_to_xpresspost( $rates, $base_rate, $upgrade_limit );

				if ( ! empty( $base_rate ) && ! empty( $rate ) && 'DOM.XP' !== ( $base_rate['service_code'] ?? '' ) && 'DOM.XP' === ( $rate['service_code'] ?? '' ) ) {
					$base_days = $this->rate_transit_days( $base_rate );
					$xp_days   = $this->rate_transit_days( $rate );

					if ( null !== $base_days && null !== $xp_days ) {
						$xp_upgrade_days_saved = max( 0, $base_days - $xp_days );
					}
				}
			}

			if ( ! $rate || empty( $rate['service_code'] ) ) {
				throw new RuntimeException( __( 'Canada Post returned no rates for quick buy.', 'woo-logistics-plugin' ) );
			}

			$rate['expected_delivery_date'] = $this->wholesale_customer_delivery_estimate_date( $order, (string) ( $rate['expected_delivery_date'] ?? '' ) );

			$service_code    = $rate['service_code'];
			$shipment_weight = $this->client->shipment_weight( $order, $preset );
			$shipment        = $this->client->create_shipment( $order, $preset, $service_code, $delivery_protection['signature'], $delivery_protection['card_for_pickup'] );

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
					'xp_upgrade_days_saved'  => $xp_upgrade_days_saved,
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
	 * AJAX: returns a compact order payload for logistics quick view.
	 */
	public function ajax_quick_view_order(): void {
		$order = $this->ajax_order();
		$meta  = WLP_Order_Logistics::read( $order );
		$return_meta = WLP_Order_Logistics::read_return( $order );
		$is_wholesale = $this->is_wholesale_order( $order );
		$stage        = trim( (string) $order->get_meta( self::WHOLESALE_STAGE_META, true ) );
		$wholesale_products = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
		$wholesale_lines    = $is_wholesale ? $this->wholesale_quote_lines( $order ) : array();
		$wholesale_totals   = $is_wholesale ? $this->wholesale_quote_totals( $order ) : array();

		wp_send_json_success(
			array(
				'orderId'       => $order->get_id(),
				'orderNumber'   => $order->get_order_number(),
				'splitActive'   => WLP_Split_Shipments::active( $order ),
				'status'        => wc_get_order_status_name( $order->get_status() ),
				'total'         => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
				'customer'      => trim( (string) $order->get_formatted_billing_full_name() ),
				'email'         => (string) $order->get_billing_email(),
				'phone'         => (string) $order->get_billing_phone(),
				'shippingName'  => trim( (string) $order->get_formatted_shipping_full_name() ),
				'shippingLines' => $this->quick_view_shipping_lines( $order ),
				'shippingAddress' => array(
					'firstName' => (string) $order->get_shipping_first_name(),
					'lastName'  => (string) $order->get_shipping_last_name(),
					'company'   => (string) $order->get_shipping_company(),
					'address1'  => (string) $order->get_shipping_address_1(),
					'address2'  => (string) $order->get_shipping_address_2(),
					'city'      => (string) $order->get_shipping_city(),
					'state'     => (string) $order->get_shipping_state(),
					'postcode'  => (string) $order->get_shipping_postcode(),
					'country'   => (string) $order->get_shipping_country(),
				),
				'customerNote'  => trim( (string) $order->get_customer_note() ),
				'adminUrl'      => $order->get_edit_order_url(),
				'isWholesale'   => $is_wholesale,
				'wholesale'     => $is_wholesale ? array(
					'products'       => $wholesale_products,
					'lines'          => $wholesale_lines,
					'vialCount'      => $this->wholesale_quote_vial_count( $wholesale_products ),
					'payment'        => trim( (string) $order->get_meta( '_pg_wholesale_payment_option', true ) ),
					'paid'           => $order->is_paid(),
					'release'        => trim( (string) $order->get_meta( '_pg_wholesale_release_option', true ) ),
					'deliveryProvince' => $this->wholesale_delivery_province( $order ),
					'estimatedTotal' => (float) $order->get_meta( '_pg_wholesale_estimated_total', true ) > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_meta( '_pg_wholesale_estimated_total', true ), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ) : '',
					'quoteSubtotal'  => $wholesale_totals['subtotalFormatted'] ?? '',
					'quoteMerchandiseSubtotal' => $wholesale_totals['merchandiseSubtotalFormatted'] ?? '',
					'quoteCredit'    => $wholesale_totals['creditFormatted'] ?? '',
					'quoteCreditAmount' => $wholesale_totals['credit'] ?? 0,
					'quoteTax'       => $wholesale_totals['taxFormatted'] ?? '',
					'quoteTaxAmount' => $wholesale_totals['tax'] ?? 0,
                    'quoteTaxRows'   => $wholesale_totals['taxRows'] ?? array(),
					'quoteTaxLabel'  => $wholesale_totals['taxLabel'] ?? __( 'Estimated tax', 'woo-logistics-plugin' ),
					'quoteTotal'     => $wholesale_totals['totalFormatted'] ?? '',
					'copyText'       => $this->wholesale_quote_copy_text( $order, $wholesale_lines, $wholesale_totals ),
					'nextStep'       => trim( (string) $order->get_meta( '_wlp_wholesale_next_step', true ) ),
					'stage'          => $stage,
					'stageLabel'     => $this->wholesale_stage_label( $stage ),
					'stageAt'        => trim( (string) $order->get_meta( self::WHOLESALE_STAGE_AT_META, true ) ),
					'internalNotes'  => trim( (string) $order->get_meta( self::WHOLESALE_NOTES_META, true ) ),
					'internalNotesUpdatedAt' => trim( (string) $order->get_meta( self::WHOLESALE_NOTES_AT_META, true ) ),
					'workflow'       => $this->wholesale_workflow_payload( $order ),
					'sourcing'      => $this->wholesale_sourcing_payload( $order ),
					'profit'         => $this->profit_payload( $order ),
				) : array(),
				'packingCatalog' => $is_wholesale ? $this->wholesale_packing_catalog( $order ) : array(),
				'items'         => $this->quick_view_items( $order ),
				'notes'         => $this->quick_view_notes( $order ),
				'vialCase'      => $this->vial_case_summary( $order ),
				'logistics'     => array(
					'trackingNumber' => $meta['tracking_number'],
					'trackingUrl'    => $this->tracking_url( $meta ),
					'serviceName'    => $meta['service_name'],
					'printUrl'       => $this->print_url( $order ),
					'invoiceUrl'     => $this->invoice_url( $order ),
					'state'          => $this->order_logistics_state( $order ),
					'hasLabel'       => WLP_Order_Logistics::has_label( $order ),
					'invoicePrinted' => '' !== $meta['invoice_printed_at'],
					'packed'         => '' !== $meta['packed_at'],
					'expectedDeliveryDate' => $meta['expected_delivery_date'],
					'expectedDeliveryLabel' => $this->delivery_estimate_label( $meta['expected_delivery_date'] ),
					'deliveryLate'   => $this->delivery_estimate_is_late( $meta['expected_delivery_date'] ),
					'alcoholPadsDue' => $this->order_qualifies_for_alcohol_pads( $order ),
					'returnTrackingNumber' => $return_meta['tracking_number'],
					'returnTrackingUrl'    => $this->tracking_url( $return_meta ),
					'hasReturnLabel'       => WLP_Order_Logistics::has_return_label( $order ),
					'returnPrintUrl'       => $this->return_print_url( $order ),
				),
			)
		);
	}

	/**
	 * AJAX: saves internal-only wholesale revenue and cost inputs.
	 */
	public function ajax_save_profit(): void {
		$order = $this->ajax_order();

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Profit tracking is available for wholesale orders.', 'woo-logistics-plugin' ) ), 400 );
		}

		$fields = array(
			'actualRevenue' => WLP_Meta_Keys::PROFIT_ACTUAL_REVENUE,
			'shippingCost'  => WLP_Meta_Keys::PROFIT_SHIPPING_COST,
			'paymentFees'   => WLP_Meta_Keys::PROFIT_PAYMENT_FEES,
			'taxCost'       => WLP_Meta_Keys::PROFIT_TAX_COST,
			'otherCosts'    => WLP_Meta_Keys::PROFIT_OTHER_COSTS,
		);

		foreach ( $fields as $request_key => $meta_key ) {
			$value = isset( $_POST[ $request_key ] ) ? wc_format_decimal( wp_unslash( $_POST[ $request_key ] ), 2 ) : '0.00';
			$order->update_meta_data( $meta_key, number_format( max( 0.0, (float) $value ), 2, '.', '' ) );
		}

		$supplier_cost_mode = isset( $_POST['supplierCostMode'] ) && 'manual' === sanitize_key( wp_unslash( $_POST['supplierCostMode'] ) ) ? 'manual' : 'auto';
		$order->update_meta_data( WLP_Meta_Keys::PROFIT_SUPPLIER_COST_MODE, $supplier_cost_mode );
		if ( 'manual' === $supplier_cost_mode ) {
			$supplier_cost = isset( $_POST['supplierCost'] ) ? wc_format_decimal( wp_unslash( $_POST['supplierCost'] ), 2 ) : '0.00';
			$order->update_meta_data( WLP_Meta_Keys::PROFIT_SUPPLIER_COST, number_format( max( 0.0, (float) $supplier_cost ), 2, '.', '' ) );
		} else {
			$order->delete_meta_data( WLP_Meta_Keys::PROFIT_SUPPLIER_COST );
		}

		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$order->update_meta_data( WLP_Meta_Keys::PROFIT_NOTES, $notes );
		$order->update_meta_data( WLP_Meta_Keys::PROFIT_UPDATED_AT, current_time( 'mysql' ) );
		$order->save();

		wp_send_json_success(
			array(
				'message' => __( 'Internal profit calculation saved.', 'woo-logistics-plugin' ),
				'profit'  => $this->profit_payload( $order ),
			)
		);
	}

	/**
	 * AJAX: permanently saves supplier costs used by automatic wholesale profit calculations.
	 */
	public function ajax_save_supplier_costs(): void {
		$order = $this->ajax_order();

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Supplier cost tracking is available for wholesale orders.', 'woo-logistics-plugin' ) ), 400 );
		}

		$submitted_json = isset( $_POST['costs'] ) ? wp_unslash( $_POST['costs'] ) : '[]';
		$submitted      = json_decode( (string) $submitted_json, true );

		if ( ! is_array( $submitted ) ) {
			wp_send_json_error( array( 'message' => __( 'Supplier costs could not be read.', 'woo-logistics-plugin' ) ), 400 );
		}

		$allowed = array();
		foreach ( $this->supplier_cost_lines( $order ) as $line ) {
			$allowed[ $line['key'] ] = $line['name'];
		}

		$saved       = $this->saved_supplier_costs();
		$saved_count = 0;

		foreach ( $submitted as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key = isset( $entry['key'] ) ? sanitize_key( (string) $entry['key'] ) : '';
			if ( '' === $key || ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			$cost = isset( $entry['cost'] ) ? (float) wc_format_decimal( (string) $entry['cost'], 2 ) : 0.0;
			if ( $cost <= 0 || $cost > 100000 ) {
				continue;
			}

			$saved[ $key ] = array(
				'label'     => $allowed[ $key ],
				'cost'      => number_format( $cost, 2, '.', '' ),
				'updatedAt' => current_time( 'mysql' ),
			);
			$saved_count++;
		}

		if ( 0 === $saved_count ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least one supplier cost greater than zero.', 'woo-logistics-plugin' ) ), 400 );
		}

		update_option( self::SUPPLIER_COST_OPTION, $saved, false );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of permanent supplier costs saved. */
					_n( '%d permanent supplier cost saved. Automatic profit updated across wholesale orders.', '%d permanent supplier costs saved. Automatic profit updated across wholesale orders.', $saved_count, 'woo-logistics-plugin' ),
					$saved_count
				),
				'profit'  => $this->profit_payload( $order ),
			)
		);
	}

	/**
	 * AJAX: saves searchable store-wide supplier cost catalogue entries.
	 */
	public function ajax_save_supplier_cost_catalog(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woo-logistics-plugin' ) ), 403 );
		}

		check_ajax_referer( 'wlp_logistics', 'nonce' );

		$submitted_json = isset( $_POST['entries'] ) ? wp_unslash( $_POST['entries'] ) : '[]';
		$submitted      = json_decode( (string) $submitted_json, true );

		if ( ! is_array( $submitted ) ) {
			wp_send_json_error( array( 'message' => __( 'Supplier costs could not be read.', 'woo-logistics-plugin' ) ), 400 );
		}

		$saved       = $this->saved_supplier_costs();
		$saved_rows  = array();
		$saved_count = 0;

		foreach ( $submitted as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = isset( $entry['name'] ) ? trim( sanitize_text_field( (string) $entry['name'] ) ) : '';
			$key  = '' !== $name ? strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $name ) ) : '';

			$cost = isset( $entry['cost'] ) ? (float) wc_format_decimal( (string) $entry['cost'], 2 ) : 0.0;
			if ( '' === $key || '' === $name || $cost <= 0 || $cost > 100000 ) {
				continue;
			}

			$updated_at    = current_time( 'mysql' );
			$saved[ $key ] = array(
				'label'     => $name,
				'cost'      => number_format( $cost, 2, '.', '' ),
				'updatedAt' => $updated_at,
			);
			$saved_rows[]  = array(
				'key'       => $key,
				'name'      => $name,
				'cost'      => number_format( $cost, 2, '.', '' ),
				'updatedAt' => $updated_at,
			);
			$saved_count++;
		}

		if ( 0 === $saved_count ) {
			wp_send_json_error( array( 'message' => __( 'Change a supplier cost or add a product first.', 'woo-logistics-plugin' ) ), 400 );
		}

		update_option( self::SUPPLIER_COST_OPTION, $saved, false );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of permanent supplier costs saved. */
					_n( '%d supplier cost saved. Wholesale profit calculations will use it next time they load.', '%d supplier costs saved. Wholesale profit calculations will use them next time they load.', $saved_count, 'woo-logistics-plugin' ),
					$saved_count
				),
				'rows'    => $saved_rows,
			)
		);
	}

	/**
	 * AJAX: saves staff-only context for a wholesale quote without notifying the customer.
	 */
	public function ajax_wholesale_save_notes(): void {
		$order = $this->ajax_order();

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Internal quote notes are available for wholesale orders.', 'woo-logistics-plugin' ) ), 400 );
		}

		$notes = isset( $_POST['notes'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$notes = mb_substr( $notes, 0, 4000 );
		} else {
			$notes = substr( $notes, 0, 4000 );
		}

		$updated_at = '';
		if ( '' === $notes ) {
			$order->delete_meta_data( self::WHOLESALE_NOTES_META );
			$order->delete_meta_data( self::WHOLESALE_NOTES_AT_META );
		} else {
			$updated_at = current_time( 'mysql' );
			$order->update_meta_data( self::WHOLESALE_NOTES_META, $notes );
			$order->update_meta_data( self::WHOLESALE_NOTES_AT_META, $updated_at );
		}

		$order->save();

		wp_send_json_success(
			array(
				'message'   => __( 'Private quote notes saved.', 'woo-logistics-plugin' ),
				'notes'     => $notes,
				'updatedAt' => $updated_at,
			)
		);
	}

	/** Private, quantity-checked source allocations and price-free supplier drafts. */
	private function wholesale_sourcing_payload( WC_Order $order ): array {
		try {
			$catalog = WLP_Split_Shipments::items( $order );
		} catch ( Throwable $error ) {
			return array( 'error' => 'Reconcile the saved product list before assigning fulfillment sources.', 'lines' => array(), 'messages' => array() );
		}
		$stored = $order->get_meta( self::WHOLESALE_SOURCING_META, true );
		$stored = is_array( $stored ) ? $stored : array();
		$allocations = is_array( $stored['allocations'] ?? null ) ? $stored['allocations'] : array();
		$events = is_array( $stored['events'] ?? null ) ? $stored['events'] : array();
		$batch_events = is_array( $stored['batchEvents'] ?? null ) ? $stored['batchEvents'] : array();
		$lines = array();
		$by_source = array( 'jkl' => array(), 'xp' => array(), 'hamilton' => array() );
		foreach ( $catalog as $key => $item ) {
			$quantity = max( 0, (int) ( $item['quantity'] ?? 0 ) );
			$values = array();
			$used = 0;
			foreach ( array_keys( $by_source ) as $source ) {
				$value = max( 0, (int) ( $allocations[ $key ][ $source ] ?? 0 ) );
				$values[ $source ] = $value;
				$used += $value;
				if ( $value > 0 ) {
					$by_source[ $source ][] = '• ' . sanitize_text_field( (string) $item['name'] ) . ' — ' . $value . ' ' . ( $item['unit'] ?? 'vials' );
				}
			}
			$lines[] = array( 'key' => (string) $key, 'name' => sanitize_text_field( (string) $item['name'] ), 'quantity' => $quantity, 'unit' => $item['unit'] ?? 'vials', 'allocations' => $values, 'remaining' => max( 0, $quantity - $used ), 'invalid' => $used > $quantity );
		}
		$progress = WLP_Supplier_Batches::progress( $lines, $stored );
		foreach ( $lines as &$line ) { $line['pendingAllocations'] = $progress['pending'][ $line['key'] ] ?? array(); }
		unset( $line );
		$tokens = array();
		foreach ( array( 'jkl', 'xp' ) as $source ) {
			foreach ( array( 'stock', 'order' ) as $mode ) { $tokens[ $source ][ $mode ] = WLP_Supplier_Batches::token( $lines, $source, $mode ); }
		}
		$messages = array();
		foreach ( array( 'jkl', 'xp' ) as $source ) {
			if ( ! $by_source[ $source ] ) {
				continue;
			}
			$products = implode( "\n", $by_source[ $source ] );
			foreach ( array( 'stock', 'order' ) as $mode ) {
				if ( 'order' === $mode ) {
					$pending_products = array();
					foreach ( $lines as $line ) { $pending_qty = $line['pendingAllocations'][ $source ] ?? 0; if ( $pending_qty > 0 ) { $pending_products[] = '• ' . $line['name'] . ' — ' . $pending_qty . ' ' . $line['unit']; } }
					if ( ! $pending_products ) { continue; }
					$products = implode( "\n", $pending_products );
				} else { $products = implode( "\n", $by_source[ $source ] ); }
				$raw_products = $products;
				$rounded_products = preg_replace_callback( '/— (\d+) vials(?=\n|$)/u', static fn( $match ) => '— ' . (int) ( ceil( (int) $match[1] / 10 ) * 10 ) . ' vials', $products );
				$text = 'stock' === $mode
					? "Hi, can you confirm current availability for:\n\n" . $rounded_products
					: $rounded_products;
				if ( 'xp' === $source && 'order' === $mode ) {
					$text .= "\n\nPlease use priority shipping.";
				}
				$hash = hash( 'sha256', $text );
				$current = null;
				foreach ( array_reverse( $events ) as $event ) {
					if ( ( $event['source'] ?? '' ) === $source && ( $event['mode'] ?? '' ) === $mode && ( $event['hash'] ?? '' ) === $hash ) {
						$current = $event;
						break;
					}
				}
				if ( ! $current && 'stock' === $mode ) {
					$prior_text = "Hi, can you confirm current availability for:\n\n" . $raw_products;
					foreach ( array_reverse( $events ) as $event ) {
						if ( ( $event['source'] ?? '' ) === $source && ( $event['mode'] ?? '' ) === $mode && ( $event['hash'] ?? '' ) === hash( 'sha256', $prior_text ) && ! empty( $event['sentAt'] ) ) { $text = $prior_text; $hash = $event['hash']; $current = $event; break; }
					}
				}
				// Preserve the exact older text when a matching draft was already recorded as sent.
				if ( ! $current && 'order' === $mode ) {
					$prior_texts = array( "Hi, we would like to order:\n\n" . $raw_products . ( 'xp' === $source ? "\n\nAlso we'll give you the extra $50" : '' ) );
					$prior_texts[] = $raw_products . ( 'xp' === $source ? "\n\n+ $59 priority shipping" : '' );
					if ( 'xp' === $source ) {
						$prior_texts[] = $raw_products . "\n\nAlso we'll give you the extra $50";
						$prior_texts[] = "Hi, we would like to order:\n\n" . $raw_products . "\n\nWe'd also like to pay an extra $50 for priority shipping.";
					}
					foreach ( array_reverse( $events ) as $event ) {
						foreach ( $prior_texts as $prior_text ) {
							$prior_hash = hash( 'sha256', $prior_text );
							if ( ( $event['source'] ?? '' ) === $source && ( $event['mode'] ?? '' ) === $mode && ( $event['hash'] ?? '' ) === $prior_hash && ! empty( $event['sentAt'] ) ) {
								$text = $prior_text; $hash = $prior_hash; $current = $event; break 2;
							}
						}
					}
				}
				$messages[] = array( 'source' => $source, 'mode' => $mode, 'text' => $text, 'hash' => $hash, 'sentAt' => $current['sentAt'] ?? '', 'confirmedAt' => $current['confirmedAt'] ?? '', 'receivedAt' => $current['receivedAt'] ?? '', 'canOrder' => $order->is_paid() );
			}
		}
		return array( 'lines' => $lines, 'messages' => $messages, 'hamilton' => $by_source['hamilton'], 'batchEvents' => $batch_events, 'batchTokens' => $tokens, 'supplierProgress' => $progress, 'draftVersion' => 5 );
	}

	/** Saves only source quantities; never changes payment, stock, shipping, or customer status. */
	public function ajax_wholesale_save_sources(): void {
		$order = $this->ajax_order();
		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => 'Wholesale order required.' ), 400 );
		}
		try {
			$catalog = WLP_Split_Shipments::items( $order );
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => 'Reconcile the saved product list first.' ), 409 );
		}
		if ( isset( $_POST['expectedAllocations'] ) ) {
			$expected = json_decode( (string) wp_unslash( $_POST['expectedAllocations'] ), true );
			$stored = $order->get_meta( self::WHOLESALE_SOURCING_META, true );
			$stored = is_array( $stored ) ? $stored : array();
			$current = array();
			foreach ( $catalog as $key => $item ) {
				foreach ( array( 'jkl', 'xp', 'hamilton' ) as $source ) {
					$current[ $key ][ $source ] = max( 0, (int) ( $stored['allocations'][ $key ][ $source ] ?? 0 ) );
				}
			}
			if ( ! is_array( $expected ) || $expected != $current ) {
				wp_send_json_error( array( 'message' => 'Source allocations changed. Reopen this order or batch before saving.' ), 409 );
			}
		}
		$input = json_decode( (string) wp_unslash( $_POST['allocations'] ?? '' ), true );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_keys( $catalog ) ) ) {
			wp_send_json_error( array( 'message' => 'The item list changed. Reopen this order.' ), 409 );
		}
		$saved = array();
		foreach ( $catalog as $key => $item ) {
			$row = $input[ $key ] ?? array();
			if ( ! is_array( $row ) || array_diff( array_keys( $row ), array( 'jkl', 'xp', 'hamilton' ) ) ) {
				wp_send_json_error( array( 'message' => 'Use JKL, XP, or Hamilton quantities only.' ), 400 );
			}
			$values = array();
			foreach ( array( 'jkl', 'xp', 'hamilton' ) as $source ) {
				$value = $row[ $source ] ?? 0;
				if ( ! is_int( $value ) || $value < 0 ) {
					wp_send_json_error( array( 'message' => 'Use whole vial quantities of zero or more.' ), 400 );
				}
				$values[ $source ] = $value;
			}
			if ( array_sum( $values ) > (int) $item['quantity'] ) {
				wp_send_json_error( array( 'message' => 'Assigned quantities exceed an order line.' ), 400 );
			}
			$saved[ $key ] = $values;
		}
		$lock = 'wlp_supplier_batch_' . $order->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) {
			wp_send_json_error( array( 'message' => 'A supplier update is running. Reopen the source plan before saving.' ), 409 );
		}
		try {
			$order->read_meta_data( true );
			$state = $order->get_meta( self::WHOLESALE_SOURCING_META, true );
			$state = is_array( $state ) ? $state : array();
			if ( isset( $expected ) ) {
				$fresh = array();
				foreach ( $catalog as $key => $item ) {
					foreach ( array( 'jkl', 'xp', 'hamilton' ) as $source ) { $fresh[ $key ][ $source ] = max( 0, (int) ( $state['allocations'][ $key ][ $source ] ?? 0 ) ); }
				}
				if ( $expected != $fresh ) { throw new RuntimeException( 'Source allocations changed. Reopen this order or batch before saving.' ); }
			}
			$state['allocations'] = $saved;
			$state['events'] = is_array( $state['events'] ?? null ) ? $state['events'] : array();
			$order->update_meta_data( self::WHOLESALE_SOURCING_META, $state );
			$order->save();
		} catch ( Throwable $error ) {
			delete_option( $lock );
			wp_send_json_error( array( 'message' => $error->getMessage() ), 409 );
			return;
		}
		delete_option( $lock );
		wp_send_json_success( array( 'sourcing' => $this->wholesale_sourcing_payload( $order ) ) );
	}

	/** Records one already-sent batch supplier draft against every included wholesale order. */
	public function ajax_wholesale_batch_supplier_status(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		check_ajax_referer( 'wlp_logistics', 'nonce' );
		$ids = json_decode( (string) wp_unslash( $_POST['orderIds'] ?? '' ), true );
		$source = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$step = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		if ( ! is_array( $ids ) || ! in_array( $source, array( 'jkl', 'xp' ), true ) || ! in_array( $mode, array( 'stock', 'order' ), true ) || ! in_array( $step, array( 'sent', 'confirmed', 'received' ), true ) || ( 'confirmed' === $step && 'stock' !== $mode ) || ( 'received' === $step && 'order' !== $mode ) ) { wp_send_json_error( array( 'message' => 'Invalid batch supplier action.' ), 400 ); }
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ); sort( $ids, SORT_NUMERIC );
		if ( ! $ids ) { wp_send_json_error( array( 'message' => 'Select at least one sourced wholesale order.' ), 400 ); }
		$orders = array();
		foreach ( $ids as $id ) { $order = wc_get_order( $id ); if ( ! $order instanceof WC_Order || ! $this->is_wholesale_order( $order ) ) { wp_send_json_error( array( 'message' => 'The selected order changed. Reopen the batch.' ), 409 ); } $orders[] = $order; }
		if ( 'sent' === $step && '5' !== (string) ( $_POST['draftVersion'] ?? '' ) ) { wp_send_json_error( array( 'message' => 'The supplier draft format changed. Reload Logistics and review the updated request before recording it.' ), 409 ); }
		$tokens = json_decode( (string) wp_unslash( $_POST['expectedTokens'] ?? '{}' ), true );
		try {
			$result = WLP_Supplier_Batches::record( $orders, $source, $mode, $step, is_array( $tokens ) ? $tokens : array(), sanitize_text_field( wp_unslash( $_POST['batchKey'] ?? '' ) ), fn( $order ) => $this->wholesale_sourcing_payload( $order ), fn( $order ) => $this->wholesale_workflow_stage( $order ), 5 );
			$result['orders'] = array_map( fn( $order ) => array( 'orderId' => $order->get_id(), 'workflow' => $this->wholesale_workflow_payload( $order ) ), $orders );
		} catch ( Throwable $error ) { wp_send_json_error( array( 'message' => $error->getMessage() ), 409 ); return; }
		wp_send_json_success( $result );
	}

	/** Records one supplier message action after staff verifies the WhatsApp send or reply. */
	public function ajax_wholesale_supplier_status(): void {
		$order = $this->ajax_order();
		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => 'Wholesale order required.' ), 400 );
		}
		$source = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$step = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		$hash = sanitize_text_field( wp_unslash( $_POST['hash'] ?? '' ) );
		if ( ! in_array( $source, array( 'jkl', 'xp' ), true ) || ! in_array( $mode, array( 'stock', 'order' ), true ) || ! in_array( $step, array( 'sent', 'confirmed', 'received' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid supplier action.' ), 400 );
		}
		if ( ( 'confirmed' === $step && 'stock' !== $mode ) || ( 'received' === $step && 'order' !== $mode ) ) {
			wp_send_json_error( array( 'message' => 'Invalid status for this message.' ), 400 );
		}
		$payload = $this->wholesale_sourcing_payload( $order );
		$matches = array_values( array_filter( $payload['messages'] ?? array(), static fn( $message ) => $message['source'] === $source && $message['mode'] === $mode && hash_equals( $message['hash'], $hash ) ) );
		if ( ! $matches || ( 'order' === $mode && ! $order->is_paid() ) ) {
			wp_send_json_error( array( 'message' => 'The supplier draft changed or payment is not recorded. Reopen the order.' ), 409 );
		}
		$state = $order->get_meta( self::WHOLESALE_SOURCING_META, true );
		$state = is_array( $state ) ? $state : array();
		$events = is_array( $state['events'] ?? null ) ? $state['events'] : array();
		$index = null;
		foreach ( $events as $i => $event ) {
			if ( ( $event['source'] ?? '' ) === $source && ( $event['mode'] ?? '' ) === $mode && ( $event['hash'] ?? '' ) === $hash ) {
				$index = $i;
			}
		}
		if ( null === $index && 'sent' !== $step ) {
			wp_send_json_error( array( 'message' => 'Record the sent message first.' ), 409 );
		}
		if ( null === $index ) {
			$events[] = array( 'source' => $source, 'mode' => $mode, 'hash' => $hash, 'text' => $matches[0]['text'], 'sentAt' => '' );
			$index = count( $events ) - 1;
		}
		$field = array( 'sent' => 'sentAt', 'confirmed' => 'confirmedAt', 'received' => 'receivedAt' )[ $step ];
		if ( 'sent' !== $step && empty( $events[ $index ]['sentAt'] ) ) {
			wp_send_json_error( array( 'message' => 'Record the sent message first.' ), 409 );
		}
		if ( empty( $events[ $index ][ $field ] ) ) {
			$events[ $index ][ $field ] = current_time( 'mysql' );
			$state['events'] = $events;
			$order->update_meta_data( self::WHOLESALE_SOURCING_META, $state );
			$order->save();
		}
		wp_send_json_success( array( 'sourcing' => $this->wholesale_sourcing_payload( $order ) ) );
	}

	/**
	 * Returns the owner-provided supplier cost per 10-vial kit.
	 *
	 * @return array<string, float>
	 */
	private function supplier_cost_catalog(): array {
		$catalog = array(
			'cjc1295withdac5mg'     => 100.0,
			'cjc1295nodac5mg'       => 80.0,
			'hghfragment1761915mg'  => 120.0,
			'igf1lr31mg'            => 180.0,
			'melanotan210mg'        => 90.0,
			'melanotan110mg'        => 90.0,
			'pt14110mg'             => 80.0,
			'tb5005mg'              => 90.0,
			'tb50010mg'             => 130.0,
			'bpc1575mg'             => 80.0,
			'bpc15710mg'            => 110.0,
			'pegmgf5mg'             => 160.0,
			'mgf5mg'                => 80.0,
			'ghrp210mg'             => 90.0,
			'ghrp610mg'             => 90.0,
			'thymosinalpha110mg'    => 140.0,
			'hexarelin5mg'          => 100.0,
			'ipamorelin5mg'         => 50.0,
			'sermorelin5mg'         => 100.0,
			'tesamorelin5mg'        => 120.0,
			'oxytocin10mg'          => 60.0,
			'triptorelinacetate1mg' => 50.0,
			'selank10mg'            => 80.0,
			'dsip5mg'               => 60.0,
			'snap810mg'             => 60.0,
			'epitalon10mg'          => 95.0,
			'semax30mg'             => 150.0,
			'ara29020mg'            => 130.0,
			'nad1000mg10ml'         => 300.0,
			'vip2mg'                => 80.0,
			'kisspeptin10mg'        => 110.0,
			'pinealon20mg'          => 130.0,
			'retatrutide10mg'       => 225.0,
			'retatrutide20mg'       => 410.0,
			'retatrutide30mg'       => 560.0,
			'semaglutide5mg'        => 100.0,
			'tirzepatide5mg'        => 150.0,
			'hmg75iu'               => 120.0,
			'hcg5000iu'             => 150.0,
			'hgh50iu'               => 400.0,
			'ss3125mg'              => 160.0,
			'kpv10mg'               => 90.0,
			'ace0311mg'             => 80.0,
			'll375mg'               => 120.0,
			'5amino1mq30mg'         => 120.0,
			'ghkcu50mg'             => 150.0,
			'motsc10mg'             => 110.0,
			'hgh10iu'               => 90.0,
			'fox0410mg'             => 390.0,
			'bpc15710mgtb50010mg'   => 200.0,
			'bpc15710mgtb50010mgwolverineblend' => 200.0,
		);

		foreach ( $this->saved_supplier_costs() as $key => $entry ) {
			$catalog[ $key ] = (float) $entry['cost'];
		}

		return $catalog;
	}

	/**
	 * Returns normalized, owner-saved supplier costs.
	 *
	 * @return array<string, array{label: string, cost: string, updatedAt: string}>
	 */
	private function saved_supplier_costs(): array {
		$stored = get_option( self::SUPPLIER_COST_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$saved = array();
		foreach ( $stored as $key => $entry ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}

			$cost = isset( $entry['cost'] ) ? (float) $entry['cost'] : 0.0;
			if ( $cost <= 0 || $cost > 100000 ) {
				continue;
			}

			$saved[ $key ] = array(
				'label'     => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : $key,
				'cost'      => number_format( $cost, 2, '.', '' ),
				'updatedAt' => isset( $entry['updatedAt'] ) ? sanitize_text_field( (string) $entry['updatedAt'] ) : '',
			);
		}

		return $saved;
	}

	/**
	 * Returns display names for products with an owner-provided supplier cost.
	 *
	 * @return array<string, string>
	 */
	private function supplier_cost_labels(): array {
		$labels = array(
			'cjc1295withdac5mg'     => 'CJC-1295 with DAC 5mg',
			'cjc1295nodac5mg'       => 'CJC-1295 no DAC 5mg',
			'hghfragment1761915mg'  => 'HGH Fragment 176-191 5mg',
			'igf1lr31mg'            => 'IGF-1 LR3 1mg',
			'melanotan210mg'        => 'Melanotan-2 10mg',
			'melanotan110mg'        => 'Melanotan-1 10mg',
			'pt14110mg'             => 'PT-141 10mg',
			'tb5005mg'              => 'TB-500 5mg',
			'tb50010mg'             => 'TB-500 10mg',
			'bpc1575mg'             => 'BPC-157 5mg',
			'bpc15710mg'            => 'BPC-157 10mg',
			'bpc15710mgtb50010mgwolverineblend' => 'BPC-157 10mg + TB-500 10mg — Wolverine Blend',
			'pegmgf5mg'             => 'PEG-MGF 5mg',
			'mgf5mg'                => 'MGF 5mg',
			'ghrp210mg'             => 'GHRP-2 10mg',
			'ghrp610mg'             => 'GHRP-6 10mg',
			'thymosinalpha110mg'    => 'Thymosin Alpha-1 10mg',
			'hexarelin5mg'          => 'Hexarelin 5mg',
			'ipamorelin5mg'         => 'Ipamorelin 5mg',
			'sermorelin5mg'         => 'Sermorelin 5mg',
			'tesamorelin5mg'        => 'Tesamorelin 5mg',
			'oxytocin10mg'          => 'Oxytocin 10mg',
			'triptorelinacetate1mg' => 'Triptorelin Acetate 1mg',
			'selank10mg'            => 'Selank 10mg',
			'dsip5mg'               => 'DSIP 5mg',
			'snap810mg'             => 'SNAP-8 10mg',
			'epitalon10mg'          => 'Epitalon 10mg',
			'semax30mg'             => 'Semax 30mg',
			'ara29020mg'            => 'ARA-290 20mg',
			'nad1000mg10ml'         => 'NAD+ 1000mg / 10mL',
			'vip2mg'                => 'VIP 2mg',
			'kisspeptin10mg'        => 'Kisspeptin 10mg',
			'pinealon20mg'          => 'Pinealon 20mg',
			'retatrutide10mg'       => 'Retatrutide 10mg',
			'retatrutide20mg'       => 'Retatrutide 20mg',
			'retatrutide30mg'       => 'Retatrutide 30mg',
			'semaglutide5mg'        => 'Semaglutide 5mg',
			'tirzepatide5mg'        => 'Tirzepatide 5mg',
			'hmg75iu'               => 'HMG 75IU',
			'hcg5000iu'             => 'HCG 5000IU',
			'hgh50iu'               => 'HGH 50IU',
			'ss3125mg'              => 'SS-31 25mg',
			'kpv10mg'               => 'KPV 10mg',
			'ace0311mg'             => 'ACE-031 1mg',
			'll375mg'               => 'LL-37 5mg',
			'5amino1mq30mg'         => '5-Amino-1MQ 30mg',
			'ghkcu50mg'             => 'GHK-CU 50mg',
			'motsc10mg'             => 'MOTS-C 10mg',
			'hgh10iu'               => 'HGH 10IU',
			'fox0410mg'             => 'FOX-04 10mg',
		);

		foreach ( $this->saved_supplier_costs() as $key => $entry ) {
			$labels[ $key ] = $entry['label'];
		}

		return $labels;
	}

	/**
	 * Returns the current 30-90 vial wholesale catalog price per 10-vial kit.
	 *
	 * @return array<string, float>
	 */
	private function wholesale_starter_price_catalog(): array {
		return array(
			'5amino1mq30mg' => 245.0, 'motsc10mg' => 235.0,
			'retatrutide10mg' => 360.0, 'retatrutide20mg' => 540.0, 'retatrutide30mg' => 925.0,
			'semaglutide5mg' => 225.0, 'tirzepatide5mg' => 300.0,
			'tb5005mg' => 215.0, 'tb50010mg' => 260.0, 'bpc1575mg' => 205.0, 'bpc15710mg' => 235.0,
			'kpv10mg' => 215.0, 'ghkcu50mg' => 300.0, 'll375mg' => 245.0,
			'bpc15710mgtb50010mgwolverineblend' => 325.0,
			'cjc1295withdac5mg' => 225.0, 'cjc1295nodac5mg' => 205.0,
			'hghfragment1761915mg' => 245.0, 'igf1lr31mg' => 360.0, 'pegmgf5mg' => 320.0,
			'mgf5mg' => 205.0, 'ghrp210mg' => 215.0, 'ghrp610mg' => 215.0,
			'hexarelin5mg' => 225.0, 'ipamorelin5mg' => 175.0, 'sermorelin5mg' => 225.0,
			'tesamorelin5mg' => 245.0, 'hmg75iu' => 245.0, 'hcg5000iu' => 300.0,
			'hgh50iu' => 800.0, 'hgh10iu' => 215.0, 'melanotan210mg' => 215.0,
			'melanotan110mg' => 215.0, 'pt14110mg' => 205.0, 'thymosinalpha110mg' => 280.0,
			'oxytocin10mg' => 185.0, 'triptorelinacetate1mg' => 175.0, 'selank10mg' => 205.0,
			'dsip5mg' => 185.0, 'snap810mg' => 185.0, 'epitalon10mg' => 220.0,
			'semax30mg' => 300.0, 'ara29020mg' => 260.0, 'nad1000mg10ml' => 600.0,
			'vip2mg' => 205.0, 'kisspeptin10mg' => 235.0, 'pinealon20mg' => 260.0,
			'ss3125mg' => 320.0, 'ace0311mg' => 205.0, 'fox0410mg' => 780.0,
		);
	}

	/**
	 * Returns current storefront prices keyed by normalized product name.
	 *
	 * @return array<string, string>
	 */
	private function retail_price_catalog(): array {
		$prices   = array();
		$products = wc_get_products(
			array(
				'limit'  => -1,
				'status' => 'publish',
			)
		);

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$key = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $product->get_name() ) );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation instanceof WC_Product_Variation || '' === $variation->get_price() ) {
						continue;
					}

					$strength = implode( ' ', array_values( $variation->get_variation_attributes() ) );
					$variation_key = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $product->get_name() . ' ' . $strength ) );
					$prices[ $variation_key ] = html_entity_decode( wp_strip_all_tags( wc_price( (float) $variation->get_price(), array( 'currency' => get_woocommerce_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
				}
				continue;
			}

			if ( '' === $key || '' === $product->get_price() ) {
				continue;
			}

			$prices[ $key ] = html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price(), array( 'currency' => get_woocommerce_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
		}

		$aliases = array(
			'bpc1575mg' => 'bpc1575mg',
			'bpc15710mg' => 'bpc15710mg',
			'retatrutidereta10mg' => 'retatrutide10mg',
			'retatrutidereta20mg' => 'retatrutide20mg',
			'retatrutidereta30mg' => 'retatrutide30mg',
			'tesamorelin5mg' => 'tesamorelin5mg',
			'pt141' => 'pt14110mg',
			'cjc1295nodac' => 'cjc1295nodac5mg',
			'wolverineresearchblend20mg' => 'bpc15710mgtb50010mgwolverineblend',
			'klow80advancedrecoveryresearchblend' => 'klowkpv10mgghkcu50mgtb50010mgbpc15710mg',
		);

		foreach ( $aliases as $source_key => $target_key ) {
			if ( isset( $prices[ $source_key ] ) ) {
				$prices[ $target_key ] = $prices[ $source_key ];
			}
		}

		return $prices;
	}

	/**
	 * Renders the private supplier/wholesale/retail price comparison popup.
	 */
	private function render_pricing_comparison_modal(): void {
		$supplier  = $this->supplier_cost_catalog();
		$labels    = $this->supplier_cost_labels();
		$wholesale = $this->wholesale_starter_price_catalog();
		$retail    = $this->retail_price_catalog();
		$currency  = get_woocommerce_currency();
		?>
		<div class="wlp-pricing-modal" data-wlp-pricing-modal hidden>
			<div class="wlp-pricing-modal__panel" role="dialog" aria-modal="true" aria-labelledby="wlp-pricing-title">
				<div class="wlp-pricing-modal__header">
					<div>
						<h2 id="wlp-pricing-title"><?php echo esc_html__( 'Supplier costs + pricing', 'woo-logistics-plugin' ); ?></h2>
						<p><?php echo esc_html__( 'Search, add, or update private supplier costs. Supplier and wholesale values are per 10-vial kit.', 'woo-logistics-plugin' ); ?></p>
					</div>
					<button class="button" type="button" data-wlp-close-pricing aria-label="<?php echo esc_attr__( 'Close pricing comparison', 'woo-logistics-plugin' ); ?>">&times;</button>
				</div>
				<div class="wlp-pricing-modal__controls">
					<label class="wlp-pricing-modal__search"><span><?php echo esc_html__( 'Search products', 'woo-logistics-plugin' ); ?></span><input type="search" data-wlp-pricing-search placeholder="<?php echo esc_attr__( 'Search by product or strength', 'woo-logistics-plugin' ); ?>"></label>
					<label class="wlp-pricing-modal__target"><span><?php echo esc_html__( 'Minimum product margin', 'woo-logistics-plugin' ); ?></span><span><input type="number" min="20" max="80" step="1" value="50" data-wlp-pricing-target-margin> %</span></label>
				</div>
				<div class="wlp-pricing-modal__add">
					<label><span><?php echo esc_html__( 'Add product or strength', 'woo-logistics-plugin' ); ?></span><input type="text" maxlength="160" data-wlp-pricing-new-name placeholder="<?php echo esc_attr__( 'Exact product name and strength', 'woo-logistics-plugin' ); ?>"></label>
					<label><span><?php echo esc_html__( 'Supplier cost / 10-vial kit', 'woo-logistics-plugin' ); ?></span><input type="number" min="0.01" max="100000" step="0.01" inputmode="decimal" data-wlp-pricing-new-cost placeholder="0.00"></label>
					<button class="button" type="button" data-wlp-pricing-add><?php echo esc_html__( 'Add cost', 'woo-logistics-plugin' ); ?></button>
				</div>
				<div class="wlp-pricing-modal__notice">
					<strong><?php echo esc_html__( 'Pricing signals are guidance only.', 'woo-logistics-plugin' ); ?></strong>
					<span><?php echo esc_html__( 'They use product cost and the Starter 30–90 price only. Shipping, ads, payment fees, tax and overhead are excluded. Nothing here changes storefront or quote-builder prices.', 'woo-logistics-plugin' ); ?></span>
				</div>
				<div class="wlp-pricing-modal__table-wrap">
					<table class="wlp-pricing-modal__table">
						<thead><tr><th><?php echo esc_html__( 'Product', 'woo-logistics-plugin' ); ?></th><th><?php echo esc_html__( 'Supplier / kit', 'woo-logistics-plugin' ); ?></th><th><?php echo esc_html__( 'Starter / kit', 'woo-logistics-plugin' ); ?></th><th><?php echo esc_html__( 'Gross margin', 'woo-logistics-plugin' ); ?></th><th data-wlp-pricing-floor-heading><?php echo esc_html__( '50% floor', 'woo-logistics-plugin' ); ?></th><th><?php echo esc_html__( 'Signal', 'woo-logistics-plugin' ); ?></th><th><?php echo esc_html__( 'Retail / vial', 'woo-logistics-plugin' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $labels as $key => $label ) : ?>
								<?php $starter_price = isset( $wholesale[ $key ] ) ? (float) $wholesale[ $key ] : 0.0; ?>
								<tr data-wlp-pricing-row data-supplier-key="<?php echo esc_attr( $key ); ?>" data-wholesale-price="<?php echo esc_attr( $starter_price > 0 ? number_format( $starter_price, 2, '.', '' ) : '' ); ?>" data-search="<?php echo esc_attr( strtolower( $label ) ); ?>">
									<th><?php echo esc_html( $label ); ?></th>
									<td><label class="wlp-pricing-cost-input"><span aria-hidden="true">C$</span><input type="number" min="0.01" max="100000" step="0.01" inputmode="decimal" data-wlp-catalog-supplier-cost data-original-cost="<?php echo esc_attr( number_format( (float) $supplier[ $key ], 2, '.', '' ) ); ?>" value="<?php echo esc_attr( number_format( (float) $supplier[ $key ], 2, '.', '' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Supplier cost per 10-vial kit for %s', 'woo-logistics-plugin' ), $label ) ); ?>"></label></td>
									<td><?php echo esc_html( isset( $wholesale[ $key ] ) ? html_entity_decode( wp_strip_all_tags( wc_price( $wholesale[ $key ], array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ) : 'Not listed' ); ?></td>
									<td data-wlp-pricing-current-margin>&mdash;</td>
									<td data-wlp-pricing-floor>&mdash;</td>
									<td><span class="wlp-pricing-signal" data-wlp-pricing-signal>&mdash;</span></td>
									<td><?php echo esc_html( $retail[ $key ] ?? 'Not listed' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="wlp-pricing-modal__footer">
					<span data-wlp-pricing-status><?php echo esc_html__( 'Private store-wide defaults. No customer notification.', 'woo-logistics-plugin' ); ?></span>
					<div><button class="button" type="button" data-wlp-close-pricing><?php echo esc_html__( 'Close', 'woo-logistics-plugin' ); ?></button><button class="button button-primary" type="button" data-wlp-pricing-save disabled><?php echo esc_html__( 'Save supplier-cost changes', 'woo-logistics-plugin' ); ?></button></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Calculates supplier product cost from wholesale quantities.
	 *
	 * @return array{total: float, mappedCount: int, missing: array<int, string>, products: array<int, array<string, int|string|bool>>}
	 */
	private function automatic_supplier_cost( WC_Order $order ): array {
		$catalog = $this->supplier_cost_catalog();
		$lines   = $this->supplier_cost_lines( $order );

		$total        = 0.0;
		$mapped_count = 0;
		$missing      = array();
		$products     = array();

		foreach ( $lines as $line ) {
			$name     = $line['name'];
			$quantity = $line['quantity'];
			$key      = $line['key'];
			$has_cost = isset( $catalog[ $key ] );

			$products[] = array(
				'key'        => $key,
				'name'       => $name,
				'quantity'   => $quantity,
				'costPerKit' => $has_cost ? number_format( (float) $catalog[ $key ], 2, '.', '' ) : '',
				'missing'    => ! $has_cost,
			);

			if ( ! $has_cost ) {
				$missing[] = $name;
				continue;
			}

			$total += $catalog[ $key ] * ( $quantity / 10 );
			$mapped_count++;
		}

		return array(
			'total'       => $total,
			'mappedCount' => $mapped_count,
			'missing'     => array_values( array_unique( $missing ) ),
			'products'    => $products,
		);
	}

	/**
	 * Returns normalized wholesale product lines for supplier-cost calculations.
	 *
	 * @return array<int, array{key: string, name: string, quantity: int}>
	 */
	private function supplier_cost_lines( WC_Order $order ): array {
		$raw_lines = $this->wholesale_quote_lines( $order );

		if ( empty( $raw_lines ) ) {
			$products = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
			foreach ( array_filter( array_map( 'trim', explode( ';', $products ) ) ) as $product ) {
				if ( preg_match( '/^(.+?)\s*-\s*([0-9][0-9,]*)\s+vials?$/i', $product, $match ) ) {
					$raw_lines[] = array(
						'name'     => sanitize_text_field( $match[1] ),
						'quantity' => (int) str_replace( ',', '', $match[2] ),
					);
				}
			}
		}

		$lines = array();
		foreach ( $raw_lines as $line ) {
			$name     = trim( (string) ( $line['name'] ?? '' ) );
			$quantity = max( 0, (int) ( $line['quantity'] ?? 0 ) );
			$key      = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $name ) );

			if ( '' === $key || '' === $name || $quantity <= 0 ) {
				continue;
			}

			if ( isset( $lines[ $key ] ) ) {
				$lines[ $key ]['quantity'] += $quantity;
				continue;
			}

			$lines[ $key ] = array(
				'key'      => $key,
				'name'     => $name,
				'quantity' => $quantity,
			);
		}

		return array_values( $lines );
	}

	/**
	 * Builds the internal-only profit calculator payload for Logistics.
	 *
	 * @return array<string, float|string|bool>
	 */
	public function profit_payload( WC_Order $order ): array {
		$stored_revenue = trim( (string) $order->get_meta( WLP_Meta_Keys::PROFIT_ACTUAL_REVENUE, true ) );
		$recorded_partial_revenue = max( 0.0, (float) $order->get_meta( self::WHOLESALE_AMOUNT_PAID_META, true ) );
		$fallback_revenue = $recorded_partial_revenue > 0
			? $recorded_partial_revenue
			: ( $order->is_paid() ? max( 0.0, (float) $order->get_total() ) : 0.0 );
		$revenue        = '' !== $stored_revenue ? max( 0.0, (float) $stored_revenue ) : $fallback_revenue;
		$automatic_supplier = $this->automatic_supplier_cost( $order );
		$stored_supplier = trim( (string) $order->get_meta( WLP_Meta_Keys::PROFIT_SUPPLIER_COST, true ) );
		$stored_supplier_mode = trim( (string) $order->get_meta( WLP_Meta_Keys::PROFIT_SUPPLIER_COST_MODE, true ) );
		$supplier_cost_mode = 'manual' === $stored_supplier_mode || ( '' === $stored_supplier_mode && '' !== $stored_supplier ) ? 'manual' : 'auto';
		$supplier_cost  = 'manual' === $supplier_cost_mode ? max( 0.0, (float) $stored_supplier ) : $automatic_supplier['total'];
		$shipping_cost  = max( 0.0, (float) $order->get_meta( WLP_Meta_Keys::PROFIT_SHIPPING_COST, true ) );
		$payment_fees   = max( 0.0, (float) $order->get_meta( WLP_Meta_Keys::PROFIT_PAYMENT_FEES, true ) );
		$stored_tax     = trim( (string) $order->get_meta( WLP_Meta_Keys::PROFIT_TAX_COST, true ) );
		$tax_cost       = '' !== $stored_tax ? max( 0.0, (float) $stored_tax ) : max( 0.0, (float) $order->get_total_tax() );
		$other_costs    = max( 0.0, (float) $order->get_meta( WLP_Meta_Keys::PROFIT_OTHER_COSTS, true ) );
		$total_cost     = $supplier_cost + $shipping_cost + $payment_fees + $tax_cost + $other_costs;
		$profit         = $revenue - $total_cost;
		$margin         = $revenue > 0 ? ( $profit / $revenue ) * 100 : 0.0;
		$currency       = $order->get_currency();

		$format_money = static function ( float $amount ) use ( $currency ): string {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
		};

		return array(
			'actualRevenue'   => number_format( $revenue, 2, '.', '' ),
			'supplierCost'    => number_format( $supplier_cost, 2, '.', '' ),
			'autoSupplierCost' => number_format( $automatic_supplier['total'], 2, '.', '' ),
			'supplierCostMode' => $supplier_cost_mode,
			'mappedSupplierItems' => $automatic_supplier['mappedCount'],
			'missingSupplierCosts' => $automatic_supplier['missing'],
			'supplierCostProducts' => $automatic_supplier['products'],
			'shippingCost'    => number_format( $shipping_cost, 2, '.', '' ),
			'paymentFees'     => number_format( $payment_fees, 2, '.', '' ),
			'taxCost'         => number_format( $tax_cost, 2, '.', '' ),
			'otherCosts'      => number_format( $other_costs, 2, '.', '' ),
			'totalCost'       => number_format( $total_cost, 2, '.', '' ),
			'profit'          => number_format( $profit, 2, '.', '' ),
			'margin'          => number_format( $margin, 1, '.', '' ),
			'formattedRevenue' => $format_money( $revenue ),
			'formattedCost'    => $format_money( $total_cost ),
			'formattedProfit'  => $format_money( $profit ),
			'notes'            => trim( (string) $order->get_meta( WLP_Meta_Keys::PROFIT_NOTES, true ) ),
			'updatedAt'        => trim( (string) $order->get_meta( WLP_Meta_Keys::PROFIT_UPDATED_AT, true ) ),
			'isSaved'          => '' !== $stored_revenue,
		);
	}

	/**
	 * Builds a compact, private wholesale operations record for authenticated API use.
	 *
	 * Customer identity, product-level supplier costs, and internal notes are omitted.
	 *
	 * @return array<string, mixed>
	 */
	public function wholesale_ops_payload( WC_Order $order ): array {
		if ( ! $this->is_wholesale_order( $order ) ) {
			return array();
		}

		$profit   = $this->profit_payload( $order );
		$workflow = $this->wholesale_workflow_payload( $order );
		$created  = $order->get_date_created();

		return array(
			'orderId'   => $order->get_id(),
			'createdAt' => $created ? $created->date( DATE_ATOM ) : '',
			'status'    => $order->get_status(),
			'currency'  => $order->get_currency(),
			'isPaid'    => $order->is_paid(),
			'orderTotal' => number_format( max( 0.0, (float) $order->get_total() ), 2, '.', '' ),
			'isRealized' => (float) $profit['actualRevenue'] > 0,
			'workflow'  => array(
				'stage'  => (string) ( $workflow['stage'] ?? '' ),
				'label'  => (string) ( $workflow['label'] ?? '' ),
				'number' => (int) ( $workflow['number'] ?? 0 ),
			),
			'profit'    => array(
				'revenue'    => $profit['actualRevenue'],
				'totalCosts' => $profit['totalCost'],
				'amount'     => $profit['profit'],
				'marginPct'  => $profit['margin'],
				'isSaved'    => $profit['isSaved'],
				'costs'      => array(
					'supplier'    => $profit['supplierCost'],
					'shipping'    => $profit['shippingCost'],
					'paymentFees' => $profit['paymentFees'],
					'tax'         => $profit['taxCost'],
					'other'       => $profit['otherCosts'],
				),
				'supplierCoverage' => array(
					'mode'         => $profit['supplierCostMode'],
					'mappedItems'  => (int) $profit['mappedSupplierItems'],
					'missingCount' => count( (array) $profit['missingSupplierCosts'] ),
					'complete'     => empty( $profit['missingSupplierCosts'] ),
				),
			),
			'metaCrm'   => array(
				'qualified' => array(
					'status' => sanitize_key( (string) $order->get_meta( '_wlp_meta_capi_qualified_status', true ) ),
					'sentAt' => trim( (string) $order->get_meta( '_wlp_meta_capi_qualified_sent_at', true ) ),
				),
				'purchase' => array(
					'status' => sanitize_key( (string) $order->get_meta( '_wlp_meta_capi_purchase_status', true ) ),
					'sentAt' => trim( (string) $order->get_meta( '_wlp_meta_capi_purchase_sent_at', true ) ),
				),
			),
		);
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
	 * AJAX: manually sends a customer-visible note for late in-transit packages.
	 */
	public function ajax_send_delivery_delay_note(): void {
		$order = $this->ajax_order();
		$meta  = WLP_Order_Logistics::read( $order );

		if ( ! WLP_Order_Logistics::has_label( $order ) || '' === $meta['tracking_number'] ) {
			wp_send_json_error( array( 'message' => __( 'Create a label before sending a delivery delay follow-up.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( 'in_transit' !== $this->order_logistics_state( $order ) || ! $this->delivery_estimate_is_late( $meta['expected_delivery_date'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This order is not past its expected delivery date.', 'woo-logistics-plugin' ) ), 400 );
		}

		$first_name     = trim( (string) $order->get_billing_first_name() );
		$greeting       = '' !== $first_name ? 'Hi ' . $first_name . ',' : 'Hi,';
		$order_number   = $order->get_order_number();
		$tracking_url   = $this->tracking_url( $meta );
		$expected_label = $this->delivery_estimate_label( $meta['expected_delivery_date'] );
		$note           = $greeting . "\n\nQuick update on order #" . $order_number . ".\n\nCanada Post is showing this package past the original expected delivery date of " . $expected_label . ". The package is still in transit, and we are keeping an eye on the tracking from our side.\n\nTracking number: " . $meta['tracking_number'] . ( '' !== $tracking_url ? "\nTrack here: " . $tracking_url : '' ) . "\n\nIf the tracking does not update soon, reply to this email and we will help look into it.\n\nPeptideGo";

		$order->add_order_note( nl2br( esc_html( $note ) ), true, true );

		wp_send_json_success(
			array(
				'message' => __( 'Delivery delay follow-up sent.', 'woo-logistics-plugin' ),
			)
		);
	}

	/**
	 * AJAX: manually sends the customer return tracking note.
	 */
	public function ajax_send_return_note(): void {
		$order = $this->ajax_order();

		if ( ! WLP_Order_Logistics::has_return_label( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Create a return label before emailing it to the customer.', 'woo-logistics-plugin' ) ), 400 );
		}

		$sent = WLP_Order_Logistics::send_customer_return_label_note( $order, array(), true );
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'No return tracking number is available for this order.', 'woo-logistics-plugin' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Return label email sent.', 'woo-logistics-plugin' ),
			)
		);
	}

	/**
	 * AJAX: clears fulfillment state for a replacement/reship.
	 */
	public function ajax_prepare_reship(): void {
		$order = $this->ajax_order();

		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled or refunded orders cannot be prepared for reship.', 'woo-logistics-plugin' ) ), 400 );
		}

		$shipping_address = $this->ajax_reship_shipping_address();
		$order->set_address( $shipping_address, 'shipping' );
		$order->add_order_note( __( 'Shipping address reviewed and saved while preparing the reship.', 'woo-logistics-plugin' ), false, true );
		$order->save();

		$archive = WLP_Order_Logistics::prepare_reship( $order );
		$meta    = WLP_Order_Logistics::read( $order );

		wp_send_json_success(
			array(
				'message'            => __( 'Order prepared for reship. Create a new label before packing.', 'woo-logistics-plugin' ),
				'status'             => wc_get_order_status_name( $order->get_status() ),
				'hasLabel'           => WLP_Order_Logistics::has_label( $order ),
				'trackingNumber'     => $meta['tracking_number'],
				'trackingUrl'        => $this->tracking_url( $meta ),
				'serviceName'        => $meta['service_name'],
				'invoicePrintedAt'   => $meta['invoice_printed_at'],
				'packedAt'           => $meta['packed_at'],
				'previousTracking'   => $archive['tracking_number'] ?? '',
				'previousShipmentId' => $archive['shipment_id'] ?? '',
				'shippingName'       => trim( (string) $order->get_formatted_shipping_full_name() ),
				'shippingLines'      => $this->quick_view_shipping_lines( $order ),
			)
		);
	}

	/**
	 * AJAX: marks an order as packed.
	 */
	public function ajax_mark_packed(): void {
		$order = $this->ajax_order();

		WLP_Order_Logistics::mark_packed( $order );
		$meta = WLP_Order_Logistics::read( $order );

		wp_send_json_success(
			array(
				'message'          => __( 'Order marked packed. Invoice/guide marked printed.', 'woo-logistics-plugin' ),
				'packedAt'         => $meta['packed_at'],
				'invoicePrintedAt' => $meta['invoice_printed_at'],
			)
		);
	}

	/**
	 * AJAX: marks an order shipped without completing it.
	 */
	public function ajax_mark_shipped(): void {
		$order = $this->ajax_order();

		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled or refunded orders cannot be marked shipped.', 'woo-logistics-plugin' ) ), 400 );
		}

		$was_wholesale_shipped = $this->is_wholesale_order( $order ) && 'shipped' === $this->wholesale_workflow_stage( $order );
		WLP_Order_Logistics::mark_shipped( $order );
		if ( $this->is_wholesale_order( $order ) ) {
			$now = current_time( 'mysql' );
			$order->update_meta_data( self::WHOLESALE_WORKFLOW_META, 'shipped' );
			$order->update_meta_data( self::WHOLESALE_WORKFLOW_AT_META, $now );
			if ( ! $was_wholesale_shipped ) {
				$order->add_order_note( __( 'Wholesale shipment handoff confirmed in Logistics.', 'woo-logistics-plugin' ), false, true );
			}
			$order->save();
		}
		$meta = WLP_Order_Logistics::read( $order );
		$notification = $this->is_wholesale_order( $order ) ? WLP_Split_Shipments::notify_shipped( $order ) : null;

		wp_send_json_success(
			array(
				'shipmentNotification' => $notification,
				'message'          => $notification ? $notification['message'] : __( 'Order marked shipped and moved to In transit.', 'woo-logistics-plugin' ),
				'shippedAt'        => $meta['shipped_at'],
				'packedAt'         => $meta['packed_at'],
				'invoicePrintedAt' => $meta['invoice_printed_at'],
				'state'            => $this->order_logistics_state( $order ),
			)
		);
	}

	/**
	 * AJAX: marks an order delivered and completed.
	 */
	public function ajax_mark_delivered(): void {
		$order = $this->ajax_order();

		if ( $order->has_status( 'cancelled' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled orders cannot be marked delivered.', 'woo-logistics-plugin' ) ), 400 );
		}

		$is_wholesale = $this->is_wholesale_order( $order );
		$disable_completed_email = static function ( bool $enabled ): bool {
			return false;
		};

		if ( $is_wholesale ) {
			add_filter( 'woocommerce_email_enabled_customer_completed_order', $disable_completed_email, PHP_INT_MAX );
		}

		try {
			WLP_Order_Logistics::mark_delivered( $order );
		} finally {
			if ( $is_wholesale ) {
				remove_filter( 'woocommerce_email_enabled_customer_completed_order', $disable_completed_email, PHP_INT_MAX );
			}
		}
		$meta = WLP_Order_Logistics::read( $order );

		wp_send_json_success(
			array(
				'message'     => $is_wholesale
					? __( 'Wholesale order marked delivered and completed. No customer email was sent.', 'woo-logistics-plugin' )
					: __( 'Order marked delivered and completed.', 'woo-logistics-plugin' ),
				'deliveredAt' => $meta['delivered_at'],
				'status'      => wc_get_order_status_name( $order->get_status() ),
			)
			);
	}

	/**
	 * AJAX: sends a wholesale follow-up message and advances the wholesale stage.
	 */
	public function ajax_wholesale_followup_preview(): void {
		$order = $this->ajax_order();
		$stage = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : '';

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		$allowed_stages = array( 'shipping_details_request', 'payment_instructions', 'payment_received', 'hamilton_hub', 'inspected_label', 'shipped_tracking', 'delivered_complete', 'refund_etransfer', 'refund_wire' );
		if ( ! in_array( $stage, $allowed_stages, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown wholesale follow-up stage.', 'woo-logistics-plugin' ) ), 400 );
		}

		$message = $this->wholesale_followup_message( $order, $stage, WLP_Order_Logistics::read( $order ) );
		if ( empty( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not build the wholesale follow-up email.', 'woo-logistics-plugin' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'label'   => $message['label'],
				'subject' => $message['subject'],
				'body'    => $message['body'],
			)
		);
	}

	/**
	 * AJAX: sends a wholesale follow-up message and advances the wholesale stage.
	 */
	public function ajax_wholesale_followup(): void {
		$order = $this->ajax_order();
		$stage = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : '';

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		$allowed_stages = array( 'shipping_details_request', 'payment_instructions', 'payment_received', 'hamilton_hub', 'inspected_label', 'shipped_tracking', 'delivered_complete', 'refund_etransfer', 'refund_wire' );
		if ( ! in_array( $stage, $allowed_stages, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown wholesale follow-up stage.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled or refunded orders cannot receive wholesale follow-ups.', 'woo-logistics-plugin' ) ), 400 );
		}

		$meta = WLP_Order_Logistics::read( $order );
		if ( 'inspected_label' === $stage && '' === $meta['tracking_number'] ) {
			wp_send_json_error( array( 'message' => __( 'Create the shipping label before sending the inspected/label-made update.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( 'delivered_complete' === $stage ) {
			WLP_Order_Logistics::mark_delivered( $order );
			$meta = WLP_Order_Logistics::read( $order );
		} elseif ( 'payment_received' === $stage && ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			$this->suppress_wholesale_processing_email = true;
			try {
				$order->update_status( 'processing', __( 'Wholesale payment received. Fulfillment started.', 'woo-logistics-plugin' ), false );
			} finally {
				$this->suppress_wholesale_processing_email = false;
			}
		}

		$message = $this->wholesale_followup_message( $order, $stage, $meta );
		if ( empty( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not build the wholesale follow-up email.', 'woo-logistics-plugin' ) ), 500 );
		}
		$custom_body = isset( $_POST['body'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) ) : '';
		if ( '' !== $custom_body ) {
			$message['body'] = $custom_body;
		}

		$sent = $this->send_wholesale_followup_email( $order, $message['subject'], $message['body'] );
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'WordPress could not send the wholesale follow-up email.', 'woo-logistics-plugin' ) ), 500 );
		}

		$now = current_time( 'mysql' );
		$order->update_meta_data( self::WHOLESALE_STAGE_META, $stage );
		$order->update_meta_data( self::WHOLESALE_STAGE_AT_META, $now );
		$workflow_stage = match ( $stage ) {
			'shipping_details_request' => 'awaiting_customer',
			'payment_instructions'      => 'awaiting_payment',
			'payment_received'          => 'supplier_order',
			'hamilton_hub'              => 'inbound_hamilton',
			'inspected_label'           => 'ready_to_ship',
			'shipped_tracking'          => 'shipped',
			'delivered_complete'        => 'shipped',
			default                     => '',
		};
		if ( '' !== $workflow_stage ) {
			$order->update_meta_data( self::WHOLESALE_WORKFLOW_META, $workflow_stage );
			$order->update_meta_data( self::WHOLESALE_WORKFLOW_AT_META, $now );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: stage label, 2: customer email. */
				__( 'Wholesale follow-up sent: %1$s to %2$s.', 'woo-logistics-plugin' ),
				$message['label'],
				$order->get_billing_email()
			),
			false,
			true
		);
		$order->save();

		wp_send_json_success(
			array(
				'message'      => sprintf(
					/* translators: %s: stage label. */
					__( 'Wholesale update sent: %s.', 'woo-logistics-plugin' ),
					$message['label']
				),
				'stage'        => $stage,
				'stageLabel'   => $message['label'],
				'stageAt'      => $now,
				'status'       => wc_get_order_status_name( $order->get_status() ),
				'trackingUrl'  => $this->tracking_url( $meta ),
				'trackingNumber' => $meta['tracking_number'],
			)
		);
	}

	/**
	 * AJAX: advances the internal wholesale workflow without emailing the customer.
	 */
	public function ajax_wholesale_advance(): void {
		$order  = $this->ajax_order();
		$action = isset( $_POST['workflowAction'] ) ? sanitize_key( wp_unslash( $_POST['workflowAction'] ) ) : '';
		$suppress_customer_email = 'payment_verified' === $action
			&& isset( $_POST['suppressCustomerEmail'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['suppressCustomerEmail'] ) );

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled or refunded orders cannot be advanced.', 'woo-logistics-plugin' ) ), 400 );
		}

		$transitions = array(
			'customer_confirmed' => array(
				'from'  => 'awaiting_customer',
				'to'    => 'stock_check',
				'label' => __( 'Customer confirmed the quote and shipping details.', 'woo-logistics-plugin' ),
			),
			'stock_confirmed' => array(
				'from'  => 'stock_check',
				'to'    => 'final_quote',
				'label' => __( 'Supplier stock confirmed for the wholesale quote.', 'woo-logistics-plugin' ),
			),
			'payment_verified' => array(
				'from'  => array( 'final_quote', 'awaiting_payment' ),
				'to'    => 'supplier_order',
				'label' => __( 'Wholesale payment verified internally. Fulfillment can begin.', 'woo-logistics-plugin' ),
			),
			'received_inspected' => array(
				'from'  => array( 'supplier_order', 'inbound_hamilton' ),
				'to'    => 'ready_to_ship',
				'label' => __( 'Wholesale stock confirmed on hand in Hamilton and inspection completed.', 'woo-logistics-plugin' ),
			),
			'confirm_shipped' => array(
				'from'  => 'ready_to_ship',
				'to'    => 'shipped',
				'label' => __( 'Wholesale shipment handoff confirmed.', 'woo-logistics-plugin' ),
			),
		);

		if ( ! isset( $transitions[ $action ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown wholesale workflow action.', 'woo-logistics-plugin' ) ), 400 );
		}

		$transition = $transitions[ $action ];
		$current    = $this->wholesale_workflow_stage( $order );

		if ( $current === $transition['to'] ) {
			wp_send_json_success(
				array(
					'message'  => __( 'This wholesale step was already completed.', 'woo-logistics-plugin' ),
					'workflow' => $this->wholesale_workflow_payload( $order ),
					'state'    => $this->order_logistics_state( $order ),
				)
			);
		}

		$allowed_from = (array) $transition['from'];
		if ( ! in_array( $current, $allowed_from, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Complete the current wholesale step before advancing.', 'woo-logistics-plugin' ) ), 409 );
		}

		if ( 'confirm_shipped' === $action ) {
			$logistics = WLP_Order_Logistics::read( $order );
			if ( '' === trim( $logistics['tracking_number'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Create or save the customer tracking number before confirming shipment.', 'woo-logistics-plugin' ) ), 409 );
			}
		}

		$now = current_time( 'mysql' );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_META, $transition['to'] );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_AT_META, $now );

		if ( 'payment_verified' === $action && ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			if ( $suppress_customer_email ) {
				$this->suppress_wholesale_processing_email = true;
				try {
					$order->update_status( 'processing', __( 'Wholesale payment verified internally. No customer email was sent.', 'woo-logistics-plugin' ), false );
				} finally {
					$this->suppress_wholesale_processing_email = false;
				}
			} else {
				$order->update_status( 'processing', __( 'Wholesale payment verified internally. The automatic wholesale payment-received email was triggered.', 'woo-logistics-plugin' ), false );
			}
		}
		if ( 'received_inspected' === $action ) {
			$order->update_meta_data( self::WHOLESALE_INSPECTED_AT_META, $now );
		}
		if ( 'confirm_shipped' === $action ) {
			WLP_Order_Logistics::mark_shipped( $order );
		}

		$transition_label = $suppress_customer_email
			? __( 'Wholesale payment verified internally. Fulfillment can begin. No customer email was sent.', 'woo-logistics-plugin' )
			: $transition['label'];
		$order->add_order_note( $transition_label, false, true );
		$order->save();

		if ( 'confirm_shipped' === $action ) { $notification = WLP_Split_Shipments::notify_shipped( $order ); $transition_label = $notification['message']; }
		$meta_outcome = null;
		if ( 'customer_confirmed' === $action ) {
			$meta_outcome = WLP_Meta_CAPI::send_outcome( $order, 'qualified' );
		}
		if ( 'payment_verified' === $action ) {
			$meta_outcome = WLP_Meta_CAPI::send_outcome( $order, 'purchase' );
		}

		wp_send_json_success(
			array(
				'message'  => $transition_label,
				'metaOutcome' => $meta_outcome,
				'workflow' => $this->wholesale_workflow_payload( $order ),
				'state'    => $this->order_logistics_state( $order ),
				'status'   => wc_get_order_status_name( $order->get_status() ),
			)
		);
	}

	/**
	 * AJAX: records a reconciled partial wholesale payment without changing status or emailing the customer.
	 */
	public function ajax_wholesale_record_partial_payment(): void {
		$order = $this->ajax_order();

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( $order->has_status( array( 'processing', 'completed', 'cancelled', 'refunded' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Partial payments can only be recorded while the wholesale order is still awaiting full payment.', 'woo-logistics-plugin' ) ), 409 );
		}

		$amount_raw = isset( $_POST['amount'] ) ? wc_format_decimal( wp_unslash( $_POST['amount'] ), 2 ) : '';
		$amount_cents = (int) round( (float) $amount_raw * 100 );
		$entry_token = isset( $_POST['entryToken'] ) ? sanitize_text_field( wp_unslash( $_POST['entryToken'] ) ) : '';
		$entry_token = substr( $entry_token, 0, 80 );

		if ( $amount_cents <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a partial payment greater than C$0.00.', 'woo-logistics-plugin' ) ), 400 );
		}

		$summary = $this->wholesale_payment_summary( $order );
		if ( $amount_cents > $summary['balanceCents'] ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: remaining balance. */
						__( 'That exceeds the remaining balance of %s.', 'woo-logistics-plugin' ),
						$summary['balanceFormatted']
					),
				),
				400
			);
		}

		$entries = $order->get_meta( self::WHOLESALE_PARTIAL_PAYMENTS_META, true );
		$entries = is_array( $entries ) ? $entries : array();
		if ( '' !== $entry_token ) {
			foreach ( $entries as $entry ) {
				if ( is_array( $entry ) && hash_equals( (string) ( $entry['reference'] ?? '' ), $entry_token ) ) {
					wp_send_json_success(
						array(
							'message' => __( 'This partial payment was already recorded.', 'woo-logistics-plugin' ),
							'payment' => $this->wholesale_payment_summary( $order ),
						)
					);
				}
			}
		}

		$now = current_time( 'mysql' );
		$new_paid_cents = $summary['paidCents'] + $amount_cents;
		$new_balance_cents = max( 0, $summary['totalCents'] - $new_paid_cents );
		$entries[] = array(
			'amount'      => number_format( $amount_cents / 100, 2, '.', '' ),
			'recorded_at' => $now,
			'reference'   => '' !== $entry_token ? $entry_token : wp_generate_uuid4(),
			'user_id'     => get_current_user_id(),
		);

		$order->update_meta_data( self::WHOLESALE_AMOUNT_PAID_META, number_format( $new_paid_cents / 100, 2, '.', '' ) );
		$order->update_meta_data( self::WHOLESALE_PAYMENT_BALANCE_META, number_format( $new_balance_cents / 100, 2, '.', '' ) );
		$order->update_meta_data( self::WHOLESALE_PARTIAL_PAYMENT_AT_META, $now );
		$order->update_meta_data( self::WHOLESALE_PARTIAL_PAYMENTS_META, $entries );
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment amount, 2: cumulative amount paid, 3: balance due. */
				__( 'Partial wholesale payment recorded: C$%1$s. Total recorded: C$%2$s. Balance due: C$%3$s. Order remains unpaid in full; no customer notification was sent.', 'woo-logistics-plugin' ),
				number_format( $amount_cents / 100, 2, '.', ',' ),
				number_format( $new_paid_cents / 100, 2, '.', ',' ),
				number_format( $new_balance_cents / 100, 2, '.', ',' )
			),
			false,
			true
		);
		$order->save();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: payment amount, 2: remaining balance. */
					__( 'Recorded %1$s. Balance due: %2$s. Order remains Pending and no customer email was sent.', 'woo-logistics-plugin' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $amount_cents / 100, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $new_balance_cents / 100, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' )
				),
				'payment' => $this->wholesale_payment_summary( $order ),
			)
		);
	}

	/**
	 * AJAX: corrects an internal wholesale workflow step without notifying the customer.
	 */
	public function ajax_wholesale_set_workflow(): void {
		$order  = $this->ajax_order();
		$target = isset( $_POST['workflowStage'] ) ? sanitize_key( wp_unslash( $_POST['workflowStage'] ) ) : '';

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled or refunded orders cannot be adjusted.', 'woo-logistics-plugin' ) ), 400 );
		}

		$allowed = array( 'awaiting_customer', 'stock_check', 'final_quote', 'awaiting_payment', 'supplier_order', 'inbound_hamilton', 'ready_to_ship' );
		if ( ! in_array( $target, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid internal wholesale workflow step.', 'woo-logistics-plugin' ) ), 400 );
		}

		if (
			$order->has_status( array( 'processing', 'completed' ) )
			&& in_array( $target, array( 'awaiting_customer', 'stock_check', 'final_quote', 'awaiting_payment' ), true )
		) {
			wp_send_json_error( array( 'message' => __( 'A paid or completed order cannot be moved back to quote stages. Select a fulfillment stage instead.', 'woo-logistics-plugin' ) ), 409 );
		}

		$current = $this->wholesale_workflow_stage( $order );
		if ( $current === $target ) {
			wp_send_json_success(
				array(
					'message'  => __( 'This wholesale workflow step is already selected.', 'woo-logistics-plugin' ),
					'workflow' => $this->wholesale_workflow_payload( $order ),
					'state'    => $this->order_logistics_state( $order ),
				)
			);
		}

		$now = current_time( 'mysql' );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_META, $target );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_AT_META, $now );
		if ( 'ready_to_ship' === $target ) {
			$order->update_meta_data( self::WHOLESALE_INSPECTED_AT_META, $now );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: previous workflow step, 2: new workflow step. */
				__( 'Wholesale workflow adjusted internally from %1$s to %2$s. No customer email was sent.', 'woo-logistics-plugin' ),
				$current,
				$target
			),
			false,
			true
		);
		$order->save();

		wp_send_json_success(
			array(
				'message'  => __( 'Wholesale workflow step updated internally. No customer email was sent.', 'woo-logistics-plugin' ),
				'workflow' => $this->wholesale_workflow_payload( $order ),
				'state'    => $this->order_logistics_state( $order ),
			)
		);
	}

	/**
	 * AJAX: saves supplier-to-Hamilton tracking. This is internal-only.
	 */
	public function ajax_wholesale_save_inbound_tracking(): void {
		$order = $this->ajax_order();

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		$current = $this->wholesale_workflow_stage( $order );
		if ( ! in_array( $current, array( 'supplier_order', 'inbound_hamilton' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Verify payment before adding supplier tracking.', 'woo-logistics-plugin' ) ), 409 );
		}

		$carrier = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : '';
		$tracking = isset( $_POST['trackingNumber'] ) ? sanitize_text_field( wp_unslash( $_POST['trackingNumber'] ) ) : '';
		$url = isset( $_POST['trackingUrl'] ) ? esc_url_raw( wp_unslash( $_POST['trackingUrl'] ) ) : '';

		if ( '' === $tracking ) {
			wp_send_json_error( array( 'message' => __( 'Enter the supplier inbound tracking number.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( '' === $url && preg_match( '/canada\s*post|postes\s*canada/i', $carrier ) ) {
			$url = 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $tracking );
		}

		$now = current_time( 'mysql' );
		$order->update_meta_data( self::WHOLESALE_INBOUND_CARRIER_META, $carrier );
		$order->update_meta_data( self::WHOLESALE_INBOUND_TRACKING_META, $tracking );
		$order->update_meta_data( self::WHOLESALE_INBOUND_TRACKING_URL_META, $url );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_META, 'inbound_hamilton' );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_AT_META, $now );
		$order->add_order_note( __( 'Supplier inbound tracking saved for the Hamilton shipment.', 'woo-logistics-plugin' ), false, true );
		$order->save();

		wp_send_json_success(
			array(
				'message'  => __( 'Inbound tracking saved. Watch for arrival in Hamilton.', 'woo-logistics-plugin' ),
				'workflow' => $this->wholesale_workflow_payload( $order ),
				'state'    => $this->order_logistics_state( $order ),
			)
		);
	}

	/**
	 * AJAX: previews the exact customer tracking email without changing the order.
	 */
	public function ajax_wholesale_tracking_preview(): void {
		$order = $this->ajax_order();
		if ( WLP_Split_Shipments::active( $order ) ) {
			try { $message = WLP_Split_Shipments::email_preview( $order ); }
			catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 409 ); }
			wp_send_json_success( array( 'recipient' => sanitize_email( $order->get_billing_email() ), 'subject' => $message['subject'], 'body' => $message['body'] ) );
		}

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		$tracking = $this->wholesale_outbound_tracking_request();
		$message  = $this->wholesale_followup_message( $order, 'shipped_tracking', array_merge( WLP_Order_Logistics::read( $order ), $tracking ) );

		wp_send_json_success(
			array(
				'recipient' => sanitize_email( $order->get_billing_email() ),
				'subject'   => $message['subject'],
				'body'      => $message['body'],
			)
		);
	}

	/**
	 * AJAX: saves manual outbound tracking or marks a wholesale order shipped and emails tracking.
	 */
	public function ajax_wholesale_save_tracking(): void {
		$order = $this->ajax_order();

		if ( ! $this->is_wholesale_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a wholesale order.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled or refunded orders cannot be shipped.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( 'ready_to_ship' !== $this->wholesale_workflow_stage( $order ) || '' === trim( (string) $order->get_meta( self::WHOLESALE_INSPECTED_AT_META, true ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Receive and inspect the wholesale order before adding customer tracking.', 'woo-logistics-plugin' ) ), 409 );
		}

		$mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'save';
		$tracking = $this->wholesale_outbound_tracking_request();
		$current_expected_delivery_date = trim( (string) $order->get_meta( WLP_Meta_Keys::EXPECTED_DELIVERY_DATE, true ) );
		if ( $tracking['expected_delivery_date'] !== $current_expected_delivery_date ) {
			$tracking['expected_delivery_date'] = $this->wholesale_customer_delivery_estimate_date( $order, $tracking['expected_delivery_date'] );
		}
		$now      = current_time( 'mysql' );

		$order->update_meta_data( WLP_Meta_Keys::TRACKING_NUMBER, $tracking['tracking_number'] );
		$order->update_meta_data( WLP_Meta_Keys::TRACKING_URL, $tracking['tracking_url'] );
		$order->update_meta_data( WLP_Meta_Keys::SERVICE_NAME, $tracking['service_name'] );
		$order->update_meta_data( WLP_Meta_Keys::EXPECTED_DELIVERY_DATE, $tracking['expected_delivery_date'] );
		$order->save();

		if ( 'ship' !== $mode ) {
			$order->add_order_note( __( 'Customer outbound tracking saved. Order remains in Wholesale to ship.', 'woo-logistics-plugin' ), false, true );
			$order->save();
			wp_send_json_success(
				array(
					'message'  => __( 'Customer tracking saved. The order has not been marked shipped.', 'woo-logistics-plugin' ),
					'workflow' => $this->wholesale_workflow_payload( $order ),
				)
			);
		}

		$message = $this->wholesale_followup_message( $order, 'shipped_tracking', array_merge( WLP_Order_Logistics::read( $order ), $tracking ) );
		$custom_body = isset( $_POST['body'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) ) : '';
		if ( '' !== $custom_body ) {
			$message['body'] = $custom_body;
		}

		if ( ! $this->send_wholesale_followup_email( $order, $message['subject'], $message['body'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Tracking was saved, but WordPress could not send the email. The order was not marked shipped.', 'woo-logistics-plugin' ) ), 500 );
		}

		WLP_Order_Logistics::mark_shipped( $order );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_META, 'shipped' );
		$order->update_meta_data( self::WHOLESALE_WORKFLOW_AT_META, $now );
		$order->update_meta_data( self::WHOLESALE_STAGE_META, 'shipped_tracking' );
		$order->update_meta_data( self::WHOLESALE_STAGE_AT_META, $now );
		$order->add_order_note( __( 'Wholesale tracking email sent and order marked shipped.', 'woo-logistics-plugin' ), false, true );
		$order->save();

		wp_send_json_success(
			array(
				'message'  => __( 'Wholesale order marked shipped and tracking email sent.', 'woo-logistics-plugin' ),
				'workflow' => $this->wholesale_workflow_payload( $order ),
				'state'    => $this->order_logistics_state( $order ),
			)
		);
	}

	/**
	 * Reads and validates manual outbound tracking fields from the request.
	 *
	 * @return array<string, string>
	 */
	private function wholesale_outbound_tracking_request(): array {
		$carrier = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : '';
		$number  = isset( $_POST['trackingNumber'] ) ? sanitize_text_field( wp_unslash( $_POST['trackingNumber'] ) ) : '';
		$url     = isset( $_POST['trackingUrl'] ) ? esc_url_raw( wp_unslash( $_POST['trackingUrl'] ) ) : '';
		$date    = isset( $_POST['expectedDeliveryDate'] ) ? sanitize_text_field( wp_unslash( $_POST['expectedDeliveryDate'] ) ) : '';

		if ( '' === $carrier || '' === $number ) {
			wp_send_json_error( array( 'message' => __( 'Carrier and customer tracking number are required.', 'woo-logistics-plugin' ) ), 400 );
		}

		if ( '' === $url && false !== stripos( $carrier, 'canada post' ) ) {
			$url = 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $number );
		}

		if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Expected delivery must use YYYY-MM-DD.', 'woo-logistics-plugin' ) ), 400 );
		}

		return array(
			'tracking_number'        => $number,
			'tracking_url'           => $url,
			'service_name'           => $carrier,
			'expected_delivery_date' => $date,
		);
	}

	/**
	 * AJAX: cancels an order from the logistics desk.
	 */
	public function ajax_cancel_order(): void {
		$order = $this->ajax_order();

		if ( $order->has_status( 'cancelled' ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Order is already cancelled.', 'woo-logistics-plugin' ),
					'status'  => wc_get_order_status_name( $order->get_status() ),
				)
			);
		}

		$block_mail = static function ( $short_circuit = null ): bool {
			return false;
		};
		$email_filters = array(
			'woocommerce_email_enabled_cancelled_order',
			'woocommerce_email_enabled_customer_cancelled_order',
		);

		foreach ( $email_filters as $email_filter ) {
			add_filter( $email_filter, $block_mail, PHP_INT_MAX );
		}
		add_filter( 'pre_wp_mail', $block_mail, PHP_INT_MAX );

		try {
			$order->update_status( 'cancelled', '', true );
			$order->save();
		} finally {
			foreach ( $email_filters as $email_filter ) {
				remove_filter( $email_filter, $block_mail, PHP_INT_MAX );
			}
			remove_filter( 'pre_wp_mail', $block_mail, PHP_INT_MAX );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Order cancelled.', 'woo-logistics-plugin' ),
				'status'  => wc_get_order_status_name( $order->get_status() ),
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
		// Reading an existing label has no order or carrier side effects. Require
		// manage_woocommerce above, but not a session-bound nonce: authorized staff
		// must be able to reopen saved links in another signed-in browser.
		// Purchase, void, and fulfillment actions retain their nonce checks.

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
	 * Streams a stored return label PDF from Canada Post for printing.
	 */
	public function print_return_label(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to print return labels.', 'woo-logistics-plugin' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'wlp_print_return_label_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'woo-logistics-plugin' ) );
		}

		$meta = WLP_Order_Logistics::read_return( $order );
		if ( '' === $meta['label_artifact_url'] ) {
			wp_die( esc_html__( 'No printable return label is stored for this order.', 'woo-logistics-plugin' ) );
		}

		try {
			$artifact = $this->client->download_artifact( $meta['label_artifact_url'] );
			nocache_headers();
			header( 'Content-Type: ' . ( $artifact['content_type'] ?: 'application/pdf' ) );
			header( 'Content-Disposition: inline; filename="canada-post-return-' . sanitize_file_name( $meta['tracking_number'] ?: (string) $order->get_id() ) . '.pdf"' );
			echo $artifact['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
	}

	/**
	 * Streams a stored return label PDF through a customer-safe signed URL.
	 */
	public function customer_return_label(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! WLP_Order_Logistics::verify_return_label_token( $order, $token ) ) {
			wp_die( esc_html__( 'This return label link is invalid or expired.', 'woo-logistics-plugin' ) );
		}

		$meta = WLP_Order_Logistics::read_return( $order );
		if ( '' === $meta['label_artifact_url'] ) {
			wp_die( esc_html__( 'No printable return label is stored for this order.', 'woo-logistics-plugin' ) );
		}

		try {
			$artifact = $this->client->download_artifact( $meta['label_artifact_url'] );
			nocache_headers();
			header( 'Content-Type: ' . ( $artifact['content_type'] ?: 'application/pdf' ) );
			header( 'Content-Disposition: inline; filename="canada-post-return-' . sanitize_file_name( $meta['tracking_number'] ?: (string) $order->get_id() ) . '.pdf"' );
			echo $artifact['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
	}

	/**
	 * Renders read-only address slips for counter postage.
	 */
	public function manual_counter_labels(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to print address slips.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wlp_manual_counter_labels' );
		$ids = $this->request_order_ids();
		if ( ! $ids || count( $ids ) > 100 ) {
			wp_die( 'Select between 1 and 100 orders.', '', array( 'response' => 400 ) );
		}
		$rows = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
				wp_die( 'An order is unavailable. Return to Logistics and select current orders.', '', array( 'response' => 400 ) );
			}
			$wholesale = $this->is_wholesale_order( $order );
			$shipping = $order->get_address( 'shipping' );
			foreach ( array( 'first_name', 'address_1', 'city', 'postcode', 'country' ) as $field ) {
				if ( empty( $shipping[ $field ] ) ) {
					wp_die( esc_html( 'Shipping address incomplete for order #' . $order->get_order_number() . '. Correct the order before printing.' ), '', array( 'response' => 400 ) );
				}
			}
			$service = $this->service_code_for_order_shipping( $order );
			if ( $wholesale && '' === $service ) { $service = WLP_Settings::wholesale_service_code( $order ); }
			$source = $wholesale ? 'Wholesale default' : 'Order shipping method';
			if ( '' === $service ) { $service = WLP_Settings::default_service_code(); $source = 'Logistics default'; }
			if ( 'CA' !== $shipping['country'] ) { $service = ''; $source = 'Confirm international service at counter'; }
			$signature = (string) $order->get_meta( '_pg_delivery_signature_required', true );
			$card = (string) $order->get_meta( '_pg_delivery_card_for_pickup', true );
			if ( ! in_array( $signature, array( 'yes', 'no' ), true ) ) { $signature = $wholesale || WLP_Settings::signature_required() ? 'yes' : 'confirm'; }
			if ( ! in_array( $card, array( 'yes', 'no' ), true ) ) { $card = $wholesale ? 'no' : 'confirm'; }
			$rows[] = array( 'id' => $id, 'number' => $order->get_order_number(), 'wholesale' => $wholesale, 'shipping' => $shipping, 'service' => $service, 'source' => $source, 'signature' => $signature, 'card' => $card );
		}
		usort( $rows, static fn( $a, $b ) => ( $a['wholesale'] <=> $b['wholesale'] ) ?: ( $b['id'] <=> $a['id'] ) );
		require_once WLP_PATH . 'includes/class-wlp-manual-counter-labels.php';
		WLP_Manual_Counter_Labels::render( $rows );
		exit;
	}

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

			try {
				$artifact = $this->client->download_artifact( $meta['label_artifact_url'] );
				$labels[] = $artifact['body'];
			} catch ( Throwable $error ) {
				wp_die(
					esc_html__( 'Canada Post could not provide an existing label. No new labels were purchased. Return to Logistics and retry printing after the carrier connection is restored.', 'woo-logistics-plugin' ),
					esc_html__( 'Label download unavailable', 'woo-logistics-plugin' ),
					array( 'response' => 500, 'back_link' => true )
				);
				return;
			}
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
	 * Renders one printable page containing invoice + guide sheets for selected orders.
	 */
	public function bulk_print_invoices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to print invoices.', 'woo-logistics-plugin' ) );
		}

		check_admin_referer( 'wlp_bulk_print_invoices' );

		$order_ids = $this->request_order_ids();
		if ( empty( $order_ids ) ) {
			wp_die( esc_html__( 'Select at least one order to print invoices.', 'woo-logistics-plugin' ) );
		}

		$orders = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				WLP_Order_Logistics::mark_invoice_printed( $order );
				$orders[] = $order;
			}
		}

		if ( empty( $orders ) ) {
			wp_die( esc_html__( 'No printable orders were found.', 'woo-logistics-plugin' ) );
		}

		nocache_headers();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( sprintf( 'PeptideGo Bulk Invoices %s', gmdate( 'Y-m-d H:i' ) ) ); ?></title>
			<link rel="preconnect" href="https://fonts.googleapis.com">
			<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
			<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
			<style>
				@page { margin: 0.35in; size: letter portrait; }
				* { box-sizing: border-box; }
				body {
					color: #111;
					font-family: Arial, Helvetica, sans-serif;
					font-size: 12px;
					line-height: 1.35;
					margin: 0;
				}
				.wlp-invoice {
					break-after: page;
					margin: 0 auto;
					max-width: 100%;
					page-break-after: always;
				}
				.wlp-invoice:last-of-type {
					break-after: auto;
					page-break-after: auto;
				}
				.wlp-invoice__header {
					align-items: flex-start;
					border-bottom: 2px solid #111;
					display: flex;
					justify-content: space-between;
					padding-bottom: 7px;
				}
				.wlp-invoice__brand {
					color: #111;
					font-family: 'Montserrat', Arial, Helvetica, sans-serif;
					font-size: 24px;
					font-weight: 800;
					letter-spacing: -0.02em;
				}
				.wlp-invoice__title {
					font-size: 11px;
					font-weight: 700;
					letter-spacing: 0.08em;
					margin-top: 4px;
					text-transform: uppercase;
				}
				.wlp-invoice__meta {
					font-size: 11px;
					text-align: right;
				}
				.wlp-invoice__meta strong {
					display: block;
					font-size: 20px;
					line-height: 1.1;
				}
				.wlp-invoice__tracking {
					font-size: 10px;
					font-weight: 700;
					margin-top: 4px;
				}
				.wlp-invoice__items {
					border-collapse: collapse;
					margin-top: 7px;
					width: 100%;
				}
				.wlp-invoice__items th,
				.wlp-invoice__items td {
					border-bottom: 1px solid #d8dde5;
					padding: 7px 6px;
					text-align: left;
					vertical-align: top;
				}
				.wlp-invoice__items th {
					font-size: 10px;
					letter-spacing: 0.08em;
					text-transform: uppercase;
				}
				.wlp-invoice__items tfoot td {
					border-bottom: 0;
					font-size: 11px;
					font-weight: 700;
					padding-bottom: 0;
					padding-top: 6px;
				}
				.wlp-invoice__items tfoot .wlp-invoice__summary {
					text-align: right;
				}
				.wlp-invoice__summary span {
					display: inline-block;
					margin-left: 16px;
					white-space: nowrap;
				}
				.wlp-invoice__items .is-number {
					text-align: right;
					white-space: nowrap;
				}
				.wlp-invoice__note {
					-webkit-print-color-adjust: exact;
					background: #fff2a8;
					border: 3px solid #111;
					border-radius: 6px;
					font-size: 14px;
					font-weight: 700;
					line-height: 1.45;
					margin-top: 7px;
					padding: 10px 12px;
					print-color-adjust: exact;
				}
				.wlp-invoice__note strong {
					display: block;
					font-family: 'Montserrat', Arial, Helvetica, sans-serif;
					font-size: 12px;
					font-weight: 800;
					letter-spacing: 0.08em;
					margin-bottom: 4px;
					text-transform: uppercase;
				}
				.wlp-invoice__fulfillment {
					background: #fff7d6;
					border: 2px solid #c79600;
					border-radius: 6px;
					color: #111827;
					font-size: 12px;
					font-weight: 800;
					margin-top: 7px;
					padding: 7px 9px;
					text-align: center;
					text-transform: uppercase;
				}
				.wlp-invoice__footer {
					align-items: center;
					border-top: 0;
					display: flex;
					font-size: 11px;
					gap: 12px;
					justify-content: center;
					margin-top: 6px;
					padding-top: 0;
				}
				.wlp-invoice__footer strong {
					white-space: nowrap;
				}
				.wlp-invoice__actions {
					margin: 14px 0;
					text-align: right;
				}
				@media print {
					.wlp-invoice__actions { display: none; }
					.wlp-invoice__fulfillment,
					.wlp-invoice__items th,
					.wlp-invoice__items td,
					.wlp-invoice__footer { border-color: #bbb; }
				}
				.wlp-reconstitution-guide {
					align-items: center;
					display: flex;
					justify-content: center;
					margin-top: 10px;
					text-align: center;
				}
				.wlp-reconstitution-guide img {
					display: block;
					height: 5.05in;
					max-height: 5.05in;
					max-width: 7.45in;
					object-fit: fill;
					width: 7.45in;
				}
				.wlp-invoice--several-items .wlp-invoice__items th,
				.wlp-invoice--several-items .wlp-invoice__items td,
				.wlp-invoice--many-items .wlp-invoice__items th,
				.wlp-invoice--many-items .wlp-invoice__items td {
					padding-bottom: 5px;
					padding-top: 5px;
				}
				.wlp-invoice--many-items .wlp-reconstitution-guide img {
					height: 4.55in;
					max-height: 4.55in;
				}
			</style>
		</head>
		<body>
			<div class="wlp-invoice__actions">
				<button type="button" onclick="window.print()"><?php echo esc_html__( 'Print invoice + guide batch', 'woo-logistics-plugin' ); ?></button>
			</div>
			<?php foreach ( $orders as $order ) : ?>
				<?php $this->render_invoice_sheet( $order ); ?>
			<?php endforeach; ?>
			<script>
				window.addEventListener('load', function () {
					window.setTimeout(function () {
						window.print();
					}, 300);
				});
			</script>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Renders a printable order invoice with the reconstitution guide.
	 */
	public function print_invoice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to print invoices.', 'woo-logistics-plugin' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'wlp_print_invoice_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'woo-logistics-plugin' ) );
		}

		WLP_Order_Logistics::mark_invoice_printed( $order );

		$meta        = WLP_Order_Logistics::read( $order );
		$tracking    = trim( (string) $meta['tracking_number'] );
		$tracking    = '' !== $tracking ? $tracking : __( 'Pending', 'woo-logistics-plugin' );
		$guide_url   = WLP_URL . 'assets/images/reconstitution-guide-current.png?v=20260620';
		$first_name   = trim( (string) $order->get_shipping_first_name() );
		$first_name   = '' !== $first_name ? $first_name : trim( (string) $order->get_billing_first_name() );
		$last_name    = trim( (string) $order->get_shipping_last_name() );
		$last_name    = '' !== $last_name ? $last_name : trim( (string) $order->get_billing_last_name() );
		$last_initial = '' !== $last_name ? strtoupper( substr( $last_name, 0, 1 ) ) . '.' : '';
		$customer     = trim( $first_name . ' ' . $last_initial );
		$order_date  = $order->get_date_created();
		$customer_note = trim( (string) $order->get_customer_note() );
		$subtotal    = (float) $order->get_subtotal();
		$discount    = $this->invoice_discount_total( $order );
		$shipping    = (float) $order->get_shipping_total();
		$total       = (float) $order->get_total();
		$item_count  = count( $order->get_items() );
		$invoice_classes = array( 'wlp-invoice' );
		if ( $item_count >= 7 ) {
			$invoice_classes[] = 'wlp-invoice--many-items';
		} elseif ( $item_count >= 4 ) {
			$invoice_classes[] = 'wlp-invoice--several-items';
		}

		nocache_headers();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( sprintf( 'PeptideGo Order %s', $order->get_order_number() ) ); ?></title>
			<link rel="preconnect" href="https://fonts.googleapis.com">
			<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
			<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
			<style>
				@page { margin: 0.35in; size: letter portrait; }
				* { box-sizing: border-box; }
				body {
					color: #111;
					font-family: Arial, Helvetica, sans-serif;
					font-size: 12px;
					line-height: 1.35;
					margin: 0;
				}
				.wlp-invoice {
					margin: 0 auto;
					max-width: 100%;
				}
				.wlp-invoice__header {
					align-items: flex-start;
					border-bottom: 2px solid #111;
					display: flex;
					justify-content: space-between;
					padding-bottom: 7px;
				}
				.wlp-invoice__brand {
					color: #111;
					font-family: 'Montserrat', Arial, Helvetica, sans-serif;
					font-size: 24px;
					font-weight: 800;
					letter-spacing: -0.02em;
				}
				.wlp-invoice__title {
					font-size: 11px;
					font-weight: 700;
					letter-spacing: 0.08em;
					margin-top: 4px;
					text-transform: uppercase;
				}
				.wlp-invoice__meta {
					font-size: 11px;
					text-align: right;
				}
				.wlp-invoice__meta strong {
					display: block;
					font-size: 20px;
					line-height: 1.1;
				}
				.wlp-invoice__tracking {
					font-size: 10px;
					font-weight: 700;
					margin-top: 4px;
				}
				.wlp-invoice__items {
					border-collapse: collapse;
					margin-top: 7px;
					width: 100%;
				}
				.wlp-invoice__items th,
				.wlp-invoice__items td {
					border-bottom: 1px solid #d8dde5;
					padding: 7px 6px;
					text-align: left;
					vertical-align: top;
				}
				.wlp-invoice__items th {
					font-size: 10px;
					letter-spacing: 0.08em;
					text-transform: uppercase;
				}
				.wlp-invoice__items tfoot td {
					border-bottom: 0;
					font-size: 11px;
					font-weight: 700;
					padding-bottom: 0;
					padding-top: 6px;
				}
				.wlp-invoice__items tfoot .wlp-invoice__summary {
					text-align: right;
				}
				.wlp-invoice__summary span {
					display: inline-block;
					margin-left: 16px;
					white-space: nowrap;
				}
				.wlp-invoice__items .is-number {
					text-align: right;
					white-space: nowrap;
				}
				.wlp-invoice__note {
					-webkit-print-color-adjust: exact;
					background: #fff2a8;
					border: 3px solid #111;
					border-radius: 6px;
					font-size: 14px;
					font-weight: 700;
					line-height: 1.45;
					margin-top: 7px;
					padding: 10px 12px;
					print-color-adjust: exact;
				}
				.wlp-invoice__note strong {
					display: block;
					font-family: 'Montserrat', Arial, Helvetica, sans-serif;
					font-size: 12px;
					font-weight: 800;
					letter-spacing: 0.08em;
					margin-bottom: 4px;
					text-transform: uppercase;
				}
				.wlp-invoice__fulfillment {
					background: #fff7d6;
					border: 2px solid #c79600;
					border-radius: 6px;
					color: #111827;
					font-size: 12px;
					font-weight: 800;
					margin-top: 7px;
					padding: 7px 9px;
					text-align: center;
					text-transform: uppercase;
				}
				.wlp-invoice__footer {
					align-items: center;
					border-top: 0;
					display: flex;
					font-size: 11px;
					gap: 12px;
					justify-content: center;
					margin-top: 6px;
					padding-top: 0;
				}
				.wlp-invoice__footer strong {
					white-space: nowrap;
				}
				.wlp-invoice__actions {
					margin: 14px 0;
					text-align: right;
				}
				@media print {
					.wlp-invoice__actions { display: none; }
					.wlp-invoice__fulfillment,
					.wlp-invoice__items th,
					.wlp-invoice__items td,
					.wlp-invoice__footer { border-color: #bbb; }
				}
				.wlp-reconstitution-guide {
					align-items: center;
					display: flex;
					justify-content: center;
					margin-top: 10px;
					text-align: center;
				}
				.wlp-reconstitution-guide img {
					display: block;
					height: 5.05in;
					max-height: 5.05in;
					max-width: 7.45in;
					object-fit: fill;
					width: 7.45in;
				}
				.wlp-invoice--several-items .wlp-invoice__items th,
				.wlp-invoice--several-items .wlp-invoice__items td,
				.wlp-invoice--many-items .wlp-invoice__items th,
				.wlp-invoice--many-items .wlp-invoice__items td {
					padding-bottom: 5px;
					padding-top: 5px;
				}
				.wlp-invoice--many-items .wlp-reconstitution-guide img {
					height: 4.55in;
					max-height: 4.55in;
				}
			</style>
		</head>
		<body>
			<div class="wlp-invoice__actions">
				<button type="button" onclick="window.print()"><?php echo esc_html__( 'Print invoice + guide', 'woo-logistics-plugin' ); ?></button>
			</div>
			<main class="<?php echo esc_attr( implode( ' ', $invoice_classes ) ); ?>">
				<header class="wlp-invoice__header">
					<div>
						<div class="wlp-invoice__brand">PeptideGo</div>
						<div class="wlp-invoice__title"><?php echo esc_html__( 'Packing slip + reconstitution guide', 'woo-logistics-plugin' ); ?></div>
					</div>
					<div class="wlp-invoice__meta">
						<strong><?php echo esc_html( sprintf( 'Order #%s', $order->get_order_number() ) ); ?></strong>
						<div><?php echo esc_html( $customer ); ?></div>
						<div class="wlp-invoice__tracking"><?php echo esc_html( sprintf( 'Tracking: %s', $tracking ) ); ?></div>
					</div>
				</header>


				<?php if ( $this->is_wholesale_order( $order ) && $this->wholesale_quote_lines( $order ) ) : ?>
					<?php $this->render_wholesale_invoice_items( $order ); ?>
				<?php else : ?>
				<table class="wlp-invoice__items">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Item', 'woo-logistics-plugin' ); ?></th>
							<th class="is-number"><?php echo esc_html__( 'Qty', 'woo-logistics-plugin' ); ?></th>
							<th class="is-number"><?php echo esc_html__( 'Price', 'woo-logistics-plugin' ); ?></th>
							<th class="is-number"><?php echo esc_html__( 'Line total', 'woo-logistics-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $order->get_items() as $item ) : ?>
							<?php
							$quantity   = max( 1, (int) $item->get_quantity() );
							$line_total = (float) $item->get_subtotal();
							$unit_price = $line_total / $quantity;
							?>
							<tr>
								<td><?php echo esc_html( $item->get_name() ); ?></td>
								<td class="is-number"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
								<td class="is-number"><?php echo esc_html( $this->invoice_money( $order, $unit_price ) ); ?></td>
								<td class="is-number"><?php echo esc_html( $this->invoice_money( $order, $line_total ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="4" class="wlp-invoice__summary">
								<span><?php echo esc_html( sprintf( 'Subtotal: %s', $this->invoice_money( $order, $subtotal ) ) ); ?></span>
								<?php if ( $discount > 0 ) : ?>
									<span><?php echo esc_html( sprintf( 'Discount: -%s', $this->invoice_money( $order, $discount ) ) ); ?></span>
								<?php endif; ?>
								<span><?php echo esc_html( sprintf( 'Shipping: %s', $this->invoice_money( $order, $shipping ) ) ); ?></span>
								<span><?php echo esc_html( sprintf( 'Total: %s', $this->invoice_money( $order, $total ) ) ); ?></span>
							</td>
						</tr>
					</tfoot>
				</table>

				<?php endif; ?>
				<?php if ( '' !== $customer_note ) : ?>
					<div class="wlp-invoice__note">
						<strong><?php echo esc_html__( 'Important customer note', 'woo-logistics-plugin' ); ?></strong>
						<?php echo nl2br( esc_html( $customer_note ) ); ?>
					</div>
				<?php endif; ?>

				<?php $this->render_alcohol_pads_invoice_reminder( $order ); ?>

				<section class="wlp-reconstitution-guide" aria-label="<?php echo esc_attr__( 'Reconstitution guide', 'woo-logistics-plugin' ); ?>">
					<img src="<?php echo esc_url( $guide_url ); ?>" alt="<?php echo esc_attr__( 'Reconstitution quick guide', 'woo-logistics-plugin' ); ?>">
				</section>
				<footer class="wlp-invoice__footer">
					<strong>Please refrigerate after reconstitution. Reconstitution calculator: peptidego.ca/calc</strong>
				</footer>
			</main>
			<script>
				window.addEventListener('load', function () {
					window.setTimeout(function () {
						window.print();
					}, 300);
				});
			</script>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Renders one printable invoice + guide sheet.
	 */
	private function render_wholesale_invoice_items( WC_Order $order ): void {
		$lines = $this->wholesale_quote_lines( $order );
		$totals = $this->wholesale_quote_totals( $order );
		?>
		<table class="wlp-invoice__items" data-wlp-wholesale-invoice>
			<thead><tr><th>Item</th><th class="is-number">Qty</th><th class="is-number">Price</th><th class="is-number">Line total</th></tr></thead>
			<tbody><?php foreach ( $lines as $line ) : ?>
				<tr><td><?php echo esc_html( $line['name'] ); ?></td><td class="is-number"><?php echo esc_html( (string) $line['quantity'] ); ?></td><td class="is-number"><?php echo esc_html( $line['unitPrice'] ); ?></td><td class="is-number"><?php echo esc_html( $line['lineTotal'] ); ?></td></tr>
			<?php endforeach; ?></tbody>
			<tfoot><tr><td colspan="4" class="wlp-invoice__summary">
				<span>Subtotal before tax: <?php echo esc_html( $totals['subtotalFormatted'] ); ?></span>
				<?php if ( 0.0 !== $totals['credit'] ) : ?><span>Wholesale credit/discount included: <?php echo esc_html( $totals['creditFormatted'] ); ?></span><?php endif; ?>
				<?php if ( ! empty( $totals['taxRows'] ) ) : foreach ( $totals['taxRows'] as $tax_row ) : ?>
                <span><?php echo esc_html( $tax_row['label'] . ': ' . $tax_row['formatted'] ); ?></span>
                <?php endforeach; else : ?><span><?php echo esc_html( $totals['taxLabel'] . ': ' . $totals['taxFormatted'] ); ?></span><?php endif; ?>
				<span>Shipping: Included</span>
				<span>Total including tax: <?php echo esc_html( $totals['totalFormatted'] ); ?></span>
			</td></tr></tfoot>
		</table>
		<p>Saved wholesale quote breakdown. Full order contents; use the package packing sheet for each split shipment.</p>
		<p>Wholesale Refund &amp; Return Policy: <a href="<?php echo esc_url( home_url( '/refunds/#wholesale-policy' ) ); ?>">peptidego.ca/refunds/#wholesale-policy</a></p>
		<?php
	}

	private function render_invoice_sheet( WC_Order $order ): void {
		$meta          = WLP_Order_Logistics::read( $order );
		$tracking      = trim( (string) $meta['tracking_number'] );
		$tracking      = '' !== $tracking ? $tracking : __( 'Pending', 'woo-logistics-plugin' );
		$guide_url     = WLP_URL . 'assets/images/reconstitution-guide-current.png?v=20260620';
		$first_name    = trim( (string) $order->get_shipping_first_name() );
		$first_name    = '' !== $first_name ? $first_name : trim( (string) $order->get_billing_first_name() );
		$last_name     = trim( (string) $order->get_shipping_last_name() );
		$last_name     = '' !== $last_name ? $last_name : trim( (string) $order->get_billing_last_name() );
		$last_initial  = '' !== $last_name ? strtoupper( substr( $last_name, 0, 1 ) ) . '.' : '';
		$customer      = trim( $first_name . ' ' . $last_initial );
		$customer_note = trim( (string) $order->get_customer_note() );
		$subtotal      = (float) $order->get_subtotal();
		$discount      = $this->invoice_discount_total( $order );
		$shipping      = (float) $order->get_shipping_total();
		$total         = (float) $order->get_total();
		$item_count    = count( $order->get_items() );
		$invoice_classes = array( 'wlp-invoice' );

		if ( $item_count >= 7 ) {
			$invoice_classes[] = 'wlp-invoice--many-items';
		} elseif ( $item_count >= 4 ) {
			$invoice_classes[] = 'wlp-invoice--several-items';
		}
		?>
		<main class="<?php echo esc_attr( implode( ' ', $invoice_classes ) ); ?>">
			<header class="wlp-invoice__header">
				<div>
					<div class="wlp-invoice__brand">PeptideGo</div>
					<div class="wlp-invoice__title"><?php echo esc_html__( 'Packing slip + reconstitution guide', 'woo-logistics-plugin' ); ?></div>
				</div>
				<div class="wlp-invoice__meta">
					<strong><?php echo esc_html( sprintf( 'Order #%s', $order->get_order_number() ) ); ?></strong>
					<div><?php echo esc_html( $customer ); ?></div>
					<div class="wlp-invoice__tracking"><?php echo esc_html( sprintf( 'Tracking: %s', $tracking ) ); ?></div>
				</div>
			</header>

			<?php if ( $this->is_wholesale_order( $order ) && $this->wholesale_quote_lines( $order ) ) : ?>
				<?php $this->render_wholesale_invoice_items( $order ); ?>
			<?php else : ?>
			<table class="wlp-invoice__items">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Item', 'woo-logistics-plugin' ); ?></th>
						<th class="is-number"><?php echo esc_html__( 'Qty', 'woo-logistics-plugin' ); ?></th>
						<th class="is-number"><?php echo esc_html__( 'Price', 'woo-logistics-plugin' ); ?></th>
						<th class="is-number"><?php echo esc_html__( 'Line total', 'woo-logistics-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $order->get_items() as $item ) : ?>
						<?php
						$quantity   = max( 1, (int) $item->get_quantity() );
						$line_total = (float) $item->get_subtotal();
						$unit_price = $line_total / $quantity;
						?>
						<tr>
							<td><?php echo esc_html( $item->get_name() ); ?></td>
							<td class="is-number"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
							<td class="is-number"><?php echo esc_html( $this->invoice_money( $order, $unit_price ) ); ?></td>
							<td class="is-number"><?php echo esc_html( $this->invoice_money( $order, $line_total ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="4" class="wlp-invoice__summary">
							<span><?php echo esc_html( sprintf( 'Subtotal: %s', $this->invoice_money( $order, $subtotal ) ) ); ?></span>
							<?php if ( $discount > 0 ) : ?>
								<span><?php echo esc_html( sprintf( 'Discount: -%s', $this->invoice_money( $order, $discount ) ) ); ?></span>
							<?php endif; ?>
							<span><?php echo esc_html( sprintf( 'Shipping: %s', $this->invoice_money( $order, $shipping ) ) ); ?></span>
							<span><?php echo esc_html( sprintf( 'Total: %s', $this->invoice_money( $order, $total ) ) ); ?></span>
						</td>
					</tr>
				</tfoot>
			</table>

			<?php endif; ?>
			<?php if ( '' !== $customer_note ) : ?>
				<div class="wlp-invoice__note">
					<strong><?php echo esc_html__( 'Important customer note', 'woo-logistics-plugin' ); ?></strong>
					<?php echo nl2br( esc_html( $customer_note ) ); ?>
				</div>
			<?php endif; ?>

			<?php $this->render_alcohol_pads_invoice_reminder( $order ); ?>

			<section class="wlp-reconstitution-guide" aria-label="<?php echo esc_attr__( 'Reconstitution guide', 'woo-logistics-plugin' ); ?>">
				<img src="<?php echo esc_url( $guide_url ); ?>" alt="<?php echo esc_attr__( 'Reconstitution quick guide', 'woo-logistics-plugin' ); ?>">
			</section>
			<footer class="wlp-invoice__footer">
				<strong>Please refrigerate after reconstitution. Reconstitution calculator: peptidego.ca/calc</strong>
			</footer>
		</main>
		<?php
	}

	/**
	 * Returns Woo statuses that can appear in the logistics tabs.
	 *
	 * @return array<int, string>
	 */
	private function logistics_query_statuses(): array {
		$statuses   = WLP_Settings::eligible_statuses();
		$statuses[] = 'completed';
		$statuses[] = 'pending';

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
			'wholesale_quotes'  => __( 'Quotes', 'woo-logistics-plugin' ),
			'wholesale_to_ship' => __( 'Wholesale to ship', 'woo-logistics-plugin' ),
			'wholesale_shipped' => __( 'Wholesale shipped', 'woo-logistics-plugin' ),
			'wholesale_delivered' => __( 'Wholesale delivered', 'woo-logistics-plugin' ),
			'to_be_shipped'   => __( 'To be shipped', 'woo-logistics-plugin' ),
			'in_transit'      => __( 'In transit', 'woo-logistics-plugin' ),
			'returns'         => __( 'Returns', 'woo-logistics-plugin' ),
			'delivered'       => __( 'Delivered', 'woo-logistics-plugin' ),
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
		$groups = array(
			__( 'Wholesale', 'woo-logistics-plugin' ) => array( 'wholesale_quotes', 'wholesale_to_ship', 'wholesale_shipped', 'wholesale_delivered' ),
			__( 'Retail and returns', 'woo-logistics-plugin' ) => array( 'to_be_shipped', 'in_transit', 'returns', 'delivered' ),
		);
		$states = $this->logistics_states();
		?>
		<div class="wlp-tab-groups">
			<?php foreach ( $groups as $group_label => $group_states ) : ?>
				<div class="wlp-tab-group">
					<strong class="wlp-tab-group__label"><?php echo esc_html( $group_label ); ?></strong>
					<nav class="nav-tab-wrapper wlp-tabs" aria-label="<?php echo esc_attr( $group_label ); ?>">
						<?php foreach ( $group_states as $state ) : ?>
							<?php
							$label = $states[ $state ];
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
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders simple workload filters for the active wholesale tab.
	 *
	 * @param array<int, WC_Order> $orders Orders in the active logistics state.
	 */
	private function render_wholesale_workload_filters( array $orders, string $current_state ): void {
		if ( ! str_starts_with( $current_state, 'wholesale_' ) || 'wholesale_delivered' === $current_state ) {
			return;
		}

		$counts = array(
			'all'     => count( $orders ),
			'action'  => 0,
			'waiting' => 0,
			'ready'   => 0,
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$stage = $this->wholesale_workflow_stage( $order );
			if ( in_array( $stage, array( 'stock_check', 'final_quote', 'supplier_order', 'ready_to_ship' ), true ) ) {
				$counts['action']++;
			}
			if ( in_array( $stage, array( 'awaiting_customer', 'awaiting_payment', 'inbound_hamilton' ), true ) ) {
				$counts['waiting']++;
			}
			if ( 'ready_to_ship' === $stage ) {
				$counts['ready']++;
			}
		}

		$filters = array(
			'all'     => __( 'All', 'woo-logistics-plugin' ),
			'action'  => __( 'Needs action', 'woo-logistics-plugin' ),
			'waiting' => __( 'Waiting', 'woo-logistics-plugin' ),
			'ready'   => __( 'Ready to ship', 'woo-logistics-plugin' ),
		);
		?>
		<div class="wlp-wholesale-filters" data-wlp-wholesale-filters aria-label="<?php echo esc_attr__( 'Wholesale workload filters', 'woo-logistics-plugin' ); ?>">
			<?php foreach ( $filters as $key => $label ) : ?>
				<button class="button <?php echo esc_attr( 'all' === $key ? 'is-active' : '' ); ?>" type="button" data-wlp-wholesale-filter="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?> <span><?php echo esc_html( (string) $counts[ $key ] ); ?></span></button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders the vial prelabel totals for unpacked orders in the active tab.
	 *
	 * @param array<int, WC_Order> $orders Orders in the current logistics tab.
	 */
	private function render_prelabel_summary( array $orders, string $current_state ): void {
		if ( 'to_be_shipped' !== $current_state ) {
			return;
		}

		$summary = $this->prelabel_summary( $orders );
		?>
		<section class="wlp-prelabel-summary" data-wlp-prelabel-summary aria-label="<?php echo esc_attr__( 'Advance packing supplies', 'woo-logistics-plugin' ); ?>">
			<div class="wlp-prelabel-summary__header">
				<div>
					<h2><?php echo esc_html__( 'Prep needed', 'woo-logistics-plugin' ); ?></h2>
					<p><?php echo esc_html__( 'Only counts unpacked orders in this tab. Vial cases are calculated per order and exclude BAC water.', 'woo-logistics-plugin' ); ?></p>
				</div>
				<div class="wlp-prelabel-summary__counts">
					<span><?php echo esc_html( sprintf( _n( '%d unpacked order', '%d unpacked orders', $summary['order_count'], 'woo-logistics-plugin' ), $summary['order_count'] ) ); ?></span>
					<strong data-wlp-prelabel-count="peptide" data-count="<?php echo esc_attr( (string) $summary['vial_count'] ); ?>"><?php echo esc_html( sprintf( _n( '%d peptide vial', '%d peptide vials', $summary['vial_count'], 'woo-logistics-plugin' ), $summary['vial_count'] ) ); ?></strong>
					<em data-wlp-prelabel-count="case" data-count="<?php echo esc_attr( (string) $summary['case_count'] ); ?>"><?php echo esc_html( sprintf( _n( '%d vial case', '%d vial cases', $summary['case_count'], 'woo-logistics-plugin' ), $summary['case_count'] ) ); ?></em>
					<em data-wlp-prelabel-count="bac" data-count="<?php echo esc_attr( (string) $summary['bac_count'] ); ?>"><?php echo esc_html( sprintf( _n( '%d BAC water', '%d BAC waters', $summary['bac_count'], 'woo-logistics-plugin' ), $summary['bac_count'] ) ); ?></em>
					<button class="button-link" type="button" data-wlp-prelabel-reset><?php echo esc_html__( 'Reset checklist', 'woo-logistics-plugin' ); ?></button>
				</div>
			</div>
			<h3><?php echo esc_html__( 'Peptide vials', 'woo-logistics-plugin' ); ?></h3>
			<?php if ( empty( $summary['items'] ) ) : ?>
				<p class="wlp-prelabel-summary__empty"><?php echo esc_html__( 'No unpacked peptide vials need labels right now.', 'woo-logistics-plugin' ); ?></p>
			<?php else : ?>
				<ul class="wlp-prelabel-summary__items">
					<?php foreach ( $summary['items'] as $item ) : ?>
						<li data-wlp-prelabel-row data-prelabel-type="peptide" data-prelabel-quantity="<?php echo esc_attr( (string) $item['quantity'] ); ?>" data-prelabel-key="<?php echo esc_attr( sha1( 'peptide|' . $item['name'] . '|' . (string) $item['quantity'] . '|' . implode( ',', $summary['orders'] ) ) ); ?>">
							<span><?php echo esc_html( $item['name'] ); ?></span>
							<strong><?php echo esc_html( (string) $item['quantity'] ); ?></strong>
							<button class="button button-small" type="button" data-wlp-prelabel-done><?php echo esc_html__( 'Done', 'woo-logistics-plugin' ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<h3><?php echo esc_html__( 'Vial cases', 'woo-logistics-plugin' ); ?></h3>
			<?php if ( empty( $summary['case_items'] ) ) : ?>
				<p class="wlp-prelabel-summary__empty"><?php echo esc_html__( 'No vial cases are needed right now.', 'woo-logistics-plugin' ); ?></p>
			<?php else : ?>
				<ul class="wlp-prelabel-summary__items is-vial-case">
					<?php foreach ( $summary['case_items'] as $item ) : ?>
						<li data-wlp-prelabel-row data-prelabel-type="case" data-prelabel-quantity="<?php echo esc_attr( (string) $item['quantity'] ); ?>" data-prelabel-key="<?php echo esc_attr( sha1( 'case|' . $item['name'] . '|' . (string) $item['quantity'] . '|' . implode( ',', $summary['orders'] ) ) ); ?>">
							<span><?php echo esc_html( $item['name'] ); ?></span>
							<strong><?php echo esc_html( (string) $item['quantity'] ); ?></strong>
							<button class="button button-small" type="button" data-wlp-prelabel-done><?php echo esc_html__( 'Done', 'woo-logistics-plugin' ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<h3><?php echo esc_html__( 'BAC water labels', 'woo-logistics-plugin' ); ?></h3>
			<?php if ( empty( $summary['bac_items'] ) ) : ?>
				<p class="wlp-prelabel-summary__empty"><?php echo esc_html__( 'No unpacked BAC waters need labels right now.', 'woo-logistics-plugin' ); ?></p>
			<?php else : ?>
				<ul class="wlp-prelabel-summary__items is-bac-water">
					<?php foreach ( $summary['bac_items'] as $item ) : ?>
						<li data-wlp-prelabel-row data-prelabel-type="bac" data-prelabel-quantity="<?php echo esc_attr( (string) $item['quantity'] ); ?>" data-prelabel-key="<?php echo esc_attr( sha1( 'bac|' . $item['name'] . '|' . (string) $item['quantity'] . '|' . implode( ',', $summary['orders'] ) ) ); ?>">
							<span><?php echo esc_html( $item['name'] ); ?></span>
							<strong><?php echo esc_html( (string) $item['quantity'] ); ?></strong>
							<button class="button button-small" type="button" data-wlp-prelabel-done><?php echo esc_html__( 'Done', 'woo-logistics-plugin' ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $summary['orders'] ) ) : ?>
				<div class="wlp-prelabel-summary__orders">
					<span><?php echo esc_html__( 'Orders counted:', 'woo-logistics-plugin' ); ?></span>
					<?php echo esc_html( implode( ', ', $summary['orders'] ) ); ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Classifies an order using the same Woo logistics state rules as the storefront hub.
	 */
	private function order_logistics_state( WC_Order $order ): string {
		if ( WLP_Split_Shipments::active( $order ) ) {
			$split = WLP_Split_Shipments::summary( $order );
			if ( $this->is_wholesale_order( $order ) ) { return $split['allDelivered'] ? 'wholesale_delivered' : ( $split['allShipped'] ? 'wholesale_shipped' : 'wholesale_to_ship' ); }
			return $split['allDelivered'] ? 'delivered' : ( $split['allShipped'] ? 'in_transit' : 'to_be_shipped' );
		}
		$meta = WLP_Order_Logistics::read( $order );

		if ( WLP_Order_Logistics::has_return_label( $order ) ) {
			return 'returns';
		}

		if ( $this->is_wholesale_order( $order ) ) {
			$workflow_stage = $this->wholesale_workflow_stage( $order );
			if ( '' !== $meta['delivered_at'] ) { return 'wholesale_delivered'; }

			if ( 'shipped' === $workflow_stage || '' !== $meta['shipped_at'] || '' !== $meta['delivered_at'] ) {
				return 'wholesale_shipped';
			}

			if ( in_array( $workflow_stage, array( 'supplier_order', 'inbound_hamilton', 'ready_to_ship' ), true ) ) {
				return 'wholesale_to_ship';
			}

			return 'wholesale_quotes';
		}

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
		$return_meta     = WLP_Order_Logistics::read_return( $order );
		$has_label       = WLP_Order_Logistics::has_label( $order );
		$has_return_label = WLP_Order_Logistics::has_return_label( $order );
		$invoice_printed = '' !== $meta['invoice_printed_at'];
		$is_packed       = '' !== $meta['packed_at'];
		$package_preview = $this->order_package_preview( $order, $meta, $has_label );
		$state           = $this->order_logistics_state( $order );
		$is_delivered    = in_array( $state, array( 'delivered', 'wholesale_delivered' ), true );
		$tracking_url    = $this->tracking_url( $meta );
		$return_tracking_url = $this->tracking_url( $return_meta );
		$alcohol_pads_due = $this->order_qualifies_for_alcohol_pads( $order );
		$delivery_protection = $this->order_delivery_protection_defaults( $order );
		$is_wholesale    = $this->is_wholesale_order( $order );
		$workflow_stage  = $is_wholesale ? $this->wholesale_workflow_stage( $order ) : '';
		$is_shipped_view = in_array( $state, array( 'in_transit', 'wholesale_shipped' ), true );
		$estimate_date   = $is_shipped_view ? $this->current_delivery_estimate_date( $order, $meta ) : '';
		$delivery_late   = $is_shipped_view && $this->delivery_estimate_is_late( $estimate_date );
		$detail_label    = $is_shipped_view ? __( 'Estimated delivery', 'woo-logistics-plugin' ) : __( 'Package', 'woo-logistics-plugin' );
		$detail_value    = $is_shipped_view ? $this->delivery_estimate_label( $estimate_date ) : $package_preview['preset'];
		$vial_case       = $this->vial_case_summary( $order );
		$pill            = $this->logistics_state_label( $state );
		$pill_class      = match ( $state ) {
			'delivered', 'wholesale_delivered' => 'is-delivered',
			'in_transit'       => $delivery_late ? 'is-late' : 'is-ready',
			'returns'          => 'is-return',
			'wholesale_quotes', 'wholesale_to_ship' => 'is-wholesale',
			'wholesale_shipped' => 'is-delivered',
			default            => 'is-pending',
		};
		$is_selectable = ! $is_delivered || $has_label;
		?>
		<section class="wlp-card<?php echo esc_attr( $delivery_late ? ' is-delivery-late' : '' ); ?>" data-wlp-order-card data-is-wholesale="<?php echo esc_attr( $is_wholesale ? 'yes' : 'no' ); ?>" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-logistics-state="<?php echo esc_attr( $state ); ?>" data-wholesale-workflow-stage="<?php echo esc_attr( $workflow_stage ); ?>" data-has-label="<?php echo esc_attr( $has_label ? 'yes' : 'no' ); ?>" data-invoice-printed="<?php echo esc_attr( $invoice_printed ? 'yes' : 'no' ); ?>" data-packed="<?php echo esc_attr( $is_packed ? 'yes' : 'no' ); ?>" data-alcohol-pads-due="<?php echo esc_attr( $alcohol_pads_due ? 'yes' : 'no' ); ?>" data-delivery-late="<?php echo esc_attr( $delivery_late ? 'yes' : 'no' ); ?>" data-print-url="<?php echo esc_url( $has_label ? $this->print_url( $order ) : '' ); ?>" data-wlp-signature-default="<?php echo esc_attr( $delivery_protection['signature'] ? 'yes' : 'no' ); ?>" data-wlp-card-for-pickup-default="<?php echo esc_attr( $delivery_protection['card_for_pickup'] ? 'yes' : 'no' ); ?>">
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
			<?php if ( $is_wholesale && WLP_Split_Shipments::active( $order ) ) : ?>
				<?php $split_packages = WLP_Split_Shipments::read( $order )['packages']; ?>
				<section class="wlp-split-card-ribbon" aria-label="Split shipment status">
					<strong><?php $joined_ids = WLP_Split_Shipments::joined_ids( $order ); echo esc_html( ( count( $joined_ids ) > 1 ? 'JOINED ORDERS #' . implode( ' + #', $joined_ids ) : ( count( $split_packages ) === 1 ? 'SINGLE SHIPMENT' : 'SPLIT ORDER' ) ) . ' · ' . count( $split_packages ) . ' PACKAGE(S)' ); ?></strong>
					<?php if ( count( $joined_ids ) > 1 ) : ?>
						<p>One fulfillment card. Original orders and payments remain separate.</p>
						<details><summary>View original orders</summary><?php foreach ( $joined_ids as $joined_id ) : $original = wc_get_order( $joined_id ); ?><p><a href="<?php echo esc_url( $original->get_edit_order_url() ); ?>" target="_blank" rel="noopener">Order #<?php echo esc_html( (string) $joined_id ); ?></a></p><?php endforeach; ?></details>
					<?php endif; ?>
					<ul>
						<?php foreach ( $split_packages as $package_index => $split_package ) : ?>
							<?php
							$package_state = ! empty( $split_package['deliveredAt'] ) ? 'Delivered' : ( ! empty( $split_package['shippedAt'] ) ? 'Shipped' : ( ! empty( $split_package['label']['tracking_number'] ) ? 'Label ready · handoff not recorded' : ( ! empty( $split_package['purchaseAttempt'] ) ? 'Label purchase needs review' : 'Draft · needs label' ) ) );
							?>
							<li><b><?php echo esc_html( 'P' . ( $package_index + 1 ) . ' · ' . array_sum( $split_package['items'] ) . ' items' ); ?></b><span><?php echo esc_html( $package_state ); ?>
							<?php if ( ! empty( $split_package['label']['shipment_id'] ) ) : ?>
							<br><a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( WLP_Split_Shipments::print_url( $order, $split_package['id'], 'label' ) ); ?>">Print label <?php echo esc_html( (string) ( $package_index + 1 ) ); ?></a>
							<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( WLP_Split_Shipments::print_url( $order, $split_package['id'], 'packing' ) ); ?>">Packing sheet <?php echo esc_html( (string) ( $package_index + 1 ) ); ?></a>
							<?php endif; ?>
							<?php if ( empty( $split_package['label'] ) && empty( $split_package['purchaseAttempt'] ) && empty( $split_package['shippedAt'] ) && empty( $split_package['deliveredAt'] ) ) : ?>
							<br><button type="button" class="button button-primary" data-wlp-split-open data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-package-focus="<?php echo esc_attr( $split_package['id'] ); ?>">Open package <?php echo esc_html( (string) ( $package_index + 1 ) ); ?></button>
							<?php endif; ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p><?php foreach ( $joined_ids as $invoice_id ) : $invoice_order = wc_get_order( $invoice_id ); ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $this->invoice_url( $invoice_order ) ); ?>">Invoice + guide #<?php echo esc_html( (string) $invoice_id ); ?></a> <?php endforeach; ?></p>
				</section>
			<?php endif; ?>
			<?php if ( $alcohol_pads_due ) : ?>
				<div class="wlp-fulfillment-reminder"><?php echo esc_html__( 'Add 20 complimentary alcohol pads', 'woo-logistics-plugin' ); ?></div>
			<?php endif; ?>
			<?php if ( $delivery_late ) : ?>
				<div class="wlp-late-delivery-banner">
					<strong><?php echo esc_html__( 'Delivery needs review', 'woo-logistics-plugin' ); ?></strong>
					<span><?php echo esc_html( sprintf( __( 'Expected delivery was %s. Check Canada Post tracking and follow up if needed.', 'woo-logistics-plugin' ), $detail_value ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $is_wholesale ) : ?>
				<?php foreach ( WLP_Split_Shipments::matching_orders( $order ) as $matching_id ) : ?>
					<div class="wlp-split-card-ribbon"><strong>Matching order #<?php echo esc_html( (string) $matching_id ); ?></strong><p>Same customer email and shipping address.</p><button type="button" class="button" data-wlp-join-order data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-other-order-id="<?php echo esc_attr( (string) $matching_id ); ?>">Join into one shipment</button></div>
				<?php endforeach; ?>
				<div class="wlp-wholesale-badge"><?php echo esc_html__( 'Wholesale order', 'woo-logistics-plugin' ); ?></div>
				<button type="button" class="button" data-wlp-split-open data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo count( WLP_Split_Shipments::joined_ids( $order ) ) > 1 ? 'Open joined shipment' : 'Shipment / packages'; ?></button>
				<div class="wlp-card-packing">
					<?php $card_workflow = $this->wholesale_workflow_payload( $order ); ?><strong data-wlp-card-supplier-status><?php echo esc_html( $card_workflow['label'] ); ?></strong><p data-wlp-card-supplier-next><?php echo esc_html( 'Next: ' . $card_workflow['task'] ); ?></p>
					<p>Order contents · send together or split in Shipment / packages.</p>
					<ul>
						<?php foreach ( $this->wholesale_packing_catalog( $order ) as $line ) : ?>
							<li><span><?php echo esc_html( $line['name'] ); ?></span><b>&times;<?php echo esc_html( $line['quantity'] ); ?></b></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! $this->wholesale_packing_catalog( $order ) ) : ?>
						<p><b>Review order contents before packing.</b></p>
						<p><?php echo esc_html( $order->get_meta( '_pg_wholesale_quote_products', true ) ); ?></p>
					<?php foreach ( $this->quick_view_items( $order ) as $item ) : ?>
						<p><?php echo esc_html( $item['name'] . ' ×' . $item['quantity'] ); ?> <small>(Woo item)</small></p>
					<?php endforeach; ?>
					<?php endif; ?>
					<p><b>Vial case:</b> <?php echo esc_html( $vial_case['label'] ); ?></p>
					<?php if ( $order->get_customer_note() ) : ?>
						<details class="wlp-tidy-details" <?php echo strlen( $order->get_customer_note() ) <= 300 ? 'open' : ''; ?>><summary>Customer note · review before packing</summary><p class="wlp-packing-note"><?php echo esc_html( $order->get_customer_note() ); ?></p></details>
					<?php endif; ?>
					<?php if ( $order->get_meta( self::WHOLESALE_NOTES_META, true ) ) : ?>
						<p class="wlp-packing-note"><b>Private notes:</b> <?php echo esc_html( $order->get_meta( self::WHOLESALE_NOTES_META, true ) ); ?></p>
					<?php endif; ?>
				</div>
				<details class="wlp-tidy-details">
					<summary>Workflow, supplier tracking &amp; quote</summary>
					<?php $this->render_wholesale_quote_summary( $order ); ?>
				</details>
			<?php endif; ?>
			<?php if ( 'returns' === $state ) : ?>
				<div class="wlp-return-banner">
					<strong><?php echo esc_html__( 'Return in progress', 'woo-logistics-plugin' ); ?></strong>
					<span><?php echo esc_html__( 'Customer has a return label. Watch this card until the package is received, then complete the refund/resolution manually.', 'woo-logistics-plugin' ); ?></span>
				</div>
			<?php endif; ?>
			<details class="wlp-card-details">
				<summary>
					<span><?php echo esc_html__( 'Order details', 'woo-logistics-plugin' ); ?></span>
					<small><?php echo esc_html__( 'Status, tracking + package', 'woo-logistics-plugin' ); ?></small>
				</summary>
				<dl class="wlp-facts">
				<div><dt><?php echo esc_html__( 'Status', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Logistics', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( $pill ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Invoice', 'woo-logistics-plugin' ); ?></dt><dd data-wlp-card-invoice><?php echo esc_html( $invoice_printed ? __( 'Printed', 'woo-logistics-plugin' ) : __( 'Not printed', 'woo-logistics-plugin' ) ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Packed', 'woo-logistics-plugin' ); ?></dt><dd data-wlp-card-packed><?php echo esc_html( $is_packed ? __( 'Yes', 'woo-logistics-plugin' ) : __( 'No', 'woo-logistics-plugin' ) ); ?></dd></div>
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
				<div>
					<dt><?php echo esc_html__( 'Return', 'woo-logistics-plugin' ); ?></dt>
					<dd data-wlp-card-return-tracking>
						<?php if ( '' !== $return_meta['tracking_number'] && '' !== $return_tracking_url ) : ?>
							<a target="_blank" href="<?php echo esc_url( $return_tracking_url ); ?>"><?php echo esc_html( $return_meta['tracking_number'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $return_meta['tracking_number'] ?: '-' ); ?>
						<?php endif; ?>
					</dd>
				</div>
				<div><dt data-wlp-card-detail-label><?php echo esc_html( $detail_label ); ?></dt><dd data-wlp-card-detail><?php echo esc_html( $detail_value ); ?></dd></div>
				<div><dt><?php echo esc_html__( 'Vial case', 'woo-logistics-plugin' ); ?></dt><dd><?php echo esc_html( $vial_case['label'] ); ?></dd></div>
				</dl>
			</details>
				<div class="wlp-actions">
					<?php if ( $is_wholesale ) : ?>
						<button class="button button-primary" type="button" data-wlp-open-sourcing data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Fulfillment sources', 'woo-logistics-plugin' ); ?></button>
					<?php endif; ?>
					<button class="button <?php echo $is_wholesale ? '' : 'button-primary'; ?>" type="button" data-wlp-quick-view data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Quick view', 'woo-logistics-plugin' ); ?></button>
					<?php if ( ! $is_wholesale ) : ?>
					<button class="button" type="button" data-wlp-mark-packed data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" <?php disabled( $is_packed ); ?>><?php echo esc_html( $is_packed ? __( 'Packed', 'woo-logistics-plugin' ) : __( 'Mark packed', 'woo-logistics-plugin' ) ); ?></button>
					<?php endif; ?>
					<details class="wlp-action-menu wlp-action-menu--all">
						<summary class="button"><?php echo esc_html__( 'More actions', 'woo-logistics-plugin' ); ?></summary>
						<div class="wlp-action-menu__panel">
							<div class="wlp-action-menu__group" data-wlp-ship-actions>
								<strong><?php echo esc_html__( 'Shipping', 'woo-logistics-plugin' ); ?></strong>
								<?php if ( $is_wholesale ) : ?>
					<button class="button" type="button" data-wlp-mark-packed data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" <?php disabled( $is_packed ); ?>><?php echo esc_html( $is_packed ? __( 'Packed', 'woo-logistics-plugin' ) : __( 'Mark packed', 'woo-logistics-plugin' ) ); ?></button>
					<?php if ( $is_wholesale && $order->has_status( array( 'processing', 'completed' ) ) && ! WLP_Split_Shipments::active( $order ) ) : ?>
						<button class="button" type="button" data-wlp-mark-shipped data-wlp-direct-fulfillment data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" <?php disabled( '' !== $meta['shipped_at'] || '' !== $meta['delivered_at'] ); ?>><?php echo esc_html( '' !== $meta['shipped_at'] || '' !== $meta['delivered_at'] ? __( 'Shipped', 'woo-logistics-plugin' ) : __( 'Mark shipped', 'woo-logistics-plugin' ) ); ?></button>
						<button class="button" type="button" data-wlp-mark-delivered data-wlp-direct-fulfillment data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" <?php disabled( '' !== $meta['delivered_at'] ); ?>><?php echo esc_html( '' !== $meta['delivered_at'] ? __( 'Delivered', 'woo-logistics-plugin' ) : __( 'Mark delivered', 'woo-logistics-plugin' ) ); ?></button>
					<?php endif; ?>
								<?php endif; ?>
								<?php if ( ! $is_delivered || $has_label ) : ?>
									<button class="button" type="button" data-wlp-create-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html( $has_label ? __( 'View label options', 'woo-logistics-plugin' ) : __( 'Create label', 'woo-logistics-plugin' ) ); ?></button>
								<?php endif; ?>
							<a class="button" target="_blank" data-wlp-print-invoice href="<?php echo esc_url( $this->invoice_url( $order ) ); ?>"><?php echo esc_html__( 'Invoice + guide', 'woo-logistics-plugin' ); ?></a>
							<?php if ( ! $is_wholesale && 'to_be_shipped' === $state ) : ?>
								<button class="button" type="button" data-wlp-mark-shipped data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Mark shipped', 'woo-logistics-plugin' ); ?></button>
							<?php endif; ?>
							<?php if ( $has_label ) : ?>
								<a class="button" target="_blank" data-wlp-print-label href="<?php echo esc_url( $this->print_url( $order ) ); ?>"><?php echo esc_html__( 'Print label', 'woo-logistics-plugin' ); ?></a>
								<button class="button" type="button" data-wlp-send-customer-note data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Send customer note', 'woo-logistics-plugin' ); ?></button>
								<?php if ( $delivery_late ) : ?>
									<button class="button" type="button" data-wlp-send-delay-note data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Send delay follow-up', 'woo-logistics-plugin' ); ?></button>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( ! $is_wholesale && ! $is_delivered ) : ?>
								<button class="button" type="button" data-wlp-mark-delivered data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Mark delivered', 'woo-logistics-plugin' ); ?></button>
							<?php endif; ?>
							</div>
							<div class="wlp-action-menu__group" data-wlp-return-actions>
								<strong><?php echo esc_html__( 'Returns and reships', 'woo-logistics-plugin' ); ?></strong>
							<button class="button" type="button" data-wlp-prepare-reship data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Prepare reship', 'woo-logistics-plugin' ); ?></button>
							<button class="button" type="button" data-wlp-create-return-label data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Create return label', 'woo-logistics-plugin' ); ?></button>
							<?php if ( $has_return_label ) : ?>
								<a class="button" target="_blank" data-wlp-print-return-label href="<?php echo esc_url( $this->return_print_url( $order ) ); ?>"><?php echo esc_html__( 'Print return', 'woo-logistics-plugin' ); ?></a>
								<button class="button" type="button" data-wlp-send-return-note data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Email return label + instructions', 'woo-logistics-plugin' ); ?></button>
							<?php endif; ?>
							</div>
							<button class="button wlp-button-danger" type="button" data-wlp-cancel-order data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Cancel order', 'woo-logistics-plugin' ); ?></button>
						</div>
					</details>
					<?php if ( $is_wholesale ) : ?>
						<details class="wlp-wholesale-communications">
							<summary><?php echo esc_html__( 'Customer communication', 'woo-logistics-plugin' ); ?></summary>
						<div class="wlp-wholesale-note-control">
							<label class="screen-reader-text" for="wlp-wholesale-update-<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Wholesale update', 'woo-logistics-plugin' ); ?></label>
							<select id="wlp-wholesale-update-<?php echo esc_attr( (string) $order->get_id() ); ?>" data-wlp-wholesale-update-select>
								<option value="payment_instructions"><?php echo esc_html__( 'Send final quote + payment', 'woo-logistics-plugin' ); ?></option>
								<option value="shipping_details_request"><?php echo esc_html__( 'Missing shipping details', 'woo-logistics-plugin' ); ?></option>
								<option value="payment_received"><?php echo esc_html__( 'Payment received + fulfillment', 'woo-logistics-plugin' ); ?></option>
								<option value="hamilton_hub"><?php echo esc_html__( 'Fulfillment in progress', 'woo-logistics-plugin' ); ?></option>
								<option value="inspected_label"><?php echo esc_html__( 'Tracking ready', 'woo-logistics-plugin' ); ?></option>
								<option value="shipped_tracking"><?php echo esc_html__( 'Shipped + tracking', 'woo-logistics-plugin' ); ?></option>
								<option value="refund_etransfer"><?php echo esc_html__( 'Refund by e-Transfer', 'woo-logistics-plugin' ); ?></option>
								<option value="refund_wire"><?php echo esc_html__( 'Refund by wire/EFT', 'woo-logistics-plugin' ); ?></option>
								<?php if ( ! $is_delivered ) : ?>
									<option value="delivered_complete"><?php echo esc_html__( 'Delivered + complete', 'woo-logistics-plugin' ); ?></option>
								<?php endif; ?>
							</select>
							<div class="wlp-wholesale-message-preview">
								<strong data-wlp-wholesale-preview-subject><?php echo esc_html__( 'Loading message preview...', 'woo-logistics-plugin' ); ?></strong>
								<textarea data-wlp-wholesale-message-body rows="10" aria-label="<?php echo esc_attr__( 'Wholesale message body', 'woo-logistics-plugin' ); ?>"></textarea>
							</div>
							<button class="button wlp-wholesale-action" type="button" data-wlp-wholesale-followup data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Send wholesale update', 'woo-logistics-plugin' ); ?></button>
						</div>
						</details>
					<?php endif; ?>
				</div>
		</section>
		<?php
	}

	/**
	 * Returns true when an order came from or was marked as wholesale.
	 */
	private function is_wholesale_order( WC_Order $order ): bool {
		return 'yes' === (string) $order->get_meta( WLP_Meta_Keys::IS_WHOLESALE, true )
			|| 'yes' === (string) $order->get_meta( '_pg_is_wholesale', true )
			|| 'wholesale_quote' === (string) $order->get_meta( '_pg_order_source', true );
	}

	/**
	 * Returns the current wholesale follow-up stage label.
	 */
	private function wholesale_stage_label( string $stage ): string {
		return match ( $stage ) {
			'shipping_details_request' => __( 'Shipping details requested', 'woo-logistics-plugin' ),
			'payment_instructions' => __( 'Final quote + payment sent', 'woo-logistics-plugin' ),
			'payment_received'     => __( 'Payment received', 'woo-logistics-plugin' ),
			'hamilton_hub'         => __( 'Fulfillment update sent', 'woo-logistics-plugin' ),
			'inspected_label'      => __( 'Tracking ready', 'woo-logistics-plugin' ),
			'shipped_tracking'     => __( 'Shipped + tracking sent', 'woo-logistics-plugin' ),
			'delivered_complete'   => __( 'Delivered + completed', 'woo-logistics-plugin' ),
			'refund_etransfer'     => __( 'Refund by e-Transfer sent', 'woo-logistics-plugin' ),
			'refund_wire'          => __( 'Refund by wire/EFT sent', 'woo-logistics-plugin' ),
			default                => __( 'Quote needs follow-up', 'woo-logistics-plugin' ),
		};
	}

	/**
	 * Resolves the internal wholesale workflow stage, including safe defaults for older quotes.
	 */
	private function wholesale_workflow_stage( WC_Order $order ): string {
		if ( WLP_Split_Shipments::active( $order ) ) {
			return WLP_Split_Shipments::summary( $order )['allShipped'] ? 'shipped' : 'ready_to_ship';
		}
		$allowed = array( 'awaiting_customer', 'stock_check', 'final_quote', 'awaiting_payment', 'supplier_order', 'inbound_hamilton', 'ready_to_ship', 'shipped' );
		$stored  = sanitize_key( (string) $order->get_meta( self::WHOLESALE_WORKFLOW_META, true ) );

		if ( in_array( $stored, $allowed, true ) ) {
			if (
				$order->has_status( array( 'processing', 'completed' ) )
				&& in_array( $stored, array( 'awaiting_customer', 'stock_check', 'final_quote', 'awaiting_payment' ), true )
			) {
				return 'supplier_order';
			}

			return $stored;
		}

		$meta = WLP_Order_Logistics::read( $order );
		if ( '' !== $meta['shipped_at'] || '' !== $meta['delivered_at'] ) {
			return 'shipped';
		}

		$followup = sanitize_key( (string) $order->get_meta( self::WHOLESALE_STAGE_META, true ) );
		if ( in_array( $followup, array( 'delivered_complete', 'shipped_tracking' ), true ) ) {
			return 'shipped';
		}
		if ( 'inspected_label' === $followup ) {
			return 'ready_to_ship';
		}
		if ( 'hamilton_hub' === $followup ) {
			return 'inbound_hamilton';
		}
		if ( 'payment_received' === $followup || $order->has_status( array( 'processing', 'completed' ) ) ) {
			return 'supplier_order';
		}
		if ( 'payment_instructions' === $followup ) {
			return 'awaiting_payment';
		}

		return 'awaiting_customer';
	}

	/**
	 * Returns the recorded wholesale payment and remaining balance.
	 *
	 * @return array<string, int|string|bool>
	 */
	private function wholesale_payment_summary( WC_Order $order ): array {
		$total_cents = max( 0, (int) round( (float) $order->get_total() * 100 ) );
		$paid_cents = max( 0, (int) round( (float) $order->get_meta( self::WHOLESALE_AMOUNT_PAID_META, true ) * 100 ) );
		$paid_cents = min( $paid_cents, $total_cents );
		$balance_cents = max( 0, $total_cents - $paid_cents );
		$currency = $order->get_currency();
		$format = static function ( int $cents ) use ( $currency ): string {
			return html_entity_decode( wp_strip_all_tags( wc_price( $cents / 100, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
		};

		return array(
			'totalCents'       => $total_cents,
			'paidCents'        => $paid_cents,
			'balanceCents'     => $balance_cents,
			'total'            => number_format( $total_cents / 100, 2, '.', '' ),
			'paid'             => number_format( $paid_cents / 100, 2, '.', '' ),
			'balance'          => number_format( $balance_cents / 100, 2, '.', '' ),
			'totalFormatted'   => $format( $total_cents ),
			'paidFormatted'    => $format( $paid_cents ),
			'balanceFormatted' => $format( $balance_cents ),
			'hasPartial'       => $paid_cents > 0 && $balance_cents > 0,
			'isFullyRecorded'  => $total_cents > 0 && 0 === $balance_cents,
			'updatedAt'        => trim( (string) $order->get_meta( self::WHOLESALE_PARTIAL_PAYMENT_AT_META, true ) ),
		);
	}

	/**
	 * Returns the guided wholesale card state.
	 *
	 * @return array<string, mixed>
	 */
	private function wholesale_workflow_payload( WC_Order $order ): array {
		$stage = $this->wholesale_workflow_stage( $order );
		$steps = array(
			'awaiting_customer' => array(
				'label'       => __( 'Waiting on customer', 'woo-logistics-plugin' ),
				'task'        => __( 'Wait for YES and complete shipping details.', 'woo-logistics-plugin' ),
				'next'        => __( 'Confirm supplier stock.', 'woo-logistics-plugin' ),
				'action'      => 'customer_confirmed',
				'actionLabel' => __( 'Customer confirmed', 'woo-logistics-plugin' ),
				'number'      => 1,
			),
			'stock_check' => array(
				'label'       => __( 'Supplier check', 'woo-logistics-plugin' ),
				'task'        => __( 'Confirm every requested strength and quantity with the supplier.', 'woo-logistics-plugin' ),
				'next'        => __( 'Send the final total and payment instructions.', 'woo-logistics-plugin' ),
				'action'      => 'stock_confirmed',
				'actionLabel' => __( 'Stock confirmed', 'woo-logistics-plugin' ),
				'notificationStage' => 'payment_instructions',
				'number'      => 2,
			),
			'final_quote' => array(
				'label'       => __( 'Final quote ready', 'woo-logistics-plugin' ),
				'task'        => __( 'Send the final quote from Customer communication if needed, or record the payment once reconciled. Processing sends the payment-received email automatically.', 'woo-logistics-plugin' ),
				'next'        => __( 'Begin fulfillment after payment is verified.', 'woo-logistics-plugin' ),
				'action'      => 'payment_verified',
				'actionLabel' => __( 'Record payment verified', 'woo-logistics-plugin' ),
				'paymentEmailOption' => true,
				'number'      => 3,
			),
			'awaiting_payment' => array(
				'label'       => __( 'Awaiting payment', 'woo-logistics-plugin' ),
				'task'        => __( 'Verify the exact payment. Recording it changes the order to Processing and emails the customer automatically.', 'woo-logistics-plugin' ),
				'next'        => __( 'Place the supplier order.', 'woo-logistics-plugin' ),
				'action'      => 'payment_verified',
				'actionLabel' => __( 'Record payment verified', 'woo-logistics-plugin' ),
				'paymentEmailOption' => true,
				'number'      => 3,
			),
			'supplier_order' => array(
				'label'       => __( 'Order from supplier or use stock on hand', 'woo-logistics-plugin' ),
				'task'        => __( 'Add supplier tracking, or confirm stock is already physically on hand and inspected.', 'woo-logistics-plugin' ),
				'next'        => __( 'Create the customer shipping label.', 'woo-logistics-plugin' ),
				'action'      => 'received_inspected',
				'actionLabel' => __( 'Stock already here + inspected', 'woo-logistics-plugin' ),
				'notificationStage' => 'hamilton_hub',
				'number'      => 4,
			),
			'inbound_hamilton' => array(
				'label'       => __( 'Inbound to Hamilton', 'woo-logistics-plugin' ),
				'task'        => __( 'Watch the supplier shipment until it arrives in Hamilton.', 'woo-logistics-plugin' ),
				'next'        => __( 'Inspect it, then add customer tracking.', 'woo-logistics-plugin' ),
				'action'      => 'received_inspected',
				'actionLabel' => __( 'Received + inspected', 'woo-logistics-plugin' ),
				'notificationStage' => 'hamilton_hub',
				'number'      => 4,
			),
			'ready_to_ship' => array(
				'label'       => __( 'Ready to ship', 'woo-logistics-plugin' ),
				'task'        => __( 'Tracking is ready. Hand the package to the carrier, then confirm shipment.', 'woo-logistics-plugin' ),
				'next'        => __( 'Monitor tracking until delivery.', 'woo-logistics-plugin' ),
				'action'      => 'confirm_shipped',
				'actionLabel' => __( 'Confirm shipped', 'woo-logistics-plugin' ),
				'number'      => 5,
			),
			'shipped' => array(
				'label'       => __( 'Shipped', 'woo-logistics-plugin' ),
				'task'        => __( 'Monitor customer tracking until delivery.', 'woo-logistics-plugin' ),
				'next'        => __( 'Complete the order after verified delivery.', 'woo-logistics-plugin' ),
				'action'      => '',
				'actionLabel' => '',
				'notificationStage' => 'shipped_tracking',
				'number'      => 6,
			),
		);

		$payload = $steps[ $stage ];
		$payload['emailStage'] = $payload['emailStage'] ?? '';
		$payload['notificationStage'] = $payload['notificationStage'] ?? '';
		$payload['stage'] = $stage;
		$payload['updatedAt'] = trim( (string) $order->get_meta( self::WHOLESALE_WORKFLOW_AT_META, true ) );
		$payload['inboundCarrier'] = trim( (string) $order->get_meta( self::WHOLESALE_INBOUND_CARRIER_META, true ) );
		$payload['inboundTrackingNumber'] = trim( (string) $order->get_meta( self::WHOLESALE_INBOUND_TRACKING_META, true ) );
		$payload['inboundTrackingUrl'] = trim( (string) $order->get_meta( self::WHOLESALE_INBOUND_TRACKING_URL_META, true ) );
		$payload['inspectedAt'] = trim( (string) $order->get_meta( self::WHOLESALE_INSPECTED_AT_META, true ) );
		$payload['payment'] = $this->wholesale_payment_summary( $order );
		$sourcing = $this->wholesale_sourcing_payload( $order );
		$supplier = $sourcing['supplierProgress'] ?? array();
		$payload['supplierProgress'] = $supplier;
		$waiting_for_supplier = ( $supplier['ordered'] ?? 0 ) > ( $supplier['received'] ?? 0 );
		if ( ( in_array( $stage, array( 'supplier_order', 'inbound_hamilton' ), true ) && ( $supplier['ordered'] ?? 0 ) > 0 ) || ( 'ready_to_ship' === $stage && $waiting_for_supplier ) ) {
			$payload['label'] = 'Supplier order placed';
			$payload['task'] = ( ( $supplier['unassigned'] ?? 0 ) + ( $supplier['pendingTotal'] ?? 0 ) ) > 0 ? 'Supplier order placed for assigned items. Source the remaining items.' : 'Wait for the supplier items, then record receipt and inspection.';
			$payload['next'] = ( ( $supplier['unassigned'] ?? 0 ) + ( $supplier['pendingTotal'] ?? 0 ) ) > 0 ? 'Order the remaining items in Fulfillment sources.' : 'Received + inspected, then pack and create the customer label.';
			$payload['action'] = ''; $payload['actionLabel'] = ''; $payload['notificationStage'] = ''; $payload['openSourcing'] = true;
			if ( $supplier['received'] === $supplier['ordered'] && $supplier['local'] > 0 && 0 === $supplier['pendingTotal'] + $supplier['unassigned'] ) {
				$payload['label'] = 'Supplier stock received · Hamilton pick needed';
				$payload['task'] = 'Pick the Hamilton items and inspect the complete order.';
				$payload['action'] = 'received_inspected';
				$payload['actionLabel'] = 'All stock picked + inspected';
			}

		}


		$logistics = WLP_Order_Logistics::read( $order );
		$payload['carrier'] = $logistics['service_name'];
		$payload['trackingNumber'] = $logistics['tracking_number'];
		$payload['trackingUrl'] = $this->tracking_url( $logistics );
		$payload['expectedDeliveryDate'] = $logistics['expected_delivery_date'];

		if ( 'ready_to_ship' === $stage && ! $waiting_for_supplier && '' === trim( (string) $logistics['tracking_number'] ) ) {
			$payload['task']        = __( 'Create the customer shipping label, then return here to confirm carrier handoff.', 'woo-logistics-plugin' );
			$payload['next']        = __( 'Preview an optional tracking update only after tracking is saved.', 'woo-logistics-plugin' );
			$payload['action']      = '';
			$payload['actionLabel'] = '';
			$payload['openLabel']   = true;
		}
		$payload['openLabel'] = ! empty( $payload['openLabel'] );
		$payload['paymentEmailOption'] = ! empty( $payload['paymentEmailOption'] );
		if ( 'ready_to_ship' === $stage && ! $waiting_for_supplier && '' !== trim( (string) $logistics['tracking_number'] ) ) {
			$payload['notificationStage'] = 'shipped_tracking';
		}

		return $payload;
	}

	/**
	 * Renders the compact guided workflow used on wholesale order cards.
	 */
	private function render_wholesale_workflow( WC_Order $order ): void {
		if ( WLP_Split_Shipments::active( $order ) ) {
			$s = WLP_Split_Shipments::summary( $order );
			echo '<p class="wlp-split-summary">' . esc_html( ( $s['allShipped'] ? 'All packages shipped' : ( $s['shipped'] ? 'Partially shipped' : 'Split shipment planned' ) ) . ': ' . $s['shipped'] . ' / ' . $s['total'] . ' items shipped; ' . $s['remaining'] . ' remaining.' ) . '</p>';
			return;
		}
		$workflow = $this->wholesale_workflow_payload( $order );
		$step_labels = array(
			1 => __( 'Quote', 'woo-logistics-plugin' ),
			2 => __( 'Stock', 'woo-logistics-plugin' ),
			3 => __( 'Payment', 'woo-logistics-plugin' ),
			4 => __( 'Hamilton', 'woo-logistics-plugin' ),
			5 => __( 'Pack + label', 'woo-logistics-plugin' ),
			6 => __( 'Shipped', 'woo-logistics-plugin' ),
		);
		$order_id = (string) $order->get_id();
		?>
		<section class="wlp-wholesale-workflow" data-wlp-wholesale-workflow data-workflow-stage="<?php echo esc_attr( $workflow['stage'] ); ?>">
			<ol class="wlp-wholesale-progress" aria-label="<?php echo esc_attr__( 'Wholesale order progress', 'woo-logistics-plugin' ); ?>">
				<?php foreach ( $step_labels as $number => $label ) : ?>
					<?php $step_class = $number < $workflow['number'] ? 'is-complete' : ( $number === $workflow['number'] ? 'is-current' : '' ); ?>
					<li class="<?php echo esc_attr( $step_class ); ?>">
						<span><?php echo esc_html( (string) $number ); ?></span><?php echo esc_html( $label ); ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<div class="wlp-wholesale-current-task">
				<div>
					<span class="wlp-wholesale-current-task__eyebrow"><?php echo esc_html__( 'Do this now', 'woo-logistics-plugin' ); ?></span>
					<strong><?php echo esc_html( $workflow['label'] ); ?></strong>
					<p><?php echo esc_html( $workflow['task'] ); ?></p>
					<small><b><?php echo esc_html__( 'Next:', 'woo-logistics-plugin' ); ?></b> <?php echo esc_html( $workflow['next'] ); ?></small>
				</div>
				<div class="wlp-wholesale-current-task__actions">
					<?php if ( '' !== $workflow['action'] ) : ?>
						<button class="button button-primary" type="button" data-wlp-wholesale-advance="<?php echo esc_attr( $workflow['action'] ); ?>" data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html( $workflow['actionLabel'] ); ?></button>
					<?php endif; ?>
					<?php if ( '' !== $workflow['emailStage'] ) : ?>
						<button class="button button-primary" type="button" data-wlp-open-wholesale-email="<?php echo esc_attr( $workflow['emailStage'] ); ?>" data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html( $workflow['actionLabel'] ); ?></button>
					<?php endif; ?>
					<?php if ( ! empty( $workflow['openSourcing'] ) ) : ?>
						<button class="button button-primary" type="button" data-wlp-open-sourcing data-order-id="<?php echo esc_attr( $order_id ); ?>">Review supplier progress</button>
					<?php endif; ?>
					<?php if ( $workflow['openLabel'] ) : ?>
						<button class="button button-primary" type="button" data-wlp-split-open data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Shipment / packages', 'woo-logistics-plugin' ); ?></button>
					<?php endif; ?>
					<?php if ( '' !== $workflow['action'] && '' !== $workflow['notificationStage'] ) : ?>
						<label class="wlp-wholesale-notify"><input type="checkbox" data-wlp-workflow-notify data-wlp-workflow-notify-stage="<?php echo esc_attr( $workflow['notificationStage'] ); ?>"><span><?php echo esc_html__( 'Notify customer by email', 'woo-logistics-plugin' ); ?> <small><?php echo esc_html__( 'Preview first', 'woo-logistics-plugin' ); ?></small></span></label>
					<?php endif; ?>
					<?php if ( $workflow['paymentEmailOption'] ) : ?>
						<label class="wlp-wholesale-notify"><input type="checkbox" data-wlp-payment-notify-email checked><span><?php echo esc_html__( 'Email payment received confirmation', 'woo-logistics-plugin' ); ?> <small><?php echo esc_html__( 'Uncheck for internal-only Processing', 'woo-logistics-plugin' ); ?></small></span></label>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( in_array( $workflow['stage'], array( 'final_quote', 'awaiting_payment' ), true ) ) : ?>
				<?php $payment = $workflow['payment']; ?>
				<div class="wlp-wholesale-partial-payment" data-wlp-wholesale-partial-payment>
					<div class="wlp-wholesale-payment-summary">
						<div><span><?php echo esc_html__( 'Recorded', 'woo-logistics-plugin' ); ?></span><strong><?php echo esc_html( $payment['paidFormatted'] ); ?></strong></div>
						<div><span><?php echo esc_html__( 'Balance due', 'woo-logistics-plugin' ); ?></span><strong><?php echo esc_html( $payment['balanceFormatted'] ); ?></strong></div>
					</div>
					<?php if ( $payment['balanceCents'] > 0 ) : ?>
						<div class="wlp-wholesale-partial-payment__entry">
							<label><span><?php echo esc_html__( 'Partial payment received (CAD)', 'woo-logistics-plugin' ); ?></span><input type="number" min="0.01" max="<?php echo esc_attr( $payment['balance'] ); ?>" step="0.01" inputmode="decimal" data-wlp-partial-payment-amount placeholder="0.00"></label>
							<button class="button" type="button" data-wlp-record-partial-payment data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Record partial payment', 'woo-logistics-plugin' ); ?></button>
						</div>
					<?php else : ?>
						<p><?php echo esc_html__( 'The full quoted total is recorded. Use “Record payment verified” only after final reconciliation.', 'woo-logistics-plugin' ); ?></p>
					<?php endif; ?>
					<small><?php echo esc_html__( 'Internal only. This does not change the order status, email the customer, start fulfillment, or report a purchase.', 'woo-logistics-plugin' ); ?></small>
				</div>
			<?php endif; ?>
			<?php if ( 'shipped' === $workflow['stage'] ) : ?>
				<div class="wlp-wholesale-shipped-tracking">
					<span><?php echo esc_html__( 'Customer tracking', 'woo-logistics-plugin' ); ?></span>
					<?php if ( '' !== $workflow['trackingNumber'] && '' !== $workflow['trackingUrl'] ) : ?>
						<a target="_blank" href="<?php echo esc_url( $workflow['trackingUrl'] ); ?>"><?php echo esc_html( $workflow['trackingNumber'] ); ?></a>
					<?php else : ?>
						<strong><?php echo esc_html( $workflow['trackingNumber'] ?: __( 'Tracking unavailable', 'woo-logistics-plugin' ) ); ?></strong>
					<?php endif; ?>
					<?php if ( '' !== $workflow['carrier'] ) : ?><small><?php echo esc_html( $workflow['carrier'] ); ?></small><?php endif; ?>
				</div>
			<?php endif; ?>
			<details class="wlp-wholesale-workflow-adjust" data-wlp-wholesale-workflow-adjust>
				<summary><?php echo esc_html__( 'Adjust internal workflow step', 'woo-logistics-plugin' ); ?></summary>
				<p><?php echo esc_html__( 'Use only to correct the order board. This never emails the customer; Shipped still requires the blue Confirm shipped action.', 'woo-logistics-plugin' ); ?></p>
				<div>
					<select data-wlp-workflow-stage-select aria-label="<?php echo esc_attr__( 'Wholesale workflow step', 'woo-logistics-plugin' ); ?>">
						<option value="awaiting_customer" <?php selected( 'awaiting_customer', $workflow['stage'] ); ?>><?php echo esc_html__( 'Quotes — Waiting on customer', 'woo-logistics-plugin' ); ?></option>
						<option value="stock_check" <?php selected( 'stock_check', $workflow['stage'] ); ?>><?php echo esc_html__( 'Quotes — Supplier check', 'woo-logistics-plugin' ); ?></option>
						<option value="final_quote" <?php selected( 'final_quote', $workflow['stage'] ); ?>><?php echo esc_html__( 'Quotes — Final quote ready', 'woo-logistics-plugin' ); ?></option>
						<option value="awaiting_payment" <?php selected( 'awaiting_payment', $workflow['stage'] ); ?>><?php echo esc_html__( 'Quotes — Awaiting payment', 'woo-logistics-plugin' ); ?></option>
						<option value="supplier_order" <?php selected( 'supplier_order', $workflow['stage'] ); ?>><?php echo esc_html__( 'Wholesale to ship — Supplier / stock on hand', 'woo-logistics-plugin' ); ?></option>
						<option value="inbound_hamilton" <?php selected( 'inbound_hamilton', $workflow['stage'] ); ?>><?php echo esc_html__( 'Wholesale to ship — Inbound to Hamilton', 'woo-logistics-plugin' ); ?></option>
						<option value="ready_to_ship" <?php selected( 'ready_to_ship', $workflow['stage'] ); ?>><?php echo esc_html__( 'Wholesale to ship — Ready to ship (records inspection)', 'woo-logistics-plugin' ); ?></option>
					</select>
					<button class="button" type="button" data-wlp-workflow-set-step data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Set step', 'woo-logistics-plugin' ); ?></button>
				</div>
			</details>

			<?php if ( in_array( $workflow['stage'], array( 'supplier_order', 'inbound_hamilton' ), true ) ) : ?>
				<div class="wlp-wholesale-tracking-form" data-wlp-wholesale-inbound-form>
					<strong><?php echo esc_html__( 'Supplier to Hamilton tracking', 'woo-logistics-plugin' ); ?></strong>
					<p><?php echo esc_html__( 'Internal only. This tracking is never included in the customer email.', 'woo-logistics-plugin' ); ?></p>
					<div class="wlp-wholesale-tracking-grid">
						<label><span><?php echo esc_html__( 'Carrier', 'woo-logistics-plugin' ); ?></span><input type="text" data-wlp-inbound-carrier value="<?php echo esc_attr( $workflow['inboundCarrier'] ); ?>" placeholder="<?php echo esc_attr__( 'Canada Post', 'woo-logistics-plugin' ); ?>"></label>
						<label><span><?php echo esc_html__( 'Inbound tracking', 'woo-logistics-plugin' ); ?></span><input type="text" data-wlp-inbound-number value="<?php echo esc_attr( $workflow['inboundTrackingNumber'] ); ?>"></label>
						<label class="is-wide"><span><?php echo esc_html__( 'Tracking link (auto for Canada Post)', 'woo-logistics-plugin' ); ?></span><input type="url" data-wlp-inbound-url value="<?php echo esc_attr( $workflow['inboundTrackingUrl'] ); ?>"></label>
					</div>
					<button class="button" type="button" data-wlp-save-inbound-tracking data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Add inbound tracking', 'woo-logistics-plugin' ); ?></button>
				</div>
			<?php endif; ?>

			<?php if ( 'ready_to_ship' === $workflow['stage'] ) : ?>
				<div class="wlp-wholesale-tracking-form" data-wlp-wholesale-outbound-form>
					<strong><?php echo esc_html__( 'Customer shipment', 'woo-logistics-plugin' ); ?></strong>
					<p><?php echo esc_html__( 'A Canada Post label automatically adds its tracking here. You can edit it before sending.', 'woo-logistics-plugin' ); ?></p>
					<p class="wlp-wholesale-tracking-status" data-wlp-outbound-auto-status hidden></p>
					<div class="wlp-wholesale-tracking-grid">
						<label><span><?php echo esc_html__( 'Carrier', 'woo-logistics-plugin' ); ?></span><input type="text" data-wlp-outbound-carrier value="<?php echo esc_attr( $workflow['carrier'] ); ?>" placeholder="<?php echo esc_attr__( 'Canada Post', 'woo-logistics-plugin' ); ?>"></label>
						<label><span><?php echo esc_html__( 'Customer tracking', 'woo-logistics-plugin' ); ?></span><input type="text" data-wlp-outbound-number value="<?php echo esc_attr( $workflow['trackingNumber'] ); ?>"></label>
						<label class="is-wide"><span><?php echo esc_html__( 'Tracking link (optional)', 'woo-logistics-plugin' ); ?></span><input type="url" data-wlp-outbound-url value="<?php echo esc_attr( $workflow['trackingUrl'] ); ?>"></label>
						<label><span><?php echo esc_html__( 'Expected delivery (optional)', 'woo-logistics-plugin' ); ?></span><input type="date" data-wlp-outbound-date value="<?php echo esc_attr( $workflow['expectedDeliveryDate'] ); ?>"></label>
					</div>
					<div class="wlp-wholesale-tracking-actions">
						<button class="button" type="button" data-wlp-save-outbound-tracking data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Save tracking changes', 'woo-logistics-plugin' ); ?></button>
						<button class="button button-primary" type="button" data-wlp-preview-outbound-tracking data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Preview tracking email', 'woo-logistics-plugin' ); ?></button>
					</div>
					<div class="wlp-wholesale-tracking-preview" data-wlp-tracking-preview hidden>
						<strong data-wlp-tracking-preview-subject></strong>
						<span data-wlp-tracking-preview-recipient></span>
						<textarea rows="12" data-wlp-tracking-preview-body aria-label="<?php echo esc_attr__( 'Customer tracking email body', 'woo-logistics-plugin' ); ?>"></textarea>
						<button class="button button-primary" type="button" data-wlp-confirm-outbound-ship data-order-id="<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Mark shipped + send this email', 'woo-logistics-plugin' ); ?></button>
					</div>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Totals quoted vial quantities from the wholesale quote products text.
	 */
	private function wholesale_quote_vial_count( string $products ): int {
		if ( '' === $products ) {
			return 0;
		}

		preg_match_all( '/\b(\d+)\s+vials?\b/i', $products, $matches );

		if ( empty( $matches[1] ) ) {
			return 0;
		}

		return array_sum( array_map( 'intval', $matches[1] ) );
	}

	/**
	 * Parses the quote builder summary into itemized wholesale pricing.
	 *
	 * @return array<int, array{name: string, quantity: int, unitPrice: string, lineTotal: string}>
	 */
	private function wholesale_quote_lines( WC_Order $order ): array {
		$details = $this->wholesale_quote_details( $order );

		if ( '' === $details ) {
			return array();
		}

		preg_match_all(
			'/^\s*-\s*(.+?):\s*([0-9][0-9,]*)\s+vials?\s*[x×]\s*C?\$?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)(?:\s*\/\s*vial)?\s*=\s*C?\$?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*$/mi',
			$details,
			$matches,
			PREG_SET_ORDER
		);

		if ( empty( $matches ) ) {
			return array();
		}

		$currency = $order->get_currency();
		$lines    = array();
		$parsed_total = 0.0;

		foreach ( $matches as $match ) {
			$unit_price = (float) str_replace( ',', '', $match[3] );
			$line_total = (float) str_replace( ',', '', $match[4] );
			$parsed_total += $line_total;

			$lines[] = array(
				'name'       => sanitize_text_field( $match[1] ),
				'quantity'   => (int) str_replace( ',', '', $match[2] ),
				'unitPrice'  => html_entity_decode( wp_strip_all_tags( wc_price( $unit_price, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ),
				'lineTotal'  => html_entity_decode( wp_strip_all_tags( wc_price( $line_total, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		$products        = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
		$estimated_total = (float) $order->get_meta( '_pg_wholesale_estimated_total', true );
		$parsed_names    = array_map(
			static fn( array $line ): string => strtolower( preg_replace( '/[^a-z0-9]+/i', '', $line['name'] ) ),
			$lines
		);
		$missing_products = array();

		foreach ( array_filter( array_map( 'trim', explode( ';', $products ) ) ) as $product ) {
			if ( ! preg_match( '/^(.+?)\s*-\s*([0-9][0-9,]*)\s+vials?$/i', $product, $product_match ) ) {
				continue;
			}

			$product_name = sanitize_text_field( $product_match[1] );
			$normalized   = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $product_name ) );

			if ( ! in_array( $normalized, $parsed_names, true ) ) {
				$missing_products[] = array(
					'name'     => $product_name,
					'quantity' => (int) str_replace( ',', '', $product_match[2] ),
				);
			}
		}

		/*
		 * Older quotes can contain one selected product in the concise product
		 * list but omit its pricing line from the saved builder summary. When
		 * that happens, reconcile the one missing row from the quote total.
		 */
		if ( 1 === count( $missing_products ) && $estimated_total > $parsed_total && $missing_products[0]['quantity'] > 0 ) {
			$missing    = $missing_products[0];
			$line_total = $estimated_total - $parsed_total;
			$unit_price = $line_total / $missing['quantity'];
			$lines[]    = array(
				'name'       => $missing['name'],
				'quantity'   => $missing['quantity'],
				'unitPrice'  => html_entity_decode( wp_strip_all_tags( wc_price( $unit_price, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ),
				'lineTotal'  => html_entity_decode( wp_strip_all_tags( wc_price( $line_total, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		return $lines;
	}

	/**
	 * Returns the saved wholesale builder summary used for pricing details.
	 */
	private function wholesale_quote_details( WC_Order $order ): string {
		$details = trim( (string) $order->get_meta( '_pg_wholesale_quote_details', true ) );

		if ( '' === $details ) {
			$details = trim( (string) $order->get_customer_note() );
		}

		return $details;
	}

	/**
	 * Returns the delivery province saved on the address or quote-builder summary.
	 */
	private function wholesale_delivery_province( WC_Order $order ): string {
		$state   = trim( (string) ( $order->get_shipping_state() ?: $order->get_billing_state() ) );
		$country = trim( (string) ( $order->get_shipping_country() ?: $order->get_billing_country() ) );

		if ( '' !== $state ) {
			$states = '' !== $country ? WC()->countries->get_states( $country ) : array();
			return sanitize_text_field( $states[ $state ] ?? $state );
		}

		$details = $this->wholesale_quote_details( $order );
		if ( '' !== $details && preg_match( '/^Delivery location:\s*(.+)$/mi', $details, $match ) ) {
			return sanitize_text_field( trim( $match[1] ) );
		}

		return '';
	}

	/**
	 * Adds one Monday-to-Friday business day to known non-Ontario wholesale estimates.
	 */
	private function wholesale_customer_delivery_estimate_date( WC_Order $order, string $expected_delivery_date ): string {
		$expected_delivery_date = trim( $expected_delivery_date );
		if ( '' === $expected_delivery_date || ! $this->is_wholesale_order( $order ) ) {
			return $expected_delivery_date;
		}

		$province = strtolower( preg_replace( '/[^a-z]/i', '', $this->wholesale_delivery_province( $order ) ) ?? '' );
		if ( '' === $province || in_array( $province, array( 'on', 'ontario' ), true ) ) {
			return $expected_delivery_date;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $expected_delivery_date, wp_timezone() );
		if ( false === $date ) {
			return $expected_delivery_date;
		}

		do {
			$date = $date->modify( '+1 day' );
		} while ( (int) $date->format( 'N' ) >= 6 );

		return $date->format( 'Y-m-d' );
	}

	/**
	 * Applies the customer-facing wholesale delivery buffer to a Canada Post rate list.
	 *
	 * @param array<int, array<string, mixed>> $rates Canada Post rates.
	 * @return array<int, array<string, mixed>>
	 */
	private function wholesale_customer_delivery_rate_estimates( WC_Order $order, array $rates ): array {
		foreach ( $rates as &$rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}

			$rate['expected_delivery_date'] = $this->wholesale_customer_delivery_estimate_date( $order, (string) ( $rate['expected_delivery_date'] ?? '' ) );
		}
		unset( $rate );

		return $rates;
	}

	/**
	 * Parses the wholesale subtotal, tax, and tax-inclusive total.
	 *
	 * @return array{subtotal: float, merchandiseSubtotal: float, credit: float, tax: float, total: float, subtotalFormatted: string, merchandiseSubtotalFormatted: string, creditFormatted: string, taxFormatted: string, totalFormatted: string, taxLabel: string}
	 */
	private function wholesale_quote_totals( WC_Order $order ): array {
		$details  = $this->wholesale_quote_details( $order );
		$currency = $order->get_currency();
		$subtotal = max( 0.0, (float) $order->get_meta( '_pg_wholesale_estimated_total', true ) );
		$merchandise_subtotal = $subtotal;
		$credit   = 0.0;
		$tax      = max( 0.0, (float) $order->get_total_tax() );
		$total    = max( 0.0, (float) $order->get_total() );
		$tax_label = __( 'Estimated tax', 'woo-logistics-plugin' );
        $tax_rows = array();
		$amount_pattern = '([0-9][0-9,]*(?:\.[0-9]{1,2})?)';

		if ( '' !== $details && preg_match( '/Estimated subtotal before tax:\s*C?\$?\s*' . $amount_pattern . '/i', $details, $match ) ) {
			$subtotal = (float) str_replace( ',', '', $match[1] );
		}

		if ( '' !== $details && preg_match( '/Merchandise subtotal before credit:\s*C?\$?\s*' . $amount_pattern . '/i', $details, $match ) ) {
			$merchandise_subtotal = (float) str_replace( ',', '', $match[1] );
		}

		if ( '' !== $details && preg_match( '/Wholesale (?:credit|discount):\s*(-?)\s*C?\$?\s*' . $amount_pattern . '/i', $details, $match ) ) {
			$credit = (float) str_replace( ',', '', $match[2] );
			if ( '-' === $match[1] ) {
				$credit *= -1;
			}
		}

		if ( '' !== $details && preg_match( '/Estimated\s+(GST\/HST|GST|HST):\s*C?\$?\s*' . $amount_pattern . '(?:\s*\(([^)]+)\))?/i', $details, $match ) ) {
			$tax       = (float) str_replace( ',', '', $match[2] );
			$tax_label = 'Estimated ' . strtoupper( $match[1] );
			if ( ! empty( $match[3] ) ) {
				$tax_label .= ' (' . sanitize_text_field( $match[3] ) . ')';
			}
		}

        if ( '' !== $details && preg_match_all( '/^Estimated\s+(?:GST\/HST|GST|HST|PST|RST):\s*C?\$?\s*' . $amount_pattern . '/mi', $details, $tax_matches ) ) {
            $tax = array_sum( array_map( static fn( $value ) => (float) str_replace( ',', '', $value ), $tax_matches[1] ) );
            foreach ( $tax_matches[1] as $index => $value ) {
                $tax_rows[] = array( 'label' => '', 'amount' => (float) str_replace( ',', '', $value ) );
            }
            preg_match_all( '/^Estimated\s+(GST\/HST|GST|HST|PST|RST):/mi', $details, $label_matches );
            foreach ( $label_matches[1] as $index => $label ) { $tax_rows[$index]['label'] = $label; }
            $labels = array_unique( $label_matches[1] );
            $tax_label = 'Estimated ' . implode( ' + ', $labels );
        }

		if ( '' !== $details && preg_match( '/Total including (?:GST\/HST|GST|HST|tax):\s*C?\$?\s*' . $amount_pattern . '/i', $details, $match ) ) {
			$total = (float) str_replace( ',', '', $match[1] );
		} elseif ( $subtotal > 0 && $tax > 0 ) {
			$total = $subtotal + $tax;
		} elseif ( $total <= 0 ) {
			$total = $subtotal;
		}

		$format_money = static function ( float $amount ) use ( $currency ): string {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
		};

		return array(
			'subtotal'          => $subtotal,
			'merchandiseSubtotal' => $merchandise_subtotal,
			'credit'            => $credit,
			'tax'               => $tax,
			'total'             => $total,
			'subtotalFormatted' => $format_money( $subtotal ),
			'merchandiseSubtotalFormatted' => $format_money( $merchandise_subtotal ),
			'creditFormatted'   => $format_money( $credit ),
			'taxFormatted'      => $format_money( $tax ),
			'totalFormatted'    => $format_money( $total ),
			'taxLabel'          => $tax_label,
            'taxRows'           => array_map( static fn( $row ) => array( 'label' => $row['label'], 'formatted' => $format_money( $row['amount'] ) ), $tax_rows ),
		);
	}

	/**
	 * Builds a plain-text quote breakdown that can be pasted into WhatsApp.
	 *
	 * @param array<int, array{name: string, quantity: int, unitPrice: string, lineTotal: string}> $quote_lines Itemized quote rows.
	 * @param array{subtotal: float, merchandiseSubtotal: float, credit: float, tax: float, total: float, subtotalFormatted: string, merchandiseSubtotalFormatted: string, creditFormatted: string, taxFormatted: string, totalFormatted: string, taxLabel: string} $totals Quote totals.
	 */
	private function wholesale_quote_copy_text( WC_Order $order, array $quote_lines, array $totals ): string {
		$first_name = trim( (string) $order->get_billing_first_name() );
		$customer   = '' !== $first_name ? $first_name : trim( (string) $order->get_formatted_billing_full_name() );
		$greeting   = '' !== $customer ? 'Hi ' . $customer . ',' : 'Hi,';
		$rows       = array();

		foreach ( $quote_lines as $line ) {
			$rows[] = sprintf(
				'%1$s × %2$d vials at %3$s each = %4$s',
				$line['name'],
				$line['quantity'],
				$line['unitPrice'],
				$line['lineTotal']
			);
		}

		if ( empty( $rows ) ) {
			$products = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
			if ( '' !== $products ) {
				$rows[] = str_replace( '; ', "\n", $products );
			}
		}

		$totals_rows = array();
		if ( 0.0 !== (float) $totals['credit'] ) {
			$totals_rows[] = 'Merchandise subtotal: ' . $totals['merchandiseSubtotalFormatted'];
			$totals_rows[] = 'Wholesale credit: ' . $totals['creditFormatted'];
			$totals_rows[] = 'Subtotal after credit: ' . $totals['subtotalFormatted'];
		} else {
			$totals_rows[] = 'Subtotal: ' . $totals['subtotalFormatted'];
		}
		if ( $totals['tax'] > 0 ) {
            if ( ! empty( $totals['taxRows'] ) ) {
                foreach ( $totals['taxRows'] as $row ) { $totals_rows[] = $row['label'] . ': ' . $row['formatted']; }
            } else {
                $totals_rows[] = $totals['taxLabel'] . ': ' . $totals['taxFormatted'];
            }
		}
		$totals_rows[] = 'Total: ' . $totals['totalFormatted'];

		return trim(
			$greeting
			. "\n\nQuote #" . $order->get_order_number() . ":\n\n"
			. implode( "\n", $rows )
			. "\n\n"
			. implode( "\n", $totals_rows )
			. "\n\nWholesale Refund & Return Policy:\n" . home_url( '/refunds/#wholesale-policy' )
		);
	}

	/**
	 * Builds the buyer-facing wholesale follow-up message.
	 *
	 * @return array{subject: string, body: string, label: string}
	 */
	private function wholesale_followup_message( WC_Order $order, string $stage, array $meta ): array {
		$order_number = $order->get_order_number();
		$first_name   = trim( (string) $order->get_billing_first_name() );
		$greeting     = '' !== $first_name ? 'Hi ' . $first_name . ',' : 'Hi,';
		$quote_totals = $this->wholesale_quote_totals( $order );
		$quote_lines  = $this->wholesale_quote_lines( $order );
		$total        = $quote_totals['totalFormatted'];
		$payment      = trim( (string) $order->get_meta( '_pg_wholesale_payment_option', true ) );
		$products     = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
		$product_rows = array();

		foreach ( $quote_lines as $line ) {
			$product_rows[] = sprintf(
				'- %1$s: %2$d vials x %3$s per vial = %4$s',
				$line['name'],
				$line['quantity'],
				$line['unitPrice'],
				$line['lineTotal']
			);
		}

		if ( empty( $product_rows ) ) {
			$product_rows[] = '' !== $products ? str_replace( '; ', "\n- ", '- ' . $products ) : 'See quote/order details.';
		}

		$quote_breakdown = "Requested products:\n" . implode( "\n", $product_rows );
		$quote_breakdown .= "\n\nQuote totals (CAD):";
		if ( abs( $quote_totals['credit'] ) > 0.00001 ) {
			$quote_breakdown .= "\nMerchandise subtotal: " . $quote_totals['merchandiseSubtotalFormatted'];
			$quote_breakdown .= "\nWholesale credit: " . $quote_totals['creditFormatted'];
			$quote_breakdown .= "\nSubtotal after credit: " . $quote_totals['subtotalFormatted'];
		} else {
			$quote_breakdown .= "\nSubtotal before tax: " . $quote_totals['subtotalFormatted'];
		}
		if ( $quote_totals['tax'] > 0 ) {
            if ( ! empty( $quote_totals['taxRows'] ) ) {
                foreach ( $quote_totals['taxRows'] as $row ) { $quote_breakdown .= "\n" . $row['label'] . ': ' . $row['formatted']; }
            } else {
                $quote_breakdown .= "\n" . $quote_totals['taxLabel'] . ': ' . $quote_totals['taxFormatted'];
            }
		}
		$quote_breakdown .= "\n" . ( $quote_totals['tax'] > 0 ? 'Final total including tax: ' : 'Final total: ' ) . $total;
		$tracking_url = $this->tracking_url( $meta );
		$tracking     = trim( (string) $meta['tracking_number'] );
		$carrier      = trim( (string) $meta['service_name'] );
		$expected_delivery = trim( (string) $meta['expected_delivery_date'] );
		$quote_delivery = '';
		if ( preg_match( '/^Estimated delivery:\s*(.+)$/mi', (string) $order->get_meta( '_pg_wholesale_quote_details', true ), $delivery_match ) ) {
			$quote_delivery = trim( $delivery_match[1] );
		}
		$processing_delivery = '' !== $quote_delivery
			? "\n\nEstimated delivery shown in your quote: " . $quote_delivery . "\nThis estimate is subject to availability and payment timing. We will confirm any changes during preparation."
			: "\n\nEstimated delivery: We will confirm your delivery estimate during preparation.";
		$order_line   = "\n\nQuote/order #: " . $order_number;
		$lab_results_note = "\n\nWholesale testing documentation: Custom or order-specific COAs are not generated or emailed per wholesale order. Available PeptideGo-specific reports are self-serve, and not every product or lot has a published report:\n" . home_url( '/lab-results/' );
		$common_close = "\n\nThank you for working with PeptideGo Wholesale.\nPeptideGo";
		$portal_note = "\n\nView your quote, order status and package tracking:\n" . home_url( '/my-account/?wholesale=1' ) . "\nSign in or create an account with the email used for your quote. Verify that email when prompted to see guest orders.";
		$policy_close = "\n\nWholesale Refund & Return Policy:\n" . home_url( '/refunds/#wholesale-policy' ) . $lab_results_note . $portal_note . $common_close;
		$cad_transfer = "\n\nCAD payment options:\n\nInterac e-Transfer:\nRecipient email: orders@peptidego.ca\nBusiness name: North End Tech Inc.\nAmount: " . $total . "\nMessage/reference: Quote/order #" . $order_number . "\nSecurity question: Not required if Auto-Deposit is shown by your bank.\n\nBank wire/EFT:\nBeneficiary name: North End Tech Inc.\nBeneficiary address: 21 King St W, Hamilton, ON L8P 4W7, Canada\nBank: RBC Royal Bank\nInstitution number: 003\nTransit number: 01822\nAccount number: 1048933\nSWIFT/BIC: ROYCCAT2\nBranch address: 21 King St W, Hamilton, ON L8P 4W7\nBranch phone: (905) 521-2000\nCurrency: CAD\nPayment reference: Quote/order #" . $order_number . "\n\nPlease use the quote/order number only in the payment message/reference field. Do not include product names.";
		$btc_transfer = "\n\nBTC payment:\nReply to this email before sending BTC so we can confirm the current wallet address for this quote. Please do not send BTC to an old saved address.";
		$payment_block = false !== stripos( $payment, 'btc' ) ? $btc_transfer : $cad_transfer;

		return match ( $stage ) {
			'shipping_details_request' => array(
				'label'   => __( 'Shipping details request', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'PeptideGo wholesale quote #%s - shipping details needed', $order_number ),
				'body'    => $greeting . $order_line . "\n\nYour wholesale quote has been reviewed and is ready for the next step.\n\nBefore we send final payment instructions, reply with:\n\nShipping name:\nAddress:\nUnit number, if applicable:\nCity:\nProvince/state:\nPostal code:\nCountry:\nPhone number:\nEmail, if different from this one:\n\nOnce we have that, we will confirm the final total and send the payment instructions for the method you selected on the quote form." . $policy_close,
			),
			'payment_instructions' => array(
				'label'   => __( 'Final quote and payment instructions', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'PeptideGo wholesale quote #%s - final total', $order_number ),
				'body'    => $greeting . $order_line . "\n\nYour wholesale quote has been reviewed and is ready to move forward.\n\n" . $quote_breakdown . "\n\nPayment method: " . ( '' !== $payment ? $payment : 'CAD bank transfer' ) . $payment_block . "\n\nPayment is due as one transfer when possible. If your bank has a daily transfer limit, reply before sending and we can approve a split-payment schedule.\n\nPlease reply once payment is sent so we can confirm it and start fulfillment." . $policy_close,
			),
			'payment_received' => array(
				'label'   => __( 'Payment received', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'Payment received for PeptideGo wholesale order #%s', $order_number ),
				'body'    => $greeting . $order_line . "\n\nPayment has been received for your wholesale order. Your order is now being prepared." . $processing_delivery . "\n\nWholesale orders require preparation before dispatch; same-day shipping is not guaranteed. We will send tracking once your order is dispatched." . $policy_close,
			),
			'hamilton_hub' => array(
				'label'   => __( 'Hamilton hub update', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'PeptideGo wholesale order #%s is moving to fulfillment', $order_number ),
				'body'    => $greeting . $order_line . "\n\nYour wholesale order is moving through the Hamilton fulfillment hub now.\n\nWe will inspect the order contents, prepare the shipment, and send tracking once the label is ready." . $policy_close,
			),
			'inspected_label' => array(
				'label'   => __( 'Tracking ready', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'Tracking ready for PeptideGo wholesale order #%s', $order_number ),
				'body'    => $greeting . $order_line . "\n\nYour wholesale order has been checked, packed, and the shipping label has been created.\n\nTracking: " . ( '' !== $tracking ? $tracking : 'Pending' ) . ( '' !== $tracking_url ? "\nTrack here: " . $tracking_url : '' ) . "\n\nYou can use the tracking link for delivery updates from here." . $policy_close,
			),
			'shipped_tracking' => array(
				'label'   => __( 'Shipped with tracking', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'PeptideGo wholesale order #%s has shipped', $order_number ),
				'body'    => $greeting . $order_line . "\n\nYour wholesale order has been inspected and shipped.\n\nCarrier: " . ( '' !== $carrier ? $carrier : 'Shipping carrier' ) . "\nTracking: " . $tracking . ( '' !== $tracking_url ? "\nTrack here: " . $tracking_url : '' ) . ( '' !== $expected_delivery ? "\nEstimated delivery: " . $expected_delivery : '' ) . "\n\nPlease use the carrier tracking page for the latest delivery updates." . $policy_close,
			),
			'delivered_complete' => array(
				'label'   => __( 'Delivered and completed', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'PeptideGo wholesale order #%s delivered', $order_number ),
				'body'    => $greeting . $order_line . "\n\nYour wholesale order has been marked delivered and completed on our side.\n\nIf anything looks off with the shipment, reply to this email so we can look into it quickly." . $policy_close,
			),
			'refund_etransfer' => array(
				'label'   => __( 'Refund by e-Transfer', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'PeptideGo wholesale order #%s - refund update', $order_number ),
				'body'    => $greeting . $order_line . "\n\nUnfortunately, we are not able to fulfill this wholesale order.\n\nWe will be sending your refund back by Interac e-Transfer to the same payment email/source used for this order where possible.\n\nIf your email has Auto-Deposit enabled, no security answer should be needed. If Auto-Deposit is not enabled and your bank asks for a security answer, please use:\n\npgo123\n\nSorry for the inconvenience, and thank you for your understanding." . $common_close,
			),
			'refund_wire' => array(
				'label'   => __( 'Refund by wire/EFT', 'woo-logistics-plugin' ),
				'subject' => sprintf( 'PeptideGo wholesale order #%s - refund update', $order_number ),
				'body'    => $greeting . $order_line . "\n\nUnfortunately, we are not able to fulfill this wholesale order.\n\nWe will be sending your refund back by wire/EFT to the same payment account/source used for this order where possible.\n\nIf the refund needs to go to a different account, please reply with the correct return wire/EFT details before we send it.\n\nSorry for the inconvenience, and thank you for your understanding." . $common_close,
			),
			default => array(),
		};
	}

	/**
	 * Sends a plain-text wholesale follow-up email.
	 */
	private function send_wholesale_followup_email( WC_Order $order, string $subject, string $body ): bool {
		$email = sanitize_email( $order->get_billing_email() );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: PeptideGo Wholesale <wholesale@peptidego.ca>',
			'Reply-To: PeptideGo Wholesale <wholesale@peptidego.ca>',
		);

		return (bool) wp_mail( $email, $subject, $body, $headers );
	}

	/**
	 * Shows the quote follow-up details directly on wholesale order cards.
	 */
	private function render_wholesale_quote_summary( WC_Order $order ): void {
		$products        = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
		$vial_count      = $this->wholesale_quote_vial_count( $products );
		$email           = sanitize_email( $order->get_billing_email() );
		$phone           = sanitize_text_field( $order->get_billing_phone() );
		$province        = $this->wholesale_delivery_province( $order );
		$payment         = trim( (string) $order->get_meta( '_pg_wholesale_payment_option', true ) );
		$release         = trim( (string) $order->get_meta( '_pg_wholesale_release_option', true ) );
		$quote_lines     = $this->wholesale_quote_lines( $order );
		$quote_totals    = $this->wholesale_quote_totals( $order );
		$copy_text       = $this->wholesale_quote_copy_text( $order, $quote_lines, $quote_totals );
		$stage           = trim( (string) $order->get_meta( self::WHOLESALE_STAGE_META, true ) );
		$stage_at        = trim( (string) $order->get_meta( self::WHOLESALE_STAGE_AT_META, true ) );
		$internal_notes  = trim( (string) $order->get_meta( self::WHOLESALE_NOTES_META, true ) );
		$internal_notes_at = trim( (string) $order->get_meta( self::WHOLESALE_NOTES_AT_META, true ) );

		?>
		<div class="wlp-wholesale-summary" aria-label="<?php echo esc_attr__( 'Wholesale quote summary', 'woo-logistics-plugin' ); ?>">
			<?php $this->render_wholesale_workflow( $order ); ?>
			<?php if ( $quote_totals['total'] > 0 ) : ?>
				<div><span><?php echo esc_html( $quote_totals['tax'] > 0 ? __( 'Total including tax', 'woo-logistics-plugin' ) : __( 'Quote total', 'woo-logistics-plugin' ) ); ?></span><strong><?php echo esc_html( $quote_totals['totalFormatted'] ); ?></strong></div>
			<?php endif; ?>
			<?php if ( $vial_count > 0 ) : ?>
				<div><span><?php echo esc_html__( 'Total vials', 'woo-logistics-plugin' ); ?></span><strong><?php echo esc_html( sprintf( _n( '%d vial', '%d vials', $vial_count, 'woo-logistics-plugin' ), $vial_count ) ); ?></strong></div>
			<?php endif; ?>
			<?php if ( '' !== $email ) : ?>
				<div><span><?php echo esc_html__( 'Email', 'woo-logistics-plugin' ); ?></span><strong><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></strong></div>
			<?php endif; ?>
			<?php if ( '' !== $phone ) : ?>
				<div><span><?php echo esc_html__( 'Phone', 'woo-logistics-plugin' ); ?></span><strong><?php echo esc_html( $phone ); ?></strong></div>
			<?php endif; ?>
			<?php if ( '' !== $province ) : ?>
				<div><span><?php echo esc_html__( 'Delivery province', 'woo-logistics-plugin' ); ?></span><strong><?php echo esc_html( $province ); ?></strong></div>
			<?php endif; ?>
			<?php if ( '' !== $payment ) : ?>
				<div><span><?php echo esc_html__( 'Payment', 'woo-logistics-plugin' ); ?></span><strong><?php echo esc_html( $payment ); ?></strong></div>
			<?php endif; ?>
			<?php if ( '' !== $release ) : ?>
				<div><span><?php echo esc_html__( 'Requested shipping', 'woo-logistics-plugin' ); ?></span><strong><?php echo esc_html( $release ); ?></strong></div>
			<?php endif; ?>
			<div><span><?php echo esc_html__( 'Follow-up', 'woo-logistics-plugin' ); ?></span><strong data-wlp-wholesale-stage-label><?php echo esc_html( $this->wholesale_stage_label( $stage ) ); ?></strong></div>
			<?php if ( '' !== $stage_at ) : ?>
				<div><span><?php echo esc_html__( 'Updated', 'woo-logistics-plugin' ); ?></span><strong data-wlp-wholesale-stage-at><?php echo esc_html( $stage_at ); ?></strong></div>
			<?php endif; ?>
			<?php if ( ! empty( $quote_lines ) ) : ?>
				<div class="wlp-wholesale-line-items">
					<span><?php echo esc_html__( 'Requested items', 'woo-logistics-plugin' ); ?></span>
					<div class="wlp-wholesale-line-items__scroll">
						<table>
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Product', 'woo-logistics-plugin' ); ?></th>
									<th><?php echo esc_html__( 'Qty', 'woo-logistics-plugin' ); ?></th>
									<th><?php echo esc_html__( 'Price / vial', 'woo-logistics-plugin' ); ?></th>
									<th><?php echo esc_html__( 'Line total', 'woo-logistics-plugin' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $quote_lines as $quote_line ) : ?>
									<tr>
										<td><?php echo esc_html( $quote_line['name'] ); ?></td>
										<td><?php echo esc_html( $quote_line['quantity'] ); ?></td>
										<td><?php echo esc_html( $quote_line['unitPrice'] ); ?></td>
										<td><strong><?php echo esc_html( $quote_line['lineTotal'] ); ?></strong></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<?php if ( $quote_totals['subtotal'] > 0 ) : ?>
								<tfoot>
									<?php if ( 0.0 !== (float) $quote_totals['credit'] ) : ?>
										<tr>
											<th colspan="3"><?php echo esc_html__( 'Merchandise subtotal', 'woo-logistics-plugin' ); ?></th>
											<td><?php echo esc_html( $quote_totals['merchandiseSubtotalFormatted'] ); ?></td>
										</tr>
										<tr>
											<th colspan="3"><?php echo esc_html__( 'Wholesale credit', 'woo-logistics-plugin' ); ?></th>
											<td><?php echo esc_html( $quote_totals['creditFormatted'] ); ?></td>
										</tr>
										<tr>
											<th colspan="3"><?php echo esc_html__( 'Subtotal after credit', 'woo-logistics-plugin' ); ?></th>
											<td><?php echo esc_html( $quote_totals['subtotalFormatted'] ); ?></td>
										</tr>
									<?php else : ?>
										<tr>
											<th colspan="3"><?php echo esc_html__( 'Subtotal', 'woo-logistics-plugin' ); ?></th>
											<td><?php echo esc_html( $quote_totals['subtotalFormatted'] ); ?></td>
										</tr>
									<?php endif; ?>
                                    <?php if ( $quote_totals['tax'] > 0 ) : ?>
                                        <?php $display_tax_rows = $quote_totals['taxRows'] ?: array( array( 'label' => $quote_totals['taxLabel'], 'formatted' => $quote_totals['taxFormatted'] ) ); ?>
                                        <?php foreach ( $display_tax_rows as $tax_row ) : ?>
                                        <tr><th colspan="3"><?php echo esc_html( $tax_row['label'] ); ?></th><td><?php echo esc_html( $tax_row['formatted'] ); ?></td></tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
									<tr class="wlp-wholesale-line-items__total">
										<th colspan="3"><?php echo esc_html__( 'Total', 'woo-logistics-plugin' ); ?></th>
										<td><strong><?php echo esc_html( $quote_totals['totalFormatted'] ); ?></strong></td>
									</tr>
								</tfoot>
							<?php endif; ?>
						</table>
					</div>
				</div>
			<?php elseif ( '' !== $products ) : ?>
				<p><span><?php echo esc_html__( 'Requested', 'woo-logistics-plugin' ); ?></span><?php echo esc_html( $products ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $copy_text ) : ?>
				<div class="wlp-wholesale-copy">
					<button class="button" type="button" data-wlp-copy-wholesale><?php echo esc_html__( 'Copy customer quote', 'woo-logistics-plugin' ); ?></button>
					<textarea hidden readonly data-wlp-wholesale-copy-source><?php echo esc_textarea( $copy_text ); ?></textarea>
				</div>
			<?php endif; ?>
			<div class="wlp-wholesale-internal-notes" data-wlp-wholesale-notes>
				<label>
					<span><?php echo esc_html__( 'Customer / quote notes', 'woo-logistics-plugin' ); ?></span>
					<textarea rows="3" maxlength="4000" data-wlp-wholesale-notes-field placeholder="<?php echo esc_attr__( 'Add customer context, commitments, follow-up points or quote decisions.', 'woo-logistics-plugin' ); ?>"><?php echo esc_textarea( $internal_notes ); ?></textarea>
				</label>
				<div class="wlp-wholesale-internal-notes__actions">
					<small data-wlp-wholesale-notes-status><?php echo esc_html( '' !== $internal_notes_at ? sprintf( __( 'Private. Saved %s. Never emailed to the customer.', 'woo-logistics-plugin' ), $internal_notes_at ) : __( 'Private. Never emailed to the customer.', 'woo-logistics-plugin' ) ); ?></small>
					<button class="button" type="button" data-wlp-save-wholesale-notes data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"><?php echo esc_html__( 'Save private notes', 'woo-logistics-plugin' ); ?></button>
				</div>
			</div>
		</div>
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
			$preset = $this->quick_buy_preset( $order );

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
	 * Returns true once the stored carrier delivery date is before today.
	 */
	private function delivery_estimate_is_late( string $expected_delivery_date ): bool {
		$expected_delivery_date = trim( $expected_delivery_date );
		if ( '' === $expected_delivery_date ) {
			return false;
		}

		$timestamp = strtotime( $expected_delivery_date );
		if ( false === $timestamp ) {
			return false;
		}

		$expected = wp_date( 'Y-m-d', $timestamp );
		$today    = wp_date( 'Y-m-d', current_time( 'timestamp' ) );

		return $expected < $today;
	}

	/**
	 * Builds compact line item details for quick view.
	 *
	 * @return array<int, array{name: string, sku: string, quantity: int, total: string}>
	 */
	private function wholesale_packing_catalog( WC_Order $order ): array {
		try {
			return array_values( WLP_Split_Shipments::items( $order ) );
		} catch ( Throwable $error ) {
			// Never display a partial parsed list as a complete packing list.
			return array();
		}
	}

	private function quick_view_items( WC_Order $order ): array {
		$items                 = array();
		$has_complimentary_bac = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( $this->is_complimentary_bac_item( $item ) ) {
				$has_complimentary_bac = true;
			}

			$items[] = array(
				'name'     => $item->get_name(),
				'sku'      => $product ? (string) $product->get_sku() : '',
				'quantity' => (int) $item->get_quantity(),
				'total'    => html_entity_decode( wp_strip_all_tags( wc_price( (float) $item->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		if ( ! $this->is_wholesale_order( $order ) && ! $has_complimentary_bac && (float) $order->get_subtotal() >= self::COMPLIMENTARY_BAC_MIN_SUBTOTAL ) {
			$items[] = array(
				'name'     => __( 'Complimentary 10mL BAC Water', 'woo-logistics-plugin' ),
				'sku'      => __( '$299+ order bonus', 'woo-logistics-plugin' ),
				'quantity' => 1,
				'total'    => __( 'Included', 'woo-logistics-plugin' ),
			);
		}

		return $items;
	}

	/**
	 * Checks whether an order line is the complimentary BAC water gift.
	 */
	private function is_complimentary_bac_item( WC_Order_Item_Product $item ): bool {
		if ( 'yes' === strtolower( trim( (string) $item->get_meta( '_peptidego_free_bac_gift', true ) ) ) ) {
			return true;
		}

		$name = strtolower( $item->get_name() );

		return str_contains( $name, 'complimentary' ) && $this->is_bac_water_item( $item );
	}

	/**
	 * Builds compact shipping address lines for quick view.
	 *
	 * @return array<int, string>
	 */
	private function quick_view_shipping_lines( WC_Order $order ): array {
		$city_line = trim( implode( ' ', array_filter( array( trim( (string) $order->get_shipping_city() ), trim( (string) $order->get_shipping_state() ), trim( (string) $order->get_shipping_postcode() ) ) ) ) );
		$address = array_filter(
			array(
				trim( (string) $order->get_shipping_company() ),
				trim( (string) $order->get_shipping_address_1() ),
				trim( (string) $order->get_shipping_address_2() ),
				$city_line,
				trim( (string) $order->get_shipping_country() ),
			)
		);

		return array_values( $address );
	}

	/**
	 * Returns the newest order notes for quick view.
	 *
	 * @return array<int, array{date: string, note: string, customerNote: bool}>
	 */
	private function quick_view_notes( WC_Order $order ): array {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 5,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			)
		);
		$payload = array();

		foreach ( $notes as $note ) {
			$payload[] = array(
				'date'         => $note->date_created ? $note->date_created->date_i18n( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) ) : '',
				'note'         => trim( wp_strip_all_tags( (string) $note->content ) ),
				'customerNote' => (bool) $note->customer_note,
			);
		}

		return $payload;
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
				<h2 data-wlp-drawer-title><?php echo esc_html__( 'Create Canada Post Label', 'woo-logistics-plugin' ); ?></h2>
				<div id="wlp-drawer-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a text setting row.
	 */
	private function field_text( string $key, string $label ): void {
		$value = str_starts_with( $key, 'wlp_return_' ) ? WLP_Settings::return_option( $key ) : (string) get_option( $key, '' );
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
		if ( WLP_Settings::OPTION_NOTE_TEMPLATE === $key ) {
			$value = WLP_Settings::customer_label_note_template();
		} elseif ( WLP_Settings::OPTION_RETURN_TEMPLATE === $key ) {
			$value = WLP_Settings::return_customer_note_template();
		} else {
			$value = (string) get_option( $key, '' );
		}
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
		$value  = str_starts_with( $key, 'wlp_return_' ) ? WLP_Settings::return_option( $key ) : (string) get_option( $key, '' );
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
		$value = str_starts_with( $key, 'wlp_return_' ) ? WLP_Settings::return_option( $key ) : (string) get_option( $key, '' );
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

		$action = sanitize_key( $_POST['action'] ?? '' );
		try {
			WLP_Contract_Shipping::assert_admin_action( $order, $action, $_POST );
		} catch ( RuntimeException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 409 );
		}
		if ( WLP_Split_Shipments::active( $order ) && in_array( $action, array( 'wlp_create_label', 'wlp_quick_buy_label', 'wlp_prepare_reship', 'wlp_mark_packed', 'wlp_mark_shipped', 'wlp_mark_delivered', 'wlp_wholesale_advance', 'wlp_wholesale_set_workflow', 'wlp_wholesale_save_tracking', 'wlp_wholesale_followup', 'wlp_send_customer_note' ), true ) ) {
			wp_send_json_error( array( 'message' => 'This order has split packages. Open Split shipment / packages to manage the correct package. Send any customer update separately after reviewing all package tracking.' ), 409 );
		}

		return $order;
	}

	/**
	 * Reads and validates the reviewed shipping address submitted before a reship.
	 *
	 * @return array<string, string>
	 */
	private function ajax_reship_shipping_address(): array {
		$fields = array(
			'first_name' => 'shippingFirstName',
			'last_name'  => 'shippingLastName',
			'company'    => 'shippingCompany',
			'address_1'  => 'shippingAddress1',
			'address_2'  => 'shippingAddress2',
			'city'       => 'shippingCity',
			'state'      => 'shippingState',
			'postcode'   => 'shippingPostcode',
			'country'    => 'shippingCountry',
		);
		$address = array();

		foreach ( $fields as $key => $post_key ) {
			$address[ $key ] = isset( $_POST[ $post_key ] )
				? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) )
				: '';
		}

		$address['country'] = strtoupper( $address['country'] );
		foreach ( array( 'first_name', 'address_1', 'city', 'state', 'postcode', 'country' ) as $key ) {
			if ( '' === trim( $address[ $key ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Complete the recipient name, street address, city, province/state, postal code, and country before preparing the reship.', 'woo-logistics-plugin' ) ), 400 );
			}
		}

		return $address;
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
	 * Returns the saved retail delivery-protection defaults for an order.
	 *
	 * Orders created before this checkout option existed fall back to the
	 * existing global signature setting and no Card for Pickup selection.
	 *
	 * @return array{signature: bool, card_for_pickup: bool}
	 */
	private function order_delivery_protection_defaults( WC_Order $order ): array {
		$signature = trim( (string) $order->get_meta( '_pg_delivery_signature_required', true ) );
		$card       = trim( (string) $order->get_meta( '_pg_delivery_card_for_pickup', true ) );

		if ( '' === $signature && '' === $card ) {
			return array(
				'signature'       => WLP_Settings::signature_required(),
				'card_for_pickup' => false,
			);
		}

		return array(
			'signature'       => 'yes' === $signature,
			'card_for_pickup' => 'yes' === $card,
		);
	}

	/**
	 * Reads the per-request Card for Pickup option sent by the label drawer.
	 */
	private function ajax_card_for_pickup(): bool {
		$value = isset( $_POST['cardForPickup'] ) ? sanitize_text_field( wp_unslash( $_POST['cardForPickup'] ) ) : '';

		return 'yes' === $value;
	}

	/**
	 * Reads an optional custom package from the label drawer.
	 *
	 * @return array{id: string, name: string, weight: float, length: float, width: float, height: float}|null
	 */
	private function ajax_custom_package( bool $required ): ?array {
		$enabled = isset( $_POST['customPackage'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['customPackage'] ) );
		if ( ! $enabled ) {
			if ( $required ) {
				throw new RuntimeException( __( 'Custom package dimensions are required.', 'woo-logistics-plugin' ) );
			}

			return null;
		}

		$length = $this->ajax_dimension_float( 'customLength', __( 'Length', 'woo-logistics-plugin' ), 1.0, 200.0 );
		$width  = $this->ajax_dimension_float( 'customWidth', __( 'Width', 'woo-logistics-plugin' ), 1.0, 200.0 );
		$height = $this->ajax_dimension_float( 'customHeight', __( 'Height', 'woo-logistics-plugin' ), 1.0, 200.0 );
		$weight = $this->ajax_dimension_float( 'customWeight', __( 'Weight', 'woo-logistics-plugin' ), 0.01, 30.0 );

		return array(
			'id'     => 'custom-box',
			'name'   => sprintf(
				/* translators: 1: length in cm, 2: width in cm, 3: height in cm, 4: weight in kg. */
				__( 'Custom Box (%1$scm x %2$scm x %3$scm, %4$skg)', 'woo-logistics-plugin' ),
				$this->format_package_number( $length ),
				$this->format_package_number( $width ),
				$this->format_package_number( $height ),
				$this->format_package_number( $weight )
			),
			'weight' => $weight,
			'length' => $length,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Reads and validates a numeric custom package field.
	 */
	private function ajax_dimension_float( string $field, string $label, float $min, float $max ): float {
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';

		if ( '' === $value || ! is_numeric( $value ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: package field label. */
					__( '%s must be a number.', 'woo-logistics-plugin' ),
					$label
				)
			);
		}

		$number = (float) $value;
		if ( $number < $min || $number > $max ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: package field label, 2: minimum value, 3: maximum value. */
					__( '%1$s must be between %2$s and %3$s.', 'woo-logistics-plugin' ),
					$label,
					$this->format_package_number( $min ),
					$this->format_package_number( $max )
				)
			);
		}

		return round( $number, 2 );
	}

	/**
	 * Formats package values for admin labels.
	 */
	private function format_package_number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Reads the max extra cost allowed for automatic Xpresspost upgrades.
	 */
	private function ajax_xpresspost_upgrade_limit(): float {
		$value = isset( $_POST['xpresspostUpgradeLimit'] ) ? sanitize_text_field( wp_unslash( $_POST['xpresspostUpgradeLimit'] ) ) : '1.00';

		if ( ! is_numeric( $value ) ) {
			return 1.0;
		}

		return min( 50.0, max( 0.0, (float) $value ) );
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
	 * Returns the rate that best matches the customer's purchased shipping method.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 * @return array<string, string>
	 */
	private function preferred_rate_for_order( array $rates, WC_Order $order, float $xpresspost_upgrade_limit = 1.0 ): array {
		$base_rate = $this->base_quick_buy_rate( $rates, $order );

		return $this->maybe_upgrade_to_xpresspost( $rates, $base_rate, $xpresspost_upgrade_limit );
	}

	/**
	 * Returns the customer-selected/default rate before any Xpresspost upgrade.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 * @return array<string, string>
	 */
	private function base_quick_buy_rate( array $rates, WC_Order $order ): array {
		$order_service = $this->service_code_for_order_shipping( $order );

		if ( '' !== $order_service ) {
			$order_rate = $this->find_rate( $rates, $order_service );
			if ( ! empty( $order_rate ) ) {
				return $order_rate;
			}
		}

		return $this->preferred_rate( $rates );
	}

	/**
	 * Maps the WooCommerce shipping method chosen at checkout to a Canada Post service.
	 */
	private function service_code_for_order_shipping( WC_Order $order ): string {
		$payload = $this->order_shipping_payload( $order );

		return (string) $payload['serviceCode'];
	}

	/**
	 * Builds display data for the WooCommerce shipping service the customer selected.
	 *
	 * @return array{label: string, serviceCode: string}
	 */
	private function order_shipping_payload( WC_Order $order ): array {
		$parts = array();
		$labels = array();

		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if ( ! $shipping_item instanceof WC_Order_Item_Shipping ) {
				continue;
			}

			$parts[] = (string) $shipping_item->get_method_id();
			$parts[] = (string) $shipping_item->get_method_title();
			$parts[] = (string) $shipping_item->get_name();
			$labels[] = (string) $shipping_item->get_name();

			foreach ( $shipping_item->get_meta_data() as $meta ) {
				$parts[] = (string) $meta->key;
				$parts[] = is_scalar( $meta->value ) ? (string) $meta->value : wp_json_encode( $meta->value );
			}
		}

		$value = strtolower( implode( ' ', array_filter( $parts ) ) );
		$code  = '';

		if ( str_contains( $value, 'priority' ) ) {
			$code = 'DOM.PC';
		} elseif ( str_contains( $value, 'xpresspost' ) || str_contains( $value, 'expresspost' ) ) {
			$code = 'DOM.XP';
		} elseif ( str_contains( $value, 'expedited' ) ) {
			$code = 'DOM.EP';
		} elseif ( ! WLP_Settings::hide_regular_parcel() && ( str_contains( $value, 'regular' ) || str_contains( $value, 'standard' ) ) ) {
			$code = 'DOM.RP';
		}

		if ( '' === $code && $this->is_wholesale_order( $order ) && 'CA' === $order->get_shipping_country() ) {
			$code = WLP_Settings::wholesale_service_code( $order );
		}

		return array(
			'label'       => implode( ', ', array_filter( array_unique( $labels ) ) ),
			'serviceCode' => $code,
		);
	}

	/**
	 * Builds display data for the configured return address.
	 *
	 * @return array{label: string, lines: array<int, string>}
	 */
	private function return_address_payload(): array {
		$lines = array_values(
			array_filter(
				array(
					WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_COMPANY ),
					WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_ADDR_1 ),
					WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_ADDR_2 ),
					trim( WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_CITY ) . ', ' . WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_PROV ) . ' ' . WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_POSTAL ) ),
				),
				static fn ( string $line ): bool => '' !== trim( $line )
			)
		);

		return array(
			'label' => WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_COMPANY ) ?: WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_NAME ),
			'lines' => $lines,
		);
	}

	/**
	 * Chooses the package preset for quick buy.
	 *
	 * @return array<string, float|string>
	 */
	private function quick_buy_preset( WC_Order $order ): array {
		if ( $this->should_use_medium_box( $order ) ) {
			$medium_preset = $this->medium_box_preset();

			if ( ! empty( $medium_preset ) ) {
				return $medium_preset;
			}
		}

		return WLP_Settings::default_preset();
	}

	/**
	 * Determines whether the order needs the medium box.
	 */
	private function should_use_medium_box( WC_Order $order ): bool {
		$peptide_vials = $this->peptide_vial_count( $order );

		return $peptide_vials >= 6 || ( $peptide_vials >= 4 && $this->bac_water_count( $order ) > 0 );
	}

	/**
	 * Builds package suggestion details for the label drawer.
	 *
	 * @return array{id: string, label: string, reason: string, peptideVials: int, bacWaters: int}
	 */
	private function package_suggestion_payload( WC_Order $order ): array {
		$preset         = $this->quick_buy_preset( $order );
		$peptide_vials = $this->peptide_vial_count( $order );
		$bac_waters    = $this->bac_water_count( $order );
		$reason         = $this->should_use_medium_box( $order )
			? __( 'Suggested because this order has 6+ peptide vials, or 4+ peptide vials plus BAC water.', 'woo-logistics-plugin' )
			: __( 'Suggested for smaller vial orders.', 'woo-logistics-plugin' );

		return array(
			'id'           => (string) ( $preset['id'] ?? '' ),
			'label'        => ! empty( $preset ) ? $this->preset_label( $preset ) : '',
			'reason'       => $reason,
			'peptideVials' => $peptide_vials,
			'bacWaters'    => $bac_waters,
		);
	}

	/**
	 * Finds the rate the UI should suggest for manual label buying.
	 *
	 * @param array<int, array{preset: array<string, mixed>, rates: array<int, array<string, string>>}> $preset_payloads Preset/rate payloads.
	 * @return array{presetId: string, serviceCode: string, reason: string}
	 */
	private function suggested_rate_payload( array $preset_payloads, WC_Order $order, string $preset_id = '' ): array {
		if ( '' === $preset_id ) {
			$suggested_preset = $this->quick_buy_preset( $order );
			$preset_id        = (string) ( $suggested_preset['id'] ?? '' );
		}

		foreach ( $preset_payloads as $entry ) {
			if ( (string) ( $entry['preset']['id'] ?? '' ) !== $preset_id ) {
				continue;
			}

			$rate = $this->preferred_rate_for_order( $entry['rates'] ?? array(), $order );

			return array(
				'presetId'    => $preset_id,
				'serviceCode' => (string) ( $rate['service_code'] ?? '' ),
				'reason'      => $this->suggested_rate_reason( $entry['rates'] ?? array(), $rate, $order ),
			);
		}

		return array(
			'presetId'    => $preset_id,
			'serviceCode' => '',
			'reason'      => '',
		);
	}

	/**
	 * Builds package suggestion details for a return label drawer.
	 *
	 * @return array{id: string, label: string, reason: string, peptideVials: int, bacWaters: int}
	 */
	private function return_package_suggestion_payload( WC_Order $order ): array {
		$meta      = WLP_Order_Logistics::read( $order );
		$preset_id = trim( (string) ( $meta['preset_id'] ?? '' ) );

		if ( '' !== $preset_id ) {
			try {
				$preset = $this->client->find_preset( $preset_id );

				return array(
					'id'           => (string) ( $preset['id'] ?? '' ),
					'label'        => $this->preset_label( $preset ),
					'reason'       => __( 'Suggested because the original outbound label used this package.', 'woo-logistics-plugin' ),
					'peptideVials' => $this->peptide_vial_count( $order ),
					'bacWaters'    => $this->bac_water_count( $order ),
				);
			} catch ( Throwable $error ) {
				return $this->package_suggestion_payload( $order );
			}
		}

		return $this->package_suggestion_payload( $order );
	}

	/**
	 * Explains why a service was suggested.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 */
	private function suggested_rate_reason( array $rates, array $rate, WC_Order $order ): string {
		if ( 'DOM.XP' === ( $rate['service_code'] ?? '' ) ) {
			$order_service = $this->service_code_for_order_shipping( $order );
			$base_rate     = '' !== $order_service ? $this->find_rate( $rates, $order_service ) : $this->preferred_rate( $rates );

			if ( ! empty( $base_rate ) && 'DOM.XP' !== ( $base_rate['service_code'] ?? '' ) && $this->xpresspost_is_worth_upgrade( $base_rate, $rate, 1.0 ) ) {
				return __( 'Suggested because Xpresspost is faster and within $1 of the cheaper/customer-selected service.', 'woo-logistics-plugin' );
			}
		}

		if ( ( $rate['service_code'] ?? '' ) === $this->service_code_for_order_shipping( $order ) ) {
			return __( 'Suggested because it matches the shipping service the customer picked.', 'woo-logistics-plugin' );
		}

		return __( 'Suggested by your quick-buy service fallback.', 'woo-logistics-plugin' );
	}

	/**
	 * Upgrades to Xpresspost when it is faster and within $1 of the base rate.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 * @return array<string, string>
	 */
	private function maybe_upgrade_to_xpresspost( array $rates, array $base_rate, float $xpresspost_upgrade_limit = 1.0 ): array {
		$xpresspost = $this->find_rate( $rates, 'DOM.XP' );

		if ( empty( $xpresspost ) || empty( $base_rate ) || 'DOM.XP' === ( $base_rate['service_code'] ?? '' ) ) {
			return $base_rate;
		}

		return $this->xpresspost_is_worth_upgrade( $base_rate, $xpresspost, $xpresspost_upgrade_limit ) ? $xpresspost : $base_rate;
	}

	/**
	 * Checks the faster-Xpresspost upgrade rule.
	 */
	private function xpresspost_is_worth_upgrade( array $base_rate, array $xpresspost, float $xpresspost_upgrade_limit = 1.0 ): bool {
		if ( ! is_numeric( $base_rate['due'] ?? null ) || ! is_numeric( $xpresspost['due'] ?? null ) ) {
			return false;
		}

		$base_due = (float) $base_rate['due'];
		$xp_due   = (float) $xpresspost['due'];

		if ( $xp_due > $base_due + max( 0.0, $xpresspost_upgrade_limit ) ) {
			return false;
		}

		$base_days = $this->rate_transit_days( $base_rate );
		$xp_days   = $this->rate_transit_days( $xpresspost );

		return null !== $base_days && null !== $xp_days && $xp_days < $base_days;
	}

	/**
	 * Extracts a comparable transit day count from a rate.
	 */
	private function rate_transit_days( array $rate ): ?int {
		foreach ( array( 'expected_transit_time', 'guaranteed_transit_time', 'max_transit_time', 'min_transit_time' ) as $key ) {
			if ( isset( $rate[ $key ] ) && preg_match( '/\d+/', (string) $rate[ $key ], $matches ) ) {
				return (int) $matches[0];
			}
		}

		return null;
	}

	/**
	 * Returns the configured Medium Box preset when available.
	 *
	 * @return array<string, float|string>
	 */
	private function medium_box_preset(): array {
		foreach ( WLP_Settings::presets() as $preset ) {
			$id   = strtolower( (string) ( $preset['id'] ?? '' ) );
			$name = strtolower( (string) ( $preset['name'] ?? '' ) );

			if ( 'medium-box' === $id || str_contains( $name, 'medium' ) ) {
				return $preset;
			}
		}

		return array();
	}

	/**
	 * Builds the printable label URL.
	 */
	private function print_url( WC_Order $order ): string {
		return admin_url( 'admin-post.php?action=wlp_print_label&order_id=' . $order->get_id() );
	}

	/**
	 * Builds the printable return label URL.
	 */
	private function return_print_url( WC_Order $order ): string {
		return html_entity_decode(
			wp_nonce_url(
				admin_url( 'admin-post.php?action=wlp_print_return_label&order_id=' . $order->get_id() ),
				'wlp_print_return_label_' . $order->get_id()
			),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Builds the printable invoice and guide URL.
	 */
	private function invoice_url( WC_Order $order ): string {
		return html_entity_decode(
			wp_nonce_url(
				admin_url( 'admin-post.php?action=wlp_print_invoice&order_id=' . $order->get_id() ),
				'wlp_print_invoice_' . $order->get_id()
			),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Checks whether fulfillment should include complimentary alcohol pads.
	 */
	private function order_qualifies_for_alcohol_pads( WC_Order $order ): bool {
		return (float) $order->get_subtotal() >= self::ALCOHOL_PADS_MIN_SUBTOTAL;
	}

	/**
	 * Builds the vial case recommendation, excluding bac water line items.
	 *
	 * @return array{count: int, label: string}
	 */
	private function vial_case_summary( WC_Order $order ): array {
		$count = $this->peptide_vial_count( $order );

		if ( 0 === $count ) {
			return array(
				'count' => 0,
				'label' => __( 'No peptide vial case needed', 'woo-logistics-plugin' ),
			);
		}

		$cases = $this->vial_case_plan( $count );
		$parts = array_map(
			static function ( int $size ): string {
				return sprintf(
					/* translators: %d: vial case capacity. */
					_n( '%d vial case', '%d vial case', $size, 'woo-logistics-plugin' ),
					$size
				);
			},
			$cases
		);

		return array(
			'count' => $count,
			'label' => sprintf(
				/* translators: 1: total peptide vial count, 2: vial case recommendation. */
				__( '%1$d peptide vials: %2$s', 'woo-logistics-plugin' ),
				$count,
				implode( ' + ', $parts )
			),
		);
	}

	/**
	 * Counts peptide vial line items, excluding bac water and complimentary bac water.
	 */
	private function peptide_vial_count( WC_Order $order ): int {
		$count = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( $this->is_bac_water_item( $item ) ) {
				continue;
			}

			$count += (int) $item->get_quantity();
		}

		return $count;
	}

	/**
	 * Counts BAC water line items, including complimentary BAC water.
	 */
	private function bac_water_count( WC_Order $order ): int {
		$count = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( ! $this->is_bac_water_item( $item ) ) {
				continue;
			}

			$count += (int) $item->get_quantity();
		}

		return $count;
	}

	/**
	 * Builds prelabel totals for orders that still need packing.
	 *
	 * @param array<int, WC_Order> $orders Orders in the current logistics tab.
	 * @return array{order_count: int, vial_count: int, case_count: int, bac_count: int, items: array<int, array{name: string, quantity: int}>, case_items: array<int, array{name: string, quantity: int}>, bac_items: array<int, array{name: string, quantity: int}>, orders: array<int, string>}
	 */
	private function prelabel_summary( array $orders ): array {
		$items       = array();
		$case_items  = array();
		$bac_items   = array();
		$order_count = 0;
		$vial_count  = 0;
		$bac_count   = 0;
		$order_ids   = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$meta = WLP_Order_Logistics::read( $order );
			if ( '' !== $meta['packed_at'] ) {
				continue;
			}

			$order_count++;
			$order_ids[] = '#' . $order->get_order_number();

			$peptide_vial_count = $this->peptide_vial_count( $order );
			if ( $peptide_vial_count > 0 ) {
				foreach ( $this->vial_case_plan( $peptide_vial_count ) as $case_size ) {
					if ( ! isset( $case_items[ $case_size ] ) ) {
						$case_items[ $case_size ] = 0;
					}

					$case_items[ $case_size ]++;
				}
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$name     = $this->prelabel_item_name( $item );
				$quantity = (int) $item->get_quantity();
				if ( $quantity <= 0 ) {
					continue;
				}

				if ( $this->is_bac_water_item( $item ) ) {
					if ( ! isset( $bac_items[ $name ] ) ) {
						$bac_items[ $name ] = 0;
					}

					$bac_items[ $name ] += $quantity;
					$bac_count          += $quantity;
					continue;
				}

				if ( ! isset( $items[ $name ] ) ) {
					$items[ $name ] = 0;
				}

				$items[ $name ] += $quantity;
				$vial_count     += $quantity;
			}
		}

		ksort( $items, SORT_NATURAL | SORT_FLAG_CASE );
		ksort( $bac_items, SORT_NATURAL | SORT_FLAG_CASE );
		krsort( $case_items, SORT_NUMERIC );

		$rows = array();
		foreach ( $items as $name => $quantity ) {
			$rows[] = array(
				'name'     => $name,
				'quantity' => $quantity,
			);
		}

		$bac_rows = array();
		foreach ( $bac_items as $name => $quantity ) {
			$bac_rows[] = array(
				'name'     => $name,
				'quantity' => $quantity,
			);
		}

		$case_rows = array();
		foreach ( $case_items as $case_size => $quantity ) {
			$case_rows[] = array(
				'name'     => sprintf(
					/* translators: %d: vial case capacity. */
					__( '%d-vial case', 'woo-logistics-plugin' ),
					$case_size
				),
				'quantity' => $quantity,
			);
		}

		return array(
			'order_count' => $order_count,
			'vial_count'  => $vial_count,
			'case_count'  => array_sum( $case_items ),
			'bac_count'   => $bac_count,
			'items'       => $rows,
			'case_items'  => $case_rows,
			'bac_items'   => $bac_rows,
			'orders'      => $order_ids,
		);
	}

	/**
	 * Returns the display name used in the prelabel totals.
	 */
	private function prelabel_item_name( WC_Order_Item_Product $item ): string {
		$name = trim( $item->get_name() );

		return '' !== $name ? $name : __( 'Unnamed product', 'woo-logistics-plugin' );
	}

	/**
	 * Checks if a line item is bac water or complimentary bac water.
	 */
	private function is_bac_water_item( WC_Order_Item_Product $item ): bool {
		$product = $item->get_product();
		$value   = strtolower( $item->get_name() . ' ' . ( $product ? (string) $product->get_sku() : '' ) );
		$value   = preg_replace( '/[^a-z0-9]+/', ' ', $value ) ?: '';

		return (bool) preg_match( '/\b(bac water|bacteriostatic water|complimentary bac water)\b/', $value );
	}

	/**
	 * Chooses the smallest-overage combination of available vial cases.
	 *
	 * @return array<int, int>
	 */
	private function vial_case_plan( int $vial_count ): array {
		$sizes      = array( 10, 6, 4, 2 );
		$max_search = $vial_count + 10;
		$best       = null;

		for ( $capacity = $vial_count; $capacity <= $max_search; $capacity++ ) {
			$plans = array( 0 => array() );

			for ( $total = 1; $total <= $capacity; $total++ ) {
				foreach ( $sizes as $size ) {
					if ( $total < $size || ! isset( $plans[ $total - $size ] ) ) {
						continue;
					}

					$plan = array_merge( $plans[ $total - $size ], array( $size ) );
					rsort( $plan );

					if ( ! isset( $plans[ $total ] ) || count( $plan ) < count( $plans[ $total ] ) || ( count( $plan ) === count( $plans[ $total ] ) && $plan > $plans[ $total ] ) ) {
						$plans[ $total ] = $plan;
					}
				}
			}

			if ( isset( $plans[ $capacity ] ) ) {
				$best = $plans[ $capacity ];
				break;
			}
		}

		return $best ?: array( 2 );
	}

	/**
	 * Renders the printable alcohol pads fulfillment reminder.
	 */
	private function render_alcohol_pads_invoice_reminder( WC_Order $order ): void {
		if ( ! $this->order_qualifies_for_alcohol_pads( $order ) ) {
			return;
		}
		?>
		<div class="wlp-invoice__fulfillment">
			<?php echo esc_html__( 'Fulfillment reminder: add 20 complimentary alcohol pads (order $299+).', 'woo-logistics-plugin' ); ?>
		</div>
		<?php
	}

	/**
	 * Calculates item discount shown on printable invoices.
	 */
	private function invoice_discount_total( WC_Order $order ): float {
		$subtotal = 0.0;
		$total    = 0.0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$subtotal += (float) $item->get_subtotal();
			$total    += (float) $item->get_total();
		}

		return max( 0.0, $subtotal - $total );
	}

	/**
	 * Formats money for the printable invoice.
	 *
	 * @param float|int|string $amount Monetary amount.
	 */
	private function invoice_money( WC_Order $order, $amount ): string {
		return html_entity_decode(
			wp_strip_all_tags(
				wc_price(
					(float) $amount,
					array(
						'currency' => $order->get_currency(),
					)
				)
			),
			ENT_QUOTES,
			'UTF-8'
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

		return html_entity_decode(
			wp_nonce_url(
				admin_url( 'admin-post.php?action=wlp_bulk_print_labels&order_ids=' . rawurlencode( implode( ',', $order_ids ) ) ),
				'wlp_bulk_print_labels'
			),
			ENT_QUOTES,
			'UTF-8'
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
		$recipient = trim( (string) $order->get_formatted_shipping_full_name() );

		if ( '' === $recipient ) {
			$recipient = trim( (string) $order->get_formatted_billing_full_name() );
		}

		$parts = array_filter(
			array(
				$recipient,
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
