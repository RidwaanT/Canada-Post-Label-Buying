<?php
/**
 * Purpose: Handles Canada Post rating, shipment creation, and label download calls.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight Canada Post Web Services client.
 */
final class WLP_Canada_Post_Client {
	private const TRACKING_BASE = 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=';

	/**
	 * Returns configured parcel presets.
	 *
	 * @return array<int, array<string, float|string>>
	 */
	public function get_presets(): array {
		return WLP_Settings::presets();
	}

	/**
	 * Returns static Canada Post domestic services supported by this plugin.
	 *
	 * @return array<int, array{id: string, name: string}>
	 */
	public function get_services(): array {
		return array(
			array(
				'id'   => 'DOM.RP',
				'name' => 'Canada Post Regular Parcel',
			),
			array(
				'id'   => 'DOM.XP',
				'name' => 'Canada Post Xpresspost',
			),
			array(
				'id'   => 'DOM.EP',
				'name' => 'Canada Post Expedited Parcel',
			),
			array(
				'id'   => 'DOM.PC',
				'name' => 'Canada Post Priority',
			),
		);
	}

	/**
	 * Fetches rates for a Woo order and package preset.
	 *
	 * @param WC_Order                    $order WooCommerce order.
	 * @param array<string, float|string> $preset Package preset.
	 * @return array<int, array<string, string>>
	 */
	public function get_rates( WC_Order $order, array $preset ): array {
		$this->assert_canadian_destination( $order );
		$destination_postal = $this->normalize_postal_code( (string) $order->get_shipping_postcode() );

		if ( '' === $destination_postal ) {
			throw new RuntimeException( __( 'Order is missing a shipping postal code.', 'woo-logistics-plugin' ) );
		}

		$body = $this->request(
			'POST',
			'/rs/ship/price',
			$this->build_rate_xml( $order, $destination_postal, $preset ),
			'application/vnd.cpc.ship.rate-v4+xml',
			'application/vnd.cpc.ship.rate-v4+xml'
		);

		return $this->parse_rates( $body );
	}

	/**
	 * Creates a Canada Post non-contract shipment and returns label details.
	 *
	 * @param WC_Order                    $order WooCommerce order.
	 * @param array<string, float|string> $preset Package preset.
	 * @return array<string, string>
	 */
	public function create_shipment( WC_Order $order, array $preset, string $service_code ): array {
		$this->assert_canadian_destination( $order );
		$customer_number = $this->required_option( WLP_Settings::OPTION_CUSTOMER, __( 'Customer number', 'woo-logistics-plugin' ) );

		$body = $this->request(
			'POST',
			'/rs/' . rawurlencode( $customer_number ) . '/ncshipment',
			$this->build_shipment_xml( $order, $preset, $service_code ),
			'application/vnd.cpc.ncshipment-v4+xml',
			'application/vnd.cpc.ncshipment-v4+xml'
		);

		return $this->parse_shipment( $body );
	}

	/**
	 * Downloads a stored Canada Post label artifact.
	 *
	 * @return array{body: string, content_type: string}
	 */
	public function download_artifact( string $url ): array {
		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'headers' => array(
					'Authorization' => $this->auth_header(),
					'Accept'        => 'application/pdf, application/octet-stream, */*',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( __( 'Failed to download Canada Post label artifact.', 'woo-logistics-plugin' ) );
		}

		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body         = (string) wp_remote_retrieve_body( $response );

		if ( str_contains( strtolower( $content_type ), 'pdf' ) ) {
			$body = WLP_Label_Normalizer::normalize_pdf( $body );
		}

		return array(
			'body'         => $body,
			'content_type' => $content_type ?: 'application/pdf',
		);
	}

	/**
	 * Finds a preset by id.
	 *
	 * @return array<string, float|string>
	 */
	public function find_preset( string $preset_id ): array {
		foreach ( $this->get_presets() as $preset ) {
			if ( $preset_id === $preset['id'] ) {
				return $preset;
			}
		}

		throw new RuntimeException( __( 'Invalid package preset selected.', 'woo-logistics-plugin' ) );
	}

	/**
	 * Calculates the shipment weight Canada Post will receive for a preset.
	 *
	 * @param WC_Order                    $order WooCommerce order.
	 * @param array<string, float|string> $preset Package preset.
	 */
	public function shipment_weight( WC_Order $order, array $preset ): string {
		return $this->parcel_weight( $order, $preset );
	}

