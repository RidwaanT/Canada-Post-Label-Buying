<?php

/**
 * Purpose: Probe Canada Post sandbox rating without booting WordPress.
 *
 * Usage:
 * CP_DEVELOPMENT_USER=... CP_DEVELOPMENT_PASSWORD=... CP_CUSTOMER_NUMBER=... php scripts/canada-post-sandbox-probe.php
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

$user = getenv('CP_DEVELOPMENT_USER') ?: getenv('WLP_CP_API_USER') ?: '';
$password = getenv('CP_DEVELOPMENT_PASSWORD') ?: getenv('WLP_CP_API_PASSWORD') ?: '';
$customer_number = getenv('CP_CUSTOMER_NUMBER') ?: getenv('WLP_CP_CUSTOMER_NUMBER') ?: '';
$origin_postal_code = getenv('CP_ORIGIN_POSTAL_CODE') ?: getenv('WLP_CP_ORIGIN_POSTAL_CODE') ?: 'M5V3L9';
$destination_postal_code = getenv('CP_DESTINATION_POSTAL_CODE') ?: getenv('WLP_CP_DESTINATION_POSTAL_CODE') ?: 'K1A0B1';
$buy_label = 'yes' === strtolower((string) (getenv('WLP_PROBE_BUY_LABEL') ?: 'no'));

foreach (
    array(
        'CP_DEVELOPMENT_USER or WLP_CP_API_USER' => $user,
        'CP_DEVELOPMENT_PASSWORD or WLP_CP_API_PASSWORD' => $password,
        'CP_CUSTOMER_NUMBER or WLP_CP_CUSTOMER_NUMBER' => $customer_number,
    ) as $name => $value
) {
    if ('' === trim($value)) {
        fwrite(STDERR, 'Missing required environment value: ' . $name . PHP_EOL);
        exit(1);
    }
}

$xml = new SimpleXMLElement('<mailing-scenario xmlns="http://www.canadapost.ca/ws/ship/rate-v4"></mailing-scenario>');
$xml->addChild('customer-number', normalize_postal_value($customer_number));
$parcel = $xml->addChild('parcel-characteristics');
$parcel->addChild('weight', '0.5');
$dimensions = $parcel->addChild('dimensions');
$dimensions->addChild('length', '17');
$dimensions->addChild('width', '11');
$dimensions->addChild('height', '6');
$xml->addChild('origin-postal-code', normalize_postal_code($origin_postal_code));
$destination = $xml->addChild('destination');
$domestic = $destination->addChild('domestic');
$domestic->addChild('postal-code', normalize_postal_code($destination_postal_code));

$response = request_canada_post(
    'https://ct.soa-gw.canadapost.ca/rs/ship/price',
    (string) $xml->asXML(),
    $user,
    $password
);

echo 'Canada Post sandbox rate probe' . PHP_EOL;
echo 'HTTP status: ' . $response['status'] . PHP_EOL;

if ($response['status'] < 200 || $response['status'] >= 300) {
    echo 'Error: ' . extract_error_message($response['body']) . PHP_EOL;
    exit(1);
}

$rates = parse_rates($response['body']);
echo 'Rates returned: ' . count($rates) . PHP_EOL;

foreach ($rates as $rate) {
    $delivery = '';
    if ($rate['expected_delivery_date'] || $rate['expected_transit_time']) {
        $delivery = ' transit ' . ($rate['expected_transit_time'] ? $rate['expected_transit_time'] . ' business days' : 'unavailable');
        $delivery .= $rate['expected_delivery_date'] ? ' expected ' . $rate['expected_delivery_date'] : '';
    }
    echo '- ' . $rate['service_code'] . ' ' . $rate['service_name'] . ' ' . $rate['due'] . $delivery . PHP_EOL;
}

if (! $buy_label) {
    echo 'Label purchase probe skipped. Set WLP_PROBE_BUY_LABEL=yes to buy a sandbox label.' . PHP_EOL;
    exit(0);
}

$service_code = getenv('WLP_PROBE_SERVICE_CODE') ?: ($rates[0]['service_code'] ?? 'DOM.RP');
$shipment_response = request_canada_post(
    'https://ct.soa-gw.canadapost.ca/rs/' . rawurlencode($customer_number) . '/ncshipment',
    build_shipment_xml($customer_number, $origin_postal_code, $destination_postal_code, $service_code),
    $user,
    $password,
    'application/vnd.cpc.ncshipment-v4+xml'
);

echo 'Shipment HTTP status: ' . $shipment_response['status'] . PHP_EOL;

if ($shipment_response['status'] < 200 || $shipment_response['status'] >= 300) {
    echo 'Shipment error: ' . extract_error_message($shipment_response['body']) . PHP_EOL;
    exit(1);
}

$shipment = parse_shipment($shipment_response['body']);
echo 'Shipment id: ' . $shipment['shipment_id'] . PHP_EOL;
echo 'Tracking number: ' . $shipment['tracking_number'] . PHP_EOL;
echo 'Label artifact present: ' . ('' !== $shipment['label_artifact_url'] ? 'yes' : 'no') . PHP_EOL;

/**
 * Sends an XML request to Canada Post.
 *
 * @return array{status: int, body: string}
 */
