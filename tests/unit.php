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

$failures = array();

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

$order = new WC_Order();
wlp_assert(! WLP_Order_Logistics::has_label($order), 'Order without metadata should not have a label.');
WLP_Order_Logistics::write_label(
	$order,
	array(
		'label_artifact_url' => 'https://example.com/label.pdf',
		'tracking_number'    => '123456789012',
		'service_code'       => 'DOM.XP',
	)
);
wlp_assert(WLP_Order_Logistics::has_label($order), 'Order with label metadata should have a label.');
wlp_assert('123456789012' === WLP_Order_Logistics::read($order)['tracking_number'], 'Expected tracking number readback.');

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
	}
	exit(1);
}

echo 'Unit checks passed.' . PHP_EOL;