	/**
	 * Finds a service display name by code.
	 */
	public function service_name( string $service_code ): string {
		foreach ( $this->get_services() as $service ) {
			if ( $service_code === $service['id'] ) {
				return $service['name'];
			}
		}

		return $service_code;
	}

	/**
	 * Performs a Canada Post XML request with SLM retry handling.
	 */
	private function request( string $method, string $path, string $xml, string $content_type, string $accept ): string {
		$attempts   = 3;
		$delay_ms   = 1500;
		$last_error = null;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$response = wp_remote_request(
				$this->base_url() . $path,
				array(
					'method'  => $method,
					'headers' => array(
						'Authorization'   => $this->auth_header(),
						'Content-Type'    => $content_type,
						'Accept'          => $accept,
						'Accept-Language' => 'en-CA',
					),
					'body'    => $xml,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( $response->get_error_message() );
			}

			$body   = (string) wp_remote_retrieve_body( $response );
			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( $status >= 200 && $status < 300 ) {
				return $body;
			}

			$message    = $this->extract_error_message( $body, __( 'Canada Post request failed.', 'woo-logistics-plugin' ) );
			$last_error = new RuntimeException( $message );

			if ( ! str_contains( strtolower( $message ), 'rejected by slm monitor' ) || $attempt >= $attempts ) {
				break;
			}

			usleep( $delay_ms * $attempt * 1000 );
		}

		throw $last_error ?: new RuntimeException( __( 'Canada Post request failed.', 'woo-logistics-plugin' ) );
	}

	/**
	 * Builds a Canada Post rate XML payload.
	 *
	 * @param array<string, float|string> $preset Package preset.
	 */
	private function build_rate_xml( WC_Order $order, string $destination_postal, array $preset ): string {
		$xml = new SimpleXMLElement( '<mailing-scenario xmlns="http://www.canadapost.ca/ws/ship/rate-v4"></mailing-scenario>' );
		$xml->addChild( 'customer-number', $this->required_option( WLP_Settings::OPTION_CUSTOMER, __( 'Customer number', 'woo-logistics-plugin' ) ) );
		$parcel = $xml->addChild( 'parcel-characteristics' );
		$parcel->addChild( 'weight', $this->parcel_weight( $order, $preset ) );
		$dimensions = $parcel->addChild( 'dimensions' );
		$dimensions->addChild( 'length', (string) $preset['length'] );
		$dimensions->addChild( 'width', (string) $preset['width'] );
		$dimensions->addChild( 'height', (string) $preset['height'] );
		$this->add_signature_option( $xml );
		$xml->addChild( 'origin-postal-code', $this->normalize_postal_code( $this->required_option( WLP_Settings::OPTION_ORIGIN_POSTAL, __( 'Origin postal code', 'woo-logistics-plugin' ) ) ) );
		$destination = $xml->addChild( 'destination' );
		$domestic    = $destination->addChild( 'domestic' );
		$domestic->addChild( 'postal-code', $destination_postal );

		return (string) $xml->asXML();
	}

