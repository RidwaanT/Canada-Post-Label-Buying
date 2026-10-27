<?php
/** Builds and parses Canada Post XML without carrier side effects. */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
trait WLP_Contract_XML {
	private const SHIP_NS = 'http://www.canadapost.ca/ws/shipment-v8';
	private const MAN_NS  = 'http://www.canadapost.ca/ws/manifest-v8';
	/** Validates commercial account and dispatch-point settings. */
	public static function config(): array {
		$customer = trim( (string) get_option( WLP_Settings::OPTION_CUSTOMER, '' ) );
		$contract = trim( (string) get_option( WLP_Settings::OPTION_CONTRACT, '' ) );
		if ( ! preg_match( '/^[0-9]{1,10}$/D', $customer ) || ! preg_match( '/^[0-9]{1,10}$/D', $contract ) ) {
			throw new RuntimeException( 'Save a valid customer number and agreement number in Logistics settings.' );
		}
		$pickup = 'yes' === get_option( WLP_Settings::OPTION_PICKUP, 'no' );
		$postal = preg_replace( '/\s+/', '', strtoupper( (string) get_option( $pickup ? WLP_Settings::OPTION_ORIGIN_POSTAL : WLP_Settings::OPTION_DEPOSIT_POSTAL, '' ) ) );
		if ( ! preg_match( '/^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/D', $postal ) ) {
			throw new RuntimeException( 'Set the drop-off post office postal code in Logistics settings, or select pickup at the origin address.' );
		}
		return array(
			'customer' => $customer,
			'contract' => str_pad( $contract, 10, '0', STR_PAD_LEFT ),
			'postal'   => $postal,
			'pickup'   => $pickup,
			'sandbox'  => ( new WLP_Canada_Post_Client() )->contract_is_sandbox(),
		);
	}
	private static function xml( string $text ): SimpleXMLElement {
		$old = libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $text, SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		if ( ! $xml ) {
			throw new RuntimeException( 'Invalid Canada Post XML response.' );
		} return $xml;
	}
	private static function links( string $body, string $rel ): array {
		$out = array();
		foreach ( self::xml( $body )->xpath( '//*[local-name()="link"]' ) ?: array() as $l ) {
			if ( (string) $l['rel'] === $rel ) {
				$out[] = (string) $l['href'];
			}
		}return $out;
	}
	private static function copy_node( DOMDocument $doc, DOMElement $source ): DOMElement {
		$out = $doc->createElementNS( self::SHIP_NS, $source->localName );
		foreach ( $source->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$out->appendChild( self::copy_node( $doc, $child ) );
			} elseif ( $child instanceof DOMText ) {
				$out->appendChild( $doc->createTextNode( $child->nodeValue ) );
			}
		}
		return $out;
	}
	/** Converts shared domestic delivery data to the contract shipment schema. */
	public static function shipment_xml( string $legacy, array $config, string $group, string $request ): string {
		$old = new DOMDocument();
		if ( ! $old->loadXML( $legacy, LIBXML_NONET ) ) {
			throw new RuntimeException( 'Invalid shipment data.' );
		}
		$doc  = new DOMDocument( '1.0', 'UTF-8' );
		$root = $doc->createElementNS( self::SHIP_NS, 'shipment' );
		$doc->appendChild( $root );
		foreach ( array(
			'customer-request-id'      => $request,
			'group-id'                 => $group,
			'requested-shipping-point' => $config['postal'],
		) as $k => $v ) {
			$e = $doc->createElementNS( self::SHIP_NS, $k );
			$e->appendChild( $doc->createTextNode( $v ) );
			$root->appendChild( $e );}
		if ( $config['pickup'] ) {
			$root->appendChild( $doc->createElementNS( self::SHIP_NS, 'cpc-pickup-indicator', 'true' ) );
		}
		$node = $old->getElementsByTagName( 'delivery-spec' )->item( 0 );
		if ( ! $node instanceof DOMElement ) {
			throw new RuntimeException( 'Missing delivery details.' );
		}
		$delivery = self::copy_node( $doc, $node );
		$root->appendChild( $delivery );
		$sender  = $delivery->getElementsByTagNameNS( self::SHIP_NS, 'sender' )->item( 0 );
		$address = $sender?->getElementsByTagNameNS( self::SHIP_NS, 'address-details' )->item( 0 );
		if ( $address && ! $address->getElementsByTagNameNS( self::SHIP_NS, 'country-code' )->length ) {
			$address->appendChild( $doc->createElementNS( self::SHIP_NS, 'country-code', 'CA' ) );
		}
		$print = $doc->createElementNS( self::SHIP_NS, 'print-preferences' );
		$print->appendChild( $doc->createElementNS( self::SHIP_NS, 'output-format', '4x6' ) );
		$print->appendChild( $doc->createElementNS( self::SHIP_NS, 'encoding', 'PDF' ) );
		$delivery->appendChild( $print );
		$settle = $doc->createElementNS( self::SHIP_NS, 'settlement-info' );
		$settle->appendChild( $doc->createElementNS( self::SHIP_NS, 'contract-id', $config['contract'] ) );
		$settle->appendChild( $doc->createElementNS( self::SHIP_NS, 'intended-method-of-payment', 'Account' ) );
		$delivery->appendChild( $settle );
		return (string) $doc->saveXML();
	}
	/** Builds an explicit transmission with held shipments excluded. */
	public static function transmit_xml( array $batch, string $group, ?array $handoff = null ): string {
		$cfg    = $handoff ?? $batch['config'];
		$x      = new SimpleXMLElement( '<transmit-set xmlns="' . self::MAN_NS . '"/>' );
		$groups = $x->addChild( 'group-ids' );
		$groups->addChild( 'group-id', $group );
		$x->addChild( 'requested-shipping-point', $cfg['postal'] );
		if ( $cfg['pickup'] ) {
			$x->addChild( 'cpc-pickup-indicator', 'true' );
		}$x->addChild( 'method-of-payment', 'Account' );
		$x->addChild( 'detailed-manifests', 'true' );
		$a      = $x->addChild( 'manifest-address' );
		$fields = array(
			'manifest-name'    => WLP_Settings::OPTION_ORIGIN_NAME,
			'manifest-company' => WLP_Settings::OPTION_ORIGIN_COMPANY,
			'phone-number'     => WLP_Settings::OPTION_ORIGIN_PHONE,
		);
		foreach ( $fields as $k => $v ) {
			$value = trim( (string) get_option( $v, '' ) );
			if ( ! $value ) {
				throw new RuntimeException( 'Complete the sender contact details in Logistics settings.' );
			}$a->addChild( $k, htmlspecialchars( $value, ENT_XML1, 'UTF-8' ) );}
		$addr = $a->addChild( 'address-details' );
		foreach ( array(
			'address-line-1'  => WLP_Settings::OPTION_ORIGIN_ADDR_1,
			'address-line-2'  => WLP_Settings::OPTION_ORIGIN_ADDR_2,
			'city'            => WLP_Settings::OPTION_ORIGIN_CITY,
			'prov-state'      => WLP_Settings::OPTION_ORIGIN_PROV,
			'postal-zip-code' => WLP_Settings::OPTION_ORIGIN_POSTAL,
		) as $k => $v ) {
			$value = trim( (string) get_option( $v, '' ) );
			if ( $k === 'postal-zip-code' ) {
				$value = preg_replace( '/\s+/', '', strtoupper( $value ) );
			}if ( ! $value && $k !== 'address-line-2' ) {
				throw new RuntimeException( 'Complete the sender address in Logistics settings.' );
			}if ( $value ) {
				$addr->addChild( $k, htmlspecialchars( $value, ENT_XML1, 'UTF-8' ) );
			}
		}$addr->addChild( 'country-code', 'CA' );
		if ( ! empty( $batch['excluded'] ) ) {
			$excluded = $x->addChild( 'excluded-shipments' );
			foreach ( $batch['excluded'] as $id ) {
				$excluded->addChild( 'shipment-id', htmlspecialchars( $id, ENT_XML1, 'UTF-8' ) );
			}
		}return (string) $x->asXML();
	}
}
