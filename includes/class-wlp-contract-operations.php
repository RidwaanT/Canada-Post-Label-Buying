<?php
/** Durable carrier operations. Persist intent before mutations; never retry uncertain writes. */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

trait WLP_Contract_Operations {
	private const STORE = 'wlp_contract_batches_v1';
	private const LOCK  = 'wlp_contract_operation_lock';

	private static function lock(): void {
		if ( ! add_option( self::LOCK, gmdate( 'c' ), '', 'no' ) ) {
			throw new RuntimeException( 'Another carrier operation is running or was interrupted. Wait, then check Shipping manifests before retrying.' );
		}
	}
	private static function read(): array {
		$v = get_option( self::STORE, array() );
		return is_array( $v ) ? $v : array(); }
	private static function save( array $all ): void {
		update_option( self::STORE, $all, false );
		if ( get_option( self::STORE ) !== $all ) {
			throw new RuntimeException( 'Could not save carrier state. Stop and reconcile before retrying.' );
		}
	}
	private static function same_account( array $b ): void {
		$cfg = self::config();
		if ( $b['config']['customer'] !== $cfg['customer'] || $b['config']['sandbox'] !== $cfg['sandbox'] ) {
			throw new RuntimeException( 'Restore this batch’s original carrier account and environment.' );
		}
	}
	private static function unresolved( string $state ): bool {
		return in_array( $state, array( 'requesting', 'uncertain', 'voiding', 'void_uncertain' ), true );}
	private static function result( WLP_Canada_Post_Client $c, string $body ): array {
		$r = $c->contract_parse_shipment( $body );
		if ( empty( $r['shipment_id'] ) || empty( $r['label_artifact_url'] ) || empty( $r['tracking_number'] ) ) {
			throw new RuntimeException( 'Carrier shipment details are incomplete. Recover the saved request before retrying.' );
		}
		$r['self_url'] = self::links( $body, 'self' )[0] ?? '';
		return $r;
	}

