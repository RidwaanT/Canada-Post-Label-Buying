<?php
/**
 * Purpose: Sends consented wholesale CRM outcomes through the existing Meta for WooCommerce connection.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta Conversions API bridge for wholesale lead-quality outcomes.
 */
final class WLP_Meta_CAPI {
	private const EVENT_VERSION = 'v1';
	private const META_PREFIX = '_wlp_meta_capi_';
	private static string $active_event_id = '';

	/**
	 * Sends one deduplicated CRM outcome for a wholesale order.
	 *
	 * @return array{status:string,message:string,event_id:string}
	 */
	public static function send_outcome( WC_Order $order, string $outcome ): array {
		$outcome = sanitize_key( $outcome );
		// Never send wholesale purchases to the unrelated retail plugin connection.
		if ( 'purchase' === $outcome ) {
			return class_exists( 'WLP_Wholesale_Purchase' )
				? WLP_Wholesale_Purchase::queue( $order )
				: self::result( 'skipped', 'Wholesale Purchase connection is unavailable.' );
		}
		$configs = array(
			'qualified' => array(
				'event_name' => 'QualifiedLead',
				'label'      => __( 'qualified lead', 'woo-logistics-plugin' ),
			),
			'purchase' => array(
				'event_name' => 'Purchase',
				'label'      => __( 'verified purchase', 'woo-logistics-plugin' ),
			),
		);

		if ( ! isset( $configs[ $outcome ] ) ) {
			return self::result( 'skipped', __( 'Unknown Meta outcome.', 'woo-logistics-plugin' ) );
		}

		if ( 'yes' !== $order->get_meta( '_pg_meta_measurement_allowed', true ) ) {
			return self::result( 'skipped', __( 'Meta measurement was not permitted for this quote.', 'woo-logistics-plugin' ) );
		}

		$sent_meta = self::META_PREFIX . $outcome . '_sent_at';
		$event_id  = self::event_id( $order, $outcome );
		if ( '' !== trim( (string) $order->get_meta( $sent_meta, true ) ) ) {
			return self::result( 'already_sent', __( 'This Meta outcome was already sent.', 'woo-logistics-plugin' ), $event_id );
		}

		if (
			! function_exists( 'facebook_for_woocommerce' )
			|| ! class_exists( '\\WooCommerce\\Facebook\\Events\\Event' )
		) {
			return self::record_failure( $order, $outcome, __( 'Meta for WooCommerce is unavailable.', 'woo-logistics-plugin' ), $event_id );
		}

		$user_data = array(
			'em'                  => sanitize_email( (string) $order->get_billing_email() ),
			'ph'                  => preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() ),
			'fn'                  => sanitize_text_field( (string) $order->get_billing_first_name() ),
			'ln'                  => sanitize_text_field( (string) $order->get_billing_last_name() ),
			'country'             => strtolower( (string) $order->get_billing_country() ),
			'external_id'         => 'pg-wholesale-' . $order->get_id(),
			'click_id'            => self::tracking_value( $order, '_pg_meta_fbc' ),
			'browser_id'          => self::tracking_value( $order, '_pg_meta_fbp' ),
			'client_ip_address'   => '',
			'client_user_agent'   => '',
		);
		foreach ( array( 'em', 'ph', 'fn', 'ln', 'country', 'click_id', 'browser_id' ) as $optional_key ) {
			if ( null === $user_data[ $optional_key ] || '' === $user_data[ $optional_key ] ) {
				unset( $user_data[ $optional_key ] );
			}
		}

		$custom_data = array(
			'event_source'      => 'crm',
			'lead_event_source' => 'PeptideGo CRM',
		);
		if ( 'purchase' === $outcome ) {
			$value = (float) $order->get_total();
			if ( $value <= 0 ) {
				$value = (float) $order->get_meta( '_pg_wholesale_estimated_total', true );
			}
			$custom_data = array_merge(
				$custom_data,
				array(
				'currency' => strtoupper( (string) ( $order->get_currency() ?: 'CAD' ) ),
				'value'    => round( max( 0, $value ), 2 ),
				)
			);
		}

