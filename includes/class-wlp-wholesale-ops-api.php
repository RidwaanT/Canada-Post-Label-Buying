<?php
/**
 * Purpose: Exposes privacy-minimized wholesale operations summaries to authenticated store managers.
 *
 * @package WooLogisticsPlugin
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wlp-wholesale-intake.php';

/**
 * Read-only REST interface for compact wholesale order and profit reporting.
 */
final class WLP_Wholesale_Ops_API {
	private const NAMESPACE = 'wc/v3/wlp-wholesale-ops';

	public function __construct( private WLP_Admin $admin ) {
	}

	/**
	 * Registers private read-only routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/wholesale-orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_orders' ),
				'permission_callback' => array( $this, 'can_read_orders' ),
				'args'                => $this->collection_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wholesale-orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( $this, 'can_read_orders' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Requires a WooCommerce-capable authenticated account.
	 */
	public function can_read_orders(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Returns one privacy-minimized wholesale record.
	 */
	public function get_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wlp_order_not_found', __( 'Order not found.', 'woo-logistics-plugin' ), array( 'status' => 404 ) );
		}

		$payload = $this->order_payload( $order );
		if ( empty( $payload ) ) {
			return new WP_Error( 'wlp_not_wholesale', __( 'This is not a wholesale order.', 'woo-logistics-plugin' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Returns filtered wholesale records and aggregate contribution totals.
	 */
	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$statuses = array_filter( array_map( 'sanitize_key', explode( ',', (string) $request->get_param( 'status' ) ) ) );
		$args = array(
			'limit'      => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'page'       => max( 1, (int) $request->get_param( 'page' ) ),
			'paginate'   => true,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => ! empty( $statuses ) ? $statuses : array( 'pending', 'on-hold', 'processing', 'completed' ),
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array( 'key' => WLP_Meta_Keys::IS_WHOLESALE, 'value' => 'yes' ),
				array( 'key' => '_pg_is_wholesale', 'value' => 'yes' ),
				array( 'key' => '_pg_order_source', 'value' => 'wholesale_quote' ),
			),
		);

		$after  = trim( (string) $request->get_param( 'after' ) );
		$before = trim( (string) $request->get_param( 'before' ) );
		if ( '' !== $after || '' !== $before ) {
			$args['date_created'] = ( '' !== $after ? $after : '1970-01-01' ) . '...' . ( '' !== $before ? $before : gmdate( 'Y-m-d' ) );
		}

		$result = wc_get_orders( $args );
		$orders = array();
		$totals = array(
			'revenue'         => 0.0,
			'costs'           => 0.0,
			'profit'          => 0.0,
			'incompleteCosts' => 0,
			'realizedCount'   => 0,
			'pipelineCount'   => 0,
			'pipelineQuoted'  => 0.0,
		);

		foreach ( $result->orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$payload = $this->order_payload( $order );
			if ( empty( $payload ) ) {
				continue;
			}

			$orders[] = $payload;
			if ( $payload['isRealized'] ) {
				++$totals['realizedCount'];
				$totals['revenue'] += (float) $payload['profit']['revenue'];
				$totals['costs'] += (float) $payload['profit']['totalCosts'];
				$totals['profit'] += (float) $payload['profit']['amount'];
				if ( ! $payload['profit']['supplierCoverage']['complete'] ) {
					++$totals['incompleteCosts'];
				}
			} else {
				++$totals['pipelineCount'];
				$totals['pipelineQuoted'] += (float) $payload['orderTotal'];
			}
		}

		$count = count( $orders );
		$weighted_margin = $totals['revenue'] > 0 ? ( $totals['profit'] / $totals['revenue'] ) * 100 : 0.0;

		return rest_ensure_response(
			array(
				'orders' => $orders,
				'summary' => array(
					'count'              => $count,
					'realizedCount'      => $totals['realizedCount'],
					'pipelineCount'      => $totals['pipelineCount'],
					'pipelineQuotedTotal' => number_format( $totals['pipelineQuoted'], 2, '.', '' ),
					'revenue'            => number_format( $totals['revenue'], 2, '.', '' ),
					'totalCosts'         => number_format( $totals['costs'], 2, '.', '' ),
					'profit'             => number_format( $totals['profit'], 2, '.', '' ),
					'weightedMarginPct'  => number_format( $weighted_margin, 1, '.', '' ),
					'incompleteCostOrders' => $totals['incompleteCosts'],
				),
				'pagination' => array(
					'page'       => max( 1, (int) $request->get_param( 'page' ) ),
					'total'      => (int) $result->total,
					'totalPages' => (int) $result->max_num_pages,
				),
			)
		);
	}

	/** Adds a backwards-compatible structured intake projection to existing reporting fields. */
	private function order_payload( WC_Order $order ): array {
		if ( ! WLP_Wholesale_Intake::is_wholesale( $order ) ) { return array(); }
		$payload = $this->admin->wholesale_ops_payload( $order );
		if ( empty( $payload ) ) { return array(); }
		$payload['orderNumber'] = (string) $order->get_order_number();
		$payload['intake'] = WLP_Wholesale_Intake::project( $order );
		return $payload;
	}

	/**
	 * Defines and validates compact collection filters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function collection_args(): array {
		$date_arg = array(
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static fn( $value ): bool => '' === (string) $value || 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ),
		);

		return array(
			'after'    => $date_arg,
			'before'   => $date_arg,
			'status'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'page'     => array( 'required' => false, 'default' => 1, 'sanitize_callback' => 'absint' ),
			'per_page' => array( 'required' => false, 'default' => 50, 'sanitize_callback' => 'absint' ),
		);
	}
}