function request_canada_post(string $url, string $xml, string $user, string $password, string $content_type = 'application/vnd.cpc.ship.rate-v4+xml'): array
{
    $last = array(
        'status' => 0,
        'body' => '',
    );

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $context = stream_context_create(
            array(
                'http' => array(
                    'method' => 'POST',
                    'header' => implode(
                        "\r\n",
                        array(
                            'Authorization: Basic ' . base64_encode($user . ':' . $password),
                            'Content-Type: ' . $content_type,
                            'Accept: ' . $content_type,
                            'Accept-Language: en-CA',
                        )
                    ),
                    'content' => $xml,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ),
            )
        );

        $body = file_get_contents($url, false, $context);
        $headers = $http_response_header ?? array();
        $status = 0;

        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $status = (int) $matches[1];
        }

        $last = array(
            'status' => $status,
            'body' => false === $body ? '' : $body,
        );

        if ($status >= 200 && $status < 300) {
            return $last;
        }

        if (! str_contains(strtolower(extract_error_message($last['body'])), 'rejected by slm monitor')) {
            return $last;
        }

        usleep(1500 * $attempt * 1000);
    }

    return $last;
}

/**
 * Builds a sandbox non-contract shipment XML payload.
 */
function build_shipment_xml(string $customer_number, string $origin_postal_code, string $destination_postal_code, string $service_code): string
{
    unset($customer_number);

    $origin_phone = normalize_phone((string) (getenv('CP_ORIGIN_PHONE_MEDUSA') ?: getenv('WLP_CP_ORIGIN_PHONE') ?: '4165550100'));
    if ('' === $origin_phone) {
        fwrite(STDERR, 'Missing valid origin phone for label purchase probe.' . PHP_EOL);
        exit(1);
    }

    $xml = new SimpleXMLElement('<non-contract-shipment xmlns="http://www.canadapost.ca/ws/ncshipment-v4"></non-contract-shipment>');
    $xml->addChild('requested-shipping-point', normalize_postal_code($origin_postal_code));
    $delivery = $xml->addChild('delivery-spec');
    $delivery->addChild('service-code', $service_code);
    $references = $delivery->addChild('references');
    $references->addChild('customer-ref-1', 'WLP sandbox probe');

    $sender = $delivery->addChild('sender');
    $sender->addChild('name', getenv('WLP_CP_ORIGIN_NAME') ?: 'Sandbox Sender');
    $sender->addChild('company', getenv('WLP_CP_ORIGIN_COMPANY') ?: 'Sandbox Lab');
    $sender->addChild('contact-phone', $origin_phone);
    $sender_address = $sender->addChild('address-details');
    $sender_address->addChild('address-line-1', getenv('WLP_CP_ORIGIN_ADDRESS_1') ?: '1 Sandbox Street');
    $sender_address->addChild('city', getenv('WLP_CP_ORIGIN_CITY') ?: 'Toronto');
    $sender_address->addChild('prov-state', getenv('WLP_CP_ORIGIN_PROVINCE') ?: 'ON');
    $sender_address->addChild('postal-zip-code', normalize_postal_code($origin_postal_code));

    $destination = $delivery->addChild('destination');
    $destination->addChild('name', 'Sandbox Recipient');
    $destination->addChild('company', 'Sandbox Lab');
    $destination->addChild('client-voice-number', '6135550100');
    $destination_address = $destination->addChild('address-details');
    $destination_address->addChild('address-line-1', '1 Wellington Street');
    $destination_address->addChild('city', 'Ottawa');
    $destination_address->addChild('prov-state', 'ON');
    $destination_address->addChild('country-code', 'CA');
    $destination_address->addChild('postal-zip-code', normalize_postal_code($destination_postal_code));

    $parcel = $delivery->addChild('parcel-characteristics');
    $parcel->addChild('weight', '0.5');
    $dimensions = $parcel->addChild('dimensions');
    $dimensions->addChild('length', '17');
    $dimensions->addChild('width', '11');
    $dimensions->addChild('height', '6');

    $preferences = $delivery->addChild('preferences');
    $preferences->addChild('show-packing-instructions', 'true');

    return (string) $xml->asXML();
}

