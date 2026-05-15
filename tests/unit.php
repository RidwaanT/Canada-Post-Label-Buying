<?php
/**
 * Purpose: Lightweight unit checks for pure plugin helpers without booting WordPress.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

/**
 * Minimal Woo order stub for metadata tests.
 */
class WC_Order {
	/**
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * @var array<int, mixed>
	 */
	private array $items = array();

	/**
	 * Reads metadata.
	 *
	 * @param string $key Meta key.
	 * @param bool   $single Single value flag.
	 * @return mixed
	 */
	public function get_meta(string $key, bool $single = true) {
		return $this->meta[$key] ?? '';
	}

	/**
	 * Writes metadata.
	 *
	 * @param string $key Meta key.
	 * @param mixed  $value Meta value.
	 */
	public function update_meta_data(string $key, $value): void {
		$this->meta[$key] = $value;
	}

	/**
	 * Persists metadata.
	 */
	public function save(): void {
	}

	/**
	 * Sets line items for tests.
	 *
	 * @param array<int, mixed> $items Line items.
	 */
	public function set_items(array $items): void {
		$this->items = $items;
	}

	/**
	 * Reads order items.
	 *
	 * @return array<int, mixed>
	 */
	public function get_items(string $type = ''): array {
		return $this->items;
	}
}

/**
 * Minimal Woo line item stub for weight tests.
 */
class WLP_Test_Order_Item {
	private WLP_Test_Product $product;
	private int $quantity;

	public function __construct(WLP_Test_Product $product, int $quantity) {
		$this->product  = $product;
		$this->quantity = $quantity;
	}

	public function get_product(): WLP_Test_Product {
		return $this->product;
	}

	public function get_quantity(): int {
		return $this->quantity;
	}
}

/**
 * Minimal Woo product stub for weight tests.
 */
class WLP_Test_Product {
	private string $name;
	private string $weight;
	private bool $needs_shipping;

	public function __construct(string $name, string $weight, bool $needs_shipping = true) {
		$this->name           = $name;
		$this->weight         = $weight;
		$this->needs_shipping = $needs_shipping;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_weight(): string {
		return $this->weight;
	}

	public function needs_shipping(): bool {
		return $this->needs_shipping;
	}
}

/**
 * Translates text.
 */
function __(string $value, string $domain = ''): string {
	return $value;
}

/**
 * Reads options.
 */
function get_option(string $key, $default = false) {
	global $wlp_test_options;

	return $wlp_test_options[$key] ?? $default;
}

/**
 * Converts Woo weights.
 */
function wc_get_weight(float $weight, string $to_unit): float {
	return $weight * 0.45359237;
}

/**
 * Sanitizes text.
 */
function sanitize_text_field(string $value): string {
	return trim($value);
}

/**
 * Sanitizes keys.
 */
function sanitize_key(string $value): string {
	return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $value) ?? '');
}

/**
 * Cleans Woo values.
 */
function wc_clean(string $value): string {
	return trim($value);
}

/**
 * Returns fake Woo statuses.
 *
 * @return array<string, string>
 */
function wc_get_order_statuses(): array {
	return array(
		'wc-pending'    => 'Pending payment',
		'wc-processing' => 'Processing',
		'wc-completed'  => 'Completed',
	);
}

require_once dirname(__DIR__) . '/includes/class-wlp-meta-keys.php';
require_once dirname(__DIR__) . '/includes/class-wlp-settings.php';
require_once dirname(__DIR__) . '/includes/class-wlp-order-logistics.php';
require_once dirname(__DIR__) . '/includes/class-wlp-canada-post-client.php';

$failures = array();
$wlp_test_options = array(
	WLP_Settings::OPTION_PRODUCT_WEIGHT => 'yes',
	WLP_Settings::OPTION_USE_BASE_WEIGHT => 'yes',
	WLP_Settings::OPTION_BASE_WEIGHT    => '0.05',
	WLP_Settings::OPTION_DEFAULT_SERVICE => 'DOM.XP',
	WLP_Settings::OPTION_HIDE_REGULAR   => 'no',
	WLP_Settings::OPTION_SIGNATURE      => 'no',
	WLP_Settings::OPTION_CUSTOMER       => '1234567',
	WLP_Settings::OPTION_ORIGIN_POSTAL  => 'M5V3L9',
	'woocommerce_weight_unit'           => 'lbs',
);

