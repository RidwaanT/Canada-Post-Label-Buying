<?php
/** Dedicated, opt-in wholesale Purchase connection. No catalogue/product data. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WLP_Wholesale_Purchase {
	public const DATASET = '1674431540967215';
	private const OPTION = 'wlp_wholesale_purchase_v1';
	private const META = '_wlp_purchase_1674431540967215_v1';
	private const JOB = 'wlp_wholesale_purchase_send';
	private const PAGE = 'wlp-wholesale-meta';

	public static function boot(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_wlp_wholesale_meta', [ self::class, 'handle' ] );
		add_action( 'woocommerce_order_status_changed', [ self::class, 'transition' ], 20, 4 );
		add_action( 'woocommerce_before_order_object_save', [ self::class, 'capture_origin' ] );
		add_action( self::JOB, [ self::class, 'send' ] );
	}

	private static function settings(): array {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? $value : [];
	}

	private static function save( array $value ): void {
		update_option( self::OPTION, $value, false );
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|wlp-wholesale-purchase-v1', true );
	}

	private static function seal( string $token ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new RuntimeException( 'Secure token storage is unavailable.' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $token, $nonce, self::key() ) );
	}

	private static function token(): string {
		$settings = self::settings();
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) { return ''; }
		$raw = base64_decode( (string) ( $settings['secret'] ?? '' ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) { return ''; }
		try {
			$token = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), self::key() );
			return is_string( $token ) ? $token : '';
		} catch ( Throwable $e ) { return ''; }
	}

	public static function menu(): void {
		$hook = add_submenu_page( 'woocommerce', 'Wholesale Meta', 'Wholesale Meta', 'manage_options', self::PAGE, [ self::class, 'page' ] );
		add_action( 'load-' . $hook, static function (): void {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
		} );
	}

	private static function form( string $mode ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		wp_nonce_field( 'wlp_wholesale_meta' );
		echo '<input type="hidden" name="action" value="wlp_wholesale_meta"><input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.' ); }
		$s = self::settings();
		echo '<div class="wrap" style="max-width:850px"><h1>Wholesale Meta — PGO Canada Bulk</h1>';
		echo '<p>Dataset: <code>' . esc_html( self::DATASET ) . '</code> · Integration version: ' . esc_html( WLP_VERSION ) . '</p>';
		echo '<h2>Live Purchase sending: ' . ( ! empty( $s['enabled'] ) ? 'ON' : 'OFF' ) . '</h2>';
		echo '<p>Only move a wholesale order to <strong>Processing after you have received payment</strong>. This integration does not check your bank. Unpaid pending quotes are not purchases.</p>';
		if ( ! empty( $s['notice'] ) ) { echo '<div class="notice notice-info inline"><p>' . esc_html( $s['notice'] ) . '</p></div>'; }
		if ( ! is_ssl() ) { echo '<p>HTTPS is required. Do not enter a token on this connection.</p></div>'; return; }
		echo '<h2>1. Save your access token privately</h2><p>Token: ' . ( self::token() !== '' ? 'saved (never displayed)' : 'not configured / cannot decrypt' ) . '. Stored encrypted in WordPress; replacing it turns live sending off.</p>';
		self::form( 'save' );
		echo '<label for="wlp-meta-token">Meta access token</label><br><input id="wlp-meta-token" name="token" type="password" autocomplete="new-password" class="large-text" value="" required maxlength="4096">';
		submit_button( 'Save token — keep sending OFF' ); echo '</form>';
		echo '<h2>2. Send a safe test</h2><p>Open Test events for this dataset, copy its current TEST code below, then send a synthetic C$1 Purchase. No customer, order, email or payment is changed. Repeating the same test reuses its event ID.</p>';
		self::form( 'test' );
		echo '<label for="wlp-meta-test">Test event code</label><br><input id="wlp-meta-test" name="test_code" pattern="TEST[A-Za-z0-9]+" placeholder="TEST…" required value="' . esc_attr( (string) ( $s['test_code'] ?? '' ) ) . '">';
		submit_button( 'Send test Purchase' ); echo '</form>';
		echo '<p>Last test: ' . esc_html( ! empty( $s['verified'] ) ? 'Meta accepted one test event; confirm it appears as Server / Purchase in Test events.' : 'Not verified.' ) . '</p>';
		echo '<h2>3. Enable only after verification</h2><p>Review Meta’s data restrictions and your consent obligations first. This sends order value/currency, a hashed email and order identifier, and available customer browser/click identifiers to Meta. It does not send product names, quote text, addresses or health details. Hashing does not make personal data anonymous.</p>';
		self::form( 'enable' );
		echo '<p><label><input type="checkbox" name="confirmed" value="yes" required> I saw the Server Purchase test, have reviewed applicable data-sharing restrictions, and will use Processing only after payment is received.</label></p>';
		submit_button( 'Enable future paid wholesale Purchases', 'primary', 'submit', true, empty( $s['verified'] ) ? [ 'disabled' => 'disabled' ] : [] ); echo '</form>';
		self::form( 'disable' ); submit_button( 'Turn live sending OFF', 'secondary' ); echo '</form>';
		echo '<p>No historical orders are automatically backfilled. Test order #3458 and orders marked <code>_pg_meta_test_order=yes</code> are excluded. Older quotes without captured customer browser data are skipped, not fabricated. Failed transient requests retry up to five times with the same event ID.</p>';
		$last = get_option( 'wlp_wholesale_purchase_last', [] );
		if ( is_array( $last ) && ! empty( $last['message'] ) ) {
			echo '<h2>Latest live status</h2><p>' . esc_html( (string) ( $last['time'] ?? '' ) . ' — ' . $last['message'] ) . '</p>';
		}
		echo '</div>';
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! is_ssl() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Secure administrator POST required.' ); }
		check_admin_referer( 'wlp_wholesale_meta' );
		$lock = self::lock( 'settings' );
		if ( ! $lock ) { wp_die( 'A settings request is already running. Please wait.' ); }
		try {
			$s = self::settings();
			$mode = sanitize_key( (string) ( $_POST['mode'] ?? '' ) );
			if ( 'save' === $mode ) {
				$token = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
				if ( ! preg_match( '/^[A-Za-z0-9_.|\-]{20,4096}$/D', $token ) ) { throw new RuntimeException( 'Enter a valid access token; nothing was saved.' ); }
				$s = [ 'secret' => self::seal( $token ), 'enabled' => false, 'verified' => false, 'notice' => 'Token saved privately. Live sending remains OFF. Send the test next.' ];
			} elseif ( 'disable' === $mode ) {
				$s['enabled'] = false; $s['notice'] = 'Live sending is OFF. No queued events will be sent while disabled.';
			} elseif ( 'test' === $mode ) {
				$code = trim( (string) wp_unslash( $_POST['test_code'] ?? '' ) );
				if ( ! preg_match( '/^TEST[A-Za-z0-9]{1,60}$/D', $code ) ) { throw new RuntimeException( 'Use the current TEST code from this dataset.' ); }
				if ( self::token() === '' ) { throw new RuntimeException( 'Save the access token first.' ); }
				if ( ( $s['test_code'] ?? '' ) !== $code || empty( $s['test_event'] ) || (int) ( $s['test_event']['event_time'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
					$s['verified'] = false;
					$s['test_code'] = $code;
					$s['test_event'] = [ 'event_name' => 'Purchase', 'event_time' => time(), 'event_id' => 'pg_test_' . wp_generate_uuid4(), 'action_source' => 'website', 'event_source_url' => admin_url( 'admin.php?page=' . self::PAGE ), 'user_data' => [ 'external_id' => [ hash( 'sha256', 'pgo-synthetic-capi-test' ) ], 'client_user_agent' => substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 512 ) ], 'custom_data' => [ 'value' => 1.0, 'currency' => 'CAD' ] ];
					self::save( $s );
				}
				if ( empty( $s['verified'] ) ) {
					$r = self::request( $s['test_event'], $code );
					$s['verified'] = $r['ok'];
					$s['notice'] = $r['message'];
				} else { $s['notice'] = 'This test was already accepted. Check Server / Purchase in Meta Test events.'; }
			} elseif ( 'enable' === $mode ) {
				if ( empty( $s['verified'] ) || self::token() === '' || 'yes' !== ( $_POST['confirmed'] ?? '' ) ) { throw new RuntimeException( 'Verify the test and confirm the payment/privacy checklist before enabling.' ); }
				$s['enabled'] = true;
				$s['notice'] = 'Enabled for future paid wholesale transitions to Processing. Historical orders were not sent.';
			} else { throw new RuntimeException( 'Unknown action.' ); }
			self::save( $s );
		} catch ( Throwable $e ) {
			// Never echo exception/request contents: plugins may include credentials there.
			$s = self::settings(); $s['notice'] = 'Could not complete this action. Check the token, test code, HTTPS and test confirmation. No live sending was enabled by this failed request.'; self::save( $s );
		} finally { self::unlock( 'settings', $lock ); }
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) ); exit;
	}

	/** Capture only the original public quote request, never a subsequent admin request. */
	public static function capture_origin( $order ): void {
		if ( ! $order instanceof WC_Order || ! ( doing_action( 'admin_post_pg_wholesale_inquiry' ) || doing_action( 'admin_post_nopriv_pg_wholesale_inquiry' ) ) ) { return; }
		if ( 'wholesale_quote' !== $order->get_meta( '_pg_order_source', true ) || 'yes' !== $order->get_meta( '_pg_meta_measurement_allowed', true ) || $order->get_customer_user_agent() !== '' ) { return; }
		$order->set_customer_user_agent( substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 512 ) );
	}

	public static function transition( $id, $from, $to, $order ): void {
		if ( 'processing' === $to && 'processing' !== $from && $order instanceof WC_Order ) { self::queue( $order ); }
	}

	private static function eligible( WC_Order $order ): bool {
		return 'wholesale_quote' === $order->get_meta( '_pg_order_source', true )
			&& in_array( $order->get_status(), [ 'processing', 'completed' ], true )
			&& 'yes' === $order->get_meta( '_pg_meta_measurement_allowed', true )
			&& 3458 !== $order->get_id() && 'yes' !== $order->get_meta( '_pg_meta_test_order', true )
			&& (float) $order->get_total() > 0 && is_finite( (float) $order->get_total() )
			&& (bool) preg_match( '/^[A-Z]{3}$/D', $order->get_currency() )
			&& is_email( $order->get_billing_email() ) && trim( $order->get_customer_user_agent() ) !== '';
	}

	private static function result( string $status, string $message, string $id = '' ): array { return [ 'status' => $status, 'message' => $message, 'event_id' => $id ]; }
	private static function status( int $id, string $message ): void {
		update_option( 'wlp_wholesale_purchase_last', [ 'time' => gmdate( 'Y-m-d H:i:s' ) . ' UTC', 'message' => 'Order #' . $id . ': ' . $message ], false );
	}

	public static function queue( WC_Order $order ): array {
		$order_id = $order->get_id();
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['verified'] ) || self::token() === '' ) { return self::result( 'skipped', 'Wholesale Purchase sending is OFF or not configured.' ); }
		if ( ! self::eligible( $order ) ) {
			if ( 'wholesale_quote' === $order->get_meta( '_pg_order_source', true ) ) { self::status( $order->get_id(), 'Skipped: check paid status, consent, positive total, email, original browser data and test-order exclusion.' ); }
			return self::result( 'skipped', 'Not an eligible paid wholesale order, or required customer browser/consent data is missing.' );
		}
		$lock = self::lock( 'order_' . $order->get_id() );
		if ( ! $lock ) { return self::result( 'queued', 'Another request is handling this order.' ); }
		try {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order instanceof WC_Order || ! self::eligible( $order ) ) { return self::result( 'skipped', 'Order changed before queueing.' ); }
			$state = $order->get_meta( self::META, true );
			if ( is_array( $state ) && ! empty( $state['event_id'] ) ) { return self::result( ! empty( $state['sent_at'] ) ? 'already_sent' : 'queued', 'This order already has a Purchase record; it will not be duplicated.', $state['event_id'] ); }
			$id = 'pg_wholesale_' . self::DATASET . '_' . $order->get_id() . '_purchase_v1';
			$user = [ 'em' => [ hash( 'sha256', strtolower( trim( $order->get_billing_email() ) ) ) ], 'external_id' => [ hash( 'sha256', 'pg-wholesale-order-' . $order->get_id() ) ], 'client_user_agent' => substr( $order->get_customer_user_agent(), 0, 512 ) ];
			foreach ( [ 'fbc', 'fbp' ] as $field ) {
				$v = (string) $order->get_meta( '_pg_meta_' . $field, true );
				if ( preg_match( '/^fb\.[0-9]+\.[0-9]+\.[A-Za-z0-9_\-]+$/D', $v ) && strlen( $v ) <= 512 ) { $user[$field] = $v; }
			}
			$state = [ 'event_id' => $id, 'attempts' => 0, 'status' => 'queued', 'event' => [ 'event_name' => 'Purchase', 'event_id' => $id, 'event_time' => time(), 'action_source' => 'website', 'event_source_url' => home_url( '/wholesale/' ), 'user_data' => $user, 'custom_data' => [ 'value' => (float) $order->get_total(), 'currency' => $order->get_currency() ] ] ];
			$order->update_meta_data( self::META, $state ); $order->save_meta_data();
			self::schedule( $order->get_id(), 5 ); self::status( $order->get_id(), 'Queued for Meta.' );
			return self::result( 'queued', 'Wholesale Purchase queued for PGO Canada Bulk.', $id );
		} finally { self::unlock( 'order_' . $order_id, $lock ); }
	}

	private static function schedule( int $id, int $delay ): void {
		// A retry is scheduled while the current action is running; unique=true would suppress it.
		if ( function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( time() + $delay, self::JOB, [ $id ], 'wlp-wholesale-meta', false ); }
		elseif ( ! wp_next_scheduled( self::JOB, [ $id ] ) ) { wp_schedule_single_event( time() + $delay, self::JOB, [ $id ] ); }
	}

	public static function send( $id ): void {
		$id = absint( $id ); $lock = self::lock( 'order_' . $id );
		if ( ! $lock ) { self::schedule( $id, 120 ); return; }
		try {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) { return; }
			$state = $order->get_meta( self::META, true );
			if ( ! is_array( $state ) || empty( $state['event'] ) || ! empty( $state['sent_at'] ) || (int) ( $state['attempts'] ?? 0 ) >= 5 ) { return; }
			$s = self::settings();
			if ( empty( $s['enabled'] ) || empty( $s['verified'] ) || ! self::eligible( $order ) || (float) $state['event']['custom_data']['value'] !== (float) $order->get_total() || $state['event']['custom_data']['currency'] !== $order->get_currency() ) {
				$state['status'] = 'cancelled'; unset( $state['event'] ); self::status( $id, 'Cancelled: sending disabled or order no longer eligible.' );
			} elseif ( (int) $state['event']['event_time'] < time() - 6 * DAY_IN_SECONDS ) {
				$state['status'] = 'expired'; unset( $state['event'] ); self::status( $id, 'Expired; not sent with a fabricated event time.' );
			} else {
				++$state['attempts'];
				$r = self::request( $state['event'] );
				$state['status'] = $r['ok'] ? 'sent' : 'failed';
				$state['message'] = $r['message'];
				if ( $r['ok'] ) { $state['sent_at'] = gmdate( 'c' ); unset( $state['event'] ); }
				elseif ( $r['retry'] && $state['attempts'] < 5 ) { self::schedule( $id, 120 * ( 2 ** $state['attempts'] ) ); }
				self::status( $id, $r['message'] );
			}
			$order->update_meta_data( self::META, $state ); $order->save_meta_data();
		} finally { self::unlock( 'order_' . $id, $lock ); }
	}

	private static function request( array $event, string $test_code = '' ): array {
		$token = self::token();
		if ( $token === '' ) { return [ 'ok' => false, 'retry' => false, 'message' => 'No usable token. Save the token again.' ]; }
		$body = [ 'data' => [ $event ] ];
		if ( $test_code !== '' ) { $body['test_event_code'] = $test_code; }
		try {
			$response = wp_remote_post( 'https://graph.facebook.com/v23.0/' . self::DATASET . '/events', [ 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $body ), 'limit_response_size' => 32768 ] );
		} catch ( Throwable $e ) {
			return [ 'ok' => false, 'retry' => true, 'message' => 'Request failed; delivery unconfirmed. Retry uses the same event ID.' ];
		}
		if ( is_wp_error( $response ) ) { return [ 'ok' => false, 'retry' => true, 'message' => 'Network error; delivery unconfirmed. Retry uses the same event ID.' ]; }
		$http = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : [];
		$ok = 200 === $http && is_array( $data ) && empty( $data['error'] ) && 1 === (int) ( $data['events_received'] ?? 0 );
		return [ 'ok' => $ok, 'retry' => $http === 429 || $http >= 500 || ! empty( $data['error']['is_transient'] ), 'message' => $ok ? 'Meta accepted one ' . ( $test_code !== '' ? 'test ' : '' ) . 'Purchase. Check Events Manager for processing.' : 'Meta did not confirm acceptance (HTTP ' . $http . ', code ' . (int) ( $data['error']['code'] ?? 0 ) . '). Check token permissions and dataset restrictions.' ];
	}

	/** Database compare-and-swap lock; never unlock another request's lease. */
	private static function lock( string $name ): string {
		global $wpdb;
		$key = 'wlp_purchase_lock_' . $name; $lease = ( time() + 90 ) . ':' . wp_generate_uuid4();
		if ( add_option( $key, $lease, '', false ) ) { return $lease; }
		$old = (string) get_option( $key, '' );
		if ( (int) $old >= time() ) { return ''; }
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $lease, $key, $old ) );
		wp_cache_delete( $key, 'options' );
		return 1 === $changed ? $lease : '';
	}
	private static function unlock( string $name, string $lease ): void {
		global $wpdb;
		if ( $lease === '' ) { return; }
		$key = 'wlp_purchase_lock_' . $name;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $lease ) );
		wp_cache_delete( $key, 'options' );
	}
}