/**
 * Parses rate quotes from Canada Post XML.
 *
 * @return array<int, array{service_code: string, service_name: string, due: string, expected_delivery_date: string, expected_transit_time: string, guaranteed_transit_time: string, min_transit_time: string, max_transit_time: string}>
 */
function parse_rates(string $body): array
{
    $xml = simplexml_load_string($body);
    if (! $xml instanceof SimpleXMLElement) {
        return array();
    }

    $rates = array();
    foreach ($xml->xpath('//*[local-name()="price-quote"]') ?: array() as $quote) {
        $rates[] = array(
            'service_code' => (string) ($quote->xpath('./*[local-name()="service-code"]')[0] ?? ''),
            'service_name' => (string) ($quote->xpath('./*[local-name()="service-name"]')[0] ?? ''),
            'due' => (string) ($quote->xpath('.//*[local-name()="due"]')[0] ?? ''),
            'expected_delivery_date' => (string) ($quote->xpath('.//*[local-name()="service-standard"]/*[local-name()="expected-delivery-date"]')[0] ?? ''),
            'expected_transit_time' => (string) ($quote->xpath('.//*[local-name()="service-standard"]/*[local-name()="expected-transit-time"]')[0] ?? ''),
            'guaranteed_transit_time' => (string) ($quote->xpath('.//*[local-name()="service-standard"]/*[local-name()="guaranteed-transit-time"]')[0] ?? ''),
            'min_transit_time' => (string) ($quote->xpath('.//*[local-name()="service-standard"]/*[local-name()="min-transit-time"]')[0] ?? ''),
            'max_transit_time' => (string) ($quote->xpath('.//*[local-name()="service-standard"]/*[local-name()="max-transit-time"]')[0] ?? ''),
        );
    }

    return $rates;
}

/**
 * Parses shipment fields from Canada Post XML.
 *
 * @return array{shipment_id: string, tracking_number: string, label_artifact_url: string}
 */
function parse_shipment(string $body): array
{
    $xml = simplexml_load_string($body);
    if (! $xml instanceof SimpleXMLElement) {
        return array(
            'shipment_id' => '',
            'tracking_number' => '',
            'label_artifact_url' => '',
        );
    }

    $label_url = '';
    foreach ($xml->xpath('//*[local-name()="link"]') ?: array() as $link) {
        $attributes = $link->attributes();
        if (isset($attributes['rel']) && 'label' === (string) $attributes['rel']) {
            $label_url = (string) $attributes['href'];
            break;
        }
    }

    return array(
        'shipment_id' => (string) ($xml->xpath('//*[local-name()="shipment-id"]')[0] ?? ''),
        'tracking_number' => (string) ($xml->xpath('//*[local-name()="tracking-pin"]')[0] ?? ''),
        'label_artifact_url' => $label_url,
    );
}

/**
 * Extracts a readable error message from Canada Post XML.
 */
function extract_error_message(string $body): string
{
    $xml = simplexml_load_string($body);
    if (! $xml instanceof SimpleXMLElement) {
        return 'Canada Post returned an unreadable response.';
    }

    $description = (string) ($xml->xpath('//*[local-name()="description"]')[0] ?? '');

    return '' !== trim($description) ? trim($description) : 'Canada Post request failed.';
}

/**
 * Normalizes Canadian postal codes for Canada Post APIs.
 */
function normalize_postal_code(string $postal_code): string
{
    return strtoupper(preg_replace('/[^a-z0-9]/i', '', $postal_code) ?? '');
}

/**
 * Normalizes a North American phone number.
 */
function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (11 === strlen($digits) && str_starts_with($digits, '1')) {
        return substr($digits, 1);
    }

    return $digits;
}

/**
 * Returns a scalar value trimmed for XML text.
 */
function normalize_postal_value(string $value): string
{
    return trim($value);
}
