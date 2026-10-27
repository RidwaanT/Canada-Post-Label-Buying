<?php
/**
 * Purpose: Reads and writes logistics metadata on WooCommerce orders.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order logistics metadata helpers.
 */
final class WLP_Order_Logistics {
	/**
	 * Returns normalized logistics metadata for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<string, string>
	 */
	public static function read( WC_Order $order ): array {
		return array(
			'label_created_at'       => self::string_meta( $order, WLP_Meta_Keys::LABEL_CREATED_AT, 'label_created_at' ),
			'label_artifact_url'     => self::string_meta( $order, WLP_Meta_Keys::LABEL_ARTIFACT_URL, 'label_artifact_url' ),
			'tracking_number'        => self::string_meta( $order, WLP_Meta_Keys::TRACKING_NUMBER, 'tracking_number' ),
			'tracking_url'           => self::string_meta( $order, WLP_Meta_Keys::TRACKING_URL, 'tracking_url' ),
			'service_code'           => self::string_meta( $order, WLP_Meta_Keys::SERVICE_CODE, 'service_code' ),
			'service_name'           => self::string_meta( $order, WLP_Meta_Keys::SERVICE_NAME, 'service_name' ),
			'shipping_cost'          => self::string_meta( $order, WLP_Meta_Keys::SHIPPING_COST, 'shipping_cost' ),
			'shipping_currency'      => self::string_meta( $order, WLP_Meta_Keys::SHIPPING_CURRENCY, 'shipping_currency' ),
			'shipment_id'            => self::string_meta( $order, WLP_Meta_Keys::SHIPMENT_ID, 'shipment_id' ),
			'packaging_preset_id'    => self::string_meta( $order, WLP_Meta_Keys::PACKAGING_PRESET_ID ),
			'packaging_preset_name'  => self::string_meta( $order, WLP_Meta_Keys::PACKAGING_PRESET_NAME ),
			'expected_delivery_date' => self::string_meta( $order, WLP_Meta_Keys::EXPECTED_DELIVERY_DATE, 'expected_delivery_date' ),
			'last_polled_at'         => self::string_meta( $order, WLP_Meta_Keys::LAST_POLLED_AT, 'last_polled_at' ),
			'shipped_at'             => self::string_meta( $order, WLP_Meta_Keys::SHIPPED_AT, 'shipped_at' ),
			'delivered_at'           => self::string_meta( $order, WLP_Meta_Keys::DELIVERED_AT, 'delivered_at' ),
			'preset_id'              => self::string_meta( $order, WLP_Meta_Keys::PRESET_ID ),
			'shipment_weight_kg'     => self::string_meta( $order, WLP_Meta_Keys::SHIPMENT_WEIGHT_KG ),
			'xp_upgrade_days_saved'  => self::string_meta( $order, WLP_Meta_Keys::XP_UPGRADE_DAYS_SAVED ),
			'invoice_printed_at'     => self::string_meta( $order, WLP_Meta_Keys::INVOICE_PRINTED_AT ),
			'packed_at'              => self::string_meta( $order, WLP_Meta_Keys::PACKED_AT ),
		);
	}

	/**
	 * Returns normalized return label metadata for an order.
	 *
	 * @return array<string, string>
	 */
	public static function read_return( WC_Order $order ): array {
		return array(
			'label_created_at'       => self::string_meta( $order, WLP_Meta_Keys::RETURN_LABEL_CREATED_AT ),
			'label_artifact_url'     => self::string_meta( $order, WLP_Meta_Keys::RETURN_LABEL_ARTIFACT_URL ),
			'tracking_number'        => self::string_meta( $order, WLP_Meta_Keys::RETURN_TRACKING_NUMBER ),
			'tracking_url'           => self::string_meta( $order, WLP_Meta_Keys::RETURN_TRACKING_URL ),
			'service_code'           => self::string_meta( $order, WLP_Meta_Keys::RETURN_SERVICE_CODE ),
			'service_name'           => self::string_meta( $order, WLP_Meta_Keys::RETURN_SERVICE_NAME ),
			'shipping_cost'          => self::string_meta( $order, WLP_Meta_Keys::RETURN_SHIPPING_COST ),
			'shipment_id'            => self::string_meta( $order, WLP_Meta_Keys::RETURN_SHIPMENT_ID ),
			'expected_delivery_date' => self::string_meta( $order, WLP_Meta_Keys::RETURN_EXPECTED_DELIVERY_DATE ),
		);
	}

