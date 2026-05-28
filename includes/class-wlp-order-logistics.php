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
			'expected_delivery_date' => self::string_meta( $order, WLP_Meta_Keys::EXPECTED_DELIVERY_DATE, 'expected_delivery_date' ),
			'last_polled_at'         => self::string_meta( $order, WLP_Meta_Keys::LAST_POLLED_AT, 'last_polled_at' ),
			'shipped_at'             => self::string_meta( $order, WLP_Meta_Keys::SHIPPED_AT, 'shipped_at' ),
			'delivered_at'           => self::string_meta( $order, WLP_Meta_Keys::DELIVERED_AT, 'delivered_at' ),
			'preset_id'              => self::string_meta( $order, WLP_Meta_Keys::PRESET_ID ),
			'shipment_weight_kg'     => self::string_meta( $order, WLP_Meta_Keys::SHIPMENT_WEIGHT_KG ),
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
			'expected_delivery_date' => WLP_Meta_Keys::EXPECTED_DELIVERY_DATE,
			'preset_id'              => WLP_Meta_Keys::PRESET_ID,
			'shipment_weight_kg'     => WLP_Meta_Keys::SHIPMENT_WEIGHT_KG,
			'shipped_at'             => WLP_Meta_Keys::SHIPPED_AT,
		);

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

		$previous_tracking_number = $order->get_meta( WLP_Meta_Keys::CUSTOMER_NOTE_TRACKING, true );
		if ( ! $force && is_scalar( $previous_tracking_number ) && $tracking_number === trim( (string) $previous_tracking_number ) ) {
			return false;
		}

		$note = self::customer_label_note( $order, $metadata );
		if ( '' === trim( wp_strip_all_tags( $note ) ) ) {
			return false;
		}

		$order->add_order_note( $note, true, true );
		$order->update_meta_data( WLP_Meta_Keys::CUSTOMER_NOTE_TRACKING, $tracking_number );
		$order->save();

		return true;
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
			)
		);

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
		$stored = self::read( $order );
		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $metadata ) || null === $metadata[ $key ] || '' === trim( (string) $metadata[ $key ] ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
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