	/**
	 * Builds a Canada Post non-contract shipment XML payload.
	 *
	 * @param WC_Order                    $order WooCommerce order.
	 * @param array<string, float|string> $preset Package preset.
	 */
	private function build_shipment_xml( WC_Order $order, array $preset, string $service_code ): string {
		$origin_phone = $this->normalize_phone( $this->option( WLP_Settings::OPTION_ORIGIN_PHONE ) );
		if ( '' === $origin_phone ) {
			throw new RuntimeException( __( 'Canada Post origin phone number is missing or invalid.', 'woo-logistics-plugin' ) );
		}

		$origin_name        = $this->required_option( WLP_Settings::OPTION_ORIGIN_NAME, __( 'Origin contact name', 'woo-logistics-plugin' ) );
		$sender_company     = $this->option( WLP_Settings::OPTION_ORIGIN_COMPANY ) ?: $origin_name ?: 'Warehouse';
		$shipping_phone     = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
		$destination_phone  = $this->normalize_phone( $shipping_phone ?: $order->get_billing_phone() );
		$destination_postal = $this->normalize_postal_code( (string) $order->get_shipping_postcode() );

		if ( '' === $destination_postal ) {
			throw new RuntimeException( __( 'Order is missing a shipping postal code.', 'woo-logistics-plugin' ) );
		}

		$xml = new SimpleXMLElement( '<non-contract-shipment xmlns="http://www.canadapost.ca/ws/ncshipment-v4"></non-contract-shipment>' );
		$xml->addChild( 'requested-shipping-point', $this->normalize_postal_code( $this->required_option( WLP_Settings::OPTION_ORIGIN_POSTAL, __( 'Origin postal code', 'woo-logistics-plugin' ) ) ) );
		$delivery = $xml->addChild( 'delivery-spec' );
		$delivery->addChild( 'service-code', sanitize_text_field( $service_code ) );
		$references = $delivery->addChild( 'references' );
		$references->addChild( 'customer-ref-1', substr( (string) $order->get_order_number(), 0, 35 ) );

		if ( 'yes' === get_option( WLP_Settings::OPTION_NOTIFY, 'yes' ) && '' !== $order->get_billing_email() ) {
			$notification = $delivery->addChild( 'notification' );
			$notification->addChild( 'email', sanitize_email( $order->get_billing_email() ) );
			$notification->addChild( 'on-shipment', 'true' );
			$notification->addChild( 'on-exception', 'true' );
			$notification->addChild( 'on-delivery', 'true' );
		}

		$sender = $delivery->addChild( 'sender' );
		$sender->addChild( 'name', $origin_name );
		$sender->addChild( 'company', $sender_company );
		$sender->addChild( 'contact-phone', $origin_phone );
		$sender_address = $sender->addChild( 'address-details' );
		$sender_address->addChild( 'address-line-1', $this->required_option( WLP_Settings::OPTION_ORIGIN_ADDR_1, __( 'Origin address line 1', 'woo-logistics-plugin' ) ) );
		if ( '' !== $this->option( WLP_Settings::OPTION_ORIGIN_ADDR_2 ) ) {
			$sender_address->addChild( 'address-line-2', $this->option( WLP_Settings::OPTION_ORIGIN_ADDR_2 ) );
		}
		$sender_address->addChild( 'city', $this->required_option( WLP_Settings::OPTION_ORIGIN_CITY, __( 'Origin city', 'woo-logistics-plugin' ) ) );
		$sender_address->addChild( 'prov-state', $this->normalize_province( $this->required_option( WLP_Settings::OPTION_ORIGIN_PROV, __( 'Origin province', 'woo-logistics-plugin' ) ) ) );
		$sender_address->addChild( 'postal-zip-code', $this->normalize_postal_code( $this->required_option( WLP_Settings::OPTION_ORIGIN_POSTAL, __( 'Origin postal code', 'woo-logistics-plugin' ) ) ) );

		$destination = $delivery->addChild( 'destination' );
		$destination->addChild( 'name', $this->destination_name( $order ) );
		$destination->addChild( 'company', (string) $order->get_shipping_company() );
		if ( '' !== $destination_phone ) {
			$destination->addChild( 'client-voice-number', $destination_phone );
		}
		$destination_address = $destination->addChild( 'address-details' );
		$destination_address->addChild( 'address-line-1', (string) $order->get_shipping_address_1() );
		if ( '' !== trim( (string) $order->get_shipping_address_2() ) ) {
			$destination_address->addChild( 'address-line-2', (string) $order->get_shipping_address_2() );
		}
		$destination_address->addChild( 'city', (string) $order->get_shipping_city() );
		$destination_address->addChild( 'prov-state', $this->normalize_province( (string) $order->get_shipping_state() ) );
		$destination_address->addChild( 'country-code', 'CA' );
		$destination_address->addChild( 'postal-zip-code', $destination_postal );

		$parcel = $delivery->addChild( 'parcel-characteristics' );
		$parcel->addChild( 'weight', $this->parcel_weight( $order, $preset ) );
		$dimensions = $parcel->addChild( 'dimensions' );
		$dimensions->addChild( 'length', (string) $preset['length'] );
		$dimensions->addChild( 'width', (string) $preset['width'] );
		$dimensions->addChild( 'height', (string) $preset['height'] );

		$this->add_signature_option( $delivery );

		$preferences = $delivery->addChild( 'preferences' );
		$preferences->addChild( 'show-packing-instructions', 'true' );

		return (string) $xml->asXML();
	}

