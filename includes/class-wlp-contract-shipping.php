<?php
/** Contract-only labels and explicit electronic manifests for WooCommerce. */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-wlp-contract-handoff.php';
require_once __DIR__ . '/trait-wlp-manifest-workspace.php';
require_once __DIR__ . '/class-wlp-manifest-verifier.php';
require_once __DIR__ . '/class-wlp-manifest-reconciliation.php';
require_once __DIR__ . '/class-wlp-contract-xml.php';
require_once __DIR__ . '/class-wlp-contract-operations.php';
require_once __DIR__ . '/class-wlp-manifest-quick-submit.php';
final class WLP_Contract_Shipping {
	use WLP_Contract_XML;
	use WLP_Manifest_Workspace;
	use WLP_Contract_Operations;
	use WLP_Manifest_Quick_Submit;

	/** Registers the protected operations screen. */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action(
			'admin_menu',
			static function () {
				add_submenu_page( 'woocommerce', 'Electronic shipping manifests', 'Shipping manifests', 'manage_woocommerce', 'wlp-contract-manifests', array( __CLASS__, 'page' ) );
			}
		);
		add_action( 'admin_post_wlp_contract_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_wlp_contract_manifest_pdf', array( __CLASS__, 'pdf' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'reminder_assets' ) );
		add_action( 'wp_ajax_wlp_manifest_status', array( __CLASS__, 'reminder_status' ) );
		add_action( 'wp_ajax_wlp_manifest_quick_submit', array( __CLASS__, 'quick_submit' ) );
	}
	public static function reminder_assets(): void {
		if (current_user_can('manage_woocommerce') && ($_GET['page'] ?? '') === 'wlp-contract-manifests') {
            wp_enqueue_style('wlp-manifests', WLP_URL . 'assets/css/manifests.css', array(), WLP_VERSION);
            wp_enqueue_script('wlp-manifests', WLP_URL . 'assets/js/manifests.js', array(), WLP_VERSION, true);
            return;
        }
		if ( ! current_user_can( 'manage_woocommerce' ) || ( $_GET['page'] ?? '' ) !== 'wlp-logistics' || ( $_GET['view'] ?? '' ) === 'settings' ) { return; }
		wp_enqueue_style( 'wlp-manifest-reminder', WLP_URL . 'assets/css/manifest-reminder.css', array(), WLP_VERSION );
		wp_enqueue_script( 'wlp-manifest-reminder', WLP_URL . 'assets/js/manifest-reminder.js', array( 'wlp-admin' ), WLP_VERSION, true );
	}
	/** Counts saved carrier records only; this endpoint never calls or submits to Canada Post. */
	public static function reminder_status(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		check_ajax_referer( 'wlp_logistics', 'nonce' );
		wp_send_json_success( self::quick_snapshot() );
	}
	public static function reminder_summary( array $all ): array {
		$ready = self::ready_candidates( $all );
		$pending = 0;
		$review = 0;
		foreach ( $all as $key => $batch ) {
			foreach ( $batch['shipments'] as $rid => $shipment ) {
				if ( WLP_Manifest_Reconciliation::lookup($batch['config'],$shipment) || $batch['state'] === 'manifest_ready' || in_array( $shipment['state'], array( 'transmitted', 'voided', 'rejected' ), true ) ) { continue; }
				if ( isset( $ready[ hash( 'sha256', $key . '|' . $rid ) ] ) ) { continue; }
				if ( in_array( $rid, $batch['pending'] ?? array(), true ) && in_array( $batch['state'], array( 'awaiting_manifest', 'transmitting', 'uncertain' ), true ) ) { ++$pending; continue; }
				++$review;
			}
		}
		return array( 'ready' => count( $ready ), 'pending' => $pending, 'review' => $review );
	}
	/** Lists unresolved saved parcels independently of order handoff or delivery status. */
	public static function manifest_issues( array $all ): array {
		$ready = self::ready_candidates( $all );
		$issues = array();
		foreach ( $all as $key => $batch ) {
			foreach ( $batch['shipments'] as $rid => $shipment ) {
				if ( WLP_Manifest_Reconciliation::lookup($batch['config'],$shipment) || $batch['state'] === 'manifest_ready' || in_array( $shipment['state'], array( 'transmitted', 'voided', 'rejected' ), true ) ) { continue; }
				if ( isset( $ready[ hash( 'sha256', $key . '|' . $rid ) ] ) ) { continue; }
				$pending = in_array( $rid, $batch['pending'] ?? array(), true ) && in_array( $batch['state'], array( 'awaiting_manifest', 'transmitting', 'uncertain' ), true );
				$reason = self::hold_reason( $shipment );
				$issues[] = array(
					'order' => (int) $shipment['order'],
					'package' => $shipment['package'] ?? 'Whole order',
					'tracking' => $shipment['result']['tracking_number'] ?? '',
					'reason' => $pending ? 'Submission awaiting confirmation. Refresh or recover the existing submission; do not resubmit.' : ( $reason ?: ( $shipment['state'] === 'created' ? 'Manifest missing. This parcel is already marked shipped/delivered or its batch needs review.' : 'Carrier request needs recovery.' ) ),
				);
			}
		}
		return $issues;
	}

	/** Shows the handoff requirement on the logistics desk. */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ( $_GET['page'] ?? '' ) !== 'wlp-logistics' ) {
			return;
		}
		$count = 0;
		foreach ( self::read() as $b ) {
			foreach ( $b['shipments'] as $s ) {
				if ( $b['state'] !== 'manifest_ready' && ! in_array( $s['state'], array( 'transmitted', 'voided', 'rejected' ), true ) ) {
					++$count;
				}
			}
		}
		echo '<div class="notice notice-warning"><p><strong>Commercial shipping: complete the electronic manifest before carrier handoff.</strong> ' . (int) $count . ' label(s) pending. <a href="' . esc_url( admin_url( 'admin.php?page=wlp-contract-manifests' ) ) . '">Finish shipping day</a>. Pickup is booked separately.</p></div>';
		if ( ( $_GET['view'] ?? '' ) !== 'settings' ) {
			echo '<p><button type="button" class="button" data-wlp-manifest-reminder>Check manifests</button></p>';
		}
	}
	private static function authorize(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not authorized.', '', array( 'response' => 403 ) );
		}}
	/** Validates administrator intent and dispatches an explicit carrier operation. */
	public static function action(): void {
		self::authorize();
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			wp_die( 'POST required.', '', array( 'response' => 405 ) );
		}check_admin_referer( 'wlp_contract_action' );
		$key = sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) );
		$rid = sanitize_text_field( wp_unslash( $_POST['request'] ?? '' ) );
		$op  = $_POST['operation'] ?? '';
		try {
			switch ( $op ) {
				case 'release_quick_job':
					self::quick_release();
					break;
				case 'finish_day':
					self::finish_day( $_POST );
					break;
				case 'transmit':
					if ( ( $_POST['ready'] ?? '' ) !== 'yes' ) {
						throw new RuntimeException( 'Confirm the selected parcels are ready for handoff.' );
					}
					$selected = $_POST['selected'] ?? array();
					if ( ! is_array( $selected ) || array_filter( $selected, 'is_array' ) ) {
									throw new RuntimeException( 'Invalid parcel selection.' );
					}
					$handoff_id = $_POST['handoff_id'] ?? '';
					if ( ! is_string( $handoff_id ) || $handoff_id === '' ) {
						throw new RuntimeException( 'Choose a pickup or drop-off location.' );
					}
					self::transmit( $key, array_map( 'sanitize_text_field', wp_unslash( $selected ) ), sanitize_text_field( wp_unslash( $handoff_id ) ) );
					break;
				case 'refresh':
					self::refresh( $key );
					break;
				case 'recover_void':
					self::recover_void( $key, $rid );
					break;
				case 'recover_label':
					self::recover_label( $key, $rid );
					break;
				case 'recover_transmission':
					self::recover_transmission( $key );
					break;
				case 'restore':
					self::restore_label( $key, $rid );
					break;
				case 'void':
					if ( ( $_POST['confirm_void'] ?? '' ) !== 'yes' ) {
						throw new RuntimeException( 'Confirm this unused label should be voided.' );
					}self::void_label( $key, $rid );
					break;
				case 'unlock':
					self::release_stale_lock();
					break;
				default:
					throw new RuntimeException( 'Unknown operation.' );
			}
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) . '<p><a href="' . esc_url( admin_url( 'admin.php?page=wlp-contract-manifests' ) ) . '">Back to manifests</a></p>', 'Carrier operation needs attention', array( 'response' => 400 ) );}
		wp_safe_redirect( admin_url( 'admin.php?page=wlp-contract-manifests' ) );
		exit;
	}
	private static function form( string $operation, string $batch = '', string $request = '', string $form_id = '' ): void {
		echo '<form'.($form_id ? ' id="'.esc_attr($form_id).'"' : '').' method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';
		wp_nonce_field( 'wlp_contract_action' );
		foreach ( array(
			'action'    => 'wlp_contract_action',
			'operation' => $operation,
			'batch'     => $batch,
			'request'   => $request,
		) as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
	}
	public static function pdf(): void {
		self::authorize();
		check_admin_referer( 'wlp_contract_manifest_pdf' );
		$key = sanitize_text_field( wp_unslash( $_GET['batch'] ?? '' ) );
		$i   = absint( $_GET['index'] ?? 0 );
		$b   = self::read()[ $key ] ?? null;
		$m   = $b['manifests'][ $i ] ?? null;
		if ( ! $m ) {
			wp_die( 'Manifest not found.', '', array( 'response' => 404 ) );
		}
		$client   = new WLP_Canada_Post_Client();
		$url      = $m['artifact'];
		$host     = parse_url( $url, PHP_URL_HOST );
		$expected = $b['config']['sandbox'] ? 'ct.soa-gw.canadapost.ca' : 'soa-gw.canadapost.ca';
		if ( parse_url( $url, PHP_URL_SCHEME ) !== 'https' || $host !== $expected ) {
			wp_die( 'Invalid carrier artifact.' );
		}
		if ( $b['config']['customer'] !== (string) get_option( WLP_Settings::OPTION_CUSTOMER, '' ) ) {
			wp_die( 'Carrier account mismatch.' );
		}
		// Manifest pages must retain Letter dimensions; label normalization would crop them.
		$body = $client->contract_transport( 'GET', $url, '', 'application/pdf' );
		if ( ! str_starts_with( $body, '%PDF-' ) ) {
			wp_die( 'Manifest PDF is not ready.' );
		}nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="canada-post-manifest.pdf"' );
		echo $body;
		exit;
	}

	/** Returns submission candidates; handed-over parcels are opt-in so pickup totals stay physical. */
	public static function ready_candidates( array $all, bool $include_handed_over = false ): array {
		$rows = array();
		foreach ( $all as $key => $batch ) {
			if ( $batch['state'] !== 'open' || array_filter( $batch['shipments'], static fn( $s ) => self::unresolved( $s['state'] ) ) ) { continue; }
			foreach ( $batch['shipments'] as $rid => $shipment ) {
				if ( WLP_Manifest_Reconciliation::lookup($batch['config'],$shipment) || $shipment['state'] !== 'created' || self::hold_reason( $shipment ) ) { continue; }
				$order = wc_get_order( $shipment['order'] );
				$package = 'Whole order';
				$handed_over = false;
				if ( str_starts_with( $shipment['package'] ?? '', 'split:' ) ) {
					$data = $order->get_meta( '_wlp_split_shipments', true );
					foreach ( $data['packages'] ?? array() as $index => $part ) {
						if ( 'split:' . $part['id'] !== $shipment['package'] ) { continue; }
						$handed_over = !empty($part['shippedAt']) || !empty($part['deliveredAt']);
						if ($handed_over && !$include_handed_over) { continue 2; }
						$package = 'Package ' . ( $index + 1 );
					}
				} else {
					$meta = WLP_Order_Logistics::read( $order );
					$handed_over = !empty($meta['shipped_at']) || !empty($meta['delivered_at']);
					if ($handed_over && !$include_handed_over) { continue; }
				}
				$token = hash( 'sha256', $key . '|' . $rid );
				$rows[ $token ] = array( 'handed_over' => $handed_over, 'batch' => $key, 'request' => $rid, 'order' => $shipment['order'], 'package' => $package, 'day' => $batch['day'], 'tracking' => $shipment['result']['tracking_number'] ?? '' );
			}
		}
		return $rows;
	}

	/** Preflight every selection before any carrier submission; never retry uncertain results. */
	private static function finish_day_groups( array $input ): array {
		if ( ( $input['ready'] ?? '' ) !== 'yes' || ( $input['pickup_day'] ?? '' ) !== wp_date( 'Y-m-d' ) ) {
			throw new RuntimeException( 'Confirm these parcels need manifest submission. Reload this page if the date changed.' );
		}
		$tokens = $input['parcels'] ?? array();
		if ( ! is_array( $tokens ) || ! $tokens || array_filter( $tokens, static fn( $v ) => ! is_string( $v ) ) ) { throw new RuntimeException( 'Select the parcels needing submission.' ); }
		$handoff = $input['handoff_id'] ?? '';
		if ( ! is_string( $handoff ) ) { throw new RuntimeException( 'Invalid pickup location.' ); }
		WLP_Contract_Handoff::resolve( $handoff );
		$all = self::read();
		$eligible = self::ready_candidates( $all, true );
		$groups = array();
		foreach ( array_unique( $tokens ) as $token ) {
			if ( ! isset( $eligible[ $token ] ) ) { throw new RuntimeException( 'A selected parcel changed or is no longer eligible. Reload and review the list.' ); }
			$row = $eligible[ $token ];
			if ( $row['day'] > $input['pickup_day'] ) { throw new RuntimeException( 'A selected label has a future dispatch date. Submit it on its dispatch day.' ); }
			self::same_account( $all[ $row['batch'] ] );
			if ( $all[ $row['batch'] ]['config']['contract'] !== self::config()['contract'] ) { throw new RuntimeException( 'Restore the selected batch agreement before submitting.' ); }
			$groups[ $row['batch'] ][] = $row['request'];
		}
		return $groups;
	}

	private static function finish_day( array $input ): void {
		$groups = self::finish_day_groups( $input );
		$sent = 0;
		foreach ( $groups as $key => $requests ) {
			try {
				// A pending PDF is normal. Do not let confirmation polling interrupt other selected batches.
				self::transmit( $key, $requests, $input['handoff_id'], false );
				++$sent;
			} catch ( Throwable $error ) {
				throw new RuntimeException(sprintf('%d of %d selected batches sent. Processing stopped on the next batch: %s Check awaiting confirmation before retrying; do not resubmit pending parcels.', $sent, count($groups), $error->getMessage()), 0, $error);
			}
		}
	}


	/** Count physical parcels, not orders; confirmed pickup totals exclude recorded handoffs. */
	public static function pickup_summary( array $all ): array {
		$totals = array( 'pickup' => 0, 'dropoff' => 0, 'pending' => 0, 'remaining' => count( self::ready_candidates( $all ) ) );
		$seen = array();
		foreach ( $all as $batch ) {
			foreach ( $batch['shipments'] as $rid => $shipment ) {
				if ( self::hold_reason( $shipment ) ) { continue; }
				$order = wc_get_order( $shipment['order'] );
				if ( str_starts_with( $shipment['package'] ?? '', 'split:' ) ) {
					$data = $order->get_meta( '_wlp_split_shipments', true );
					foreach ( $data['packages'] ?? array() as $part ) {
						if ( 'split:' . $part['id'] === $shipment['package'] && ( ! empty( $part['shippedAt'] ) || ! empty( $part['deliveredAt'] ) ) ) { continue 2; }
					}
				} else {
					$meta = WLP_Order_Logistics::read( $order );
					if ( ! empty( $meta['shipped_at'] ) || ! empty( $meta['delivered_at'] ) ) { continue; }
				}
				$id = $shipment['result']['shipment_id'] ?? '';
				if ( ! $id || isset( $seen[ $id ] ) ) { continue; }
				if ( $shipment['state'] === 'transmitted' ) {
					$point = $shipment['handoff'] ?? $batch['config'];
					++$totals[ ! empty( $point['pickup'] ) ? 'pickup' : 'dropoff' ];
					$seen[ $id ] = true;
				} elseif ( in_array( $rid, $batch['pending'] ?? array(), true ) && in_array( $batch['state'], array( 'awaiting_manifest', 'transmitting', 'uncertain' ), true ) ) {
					++$totals['pending'];
					$seen[ $id ] = true;
				}
			}
		}
		return $totals;
	}

	/** Renders the compact workspace without changing any carrier operation. */
	public static function page(): void {
		self::authorize();
		$tab=isset($_GET['manifest_tab']) && $_GET['manifest_tab']==='submitted' ? 'submitted' : 'needs';
		$page=isset($_GET['manifest_page']) && is_scalar($_GET['manifest_page']) ? max(1,(int)$_GET['manifest_page']) : 1;
		$review_page=isset($_GET['review_page']) && is_scalar($_GET['review_page']) ? max(1,(int)$_GET['review_page']) : 1;
		$evidence=get_option('wlp_manifest_verification_v1',array());
		self::workspace_render(self::read(),is_array($evidence)?$evidence:array(),$tab,$page,$review_page);
	}
}