	/** Creates once per physical package, or returns its durable previous result. */
	public static function create( WLP_Canada_Post_Client $client, WC_Order $order, string $legacy, array $context = array() ): array {
		$cfg     = self::config();
		$package = $context['package'] ?? ( 'primary:' . (int) $order->get_meta( '_wlp_contract_generation', true ) );
		$day     = $context['day'] ?? wp_date( 'Y-m-d' );
		$date    = DateTimeImmutable::createFromFormat( '!Y-m-d', $day );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $day ) {
			throw new RuntimeException( 'Invalid dispatch date.' );
		}
		$fingerprint = hash( 'sha256', $legacy . '|' . $day . '|' . wp_json_encode( $cfg ) );
		self::lock();try {
			$all = self::read();
			$key = '';
			foreach ( $all as $id => $b ) {
				foreach ( $b['shipments'] as $s ) {
					if ( (int) $s['order'] !== $order->get_id() ) {
						continue;
					}
					if ( self::unresolved( $s['state'] ) ) {
						throw new RuntimeException( 'An earlier carrier request needs recovery in Shipping manifests. No new label was purchased.' );
					}
					// Older records lack package identity: require explicit reconciliation instead of guessing.
					if ( ! isset( $s['package'] ) && $s['state'] === 'created' && $b['state'] !== 'manifest_ready' ) {
						throw new RuntimeException( 'Complete or void this order’s earlier contract label in Shipping manifests first.' );
					}
					if ( ( $s['package'] ?? '' ) !== $package || in_array( $s['state'], array( 'rejected', 'voided' ), true ) ) {
						continue;
					}
					if ( ( $s['fingerprint'] ?? '' ) !== $fingerprint ) {
						throw new RuntimeException( 'A label already exists for this package with different details. Reprint it, or void it before making changes.' );
					}
					if ( ! empty( $s['result'] ) ) {
						return $s['result'];
					}
				}
				if ( $b['state'] === 'open' && $b['day'] === $day && $b['config'] === $cfg ) {
					$key = $id;
				}
			}
			if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
				throw new RuntimeException( 'This order is not eligible for a new shipping label.' );
			}
			if ( ! $key ) {
				$key         = 'WOO-' . str_replace( '-', '', $day ) . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
				$all[ $key ] = array(
					'day'       => $day,
					'config'    => $cfg,
					'state'     => 'open',
					'shipments' => array(),
					'manifests' => array(),
				);}
			$rid                              = 'WOO' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 32 );
			$xml                              = self::shipment_xml( $legacy, $cfg, $key, $rid );
			$all[ $key ]['shipments'][ $rid ] = array(
				'order'       => $order->get_id(),
				'package'     => $package,
				'fingerprint' => $fingerprint,
				'state'       => 'requesting',
				'created'     => gmdate( 'c' ),
				'context'     => $context,
			);
			self::save( $all );
			try {
				$body   = $client->contract_transport( 'POST', '/rs/' . $cfg['customer'] . '/' . $cfg['customer'] . '/shipment', $xml );
				$result = self::result( $client, $body );
			} catch ( Throwable $e ) {
				$all[ $key ]['shipments'][ $rid ]['state'] = in_array( $e->getCode(), array( 400, 401, 403, 404, 422 ), true ) ? 'rejected' : 'uncertain';
				self::save( $all );
				throw $e;}
			$all[ $key ]['shipments'][ $rid ]['state']  = 'created';
			$all[ $key ]['shipments'][ $rid ]['result'] = $result;
			self::save( $all );
			return $result;
		} finally {
			delete_option( self::LOCK );}
	}

	/** Returns why a label must be held; empty means its current order/package matches. */
	public static function hold_reason( array $s ): string {
		$o = wc_get_order( $s['order'] );
		if ( ! $o instanceof WC_Order ) {
			return 'Order unavailable';
		}
		if ( $o->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return 'Order cancelled, refunded or failed';
		}
		$id      = (string) ( $s['result']['shipment_id'] ?? '' );
		$package = $s['package'] ?? 'primary:0';
		if ( str_starts_with( $package, 'split:' ) ) {
			$data = $o->get_meta( '_wlp_split_shipments', true );
			if ( ! is_array( $data ) ) {
				return 'Package no longer exists';
			}
			foreach ( ( $data['packages'] ?? array() ) as $p ) {
				if ( 'split:' . $p['id'] === $package ) {
					return ( $p['label']['shipment_id'] ?? '' ) === $id ? '' : 'Restore the saved label to this package';
				}
			}
			return 'Package no longer exists';
		}
		return WLP_Order_Logistics::read( $o )['shipment_id'] === $id ? '' : 'Restore the saved label, or void the superseded label';
	}

	/** Transmits exactly the explicitly selected ready parcels, holding all others. */
	public static function transmit( string $key, array $selected, ?string $handoff_id = null, bool $confirm_now = true ): void {
		self::lock();try {
			$all = self::read();
			$b   = $all[ $key ] ?? null;
			if ( ! $b || $b['state'] !== 'open' ) {
				throw new RuntimeException( 'This batch is not open. Recover or refresh the existing transmission.' );
			}
			$cfg = self::config();
			foreach ( array( 'customer', 'contract', 'sandbox' ) as $field ) {
				if ( $b['config'][ $field ] !== $cfg[ $field ] ) {
					throw new RuntimeException( 'Restore this batch’s original carrier account, agreement and environment.' );
				}
			}
			// Legacy callers retain the original point; the admin form requires an explicit choice.
			$handoff = $handoff_id === null
				? array_intersect_key( $b['config'], array_flip( array( 'postal', 'pickup' ) ) )
				: WLP_Contract_Handoff::resolve( $handoff_id );
			$selected = array_values( array_unique( $selected ) );
			if ( ! $selected ) {
				throw new RuntimeException( 'Select the parcels that are ready for handoff.' );
			}
			$excluded = array();
			foreach ( $b['shipments'] as $rid => $s ) {
				if ( self::unresolved( $s['state'] ) ) {
					throw new RuntimeException( 'Resolve every uncertain label request before transmitting this group.' );
				}
				if ( $s['state'] === 'created' && ! in_array( $rid, $selected, true ) ) {
					$excluded[] = $s['result']['shipment_id'];
				}
			}
			foreach ( $selected as $rid ) {
				$s = $b['shipments'][ $rid ] ?? null;
				if ( ! $s || $s['state'] !== 'created' ) {
					throw new RuntimeException( 'Selection changed. Reload Shipping manifests.' );
				}$reason = self::hold_reason( $s );
				if ( $reason ) {
					throw new RuntimeException( $reason );
				}
			}
			// Verify selected identities before a carrier POST, including externally transmitted labels.
			foreach ($selected as $rid) {
				$result = $b['shipments'][$rid]['result'];
				$verified = WLP_Manifest_Verifier::fetch($cfg, (string)$result['shipment_id'], (string)$result['tracking_number']);
				if ($verified['state'] !== 'created') { throw new RuntimeException('A selected parcel is already transmitted or unavailable at Canada Post. Review automatic verification; do not resubmit it.'); }
			}
			$b['excluded']                   = $excluded;
			$xml                             = self::transmit_xml( $b, $key, $handoff );
			$all[ $key ]['pending_handoff']  = $handoff;
			$all[ $key ]['pending']          = $selected;
			$all[ $key ]['state']            = 'transmitting';
			$all[ $key ]['transmit_started'] = gmdate( 'c' );
			self::save( $all );
			try {
				$n     = $b['config']['customer'];
				$c     = new WLP_Canada_Post_Client();
				$body  = $c->contract_transport( 'POST', '/rs/' . $n . '/' . $n . '/manifest', $xml, 'application/vnd.cpc.manifest-v8+xml' );
				$links = self::links( $body, 'manifest' );
				if ( ! $links ) {
					throw new RuntimeException( 'No manifest reference returned. Recover this transmission; do not submit again.' );
				}
			} catch ( Throwable $e ) {
				$all[ $key ]['state'] = in_array( $e->getCode(), array( 400, 401, 403, 404, 422 ), true ) ? 'open' : 'uncertain';
				if ( $all[ $key ]['state'] === 'open' ) {
					unset( $all[ $key ]['pending'], $all[ $key ]['pending_handoff'] );
				}
				self::save( $all );
				throw $e;}
			$all[ $key ]['state']          = 'awaiting_manifest';
			$all[ $key ]['manifest_links'] = $links;
			self::save( $all );
		} finally {
			delete_option( self::LOCK );}
		if ( $confirm_now ) { self::refresh( $key ); }
	}

	/** Confirms carrier manifests before allowing handoff; held parcels remain open. */
	public static function refresh( string $key ): void {
		self::lock();try {
			$all = self::read();
			$b   = $all[ $key ] ?? null;
			if ( ! $b || empty( $b['manifest_links'] ) ) {
				throw new RuntimeException( 'No saved manifest reference. Use Recover transmission.' );
			}self::same_account( $b );
			$handoff = $b['pending_handoff'] ?? array_intersect_key( $b['config'], array_flip( array( 'postal', 'pickup' ) ) );
			$done = array();
			$c    = new WLP_Canada_Post_Client();
			foreach ( $b['manifest_links'] as $url ) {
				$body = $c->contract_transport( 'GET', $url, '', 'application/vnd.cpc.manifest-v8+xml' );
				$x    = self::xml( $body );
				$po   = (string) ( $x->xpath( '//*[local-name()="po-number"]' )[0] ?? '' );
				$art  = self::links( $body, 'artifact' );
				if ( ! $po || $po === 'EMPTY' || ! $art ) {
							throw new RuntimeException( 'Manifest still pending. Refresh later; do not retransmit.' );
				}$done[] = array(
					'po'           => $po,
					'artifact'     => $art[0],
					'confirmed_at' => gmdate( 'c' ),
					'handoff'      => $handoff,
				);}
			$pending = $b['pending'] ?? array_keys( array_filter( $b['shipments'], static fn( $s )=>$s['state'] === 'created' ) );
			foreach ( $pending as $rid ) {
				$all[ $key ]['shipments'][ $rid ]['state'] = 'transmitted';
				$all[ $key ]['shipments'][ $rid ]['handoff'] = $handoff;
			}
			foreach ( $done as $m ) {
				if ( ! in_array( $m['po'], array_column( $all[ $key ]['manifests'], 'po' ), true ) ) {
					$all[ $key ]['manifests'][] = $m;
				}
			}
			unset( $all[ $key ]['manifest_links'], $all[ $key ]['pending'], $all[ $key ]['pending_handoff'] );
			$open                 = array_filter( $all[ $key ]['shipments'], static fn( $s )=>$s['state'] === 'created' );
			$all[ $key ]['state'] = $open ? 'open' : 'manifest_ready';
			self::save( $all );
		} finally {
			delete_option( self::LOCK );}
	}

	/** Read-only reconciliation of an interrupted create request using its carrier request ID. */
	public static function recover_label( string $key, string $rid ): void {
		self::lock();try {
			$all = self::read();
			$b   = $all[ $key ] ?? null;
			$s   = $b['shipments'][ $rid ] ?? null;
			if ( ! $s || ! in_array( $s['state'], array( 'requesting', 'uncertain' ), true ) ) {
				throw new RuntimeException( 'This label does not need carrier recovery.' );
			}self::same_account( $b );
			$n    = $b['config']['customer'];
			$c    = new WLP_Canada_Post_Client();
			$body = $c->contract_transport( 'GET', '/rs/' . $n . '/' . $n . '/shipment?requestId=' . rawurlencode( $rid ) );
			$urls = self::links( $body, 'shipment' );
			if ( count( $urls ) !== 1 ) {
				throw new RuntimeException( 'Carrier has not returned one matching shipment. Keep this request blocked and contact Canada Post with the request ID.' );
			}
			$body = $c->contract_transport( 'GET', $urls[0] );
			$r    = self::result( $c, $body );
			if ( ! $r['self_url'] ) {
				$r['self_url'] = $urls[0];
			}
			$all[ $key ]['shipments'][ $rid ]['result'] = $r;
			$all[ $key ]['shipments'][ $rid ]['state']  = 'created';
			self::save( $all );
		} finally {
			delete_option( self::LOCK );}
	}

	/** Finds a lost transmit response only when carrier manifest membership exactly matches. */
	public static function recover_transmission( string $key ): void {
		self::lock();try {
			$all = self::read();
			$b   = $all[ $key ] ?? null;
			if ( ! $b || ! in_array( $b['state'], array( 'uncertain', 'transmitting' ), true ) || empty( $b['pending'] ) ) {
				throw new RuntimeException( 'No recoverable transmission intent is saved.' );
			}self::same_account( $b );
			$wanted = array_map( static fn( $rid )=> (string) $b['shipments'][ $rid ]['result']['shipment_id'], $b['pending'] );
			sort( $wanted );
			$start   = gmdate( 'Ymd', strtotime( $b['transmit_started'] ) - 86400 );
			$end     = gmdate( 'Ymd' );
			$n       = $b['config']['customer'];
			$c       = new WLP_Canada_Post_Client();
			$body    = $c->contract_transport( 'GET', '/rs/' . $n . '/' . $n . '/manifest?start=' . $start . '&end=' . $end, '', 'application/vnd.cpc.manifest-v8+xml' );
			$matched = array();
			$found   = array();
			foreach ( self::links( $body, 'manifest' ) as $url ) {
				$manifest = $c->contract_transport( 'GET', $url, '', 'application/vnd.cpc.manifest-v8+xml' );
				$shipurls = self::links( $manifest, 'manifestShipments' );
				if ( ! $shipurls ) {
							continue;
				}
				$shipbody = $c->contract_transport( 'GET', $shipurls[0] );
				$sx       = self::xml( $shipbody );
				$ids      = array_map( 'strval', $sx->xpath( '//*[local-name()="shipment-id"]' ) ?: array() );
				if ( ! $ids ) {
					foreach ( self::links( $shipbody, 'shipment' ) as $u ) {
										$ids[] = basename( (string) parse_url( $u, PHP_URL_PATH ) );
					}
				}
				if ( $ids && ! array_diff( $ids, $wanted ) ) {
					$matched[] = $url;
						$found = array_merge( $found, $ids );}
			}
			sort( $found );
			if ( $found !== $wanted ) {
				throw new RuntimeException( 'No exact matching manifest found yet. Keep this batch blocked; contact Canada Post if it remains pending.' );
			}
			$all[ $key ]['manifest_links'] = $matched;
			$all[ $key ]['state']          = 'awaiting_manifest';
			self::save( $all );
		} finally {
			delete_option( self::LOCK );}
		self::refresh( $key );
	}

	/** Voids an untransmitted label. An ambiguous delete remains blocked for carrier review. */
	public static function void_label( string $key, string $rid ): void {
		self::lock();try {
			$all = self::read();
			$b   = $all[ $key ] ?? null;
			$s   = $b['shipments'][ $rid ] ?? null;
			if ( ! $s || $b['state'] !== 'open' || $s['state'] !== 'created' ) {
				throw new RuntimeException( 'Only a confirmed, untransmitted label can be voided here.' );
			}self::same_account( $b );
			if ( empty( $s['result']['self_url'] ) ) {
				// Releases before 0.1.173 saved the shipment ID but omitted its self link.
				$id = (string) ( $s['result']['shipment_id'] ?? '' );
				if ( ! preg_match( '/^[0-9]{1,32}$/D', $id ) ) {
					throw new RuntimeException( 'No carrier shipment reference is stored. Contact Canada Post before replacing this label.' );
				}
				$host = $b['config']['sandbox'] ? 'ct.soa-gw.canadapost.ca' : 'soa-gw.canadapost.ca';
				$n = $b['config']['customer'];
				$s['result']['self_url'] = 'https://' . $host . '/rs/' . $n . '/' . $n . '/shipment/' . $id;
				$all[ $key ]['shipments'][ $rid ]['result']['self_url'] = $s['result']['self_url'];
			}
			$all[ $key ]['shipments'][ $rid ]['state'] = 'voiding';
			self::save( $all );
			try {
				( new WLP_Canada_Post_Client() )->contract_transport( 'DELETE', $s['result']['self_url'] );
			} catch ( Throwable $e ) {
				$all[ $key ]['shipments'][ $rid ]['state'] = 'void_uncertain';
				self::save( $all );
				throw $e;}
			$all[ $key ]['shipments'][ $rid ]['state'] = 'voided';
			self::save( $all );
		} finally {
			delete_option( self::LOCK );}
		self::restore_label( $key, $rid );
	}

	/** Confirms an interrupted void only when the previously known carrier resource is gone. */
	public static function recover_void( string $key, string $rid ): void {
		self::lock();try {
			$all = self::read();
			$b   = $all[ $key ] ?? null;
			$s   = $b['shipments'][ $rid ] ?? null;
			if ( ! $s || ! in_array( $s['state'], array( 'voiding', 'void_uncertain' ), true ) || empty( $s['result']['self_url'] ) ) {
				throw new RuntimeException( 'No interrupted void is saved.' );
			}self::same_account( $b );
			try {
				( new WLP_Canada_Post_Client() )->contract_transport( 'GET', $s['result']['self_url'] );
			} catch ( Throwable $e ) {
				if ( $e->getCode() !== 404 ) {
					throw $e;
				}$all[ $key ]['shipments'][ $rid ]['state'] = 'voided';
				self::save( $all );
				return;}
			throw new RuntimeException( 'The shipment still exists. Keep it held and confirm the void outcome with Canada Post.' );
		} finally {
			delete_option( self::LOCK );}
	}

	/** Allows resetting a split-package attempt only when no carrier outcome is unresolved. */
	public static function can_retry_package( WC_Order $order, string $package ): bool {
		foreach ( self::read() as $b ) {
			foreach ( $b['shipments'] as $s ) {
				if ( (int) $s['order'] === $order->get_id() && ( $s['package'] ?? $package ) === $package && ! in_array( $s['state'], array( 'rejected', 'voided' ), true ) ) {
								return false;
				}
			}
		}
		return true;
	}

	/** Restores saved carrier results (or clears a confirmed void) without another purchase or email. */
	public static function restore_label( string $key, string $rid ): void {
		self::lock();
		$splitlock = '';try {
				$b = self::read()[ $key ] ?? null;
			$s     = $b['shipments'][ $rid ] ?? null;
			if ( ! $s || ! in_array( $s['state'], array( 'created', 'transmitted', 'voided' ), true ) ) {
				throw new RuntimeException( 'Recover the carrier result first.' );
			}
			$o = wc_get_order( $s['order'] );
			if ( ! $o instanceof WC_Order ) {
				throw new RuntimeException( 'Order unavailable.' );
			}
			$package = $s['package'] ?? 'primary:0';
			$r       = $s['result'];
			$clear   = $s['state'] === 'voided';
			if ( str_starts_with( $package, 'split:' ) ) {
				$splitlock = 'wlp_split_lock_' . $o->get_id();
				if ( ! add_option( $splitlock, time(), '', 'no' ) ) {
					$splitlock = '';
					throw new RuntimeException( 'Package is being edited. Try restoration later.' );}
				$data = $o->get_meta( '_wlp_split_shipments', true );
				if ( ! is_array( $data ) ) {
					throw new RuntimeException( 'Package no longer exists.' );
				}$found = false;
				foreach ( $data['packages'] as &$p ) {
					if ( 'split:' . $p['id'] === $package ) {
						$existing = $p['label']['shipment_id'] ?? '';
						if ( ! empty( $p['label'] ) && $existing !== $r['shipment_id'] ) {
										throw new RuntimeException( 'A different label is already attached. Review it before restoring.' );
						}
						if ( $clear ) {
								unset( $p['label'], $p['purchaseAttempt'] );
						} else {
										$p['label'] = array_merge( $p['label'] ?? array(), $r );
						}$found = true;
						break;
					}
				}unset( $p );
				if ( ! $found ) {
					throw new RuntimeException( 'Package no longer exists.' );
				}$data['revision'] = (int) ( $data['revision'] ?? 0 ) + 1;
				$o->update_meta_data( '_wlp_split_shipments', $data );
			} else {
				$current  = WLP_Order_Logistics::read( $o );
				$existing = $current['shipment_id'];
				if ( ( $existing && $existing !== $r['shipment_id'] ) || ( ! $existing && ! empty( $current['tracking_number'] ) ) ) {
					throw new RuntimeException( 'Different tracking is already attached. Review it before restoring.' );
				}
				if ( ! $clear && $package !== 'primary:' . (int) $o->get_meta( '_wlp_contract_generation', true ) ) {
					throw new RuntimeException( 'This is an earlier reship. Do not restore it over the current shipment.' );
				}
				foreach ( array( 'shipment_id', 'tracking_number', 'tracking_url', 'label_artifact_url', 'label_created_at' ) as $field ) {
					$value = $field === 'label_created_at' ? $s['created'] : ( $r[ $field ] ?? '' );
					$meta  = '_wlp_' . $field;
					if ( $clear ) {
						$o->delete_meta_data( $meta );
					} else {
						$o->update_meta_data( $meta, $value );
					}
					if ( isset( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $field ] ) ) {
						if ( $clear ) {
							$o->delete_meta_data( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $field ] );
						} elseif ( WLP_Settings::mirror_external_logistics_meta() ) {
							$o->update_meta_data( WLP_Meta_Keys::LEGACY_MEDUSA_KEYS[ $field ], $value );
						}
					}
				}
			}$o->save();
		} finally {
			if ( $splitlock ) {
				delete_option( $splitlock );
			}delete_option( self::LOCK );}
	}

	/** Blocks printing a voided or unresolved label, even if clearing order metadata failed. */
	public static function assert_printable( string $artifact ): void {
		foreach ( self::read() as $b ) {
			foreach ( $b['shipments'] as $s ) {
				if ( ( $s['result']['label_artifact_url'] ?? '' ) === $artifact && ! in_array( $s['state'], array( 'created', 'transmitted' ), true ) ) {
					throw new RuntimeException( 'This label is voided or needs carrier recovery. Review Shipping manifests before printing.' );
				}
			}
		}
	}

	/** Checks handoff actions before an AJAX handler saves metadata or sends an email. */
	public static function assert_admin_action( WC_Order $order, string $action, array $input ): void {
		if ( 'wlp_prepare_reship' === $action ) {
			self::assert_reship_allowed( $order );
		}
		$handoff = 'wlp_mark_shipped' === $action
			|| ( 'wlp_wholesale_advance' === $action && ( $input['workflowAction'] ?? '' ) === 'confirm_shipped' )
			|| ( 'wlp_wholesale_save_tracking' === $action && ( $input['mode'] ?? '' ) === 'ship' )
			|| ( 'wlp_wholesale_followup' === $action && ( $input['stage'] ?? '' ) === 'shipped_tracking' );
		if ( $handoff ) {
			self::assert_order_ready( $order );
			self::assert_transmitted( WLP_Order_Logistics::read( $order )['shipment_id'] );
		}
	}

	/** Blocks handoff of known contract labels until Canada Post confirms the manifest. */
	public static function assert_transmitted( string $shipment ): void {
		if ( ! $shipment ) {
			return;
		}
		foreach ( self::read() as $b ) {
			foreach ( $b['shipments'] as $s ) {
				if ( ( $s['result']['shipment_id'] ?? '' ) === $shipment ) {
					if ( $s['state'] === 'transmitted' || ( $s['state'] === 'created' && $b['state'] === 'manifest_ready' ) ) {
						return;
					}
					throw new RuntimeException( 'Complete the electronic manifest in WooCommerce → Shipping manifests before marking this parcel shipped.' );
				}
			}
		}
	}

	/** Prevents reship from orphaning a pending label or uncertain carrier operation. */
	public static function assert_order_ready( WC_Order $order, ?string $package = null ): void {
		foreach ( self::read() as $batch ) {
			foreach ( $batch['shipments'] as $shipment ) {
				if ( (int) $shipment['order'] !== $order->get_id() || ( null !== $package && ( $shipment['package'] ?? '' ) !== $package ) ) {
					continue;
				}
				if ( in_array( $shipment['state'], array( 'voided', 'rejected', 'transmitted' ), true ) || ( 'created' === $shipment['state'] && 'manifest_ready' === $batch['state'] ) ) {
					continue;
				}
				throw new RuntimeException( 'Complete the electronic manifest and resolve pending carrier requests before marking these parcels shipped.' );
			}
		}
	}

	/** Prevents reship from orphaning a pending label or uncertain carrier operation. */
	public static function assert_reship_allowed( WC_Order $order ): void {
		if ( get_option( self::LOCK ) ) {
			throw new RuntimeException( 'Wait for the carrier operation to finish before preparing a reship.' );
		}
		foreach ( self::read() as $b ) {
			foreach ( $b['shipments'] as $s ) {
				if ( (int) $s['order'] !== $order->get_id() ) {
					continue;
				}
				if ( self::unresolved( $s['state'] ) || ( $s['state'] === 'created' && $b['state'] !== 'manifest_ready' ) ) {
					throw new RuntimeException( 'Void unused labels or complete their electronic manifest before preparing a reship. Recover uncertain requests first.' );
				}
			}
		}
	}

	/** Safely leaves interrupted mutations blocked while allowing read-only recovery after a worker died. */
	public static function release_stale_lock(): void {
		$value = get_option( self::LOCK );
		$time  = is_string( $value ) ? strtotime( $value ) : false;
		if ( ! $time || time() - $time < 900 ) {
			throw new RuntimeException( 'Wait at least 15 minutes for the carrier operation to finish.' );
		}
		// Conditional database deletion cannot remove a newly acquired lock.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $value ) );
		wp_cache_delete( self::LOCK, 'options' );
	}
}