		try {
			$event_class = '\\WooCommerce\\Facebook\\Events\\Event';
			$event = new $event_class(
				array(
					'event_name'       => $configs[ $outcome ]['event_name'],
					'event_time'       => time(),
					'event_id'         => $event_id,
					'action_source'    => 'system_generated',
					'event_source_url' => home_url( '/wholesale/' ),
					'user_data'        => $user_data,
					'custom_data'      => $custom_data,
				)
			);

			$meta = facebook_for_woocommerce();
			$pixel_id = trim( (string) $meta->get_integration()->get_facebook_pixel_id() );
			if ( '' === $pixel_id ) {
				throw new RuntimeException( __( 'The connected Meta pixel is missing.', 'woo-logistics-plugin' ) );
			}

			self::$active_event_id = $event_id;
			add_filter( 'wc_facebook_api_pixel_event_request_data', array( self::class, 'scrub_admin_request_data' ), 999, 2 );
			try {
				$response = $meta->get_api()->send_pixel_events( $pixel_id, array( $event ) );
			} finally {
				remove_filter( 'wc_facebook_api_pixel_event_request_data', array( self::class, 'scrub_admin_request_data' ), 999 );
				self::$active_event_id = '';
			}
			if ( is_object( $response ) && method_exists( $response, 'has_api_error' ) && $response->has_api_error() ) {
				$error_code = method_exists( $response, 'get_api_error_code' ) ? (string) $response->get_api_error_code() : '';
				throw new RuntimeException(
					$error_code
						? sprintf( __( 'Meta rejected the event (code %s).', 'woo-logistics-plugin' ), $error_code )
						: __( 'Meta rejected the event.', 'woo-logistics-plugin' )
				);
			}

			$now = current_time( 'mysql' );
			$order->update_meta_data( $sent_meta, $now );
			$order->update_meta_data( self::META_PREFIX . $outcome . '_event_id', $event_id );
			$order->update_meta_data( self::META_PREFIX . $outcome . '_status', 'accepted' );
			$order->delete_meta_data( self::META_PREFIX . $outcome . '_error' );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					/* translators: %s: CRM outcome label. */
					__( 'Meta CRM outcome accepted: %s. Contact fields were normalized and SHA-256 hashed by Meta for WooCommerce before transmission.', 'woo-logistics-plugin' ),
					$configs[ $outcome ]['label']
				),
				false,
				true
			);

			return self::result( 'accepted', __( 'Meta accepted the CRM outcome.', 'woo-logistics-plugin' ), $event_id );
		} catch ( Throwable $error ) {
			return self::record_failure( $order, $outcome, $error->getMessage(), $event_id );
		}
	}

	/**
	 * Removes Logistics-admin request identifiers added by Meta's generic Event defaults.
	 *
	 * @param array<string, mixed> $payload Outbound Meta request payload.
	 * @param mixed                $request Meta request object.
	 * @return array<string, mixed>
	 */
	public static function scrub_admin_request_data( array $payload, $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		foreach ( $payload['data'] ?? array() as $index => $event ) {
			if ( ! is_array( $event ) || ( $event['event_id'] ?? '' ) !== self::$active_event_id ) {
				continue;
			}

			unset( $payload['data'][ $index ]['referrer_url'] );
			if ( isset( $payload['data'][ $index ]['user_data'] ) && is_array( $payload['data'][ $index ]['user_data'] ) ) {
				$payload['data'][ $index ]['user_data'] = array_filter(
					$payload['data'][ $index ]['user_data'],
					static fn( $value ): bool => null !== $value && '' !== $value
				);
			}
		}

		return $payload;
	}

	private static function event_id( WC_Order $order, string $outcome ): string {
		return sprintf( 'pg_wholesale_%d_%s_%s', $order->get_id(), $outcome, self::EVENT_VERSION );
	}

	private static function tracking_value( WC_Order $order, string $key ): string {
		$value = trim( (string) $order->get_meta( $key, true ) );
		return strlen( $value ) <= 512 ? $value : '';
	}

	/**
	 * @return array{status:string,message:string,event_id:string}
	 */
	private static function record_failure( WC_Order $order, string $outcome, string $message, string $event_id ): array {
		$safe_message = sanitize_text_field( $message );
		$order->update_meta_data( self::META_PREFIX . $outcome . '_status', 'failed' );
		$order->update_meta_data( self::META_PREFIX . $outcome . '_error', substr( $safe_message, 0, 300 ) );
		$order->update_meta_data( self::META_PREFIX . $outcome . '_last_attempt_at', current_time( 'mysql' ) );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: %s: non-sensitive Meta API error. */
				__( 'Meta CRM outcome was not sent: %s The wholesale workflow was not blocked.', 'woo-logistics-plugin' ),
				$safe_message
			),
			false,
			true
		);

		return self::result( 'failed', $safe_message, $event_id );
	}

	/**
	 * @return array{status:string,message:string,event_id:string}
	 */
	private static function result( string $status, string $message, string $event_id = '' ): array {
		return array(
			'status'   => $status,
			'message'  => $message,
			'event_id' => $event_id,
		);
	}
}