	/**
	 * Adds Canada Post Signature option when enabled.
	 */
	private function add_signature_option( SimpleXMLElement $xml_parent ): void {
		if ( 'yes' !== $this->option( WLP_Settings::OPTION_SIGNATURE ) ) {
			return;
		}

		$options = $xml_parent->addChild( 'options' );
		$option  = $options->addChild( 'option' );
		$option->addChild( 'option-code', 'SO' );
	}

	/**
	 * Parses Canada Post rate XML.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function parse_rates( string $body ): array {
		$xml    = $this->xml( $body );
		$quotes = $xml->xpath( '//*[local-name()="price-quote"]' ) ?: array();
		$rates  = array();

		foreach ( $quotes as $quote ) {
			$rates[] = array(
				'service_code' => (string) ( $quote->xpath( './*[local-name()="service-code"]' )[0] ?? '' ),
				'service_name' => (string) ( $quote->xpath( './*[local-name()="service-name"]' )[0] ?? '' ),
				'due'          => (string) ( $quote->xpath( './/*[local-name()="due"]' )[0] ?? '' ),
			);
		}

		return $this->filter_and_sort_rates( $rates );
	}

	/**
	 * Applies configured service policy and display order to returned rates.
	 *
	 * @param array<int, array<string, string>> $rates Rates.
	 * @return array<int, array<string, string>>
	 */
	private function filter_and_sort_rates( array $rates ): array {
		$filtered = array_values(
			array_filter(
				$rates,
				static function ( array $rate ): bool {
					return ! WLP_Settings::hide_regular_parcel() || 'DOM.RP' !== ( $rate['service_code'] ?? '' );
				}
			)
		);

		usort(
			$filtered,
			function ( array $left, array $right ): int {
				return $this->service_sort_rank( (string) ( $left['service_code'] ?? '' ) ) <=> $this->service_sort_rank( (string) ( $right['service_code'] ?? '' ) );
			}
		);

		return $filtered;
	}

	/**
	 * Sorts Canada Post services as Regular, Xpresspost, Expedited, Priority.
	 */
	private function service_sort_rank( string $service_code ): int {
		$order = array(
			'DOM.RP' => 10,
			'DOM.XP' => 20,
			'DOM.EP' => 30,
			'DOM.PC' => 40,
		);

		return $order[ $service_code ] ?? 999;
	}

	/**
	 * Parses Canada Post shipment XML.
	 *
	 * @return array<string, string>
	 */
	private function parse_shipment( string $body ): array {
		$xml         = $this->xml( $body );
		$shipment_id = (string) ( $xml->xpath( '//*[local-name()="shipment-id"]' )[0] ?? '' );
		$tracking    = (string) ( $xml->xpath( '//*[local-name()="tracking-pin"]' )[0] ?? '' );
		$label_url   = '';

		foreach ( $xml->xpath( '//*[local-name()="link"]' ) ?: array() as $link ) {
			$attributes = $link->attributes();
			if ( isset( $attributes['rel'] ) && 'label' === (string) $attributes['rel'] ) {
				$label_url = (string) $attributes['href'];
				break;
			}
		}

		if ( '' === $shipment_id || '' === $tracking ) {
			throw new RuntimeException( __( 'Canada Post did not return the expected shipment identifiers.', 'woo-logistics-plugin' ) );
		}

		return array(
			'shipment_id'        => $shipment_id,
			'tracking_number'    => $tracking,
			'tracking_url'       => self::TRACKING_BASE . rawurlencode( $tracking ),
			'label_artifact_url' => $label_url,
		);
	}

	/**
	 * Parses XML safely.
	 */
	private function xml( string $body ): SimpleXMLElement {
		$xml = simplexml_load_string( $body );
		if ( ! $xml instanceof SimpleXMLElement ) {
			throw new RuntimeException( __( 'Canada Post returned invalid XML.', 'woo-logistics-plugin' ) );
		}

		return $xml;
	}

	/**
	 * Extracts a readable error from a Canada Post XML response.
	 */
	private function extract_error_message( string $body, string $fallback ): string {
		try {
			$xml         = $this->xml( $body );
			$description = (string) ( $xml->xpath( '//*[local-name()="description"]' )[0] ?? '' );
			return '' !== trim( $description ) ? trim( $description ) : $fallback;
		} catch ( RuntimeException $error ) {
			return $fallback;
		}
	}

