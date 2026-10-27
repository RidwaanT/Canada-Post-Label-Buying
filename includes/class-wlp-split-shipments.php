<?php
/** Package-level fulfillment with shipment notifications after confirmed handoff. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WLP_Split_Shipments {
	public const META = '_wlp_split_shipments';
	public static function root_order( WC_Order $order ): WC_Order {
		$id = (int) $order->get_meta( '_wlp_join_parent', true );
		if ( ! $id ) { return $order; }
		$root = wc_get_order( $id );
		if ( ! $root instanceof WC_Order || ! in_array( $order->get_id(), (array) $root->get_meta( '_wlp_join_children', true ), true ) ) { throw new RuntimeException( 'Joined shipment link needs review.' ); }
		return $root;
	}
	public static function joined_ids( WC_Order $order ): array {
		$root = self::root_order( $order );
		return array_merge( array( $root->get_id() ), array_map( 'intval', (array) ( $root->get_meta( '_wlp_join_children', true ) ?: array() ) ) );
	}
	/** One fulfillment card per linked group; original Woo orders remain unchanged. */
	public static function fulfillment_cards( array $orders ): array {
		$cards = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$root = self::root_order( $order );
			$cards[ $root->get_id() ] = $root;
		}
		return array_values( $cards );
	}
	private static function destination_key( WC_Order $order ): string {
		$s = $order->get_address( 'shipping' ); $values = array( strtolower( trim( $order->get_billing_email() ) ) );
		if ( ! is_email( $values[0] ) ) { return ''; }
		foreach ( array( 'first_name','last_name','company','address_1','address_2','city','state','postcode','country' ) as $key ) {
			$v = strtolower( trim( (string) ( $s[ $key ] ?? '' ) ) );
			if ( ! in_array( $key, array( 'company','address_2' ), true ) && '' === $v ) { return ''; }
			$values[] = $v;
		}
		return hash( 'sha256', implode( "\n", $values ) );
	}
	private static function joinable( WC_Order $order ): bool {
		if ( ! $order->has_status( array( 'processing' ) ) || ! $order->get_meta( '_pg_wholesale_quote_products', true ) || ! self::destination_key( $order ) ) { return false; }
		foreach ( array( '_wlp_join_parent','_wlp_join_children','_wlp_tracking_number','_wlp_label_artifact_url','_wlp_shipped_at','_wlp_delivered_at','_wlp_packed_at','_wlp_wholesale_customer_tracking_number' ) as $key ) { if ( $order->get_meta( $key, true ) ) { return false; } }
		$legacy = WLP_Order_Logistics::read( $order );
		foreach ( array( 'tracking_number', 'label_artifact_url', 'shipment_id', 'packed_at', 'shipped_at', 'delivered_at' ) as $key ) { if ( ! empty( $legacy[ $key ] ) ) { return false; } }
		return empty( self::read( $order )['packages'] );
	}
	public static function matching_orders( WC_Order $order ): array {
		if ( ! self::joinable( $order ) ) { return array(); }
		static $cache = array();
		$email = strtolower( trim( $order->get_billing_email() ) );
		if ( ! isset( $cache[ $email ] ) ) { $cache[ $email ] = wc_get_orders( array( 'status' => 'processing', 'billing_email' => $email, 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) ); }
		$candidates = $cache[ $email ];
		$matches = array(); $key = self::destination_key( $order );
		foreach ( $candidates as $other ) {
			if ( $other->get_id() !== $order->get_id() && self::joinable( $other ) && self::destination_key( $other ) === $key ) { $matches[] = $other->get_id(); }
		}
		return $matches;
	}
	private static function assert_join_destination( WC_Order $root ): void {
		foreach ( array_slice( self::joined_ids( $root ), 1 ) as $id ) {
			$other = wc_get_order( $id );
			if ( ! $other instanceof WC_Order || (int) $other->get_meta( '_wlp_join_parent', true ) !== $root->get_id() || ! self::destination_key( $root ) || self::destination_key( $root ) !== self::destination_key( $other ) ) { throw new RuntimeException( 'Joined orders no longer have matching shipping details. Review before shipping.' ); }
			if ( ! $root->has_status( array( 'processing', 'completed' ) ) || ! $other->has_status( array( 'processing', 'completed' ) ) || $root->get_currency() !== $other->get_currency() ) { throw new RuntimeException( 'A joined order status or currency changed. Review before fulfillment.' ); }
		}
	}
	private static function join_orders( int $first, int $second ): array {
		if ( $first === $second || ! $second ) { throw new RuntimeException( 'Choose two different orders.' ); }
		$ids = array( $first, $second ); sort( $ids ); $locks = array(); $before = array();
		try {
			foreach ( $ids as $id ) { $key = 'wlp_split_lock_' . $id; if ( ! add_option( $key, time(), '', false ) ) { throw new RuntimeException( 'An order is busy. Reopen before retrying.' ); } $locks[] = $key; }
			$root = wc_get_order( $ids[0] ); $child = wc_get_order( $ids[1] );
			foreach ( array( $root, $child ) as $o ) { if ( ! $o instanceof WC_Order ) { throw new RuntimeException( 'Order not found.' ); } $o->read_meta_data( true ); if ( ! self::joinable( $o ) ) { throw new RuntimeException( 'Only matching paid wholesale orders without packing, labels or saved plans can be joined.' ); } }
			if ( self::destination_key( $root ) !== self::destination_key( $child ) || $root->get_currency() !== $child->get_currency() ) { throw new RuntimeException( 'Customer, shipping address or currency differs. Orders were not joined.' ); }
			// Parse both before writing any link; commercial records remain untouched.
			self::own_items( $root ); self::own_items( $child );
			foreach ( array( $root, $child ) as $o ) { foreach ( array( '_wlp_join_parent','_wlp_join_children',self::META ) as $key ) { $before[ $o->get_id() ][ $key ] = $o->get_meta( $key, true ); } }
			$root->update_meta_data( '_wlp_join_children', array( $child->get_id() ) ); $root->save();
			$child->update_meta_data( '_wlp_join_parent', $root->get_id() ); $child->save();
			$catalog = self::items( $root );
			$plan = array( 'revision' => 0, 'catalog' => $catalog, 'packages' => array( array( 'id' => wp_generate_uuid4(), 'items' => array_map( static fn( $i ) => $i['quantity'], $catalog ), 'note' => 'Combined shipment for orders #' . implode( ' and #', $ids ), 'label' => array(), 'dispatchDate' => '', 'deliveryDate' => '', 'shippedAt' => '', 'deliveredAt' => '' ) ) );
			self::validate( $plan['packages'], $catalog ); self::save( $root, $plan );
			foreach ( array( $root, $child ) as $o ) { $o->add_order_note( 'Joined shipment for orders #' . implode( ' and #', $ids ) . '. One package managed on #' . $root->get_id() . '. Payments unchanged. No customer email sent.', false, true ); }
			return self::payload( $root );
		} catch ( Throwable $e ) {
			foreach ( $before as $id => $meta ) { $o = wc_get_order( $id ); foreach ( $meta as $key => $value ) { $o->update_meta_data( $key, $value ); } $o->save(); }
			throw $e;
		} finally { foreach ( $locks as $key ) { delete_option( $key ); } }
	}
	public static function read( WC_Order $order ): array {
		$order = self::root_order( $order );
		$value = $order->get_meta( self::META, true );
		return is_array( $value ) ? $value : array( 'revision' => 0, 'packages' => array() );
	}
	public static function active( WC_Order $order ): bool { return ! empty( self::read( $order )['packages'] ) || count( self::joined_ids( $order ) ) > 1; }
	/** Customer-safe saved-state preview. Never includes internal packing notes or supplier data. */
	public static function email_preview( WC_Order $order ): array {
		$order = self::root_order( $order );
		$data = self::read( $order ); $items = self::items( $order );
		if ( empty( $data['packages'] ) && $order->get_meta( '_wlp_shipped_at', true ) ) {
			$tracking = (string) ( $order->get_meta( '_wlp_tracking_number', true ) ?: $order->get_meta( '_wlp_wholesale_customer_tracking_number', true ) );
			if ( $tracking ) { $data['packages'] = array( array( 'items' => array_map( static fn( $item ) => $item['quantity'], $items ), 'shippedAt' => $order->get_meta( '_wlp_shipped_at', true ), 'label' => array( 'tracking_number' => $tracking, 'service_name' => (string) ( $order->get_meta( '_wlp_service_name', true ) ?: 'Canada Post' ) ) ) ); }
		}
		self::validate( $data['packages'], $items );
		$allocated = array_sum( array_map( static fn( $p ) => array_sum( $p['items'] ), $data['packages'] ) );
		$multiple = count( $data['packages'] ) > 1 || $allocated < array_sum( array_column( $items, 'quantity' ) );
		$lines = array( 'Hi,', '', 'Here is the shipment update for order #' . $order->get_order_number() . '.', ( $multiple ? 'Your order is being handled in separate packages. ' : '' ) . 'Shipping is included, with no additional shipping charge.', '' );
		$joined = self::joined_ids( $order );
		if ( count( $joined ) > 1 ) { $lines = array( 'Hi,', '', 'We have joined your orders #' . implode( ' and #', $joined ) . ' for streamlined processing. ' . ( $multiple ? 'Your items will arrive in separate packages as detailed below. ' : 'Your items are being prepared together in one package. ' ) . 'Your original order records and payments remain unchanged. Shipping is included, with no additional shipping charge.', '' ); }
		$assigned = array();
		foreach ( $data['packages'] as $i => $p ) {
			$status = ! empty( $p['deliveredAt'] ) ? 'Delivered' : ( ! empty( $p['shippedAt'] ) ? 'Shipped' : 'Preparing, not yet shipped' );
			$lines[] = 'PACKAGE ' . ( $i + 1 ) . ': ' . $status;
			foreach ( $p['items'] as $key => $qty ) {
				$lines[] = '- ' . $items[ $key ]['name'] . ': ' . $qty . ' ' . ( $items[ $key ]['unit'] ?? ( str_starts_with( $key, 'quote_' ) ? 'vials' : 'unit(s)' ) );
				$assigned[ $key ] = ( $assigned[ $key ] ?? 0 ) + $qty;
			}
			if ( empty( $p['shippedAt'] ) && empty( $p['deliveredAt'] ) ) { $lines[] = 'Dispatch estimate: ' . ( ( $p['dispatchDate'] ?? '' ) ?: 'to be confirmed' ); }
			if ( empty( $p['deliveredAt'] ) ) { $lines[] = 'Delivery estimate: ' . ( ( $p['deliveryDate'] ?? '' ) ?: ( ( $p['label']['expected_delivery_date'] ?? '' ) ?: 'to be confirmed' ) ); }
			$tracking = $p['label']['tracking_number'] ?? '';
			$lines[] = $tracking ? 'Tracking: ' . ( $p['label']['service_name'] ?? 'Carrier' ) . ' ' . $tracking : 'Tracking: not yet available. We will send it once ready.';
			$service = (string) ( $p['label']['service_name'] ?? '' );
			if ( $tracking && ( ! empty( $p['label']['shipment_id'] ) || preg_match( '/Canada\s*Post|^(Priority|Xpresspost|Expedited Parcel|Regular Parcel)$/i', $service ) ) ) {
				$lines[] = 'Track package: https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $tracking );
			}
			if ( $tracking && empty( $p['shippedAt'] ) && empty( $p['deliveredAt'] ) ) { $lines[] = 'Tracking is ready; carrier handoff has not yet been confirmed.'; }
			$lines[] = '';
		}
		$remaining = array();
		foreach ( $items as $key => $item ) {
			$qty = $item['quantity'] - ( $assigned[ $key ] ?? 0 );
			if ( $qty > 0 ) { $remaining[] = '- ' . $item['name'] . ': ' . $qty . ' ' . ( $item['unit'] ?? ( str_starts_with( $key, 'quote_' ) ? 'vials' : 'unit(s)' ) ); }
		}
		if ( $remaining ) { $lines = array_merge( $lines, array( 'COMING LATER: not yet assigned to a package' ), $remaining, array( 'Dispatch and delivery estimates: to be confirmed.', '' ) ); }
		$lines[] = ( $multiple ? 'Each package travels separately and may arrive on a different day. ' : '' ) . 'Delivery dates are estimates, subject to the carrier.';
		$lines[] = ''; $lines[] = 'View your order and package tracking: https://peptidego.ca/my-account/?wholesale=1';
		$lines[] = 'Sign in with your order email. If prompted, verify your email to see guest orders.';
		$lines[] = ''; $lines[] = 'Thank you for your patience.'; $lines[] = 'PeptideGo Support';
		$preview = array( 'subject' => 'Shipment update for order #' . $order->get_order_number(), 'body' => implode( "\n", $lines ), 'recipient' => sanitize_email( $order->get_billing_email() ), 'from' => 'PeptideGo Wholesale <wholesale@peptidego.ca>' );
		if ( count( $joined ) > 1 ) { $preview['subject'] = 'Shipment update for orders #' . implode( ' and #', $joined ); }
		$preview['token'] = hash( 'sha256', implode( "\n", $preview ) );
		$history = (array) $order->get_meta( '_wlp_split_email_history', true );
		$preview['sendState'] = $history[ $preview['token'] ]['state'] ?? '';
		$preview['sentAt'] = $history[ $preview['token'] ]['at'] ?? '';
		return $preview;
	}
	/** Explicit, preview-bound send. The shared order lock prevents concurrent duplicates. */
	private static function send_update( WC_Order $order ): array {
		if ( ! self::active( $order ) ) { throw new RuntimeException( 'Save a split plan before sending an update.' ); }
		$preview = self::email_preview( $order );
		if ( ! is_email( $preview['recipient'] ) ) { throw new RuntimeException( 'Add a valid customer email to this order first.' ); }
		if ( ! hash_equals( $preview['token'], (string) ( $_POST['previewToken'] ?? '' ) ) ) { throw new RuntimeException( 'The recipient or shipment update changed. Reopen and review the current preview before sending.' ); }
		return self::deliver_update( $order, $preview );
	}
	private static function deliver_update( WC_Order $order, array $preview ): array {
		$history = (array) $order->get_meta( '_wlp_split_email_history', true );
		if ( isset( $history[ $preview['token'] ] ) ) {
			if ( 'sent' === $history[ $preview['token'] ]['state'] ) { return self::payload( $order ); }
			throw new RuntimeException( 'A send was already attempted for this update. Check the mail log before retrying to avoid a duplicate.' );
		}
		$history[ $preview['token'] ] = array( 'state' => 'sending', 'at' => current_time( 'mysql' ) );
		$order->update_meta_data( '_wlp_split_email_history', $history );
		$order->save();
		$accepted = wp_mail( $preview['recipient'], $preview['subject'], self::email_html( $preview['body'] ), array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $preview['from'], 'Reply-To: ' . $preview['from'] ) );
		$history[ $preview['token'] ] = array( 'state' => $accepted ? 'sent' : 'failed', 'at' => current_time( 'mysql' ) );
		$order->update_meta_data( '_wlp_split_email_history', $history );
		$order->save();
		$order->add_order_note( $accepted ? 'Split shipment update accepted by mailer. Delivery is not yet verified.' : 'Split shipment update mailer failed. Review mail logs before another attempt.', false, true );
		if ( ! $accepted ) { throw new RuntimeException( 'The mailer did not accept the update. Check the mail log before retrying.' ); }
		return self::payload( $order );
	}
	/** Only saved, confirmed shipment events trigger mail. No retries after an uncertain handoff. */
	public static function notify_shipped( WC_Order $order ): array {
		$order = self::root_order( $order );
		if ( ! $order->get_meta( '_pg_wholesale_quote_products', true ) || ! $order->has_status( array( 'processing', 'completed' ) ) ) { return array( 'state' => 'skipped', 'message' => '' ); }
		$lock = 'wlp_shipment_notice_lock_' . $order->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) { return array( 'state' => 'review', 'message' => 'Shipment saved. Another email attempt is running; check notification history.' ); }
		try {
			$order->read_meta_data( true );
			$events = array();
			$packages = self::read( $order )['packages'];
			foreach ( $packages as $p ) {
				if ( ! empty( $p['shippedAt'] ) && ! empty( $p['label']['tracking_number'] ) ) { $events[] = hash( 'sha256', $p['id'] . '|' . $p['shippedAt'] . '|' . $p['label']['tracking_number'] ); }
			}
			if ( ! $packages && $order->get_meta( '_wlp_shipped_at', true ) ) {
				$tracking = (string) ( $order->get_meta( '_wlp_tracking_number', true ) ?: $order->get_meta( '_wlp_wholesale_customer_tracking_number', true ) );
				if ( $tracking ) { $events[] = hash( 'sha256', 'order|' . $order->get_meta( '_wlp_shipped_at', true ) . '|' . $tracking ); }
			}
			if ( ! $events ) { return array( 'state' => 'skipped', 'message' => 'No confirmed shipment with tracking to notify.' ); }
			$history = (array) $order->get_meta( '_wlp_shipment_notice_events', true );
			$pending = array_values( array_filter( $events, static fn( $event ) => ! isset( $history[$event] ) ) );
			foreach ( $events as $event ) {
				if ( isset( $history[$event] ) && 'sent' !== $history[$event]['state'] ) { return array( 'state' => 'review', 'message' => 'Shipment saved, but a previous email attempt needs review. Check the mail log before retrying.' ); }
			}
			if ( ! $pending ) { return array( 'state' => 'sent', 'message' => 'Shipment update already accepted by mailer. No duplicate sent.' ); }
			$preview = self::email_preview( $order );
			if ( ! is_email( $preview['recipient'] ) ) { throw new RuntimeException( 'Customer email is missing or invalid.' ); }
			foreach ( $pending as $event ) { $history[$event] = array( 'state' => 'sending', 'at' => current_time( 'mysql' ) ); }
			$order->update_meta_data( '_wlp_shipment_notice_events', $history ); $order->save();
			self::deliver_update( $order, $preview );
			foreach ( $pending as $event ) { $history[$event] = array( 'state' => 'sent', 'at' => current_time( 'mysql' ) ); }
			$order->update_meta_data( '_wlp_shipment_notice_events', $history ); $order->save();
			return array( 'state' => 'sent', 'message' => 'Shipment saved and tracking update accepted by mailer. Inbox delivery is not yet verified.' );
		} catch ( Throwable $e ) {
			$order->add_order_note( 'Automatic shipment email needs review. Shipment remains saved; check mail logs before retrying.', false, true );
			return array( 'state' => 'review', 'message' => 'Shipment saved, but the tracking email needs review. Check the mail log before retrying.' );
		} finally { delete_option( $lock ); }
	}
	/** Escape customer content before adding only our generated carrier links. */
	public static function email_html( string $body ): string {
		$safe = htmlspecialchars( $body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$safe = preg_replace( '!https://www\.canadapost-postescanada\.ca/track-reperage/en\#/search\?searchFor=[A-Za-z0-9%._~-]+!', '<a href="$0">$0</a>', $safe );
		$safe = str_replace( 'https://peptidego.ca/my-account/?wholesale=1', '<a href="https://peptidego.ca/my-account/?wholesale=1">View your order and tracking</a>', $safe );
		return '<div style="font-family:Arial,sans-serif;font-size:16px;line-height:1.5;overflow-wrap:anywhere">' . nl2br( $safe ) . '</div>';
	}
	private static function schedule_input( string $prefix = '' ): array {
		$result = array();
		foreach ( array( 'dispatchDate', 'deliveryDate' ) as $field ) {
			$value = sanitize_text_field( wp_unslash( $_POST[ $prefix . $field ] ?? '' ) );
			$date = $value ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value ) : false;
			if ( $value && ( ! $date || $date->format( 'Y-m-d' ) !== $value ) ) { throw new RuntimeException( 'Use a valid date or leave the estimate blank.' ); }
			$result[ $field ] = $value;
		}
		if ( $result['dispatchDate'] && $result['deliveryDate'] && $result['deliveryDate'] < $result['dispatchDate'] ) { throw new RuntimeException( 'Delivery estimate cannot be before dispatch.' ); }
		return $result;
	}
	public static function register(): void {
		add_action( 'wp_ajax_wlp_split', array( self::class, 'ajax' ) );
		add_action( 'admin_post_wlp_split_print', array( self::class, 'print_package' ) );
	}
	/** Native lines are authoritative; metadata-only wholesale quotes use the saved product list. */
	public static function items( WC_Order $order ): array {
		$order = self::root_order( $order );
		$items = self::own_items( $order );
		foreach ( array_slice( self::joined_ids( $order ), 1 ) as $id ) {
			$other = wc_get_order( $id );
			if ( ! $other instanceof WC_Order ) { throw new RuntimeException( 'Joined order missing.' ); }
			foreach ( self::own_items( $other ) as $key => $item ) { $item['name'] .= ' (order #' . $id . ')'; $items[ ( str_starts_with( $key, 'quote_' ) ? 'quote_' : 'item_' ) . 'joined_' . $id . '_' . $key ] = $item; }
		}
		return $items;
	}
	private static function own_items( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || $item->get_quantity() <= 0 ) { continue; }
			$qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $id );
			if ( $qty > 0 ) { $items[ 'item_' . $id ] = array( 'name' => $item->get_name(), 'quantity' => $qty, 'unit' => preg_match( '/\b(?:bac|bacteriostatic)\s+water\b/i', $item->get_name() ) ? 'bottles' : 'vials' ); }
		}
		$native = $items;
		$products = trim( (string) $order->get_meta( '_pg_wholesale_quote_products', true ) );
		if ( '' === $products ) { return $native; }
		$items = array();
		foreach ( array_filter( array_map( 'trim', explode( ';', $products ) ) ) as $line ) {
			if ( ! preg_match( '/^(.+?)\s*-\s*([0-9][0-9,]*)\s+(?:complimentary\s+)?(vials?|bottles?)(?:\s+complimentary)?$/i', $line, $m ) ) {
				throw new RuntimeException( 'The saved quote product list needs review before splitting.' );
			}
			$key = 'quote_' . substr( hash( 'sha256', $m[1] ), 0, 20 );
			if ( isset( $items[ $key ] ) ) { throw new RuntimeException( 'Duplicate quote products need review before splitting.' ); }
			$items[ $key ] = array( 'name' => sanitize_text_field( $m[1] ), 'quantity' => (int) str_replace( ',', '', $m[2] ), 'unit' => str_starts_with( strtolower( $m[3] ), 'bottle' ) ? 'bottles' : 'vials' );
		}
		foreach ( $native as $key => $line ) {
			$matched = false;
			foreach ( $items as $quoted ) {
				if ( strtolower( preg_replace( '/[^a-z0-9]/i', '', $line['name'] ) ) === strtolower( preg_replace( '/[^a-z0-9]/i', '', $quoted['name'] ) ) ) {
					if ( $line['quantity'] !== $quoted['quantity'] ) { throw new RuntimeException( 'Quote and order quantities disagree. Reconcile them before splitting.' ); }
					$matched = true; break;
				}
			}
			if ( ! $matched ) { $items[ $key ] = $line; }
		}
		return $items;
	}
	public static function summary( WC_Order $order ): array {
		$data = self::read( $order );
		$valid = true;
		try { $items = self::items( $order ); }
		catch ( Throwable $e ) { $items = $data['catalog'] ?? array(); $valid = false; }
		$total = array_sum( array_column( $items, 'quantity' ) );
		$shipped = 0; $delivered = 0; $assigned = array();
		foreach ( $data['packages'] as $p ) {
			foreach ( $p['items'] as $key => $qty ) {
				$assigned[ $key ] = ( $assigned[ $key ] ?? 0 ) + $qty;
				if ( ! empty( $p['shippedAt'] ) || ! empty( $p['deliveredAt'] ) ) { $shipped += $qty; }
				if ( ! empty( $p['deliveredAt'] ) ) { $delivered += $qty; }
			}
		}
		foreach ( $assigned as $key => $qty ) { if ( ! isset( $items[ $key ] ) || $qty > $items[ $key ]['quantity'] ) { $valid = false; } }
		return array( 'total' => $total, 'shipped' => $shipped, 'remaining' => max( 0, $total - $shipped ), 'allShipped' => $valid && $total > 0 && $shipped === $total, 'allDelivered' => $valid && $total > 0 && $delivered === $total, 'valid' => $valid, 'assigned' => $assigned );
	}
	/** Validate the complete allocation against current quantities, before any write. */
	public static function validate( array $packages, array $items ): void {
		$used = array();
		foreach ( $packages as $p ) {
			if ( empty( $p['items'] ) ) { throw new RuntimeException( 'Select at least one item for each package.' ); }
			foreach ( $p['items'] as $key => $qty ) {
				if ( ! isset( $items[ $key ] ) || ! is_int( $qty ) || $qty < 1 ) { throw new RuntimeException( 'Use positive whole quantities from this order.' ); }
				$used[ $key ] = ( $used[ $key ] ?? 0 ) + $qty;
				if ( $used[ $key ] > $items[ $key ]['quantity'] ) { throw new RuntimeException( 'Package quantities exceed the order. Reduce the allocation first.' ); }
			}
		}
	}
	private static function save( WC_Order $order, array &$data ): void {
		++$data['revision'];
		$order->update_meta_data( self::META, $data );
		$order->save();
	}
	public static function payload( WC_Order $order ): array {
		$order = self::root_order( $order );
		$data = self::read( $order );
		try { $data['items'] = self::items( $order ); }
		catch ( Throwable $e ) { if ( empty( $data['catalog'] ) ) { throw $e; } $data['items'] = $data['catalog']; }
		$data['summary'] = self::summary( $order );
		$data['orderId'] = $order->get_id();
		$data['today'] = substr( current_time( 'mysql' ), 0, 10 );
		$data['manifestsUrl'] = admin_url( 'admin.php?page=wlp-contract-manifests' );
		$data['invoiceUrl'] = html_entity_decode( wp_nonce_url( admin_url( 'admin-post.php?action=wlp_print_invoice&order_id=' . $order->get_id() ), 'wlp_print_invoice_' . $order->get_id() ), ENT_QUOTES, 'UTF-8' );
		$data['joinedOrders'] = self::joined_ids( $order );
		foreach ( (array) $order->get_meta( '_wlp_shipment_notice_events', true ) as $notice ) {
			if ( 'sent' !== ( $notice['state'] ?? '' ) ) { $data['shipmentNotification'] = array( 'state' => 'review', 'message' => 'A shipment email needs review. Check the mail log before retrying.' ); break; }
		}
		$shipping = $order->get_address( 'shipping' );
		$name = trim( ( $shipping['first_name'] ?? '' ) . ' ' . ( $shipping['last_name'] ?? '' ) );
		$missing = array();
		if ( ! $name ) { $missing[] = 'recipient name'; }
		foreach ( array( 'address_1' => 'street address', 'city' => 'city', 'state' => 'province/state', 'postcode' => 'postal code', 'country' => 'country' ) as $key => $label ) {
			if ( ! trim( (string) ( $shipping[ $key ] ?? '' ) ) ) { $missing[] = $label; }
		}
		$data['shipTo'] = array( 'name' => $name, 'lines' => array_values( array_filter( array(
			$shipping['company'] ?? '', $shipping['address_1'] ?? '', $shipping['address_2'] ?? '',
			trim( ( $shipping['city'] ?? '' ) . ', ' . ( $shipping['state'] ?? '' ) . ' ' . ( $shipping['postcode'] ?? '' ), ', ' ),
			$shipping['country'] ?? '',
		), static fn( $line ) => '' !== trim( (string) $line ) ) ), 'missing' => $missing );
		$data['emailPreview'] = $data['summary']['valid'] ? self::email_preview( $order ) : null;
		foreach ( $data['packages'] as &$p ) {
			$p['printUrl'] = self::print_url( $order, $p['id'], 'label' );
			$p['packingUrl'] = self::print_url( $order, $p['id'], 'packing' );
			unset( $p['label']['label_artifact_url'] );
		}
		unset( $p );
		return $data;
	}
	public static function ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		check_ajax_referer( 'wlp_logistics', 'nonce' );
		$id = absint( $_POST['orderId'] ?? 0 );
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) { wp_send_json_error( array( 'message' => 'Order not found.' ), 404 ); }
		$op = sanitize_key( $_POST['operation'] ?? 'read' );
		if ( 'join' === $op ) {
			try { $joined = self::join_orders( $id, absint( $_POST['otherOrderId'] ?? 0 ) ); }
			catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 409 ); return; }
			wp_send_json_success( $joined ); return;
		}
		try { $order = self::root_order( $order ); self::assert_join_destination( $order ); $id = $order->get_id(); }
		catch ( Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 409 ); return; }
		$lock = 'wlp_split_lock_' . $id;
		$locked = false; $error = null;
		try {
			if ( 'read' === $op ) { $result = self::payload( $order ); }
			elseif ( 'sync_delivery' === $op ) {
				$client = new WLP_Canada_Post_Client();
				WLP_Wholesale_Reviews::sync_carrier_delivery( $order, static fn( string $tracking ) => $client->get_tracking_estimate( $tracking, true ), 5, true );
				$result = self::payload( $order );
			}
			elseif ( 'estimate' === $op ) {
				// Read-only: works before a split plan exists; never buys or saves anything.
				$shipping = $order->get_address( 'shipping' );
				foreach ( array( 'address_1', 'city', 'state', 'postcode', 'country' ) as $field ) {
					if ( ! trim( (string) ( $shipping[ $field ] ?? '' ) ) ) { throw new RuntimeException( 'Estimate unavailable: complete the saved shipping address first.' ); }
				}
				$schedule = self::schedule_input();
				$dispatch = $schedule['dispatchDate'];
				if ( ! $dispatch || $dispatch < substr( current_time( 'mysql' ), 0, 10 ) ) { throw new RuntimeException( 'Choose today or a future dispatch date.' ); }
				$preset = array( 'wlp_measured_weight' => true, 'expected_mailing_date' => $dispatch );
				foreach ( array( 'weight' => 30, 'length' => 200, 'width' => 200, 'height' => 200 ) as $field => $max ) {
					$n = (float) ( $_POST[ $field ] ?? 0 );
					if ( ! is_finite( $n ) || $n <= 0 || $n > $max ) { throw new RuntimeException( 'Estimate unavailable: check the box weight and dimensions.' ); }
					$preset[ $field ] = $n;
				}
				$service = sanitize_text_field( $_POST['service'] ?? WLP_Settings::wholesale_service_code( $order ) );
				if ( ! in_array( $service, array( 'DOM.PC', 'DOM.XP' ), true ) ) { throw new RuntimeException( 'Choose Priority or Xpresspost.' ); }
				$rates = ( new WLP_Canada_Post_Client() )->get_rates( $order, $preset, 'yes' === ( $_POST['signature'] ?? 'yes' ) );
				$rate = null;
				foreach ( $rates as $candidate ) { if ( $candidate['service_code'] === $service ) { $rate = $candidate; break; } }
				if ( ! $rate || empty( $rate['expected_delivery_date'] ) ) { throw new RuntimeException( 'Estimate unavailable for this service and dispatch date.' ); }
				$result = array( 'deliveryDate' => $rate['expected_delivery_date'], 'service' => $service, 'due' => $rate['due'] );
			}
			elseif ( 'send_update' === $op ) {
				$locked = add_option( $lock, time(), '', false );
				if ( ! $locked ) { throw new RuntimeException( 'Another package action is running. Refresh before sending.' ); }
				$order = wc_get_order( $id );
				$order->read_meta_data( true );
				$result = self::send_update( $order );
			}
			else {
				if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) { throw new RuntimeException( 'Only paid Processing or Completed orders can be split.' ); }
				$locked = add_option( $lock, time(), '', false );
				if ( ! $locked ) { throw new RuntimeException( 'Another package action is running. Wait and refresh; contact support if this persists.' ); }
				$order = wc_get_order( $id );
				$order->read_meta_data( true );
				if ( self::root_order( $order )->get_id() !== $id ) { throw new RuntimeException( 'This order was just joined. Reopen the shared shipment before continuing.' ); }
				self::assert_join_destination( $order );
				$data = self::read( $order );
				if ( (int) ( $_POST['revision'] ?? -1 ) !== $data['revision'] ) { throw new RuntimeException( 'This order changed in another window. Reopen Split shipment and try again.' ); }
				$items = self::items( $order );
				$data['catalog'] = $items;
				if ( ! $items ) { throw new RuntimeException( 'No shippable items found. Review the order first.' ); }
				$pid = sanitize_key( $_POST['packageId'] ?? '' );
				$index = array_search( $pid, array_column( $data['packages'], 'id' ), true );
				if ( 'save' === $op ) {
					if ( ! self::active( $order ) ) {
						$meta = WLP_Order_Logistics::read( $order );
						if ( $meta['tracking_number'] || $meta['label_artifact_url'] || $meta['shipped_at'] || $meta['delivered_at'] || $order->get_meta( '_wlp_wholesale_customer_tracking_number', true ) ) {
							throw new RuntimeException( 'This order already has shipment records. Review them before converting to split fulfillment.' );
						}
					}
					if ( false !== $index && ( ! empty( $data['packages'][ $index ]['label'] ) || ! empty( $data['packages'][ $index ]['purchaseAttempt'] ) || ! empty( $data['packages'][ $index ]['shippedAt'] ) ) ) { throw new RuntimeException( 'A labelled or shipped package cannot be reallocated.' ); }
					$input = json_decode( wp_unslash( $_POST['items'] ?? '{}' ), true );
					if ( ! is_array( $input ) ) { throw new RuntimeException( 'Invalid quantities.' ); }
					$selected = array();
					foreach ( $input as $key => $qty ) {
						if ( ! is_numeric( $qty ) || (float) $qty !== (float) (int) $qty || $qty < 0 ) { throw new RuntimeException( 'Quantities must be whole numbers, zero or greater.' ); }
						if ( (int) $qty > 0 ) { $selected[ $key ] = (int) $qty; }
					}
					$p = array_merge( array( 'id' => false === $index ? wp_generate_uuid4() : $pid, 'items' => $selected, 'note' => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 'label' => array(), 'shippedAt' => '', 'deliveredAt' => '' ), self::schedule_input() );
					$auto_remainder = 'yes' === ( $_POST['splitRemainder'] ?? '' );
					if ( $auto_remainder && false === $index && ! empty( $data['packages'] ) ) { throw new RuntimeException( 'Choose an existing draft package to split.' ); }
					$split_source = false === $index ? array_map( static fn( $item ) => $item['quantity'], $items ) : $data['packages'][ $index ]['items'];
					if ( $auto_remainder ) {
						foreach ( $selected as $key => $qty ) { if ( $qty > ( $split_source[ $key ] ?? 0 ) ) { throw new RuntimeException( 'A split can only use items from the selected package.' ); } }
					}
					if ( false === $index ) { $data['packages'][] = $p; } else { $data['packages'][ $index ] = $p; }
					self::validate( $data['packages'], $items );
					if ( $auto_remainder ) {
						$rest = array();
						foreach ( $split_source as $key => $source_qty ) { $qty = $source_qty - ( $selected[ $key ] ?? 0 ); if ( $qty > 0 ) { $rest[ $key ] = $qty; } }
						if ( $rest ) { $data['packages'][] = array_merge( array( 'id' => wp_generate_uuid4(), 'items' => $rest, 'note' => '', 'label' => array(), 'shippedAt' => '', 'deliveredAt' => '' ), self::schedule_input( 'later_' ) ); }
						self::validate( $data['packages'], $items );
					}
					self::save( $order, $data );
				} else {
					if ( false === $index ) { throw new RuntimeException( 'Package not found.' ); }
					self::validate( $data['packages'], $items );
					$p =& $data['packages'][ $index ];
					if ( 'remove' === $op ) {
						if ( ! empty( $p['label'] ) || ! empty( $p['purchaseAttempt'] ) || ! empty( $p['shippedAt'] ) ) { throw new RuntimeException( 'Only an unlabelled draft package can be removed.' ); }
						array_splice( $data['packages'], $index, 1 );
					} elseif ( 'schedule' === $op ) {
						$p = array_merge( $p, self::schedule_input() );
					} elseif ( 'tracking' === $op ) {
						if ( ! empty( $p['label'] ) || ! empty( $p['shippedAt'] ) ) { throw new RuntimeException( 'Tracking is already saved for this package.' ); }
						$tracking = sanitize_text_field( wp_unslash( $_POST['tracking'] ?? '' ) );
						if ( ! preg_match( '/^[A-Za-z0-9 -]{6,40}$/', $tracking ) ) { throw new RuntimeException( 'Enter a valid carrier tracking number.' ); }
						foreach ( $data['packages'] as $other ) { if ( ( $other['label']['tracking_number'] ?? '' ) === $tracking ) { throw new RuntimeException( 'This tracking is already assigned to a package.' ); } }
						$p['label'] = array( 'tracking_number' => $tracking, 'service_name' => sanitize_text_field( wp_unslash( $_POST['carrier'] ?? 'Canada Post' ) ) );
					} elseif ( 'shipped' === $op ) {
						WLP_Contract_Shipping::assert_order_ready($order, 'split:' . $p['id']);
						WLP_Contract_Shipping::assert_transmitted( (string) ( $p['label']['shipment_id'] ?? '' ) );
						if ( empty( $p['label']['tracking_number'] ) ) { throw new RuntimeException( 'Create a label or save tracking first.' ); }
						if ( empty( $p['shippedAt'] ) ) { $p['shippedAt'] = current_time( 'mysql' ); }
					} elseif ( 'delivered' === $op ) {
						if ( empty( $p['shippedAt'] ) ) { throw new RuntimeException( 'Confirm carrier handoff first.' ); }
						if ( empty( $p['deliveredAt'] ) ) { $p['deliveredAt'] = current_time( 'mysql' ); }
					} elseif ( 'rates' === $op || 'buy' === $op ) {
						if ( ! empty( $p['label'] ) || ! empty( $p['purchaseAttempt'] ) ) { throw new RuntimeException( 'A label exists or purchase needs review. Check Canada Post before any new purchase; save existing tracking to recover.' ); }
						$preset = array( 'id' => 'split-package', 'name' => 'Split package', 'wlp_measured_weight' => true );
						$schedule = self::schedule_input();
						$dispatch = $schedule['dispatchDate'];
						if ( ! $dispatch || $dispatch < substr( current_time( 'mysql' ), 0, 10 ) ) { throw new RuntimeException( 'Choose today or a future dispatch date before getting rates.' ); }
						$preset['expected_mailing_date'] = $dispatch;
						$preset['_wlp_package_id'] = 'split:' . $p['id'];
						foreach ( array( 'weight' => 30, 'length' => 200, 'width' => 200, 'height' => 200 ) as $field => $max ) {
							$n = (float) ( $_POST[ $field ] ?? 0 );
							if ( ! is_finite( $n ) || $n <= 0 || $n > $max ) { throw new RuntimeException( 'Enter the measured package weight (kg) and dimensions (cm).' ); }
							$preset[ $field ] = $n;
						}
						$signature = 'yes' === ( $_POST['signature'] ?? '' );
						$pickup = 'yes' === ( $_POST['pickup'] ?? '' );
						$client = new WLP_Canada_Post_Client();
						$rates = $client->get_rates( $order, $preset, $signature );
						if ( 'rates' === $op ) { $result = self::payload( $order ); $result['rates'] = $rates; }
						else {
							$service = sanitize_text_field( $_POST['service'] ?? '' );
							$rate = null;
							foreach ( $rates as $r ) { if ( ( $r['service_code'] ?? '' ) === $service ) { $rate = $r; break; } }
							if ( ! $rate || abs( (float) $rate['due'] - (float) ( $_POST['quotedCost'] ?? -1 ) ) > 0.001 ) { throw new RuntimeException( 'The rate changed. Get rates again and review the price.' ); }
							if ( (string) ( $rate['expected_delivery_date'] ?? '' ) !== (string) ( $_POST['quotedDelivery'] ?? '' ) ) { throw new RuntimeException( 'The carrier delivery estimate changed. Get rates again and review it.' ); }
							$p['dispatchDate'] = $dispatch;
							$p['deliveryDate'] = (string) ( $rate['expected_delivery_date'] ?? '' );
							$p['purchaseAttempt'] = current_time( 'mysql' );
							self::save( $order, $data ); // Persist before calling the carrier; never automatically retry an uncertain purchase.
							try {
								$p['label'] = $client->create_shipment( $order, $preset, $service, $signature, $pickup );
							} catch ( Throwable $e ) {
								if ( WLP_Contract_Shipping::can_retry_package($order, $preset['_wlp_package_id']) ) { unset($p['purchaseAttempt']); self::save($order, $data); }
								throw $e;
							}
							$p['label']['service_name'] = $client->service_name( $service );
							$p['label']['shipping_cost'] = $rate['due'];
							$p['label']['expected_delivery_date'] = $rate['expected_delivery_date'] ?? '';
							$p['package'] = $preset;
							$p['label']['service_code'] = $service;
						}
					} else { throw new RuntimeException( 'Unknown package action.' ); }
					if ( 'rates' !== $op ) { self::save( $order, $data ); }
					if ( 'buy' === $op && WLP_Settings::customer_label_note_enabled() ) {
						$label_note = $p['label'];
						$label_note['package_number'] = (string) ( $index + 1 );
						WLP_Order_Logistics::send_customer_label_note( $order, $label_note );
					}
					unset( $p );
				}
				if ( 'rates' !== $op ) {
					$summary = self::summary( $order );
					if ( $summary['allShipped'] ) {
						$order->update_meta_data( '_wlp_shipped_at', (string) ( $order->get_meta( '_wlp_shipped_at', true ) ?: current_time( 'mysql' ) ) );
						$order->update_meta_data( '_wlp_wholesale_workflow_stage', 'shipped' );
					}
					if ( $summary['allDelivered'] ) { $order->update_meta_data( '_wlp_delivered_at', (string) ( $order->get_meta( '_wlp_delivered_at', true ) ?: current_time( 'mysql' ) ) ); }
					$order->save();
					foreach ( array_slice( self::joined_ids( $order ), 1 ) as $joined_id ) {
						$joined_order = wc_get_order( $joined_id );
						if ( $summary['allShipped'] ) { $joined_order->update_meta_data( '_wlp_shipped_at', $order->get_meta( '_wlp_shipped_at', true ) ); $joined_order->update_meta_data( '_wlp_wholesale_workflow_stage', 'shipped' ); }
						if ( $summary['allDelivered'] ) { $joined_order->update_meta_data( '_wlp_delivered_at', $order->get_meta( '_wlp_delivered_at', true ) ); }
						if ( $summary['allShipped'] || $summary['allDelivered'] ) { $joined_order->save(); }
					}
					$order->add_order_note( 'Split shipment: ' . $op . ' saved.', false, true );
					$notification = 'shipped' === $op ? self::notify_shipped( $order ) : null;
					$result = self::payload( $order );
					if ( $notification ) { $result['shipmentNotification'] = $notification; }
				}
			}
		} catch ( Throwable $e ) { $error = $e->getMessage(); }
		finally { if ( $locked ) { delete_option( $lock ); } }
		if ( $error ) {
			$failure = array( 'message' => $error );
			// A rejected or interrupted purchase can advance the revision before failing.
			// Return persisted state so the UI cannot offer a stale purchase again.
			if ( 'buy' === $op ) {
				try {
					$order = wc_get_order( $id );
					$order->read_meta_data( true );
					$failure['shipment'] = self::payload( $order );
				} catch ( Throwable $ignored ) {}
			}
			wp_send_json_error( $failure, 409 );
		}
		wp_send_json_success( $result );
	}
	public static function print_url( WC_Order $order, string $pid, string $kind ): string {
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wlp_split_print_' . $order->get_id() ), add_query_arg( array( 'action' => 'wlp_split_print', 'order_id' => $order->get_id(), 'package_id' => $pid, 'kind' => $kind ), admin_url( 'admin-post.php' ) ) );
	}
	public static function print_package(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Permission denied.' ); }
		$id = absint( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'wlp_split_print_' . $id );
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) { wp_die( 'Order not found.' ); }
		$order = self::root_order( $order ); self::assert_join_destination( $order );
		$data = self::read( $order );
		$index = array_search( sanitize_key( $_GET['package_id'] ?? '' ), array_column( $data['packages'], 'id' ), true );
		if ( false === $index ) { wp_die( 'Package not found.' ); }
		$p = $data['packages'][ $index ];
		try {
			nocache_headers();
			if ( 'label' === ( $_GET['kind'] ?? '' ) ) {
				if ( empty( $p['label']['label_artifact_url'] ) ) { throw new RuntimeException( 'No label PDF stored. Use your carrier account for externally purchased labels.' ); }
				$artifact = ( new WLP_Canada_Post_Client() )->download_artifact( $p['label']['label_artifact_url'] );
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: inline; filename="package-label.pdf"' );
				echo $artifact['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				$items = self::items( $order );
				header( 'Content-Type: text/html; charset=utf-8' );
				echo '<!doctype html><html><head><meta charset="utf-8"><title>Package packing list</title><style>body{font:16px sans-serif;max-width:720px;margin:40px auto}td,th{padding:12px;text-align:left;border-bottom:1px solid #ddd}table{width:100%}</style></head><body><h1>Order(s) #' . esc_html( implode( ' + #', self::joined_ids( $order ) ) ) . ' · Package ' . ( $index + 1 ) . '</h1><p>Package contents only. See the original order invoices for pricing.</p><table><tr><th>Product</th><th>Quantity</th></tr>';
				foreach ( $p['items'] as $key => $qty ) { echo '<tr><td>' . esc_html( $items[ $key ]['name'] ?? $key ) . '</td><td>' . esc_html( (string) $qty ) . '</td></tr>'; }
				echo '</table><p>' . nl2br( esc_html( $p['note'] ) ) . '</p></body></html>';
			}
			exit;
		} catch ( Throwable $e ) { wp_die( esc_html( $e->getMessage() ) ); }
	}
}
