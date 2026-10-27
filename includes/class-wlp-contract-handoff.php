<?php
/** Approved carrier handoff points, selected independently of the sender address. */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

final class WLP_Contract_Handoff {

	public const DEFAULT_ID = 'hamilton-pickup';

	/** Returns the same operational handoff choices used by Medusa. */
	public static function locations(): array {
		return array(
			'hamilton-pickup' => array( 'name' => 'Pickup — 260 King St. E, Hamilton', 'postal' => 'L8N1B7', 'pickup' => true ),
			'jackson-square' => array( 'name' => 'Drop-off — Jackson Square Canada Post', 'postal' => 'L8P4S0', 'pickup' => false ),
			'wellington-shoppers' => array( 'name' => 'Drop-off — Cannon & Wellington Shoppers', 'postal' => 'L8L0A0', 'pickup' => false ),
			'sherman-main-shoppers' => array( 'name' => 'Drop-off — Sherman & Main Shoppers', 'postal' => 'L8M1L0', 'pickup' => false ),
		);
	}

	/** Resolves an allowlisted ID; callers cannot submit an arbitrary shipping point. */
	public static function resolve( string $id ): array {
		$point = self::locations()[ $id ] ?? null;
		if ( ! $point ) {
			throw new RuntimeException( 'Choose a valid pickup or drop-off location.' );
		}
		return array( 'id' => $id ) + $point;
	}

	/** Describes saved snapshots, including records made before the location selector. */
	public static function describe( array $point ): string {
		return ( $point['name'] ?? ( $point['pickup'] ? 'Pickup' : 'Drop-off' ) ) . ' · ' . $point['postal'];
	}
}