/**
 * Records an assertion failure.
 *
 * @param bool   $condition Assertion condition.
 * @param string $message Failure message.
 */
function wlp_assert(bool $condition, string $message): void {
	global $failures;

	if (! $condition) {
		$failures[] = $message;
	}
}

$presets = WLP_Settings::sanitize_presets(
	array(
		array('id' => 'Small Box!', 'name' => 'Small Box', 'weight' => '0.5', 'length' => '12', 'width' => '11', 'height' => '6'),
		array('id' => '', 'name' => 'Bad', 'weight' => '0', 'length' => '0', 'width' => '0', 'height' => '0'),
	)
);
wlp_assert(1 === count($presets), 'Expected one valid preset.');
wlp_assert('smallbox' === $presets[0]['id'], 'Expected sanitized preset id.');

$statuses = WLP_Settings::sanitize_statuses(array('wc-processing', 'completed', 'bad-status'));
wlp_assert(array('processing', 'completed') === $statuses, 'Expected statuses to be normalized and filtered.');
wlp_assert('DOM.XP' === WLP_Settings::sanitize_service_code('dom.xp'), 'Expected valid service code to be normalized.');
wlp_assert('' === WLP_Settings::sanitize_service_code('DOM.BAD'), 'Expected invalid service code to be rejected.');
wlp_assert('DOM.XP' === WLP_Settings::default_service_code(), 'Expected default service option readback.');
wlp_assert(array('', 'DOM.RP', 'DOM.XP', 'DOM.EP', 'DOM.PC') === array_keys(WLP_Settings::service_options()), 'Expected service options in label display order.');
wlp_assert(! WLP_Settings::signature_required(), 'Expected signature option to default off.');
wlp_assert('0' === WLP_Settings::sanitize_non_negative_float('0'), 'Expected zero base weight to be accepted.');
wlp_assert('' === WLP_Settings::sanitize_non_negative_float('-0.01'), 'Expected negative base weight to be rejected.');

$order = new WC_Order();
wlp_assert(! WLP_Order_Logistics::has_label($order), 'Order without metadata should not have a label.');
WLP_Order_Logistics::write_label(
	$order,
	array(
		'label_artifact_url' => 'https://example.com/label.pdf',
		'tracking_number'    => '123456789012',
		'service_code'       => 'DOM.XP',
		'preset_id'          => 'smallbox',
		'shipment_weight_kg' => '1.864',
	)
);
wlp_assert(WLP_Order_Logistics::has_label($order), 'Order with label metadata should have a label.');
wlp_assert('123456789012' === WLP_Order_Logistics::read($order)['tracking_number'], 'Expected tracking number readback.');
wlp_assert('smallbox' === WLP_Order_Logistics::read($order)['preset_id'], 'Expected preset id readback.');
wlp_assert('1.864' === WLP_Order_Logistics::read($order)['shipment_weight_kg'], 'Expected shipment weight readback.');

$weight_order = new WC_Order();
$weight_order->set_items(
	array(
		new WLP_Test_Order_Item(new WLP_Test_Product('Weighted product', '2'), 2),
		new WLP_Test_Order_Item(new WLP_Test_Product('Virtual product', '', false), 1),
	)
);
$client     = (new ReflectionClass(WLP_Canada_Post_Client::class))->newInstanceWithoutConstructor();
$method     = new ReflectionMethod(WLP_Canada_Post_Client::class, 'order_product_weight_kg');
$weight_kg  = $method->invoke($client, $weight_order);
$difference = abs($weight_kg - 1.81436948);
wlp_assert($difference < 0.00001, 'Expected product weights to convert from pounds to kilograms.');

