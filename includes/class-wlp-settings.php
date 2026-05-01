<?php
/**
 * Purpose: Owns Woo Logistics Plugin settings, defaults, and sanitizers.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings registry and helpers.
 */
final class WLP_Settings {
	public const OPTION_SANDBOX        = 'wlp_cp_sandbox';
	public const OPTION_API_USER       = 'wlp_cp_api_user';
	public const OPTION_API_PASSWORD   = 'wlp_cp_api_password';
	public const OPTION_CUSTOMER       = 'wlp_cp_customer_number';
	public const OPTION_ORIGIN_NAME    = 'wlp_cp_origin_name';
	public const OPTION_ORIGIN_COMPANY = 'wlp_cp_origin_company';
	public const OPTION_ORIGIN_EMAIL   = 'wlp_cp_origin_email';
	public const OPTION_ORIGIN_PHONE   = 'wlp_cp_origin_phone';
	public const OPTION_ORIGIN_ADDR_1  = 'wlp_cp_origin_address_1';
	public const OPTION_ORIGIN_ADDR_2  = 'wlp_cp_origin_address_2';
	public const OPTION_ORIGIN_CITY    = 'wlp_cp_origin_city';
	public const OPTION_ORIGIN_PROV    = 'wlp_cp_origin_province';
	public const OPTION_ORIGIN_POSTAL  = 'wlp_cp_origin_postal_code';
	public const OPTION_NOTIFY         = 'wlp_cp_customer_notifications';
	public const OPTION_PRESETS        = 'wlp_package_presets';
	public const OPTION_STATUSES       = 'wlp_eligible_statuses';

