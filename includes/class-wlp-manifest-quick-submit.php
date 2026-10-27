<?php
/** Queue explicit manifest selections without waiting for carrier responses in the packing screen. */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

trait WLP_Manifest_Quick_Submit {
	private const QUICK_JOB = 'wlp_manifest_quick_job_v1';
	private const QUICK_LOCK = 'wlp_manifest_quick_queue_lock';

	/** Saved records only. No carrier request is made by this status endpoint. */
	public static function quick_snapshot(): array {
		$all = self::read();
		$job = get_option( self::QUICK_JOB, array() );
		$locations = array();
		foreach ( WLP_Contract_Handoff::locations() as $id => $point ) {
			$locations[] = array( 'id' => $id, 'label' => WLP_Contract_Handoff::describe( $point ) );
		}
		$parcels = array();
		foreach ( self::ready_candidates( $all ) as $token => $row ) {
			if ( $row['day'] > wp_date( 'Y-m-d' ) ) { continue; }
			$parcels[] = array( 'token' => $token, 'label' => '#' . $row['order'] . ' · ' . $row['package'] . ' · label ' . $row['day'] );
		}
		$summary = self::reminder_summary( $all );
		$summary['ready'] = count( $parcels );
		return $summary + array(
			'day' => wp_date( 'Y-m-d' ), 'locations' => $locations, 'parcels' => $parcels,
			'job' => $job ? array_intersect_key( $job, array_flip( array( 'id', 'state', 'count', 'message', 'updated' ) ) ) : null,
			'queue_available' => function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ),
		);
	}

	private static function quick_save( array $job ): void {
		$job['updated'] = time();
		update_option( self::QUICK_JOB, $job, false );
		if ( get_option( self::QUICK_JOB ) !== $job ) { throw new RuntimeException( 'Could not save manifest job. Review Shipping manifests before retrying.' ); }
	}

	/** Permission, nonce, physical readiness and exact snapshot are validated before queuing. */
	public static function quick_submit(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 ); }
		check_ajax_referer( 'wlp_logistics', 'nonce' );
		$locked = false;
		try {
			if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) { throw new RuntimeException( 'Background processing is unavailable. Use Review manifests.' ); }
			$id = $_POST['request_id'] ?? '';
			if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id ) ) { throw new RuntimeException( 'Invalid submission ID.' ); }
			$locked = add_option( self::QUICK_LOCK, time(), '', 'no' );
			if ( ! $locked ) { throw new RuntimeException( 'Another manifest submission is being saved. Check status before retrying.' ); }
			$previous = get_option( self::QUICK_JOB, array() );
			if ( $previous && $previous['id'] === $id ) {
				// A lost browser response cannot enqueue the same intent twice.
				delete_option( self::QUICK_LOCK );
				$locked = false;
				wp_send_json_success( self::quick_snapshot() );
				return;
			}
			if ( $previous && in_array( $previous['state'], array( 'queued', 'processing', 'awaiting_confirmation' ), true ) ) { throw new RuntimeException( 'A manifest job is already running. Keep packing and check its status.' ); }
			$input = wp_unslash( $_POST );
			$groups = self::finish_day_groups( $input );
			if ( get_option( self::LOCK ) ) { throw new RuntimeException( 'A carrier operation is running or needs recovery. Review manifests.' ); }
			$job = array( 'id' => $id, 'state' => 'queued', 'input' => array_intersect_key( $input, array_flip( array( 'parcels', 'handoff_id', 'pickup_day', 'ready' ) ) ), 'groups' => $groups, 'count' => count( array_unique( $input['parcels'] ) ), 'message' => 'Queued. You can keep packing; Canada Post confirmation is still required.' );
			self::quick_save( $job );
			if ( ! as_enqueue_async_action( 'wlp_manifest_quick_worker', array( $id, 0 ), 'wlp-manifests', true ) ) {
				$job['state'] = 'needs_review';
				$job['message'] = 'Could not start background processing. No carrier submission was started. Review manifests.';
				self::quick_save( $job );
				throw new RuntimeException( $job['message'] );
			}
		} catch ( Throwable $error ) {
			if ( $locked ) { delete_option( self::QUICK_LOCK ); }
			wp_send_json_error( array( 'message' => $error->getMessage() ), 409 );
			return;
		}
		delete_option( self::QUICK_LOCK );
		wp_send_json_success( self::quick_snapshot() );
	}

	/** Explicit recovery releases only old queue locks, never resends a carrier request. */
	public static function quick_release(): void {
		$job = get_option( self::QUICK_JOB, array() );
		$worker = get_option( 'wlp_manifest_quick_worker_lock' );
		$queue = get_option( self::QUICK_LOCK );
		$newest = max( (int) ( $job['updated'] ?? 0 ), (int) ( $worker['started'] ?? 0 ), (int) $queue );
		if ( ! $newest || time() - $newest < 900 ) { throw new RuntimeException( 'Wait at least 15 minutes before releasing an interrupted manifest job.' ); }
		global $wpdb;
		foreach ( array( 'wlp_manifest_quick_worker_lock' => $worker, self::QUICK_LOCK => $queue ) as $name => $value ) {
			if ( $value ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, maybe_serialize( $value ) ) );
				wp_cache_delete( $name, 'options' );
			}
		}
		if ( $job && in_array( $job['state'], array( 'queued', 'processing', 'awaiting_confirmation' ), true ) ) {
			$job['state'] = 'needs_review';
			$job['message'] = 'Interrupted job released. Review existing carrier records before submitting any remaining parcels.';
			self::quick_save( $job );
		}
	}

	/** Never repeat a carrier POST. Later runs only refresh saved manifest references. */
	public static function quick_worker( string $id, int $attempt = 0 ): void {
		$worker_lock = 'wlp_manifest_quick_worker_lock';
		if ( ! add_option( $worker_lock, array( 'id' => $id, 'started' => time() ), '', 'no' ) ) { return; }
		try {
			$job = get_option( self::QUICK_JOB, array() );
			if ( ! $job || $job['id'] !== $id || ! in_array( $job['state'], array( 'queued', 'awaiting_confirmation' ), true ) ) { return; }
			if ( $job['state'] === 'queued' ) {
				try {
					// Recheck every parcel, account and store date after waiting in the queue.
					$groups = self::finish_day_groups( $job['input'] );
					if ( $groups !== $job['groups'] ) { throw new RuntimeException( 'The parcel selection changed. Review manifests.' ); }
					$job['state'] = 'processing';
					$job['message'] = 'Submitting your selected parcels to Canada Post. You can keep packing.';
					self::quick_save( $job );
					foreach ( $groups as $key => $requests ) { self::transmit( $key, $requests, $job['input']['handoff_id'], false ); }
					$job['state'] = 'awaiting_confirmation';
				} catch ( Throwable $error ) {
					$job['state'] = 'needs_review';
					$job['message'] = 'Submission needs review. Some parcels may already be submitted. Review manifests before retrying.';
					self::quick_save( $job );
					return;
				}
			}
			foreach ( array_keys( $job['groups'] ) as $key ) {
				if ( empty( self::read()[ $key ]['manifest_links'] ) ) { continue; }
				try { self::refresh( $key ); } catch ( Throwable $error ) { /* Only saved references are retried; never a transmission. */ }
			}
			$all = self::read();
			$confirmed = true;
			foreach ( $job['groups'] as $key => $requests ) {
				foreach ( $requests as $rid ) { $confirmed = $confirmed && ( $all[ $key ]['shipments'][ $rid ]['state'] ?? '' ) === 'transmitted'; }
			}
			$job['state'] = $confirmed ? 'complete' : ( $attempt >= 5 ? 'needs_review' : 'awaiting_confirmation' );
			$job['message'] = $confirmed ? $job['count'] . ' parcel(s) confirmed by Canada Post. Manifest PDFs are available in Review manifests.' : ( $attempt >= 5 ? 'Confirmation is still pending. Review manifests before handoff; do not submit these parcels again.' : 'Submitted. Waiting for Canada Post confirmation; you can keep packing.' );
			self::quick_save( $job );
			if ( $job['state'] === 'awaiting_confirmation' && ! as_schedule_single_action( time() + 30, 'wlp_manifest_quick_worker', array( $id, $attempt + 1 ), 'wlp-manifests', true ) ) {
				$job['state'] = 'needs_review';
				$job['message'] = 'Submitted, but automatic confirmation checks could not be scheduled. Review manifests; do not resubmit.';
				self::quick_save( $job );
			}
		} finally { delete_option( $worker_lock ); }
	}
}