$parcel_method = new ReflectionMethod(WLP_Canada_Post_Client::class, 'parcel_weight');
$parcel_weight = $parcel_method->invoke($client, $weight_order, array('weight' => 0.5));
wlp_assert('1.864' === $parcel_weight, 'Expected base package weight to be added to product weight.');
wlp_assert('1.864' === $client->shipment_weight($weight_order, array('weight' => 0.5)), 'Expected public shipment weight preview to match parcel weight.');

$empty_weight_order = new WC_Order();
$fallback_weight    = $parcel_method->invoke($client, $empty_weight_order, array('weight' => 0.5));
wlp_assert('0.500' === $fallback_weight, 'Expected preset weight fallback when no product lines exist.');

$wlp_test_options[WLP_Settings::OPTION_PRODUCT_WEIGHT] = 'no';
wlp_assert('0.750' === $client->shipment_weight($weight_order, array('weight' => 0.75)), 'Expected preset weight when product-weight mode is off.');
$wlp_test_options[WLP_Settings::OPTION_PRODUCT_WEIGHT] = 'yes';

$filter_method = new ReflectionMethod(WLP_Canada_Post_Client::class, 'filter_and_sort_rates');
$rates         = $filter_method->invoke(
	$client,
	array(
		array('service_code' => 'DOM.PC', 'service_name' => 'Priority', 'due' => '40.00'),
		array('service_code' => 'DOM.EP', 'service_name' => 'Expedited', 'due' => '20.00'),
		array('service_code' => 'DOM.RP', 'service_name' => 'Regular', 'due' => '15.00'),
		array('service_code' => 'DOM.XP', 'service_name' => 'Xpresspost', 'due' => '25.00'),
	)
);
wlp_assert(array('DOM.RP', 'DOM.XP', 'DOM.EP', 'DOM.PC') === array_column($rates, 'service_code'), 'Expected rates sorted by service display order.');

$wlp_test_options[WLP_Settings::OPTION_HIDE_REGULAR]    = 'yes';
$wlp_test_options[WLP_Settings::OPTION_DEFAULT_SERVICE] = 'DOM.RP';
wlp_assert(array('', 'DOM.XP', 'DOM.EP', 'DOM.PC') === array_keys(WLP_Settings::service_options()), 'Expected Regular Parcel hidden from service options.');
wlp_assert('' === WLP_Settings::default_service_code(), 'Expected hidden Regular Parcel default to fall back to cheapest.');
$filtered_rates = $filter_method->invoke($client, $rates);
wlp_assert(array('DOM.XP', 'DOM.EP', 'DOM.PC') === array_column($filtered_rates, 'service_code'), 'Expected Regular Parcel hidden from rates.');

$rate_xml_method = new ReflectionMethod(WLP_Canada_Post_Client::class, 'build_rate_xml');
$rate_xml        = (string) $rate_xml_method->invoke($client, $weight_order, 'K1A0B1', array('weight' => 0.5, 'length' => 12, 'width' => 11, 'height' => 6));
$rate_document   = new SimpleXMLElement($rate_xml);
$option_codes    = $rate_document->xpath('//*[local-name()="option-code"]') ?: array();
wlp_assert(0 === count($option_codes), 'Expected rate XML to omit signature when disabled.');

$wlp_test_options[WLP_Settings::OPTION_SIGNATURE] = 'yes';
wlp_assert(WLP_Settings::signature_required(), 'Expected signature option readback.');
$signed_rate_xml     = (string) $rate_xml_method->invoke($client, $weight_order, 'K1A0B1', array('weight' => 0.5, 'length' => 12, 'width' => 11, 'height' => 6));
$signed_rate_document = new SimpleXMLElement($signed_rate_xml);
$signed_option_codes = $signed_rate_document->xpath('//*[local-name()="option-code"]') ?: array();
wlp_assert(1 === count($signed_option_codes) && 'SO' === (string) $signed_option_codes[0], 'Expected rate XML to include Canada Post signature option SO.');

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
	}
	exit(1);
}

echo 'Unit checks passed.' . PHP_EOL;