	/**
	 * Registers settings.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers setting definitions.
	 */
	public function register_settings(): void {
		foreach ( $this->text_options() as $option ) {
			register_setting( 'wlp_settings', $option, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		}

		register_setting( 'wlp_settings', self::OPTION_ORIGIN_PHONE, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_phone' ) ) );
		register_setting( 'wlp_settings', self::OPTION_ORIGIN_PROV, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_province' ) ) );
		register_setting( 'wlp_settings', self::OPTION_API_PASSWORD, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wlp_settings', self::OPTION_SANDBOX, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ) ) );
		register_setting( 'wlp_settings', self::OPTION_NOTIFY, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ) ) );
		register_setting( 'wlp_settings', self::OPTION_PRESETS, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_presets' ) ) );
		register_setting( 'wlp_settings', self::OPTION_STATUSES, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_statuses' ) ) );
	}

	/**
	 * Returns text option keys.
	 *
	 * @return array<int, string>
	 */
	private function text_options(): array {
		return array(
			self::OPTION_API_USER,
			self::OPTION_CUSTOMER,
			self::OPTION_ORIGIN_NAME,
			self::OPTION_ORIGIN_COMPANY,
			self::OPTION_ORIGIN_EMAIL,
			self::OPTION_ORIGIN_ADDR_1,
			self::OPTION_ORIGIN_ADDR_2,
			self::OPTION_ORIGIN_CITY,
			self::OPTION_ORIGIN_POSTAL,
		);
	}

	/**
	 * Returns default package presets.
	 *
	 * @return array<int, array<string, float|string>>
	 */
	public static function default_presets(): array {
		return array(
			array(
				'id'     => 'small-box',
				'name'   => 'Small Box (12x11x6 cm)',
				'weight' => 0.5,
				'length' => 12.0,
				'width'  => 11.0,
				'height' => 6.0,
			),
			array(
				'id'     => 'medium-box',
				'name'   => 'Medium Box (17x11x6 cm)',
				'weight' => 0.5,
				'length' => 17.0,
				'width'  => 11.0,
				'height' => 6.0,
			),
		);
	}

	/**
	 * Returns configured package presets.
	 *
	 * @return array<int, array<string, float|string>>
	 */
	public static function presets(): array {
		$value   = get_option( self::OPTION_PRESETS, array() );
		$presets = self::sanitize_presets( is_array( $value ) ? $value : array() );

		return $presets ?: self::default_presets();
	}

	/**
	 * Returns eligible statuses without wc- prefixes.
	 *
	 * @return array<int, string>
	 */
	public static function eligible_statuses(): array {
		$value    = get_option( self::OPTION_STATUSES, array( 'processing' ) );
		$statuses = self::sanitize_statuses( is_array( $value ) ? $value : array() );

		return $statuses ?: array( 'processing' );
	}

	/**
	 * Sanitizes checkbox values.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_checkbox( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * Sanitizes a North American phone number for Canada Post.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_phone( $value ): string {
		$digits = preg_replace( '/\D+/', '', (string) $value ) ?? '';
		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '1' ) ) {
			$digits = substr( $digits, 1 );
		}

		return 10 === strlen( $digits ) ? $digits : sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitizes Canadian province and territory codes.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_province( $value ): string {
		$province = strtoupper( sanitize_text_field( (string) $value ) );

		return in_array( $province, array_keys( self::province_options() ), true ) ? $province : '';
	}

	/**
	 * Returns Canadian province and territory options.
	 *
	 * @return array<string, string>
	 */
	public static function province_options(): array {
		return array(
			'AB' => __( 'Alberta', 'woo-logistics-plugin' ),
			'BC' => __( 'British Columbia', 'woo-logistics-plugin' ),
			'MB' => __( 'Manitoba', 'woo-logistics-plugin' ),
			'NB' => __( 'New Brunswick', 'woo-logistics-plugin' ),
			'NL' => __( 'Newfoundland and Labrador', 'woo-logistics-plugin' ),
			'NS' => __( 'Nova Scotia', 'woo-logistics-plugin' ),
			'NT' => __( 'Northwest Territories', 'woo-logistics-plugin' ),
			'NU' => __( 'Nunavut', 'woo-logistics-plugin' ),
			'ON' => __( 'Ontario', 'woo-logistics-plugin' ),
			'PE' => __( 'Prince Edward Island', 'woo-logistics-plugin' ),
			'QC' => __( 'Quebec', 'woo-logistics-plugin' ),
			'SK' => __( 'Saskatchewan', 'woo-logistics-plugin' ),
			'YT' => __( 'Yukon', 'woo-logistics-plugin' ),
		);
	}

	/**
	 * Sanitizes eligible statuses.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public static function sanitize_statuses( $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'processing' );
		}

		$allowed = array_keys( wc_get_order_statuses() );
		$allowed = array_map(
			static function ( string $status ): string {
				return preg_replace( '/^wc-/', '', $status ) ?: $status;
			},
			$allowed
		);

		$statuses = array();
		foreach ( $value as $status ) {
			$normalized = sanitize_key( (string) $status );
			$normalized = preg_replace( '/^wc-/', '', $normalized ) ?: $normalized;
			if ( in_array( $normalized, $allowed, true ) ) {
				$statuses[] = $normalized;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Sanitizes package presets.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array<string, float|string>>
	 */
	public static function sanitize_presets( $value ): array {
		if ( ! is_array( $value ) ) {
			return self::default_presets();
		}

		$presets = array();
		foreach ( $value as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}

			$id     = sanitize_key( (string) ( $preset['id'] ?? '' ) );
			$name   = sanitize_text_field( (string) ( $preset['name'] ?? '' ) );
			$weight = self::positive_float( $preset['weight'] ?? null );
			$length = self::positive_float( $preset['length'] ?? null );
			$width  = self::positive_float( $preset['width'] ?? null );
			$height = self::positive_float( $preset['height'] ?? null );

			if ( '' === $id || '' === $name || null === $weight || null === $length || null === $width || null === $height ) {
				continue;
			}

			$presets[] = array(
				'id'     => $id,
				'name'   => $name,
				'weight' => $weight,
				'length' => $length,
				'width'  => $width,
				'height' => $height,
			);
		}

		return $presets ?: self::default_presets();
	}

	/**
	 * Returns a positive float or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function positive_float( $value ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		return $number > 0 ? $number : null;
	}
}
