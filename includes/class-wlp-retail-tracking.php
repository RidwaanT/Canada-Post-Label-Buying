<?php
/** Read-only customer shipment display. Never polls, emails, or mutates orders. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WLP_Retail_Tracking {
	public static function register(): void {
		add_action( 'woocommerce_order_details_before_order_table', array( self::class, 'render_order' ), 5 );
		add_action( 'wp_enqueue_scripts', static function(): void {
			if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) { return; }
			wp_enqueue_style( 'wlp-retail-tracking', WLP_URL . 'assets/css/retail-tracking.css', array(), WLP_VERSION );
			wp_enqueue_script( 'wlp-retail-tracking', WLP_URL . 'assets/js/retail-tracking.js', array(), WLP_VERSION, true );
		} );
		add_action( 'template_redirect', static function(): void {
			if ( function_exists( 'is_account_page' ) && is_account_page() && is_user_logged_in() ) { nocache_headers(); }
		} );
		add_action( 'woocommerce_account_dashboard', array( self::class, 'staff_preview' ) );
	}
	public static function can_view( WC_Order $order ): bool {
		return is_user_logged_in() && ( current_user_can( 'manage_woocommerce' ) || ( $order->get_customer_id() > 0 && (int) $order->get_customer_id() === get_current_user_id() ) );
	}
	private static function meta( WC_Order $order, string $key ): string {
		$v = $order->get_meta( '_wlp_' . $key, true );
		if ( ! is_scalar( $v ) || '' === trim( (string) $v ) ) { $v = $order->get_meta( '_medusa_logistics_' . $key, true ); }
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}
	public static function state( array $p ): int {
		return ! empty( $p['delivered'] ) ? 3 : ( ! empty( $p['shipped'] ) ? 2 : ( ! empty( $p['tracking'] ) || ! empty( $p['label_created'] ) ? 1 : 0 ) );
	}
	public static function packages( WC_Order $order ): array {
		$plan = $order->get_meta( '_wlp_split_shipments', true );
		if ( is_array( $plan ) && ! empty( $plan['packages'] ) && is_array( $plan['packages'] ) ) {
			$out = array();
			foreach ( $plan['packages'] as $p ) {
				if ( ! is_array( $p ) ) { continue; }
				$l = isset( $p['label'] ) && is_array( $p['label'] ) ? $p['label'] : array();
				$out[] = array( 'tracking' => $l['tracking_number'] ?? '', 'service' => $l['service_name'] ?? '', 'shipped' => $p['shippedAt'] ?? '', 'delivered' => $p['deliveredAt'] ?? '', 'estimate' => $p['deliveryDate'] ?? ( $l['expected_delivery_date'] ?? '' ), 'label_created' => $l['created_at'] ?? '' );
			}
			return $out;
		}
		return array( array( 'tracking' => self::meta( $order, 'tracking_number' ), 'service' => self::meta( $order, 'service_name' ), 'shipped' => self::meta( $order, 'shipped_at' ), 'delivered' => self::meta( $order, 'delivered_at' ), 'estimate' => self::meta( $order, 'expected_delivery_date' ), 'label_created' => self::meta( $order, 'label_created_at' ) ) );
	}
	public static function render_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! function_exists( 'is_account_page' ) || ! is_account_page() || ! self::can_view( $order ) ) { return; }
		if ( $order->get_meta( '_pg_wholesale_quote_products', true ) || 'yes' === $order->get_meta( '_wlp_is_wholesale', true ) || 'yes' === $order->get_meta( '_pg_is_wholesale', true ) || 'wholesale_quote' === $order->get_meta( '_pg_order_source', true ) || $order->get_meta( '_wlp_join_parent', true ) || $order->get_meta( '_wlp_join_children', true ) ) { return; }
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded', 'pending', 'on-hold' ) ) ) { return; }
		$packages = self::packages( $order );
		foreach ( $packages as $i => $p ) { self::card( $p, count( $packages ) > 1 ? 'Package ' . ( $i + 1 ) . ' of ' . count( $packages ) : '' ); }
	}
	private static function date_label( string $value ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:$|[ T])/', $value ) ) { return ''; }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $value, 0, 10 ), wp_timezone() );
		return false === $date || $date->format( 'Y-m-d' ) !== substr( $value, 0, 10 ) ? '' : wp_date( 'F j, Y', $date->getTimestamp() );
	}
	public static function card( array $p, string $label = '' ): void {
		foreach ( array( 'tracking','service','shipped','delivered','estimate','label_created' ) as $k ) { $p[$k] = isset( $p[$k] ) && is_scalar( $p[$k] ) ? trim( (string) $p[$k] ) : ''; }
		$step = self::state( $p );
		$names = array( 'Confirmed', 'Preparing', 'Shipped', 'Delivered' );
		$titles = array( 'Your order is confirmed', 'We’re preparing your order', 'Your order is on its way', 'Your package has arrived' );
		$descriptions = array( 'Tracking will appear once your shipping label is ready.', 'Your shipping label is ready. Tracking will update once the carrier receives your package.', 'Your package has been marked shipped. Follow its journey using the tracking link below.', 'Your package has been marked delivered. Thank you for your order.' );
		$canada = '' === $p['service'] || (bool) preg_match( '/Canada\s*Post|Xpresspost|Expedited|Priority|Regular Parcel/i', $p['service'] );
		$carrier = $canada ? 'Canada Post' : $p['service'];
		$date = self::date_label( 3 === $step ? $p['delivered'] : $p['estimate'] );
		// Never advertise an old estimate as a future delivery date.
		if ( 3 !== $step && $date && substr( $p['estimate'], 0, 10 ) < current_time( 'Y-m-d' ) ) { $date = ''; }
		?>
		<section class="wlp-retail-tracking" aria-label="Shipment tracking">
			<div class="wlp-rt-main">
				<div class="wlp-rt-top"><div class="wlp-rt-carrier"><?php if ( $canada ) : ?><img src="https://www.canadapost-postescanada.ca/cpc/assets/cpc/img/logos/favicon.ico" alt="" width="38" height="38"><?php endif; ?><div><strong><?php echo esc_html( $carrier ); ?></strong><small><?php echo esc_html( $label ?: 'Tracking your delivery' ); ?></small></div></div><span class="wlp-rt-status"><?php echo esc_html( $names[$step] ); ?></span></div>
				<h3><?php echo esc_html( $titles[$step] ); ?></h3><p class="wlp-rt-description"><?php echo esc_html( $descriptions[$step] ); ?></p>
				<div class="wlp-rt-estimate"><span><?php echo 3 === $step ? 'Delivered on' : 'Estimated delivery'; ?></span><strong class="<?php echo $date ? '' : 'wlp-rt-pending'; ?>"><?php echo esc_html( $date ?: ( 3 === $step ? 'Delivery confirmed' : 'Awaiting carrier update' ) ); ?></strong><small><?php echo 3 === $step ? 'Saved delivery confirmation.' : ( $date ? 'Delivery dates are estimates and may change.' : 'A delivery estimate will appear when available.' ); ?></small></div>
				<ol class="wlp-rt-steps"><?php foreach ( $names as $i => $name ) : ?><li class="<?php echo $i < $step ? 'is-done' : ( $i === $step ? 'is-current' : '' ); ?>" <?php if ( $i === $step ) { echo 'aria-current="step"'; } ?>><?php echo esc_html( $name ); ?></li><?php endforeach; ?></ol>
			</div>
			<?php if ( $p['tracking'] ) : ?><div class="wlp-rt-bottom"><div><span class="wlp-rt-small">Tracking number</span><div class="wlp-rt-number"><code><?php echo esc_html( $p['tracking'] ); ?></code><button type="button" class="wlp-rt-copy" aria-live="polite">Copy number</button></div></div><?php if ( $canada ) : ?><a class="wlp-rt-track" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $p['tracking'] ) ); ?>">Track with Canada Post ↗</a><?php endif; ?></div><?php endif; ?>
		</section><p class="wlp-rt-help">Questions about your delivery? <a href="mailto:support@peptidego.ca">Contact us</a></p>
		<?php
	}
	public static function staff_preview(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		if ( ! isset( $_GET['wlp_retail_preview'] ) ) { return; }
		echo '<p><strong>Staff design preview · Fictional shipment · No emails or order changes</strong></p>';
		self::card( array( 'tracking' => 'DEMO-NOT-A-REAL-PARCEL', 'service' => 'Canada Post', 'shipped' => current_time( 'mysql' ), 'estimate' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ), 'Sample shipment' );
	}
}
