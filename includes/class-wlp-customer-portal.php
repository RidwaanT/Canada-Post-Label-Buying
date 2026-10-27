<?php
/** Customer-only wholesale projection. Never return admin payloads or internal notes. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WLP_Customer_Portal {
	public const INBOX = 'wholesale@peptidego.ca';
	public static function register(): void {
		add_filter( 'woocommerce_get_query_vars', static function( $vars ) { $vars['wholesale'] = 'wholesale'; return $vars; } );
		add_filter( 'woocommerce_account_menu_items', static function( $items ) { $logout = $items['customer-logout'] ?? 'Log out'; unset( $items['customer-logout'] ); $items['wholesale'] = 'Wholesale quotes & shipments'; $items['customer-logout'] = $logout; return $items; } );
		add_filter( 'woocommerce_get_endpoint_url', static function( $url, $endpoint ) { return 'wholesale' === $endpoint ? self::url() : $url; }, 10, 2 );
		add_action( 'woocommerce_account_wholesale_endpoint', array( self::class, 'render' ) );
		add_action( 'woocommerce_account_dashboard', static function() { echo '<p><a class="button" href="' . esc_url( self::url() ) . '">View wholesale quotes &amp; shipments</a></p>'; } );
		add_action( 'admin_post_wlp_portal', array( self::class, 'handle' ) );
		add_action( 'template_redirect', static function() { if ( function_exists( 'is_account_page' ) && is_account_page() ) { nocache_headers(); } } );
		add_action( 'wp_enqueue_scripts', static function() { if ( function_exists( 'is_account_page' ) && is_account_page() ) { wp_enqueue_style( 'wlp-customer-portal', WLP_URL . 'assets/css/customer-portal.css', array(), WLP_VERSION ); } } );
	}
	public static function url(): string { return add_query_arg( 'wholesale', '1', wc_get_page_permalink( 'myaccount' ) ); }
	public static function wholesale( WC_Order $order ): bool { return (bool) $order->get_meta( '_pg_wholesale_quote_products', true ); }
	private static function email( int $uid ): string { $u = get_userdata( $uid ); return $u ? strtolower( trim( $u->user_email ) ) : ''; }
	public static function verified_email( int $uid ): string {
		$v = get_user_meta( $uid, '_wlp_portal_verified', true ); $email = self::email( $uid );
		return $email && is_array( $v ) && ( $v['expires'] ?? 0 ) > time() && hash_equals( (string) ( $v['email'] ?? '' ), $email ) ? $email : '';
	}
	public static function can_view( WC_Order $order, int $uid ): bool {
		if ( $uid < 1 || ! self::wholesale( $order ) ) { return false; }
		$owner = (int) $order->get_customer_id();
		if ( $owner > 0 ) { return $owner === $uid; }
		$email = self::verified_email( $uid );
		return '' !== $email && hash_equals( strtolower( trim( $order->get_billing_email() ) ), $email );
	}
	private static function split_class(): void { if ( ! class_exists( 'WLP_Split_Shipments' ) ) { require_once __DIR__ . '/class-wlp-split-shipments.php'; } }
	public static function group( WC_Order $order, int $uid ): array {
		if ( ! self::can_view( $order, $uid ) ) { throw new RuntimeException( 'This record is unavailable.' ); }
		self::split_class(); $root = WLP_Split_Shipments::root_order( $order ); $group = array();
		foreach ( WLP_Split_Shipments::joined_ids( $root ) as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o instanceof WC_Order || ! self::can_view( $o, $uid ) ) { throw new RuntimeException( 'Please contact wholesale support to review this joined shipment.' ); }
			$group[] = $o;
		}
		return $group;
	}
	public static function stage( WC_Order $order ): string {
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) { return wc_get_order_status_name( $order->get_status() ); }
		if ( $order->get_meta( '_wlp_delivered_at', true ) ) { return 'Delivered'; }
		if ( $order->get_meta( '_wlp_shipped_at', true ) ) { return 'Shipped'; }
		return match ( (string) $order->get_meta( '_wlp_wholesale_workflow_stage', true ) ) {
			'awaiting_customer' => 'Waiting for your details', 'stock_check' => 'Checking availability',
			'final_quote' => 'Quote being finalized', 'awaiting_payment' => 'Awaiting payment confirmation',
			'supplier_order' => 'Preparing your order', 'inbound_hamilton' => 'On the way to our Hamilton hub',
			'ready_to_ship' => 'At our hub, preparing packages', default => 'Quote / order received',
		};
	}
	public static function tracking_url( array $label ): string {
		$n = (string) ( $label['tracking_number'] ?? '' ); $service = (string) ( $label['service_name'] ?? '' );
		if ( ! $n || ( empty( $label['shipment_id'] ) && ! preg_match( '/Canada\s*Post|^(Priority|Xpresspost|Expedited Parcel|Regular Parcel)$/i', $service ) ) ) { return ''; }
		return 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $n );
	}
	/** Strict public projection: no addresses, supplier identifiers, packing notes, label artifacts or costs. */
	public static function projection( WC_Order $order, int $uid ): array {
		$group = self::group( $order, $uid );
		return self::project_group( $group );
	}
	private static function project_group( array $group ): array {
		$root = $group[0];
		$items = WLP_Split_Shipments::items( $root ); $plan = WLP_Split_Shipments::read( $root );
		if ( empty( $plan['packages'] ) ) {
			$tracking = (string) ( $root->get_meta( '_wlp_tracking_number', true ) ?: $root->get_meta( '_wlp_wholesale_customer_tracking_number', true ) );
			if ( $tracking ) {
				$plan['packages'] = array( array( 'items' => array_map( static fn( $item ) => $item['quantity'], $items ), 'label' => array( 'tracking_number' => $tracking, 'service_name' => (string) ( $root->get_meta( '_wlp_service_name', true ) ?: 'Canada Post' ) ), 'shippedAt' => $root->get_meta( '_wlp_shipped_at', true ), 'deliveredAt' => $root->get_meta( '_wlp_delivered_at', true ), 'deliveryDate' => $root->get_meta( '_wlp_expected_delivery_date', true ) ) );
			}
		}
		WLP_Split_Shipments::validate( $plan['packages'], $items );
		$packages = array(); $assigned = array();
		foreach ( $plan['packages'] as $p ) {
			$lines = array();
			foreach ( $p['items'] as $key => $qty ) { $lines[] = array( 'name' => $items[$key]['name'], 'quantity' => $qty ); $assigned[$key] = ( $assigned[$key] ?? 0 ) + $qty; }
			$label = $p['label'] ?? array();
			$packages[] = array( 'items' => $lines, 'status' => ! empty( $p['deliveredAt'] ) ? 'Delivered' : ( ! empty( $p['shippedAt'] ) ? 'Shipped' : ( ! empty( $label['tracking_number'] ) ? 'Tracking ready, not yet shipped' : 'Preparing, not yet shipped' ) ), 'tracking' => $label['tracking_number'] ?? '', 'url' => self::tracking_url( $label ), 'delivery' => $p['deliveryDate'] ?? ( $label['expected_delivery_date'] ?? '' ), 'dispatch' => empty( $p['shippedAt'] ) ? ( $p['dispatchDate'] ?? '' ) : '', 'shipped' => ! empty( $p['shippedAt'] ) );
		}
		$remaining = array(); foreach ( $items as $key => $item ) { $qty = $item['quantity'] - ( $assigned[$key] ?? 0 ); if ( $qty > 0 ) { $remaining[] = array( 'name' => $item['name'], 'quantity' => $qty ); } }
		$records = array(); foreach ( $group as $o ) { $estimate = $o->get_meta( '_pg_wholesale_estimated_total', true ); $records[] = array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'currency' => $o->get_currency(), 'estimate' => is_numeric( $estimate ) ? (string) $estimate : null ); }
		$shipped = count( array_filter( $packages, static fn( $p ) => $p['shipped'] ) );
		$stage = $shipped ? ( $shipped < count( $packages ) || $remaining ? 'Partially shipped' : 'All packages shipped' ) : self::stage( $root );
		if ( $packages && ! $remaining && count( array_filter( $packages, static fn( $p ) => 'Delivered' === $p['status'] ) ) === count( $packages ) ) { $stage = 'Delivered'; }
		return array( 'root' => $root->get_id(), 'records' => $records, 'stage' => $stage, 'packages' => $packages, 'remaining' => $remaining );
	}
	private static function form_start( string $op, int $order = 0 ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wlp_portal"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="order_id" value="' . $order . '">';
		wp_nonce_field( 'wlp_portal_' . $op . '_' . $order );
	}
	private static function lines( array $items ): void { echo '<ul class="wlp-portal-items">'; foreach ( $items as $item ) { echo '<li><span>' . esc_html( $item['name'] ) . '</span><strong>× ' . (int) $item['quantity'] . '</strong></li>'; } echo '</ul>'; }
	/** Render only the authorized public projection, never admin metadata. */
	public static function render_package( array $p, int $index, int $total ): void {
		$delivered = 'Delivered' === $p['status'];
		$shipped = ! empty( $p['shipped'] );
		$step = $delivered ? 3 : ( $shipped ? 2 : 1 );
		$status = $delivered ? 'Delivered' : ( $shipped ? 'Shipped' : 'Preparing' );
		$delivery = (string) ( $p['delivery'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $delivery ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $delivery, wp_timezone() );
			$delivery = $date && $date->format( 'Y-m-d' ) === $delivery && $delivery >= current_time( 'Y-m-d' ) ? wp_date( 'F j, Y', $date->getTimestamp() ) : '';
		}
		echo '<section class="wlp-retail-tracking wlp-wholesale-package"><div class="wlp-rt-main"><div class="wlp-rt-top"><div class="wlp-rt-carrier">';
		if ( $p['url'] ) { echo '<img src="https://www.canadapost-postescanada.ca/cpc/assets/cpc/img/logos/favicon.ico" alt="" width="38" height="38">'; }
		echo '<div><strong>' . ( $p['url'] ? 'Canada Post' : 'Your shipment' ) . '</strong><small>Package ' . ( $index + 1 ) . ' of ' . $total . '</small></div></div><span class="wlp-rt-status">' . esc_html( $status ) . '</span></div>';
		echo '<h3>' . ( $delivered ? 'Your package has arrived' : ( $shipped ? 'Your package is on its way' : 'We’re preparing this package' ) ) . '</h3><p class="wlp-rt-description">' . ( $delivered ? 'This package has been marked delivered.' : ( $shipped ? 'Follow this package using its tracking details below.' : ( $p['tracking'] ? 'Your shipping label is ready. Tracking will update once the carrier receives this package.' : 'Tracking will appear when the shipping label is ready.' ) ) ) . '</p>';
		if ( ! $delivered ) { echo '<div class="wlp-rt-estimate"><span>Estimated delivery</span><strong class="' . ( $delivery ? '' : 'wlp-rt-pending' ) . '">' . esc_html( $delivery ?: 'To be confirmed' ) . '</strong><small>Delivery dates are estimates and may change.</small></div>'; }
		if ( ! $shipped && ! empty( $p['dispatch'] ) ) { echo '<p class="wlp-portal-muted">Estimated dispatch: ' . esc_html( $p['dispatch'] ) . '</p>'; }
		echo '<ol class="wlp-rt-steps">';
		foreach ( array( 'Confirmed', 'Preparing', 'Shipped', 'Delivered' ) as $i => $name ) { echo '<li class="' . ( $i < $step ? 'is-done' : ( $i === $step ? 'is-current' : '' ) ) . '"' . ( $i === $step ? ' aria-current="step"' : '' ) . '>' . $name . '</li>'; }
		echo '</ol><details class="wlp-package-contents"><summary>In this package <span>' . array_sum( array_column( $p['items'], 'quantity' ) ) . ' items</span></summary>'; self::lines( $p['items'] ); echo '</details></div>';
		if ( $p['tracking'] ) {
			echo '<div class="wlp-rt-bottom"><div><span class="wlp-rt-small">Tracking number</span><div class="wlp-rt-number"><code>' . esc_html( $p['tracking'] ) . '</code><button type="button" class="wlp-rt-copy" aria-live="polite">Copy number</button></div></div>';
			if ( $p['url'] ) { echo '<a class="wlp-rt-track" href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener noreferrer">Track package ' . ( $index + 1 ) . ' ↗</a>'; }
			echo '</div>';
		}
		echo '</section>';
	}
	public static function demo_allowed(): bool { return is_user_logged_in() && current_user_can( 'manage_woocommerce' ); }
	public static function render_demo(): void {
		if ( ! self::demo_allowed() ) { return; }
		echo '<section class="wlp-portal-verify"><strong>DEMO ONLY · Staff preview</strong><p>This is fictional sample data, not a real order. No payment, shipment or email will be created. Customers cannot see this preview.</p><a href="' . esc_url( self::url() ) . '">Back to my real account</a></section>';
		echo '<article class="wlp-portal-card"><header><h3>Sample order #DEMO-1001</h3><span class="wlp-portal-badge">Partially shipped</span></header><p class="wlp-quote-estimate">Sample quote estimate: <strong>C$1,500.00</strong></p><p class="wlp-portal-muted">Illustrative amount only, not current pricing. Shipping is included.</p>';
		$packages = array(
			array( array( 'name' => 'Tesamorelin 10mg', 'quantity' => 10 ), array( 'name' => 'Retatrutide 10mg', 'quantity' => 10 ), array( 'name' => 'CJC-1295 no DAC + Ipamorelin blend', 'quantity' => 10 ) ),
			array( array( 'name' => 'GHK-CU 50mg', 'quantity' => 20 ), array( 'name' => 'BPC-157 5mg', 'quantity' => 10 ) ),
		);
		foreach ( $packages as $i => $items ) {
			self::render_package( array( 'items' => $items, 'status' => 0 === $i ? 'Shipped' : 'Preparing, not yet shipped', 'shipped' => 0 === $i, 'tracking' => 0 === $i ? 'DEMO-NOT-A-REAL-PARCEL' : '', 'url' => 0 === $i ? 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=DEMO-NOT-A-REAL-PARCEL' : '', 'delivery' => 0 === $i ? 'Next business day (sample only)' : '', 'dispatch' => '' ), $i, count( $packages ) );
		}
		echo '<p class="wlp-portal-muted">Separate packages may arrive on different days. Delivery dates are estimates, not guarantees.</p><details open><summary>Ask about this quote / order</summary><p>Your message would go to ' . esc_html( self::INBOX ) . ' with the order reference.</p><label>Sample message<textarea rows="3" disabled>This is what the quote message box looks like.</textarea></label><button class="button" disabled type="button">Demo only · sending disabled</button></details></article>';
	}

	/** Only staff may project real orders without customer ownership. Never used by message handlers. */
	public static function staff_projection( WC_Order $order ): array {
		if ( ! self::demo_allowed() || ! self::wholesale( $order ) ) { throw new RuntimeException( 'This record is unavailable.' ); }
		self::split_class(); $root = WLP_Split_Shipments::root_order( $order ); $group = array();
		foreach ( WLP_Split_Shipments::joined_ids( $root ) as $id ) {
			$member = wc_get_order( $id );
			if ( ! $member instanceof WC_Order || ! self::wholesale( $member ) ) { throw new RuntimeException( 'This joined shipment needs review.' ); }
			$group[] = $member;
		}
		return self::project_group( $group );
	}
	public static function render_staff(): void {
		if ( ! self::demo_allowed() ) { return; }
		echo '<section class="wlp-portal-verify"><h3>Customer order preview · Admin only</h3><p>Browse all wholesale orders or enter an order number to see its customer-facing details. You remain signed in as yourself.</p><form method="get" action="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '"><input type="hidden" name="wholesale" value="1"><label>Quote / order number<input type="number" name="wlp_preview_order" min="1" required></label><button class="button" type="submit">Preview order</button></form><p><a href="' . esc_url( add_query_arg( 'wlp_staff', '1', self::url() ) ) . '">Browse all wholesale orders</a></p></section>';
		$id = absint( $_GET['wlp_preview_order'] ?? 0 );
		if ( $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || ! self::wholesale( $order ) ) { echo '<p>Wholesale order not found.</p>'; return; }
			echo '<section class="wlp-portal-verify"><strong>Read-only customer view · #' . esc_html( $order->get_order_number() ) . '</strong><p>This previews order content, not the customer’s authenticated session. Customer messages and account actions are disabled.</p><p>' . ( $order->get_customer_id() ? 'Access: linked to a customer account.' : 'Access: guest order. The customer must verify the matching account email before this order appears.' ) . '</p></section>';
			try { self::render_card( self::staff_projection( $order ), 0, true ); } catch ( Throwable $e ) { echo '<p>This quote or joined shipment needs review in Logistics.</p>'; }
			return;
		}
		$page = max( 1, absint( $_GET['wholesale_page'] ?? 1 ) );
		$result = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 12, 'page' => $page, 'orderby' => 'date', 'order' => 'DESC', 'paginate' => true, 'meta_query' => array( array( 'key' => '_pg_wholesale_quote_products', 'compare' => 'EXISTS' ) ) ) );
		echo '<h3>All wholesale quotes &amp; orders</h3><ul>';
		foreach ( $result->orders as $order ) {
			if ( ! self::wholesale( $order ) ) { continue; }
			echo '<li><a href="' . esc_url( add_query_arg( 'wlp_preview_order', $order->get_id(), self::url() ) ) . '">Preview #' . esc_html( $order->get_order_number() ) . '</a> · ' . esc_html( self::stage( $order ) ) . '</li>';
		}
		echo '</ul><nav aria-label="Admin wholesale pages">';
		foreach ( array( 'Previous' => $page - 1, 'Next' => $page + 1 ) as $label => $next ) {
			if ( $next < 1 || $next > $result->max_num_pages ) { continue; }
			echo '<a href="' . esc_url( add_query_arg( 'wholesale_page', $next, add_query_arg( 'wlp_staff', '1', self::url() ) ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '</nav>';
	}
	private static function render_card( array $view, int $uid, bool $preview = false ): void {
		echo '<article class="wlp-portal-card"><header><h3>' . ( count( $view['records'] ) > 1 ? 'Joined orders ' : 'Quote / order ' ) . esc_html( implode( ' + ', array_map( static fn( $r ) => '#' . $r['number'], $view['records'] ) ) ) . '</h3><span class="wlp-portal-badge">' . esc_html( $view['stage'] ) . '</span></header>';
		foreach ( $view['records'] as $r ) { if ( null !== $r['estimate'] ) { echo '<p class="wlp-quote-estimate">Saved quote estimate #' . esc_html( $r['number'] ) . ': <strong>' . wp_kses_post( wc_price( $r['estimate'], array( 'currency' => $r['currency'] ) ) ) . '</strong></p>'; } }
		echo '<p class="wlp-portal-muted">Shipping is included. Quote estimates are not payment confirmations.</p>';
		foreach ( $view['packages'] as $i => $p ) { self::render_package( $p, $i, count( $view['packages'] ) ); }
		$summary_items = array();
		foreach ( array_merge( array_column( $view['packages'], 'items' ), array( $view['remaining'] ) ) as $lines ) {
			foreach ( $lines as $line ) { $name = (string) $line['name']; $summary_items[$name] = ( $summary_items[$name] ?? 0 ) + (int) $line['quantity']; }
		}
		echo '<details class="wlp-order-summary"><summary>Order summary</summary>';
		self::lines( array_map( static fn( $name, $quantity ) => array( 'name' => $name, 'quantity' => $quantity ), array_keys( $summary_items ), array_values( $summary_items ) ) );
		echo '</details>';
		if ( $view['remaining'] ) { echo '<h4>' . ( $view['packages'] ? 'Remaining items, package not assigned yet' : 'Requested items' ) . '</h4>'; self::lines( $view['remaining'] ); }
		echo '<p class="wlp-portal-muted">Delivery dates are estimates, not guarantees. Separate packages may arrive on different days.</p><details><summary>Ask about this quote / order</summary><p>Your message goes to ' . esc_html( self::INBOX ) . ' with the order reference. We will reply by email.</p>';
		if ( ! $preview ) { self::form_start( 'message', $view['root'] ); echo '<input type="hidden" name="request_id" value="' . esc_attr( wp_generate_uuid4() ) . '"><label>Your message<textarea name="message" rows="4" maxlength="4000" required placeholder="Ask a question or request a quote change"></textarea></label><button class="button" type="submit">Send to wholesale support</button></form>'; } else { echo '<p><button class="button" type="button" disabled>Customer messaging disabled in staff preview</button></p>'; }
		$messages = wc_get_order( $view['root'] )->get_meta( '_wlp_portal_messages', true ); $messages = is_array( $messages ) ? $messages : array();
		foreach ( ( $preview ? array() : array_slice( array_reverse( $messages ), 0, 10 ) ) as $msg ) { if ( (int) ( $msg['user'] ?? 0 ) !== $uid ) { continue; } echo '<blockquote><small>' . esc_html( $msg['at'] . ' · ' . ( 'accepted' === $msg['state'] ? 'Submitted' : 'Saved; email notification needs review' ) ) . '</small><p>' . nl2br( esc_html( $msg['text'] ) ) . '</p></blockquote>'; }
		echo '</details>'; if ( ! $preview ) { do_action( 'wlp_portal_order_after', wc_get_order( $view['root'] ), $uid ); } echo '</article>';
	}
	public static function render(): void {
		if ( ! is_user_logged_in() ) { return; } $uid = get_current_user_id();
		echo '<div class="wlp-portal"><header><p class="wlp-portal-eyebrow">YOUR WHOLESALE ACCOUNT</p><h2>Quotes &amp; shipments</h2><p>See what is being prepared, track each package, and ask us about your order.</p></header>';
		if ( self::demo_allowed() ) {
			if ( '1' === ( $_GET['wlp_demo'] ?? '' ) ) { self::render_demo(); echo '</div>'; return; }
			self::render_staff(); echo '</div>'; return;
		}
		if ( ! self::verified_email( $uid ) ) {
			echo '<section class="wlp-portal-verify"><h3>Find your guest quotes and orders</h3><p>Verify your account email to securely see guest orders placed with that same email. Already linked account orders appear below.</p>';
			self::form_start( 'request_code' ); echo '<button class="button" type="submit">Email me a verification code</button></form>';
			self::form_start( 'verify_code' ); echo '<label>Verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" maxlength="8" required></label><button class="button" type="submit">Verify email</button></form></section>';
		}
		$page = max( 1, absint( $_GET['wholesale_page'] ?? 1 ) ); $limit = 12;
		$args = array( 'type' => 'shop_order', 'limit' => $limit, 'page' => $page, 'orderby' => 'date', 'order' => 'DESC', 'paginate' => true, 'meta_query' => array( array( 'key' => '_pg_wholesale_quote_products', 'compare' => 'EXISTS' ) ) );
		// Query by ownership; guest email querying is enabled only after possession verification.
		$own = wc_get_orders( array_merge( $args, array( 'customer_id' => $uid ) ) ); $orders = $own->orders; $more = $page < $own->max_num_pages;
		if ( $email = self::verified_email( $uid ) ) { $guest = wc_get_orders( array_merge( $args, array( 'customer_id' => 0, 'billing_email' => $email ) ) ); $orders = array_merge( $orders, $guest->orders ); $more = $more || $page < $guest->max_num_pages; }
		$shown = array();
		foreach ( $orders as $order ) {
			if ( ! self::can_view( $order, $uid ) ) { continue; }
			try { $view = self::projection( $order, $uid ); } catch ( Throwable $e ) { echo '<p>A quote or shipment needs review. Please contact <a href="mailto:' . esc_attr( self::INBOX ) . '">' . esc_html( self::INBOX ) . '</a>.</p>'; continue; }
			if ( isset( $shown[$view['root']] ) ) { continue; } $shown[$view['root']] = true;
			self::render_card( $view, $uid );
		}
		if ( ! $shown ) { echo '<p>No accessible wholesale records on this page. If a quote used a different email, contact wholesale support for help.</p>'; }
		echo '<nav aria-label="Wholesale pages">'; if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( 'wholesale_page', $page - 1, self::url() ) ) . '">Previous</a> '; } if ( $more ) { echo '<a href="' . esc_url( add_query_arg( 'wholesale_page', $page + 1, self::url() ) ) . '">Next</a>'; } echo '</nav></div>';
	}
	private static function throttle( string $key, int $seconds ): void { if ( get_transient( $key ) ) { throw new RuntimeException( 'Please wait before trying again.' ); } set_transient( $key, 1, $seconds ); }
	/** admin-post.php does not load WooCommerce frontend notices or its session. */
	public static function prepare_notices(): void {
		try {
			if ( ! function_exists( 'WC' ) || ! defined( 'WC_ABSPATH' ) || ! did_action( 'woocommerce_init' ) ) { throw new RuntimeException(); }
			if ( ! function_exists( 'wc_add_notice' ) ) { require_once WC_ABSPATH . 'includes/wc-notice-functions.php'; }
			WC()->initialize_session();
			if ( ! WC()->session || ! function_exists( 'wc_add_notice' ) ) { throw new RuntimeException(); }
		} catch ( Throwable $e ) {
			wp_die( 'Your account session could not be started. Please return to your account and try again.', '', array( 'response' => 503 ) );
		}
	}
	public static function handle(): void {
		if ( ! is_user_logged_in() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Please sign in first.', '', array( 'response' => 403 ) ); }
		$uid = get_current_user_id(); $op = sanitize_key( $_POST['op'] ?? '' ); $id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'wlp_portal_' . $op . '_' . $id );
		self::prepare_notices();
		$lock = 'wlp_portal_user_lock_' . $uid; if ( ! add_option( $lock, time(), '', false ) ) { wc_add_notice( 'Another request is being processed. Please try again shortly.', 'error' ); wp_safe_redirect( self::url() ); exit; }
		try {
			$email = self::email( $uid ); if ( ! is_email( $email ) ) { throw new RuntimeException( 'Please set a valid account email first.' ); }
			if ( 'request_code' === $op ) {
				self::throttle( 'wlp_portal_code_wait_' . $uid, 120 ); $code = (string) random_int( 10000000, 99999999 );
				update_user_meta( $uid, '_wlp_portal_code', array( 'email' => $email, 'hash' => wp_hash_password( $code ), 'expires' => time() + 900, 'attempts' => 0 ) );
				$ok = wp_mail( $email, 'Your PeptideGo wholesale account verification code', "Your verification code is: $code\n\nEnter this code in your signed-in PeptideGo wholesale dashboard. It expires in 15 minutes. Do not share it. If you did not request it, ignore this email.", array( 'Content-Type: text/plain; charset=UTF-8', 'From: PeptideGo Wholesale <' . self::INBOX . '>' ) );
				if ( ! $ok ) { delete_user_meta( $uid, '_wlp_portal_code' ); throw new RuntimeException( 'Verification email could not be sent. Please contact wholesale support.' ); }
				wc_add_notice( 'Verification email submitted. Check the inbox for your account email.', 'success' );
			} elseif ( 'verify_code' === $op ) {
				$v = get_user_meta( $uid, '_wlp_portal_code', true ); $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
				if ( ! is_array( $v ) || ( $v['expires'] ?? 0 ) < time() || ( $v['attempts'] ?? 5 ) >= 5 || ! hash_equals( (string) ( $v['email'] ?? '' ), $email ) ) { throw new RuntimeException( 'Request a new verification code.' ); }
				$v['attempts']++; update_user_meta( $uid, '_wlp_portal_code', $v );
				if ( ! preg_match( '/^[0-9]{8}$/', $code ) || ! wp_check_password( $code, $v['hash'] ) ) { throw new RuntimeException( 'Incorrect verification code.' ); }
				update_user_meta( $uid, '_wlp_portal_verified', array( 'email' => $email, 'expires' => time() + 30 * DAY_IN_SECONDS ) ); delete_user_meta( $uid, '_wlp_portal_code' ); wc_add_notice( 'Email verified. Matching guest wholesale records are now visible.', 'success' );
			} elseif ( 'message' === $op ) {
				$order = wc_get_order( $id ); if ( ! $order instanceof WC_Order ) { throw new RuntimeException( 'This record is unavailable.' ); } self::group( $order, $uid );
				$text = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ); $request = sanitize_text_field( $_POST['request_id'] ?? '' );
				if ( strlen( $text ) < 2 || strlen( $text ) > 4000 || ! preg_match( '/^[a-f0-9-]{36}$/i', $request ) ) { throw new RuntimeException( 'Enter a message of 2 to 4000 characters.' ); }
				$messages = $order->get_meta( '_wlp_portal_messages', true ); $messages = is_array( $messages ) ? $messages : array(); foreach ( $messages as $m ) { if ( ( $m['request'] ?? '' ) === $request ) { throw new RuntimeException( 'This message was already submitted. We will reply by email.' ); } }
				self::throttle( 'wlp_portal_message_wait_' . $uid, 60 );
				$msg = array( 'request' => $request, 'user' => $uid, 'text' => $text, 'at' => current_time( 'mysql' ), 'state' => 'sending' ); $messages[] = $msg; $messages = array_slice( $messages, -50 ); $order->update_meta_data( '_wlp_portal_messages', $messages ); $order->save();
				$order->add_order_note( 'Wholesale dashboard customer message: ' . $text, false, false );
				$ok = wp_mail( self::INBOX, 'Customer message about wholesale quote/order #' . $order->get_order_number(), "A signed-in customer sent a message about wholesale quote/order #" . $order->get_order_number() . ".\nReply to: $email\n\n$text", array( 'Content-Type: text/plain; charset=UTF-8', 'From: PeptideGo Wholesale <' . self::INBOX . '>', 'Reply-To: ' . $email ) );
				$messages[array_key_last($messages)]['state'] = $ok ? 'accepted' : 'failed'; $order->update_meta_data( '_wlp_portal_messages', $messages ); $order->save(); wc_add_notice( $ok ? 'Message submitted. Wholesale support will reply by email.' : 'Message saved, but the email notification failed. Please contact wholesale support.', $ok ? 'success' : 'error' );
			} else { throw new RuntimeException( 'Unknown action.' ); }
		} catch ( Throwable $e ) { wc_add_notice( $e instanceof RuntimeException ? $e->getMessage() : 'Unable to complete this request. Please contact wholesale support.', 'error' ); }
		finally { delete_option( $lock ); }
		wp_safe_redirect( self::url() ); exit;
	}
}
