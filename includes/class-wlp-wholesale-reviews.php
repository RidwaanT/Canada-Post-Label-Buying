<?php
/** Wholesale experience reviews. Separate consent, delivery and credit ledger from retail. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WLP_Wholesale_Reviews {
	const META = '_wlp_wholesale_review';
	const HOOK = 'wlp_wholesale_review_due';
	const CARRIER_HOOK = 'wlp_wholesale_review_carrier_delivery';
	const CREDIT_MAIL_HOOK = 'wlp_wholesale_review_credit_mail';
	const TYPE = 'wlp_wh_review';
	const STYLE = '.wlp-review label{display:block;margin:12px 0}.wlp-review label:has(input[type=checkbox]){display:flex;gap:10px;align-items:flex-start}.wlp-review input[type=checkbox]{width:auto;flex:0 0 auto;margin:3px 0}.wlp-review select{display:block;width:100%;padding:10px;margin-top:8px}.wlp-review p{line-height:1.5}.wlp-wholesale-reviews__photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.wlp-wholesale-reviews__photos img{width:100%;height:auto;display:block}';
	public static function retail_reminder_skip( $skip, $id ): bool { $o = wc_get_order( (int) $id ); return (bool) $skip || ( $o instanceof WC_Order && WLP_Customer_Portal::wholesale( $o ) ); }
	public static function register(): void {
		add_filter( 'cr_skip_reminder_generic', array( self::class, 'retail_reminder_skip' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', static function() { if ( function_exists( 'is_account_page' ) && is_account_page() ) { wp_add_inline_style( 'wlp-customer-portal', self::STYLE ); } }, 20 );
		add_action( 'init', static function() { register_post_type( self::TYPE, array( 'label' => 'Wholesale reviews', 'public' => false, 'show_ui' => true, 'show_in_menu' => 'woocommerce', 'supports' => array( 'title', 'editor' ), 'capabilities' => array( 'edit_post' => 'manage_woocommerce', 'read_post' => 'manage_woocommerce', 'delete_post' => 'manage_woocommerce', 'edit_posts' => 'manage_woocommerce', 'edit_others_posts' => 'manage_woocommerce', 'publish_posts' => 'manage_woocommerce', 'read_private_posts' => 'manage_woocommerce', 'create_posts' => 'do_not_allow' ), 'map_meta_cap' => false ) ); } );
		add_action( 'woocommerce_after_order_object_save', array( self::class, 'consider' ), 30 );
		add_action( self::HOOK, array( self::class, 'send_due' ) );
		add_action( self::CARRIER_HOOK, array( self::class, 'poll_carrier_delivery' ) );
		add_action( self::CREDIT_MAIL_HOOK, array( self::class, 'send_credit' ) );
		add_action( 'init', static function() { if ( ! wp_next_scheduled( self::CARRIER_HOOK ) ) { wp_schedule_event( time() + 300, 'hourly', self::CARRIER_HOOK ); } } );
		add_action( 'admin_post_wlp_delivery_notice_settings', array( self::class, 'delivery_notice_settings' ) );
		add_action( 'admin_post_wlp_review_settings', array( self::class, 'settings' ) );
		add_action( 'admin_post_wlp_review_credit', array( self::class, 'redeem_handler' ) );
		add_action( 'admin_post_wlp_review_photo_upload', array( self::class, 'staff_photo_upload' ) );
		add_action( 'admin_post_wlp_review', array( self::class, 'handle' ) );
		add_action( 'wlp_portal_order_after', array( self::class, 'render_order' ), 10, 2 );
		add_action( 'admin_menu', static function() { add_submenu_page( 'woocommerce', 'Wholesale review rewards', 'Wholesale review rewards', 'manage_woocommerce', 'wlp-review-rewards', array( self::class, 'admin' ) ); } );
		add_action( 'add_meta_boxes', static function() { add_meta_box( 'wlp-review-details', 'Review details', array( self::class, 'review_box' ), self::TYPE ); } );
		add_action( 'admin_footer-post.php', array( self::class, 'review_upload_form' ) );
		add_shortcode( 'wlp_wholesale_reviews', array( self::class, 'published' ) );
	}
	/** Staff-only form: every additional photo needs affirmative, recorded publication approval. */
	public static function review_box( WP_Post $post ): void {
		echo '<p>Rating: ' . (int) get_post_meta( $post->ID, '_wlp_rating', true ) . ' / 5. Source order #' . (int) get_post_meta( $post->ID, '_wlp_source_order', true ) . '</p><p>Credit is independent of rating and moderation. Do not publish personal information.</p>';
		$photos = self::photos( $post->ID );
		echo '<p><strong>' . count( $photos ) . ' saved photo(s)</strong></p>';
		if ( $photos ) { echo '<div class="wlp-wholesale-reviews__photos">'; foreach ( $photos as $photo ) { echo wp_get_attachment_image( $photo, 'medium' ); } echo '</div>'; }
		if ( ! current_user_can( 'manage_woocommerce' ) || 'publish' !== $post->post_status ) { return; }
		if ( isset( $_GET['wlp_review_photo_added'] ) ) { echo '<div class="notice notice-success inline"><p>Approved photos saved to this review. Gallery now has ' . count( $photos ) . ' photo(s).</p></div>'; }
		echo '<hr><p><strong>Add approved review photos</strong></p><p>Select up to five buyer-approved photos. Each file must be 5 MB or less. Avoid choosing a photo already shown above.</p>';
		echo '<p><input form="wlp-review-photo-upload" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></p><p><label><input form="wlp-review-photo-upload" type="checkbox" name="publication_approved" value="1" required> I confirm the buyer has approved every selected photo for public display with this review.</label></p><p><button form="wlp-review-photo-upload" class="button" type="submit">Add approved photos</button></p>';
	}
	/** Render outside WordPress's post edit form; form-associated controls remain in the review box. */
	public static function review_upload_form(): void {
		global $post;
		if ( ! $post instanceof WP_Post || self::TYPE !== $post->post_type || 'publish' !== $post->post_status || ! current_user_can( 'manage_woocommerce' ) ) { return; }
		echo '<form id="wlp-review-photo-upload" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wlp_review_photo_upload"><input type="hidden" name="review_id" value="' . (int) $post->ID . '">';
		wp_nonce_field( 'wlp_review_photo_upload_' . $post->ID );
		echo '</form>';
	}
	private static function root( WC_Order $order ): WC_Order {
		if ( ! class_exists( 'WLP_Split_Shipments' ) ) { require_once __DIR__ . '/class-wlp-split-shipments.php'; }
		return WLP_Split_Shipments::root_order( $order );
	}
	public static function state( WC_Order $o ): array { $v = $o->get_meta( self::META, true ); return is_array( $v ) ? $v : array(); }
	private static function save( WC_Order $o, array $v ): void { $o->update_meta_data( self::META, $v ); $o->save(); }
	private static function customer_credit_key( WC_Order $o ): string { return hash( 'sha256', strtolower( trim( $o->get_billing_email() ) ) ); }
	/** One review reward per wholesale buyer; recognize legacy ledgers before relying on the indexed key. */
	public static function customer_has_credit( WC_Order $o ): bool {
		$email = strtolower( trim( $o->get_billing_email() ) ); if ( ! is_email( $email ) ) { return true; }
		$indexed = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'status' => 'any', 'exclude' => array( $o->get_id() ), 'meta_key' => '_wlp_review_credit_customer', 'meta_value' => self::customer_credit_key( $o ) ) );
		if ( ! empty( $indexed ) ) { return true; }
		$page = 1;
		do {
			$orders = wc_get_orders( array( 'limit' => 100, 'page' => $page++, 'status' => 'any', 'meta_query' => array( array( 'key' => self::META, 'compare' => 'EXISTS' ) ) ) );
			foreach ( $orders as $prior ) {
				if ( ! $prior instanceof WC_Order || $prior->get_id() === $o->get_id() ) { continue; }
				$state = self::state( self::root( $prior ) );
				if ( ! empty( $state['credit'] ) && $email === strtolower( (string) ( $state['buyer_email'] ?? $prior->get_billing_email() ) ) ) { return true; }
			}
		} while ( count( $orders ) === 100 );
		return false;
	}
	/** Canada Post reports a date without a time; use the end of that day so seven full days pass. */
	public static function carrier_delivery_time( string $date, string $shipped ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}[ T]/', $shipped ) ) { return ''; }
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' 23:59:59', wp_timezone() );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date || $date < substr( $shipped, 0, 10 ) || $date > wp_date( 'Y-m-d' ) ) { return ''; }
		return $d->format( 'Y-m-d H:i:s' );
	}
	/** Apply carrier facts only. No email, Woo status transition, or inference from a label. */
	public static function carrier_progress( array $package, array $result, string $earliest ): array {
		$stamp = (string) ( $result['carrier_shipped_at'] ?? '' );
		$scan = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $stamp, wp_timezone() );
		if ( empty( $package['shippedAt'] ) && $earliest && $scan && $scan->format( 'Y-m-d H:i:s' ) === $stamp && $stamp >= $earliest && $scan->getTimestamp() <= time() ) {
			// First known physical scan, which may be later than pickup; never substitute label time.
			$package['shippedAt'] = $stamp;
		}
		$date = self::carrier_delivery_time( (string) ( $result['actual_delivery_date'] ?? '' ), (string) ( ( $package['shippedAt'] ?? '' ) ?: $earliest ) );
		if ( $date ) { $package['deliveredAt'] = $date; }
		$estimate = (string) ( $result['expected_delivery_date'] ?? '' );
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $estimate, wp_timezone() );
		if ( $parsed && $parsed->format( 'Y-m-d' ) === $estimate && $estimate >= wp_date( 'Y-m-d' ) && $estimate >= substr( $earliest, 0, 10 ) ) { $package['deliveryDate'] = $estimate; }
		return $package;
	}
	/** Update physical transit and delivery facts confirmed by the carrier; never change Woo status. */
	public static function sync_carrier_delivery( WC_Order $order, callable $lookup, int $budget = 2, bool $manual = false ): int {
		$order = self::root( $order );
		$lock = 'wlp_split_lock_' . $order->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) { return 0; }
		try {
			$order->read_meta_data( true );
			$before = (string) $order->get_meta( '_wlp_delivered_at', true );
			$calls = self::sync_locked_delivery( $order, $lookup, $budget, $manual );
			if ( $calls > 0 && ! $before && $order->get_meta( '_wlp_delivered_at', true ) ) { self::notify_delivery( $order ); }
			return $calls;
		} finally { delete_option( $lock ); }
	}
	private static function sync_locked_delivery( WC_Order $order, callable $lookup, int $budget, bool $manual ): int {
		// Shipment facts are independent of optional review consent and review emails.
		if ( ! WLP_Customer_Portal::wholesale( $order ) || ! $order->has_status( array( 'processing', 'completed' ) ) || 'CAD' !== $order->get_currency() || $budget < 1 ) { return 0; }
		$plan = WLP_Split_Shipments::read( $order ); $calls = 0; $changed = false; $now = time();
		$created = $order->get_date_created();
		$earliest = $created ? $created->date( 'Y-m-d H:i:s' ) : '';
		$cooldown = $manual ? 60 : HOUR_IN_SECONDS;
		if ( ! empty( $plan['packages'] ) ) {
			$summary = WLP_Split_Shipments::summary( $order );
			if ( empty( $summary['valid'] ) || ! empty( $summary['allDelivered'] ) ) { return 0; }
			foreach ( $plan['packages'] as &$package ) {
				if ( $calls >= $budget ) { break; }
				$label = $package['label'] ?? array(); $tracking = trim( (string) ( $label['tracking_number'] ?? '' ) );
				if ( ! empty( $package['deliveredAt'] ) || ! $tracking || ( empty( $label['shipment_id'] ) && ! str_contains( strtolower( (string) ( $label['service_name'] ?? '' ) ), 'canada post' ) ) || $now - (int) ( $package['carrierPolledAt'] ?? 0 ) < $cooldown ) { continue; }
				$package['carrierPolledAt'] = $now; $changed = true; ++$calls;
				try { $package = self::carrier_progress( $package, $lookup( $tracking ), $earliest ); }
				catch ( Throwable $e ) { /* Retry later; a tracking failure never means delivered. */ }
			}
			unset( $package );
			if ( $changed ) { ++$plan['revision']; $order->update_meta_data( WLP_Split_Shipments::META, $plan ); $order->save(); if ( WLP_Split_Shipments::summary( $order )['allDelivered'] ) { $order->update_meta_data( '_wlp_delivered_at', max( array_column( $plan['packages'], 'deliveredAt' ) ) ); $order->save(); } }
		} else {
			$meta = WLP_Order_Logistics::read( $order ); $tracking = trim( (string) ( $meta['tracking_number'] ?: $order->get_meta( '_wlp_wholesale_customer_tracking_number', true ) ) );
			if ( ! $meta['delivered_at'] && $tracking && ( $meta['shipment_id'] || str_contains( strtolower( $meta['service_name'] ), 'canada post' ) ) && $now - (int) $order->get_meta( '_wlp_review_carrier_polled_at', true ) >= $cooldown ) {
				$order->update_meta_data( '_wlp_review_carrier_polled_at', $now ); $order->save(); ++$calls;
				try {
					$progress = self::carrier_progress( array( 'shippedAt' => $meta['shipped_at'], 'deliveredAt' => $meta['delivered_at'] ), $lookup( $tracking ), $earliest );
					foreach ( array( 'shippedAt' => '_wlp_shipped_at', 'deliveredAt' => '_wlp_delivered_at', 'deliveryDate' => '_wlp_expected_delivery_date' ) as $field => $key ) {
						if ( ! empty( $progress[$field] ) ) { $order->update_meta_data( $key, $progress[$field] ); }
					}
					$order->save();
				}
				catch ( Throwable $e ) { /* Retry later. */ }
			}
		}
		return $calls;
	}
	/** Rotate through wholesale orders so the bounded hourly carrier budget cannot starve older parcels. */
	public static function poll_carrier_delivery(): void {
		$lock = 'wlp_review_carrier_poll_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < HOUR_IN_SECONDS ) { return; }
			delete_option( $lock );
			if ( ! add_option( $lock, time(), '', false ) ) { return; }
		}
		try {
			$client = new WLP_Canada_Post_Client(); $remaining = 20; $page = 1;
			$cursor = (int) get_option( 'wlp_carrier_order_cursor', 0 ); $exhausted = false;
			do {
				$orders = wc_get_orders( array( 'limit' => 100, 'page' => $page++, 'orderby' => 'ID', 'order' => 'ASC', 'status' => array( 'processing', 'completed' ), 'meta_query' => array( array( 'key' => '_pg_wholesale_quote_products', 'compare' => 'EXISTS' ) ) ) );
				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order || $order->get_id() <= $cursor ) { continue; }
					try { if ( $order instanceof WC_Order && WLP_Customer_Portal::wholesale( $order ) ) { $remaining -= self::sync_carrier_delivery( $order, static fn( string $tracking ) => $client->get_tracking_estimate( $tracking, true ), $remaining ); self::consider( $order ); } }
					catch ( Throwable $e ) { /* A malformed order cannot block later eligible orders. */ }
					update_option( 'wlp_carrier_order_cursor', $order->get_id(), false );
					if ( $remaining < 1 ) { $exhausted = true; break 2; }
				}
			} while ( count( $orders ) === 100 );
			if ( ! $exhausted ) { update_option( 'wlp_carrier_order_cursor', 0, false ); }
			update_option( 'wlp_review_carrier_last_run', array( 'at' => time(), 'checked' => 20 - $remaining ), false );
		} finally { delete_option( $lock ); }
	}
	/** Final delivery only; a Woo Completed status or a purchased label alone is insufficient. */
	public static function delivered_at( WC_Order $o ): int {
		$o = self::root( $o );
		foreach ( WLP_Split_Shipments::joined_ids( $o ) as $id ) {
			$member = wc_get_order( $id );
			if ( ! $member instanceof WC_Order || ! WLP_Customer_Portal::wholesale( $member ) || ! $member->has_status( array( 'processing', 'completed' ) ) || 'CAD' !== $member->get_currency() || strtolower( $member->get_billing_email() ) !== strtolower( $o->get_billing_email() ) ) { return 0; }
		}
		$plan = WLP_Split_Shipments::read( $o );
		if ( $plan['packages'] ) {
			$summary = WLP_Split_Shipments::summary( $o );
			if ( empty( $summary['valid'] ) || empty( $summary['allDelivered'] ) ) { return 0; }
			$dates = array_column( $plan['packages'], 'deliveredAt' );
		} else { $dates = array( $o->get_meta( '_wlp_delivered_at', true ) ); }
		$latest = 0;
		foreach ( $dates as $date ) {
			if ( ! is_string( $date ) || ! $date ) { return 0; }
			$d = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date, wp_timezone() );
			if ( ! $d || $d->format( 'Y-m-d H:i:s' ) !== $date || $d->getTimestamp() > time() ) { return 0; }
			$latest = max( $latest, $d->getTimestamp() );
		}
		return $latest;
	}
	public static function consider( $order ): void {
		static $busy = false;
		if ( $busy || ! $order instanceof WC_Order || ! WLP_Customer_Portal::wholesale( $order ) ) { return; }
		$busy = true;
		try {
			$o = self::root( $order ); $s = self::state( $o );
			if ( empty( $s['consent'] ) || ! empty( $s['review'] ) || ! empty( $s['mail_state'] ) ) { return; }
			if ( self::customer_has_credit( $o ) ) { wp_clear_scheduled_hook( self::HOOK, array( $o->get_id() ) ); $s['reward_ineligible'] = time(); self::save( $o, $s ); return; }
			$at = self::delivered_at( $o );
			if ( ! $at ) { wp_clear_scheduled_hook( self::HOOK, array( $o->get_id() ) ); if ( isset( $s['due'] ) ) { unset( $s['due'] ); self::save( $o, $s ); } return; }
			$due = ! empty( $s['historical_consent'] ) ? time() : max( $at, (int) $s['consent'] ) + 7 * DAY_IN_SECONDS;
			if ( ( $s['due'] ?? 0 ) !== $due ) { $s['due'] = $due; self::save( $o, $s ); }
			if ( 'yes' === get_option( 'wlp_review_emails_enabled', 'no' ) && ! wp_next_scheduled( self::HOOK, array( $o->get_id() ) ) ) { wp_schedule_single_event( max( time() + 60, $due ), self::HOOK, array( $o->get_id() ) ); }
		} catch ( Throwable $e ) { /* No mail or order changes on malformed fulfillment data. */ }
		finally { $busy = false; }
	}
	/** Owner-attested prior consent: send one invitation only after confirmed final delivery. */
	public static function approve_historical_consent( WC_Order $order ): void {
		$order = self::root( $order ); $state = self::state( $order );
		// A saved unchecked preference remains an opt-out and is never overridden.
		if ( ( array_key_exists( 'consent', $state ) && empty( $state['consent'] ) ) || ! empty( $state['review'] ) || ! empty( $state['mail_state'] ) || ! self::delivered_at( $order ) || ! is_email( $order->get_billing_email() ) ) { return; }
		if ( empty( $state['historical_consent'] ) ) {
			if ( empty( $state['consent'] ) ) { $state['consent'] = time(); }
			$state['historical_consent'] = time(); self::save( $order, $state );
		}
		self::consider( $order );
	}
	public static function invitation( WC_Order $o ): array {
		return array( 'subject' => 'How was your PeptideGo wholesale order?', 'body' => "Hi,\n\nHow was your wholesale experience with order #" . $o->get_order_number() . "? Leave one honest review of your overall order. There is no need to review each product.\n\nChoose your one-time wholesale review reward:\n• Text review: C$50 toward your next wholesale order of at least C$100 before tax.\n• Review with one photo: C$150 total credit toward your next wholesale order of at least C$1,000 before tax.\n\nThe secure review page includes an optional photo upload. The reward is the same for positive or negative feedback. We will email you a one-time credit code after you submit your review. Enter it in the Notes field of your next wholesale quote. Credit expires 30 days after your review and is applied when we confirm your quote.\n\nReview your order:\n" . WLP_Customer_Portal::url() . '#wholesale-review-' . $o->get_id() . "\n\nSign in with your order email. If prompted, verify your email to see guest orders. Please keep addresses, shipping labels and other personal information out of your photo.\n\nThis is your one-time review invitation and reward per wholesale customer. You can turn off review invitations in your wholesale account.\n\nNorth End Tech Inc. operating as PeptideGo\n21 King St W, 5th Floor\nHamilton, ON L8P 4W7" );
	}
	public static function credit_code( WC_Order $o ): string { return 'PGW-' . $o->get_id() . '-' . strtoupper( bin2hex( random_bytes( 4 ) ) ); }
	public static function credit_message( WC_Order $o, array $s ): array {
		return array( 'subject' => 'Your PeptideGo wholesale review credit', 'body' => "Hi,\n\nThank you for reviewing your wholesale order. Your one-time C$" . (int) $s['credit'] . " credit code is " . $s['code'] . ".\n\nOn your next wholesale order, enter this code in the Notes field of the wholesale quote form and use the same email address as your reviewed order. We will verify it and apply the credit before confirming your final quote. It is not an automatic checkout coupon.\n\nUse it by " . wp_date( 'F j, Y', (int) $s['expires'] ) . ". Your next wholesale order must be at least C$" . ( (int) $s['credit'] === 150 ? '1,000' : '100' ) . " before tax. One use only; it cannot be transferred or combined with another wholesale review credit.\n\nPeptideGo Support" );
	}
	public static function send_credit( int $id ): void {
		if ( 'yes' !== get_option( 'wlp_review_credit_emails_enabled', 'no' ) ) { return; }
		$lock = 'wlp_review_lock_' . $id;
		if ( ! add_option( $lock, time(), '', false ) ) { return; }
		try {
			$o = wc_get_order( $id ); if ( ! $o instanceof WC_Order ) { return; }
			$o->read_meta_data( true ); $s = self::state( $o );
			if ( empty( $s['review'] ) || empty( $s['credit'] ) || empty( $s['code'] ) || ! empty( $s['credit_mail_state'] ) || ! is_email( $o->get_billing_email() ) ) { return; }
			$s['credit_mail_state'] = 'sending'; self::save( $o, $s ); $m = self::credit_message( $o, $s );
			$ok = wp_mail( $o->get_billing_email(), $m['subject'], $m['body'], array( 'Content-Type: text/plain; charset=UTF-8', 'From: PeptideGo Wholesale <wholesale@peptidego.ca>', 'Reply-To: support@peptidego.ca' ) );
			$s['credit_mail_state'] = $ok ? 'accepted' : 'failed'; $s['credit_mail_at'] = time(); self::save( $o, $s );
			$o->add_order_note( $ok ? 'Wholesale review credit email accepted by mailer. Inbox delivery not verified.' : 'Wholesale review credit email failed. Check mail logs before retrying.', false, true );
		} catch ( Throwable $e ) { /* Persisted sending state prevents uncertain retries. */ }
		finally { delete_option( $lock ); }
	}
	public static function send_due( int $id ): void {
		if ( 'yes' !== get_option( 'wlp_review_emails_enabled', 'no' ) ) { return; }
		$lock = 'wlp_review_lock_' . $id;
		if ( ! add_option( $lock, time(), '', false ) ) { return; }
		try {
			$o = wc_get_order( $id ); if ( ! $o instanceof WC_Order ) { return; }
			$o->read_meta_data( true ); $s = self::state( $o ); $at = self::delivered_at( $o );
			if ( ! $at || empty( $s['consent'] ) || empty( $s['due'] ) || $s['due'] > time() || ! empty( $s['review'] ) || ! empty( $s['mail_state'] ) || ! is_email( $o->get_billing_email() ) ) { return; }
			if ( self::customer_has_credit( $o ) ) { $s['reward_ineligible'] = time(); self::save( $o, $s ); return; }
			if ( empty( $s['historical_consent'] ) && max( $at, (int) $s['consent'] ) + 7 * DAY_IN_SECONDS > time() ) { self::consider( $o ); return; }
			$s['mail_state'] = 'sending'; self::save( $o, $s ); $m = self::invitation( $o );
			$ok = wp_mail( $o->get_billing_email(), $m['subject'], $m['body'], array( 'Content-Type: text/plain; charset=UTF-8', 'From: PeptideGo Wholesale <wholesale@peptidego.ca>', 'Reply-To: support@peptidego.ca' ) );
			$s['mail_state'] = $ok ? 'accepted' : 'failed'; $s['mail_at'] = time(); self::save( $o, $s );
			$o->add_order_note( $ok ? 'Wholesale review invitation accepted by mailer. Inbox delivery not verified.' : 'Wholesale review invitation failed. Check mail logs before retrying.', false, true );
		} catch ( Throwable $e ) { /* A persisted sending state prevents uncertain retries. */ }
		finally { delete_option( $lock ); }
	}
	private static function form( string $op, int $id, bool $upload = false ): void {
		echo '<form method="post"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . ' action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wlp_review"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="order_id" value="' . $id . '">'; wp_nonce_field( 'wlp_review_' . $op . '_' . $id );
	}
	public static function render_order( WC_Order $o, int $uid ): void {
		try { WLP_Customer_Portal::group( $o, $uid ); $o = self::root( $o ); } catch ( Throwable $e ) { return; }
		$s = self::state( $o );
		echo '<section id="wholesale-review-' . $o->get_id() . '" class="wlp-portal-package wlp-review"><h4>Your wholesale review &amp; credit</h4>';
		if ( ! empty( $s['submission_state'] ) && empty( $s['review'] ) ) { echo '<p>Your review submission needs support review. Please do not submit it again yet.</p></section>'; return; }
		if ( ! empty( $s['review'] ) ) {
			$expired = time() >= (int) $s['expires'];
			echo '<p>Thank you for your review. <strong>C$' . (int) $s['credit'] . '</strong> wholesale credit: ' . ( ! empty( $s['redeemed_order'] ) ? 'applied to quote #' . (int) $s['redeemed_order'] : ( $expired ? 'expired' : 'available until ' . esc_html( wp_date( 'F j, Y', (int) $s['expires'] ) ) ) ) . '.</p>';
			if ( empty( $s['redeemed_order'] ) && ! $expired ) { echo '<p>Your code: <strong>' . esc_html( $s['code'] ?? ( 'Review credit #' . $o->get_id() ) ) . '</strong>. Enter it in the Notes field of your next wholesale quote using the same email address. We will verify and apply it before confirming the quote.</p>'; }
			$photo = (int) get_post_meta( (int) $s['review'], '_wlp_photo', true );
			if ( ! self::has_saved_photo( $photo ) ) {
				echo '<h4>Add a photo to your review</h4><p>Have a photo of your order? Add it here without resubmitting your review. This does not change your existing credit.</p>';
				self::form( 'add_photo', $o->get_id(), true );
				echo '<label>Add one photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label><p>One JPEG, PNG or WebP image, up to 5 MB. Do not include shipping labels, addresses, faces or other personal information.</p><label><input type="checkbox" name="publish_consent" value="1" required> You may add this photo publicly to my review with the reward disclosure.</label><button class="button" type="submit">Add photo to my review</button></form>';
			}
		} else {
			if ( ! empty( $s['reward_ineligible'] ) || self::customer_has_credit( $o ) ) { echo '<p>You have already received your one-time wholesale review reward. Thank you for your feedback.</p>'; }
			else { self::form( 'consent', $o->get_id() ); echo '<label><input type="checkbox" name="consent" value="1" ' . ( ! empty( $s['consent'] ) ? 'checked' : '' ) . '> Email me one review invitation seven days after my final package is delivered. I can earn one wholesale review reward: C$50 for an honest review (next wholesale order at least C$100 before tax) or C$150 total with a photo (next wholesale order at least C$1,000 before tax).</label><button class="button" type="submit">Save review preference</button></form>'; }
			if ( self::delivered_at( $o ) ) {
				echo '<h4>One review of your whole order</h4><p>Tell us about communication, packaging, delivery and your overall experience. Any rating earns the same reward. Credit expires 30 days after submission. Your next wholesale order must be at least C$100 before tax for a text review credit or C$1,000 before tax for a photo review credit. We apply it when we confirm your quote.</p>';
				self::form( 'submit', $o->get_id(), true );
				echo '<label>Overall rating<select name="rating" required><option value="">Choose a rating</option><option value="5">5 - Excellent</option><option value="4">4 - Good</option><option value="3">3 - Okay</option><option value="2">2 - Poor</option><option value="1">1 - Very poor</option></select></label><label>Your review<textarea name="review" minlength="10" maxlength="3000" rows="4" required></textarea></label><label>Public display name<input name="display_name" maxlength="60" required></label><label>Optional photo for C$150 total credit<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label><p>One JPEG, PNG or WebP image, up to 5 MB. Photos may be published with your review. Do not include shipping labels, addresses, faces or other personal information.</p><label><input type="checkbox" name="publish_consent" value="1" required> You may publish my review, display name and optional photo with a reward disclosure. My rating does not affect my credit.</label><button class="button" type="submit">Submit review &amp; earn credit</button></form>';
			} else { echo '<p>Your review form opens after every package is confirmed delivered.</p>'; }
		}
		echo '</section>';
	}
	private static function photo( ?array $file = null ): int {
		$f = $file ?? ( $_FILES['photo'] ?? null );
		if ( ! $f || UPLOAD_ERR_NO_FILE === $f['error'] ) { return 0; }
		if ( UPLOAD_ERR_OK !== $f['error'] || $f['size'] > 5 * 1024 * 1024 || ! is_uploaded_file( $f['tmp_name'] ) ) { throw new RuntimeException( 'Use one image no larger than 5 MB.' ); }
		$size = @getimagesize( $f['tmp_name'] );
		if ( ! $size || ! in_array( $size['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || $size[0] * $size[1] > 24000000 ) { throw new RuntimeException( 'Use a JPEG, PNG or WebP image under 24 megapixels.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
		$editor = wp_get_image_editor( $f['tmp_name'] ); if ( is_wp_error( $editor ) ) { throw new RuntimeException( 'This image could not be processed.' ); }
		$editor->resize( 1600, 1600, false ); $editor->set_quality( 85 );
		$tmp = wp_tempnam( 'wholesale-review.jpg' ); $encoded = $editor->save( $tmp, 'image/jpeg' );
		if ( is_wp_error( $encoded ) ) { @unlink( $tmp ); throw new RuntimeException( 'This image could not be processed.' ); }
		$bytes = file_get_contents( $encoded['path'] ); @unlink( $encoded['path'] ); if ( $tmp !== $encoded['path'] ) { @unlink( $tmp ); }
		if ( ! $bytes ) { throw new RuntimeException( 'This image could not be processed.' ); }
		$upload = wp_upload_bits( 'wholesale-review-' . wp_generate_uuid4() . '.jpg', null, $bytes );
		if ( $upload['error'] ) { throw new RuntimeException( 'Photo upload failed. Please try again.' ); }
		// The resized JPEG is a new file; strip any remaining EXIF/IPTC/XMP data before it is retained or displayed.
		if ( class_exists( 'Imagick' ) ) {
			try { $image = new Imagick( $upload['file'] ); $image->stripImage(); $image->setImageFormat( 'jpeg' ); $image->writeImage( $upload['file'] ); $image->clear(); $image->destroy(); }
			catch ( Throwable $e ) { @unlink( $upload['file'] ); throw new RuntimeException( 'Photo metadata could not be removed.' ); }
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Wholesale review photo', 'post_status' => 'inherit' ), $upload['file'], 0, true );
		if ( is_wp_error( $id ) ) { @unlink( $upload['file'] ); throw new RuntimeException( 'Photo could not be saved.' ); }
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) ); return (int) $id;
	}
	/** Normalize the multi-file upload array and reject empty or malformed batches before public attachment. */
	private static function uploaded_photos(): array {
		$files = $_FILES['photos'] ?? null;
		if ( ! is_array( $files ) || ! is_array( $files['name'] ?? null ) ) { throw new RuntimeException( 'Choose one or more JPEG, PNG or WebP images.' ); }
		$out = array();
		foreach ( array_keys( $files['name'] ) as $key ) {
			$out[] = array( 'name' => $files['name'][ $key ] ?? '', 'type' => $files['type'][ $key ] ?? '', 'tmp_name' => $files['tmp_name'][ $key ] ?? '', 'error' => $files['error'][ $key ] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][ $key ] ?? 0 );
		}
		if ( ! $out || count( $out ) > 5 ) { throw new RuntimeException( 'Choose one to five JPEG, PNG or WebP images.' ); }
		return $out;
	}
	/** A C$150 reward is reserved for an image that WordPress can still resolve. */
	public static function has_saved_photo( int $photo ): bool {
		return $photo > 0 && 'attachment' === get_post_type( $photo ) && wp_attachment_is_image( $photo ) && (bool) wp_get_attachment_url( $photo );
	}
	/** Primary plus explicitly approved additional review photos, without duplicates or broken attachments. */
	public static function photos( int $review ): array {
		$ids = array_merge( array( (int) get_post_meta( $review, '_wlp_photo', true ) ), array_map( 'absint', (array) get_post_meta( $review, '_wlp_photos', true ) ) );
		return array_values( array_filter( array_unique( $ids ), array( self::class, 'has_saved_photo' ) ) );
	}
	/** Store staff-approved public images without altering review consent, reward, or customer communication. */
	public static function staff_photo_upload(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Not permitted.', '', array( 'response' => 403 ) ); }
		$review = absint( $_POST['review_id'] ?? 0 ); check_admin_referer( 'wlp_review_photo_upload_' . $review );
		$post = get_post( $review );
		if ( ! $post instanceof WP_Post || self::TYPE !== $post->post_type || 'publish' !== $post->post_status || '1' !== ( $_POST['publication_approved'] ?? '' ) ) { wp_die( 'Review, publication approval, or photo is unavailable.', '', array( 'response' => 400 ) ); }
		$uploaded = array();
		try {
			$photos = self::photos( $review );
			foreach ( self::uploaded_photos() as $file ) { $photo = self::photo( $file ); if ( ! self::has_saved_photo( $photo ) ) { throw new RuntimeException( 'Choose one or more JPEG, PNG or WebP images.' ); } $uploaded[] = $photo; }
			if ( ! $photos ) { update_post_meta( $review, '_wlp_photo', array_shift( $uploaded ) ); }
			if ( $uploaded ) { update_post_meta( $review, '_wlp_photos', array_values( array_unique( array_merge( self::photos( $review ), $uploaded ) ) ) ); }
			update_post_meta( $review, '_wlp_photo_staff_approved_at', current_time( 'mysql' ) );
			wp_safe_redirect( admin_url( 'post.php?post=' . $review . '&action=edit&wlp_review_photo_added=1' ) ); exit;
		} catch ( Throwable $e ) {
			foreach ( $uploaded as $photo ) { wp_delete_attachment( $photo, true ); }
			wp_die( esc_html( $e instanceof RuntimeException ? $e->getMessage() : 'Photo needs review before another attempt.' ), '', array( 'response' => 400 ) );
		}
	}
	public static function submit( WC_Order $o, int $uid, int $rating, string $text, string $name, int $photo ): void {
		WLP_Customer_Portal::group( $o, $uid ); $o = self::root( $o ); $s = self::state( $o );
		if ( ! self::delivered_at( $o ) || ( ! empty( $s['review'] ) || ! empty( $s['submission_state'] ) ) || self::customer_has_credit( $o ) ) { throw new RuntimeException( 'This order is not eligible for another wholesale review reward.' ); }
		if ( $rating < 1 || $rating > 5 || strlen( $text ) < 10 || strlen( $text ) > 3000 || ! $name || strlen( $name ) > 60 ) { throw new RuntimeException( 'Add a rating, display name and a review of 10 to 3000 characters.' ); }
		$s['submission_state'] = 'saving'; self::save( $o, $s );
		$id = wp_insert_post( array( 'post_type' => self::TYPE, 'post_status' => 'pending', 'post_title' => $name, 'post_content' => $text, 'post_author' => $uid, 'meta_input' => array( '_wlp_rating' => $rating, '_wlp_photo' => $photo, '_wlp_source_order' => $o->get_id(), '_wlp_reward_disclosure' => 'Reviewer received wholesale credit for an honest review.' ) ), true );
		if ( is_wp_error( $id ) ) { unset( $s['submission_state'] ); self::save( $o, $s ); throw new RuntimeException( 'Review could not be saved.' ); }
		$has_photo = self::has_saved_photo( $photo );
		$s['submission_state'] = 'saved'; $s['review'] = (int) $id; $s['credit'] = $has_photo ? 150 : 50; $s['expires'] = time() + 30 * DAY_IN_SECONDS; $s['issued'] = time(); $s['buyer_email'] = strtolower( $o->get_billing_email() ); $s['code'] = self::credit_code( $o ); $o->update_meta_data( '_wlp_review_credit_customer', self::customer_credit_key( $o ) );
		self::save( $o, $s ); wp_clear_scheduled_hook( self::HOOK, array( $o->get_id() ) );
		$o->add_order_note( 'Wholesale experience review received. C$' . $s['credit'] . ' credit issued for 30 days, independent of rating. Code stored in review ledger.', false, true );
		if ( 'yes' === get_option( 'wlp_review_credit_emails_enabled', 'no' ) ) { wp_schedule_single_event( time() + 60, self::CREDIT_MAIL_HOOK, array( $o->get_id() ) ); }
	}
	public static function handle(): void {
		if ( ! is_user_logged_in() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Please sign in first.', '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['order_id'] ?? 0 ); $op = sanitize_key( $_POST['op'] ?? '' ); check_admin_referer( 'wlp_review_' . $op . '_' . $id );
		WLP_Customer_Portal::prepare_notices();
		$lock = 'wlp_review_lock_' . $id;
		if ( ! add_option( $lock, time(), '', false ) ) { wc_add_notice( 'A review request is already processing. Please refresh shortly.', 'error' ); wp_safe_redirect( WLP_Customer_Portal::url() ); exit; }
		$photo = 0;
		try {
			$o = wc_get_order( $id ); if ( ! $o instanceof WC_Order ) { throw new RuntimeException( 'Order unavailable.' ); }
			$uid = get_current_user_id(); WLP_Customer_Portal::group( $o, $uid );
			if ( self::root( $o )->get_id() !== $id ) { throw new RuntimeException( 'Please use the main joined order.' ); }
			$o->read_meta_data( true ); $s = self::state( $o );
			if ( 'consent' === $op ) { $s['consent'] = isset( $_POST['consent'] ) ? ( ! empty( $s['consent'] ) ? $s['consent'] : time() ) : 0; self::save( $o, $s ); if ( ! $s['consent'] ) { wp_clear_scheduled_hook( self::HOOK, array( $id ) ); } wc_add_notice( 'Review preference saved.', 'success' ); }
			elseif ( 'submit' === $op ) {
				if ( ! empty( $s['review'] ) || ! empty( $s['submission_state'] ) || ! self::delivered_at( $o ) || '1' !== ( $_POST['publish_consent'] ?? '' ) ) { throw new RuntimeException( 'Confirm publication permission and check this order is delivered and not already reviewed.' ); }
				$rating = (int) ( $_POST['rating'] ?? 0 ); $text = sanitize_textarea_field( wp_unslash( $_POST['review'] ?? '' ) ); $name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
				if ( $rating < 1 || $rating > 5 || strlen( $text ) < 10 || strlen( $text ) > 3000 || ! $name || strlen( $name ) > 60 ) { throw new RuntimeException( 'Add a rating, display name and a review of 10 to 3000 characters.' ); }
				$photo = self::photo(); self::submit( $o, $uid, $rating, $text, $name, $photo ); $photo = 0; wc_add_notice( 'Thank you. Your wholesale credit is now shown with this order.', 'success' );
			} elseif ( 'add_photo' === $op ) {
				$review = (int) ( $s['review'] ?? 0 );
				if ( ! $review || self::TYPE !== get_post_type( $review ) || (int) get_post_meta( $review, '_wlp_source_order', true ) !== $o->get_id() || self::has_saved_photo( (int) get_post_meta( $review, '_wlp_photo', true ) ) || '1' !== ( $_POST['publish_consent'] ?? '' ) ) { throw new RuntimeException( 'Confirm publication permission and check that this review does not already have a saved photo.' ); }
				$photo = self::photo();
				if ( ! self::has_saved_photo( $photo ) ) { throw new RuntimeException( 'Choose one JPEG, PNG or WebP image to add.' ); }
				update_post_meta( $review, '_wlp_photo', $photo ); $s['photo_added_at'] = time(); self::save( $o, $s ); $photo = 0;
				$o->add_order_note( 'Customer added a photo to their wholesale review. Existing review credit unchanged. No email sent.', false, true ); wc_add_notice( 'Thank you. Your photo was added to your review.', 'success' );
			} else { throw new RuntimeException( 'Unknown action.' ); }
		} catch ( Throwable $e ) { if ( $photo ) { wp_delete_attachment( $photo, true ); } wc_add_notice( $e instanceof RuntimeException ? $e->getMessage() : 'Your request needs support review. Please do not resubmit until checked.', 'error' ); }
		finally { delete_option( $lock ); }
		wp_safe_redirect( WLP_Customer_Portal::url() . '#wholesale-review-' . $id ); exit;
	}
	/** Only supports the current un-taxed quote placeholder with a complete, consistent tax estimate. */
	public static function credit_quote( float $subtotal, float $tax, float $total, float $credit ): array {
		if ( $subtotal < ( $credit === 150.0 ? 1000 : 100 ) || $credit <= 0 || $credit > $subtotal || $tax < 0 || abs( $total - $subtotal - $tax ) > 0.02 ) { throw new RuntimeException( 'The quote amounts or minimum before-tax order value need reconciliation before credit can be applied.' ); }
		$net = round( $subtotal - $credit, 2 ); $new_tax = round( $net * $tax / $subtotal, 2 );
		return array( 'subtotal' => $net, 'tax' => $new_tax, 'total' => round( $net + $new_tax, 2 ) );
	}
	public static function redeem( WC_Order $source, WC_Order $target ): void {
		$s = self::state( $source );
		if ( ! self::delivered_at( $source ) || empty( $s['review'] ) || empty( $s['credit'] ) || ( $s['expires'] ?? 0 ) <= time() || ! empty( $s['redeemed_order'] ) || ! empty( $s['redemption_state'] ) ) { throw new RuntimeException( 'This credit is unavailable or already has an application in progress.' ); }
		if ( $source->get_id() === $target->get_id() || ! WLP_Customer_Portal::wholesale( $target ) || ! $target->has_status( 'pending' ) || 'CAD' !== $target->get_currency() || strtolower( $target->get_billing_email() ) !== ( $s['buyer_email'] ?? '' ) || $target->get_meta( '_wlp_review_credit_source', true ) || $target->get_meta( '_wlp_split_shipments', true ) || $target->get_meta( '_wlp_shipped_at', true ) || count( $target->get_items( 'line_item' ) ) || (float) $target->get_total_tax() !== 0.0 ) { throw new RuntimeException( 'Choose an unpaid CAD wholesale quote for the same buyer, without an existing credit, tax lines or fulfillment.' ); }
		$fees = $target->get_items( 'fee' ); $fee = count( $fees ) === 1 ? reset( $fees ) : null;
		$base = (float) $target->get_meta( '_pg_wholesale_estimated_total', true );
		if ( ! $fee || $fee->get_name() !== 'Wholesale Quote Request' || $fee->get_tax_status() !== 'none' || abs( (float) $fee->get_total() - $base ) > 0.01 || abs( (float) $target->get_total() - $base ) > 0.01 ) { throw new RuntimeException( 'The quote has custom pricing. Reconcile it before applying credit.' ); }
		$details = (string) $target->get_meta( '_pg_wholesale_quote_details', true );
		if ( $details !== $target->get_customer_note() || preg_match( '/Wholesale (credit|discount):|Merchandise subtotal before credit:/i', $details ) ) { throw new RuntimeException( 'The quote notes have existing adjustments or conflicting totals.' ); }
		if ( ! empty( $s['code'] ) && ! preg_match( '/Buyer notes:\s*(?:(?!\n\n).)*\b' . preg_quote( $s['code'], '/' ) . '\b/is', $details ) ) { throw new RuntimeException( 'The buyer must enter the matching review credit code in the wholesale quote Notes field.' ); }
		$money = '([0-9][0-9,]*(?:\\.[0-9]{1,2})?)';
		if ( ! preg_match( '/Estimated subtotal before tax:\s*C?\$?\s*' . $money . '/i', $details, $m ) || abs( (float) str_replace( ',', '', $m[1] ) - $base ) > 0.01 || ! preg_match( '/Estimated (GST\/HST|GST|HST):\s*C?\$?\s*' . $money . '/i', $details, $tm ) || ! preg_match( '/Total including (?:GST\/HST|GST|HST|tax):\s*C?\$?\s*' . $money . '/i', $details, $totalm ) ) { throw new RuntimeException( 'The quote needs a complete saved subtotal, tax and total before applying credit.' ); }
		$calc = self::credit_quote( $base, (float) str_replace( ',', '', $tm[2] ), (float) str_replace( ',', '', $totalm[1] ), (float) $s['credit'] );
		$replace = array( '/Estimated subtotal before tax:\s*C?\$?\s*' . $money . '/i' => 'Estimated subtotal before tax: C$' . number_format( $calc['subtotal'], 2, '.', '' ), '/Estimated (GST\/HST|GST|HST):\s*C?\$?\s*' . $money . '/i' => 'Estimated ' . $tm[1] . ': C$' . number_format( $calc['tax'], 2, '.', '' ), '/Total including (?:GST\/HST|GST|HST|tax):\s*C?\$?\s*' . $money . '/i' => 'Total including tax: C$' . number_format( $calc['total'], 2, '.', '' ) );
		foreach ( $replace as $pattern => $value ) { if ( preg_match_all( $pattern, $details ) !== 1 ) { throw new RuntimeException( 'Duplicate quote totals require manual reconciliation.' ); } $details = preg_replace_callback( $pattern, static fn() => $value, $details ); }
		$details .= "\nMerchandise subtotal before credit: C$" . number_format( $base, 2, '.', '' ) . "\nWholesale credit: -C$" . number_format( $s['credit'], 2, '.', '' ) . "\nReview credit reference: #" . $source->get_id();
		$s['redemption_state'] = 'applying'; $s['redemption_target'] = $target->get_id(); self::save( $source, $s );
		$target->update_meta_data( '_wlp_review_credit_before', array( 'subtotal' => $base, 'details' => $target->get_customer_note(), 'total' => $target->get_total() ) ); $target->save();
		$fee->set_amount( $calc['subtotal'] ); $fee->set_total( $calc['subtotal'] ); $fee->save();
		$target->set_customer_note( $details ); $target->update_meta_data( '_pg_wholesale_quote_details', $details ); $target->update_meta_data( '_pg_wholesale_estimated_total', (string) $calc['subtotal'] );
		$target->update_meta_data( '_wlp_review_credit_source', $source->get_id() ); $target->update_meta_data( '_wlp_review_credit_amount', $s['credit'] ); $target->calculate_totals( false ); $target->save();
		$s['redemption_state'] = 'applied'; $s['redeemed_order'] = $target->get_id(); self::save( $source, $s );
		$target->add_order_note( 'Applied C$' . $s['credit'] . ' wholesale review credit from #' . $source->get_id() . '. Saved quote tax estimate recalculated using its existing effective rate. Review the final quote before payment instructions. No email sent.', false, true );
	}
	public static function redeem_handler(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Not permitted.', '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['source'] ?? 0 ); $target_id = absint( $_POST['target'] ?? 0 ); check_admin_referer( 'wlp_review_credit_' . $id );
		// Serialize both the credit and the destination quote; uncertain applications must be reviewed.
		$lock = 'wlp_review_credit_lock'; if ( ! add_option( $lock, time(), '', false ) ) { wp_die( 'Another credit application is processing. Refresh before retrying.' ); }
		$source_lock = 'wlp_review_lock_' . $id; if ( ! add_option( $source_lock, time(), '', false ) ) { delete_option( $lock ); wp_die( 'This review is processing. Refresh before retrying.' ); }
		try { $source = wc_get_order( $id ); $target = wc_get_order( $target_id ); if ( ! $source instanceof WC_Order || ! $target instanceof WC_Order ) { throw new RuntimeException( 'Order not found.' ); } self::redeem( $source, $target ); }
		catch ( Throwable $e ) { delete_option( $source_lock ); delete_option( $lock ); wp_die( esc_html( $e instanceof RuntimeException ? $e->getMessage() : 'Application needs review. Check both orders before another attempt.' ) ); }
		delete_option( $source_lock ); delete_option( $lock ); wp_safe_redirect( admin_url( 'admin.php?page=wlp-review-rewards' ) ); exit;
	}
	/** Transactional final-delivery receipt; review invitations stay separate. */
	public static function delivery_message( WC_Order $order ): array {
		return array( 'subject' => 'Your wholesale order #' . $order->get_order_number() . ' has been delivered',
			'body' => "Hi,\n\nCanada Post has confirmed delivery of all packages for your wholesale order #" . $order->get_order_number() . ".\n\nView your order and tracking:\nhttps://peptidego.ca/my-account/?wholesale=1\n\nIf you cannot find your package or anything is missing, reply to this email and we will help.\n\nPeptideGo Wholesale" );
	}
	public static function notify_delivery( WC_Order $order ): void {
		$enabled = (int) get_option( 'wlp_delivery_notices_enabled_at', 0 );
		$date = (string) $order->get_meta( '_wlp_delivered_at', true );
		// Newly observed final delivery only. Never bulk-mail historical deliveries on activation.
		if ( ! $enabled || ! WLP_Customer_Portal::wholesale( $order ) || ! $order->has_status( array( 'processing', 'completed' ) ) || 'CAD' !== $order->get_currency() || ! $date || substr( $date, 0, 10 ) < wp_date( 'Y-m-d', $enabled ) || substr( $date, 0, 10 ) > wp_date( 'Y-m-d' ) ) { return; }
		$plan = WLP_Split_Shipments::read( $order );
		if ( $plan['packages'] && ! WLP_Split_Shipments::summary( $order )['allDelivered'] ) { return; }
		$lock = 'wlp_delivery_notice_lock_' . $order->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) { return; }
		try {
			$order->read_meta_data( true );
			if ( $order->get_meta( '_wlp_delivery_notice', true ) || 'delivered_complete' === $order->get_meta( '_wlp_wholesale_followup_stage', true ) ) { return; }
			if ( ! is_email( $order->get_billing_email() ) ) { return; }
			$message = self::delivery_message( $order );
			$order->update_meta_data( '_wlp_delivery_notice', array( 'state' => 'sending', 'at' => current_time( 'mysql' ) ) ); $order->save();
			$accepted = wp_mail( $order->get_billing_email(), $message['subject'], $message['body'], array( 'Content-Type: text/plain; charset=UTF-8', 'From: PeptideGo Wholesale <wholesale@peptidego.ca>', 'Reply-To: wholesale@peptidego.ca' ) );
			$order->update_meta_data( '_wlp_delivery_notice', array( 'state' => $accepted ? 'accepted' : 'failed', 'at' => current_time( 'mysql' ) ) ); $order->save();
			$order->add_order_note( $accepted ? 'Wholesale delivery confirmation accepted by mailer. Inbox delivery not verified.' : 'Wholesale delivery confirmation failed. Check mail logs before retrying.', false, true );
		} catch ( Throwable $e ) {
			// Persisted sending/failed states block ambiguous retries; delivery facts remain saved.
			$order->add_order_note( 'Wholesale delivery confirmation needs mail-log review before retrying.', false, true );
		} finally { delete_option( $lock ); }
	}
	public static function delivery_notice_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Not permitted.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wlp_delivery_notice_settings' );
		$at = isset( $_POST['delivery_enabled'] ) ? ( (int) get_option( 'wlp_delivery_notices_enabled_at', 0 ) ?: time() ) : 0;
		update_option( 'wlp_delivery_notices_enabled_at', $at, false );
		wp_safe_redirect( admin_url( 'admin.php?page=wlp-review-rewards' ) ); exit;
	}

	public static function settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Not permitted.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wlp_review_settings' );
		update_option( 'wlp_review_emails_enabled', isset( $_POST['enabled'] ) ? 'yes' : 'no', false );
		update_option( 'wlp_review_credit_emails_enabled', isset( $_POST['credit_enabled'] ) ? 'yes' : 'no', false );
		$historical = isset( $_POST['historical_consent_campaign'] );
		if ( isset( $_POST['enabled'] ) || $historical ) {
			$page = 1; do {
				$orders = wc_get_orders( array( 'limit' => 100, 'page' => $page++, 'status' => array( 'processing', 'completed' ), 'meta_query' => array( array( 'key' => '_pg_wholesale_quote_products', 'compare' => 'EXISTS' ) ) ) );
				foreach ( $orders as $order ) {
					if ( $historical ) {
						self::approve_historical_consent( $order );
						// Historic consent is owner-attested and is sent in this approved admin action.
						// send_due records the state before handing off to the mailer, preventing duplicates.
						self::send_due( self::root( $order )->get_id() );
					} else { self::consider( $order ); }
				}
			} while ( count( $orders ) === 100 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wlp-review-rewards' ) ); exit;
	}
	public static function admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		echo '<div class="wrap"><h1>Wholesale review rewards</h1><p>One experience review per joined delivery. C$50 text / C$150 with photo. Rewards do not depend on rating or publication.</p><p>Automatic invitations: <strong>' . ( 'yes' === get_option( 'wlp_review_emails_enabled', 'no' ) ? 'Enabled' : 'Disabled pending approval' ) . '</strong>.</p><p>Moderate submissions under WooCommerce → Wholesale reviews. Publish only content suitable for public display; negative feedback is eligible for the same reward.</p><table class="widefat"><thead><tr><th>Order</th><th>Invitation</th><th>Review</th><th>Credit</th></tr></thead><tbody>';
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$orders = wc_get_orders( array( 'limit' => 30, 'page' => $page, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => self::META, 'compare' => 'EXISTS' ) ) ) );
		foreach ( $orders as $o ) {
			$s = self::state( $o );
			$state = ! empty( $s['submission_state'] ) && empty( $s['review'] ) ? 'Submission needs review' : ( $s['mail_state'] ?? ( ! empty( $s['due'] ) ? 'Scheduled for ' . wp_date( 'Y-m-d', $s['due'] ) : ( ! empty( $s['consent'] ) ? 'Waiting for final delivery' : 'No consent' ) ) );
			$credit = 'Not issued';
			if ( ! empty( $s['credit'] ) ) {
				$credit = 'C$' . (int) $s['credit'] . ' · ' . ( ! empty( $s['redeemed_order'] ) ? 'Applied to #' . (int) $s['redeemed_order'] : ( ! empty( $s['redemption_state'] ) ? 'Application needs review' : ( $s['expires'] <= time() ? 'Expired' : 'Available; expires ' . esc_html( wp_date( 'Y-m-d', $s['expires'] ) ) ) ) );
				if ( ! empty( $s['code'] ) ) { $credit .= '<br>Code: ' . esc_html( $s['code'] ) . ' · Email: ' . esc_html( $s['credit_mail_state'] ?? 'held' ); }
			}
			echo '<tr><td><a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . $o->get_id() . '</a></td><td>' . esc_html( $state ) . '</td><td>' . ( ! empty( $s['review'] ) ? '<a href="' . esc_url( get_edit_post_link( $s['review'] ) ) . '">Review submitted</a>' : 'Not submitted' ) . '</td><td>' . $credit . '</td></tr>';
		}
		echo '</tbody></table>';
		$next = wp_next_scheduled( self::CARRIER_HOOK ); $last = get_option( 'wlp_review_carrier_last_run', array() );
		echo '<p>Carrier delivery check: next ' . ( $next ? esc_html( wp_date( 'M j, g:i a', $next ) ) : 'not scheduled' ) . '; last ' . ( ! empty( $last['at'] ) ? esc_html( wp_date( 'M j, g:i a', (int) $last['at'] ) ) . ' (' . (int) ( $last['checked'] ?? 0 ) . ' tracking lookups)' : 'not yet run' ) . '.</p>';
		echo '<h2>Wholesale delivery confirmations</h2><p>One transactional email to the order billing email when Canada Post confirms every package delivered. Historical deliveries before activation are excluded. Failed or uncertain attempts require mail-log review; no blind retries.</p><p>From and replies: wholesale@peptidego.ca. This does not enable review invitations.</p><pre>' . esc_html( "Subject: Your wholesale order #[order number] has been delivered\n\nHi,\n\nCanada Post has confirmed delivery of all packages for your wholesale order #[order number].\n\nView your order and tracking:\nhttps://peptidego.ca/my-account/?wholesale=1\n\nIf you cannot find your package or anything is missing, reply to this email and we will help.\n\nPeptideGo Wholesale" ) . '</pre><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wlp_delivery_notice_settings">';
		wp_nonce_field( 'wlp_delivery_notice_settings' );
		echo '<label><input type="checkbox" name="delivery_enabled" value="1" ' . ( get_option( 'wlp_delivery_notices_enabled_at', 0 ) ? 'checked' : '' ) . '> Enable this delivery confirmation for future carrier-confirmed wholesale deliveries</label><p><button class="button">Save delivery notification setting</button></p></form>';
		echo '<h2>Automatic invitations</h2><p>Buyers who opt in receive one invitation seven days after their final confirmed delivery. One invitation per joined fulfillment.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wlp_review_settings">'; wp_nonce_field( 'wlp_review_settings' ); echo '<p><label><input type="checkbox" name="enabled" value="1" ' . ( 'yes' === get_option( 'wlp_review_emails_enabled', 'no' ) ? 'checked' : '' ) . '> Enable the approved wholesale review invitation</label></p><p><label><input type="checkbox" name="historical_consent_campaign" value="1"> Send one invitation now to past, confirmed-delivered wholesale orders where owner-attested review consent is not yet recorded. Existing opt-outs, invitations and reviews are excluded.</label></p><p><label><input type="checkbox" name="credit_enabled" value="1" ' . ( 'yes' === get_option( 'wlp_review_credit_emails_enabled', 'no' ) ? 'checked' : '' ) . '> Email the approved credit code after a new review</label></p><button class="button">Save email settings</button></form>';
		echo '<h2>Apply an available credit</h2><p>Open the source order above, verify the matching buyer and pending destination quote, then apply once. Custom or conflicting quote totals stop for review.</p>';
		foreach ( $orders as $source ) { $v = self::state( $source ); if ( empty( $v['credit'] ) || ! empty( $v['redemption_state'] ) || $v['expires'] <= time() ) { continue; } echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wlp_review_credit"><input type="hidden" name="source" value="' . $source->get_id() . '">'; wp_nonce_field( 'wlp_review_credit_' . $source->get_id() ); echo '<p>Credit #' . $source->get_id() . ' · C$' . (int) $v['credit'] . ' <label>Apply to unpaid quote # <input type="number" name="target" min="1" required></label> <button class="button" type="submit">Apply credit once</button></p></form>'; }
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => 'wlp-review-rewards', 'paged' => $page + 1 ), admin_url( 'admin.php' ) ) ) . '">Older records</a></p></div>';
	}
	public static function published(): string {
		$posts = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'publish', 'numberposts' => 12 ) ); if ( ! $posts ) { return ''; }
		$html = '<section class="wlp-wholesale-reviews"><h2>Wholesale customer experiences</h2>';
		foreach ( $posts as $p ) { $html .= '<article><h3>' . esc_html( $p->post_title ) . '</h3><p>' . (int) get_post_meta( $p->ID, '_wlp_rating', true ) . ' / 5</p><p>' . nl2br( esc_html( $p->post_content ) ) . '</p>'; $photos = self::photos( (int) $p->ID ); if ( $photos ) { $html .= '<div class="wlp-wholesale-reviews__photos">'; foreach ( $photos as $photo ) { $html .= wp_get_attachment_image( $photo, 'medium', false, array( 'loading' => 'lazy', 'alt' => 'Customer photo of their wholesale order' ) ); } $html .= '</div>'; } $html .= '<small>Reviewer received wholesale credit for an honest review.</small></article>'; }
		return $html . '</section>';
	}
}