	/**
	 * Returns the Canada Post API base URL.
	 */
	private function base_url(): string {
		return 'yes' === get_option( WLP_Settings::OPTION_SANDBOX, 'yes' ) ? 'https://ct.soa-gw.canadapost.ca' : 'https://soa-gw.canadapost.ca';
	}

	/**
	 * Builds the Canada Post Basic auth header.
	 */
	private function auth_header(): string {
		return 'Basic ' . base64_encode( $this->required_option( WLP_Settings::OPTION_API_USER, __( 'API user', 'woo-logistics-plugin' ) ) . ':' . $this->required_option( WLP_Settings::OPTION_API_PASSWORD, __( 'API password', 'woo-logistics-plugin' ) ) );
	}

	/**
	 * Reads a required settings value.
	 */
	private function required_option( string $option, string $label ): string {
		$value = $this->option( $option );
		if ( '' === $value ) {
			throw new RuntimeException(
				sprintf(
				/* translators: %s: Canada Post setting key. */
					__( 'Canada Post setting "%s" is required before rates or labels can be created.', 'woo-logistics-plugin' ),
					$label
				)
			);
		}

		return $value;
	}

	/**
	 * Reads a settings value.
	 */
	private function option( string $option ): string {
		$value = trim( (string) get_option( $option, '' ) );

		if ( '' !== $value ) {
			return $value;
		}

		$fallbacks = array(
			WLP_Settings::OPTION_SANDBOX        => array( 'WLP_CP_SANDBOX', 'CP_USE_SANDBOX' ),
			WLP_Settings::OPTION_API_USER       => array( 'WLP_CP_API_USER', 'CP_DEVELOPMENT_USER' ),
			WLP_Settings::OPTION_API_PASSWORD   => array( 'WLP_CP_API_PASSWORD', 'CP_DEVELOPMENT_PASSWORD' ),
			WLP_Settings::OPTION_CUSTOMER       => array( 'WLP_CP_CUSTOMER_NUMBER', 'CP_CUSTOMER_NUMBER' ),
			WLP_Settings::OPTION_ORIGIN_PHONE   => array( 'WLP_CP_ORIGIN_PHONE', 'CP_ORIGIN_PHONE_MEDUSA' ),
			WLP_Settings::OPTION_ORIGIN_NAME    => array( 'WLP_CP_ORIGIN_NAME' ),
			WLP_Settings::OPTION_ORIGIN_COMPANY => array( 'WLP_CP_ORIGIN_COMPANY' ),
			WLP_Settings::OPTION_ORIGIN_EMAIL   => array( 'WLP_CP_ORIGIN_EMAIL' ),
			WLP_Settings::OPTION_ORIGIN_ADDR_1  => array( 'WLP_CP_ORIGIN_ADDRESS_1' ),
			WLP_Settings::OPTION_ORIGIN_ADDR_2  => array( 'WLP_CP_ORIGIN_ADDRESS_2' ),
			WLP_Settings::OPTION_ORIGIN_CITY    => array( 'WLP_CP_ORIGIN_CITY' ),
			WLP_Settings::OPTION_ORIGIN_PROV    => array( 'WLP_CP_ORIGIN_PROVINCE' ),
			WLP_Settings::OPTION_ORIGIN_POSTAL  => array( 'WLP_CP_ORIGIN_POSTAL_CODE', 'CP_ORIGIN_POSTAL_CODE' ),
			WLP_Settings::OPTION_SIGNATURE      => array( 'WLP_CP_SIGNATURE_REQUIRED' ),
		);

		foreach ( $fallbacks[ $option ] ?? array() as $env_name ) {
			$env_value = getenv( $env_name );
			if ( false !== $env_value && '' !== trim( (string) $env_value ) ) {
				return trim( (string) $env_value );
			}
		}

		return '';
	}

	/**
	 * Ensures v1 only handles Canadian destinations.
	 */
	private function assert_canadian_destination( WC_Order $order ): void {
		if ( 'CA' !== strtoupper( (string) $order->get_shipping_country() ) ) {
			throw new RuntimeException( __( 'Woo Logistics Plugin v1 supports Canada domestic shipments only.', 'woo-logistics-plugin' ) );
		}
	}