	/**
	 * Writes label metadata after a successful carrier purchase.
	 *
	 * @param WC_Order                         $order WooCommerce order.
	 * @param array<string, string|float|null> $metadata Label metadata values.
	 */
	public static function write_label( WC_Order $order, array $metadata ): void {
		$map = array(
			'label_created_at'       => WLP_Meta_Keys::LABEL_CREATED_AT,
			'label_artifact_url'     => WLP_Meta_Keys::LABEL_ARTIFACT_URL,
			'tracking_number'        => WLP_Meta_Keys::TRACKING_NUMBER,
			'tracking_url'           => WLP_Meta_Keys::TRACKING_URL,
			'service_code'           => WLP_Meta_Keys::SERVICE_CODE,
			'service_name'           => WLP_Meta_Keys::SERVICE_NAME,
			'shipping_cost'          => WLP_Meta_Keys::SHIPPING_COST,
			'shipping_currency'      => WLP_Meta_Keys::SHIPPING_CURRENCY,
			'shipment_id'            => WLP_Meta_Keys::SHIPMENT_ID,
			'packaging_preset_id'    => WLP_Meta_Keys::PACKAGING_PRESET_ID,
			'packaging_preset_name'  => WLP_Meta_Keys::PACKAGING_PRESET_NAME,
			'expected_delivery_date' => WLP_Meta_Keys::EXPECTED_DELIVERY_DATE,
			'preset_id'              => WLP_Meta_Keys::PRESET_ID,
			'shipment_weight_kg'     => WLP_Meta_Keys::SHIPMENT_WEIGHT_KG,
			'xp_upgrade_days_saved'  => WLP_Meta_Keys::XP_UPGRADE_DAYS_SAVED,
			'shipped_at'             => WLP_Meta_Keys::SHIPPED_AT,
		);
		$existing_shipment_id = self::string_meta( $order, WLP_Meta_Keys::SHIPMENT_ID, 'shipment_id' );
		$incoming_shipment_id = self::metadata_string( $metadata, 'shipment_id' );

		if ( '' !== $incoming_shipment_id && $incoming_shipment_id === $existing_shipment_id ) {
			$existing_created_at = self::string_meta( $order, WLP_Meta_Keys::LABEL_CREATED_AT, 'label_created_at' );
			if ( '' !== $existing_created_at ) {
				$metadata['label_created_at'] = $existing_created_at;
			}
		}

		foreach ( $map as $source => $meta_key ) {
			if ( ! array_key_exists( $source, $metadata ) || null === $metadata[ $source ] ) {
				continue;
			}

			$clean_value = wc_clean( (string) $metadata[ $source ] );
			$order->update_meta_data( $meta_key, $clean_value );

			if ( WLP_Settings::mirror_external_logistics_meta() && isset( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $source ] ) ) {
				$order->update_meta_data( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $source ], $clean_value );
			}
		}

		$order->save();

		if ( WLP_Settings::customer_label_note_enabled() ) {
			self::send_customer_label_note( $order, $metadata, false );
		}
	}

	/**
	 * Returns true when an order has a purchased label.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public static function has_label( WC_Order $order ): bool {
		$meta = self::read( $order );

		return '' !== $meta['tracking_number'] && '' !== $meta['label_artifact_url'];
	}

	/**
	 * Writes return label metadata without touching outbound label metadata.
	 *
	 * @param array<string, string|float|null> $metadata Return label metadata values.
	 */
	public static function write_return_label( WC_Order $order, array $metadata ): void {
		$map = array(
			'label_created_at'       => WLP_Meta_Keys::RETURN_LABEL_CREATED_AT,
			'label_artifact_url'     => WLP_Meta_Keys::RETURN_LABEL_ARTIFACT_URL,
			'tracking_number'        => WLP_Meta_Keys::RETURN_TRACKING_NUMBER,
			'tracking_url'           => WLP_Meta_Keys::RETURN_TRACKING_URL,
			'service_code'           => WLP_Meta_Keys::RETURN_SERVICE_CODE,
			'service_name'           => WLP_Meta_Keys::RETURN_SERVICE_NAME,
			'shipping_cost'          => WLP_Meta_Keys::RETURN_SHIPPING_COST,
			'shipment_id'            => WLP_Meta_Keys::RETURN_SHIPMENT_ID,
			'expected_delivery_date' => WLP_Meta_Keys::RETURN_EXPECTED_DELIVERY_DATE,
		);

		foreach ( $map as $source => $meta_key ) {
			if ( ! array_key_exists( $source, $metadata ) || null === $metadata[ $source ] ) {
				continue;
			}

			$order->update_meta_data( $meta_key, wc_clean( (string) $metadata[ $source ] ) );
		}

		self::return_label_access_token( $order );
		$order->save();
		// Creation only saves the label. The explicit Send return note action sends email.
	}

	/**
	 * Returns true when an order has a purchased return label.
	 */
	public static function has_return_label( WC_Order $order ): bool {
		$meta = self::read_return( $order );

		return '' !== $meta['tracking_number'] && '' !== $meta['label_artifact_url'];
	}

	/**
	 * Returns a customer-safe URL for opening the stored return label.
	 */
	public static function customer_return_label_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'action'   => 'wlp_customer_return_label',
				'order_id' => $order->get_id(),
				'token'    => self::return_label_access_token( $order ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Verifies a customer return label access token.
	 */
	public static function verify_return_label_token( WC_Order $order, string $token ): bool {
		$stored_token = self::string_meta( $order, WLP_Meta_Keys::RETURN_LABEL_ACCESS_TOKEN );

		return '' !== $stored_token && '' !== $token && hash_equals( $stored_token, $token );
	}

	/**
	 * Marks an order as having its invoice/guide printed.
	 */
	public static function mark_invoice_printed( WC_Order $order ): void {
		if ( '' === self::string_meta( $order, WLP_Meta_Keys::INVOICE_PRINTED_AT ) ) {
			$order->update_meta_data( WLP_Meta_Keys::INVOICE_PRINTED_AT, current_time( 'mysql' ) );
			$order->save();
		}
	}

	/**
	 * Marks an order as packed.
	 */
	public static function mark_packed( WC_Order $order ): void {
		$changed = false;

		if ( '' === self::string_meta( $order, WLP_Meta_Keys::INVOICE_PRINTED_AT ) ) {
			$order->update_meta_data( WLP_Meta_Keys::INVOICE_PRINTED_AT, current_time( 'mysql' ) );
			$changed = true;
		}

		if ( '' === self::string_meta( $order, WLP_Meta_Keys::PACKED_AT ) ) {
			$order->update_meta_data( WLP_Meta_Keys::PACKED_AT, current_time( 'mysql' ) );
			$order->add_order_note( 'packed', false, true );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Marks an order as shipped without completing the WooCommerce order.
	 */
	public static function mark_shipped( WC_Order $order ): void {
		if ( class_exists('WLP_Contract_Shipping') ) { WLP_Contract_Shipping::assert_order_ready($order); WLP_Contract_Shipping::assert_transmitted( self::read($order)['shipment_id'] ); }
		self::mark_packed( $order );

		if ( '' === self::string_meta( $order, WLP_Meta_Keys::SHIPPED_AT ) ) {
			$order->update_meta_data( WLP_Meta_Keys::SHIPPED_AT, current_time( 'mysql' ) );
		}

		$order->add_order_note( __( 'Marked shipped from Logistics.', 'woo-logistics-plugin' ), false, true );
		$order->save();
	}

	/**
	 * Clears current fulfillment state so a replacement/reship can be created.
	 *
	 * @return array<string, string>
	 */
	public static function prepare_reship( WC_Order $order ): array {
		if ( class_exists('WLP_Contract_Shipping') ) { WLP_Contract_Shipping::assert_reship_allowed($order); }
		$order->update_meta_data('_wlp_contract_generation', (int) $order->get_meta('_wlp_contract_generation',true) + 1);
		$previous = self::read( $order );
		$archive  = array(
			'tracking_number' => $previous['tracking_number'],
			'shipment_id'     => $previous['shipment_id'],
			'service_name'    => $previous['service_name'],
			'label_url'       => $previous['label_artifact_url'],
		);

		$clear_keys = array(
			WLP_Meta_Keys::LABEL_CREATED_AT,
			WLP_Meta_Keys::LABEL_ARTIFACT_URL,
			WLP_Meta_Keys::TRACKING_NUMBER,
			WLP_Meta_Keys::TRACKING_URL,
			WLP_Meta_Keys::SERVICE_CODE,
			WLP_Meta_Keys::SERVICE_NAME,
			WLP_Meta_Keys::SHIPPING_COST,
			WLP_Meta_Keys::SHIPPING_CURRENCY,
			WLP_Meta_Keys::SHIPMENT_ID,
			WLP_Meta_Keys::PACKAGING_PRESET_ID,
			WLP_Meta_Keys::PACKAGING_PRESET_NAME,
			WLP_Meta_Keys::EXPECTED_DELIVERY_DATE,
			WLP_Meta_Keys::LAST_POLLED_AT,
			WLP_Meta_Keys::SHIPPED_AT,
			WLP_Meta_Keys::DELIVERED_AT,
			WLP_Meta_Keys::PRESET_ID,
			WLP_Meta_Keys::SHIPMENT_WEIGHT_KG,
			WLP_Meta_Keys::XP_UPGRADE_DAYS_SAVED,
			WLP_Meta_Keys::CUSTOMER_NOTE_TRACKING,
			WLP_Meta_Keys::INVOICE_PRINTED_AT,
			WLP_Meta_Keys::PACKED_AT,
		);

		foreach ( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS as $legacy_key ) {
			$clear_keys[] = $legacy_key;
		}

		foreach ( array_unique( $clear_keys ) as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}

		$note_parts = array( __( 'Prepared for reship: current label, tracking, packed state, and invoice state were cleared.', 'woo-logistics-plugin' ) );
		if ( '' !== $archive['tracking_number'] ) {
			$note_parts[] = sprintf(
				/* translators: %s: previous tracking number. */
				__( 'Previous tracking: %s.', 'woo-logistics-plugin' ),
				$archive['tracking_number']
			);
		}
		if ( '' !== $archive['shipment_id'] ) {
			$note_parts[] = sprintf(
				/* translators: %s: previous shipment ID. */
				__( 'Previous shipment ID: %s.', 'woo-logistics-plugin' ),
				$archive['shipment_id']
			);
		}
		$note_parts[] = __( 'Buy a new label before shipping the replacement/reship.', 'woo-logistics-plugin' );
		$order->add_order_note( implode( ' ', $note_parts ), false, true );

		if ( ! $order->has_status( array( 'cancelled', 'refunded', 'processing' ) ) ) {
			$order->update_status( 'processing', __( 'Prepared for reship from Logistics.', 'woo-logistics-plugin' ), false );
		}

		$order->save();

		return $archive;
	}

	/**
	 * Marks an order as manually delivered for logistics.
	 */
	public static function mark_delivered( WC_Order $order ): void {
		$now = current_time( 'mysql' );

		if ( '' === self::string_meta( $order, WLP_Meta_Keys::SHIPPED_AT ) ) {
			$order->update_meta_data( WLP_Meta_Keys::SHIPPED_AT, $now );
		}

		if ( '' === self::string_meta( $order, WLP_Meta_Keys::DELIVERED_AT ) ) {
			$order->update_meta_data( WLP_Meta_Keys::DELIVERED_AT, $now );
		}

		if ( 'completed' !== $order->get_status() ) {
			$order->update_status( 'completed', __( 'Marked delivered from Logistics.', 'woo-logistics-plugin' ), false );
		} else {
			$order->add_order_note( __( 'Marked delivered from Logistics.', 'woo-logistics-plugin' ), false, true );
		}

		$order->save();
	}

	/**
	 * Writes live Canada Post tracking estimate metadata.
	 *
	 * @param WC_Order              $order WooCommerce order.
	 * @param array<string, string> $metadata Tracking estimate metadata.
	 */
	public static function write_tracking_estimate( WC_Order $order, array $metadata ): void {
		$map = array(
			'expected_delivery_date' => WLP_Meta_Keys::EXPECTED_DELIVERY_DATE,
			'last_polled_at'         => WLP_Meta_Keys::LAST_POLLED_AT,
			'delivered_at'           => WLP_Meta_Keys::DELIVERED_AT,
		);

		foreach ( $map as $source => $meta_key ) {
			if ( ! isset( $metadata[ $source ] ) || '' === trim( $metadata[ $source ] ) ) {
				continue;
			}

			$clean_value = wc_clean( $metadata[ $source ] );
			$order->update_meta_data( $meta_key, $clean_value );

			if ( WLP_Settings::mirror_external_logistics_meta() && isset( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $source ] ) ) {
				$order->update_meta_data( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $source ], $clean_value );
			}
		}

		$order->save();
	}

	/**
	 * Reads a string meta value, falling back to legacy Medusa keys for display.
	 *
	 * @param WC_Order    $order WooCommerce order.
	 * @param string      $key Primary meta key.
	 * @param string|null $legacy_slug Legacy Medusa slug.
	 */
	private static function string_meta( WC_Order $order, string $key, ?string $legacy_slug = null ): string {
		$value = $order->get_meta( $key, true );
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			return trim( (string) $value );
		}

		if ( $legacy_slug && isset( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $legacy_slug ] ) ) {
			$legacy = $order->get_meta( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $legacy_slug ], true );
			if ( is_scalar( $legacy ) ) {
				return trim( (string) $legacy );
			}
		}

		return '';
	}

	/**
	 * Sends the customer-facing label-created note.
	 *
	 * @param WC_Order                         $order WooCommerce order.
	 * @param array<string, string|float|null> $metadata Label metadata values.
	 * @param bool                             $force Whether to send even if this tracking number was already sent.
	 */
	public static function send_customer_label_note( WC_Order $order, array $metadata = array(), bool $force = false ): bool {
		$metadata = self::label_note_metadata( $order, $metadata );

		$tracking_number = self::metadata_string( $metadata, 'tracking_number' );
		if ( '' === $tracking_number ) {
			return false;
		}

		$event_key = '_wlp_label_notice_' . hash( 'sha256', $tracking_number );
		$lock = 'wlp_label_notice_lock_' . $order->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) { return false; }
		try {
			$order->read_meta_data( true );
			$previous = (string) $order->get_meta( WLP_Meta_Keys::CUSTOMER_NOTE_TRACKING, true );
			// Keep historical sends and uncertain attempts from being retried automatically.
			if ( ! $force && ( $tracking_number === trim( $previous ) || $order->get_meta( $event_key, true ) ) ) { return false; }
			$note = self::customer_label_note( $order, $metadata );
			if ( '' === trim( wp_strip_all_tags( $note ) ) ) { return false; }
			$order->update_meta_data( $event_key, array( 'state' => 'sending', 'at' => current_time( 'mysql' ) ) );
			$order->save();
			$note_id = $order->add_order_note( $note, true, true );
			if ( ! $note_id ) { throw new RuntimeException( 'Customer tracking note could not be saved.' ); }
			$order->update_meta_data( WLP_Meta_Keys::CUSTOMER_NOTE_TRACKING, $tracking_number );
			$order->update_meta_data( $event_key, array( 'state' => 'recorded', 'at' => current_time( 'mysql' ), 'note_id' => $note_id ) );
			$order->save();
			return true;
		} catch ( Throwable $e ) {
			// A notification failure must never turn a successful postage purchase into a retry.
			$order->add_order_note( 'Label saved; automatic tracking notification needs review. Check mail logs before retrying.', false, true );
			return false;
		} finally { delete_option( $lock ); }
	}

	/**
	 * Sends the customer-facing return label note.
	 *
	 * @param array<string, string|float|null> $metadata Return label metadata values.
	 */
	public static function send_customer_return_label_note( WC_Order $order, array $metadata = array(), bool $force = false ): bool {
		$metadata = self::return_label_note_metadata( $order, $metadata );

		$tracking_number = self::metadata_string( $metadata, 'tracking_number' );
		if ( '' === $tracking_number ) {
			return false;
		}

		$previous_tracking_number = $order->get_meta( WLP_Meta_Keys::RETURN_CUSTOMER_NOTE_TRACKING, true );
		if ( ! $force && is_scalar( $previous_tracking_number ) && $tracking_number === trim( (string) $previous_tracking_number ) ) {
			return false;
		}

		$tracking_url = self::metadata_string( $metadata, 'tracking_url' );
		if ( '' === $tracking_url ) {
			$tracking_url = 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $tracking_number );
		}

		$first_name = trim( (string) $order->get_shipping_first_name() );
		if ( '' === $first_name ) {
			$first_name = trim( (string) $order->get_billing_first_name() );
		}

		$return_address = array_filter(
			array(
				WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_NAME ),
				WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_COMPANY ),
				WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_ADDR_1 ),
				WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_ADDR_2 ),
				trim( WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_CITY ) . ', ' . WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_PROV ) . ' ' . WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_POSTAL ) ),
			),
			static fn ( string $line ): bool => '' !== trim( $line )
		);

		$note = strtr(
			WLP_Settings::return_customer_note_template(),
			array(
				'{first_name}'             => sanitize_text_field( $first_name ),
				'{order_number}'           => sanitize_text_field( (string) $order->get_order_number() ),
				'{return_tracking_number}' => sanitize_text_field( self::format_tracking_number( $tracking_number ) ),
				'{return_tracking_raw}'    => sanitize_text_field( $tracking_number ),
				'{return_tracking_url}'    => esc_url( $tracking_url ),
				'{return_label_url}'       => esc_url( self::customer_return_label_url( $order ) ),
				'{return_service_name}'    => sanitize_text_field( self::metadata_string( $metadata, 'service_name' ) ),
				'{return_address}'         => sanitize_textarea_field( implode( "\n", $return_address ) ),
			)
		);

		if ( '' === trim( wp_strip_all_tags( $note ) ) ) {
			return false;
		}

		$order->add_order_note( wp_kses_post( str_replace( 'Hi ,', 'Hi,', $note ) ), true, true );
		$order->update_meta_data( WLP_Meta_Keys::RETURN_CUSTOMER_NOTE_TRACKING, $tracking_number );
		$order->save();

		return true;
	}

	/**
	 * Sends a soft reorder follow-up after enough time has passed since delivery/completion.
	 */
	public static function send_reorder_followup_note( WC_Order $order, bool $force = false ): bool {
		if ( ! $force && '' !== trim( (string) $order->get_meta( WLP_Meta_Keys::REORDER_FOLLOWUP_SENT_AT, true ) ) ) {
			return false;
		}

		$email = sanitize_email( $order->get_billing_email() );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$first_name = trim( (string) $order->get_billing_first_name() );
		if ( '' === $first_name ) {
			$first_name = trim( (string) $order->get_shipping_first_name() );
		}

		$greeting = '' !== $first_name ? 'Hi ' . sanitize_text_field( $first_name ) . ',' : 'Hi,';
		$note     = $greeting . "\n\nJust checking in on order #" . sanitize_text_field( (string) $order->get_order_number() ) . ".\n\nIf everything arrived correctly, you can reorder anytime here:\nhttps://peptidego.ca/shop/\n\nFor larger orders, 5+ vials unlocks better pricing and free shipping automatically.\n\nIf you need anything, reply to this email.\n\nPeptideGo";

		$order->add_order_note( nl2br( esc_html( $note ) ), true, true );
		$order->update_meta_data( WLP_Meta_Keys::REORDER_FOLLOWUP_SENT_AT, current_time( 'mysql' ) );
		$order->save();

		return true;
	}

	/**
	 * Sends due reorder follow-ups for completed retail orders.
	 */
	public static function send_due_reorder_followups( int $limit = 20 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'sent' => 0, 'checked' => 0 );
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$max_completed = $now->modify( '-7 days' )->format( 'Y-m-d H:i:s' );
		$min_completed = $now->modify( '-21 days' )->format( 'Y-m-d H:i:s' );

		$orders = wc_get_orders(
			array(
				'status'       => array( 'completed' ),
				'limit'        => max( 1, $limit ),
				'orderby'      => 'date_completed',
				'order'        => 'ASC',
				'date_completed' => $min_completed . '...' . $max_completed,
				'meta_query'   => array(
					array(
						'key'     => WLP_Meta_Keys::REORDER_FOLLOWUP_SENT_AT,
						'compare' => 'NOT EXISTS',
					),
				),
				'return'       => 'objects',
			)
		);

		$sent    = 0;
		$checked = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			++$checked;

			if ( ! $order->is_paid() || (float) $order->get_total() <= 0 ) {
				continue;
			}

			if ( 'yes' === trim( (string) $order->get_meta( WLP_Meta_Keys::IS_WHOLESALE, true ) ) ) {
				continue;
			}

			if ( self::send_reorder_followup_note( $order ) ) {
				++$sent;
			}
		}

		return array( 'sent' => $sent, 'checked' => $checked );
	}

	/**
	 * Builds the customer-facing label-created note from the configured template.
	 *
	 * @param WC_Order                         $order WooCommerce order.
	 * @param array<string, string|float|null> $metadata Label metadata values.
	 */
	private static function customer_label_note( WC_Order $order, array $metadata ): string {
		$tracking_number = self::metadata_string( $metadata, 'tracking_number' );
		$tracking_url    = self::metadata_string( $metadata, 'tracking_url' );
		$service_name    = self::metadata_string( $metadata, 'service_name' );
		$service_code    = self::metadata_string( $metadata, 'service_code' );
		$estimated_date  = self::delivery_date_label( self::metadata_string( $metadata, 'expected_delivery_date' ) );
		$guaranteed_date = self::guaranteed_delivery_date_label( self::metadata_string( $metadata, 'expected_delivery_date' ), $service_code );

		if ( '' === $tracking_url && '' !== $tracking_number ) {
			$tracking_url = 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $tracking_number );
		}

		$first_name = trim( (string) $order->get_shipping_first_name() );
		if ( '' === $first_name ) {
			$first_name = trim( (string) $order->get_billing_first_name() );
		}

		$note = strtr(
			WLP_Settings::customer_label_note_template(),
			array(
				'{first_name}'          => sanitize_text_field( $first_name ),
				'{order_number}'        => sanitize_text_field( (string) $order->get_order_number() ),
				'{service_name}'        => sanitize_text_field( $service_name ),
				'{service_label}'       => sanitize_text_field( self::service_label( $service_name, $service_code ) ),
				'{tracking_number}'     => sanitize_text_field( self::format_tracking_number( $tracking_number ) ),
				'{tracking_number_raw}' => sanitize_text_field( $tracking_number ),
				'{tracking_url}'        => esc_url( $tracking_url ),
				'{estimated_delivery_date}' => sanitize_text_field( $estimated_date ),
				'{guaranteed_delivery_date}' => sanitize_text_field( $guaranteed_date ),
			)
		);

		$package_number = absint( self::metadata_string( $metadata, 'package_number' ) );
		if ( $package_number ) { $note = 'Order #' . $order->get_order_number() . ' · Package ' . $package_number . "\n\n" . $note; }
		if ( $order->get_meta( '_pg_wholesale_quote_products', true ) ) {
			$note .= "\n\nView your order status, all packages and estimated send dates:\n" . home_url( '/my-account/?wholesale=1' ) . "\nSign in or create an account with the email used for your quote. Verify that email when prompted to see guest orders.";
		}
		if ( false === strpos( WLP_Settings::customer_label_note_template(), '{estimated_delivery_date}' ) ) {
			$note .= "\n\nEstimated delivery: " . $estimated_date . '. Carrier estimate, subject to acceptance and transit conditions.';
		}

		$upgrade_days_saved = absint( self::metadata_string( $metadata, 'xp_upgrade_days_saved' ) );
		if ( $upgrade_days_saved > 0 && 'DOM.XP' === $service_code ) {
			$upgrade_message = sprintf(
				/* translators: 1: number of business days saved, 2: singular/plural business day label. */
				__( 'Good news! We upgraded your shipment to Xpresspost at no additional cost, saving an estimated %1$d %2$s. We made this complimentary upgrade because we appreciate your order and wanted to help it arrive sooner.', 'woo-logistics-plugin' ),
				$upgrade_days_saved,
				_n( 'business day', 'business days', $upgrade_days_saved, 'woo-logistics-plugin' )
			);
			$note = $upgrade_message . "\n\n" . $note;
		}

		return wp_kses_post( str_replace( 'Hi ,', 'Hi,', $note ) );
	}

	/**
	 * Reads a string from label metadata.
	 *
	 * @param array<string, string|float|null> $metadata Label metadata values.
	 */
	private static function metadata_string( array $metadata, string $key ): string {
		if ( ! array_key_exists( $key, $metadata ) || null === $metadata[ $key ] ) {
			return '';
		}

		return trim( (string) $metadata[ $key ] );
	}

	/**
	 * Combines just-created label metadata with stored order logistics metadata.
	 *
	 * @param WC_Order                         $order WooCommerce order.
	 * @param array<string, string|float|null> $metadata Label metadata values.
	 * @return array<string, string|float|null>
	 */
	private static function label_note_metadata( WC_Order $order, array $metadata ): array {
		if ( ! empty( $metadata['package_number'] ) ) { return $metadata; } // Never borrow another parcel's dates or tracking.
		$stored = self::read( $order );
		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $metadata ) || null === $metadata[ $key ] || '' === trim( (string) $metadata[ $key ] ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Combines just-created return label metadata with stored return metadata.
	 *
	 * @param array<string, string|float|null> $metadata Return label metadata values.
	 * @return array<string, string|float|null>
	 */
	private static function return_label_note_metadata( WC_Order $order, array $metadata ): array {
		$stored = self::read_return( $order );
		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $metadata ) || null === $metadata[ $key ] || '' === trim( (string) $metadata[ $key ] ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Returns or creates the secret token used for customer label access.
	 */
	private static function return_label_access_token( WC_Order $order ): string {
		$token = self::string_meta( $order, WLP_Meta_Keys::RETURN_LABEL_ACCESS_TOKEN );
		if ( '' !== $token ) {
			return $token;
		}

		$token = wp_generate_password( 40, false, false );
		$order->update_meta_data( WLP_Meta_Keys::RETURN_LABEL_ACCESS_TOKEN, $token );

		return $token;
	}

	/**
	 * Formats a Canada Post date for customer-facing notes.
	 */
	private static function delivery_date_label( string $date ): string {
		$date = trim( $date );
		if ( '' === $date ) {
			return __( 'Available after Canada Post scans the package.', 'woo-logistics-plugin' );
		}

		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return $date;
		}

		return wp_date( 'F j, Y', $timestamp );
	}

	/**
	 * Returns the guaranteed delivery date label for customer-facing notes.
	 */
	private static function guaranteed_delivery_date_label( string $date, string $service_code ): string {
		if ( in_array( $service_code, array( 'DOM.XP', 'DOM.PC' ), true ) ) {
			return self::delivery_date_label( $date );
		}

		return __( 'Not guaranteed for this service.', 'woo-logistics-plugin' );
	}

	/**
	 * Formats Canada Post PINs in readable 4-character groups.
	 */
	private static function format_tracking_number( string $tracking_number ): string {
		$digits = preg_replace( '/\D+/', '', $tracking_number ) ?? '';
		if ( '' !== $digits && $digits === $tracking_number ) {
			return implode( ' ', str_split( $digits, 4 ) );
		}

		return $tracking_number;
	}

	/**
	 * Returns the customer-facing service label for the Carrier line.
	 */
	private static function service_label( string $service_name, string $service_code ): string {
		$label = trim( preg_replace( '/^Canada Post\s+/i', '', $service_name ) ?? '' );
		if ( '' !== $label ) {
			return $label;
		}

		return match ( $service_code ) {
			'DOM.RP' => 'Regular Parcel',
			'DOM.EP' => 'Expedited Parcel',
			'DOM.PC' => 'Priority',
			default  => 'Xpresspost',
		};
	}
}
