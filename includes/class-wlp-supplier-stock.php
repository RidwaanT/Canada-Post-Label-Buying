<?php
/** Private supplier availability desk. No customer prices or order mutations. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WLP_Supplier_Stock {
	public const META = '_pgo_supplier_availability_v1';
	private const COSTS = 'wlp_supplier_stock_costs_v1';
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_wlp_supplier_stock_save', array( self::class, 'save' ) );
	}
	public static function menu(): void {
		add_menu_page( 'Supplier Stock', 'Supplier Stock', 'manage_woocommerce', 'wlp-supplier-stock', array( self::class, 'render' ), 'dashicons-archive', 56 );
		add_submenu_page( 'woocommerce', 'Supplier Stock', 'Supplier Stock', 'manage_woocommerce', 'wlp-supplier-stock', array( self::class, 'render' ) );
	}
	private static function products(): array {
		return wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'pending', 'private' ), 'orderby' => 'title', 'order' => 'ASC', 'meta_key' => '_peptidego_wholesale_listing', 'meta_value' => 'yes' ) );
	}
	private static function data( $product ): array {
		$data = $product->get_meta( self::META, true );
		return is_array( $data ) && 1 === ( $data['schema_version'] ?? null ) ? $data : array( 'schema_version' => 1, 'suppliers' => array() );
	}
	private static function blocked( $product ): bool {
		$block = $product->get_meta( '_pgo_wholesale_sales_blocked', true );
		return 'publish' !== $product->get_status() || ( '' === $block ? 'out_of_stock' === $product->get_meta( '_peptidego_wholesale_availability', true ) : 'yes' === $block );
	}
	public static function availability( array $suppliers, int $on_hand, int $reserved, bool $blocked ): string {
		if ( $blocked ) { return 'out_of_stock'; }
		foreach ( array( 'xp', 'jkl' ) as $source ) {
			if ( 'on_demand' === ( $suppliers[$source]['availability'] ?? '' ) ) { return 'available'; }
		}
		return $on_hand > $reserved ? 'available' : 'out_of_stock';
	}
	private static function revision( $product ): string {
		return hash( 'sha256', wp_json_encode( array( self::data( $product ), $product->get_status(), $product->get_meta( '_peptidego_wholesale_availability', true ), $product->get_meta( '_pgo_wholesale_sales_blocked', true ), get_option( self::COSTS . '_' . $product->get_id(), array() ) ) ) );
	}
	private static function fail( string $message ): void { wp_die( esc_html( $message ), 'Supplier Stock', array( 'response' => 409 ) ); }
	public static function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wlp_supplier_stock' );
		$id = absint( $_POST['product_id'] ?? 0 );
		$p = wc_get_product( $id );
		if ( ! $p || 'yes' !== $p->get_meta( '_peptidego_wholesale_listing', true ) || ! current_user_can( 'edit_post', $id ) ) { self::fail( 'Wholesale product not found or not editable.' ); return; }
		$revision = sanitize_text_field( wp_unslash( $_POST['revision'] ?? '' ) );
		if ( ! hash_equals( self::revision( $p ), $revision ) ) { self::fail( 'This product changed. Reload Supplier Stock before saving.' ); return; }
		$data = self::data( $p );
		$entry = get_option( self::COSTS . '_' . $id, array() );
		if ( ! is_array( $entry ) ) { self::fail( 'Invalid cost storage; no changes saved.' ); return; }
		foreach ( array( 'xp', 'jkl' ) as $source ) {
			$status = sanitize_key( $_POST[$source] ?? '' );
			if ( ! in_array( $status, array( 'unknown', 'on_demand', 'unavailable' ), true ) ) { self::fail( 'Invalid supplier availability.' ); return; }
			$prior = $data['suppliers'][$source] ?? array();
			if ( $status !== ( $prior['availability'] ?? 'unknown' ) ) {
				$data['suppliers'][$source] = array_merge( $prior, array( 'availability' => $status, 'updated_at' => gmdate( 'c' ), 'source' => 'admin', 'site_exclusion_applied' => false ) );
			}
			$amount = trim( (string) wp_unslash( $_POST[$source . '_cost'] ?? '' ) );
			$currency = sanitize_key( $_POST[$source . '_currency'] ?? '' );
			$unit = sanitize_key( $_POST[$source . '_unit'] ?? '' );
			if ( '' !== $amount ) {
				if ( ! preg_match( '/^\d{1,7}(?:\.\d{1,2})?$/D', $amount ) || ! in_array( $currency, array( 'cad', 'usd' ), true ) || ! in_array( $unit, array( 'vial', 'kit_10' ), true ) ) { self::fail( 'Enter a valid cost, currency and unit. Nothing saved.' ); return; }
				$entry[$source] = array( 'amount' => number_format( (float) $amount, 2, '.', '' ), 'currency' => strtoupper( $currency ), 'unit' => $unit );
			} else { unset( $entry[$source] ); }
		}
		$counts = array();
		$hub_unknown = '' === ( $_POST['on_hand'] ?? '' ) && '' === ( $_POST['reserved'] ?? '' );
		foreach ( array( 'on_hand', 'reserved' ) as $field ) {
			$value = $hub_unknown ? (string) ( $data['hamilton'][$field] ?? 0 ) : (string) ( $_POST[$field] ?? '' );
			if ( ! preg_match( '/^\d{1,7}$/D', $value ) ) { self::fail( 'Hamilton counts must be whole vials.' ); return; }
			$counts[$field] = (int) $value;
		}
		if ( $counts['reserved'] > $counts['on_hand'] ) { self::fail( 'Reserved vials cannot exceed Hamilton stock.' ); return; }
		$blocked = 'publish' !== $p->get_status() || isset( $_POST['blocked'] );
		$known = isset( $data['suppliers']['xp'] ) || isset( $data['suppliers']['jkl'] ) || $counts['on_hand'] > 0;
		if ( ! $blocked && ! $known ) { self::fail( 'Confirm a supplier or Hamilton stock before changing site availability.' ); return; }
		if ( ! $hub_unknown ) { $data['hamilton'] = $counts; }
		$p->update_meta_data( self::META, $data );
		$p->update_meta_data( '_pgo_wholesale_sales_blocked', $blocked ? 'yes' : 'no' );
		$p->update_meta_data( '_peptidego_wholesale_availability', self::availability( $data['suppliers'], $counts['on_hand'], $counts['reserved'], $blocked ) );
		$p->save();
		update_option( self::COSTS . '_' . $id, $entry, false );
		wp_safe_redirect( admin_url( 'admin.php?page=wlp-supplier-stock&saved=' . $id . '#product-' . $id ) );
		exit;
	}
	private static function select( string $name, string $value, array $options ): void {
		echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( str_replace( '_', ' ', $name ) ) . '">';
		foreach ( $options as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select>';
	}
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$products = array_filter( self::products(), static fn( $p ) => 'yes' === $p->get_meta( '_peptidego_wholesale_listing', true ) );
		$xp_count = count( array_filter( $products, static fn( $p ) => 'on_demand' === ( self::data( $p )['suppliers']['xp']['availability'] ?? '' ) ) );
		echo '<div class="wrap"><h1>Supplier Stock</h1><p>Wholesale products and exact strengths. Supplier costs stay private. Changes never publish draft products or change customer prices.</p>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Product stock saved.</p></div>'; }
		echo '<p><strong>' . esc_html( (string) $xp_count ) . ' XP products available on demand</strong> · ' . esc_html( (string) count( $products ) ) . ' wholesale records</p>';
		echo '<p><label for="wlp-stock-search">Find product or strength </label><input type="search" id="wlp-stock-search" placeholder="Search products…"></p>';
		echo '<p>Blocked listings stay blocked until you deliberately clear “Block wholesale sales”. Hamilton counts are entered manually in vials; order reservations are not automatic.</p>';
		echo '<style>.wlp-stock-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:14px 0;max-width:1150px}.wlp-stock-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}.wlp-stock-card label{display:block;margin:8px 0}.wlp-stock-card input[type=number]{width:110px}.wlp-stock-card h2{margin-top:0}.wlp-stock-card small{display:block;color:#646970;margin-top:8px}.wlp-stock-card select{max-width:100%}@media(max-width:900px){.wlp-stock-grid{grid-template-columns:1fr}}</style>';
		foreach ( $products as $p ) {
			$id = $p->get_id(); $costs = get_option( self::COSTS . '_' . $id, array() ); $data = self::data( $p ); $blocked = self::blocked( $p );
			echo '<form class="wlp-stock-card" id="product-' . esc_attr( (string) $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-name="' . esc_attr( strtolower( $p->get_name() ) ) . '">';
			wp_nonce_field( 'wlp_supplier_stock' );
			echo '<input type="hidden" name="action" value="wlp_supplier_stock_save"><input type="hidden" name="product_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="revision" value="' . esc_attr( self::revision( $p ) ) . '">';
			echo '<h2>' . esc_html( $p->get_name() ) . '</h2><p>Website: <strong>' . esc_html( 'publish' !== $p->get_status() ? 'Unpublished' : ( 'out_of_stock' === $p->get_meta( '_peptidego_wholesale_availability', true ) ? 'Out of stock' : 'Available' ) ) . '</strong></p><div class="wlp-stock-grid">';
			foreach ( array( 'xp', 'jkl' ) as $source ) {
				$record = $data['suppliers'][$source] ?? array(); $cost = $costs[$source] ?? array();
				echo '<fieldset><legend><strong>' . esc_html( strtoupper( $source ) ) . '</strong></legend>';
				self::select( $source, $record['availability'] ?? 'unknown', array( 'unknown' => 'Needs confirmation', 'on_demand' => 'Available on demand', 'unavailable' => 'Unavailable' ) );
				echo '<label>Your cost <input type="number" step="0.01" min="0" name="' . esc_attr( $source . '_cost' ) . '" value="' . esc_attr( $cost['amount'] ?? '' ) . '" placeholder="Not set"></label>';
				self::select( $source . '_currency', strtolower( $cost['currency'] ?? '' ), array( '' => 'Currency…', 'cad' => 'CAD', 'usd' => 'USD' ) );
				self::select( $source . '_unit', $cost['unit'] ?? '', array( '' => 'Cost per…', 'vial' => 'Vial', 'kit_10' => '10-vial kit' ) );
				echo '<small>Last confirmed: ' . esc_html( $record['updated_at'] ?? 'Not recorded' ) . '</small></fieldset>';
			}
			echo '<fieldset><legend><strong>Hamilton hub</strong></legend>';
			foreach ( array( 'on_hand' => 'Vials on hand', 'reserved' => 'Vials reserved' ) as $key => $label ) { echo '<label>' . esc_html( $label ) . ' <input type="number" min="0" step="1" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $data['hamilton'][$key] ?? '' ) ) . '" placeholder="Not recorded"></label>'; }
			echo '<small>Available: ' . esc_html( isset( $data['hamilton'] ) ? (string) max( 0, $data['hamilton']['on_hand'] - $data['hamilton']['reserved'] ) . ' vials' : 'Not recorded' ) . '</small></fieldset></div>';
			echo '<label><input type="checkbox" name="blocked" value="yes" ' . checked( $blocked, true, false ) . ( 'publish' !== $p->get_status() ? ' disabled' : '' ) . '> Block wholesale sales</label><button class="button button-primary" type="submit">Save this product</button></form>';
		}
		echo '<script>document.getElementById("wlp-stock-search").addEventListener("input",function(){const q=this.value.toLowerCase().trim();document.querySelectorAll(".wlp-stock-card").forEach(function(card){card.hidden=!card.dataset.name.includes(q);});});</script></div>';
	}
}