	/**
	 * Returns the Canada Post parcel weight in kilograms.
	 *
	 * @param WC_Order                    $order WooCommerce order.
	 * @param array<string, float|string> $preset Package preset.
	 */
	private function parcel_weight( WC_Order $order, array $preset ): string {
		if ( ! WLP_Settings::use_product_weight() ) {
			return $this->format_weight( (float) $preset['weight'] );
		}

		$product_weight = $this->order_product_weight_kg( $order );
		$base_weight    = WLP_Settings::use_base_weight() ? WLP_Settings::base_weight_kg() : 0.0;

		if ( $product_weight > 0 ) {
			return $this->format_weight( $product_weight + $base_weight );
		}

		return $this->format_weight( (float) $preset['weight'] );
	}

	/**
	 * Sums shippable WooCommerce product weights in kilograms.
	 */
	private function order_product_weight_kg( WC_Order $order ): float {
		$total   = 0.0;
		$missing = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ( method_exists( $product, 'needs_shipping' ) && ! $product->needs_shipping() ) ) {
				continue;
			}

			$quantity = method_exists( $item, 'get_quantity' ) ? max( 0.0, (float) $item->get_quantity() ) : 1.0;
			$weight   = method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';

			if ( '' === trim( $weight ) || ! is_numeric( $weight ) || (float) $weight <= 0 ) {
				$missing[] = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : __( 'Unnamed product', 'woo-logistics-plugin' );
				continue;
			}

			$total += $this->weight_to_kg( (float) $weight ) * $quantity;
		}

		if ( ! empty( $missing ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: comma-separated product names. */
					__( 'Cannot calculate shipment weight because these products are missing weights: %s.', 'woo-logistics-plugin' ),
					implode( ', ', array_slice( $missing, 0, 5 ) )
				)
			);
		}

		return $total;
	}

	/**
	 * Converts a WooCommerce product weight to kilograms.
	 */
	private function weight_to_kg( float $weight ): float {
		if ( function_exists( 'wc_get_weight' ) ) {
			return (float) wc_get_weight( $weight, 'kg' );
		}

		$unit = strtolower( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
		switch ( $unit ) {
			case 'g':
				return $weight / 1000;
			case 'lbs':
				return $weight * 0.45359237;
			case 'oz':
				return $weight * 0.028349523125;
			default:
				return $weight;
		}
	}

	/**
	 * Formats kilograms for Canada Post XML.
	 */
	private function format_weight( float $weight ): string {
		return number_format( max( 0.001, $weight ), 3, '.', '' );
	}

	/**
	 * Normalizes a Canadian postal code for Canada Post.
	 */
	private function normalize_postal_code( string $postal_code ): string {
		return strtoupper( preg_replace( '/[^a-z0-9]/i', '', $postal_code ) ?? '' );
	}

	/**
	 * Normalizes a province to a two-letter code.
	 */
	private function normalize_province( string $province ): string {
		$compact = strtolower( preg_replace( '/[^a-z]/i', '', $province ) ?? '' );
		$map     = array(
			'alberta'                 => 'AB',
			'britishcolumbia'         => 'BC',
			'manitoba'                => 'MB',
			'newbrunswick'            => 'NB',
			'newfoundlandandlabrador' => 'NL',
			'northwestterritories'    => 'NT',
			'novascotia'              => 'NS',
			'nunavut'                 => 'NU',
			'ontario'                 => 'ON',
			'princeedwardisland'      => 'PE',
			'quebec'                  => 'QC',
			'saskatchewan'            => 'SK',
			'yukon'                   => 'YT',
		);

		return $map[ $compact ] ?? strtoupper( trim( $province ) );
	}

	/**
	 * Normalizes a North American phone number.
	 */
	private function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '1' ) ) {
			return substr( $digits, 1 );
		}

		return $digits;
	}

	/**
	 * Builds the recipient name for a shipment.
	 */
	private function destination_name( WC_Order $order ): string {
		$name = trim( (string) $order->get_formatted_shipping_full_name() );
		if ( '' !== $name ) {
			return $name;
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		return '' !== $name ? $name : __( 'Customer', 'woo-logistics-plugin' );
	}
}
