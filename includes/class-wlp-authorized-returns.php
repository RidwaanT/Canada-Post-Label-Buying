<?php
/** Authorized Returns v2; never included in outbound shipment manifests. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WLP_Authorized_Returns {
	public static function create( WLP_Canada_Post_Client $client, WC_Order $order, string $xml, string $service ): array {
		$customer = trim( (string) get_option( WLP_Settings::OPTION_CUSTOMER, '' ) );
		if ( ! preg_match( '/^[0-9]{1,10}$/D', $customer ) || '' === trim( (string) get_option( WLP_Settings::OPTION_CONTRACT, '' ) ) ) {
			throw new RuntimeException( 'Save a valid customer number and commercial agreement before creating a return.' );
		}
		$customer = str_pad( $customer, 10, '0', STR_PAD_LEFT );
		// Each explicitly replaced label starts a new generation. Uncertain generations never expire.
		$previous = (string) $order->get_meta( WLP_Meta_Keys::RETURN_TRACKING_NUMBER, true );
		$key = 'wlp_return_attempt_' . $order->get_id() . '_' . substr( hash( 'sha256', $previous ), 0, 20 );
		$fingerprint = hash( 'sha256', $xml . $customer . ( $client->contract_is_sandbox() ? 'test' : 'live' ) );
		$state = array( 'state' => 'creating', 'fingerprint' => $fingerprint, 'started_at' => gmdate( 'c' ) );
		if ( ! add_option( $key, $state, '', false ) ) {
			$saved = get_option( $key, array() );
			if ( ( $saved['state'] ?? '' ) !== 'created' || ( $saved['fingerprint'] ?? '' ) !== $fingerprint ) {
				throw new RuntimeException( 'This return request is in progress or needs Canada Post reconciliation. Do not retry with another label; check the carrier record first.' );
			}
			$result = $saved['result'];
		} else {
			try {
				$body = $client->contract_transport( 'POST', '/rs/' . $customer . '/' . $customer . '/authorizedreturn', $xml, 'application/vnd.cpc.authreturn-v2+xml' );
				$result = self::parse( $body, $client->contract_is_sandbox() );
			} catch ( Throwable $error ) {
				// Explicit validation/authentication rejection cannot have created a label.
				if ( in_array( $error->getCode(), array( 400, 401, 403, 404, 422 ), true ) ) { delete_option( $key ); }
				throw $error;
			}
			$state['state'] = 'created';
			$state['result'] = $result;
			if ( ! update_option( $key, $state, false ) ) {
				throw new RuntimeException( 'Canada Post accepted the return but local recovery could not be saved. Reconcile with Canada Post before creating another label.' );
			}
		}
		// Carrier artifacts expire after five days. Retain the PDF in a non-autoloaded,
		// private database option; existing authenticated/signed print routes serve it.
		$cache_key = 'wlp_return_pdf_' . hash( 'sha256', $result['label_artifact_url'] );
		if ( ! get_option( $cache_key, '' ) ) {
			$artifact = $client->download_artifact( $result['label_artifact_url'] );
			if ( ! str_starts_with( $artifact['body'], '%PDF-' ) ) {
				throw new RuntimeException( 'Return created, but Canada Post did not provide a valid PDF. Retry the same selection to recover it without creating another return.' );
			}
			$pdf = base64_encode( $artifact['body'] );
			update_option( $cache_key, $pdf, false );
			if ( get_option( $cache_key, '' ) !== $pdf ) {
				throw new RuntimeException( 'Return created but PDF retention failed. Retry the same selection to recover the existing label.' );
			}
		}
		return $result;
	}

	public static function parse( string $body, bool $sandbox ): array {
		if ( stripos( $body, '<!DOCTYPE' ) !== false ) { throw new RuntimeException( 'Unexpected return response.' ); }
		$previous = libxml_use_internal_errors( true );
		try { $xml = simplexml_load_string( $body, SimpleXMLElement::class, LIBXML_NONET ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		if ( ! $xml || $xml->getName() !== 'authorized-return-info' ) { throw new RuntimeException( 'Could not read the return response. Reconcile with Canada Post before retrying.' ); }
		$xml->registerXPathNamespace( 'r', 'http://www.canadapost.ca/ws/authreturn-v2' );
		$pin = (string) ( $xml->xpath( '/r:authorized-return-info/r:tracking-pin' )[0] ?? '' );
		$link = $xml->xpath( '/r:authorized-return-info/r:links/r:link[@rel="returnLabel"]' )[0] ?? null;
		$url = $link ? (string) $link['href'] : '';
		$host = $sandbox ? 'ct.soa-gw.canadapost.ca' : 'soa-gw.canadapost.ca';
		if ( ! preg_match( '/^[0-9]{12,16}$/D', $pin ) || ! preg_match( '~^https://' . preg_quote( $host, '~' ) . '/(?:ers|rs)/artifact/[a-zA-Z0-9/_.-]+$~D', $url ) || (string) ( $link['media-type'] ?? '' ) !== 'application/pdf' ) {
			throw new RuntimeException( 'Return response is missing valid tracking or a trusted PDF link. Reconcile with Canada Post before retrying.' );
		}
		return array(
			'tracking_number' => $pin,
			'tracking_url' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . rawurlencode( $pin ),
			'label_artifact_url' => $url,
			'shipment_id' => 'return:' . $pin,
		);
	}
}
