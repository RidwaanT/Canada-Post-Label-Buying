<?php
/** Contract-only labels and explicit electronic manifests for WooCommerce. */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-wlp-contract-handoff.php';
require_once __DIR__ . '/class-wlp-contract-xml.php';
require_once __DIR__ . '/class-wlp-contract-operations.php';
require_once __DIR__ . '/class-wlp-manifest-quick-submit.php';
final class WLP_Contract_Shipping {
	use WLP_Contract_XML;
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
				if ( $batch['state'] === 'manifest_ready' || in_array( $shipment['state'], array( 'transmitted', 'voided', 'rejected' ), true ) ) { continue; }
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
				if ( $batch['state'] === 'manifest_ready' || in_array( $shipment['state'], array( 'transmitted', 'voided', 'rejected' ), true ) ) { continue; }
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
	private static function form( string $operation, string $batch = '', string $request = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';
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

	/** Labels eligible for today's handoff, without inferring physical readiness. */
	public static function ready_candidates( array $all ): array {
		$rows = array();
		foreach ( $all as $key => $batch ) {
			if ( $batch['state'] !== 'open' || array_filter( $batch['shipments'], static fn( $s ) => self::unresolved( $s['state'] ) ) ) { continue; }
			foreach ( $batch['shipments'] as $rid => $shipment ) {
				if ( $shipment['state'] !== 'created' || self::hold_reason( $shipment ) ) { continue; }
				$order = wc_get_order( $shipment['order'] );
				$package = 'Whole order';
				if ( str_starts_with( $shipment['package'] ?? '', 'split:' ) ) {
					$data = $order->get_meta( '_wlp_split_shipments', true );
					foreach ( $data['packages'] ?? array() as $index => $part ) {
						if ( 'split:' . $part['id'] !== $shipment['package'] ) { continue; }
						if ( ! empty( $part['shippedAt'] ) || ! empty( $part['deliveredAt'] ) ) { continue 2; }
						$package = 'Package ' . ( $index + 1 );
					}
				} else {
					$meta = WLP_Order_Logistics::read( $order );
					if ( ! empty( $meta['shipped_at'] ) || ! empty( $meta['delivered_at'] ) ) { continue; }
				}
				$token = hash( 'sha256', $key . '|' . $rid );
				$rows[ $token ] = array( 'batch' => $key, 'request' => $rid, 'order' => $shipment['order'], 'package' => $package, 'day' => $batch['day'], 'tracking' => $shipment['result']['tracking_number'] ?? '' );
			}
		}
		return $rows;
	}

	/** Preflight every selection before any carrier submission; never retry uncertain results. */
	private static function finish_day_groups( array $input ): array {
		if ( ( $input['ready'] ?? '' ) !== 'yes' || ( $input['pickup_day'] ?? '' ) !== wp_date( 'Y-m-d' ) ) {
			throw new RuntimeException( 'Confirm the parcels are ready for pickup today. Reload this page if the date changed.' );
		}
		$tokens = $input['parcels'] ?? array();
		if ( ! is_array( $tokens ) || ! $tokens || array_filter( $tokens, static fn( $v ) => ! is_string( $v ) ) ) { throw new RuntimeException( 'Select the parcels ready for pickup.' ); }
		$handoff = $input['handoff_id'] ?? '';
		if ( ! is_string( $handoff ) ) { throw new RuntimeException( 'Invalid pickup location.' ); }
		WLP_Contract_Handoff::resolve( $handoff );
		$all = self::read();
		$eligible = self::ready_candidates( $all );
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
		foreach ( self::finish_day_groups( $input ) as $key => $requests ) { self::transmit( $key, $requests, $input['handoff_id'] ); }
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

	private static function finish_day_panel( array $all ): void {
		$rows = self::ready_candidates( $all );
		$totals = self::pickup_summary( $all );
		$summary = self::reminder_summary( $all );
		$issues = self::manifest_issues( $all );
		echo '<section class="wlp-finish-day" style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:16px 0;max-width:1100px"><h2>1. Add ready parcels to today’s manifest</h2><p>Pick the boxes that are packed and labelled. Older unused labels are included below so nothing is missed. Leave any parcel you are holding unchecked.</p>';
		echo '<div role="status" style="background:#eef7f0;border:1px solid #a8cfb0;padding:16px;margin:16px 0"><h2 style="margin:0 0 8px">' . (int) $totals['pickup'] . ' package(s) confirmed for pickup</h2><p style="margin:0">' . (int) $summary['pending'] . ' awaiting carrier confirmation · ' . (int) $totals['remaining'] . ' eligible to submit</p><p style="margin:8px 0 0">Count the physical boxes before the driver arrives. This total includes confirmed pickup parcels not yet marked shipped. Pickup booking is separate.</p></div>';
		if ( $issues ) {
			echo '<div class="notice notice-warning inline" role="alert"><h3>' . count( $issues ) . ' parcel(s) need manifest attention</h3><p>Shipping or delivery status does not confirm an electronic manifest. Review these saved records in the expanded history below. Check the carrier before submitting an already handed-over parcel.</p><table class="widefat striped"><thead><tr><th>Order / package</th><th>Tracking</th><th>Action needed</th></tr></thead><tbody>';
			foreach ( $issues as $issue ) {
				echo '<tr><td>#' . (int) $issue['order'] . ' / ' . esc_html( $issue['package'] ) . '</td><td>' . esc_html( $issue['tracking'] ) . '</td><td>' . esc_html( $issue['reason'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		if ( $totals['dropoff'] ) { echo '<p>' . (int) $totals['dropoff'] . ' other parcel(s) confirmed for drop-off, excluded from the pickup total.</p>'; }
		foreach ( $all as $key => $batch ) {
			if ( ! empty( $batch['manifest_links'] ) ) { self::form( 'refresh', $key ); echo '<p>Canada Post confirmation is pending. Refresh this existing submission; do not submit it again.</p><button class="button button-primary">Refresh pending manifest</button></form>'; }
		}
		if ( $rows ) {
			self::form( 'finish_day' );
			echo '<input type="hidden" name="pickup_day" value="' . esc_attr( wp_date( 'Y-m-d' ) ) . '"><p><label>Pickup / drop-off location<br><select name="handoff_id" style="max-width:100%" required>';
			foreach ( WLP_Contract_Handoff::locations() as $id => $point ) {
				echo '<option value="' . esc_attr( $id ) . '"' . selected( $id, WLP_Contract_Handoff::DEFAULT_ID, false ) . '>' . esc_html( WLP_Contract_Handoff::describe( $point ) ) . '</option>';
			}
			echo '</select></label></p><p><button type="button" class="button" data-pick-all>Select all eligible parcels</button> <button type="button" class="button" data-pick-none>Clear selection</button> <strong data-pick-count aria-live="polite">0 parcels selected</strong></p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Ready</th><th>Order</th><th>Package</th><th>Tracking</th><th>Label dispatch date</th></tr></thead><tbody>';
			foreach ( $rows as $token => $row ) {
				echo '<tr><td><input type="checkbox" name="parcels[]" value="' . esc_attr( $token ) . '" aria-label="Add order ' . (int) $row['order'] . ' ' . esc_attr( $row['package'] ) . '"></td><td>#' . (int) $row['order'] . '</td><td>' . esc_html( $row['package'] ) . '</td><td>' . esc_html( $row['tracking'] ) . '</td><td>' . esc_html( $row['day'] ) . '</td></tr>';
			}
			echo '</tbody></table></div><p><label><input type="checkbox" name="ready" value="yes" required> These selected parcels are packed, labelled and ready for handoff today (' . esc_html( wp_date( 'F j, Y' ) ) . ') at the selected location.</label></p><button class="button button-primary button-hero" data-finish-submit>Finish shipping day</button><p class="description">Sends only your selection to Canada Post and checks for manifest confirmation. Does not book pickup or mark parcels shipped. If pickup is tomorrow, submit tomorrow before collection.</p></form>';
		} else { echo '<h3>No more eligible parcels to submit</h3><p>' . ( $summary['pending'] ? 'Your selected parcels have been submitted. Refresh the pending confirmation above before handoff.' : ( $issues ? 'Unresolved manifest records still need attention below.' : 'No unshipped parcels are eligible in the saved shipment records. Check Canada Post if a shipment is missing here.' ) ) . ' Cancelled, held and already shipped labels are excluded. Any issues remain in manifest history below.</p>'; }
		echo '<h2>2. Book or check pickup</h2><p>Pickup location: <strong>260 King St E, Hamilton, ON L8N 1B7</strong>. A manifest does not book a driver. Choose a one-time pickup, or use your existing recurring pickup.</p><a class="button button-secondary" target="_blank" rel="noopener" href="https://www.canadapost-postescanada.ca/pup-rdc/en/request-pickup">Book / check Canada Post pickup</a><p>Pickup booking status is not connected here. Keep Canada Post’s booking confirmation.</p><h2>3. Hand over the parcels</h2><p>Wait for confirmed manifests below, then hand the selected boxes to Canada Post. Mark shipped in Logistics after collection. Domestic paper manifests are optional when a parcel can be scanned; otherwise print the Letter PDF.</p>';
		foreach ( $all as $batch ) {
			foreach ( $batch['manifests'] ?? array() as $manifest ) {
				if ( substr( $manifest['confirmed_at'] ?? '', 0, 10 ) === gmdate( 'Y-m-d' ) ) { echo '<p><strong>Confirmed manifest: ' . esc_html( $manifest['po'] ) . '</strong>. PDF available in manifest history below.</p>'; }
			}
		}
		echo '</section><script>(function(){const box=document.querySelector(".wlp-finish-day"),input=box.querySelector("input[name=pickup_day]"),form=input?.form;if(!form)return;const picks=[...form.querySelectorAll("input[name=\"parcels[]\"]")],ready=form.elements.ready,button=form.querySelector("[data-finish-submit]");function update(){const count=picks.filter(p=>p.checked).length;form.querySelector("[data-pick-count]").textContent=count+" parcel"+(count===1?"":"s")+" selected";button.disabled=!count||!ready.checked;}form.querySelector("[data-pick-all]").addEventListener("click",()=>{picks.forEach(p=>p.checked=true);ready.checked=false;update();});form.querySelector("[data-pick-none]").addEventListener("click",()=>{picks.forEach(p=>p.checked=false);ready.checked=false;update();});form.addEventListener("change",e=>{if(e.target!==ready)ready.checked=false;update();});form.addEventListener("submit",()=>{button.disabled=true;button.textContent="Submitting manifest…";});update();})();</script>';
	}

	/** Renders parcel selection, carrier confirmation and safe recovery actions. */
	public static function page(): void {
		self::authorize();
		echo '<div class="wrap"><h1>Finish shipping day</h1><p>Select ready parcels, submit the manifest before pickup, then hand them to the driver.</p><p><a href="' . esc_url( admin_url( 'admin.php?page=wlp-logistics' ) ) . '">Back to Logistics</a></p>';
		if ( get_option( self::LOCK ) ) {
			self::form( 'unlock' );
			echo '<p>An operation is running or was interrupted. After 15 minutes, release its lock to use recovery. This does not resend any carrier request.</p><button class="button">Release interrupted operation lock</button></form>';}
		$quick_job = get_option( self::QUICK_JOB, array() );
		if ( $quick_job || get_option( self::QUICK_LOCK ) || get_option( 'wlp_manifest_quick_worker_lock' ) ) {
			echo '<section><h2>Quick submit status</h2><p>' . esc_html( $quick_job['message'] ?? 'A manifest queue operation was interrupted.' ) . '</p>';
			if ( in_array( $quick_job['state'] ?? '', array( 'queued', 'processing', 'awaiting_confirmation' ), true ) || get_option( self::QUICK_LOCK ) || get_option( 'wlp_manifest_quick_worker_lock' ) ) {
				self::form( 'release_quick_job' );
				echo '<p>If this job was interrupted, wait 15 minutes, release it, then review the existing carrier records. This never resends a manifest.</p><button class="button">Release interrupted quick-submit job</button></form>';
			}
			echo '</section>';
		}
		$all = array_reverse( self::read(), true );
		self::finish_day_panel( $all );
		echo '<details' . ( self::manifest_issues( $all ) ? ' open' : '' ) . '><summary style="font-size:18px;cursor:pointer">Manifest history, pending confirmations and recovery</summary>';
		if ( ! $all ) {
			echo '<p>No contract labels yet. Save your customer number, agreement, API credentials and pickup/drop-off details in Logistics settings.</p>';
		}
		foreach ( $all as $key => $b ) {
			echo '<section style="background:white;border:1px solid #ccd0d4;padding:16px;margin:16px 0;max-width:1100px"><h2>Dispatch ' . esc_html( $b['day'] ) . ' · ' . esc_html( str_replace( '_', ' ', $b['state'] ) ) . '</h2><p>' . esc_html( $b['config']['sandbox'] ? 'SANDBOX — test labels' : 'Production' ) . '</p>';
			if ( ! empty( $b['pending_handoff'] ) ) {
				echo '<p><strong>Submitted handoff:</strong> ' . esc_html( WLP_Contract_Handoff::describe( $b['pending_handoff'] ) ) . '</p>';
			}
			$blocked = false;
			foreach ( $b['shipments'] as $s ) {
				$blocked = $blocked || self::unresolved( $s['state'] );
			}
			$selectable = $b['state'] === 'open' && ! $blocked;
			if ( $selectable ) {
				self::form( 'transmit', $key );
				echo '<p><label><strong>Pickup / drop-off location</strong><br><select name="handoff_id" style="max-width:100%" required>';
				foreach ( WLP_Contract_Handoff::locations() as $id => $point ) {
					echo '<option value="' . esc_attr( $id ) . '"' . ( $id === WLP_Contract_Handoff::DEFAULT_ID ? ' selected' : '' ) . '>' . esc_html( WLP_Contract_Handoff::describe( $point ) ) . '</option>';
				}
				echo '</select></label></p><p class="description">Choose where these parcels will actually be handed to Canada Post. Your sender/return and manifest address stay unchanged. Changing the shipping point can adjust the final postage. Pickup must still be booked separately.</p>';
			}
			echo '<table class="widefat striped"><thead><tr><th>Ready</th><th>Order / package</th><th>Tracking</th><th>Status / hold reason</th></tr></thead><tbody>';
			foreach ( $b['shipments'] as $rid => $s ) {
				$reason = $s['state'] === 'created' ? self::hold_reason( $s ) : '';
				echo '<tr><td>';
				if ( $selectable && $s['state'] === 'created' && ! $reason ) {
							echo '<input type="checkbox" name="selected[]" value="' . esc_attr( $rid ) . '" aria-label="Select order ' . (int) $s['order'] . '">';
				}
				echo '</td><td>#' . (int) $s['order'] . ' · ' . esc_html( $s['package'] ?? 'Earlier label' ) . '</td><td>' . esc_html( $s['result']['tracking_number'] ?? 'Pending' ) . '</td><td>' . esc_html( str_replace( '_', ' ', $s['state'] ) ) . ( $reason ? ' — ' . esc_html( $reason ) : '' ) . '</td></tr>';
			}echo '</tbody></table>';
			if ( $selectable ) {
				echo '<p><label><input type="checkbox" name="ready" value="yes" required> The selected parcels are ready for handoff at the selected location.</label></p><button class="button button-primary">Transmit selected parcels electronically</button></form>';
			}
			if ( ! empty( $b['manifest_links'] ) ) {
				self::form( 'refresh', $key );
				echo '<button class="button button-primary">Refresh manifest confirmation</button></form>';} elseif ( in_array( $b['state'], array( 'uncertain', 'transmitting' ), true ) ) {
							self::form( 'recover_transmission', $key );
							echo '<button class="button">Recover transmission (read only)</button></form>';}
				foreach ( $b['manifests'] as $i => $m ) {
					$url = wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'wlp_contract_manifest_pdf',
								'batch'  => $key,
								'index'  => $i,
							),
							admin_url( 'admin-post.php' )
						),
						'wlp_contract_manifest_pdf'
					);
					echo '<p><strong>Confirmed electronic manifest ' . esc_html( $m['po'] ) . '</strong> · ' . esc_html( WLP_Contract_Handoff::describe( $m['handoff'] ?? $b['config'] ) ) . ' · <a target="_blank" rel="noopener" href="' . esc_url( $url ) . '">Open / print Letter PDF</a></p>';}
				echo '<details><summary>Label recovery and void controls</summary><p>Recover interrupted purchases before buying again. Void unused labels before replacing them. Keep the request ID when contacting Canada Post.</p>';
				foreach ( $b['shipments'] as $rid => $s ) {
					echo '<p><strong>Order #' . (int) $s['order'] . '</strong> · Request ' . esc_html( $rid ) . '</p>';
					if ( in_array( $s['state'], array( 'requesting', 'uncertain' ), true ) ) {
						self::form( 'recover_label', $key, $rid );
						echo '<button class="button">Recover carrier label (read only)</button></form>';}
					if ( in_array( $s['state'], array( 'created', 'transmitted', 'voided' ), true ) ) {
						self::form( 'restore', $key, $rid );
						echo '<button class="button">' . ( $s['state'] === 'voided' ? 'Clear confirmed void from order' : 'Restore saved label to order/package' ) . '</button></form>';}
					if ( $s['state'] === 'created' && $b['state'] === 'open' ) {
							self::form( 'void', $key, $rid );
							echo '<label><input type="checkbox" name="confirm_void" value="yes" required> This unused label should be voided.</label> <button class="button">Void label</button></form>';}
					if ( in_array( $s['state'], array( 'voiding', 'void_uncertain' ), true ) ) {
						self::form( 'recover_void', $key, $rid );
						echo '<p>Void outcome needs confirmation. Do not reuse this label or repurchase.</p><button class="button">Check void outcome (read only)</button></form>';}
				}echo '</details><p><small>Group: ' . esc_html( $key ) . '</small></p></section>';
		}echo '</details></div>';
		echo '<script>document.querySelectorAll("form select[name=handoff_id]").forEach(function(select){select.form.addEventListener("change",function(event){if(event.target === select || event.target.name === "selected[]"){select.form.elements.ready.checked=false;}});});</script>';
	}
}
