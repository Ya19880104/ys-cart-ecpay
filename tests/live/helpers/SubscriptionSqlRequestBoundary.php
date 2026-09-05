<?php
/** Explicit request/config/catalog fixtures only. No coordinator, model or durable-store replacement. */
declare(strict_types=1);
namespace {
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
	function absint( mixed $v ): int { return abs( (int) $v ); }
	function sanitize_text_field( mixed $v ): string { return trim( (string) $v ); }
	function sanitize_email( mixed $v ): string { return trim( (string) $v ); }
	function sanitize_key( mixed $v ): string { return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $v ) ); }
	function wp_unslash( mixed $v ): mixed { return $v; }
	function wp_json_encode( mixed $v, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $v, $flags, $depth ); }
	function get_current_user_id(): int { return 7; }
	function is_user_logged_in(): bool { return true; }
	function current_time( string $type ): int|string { return 'timestamp' === $type ? 1788566400 : '2026-09-05 00:00:00'; }
	/** Explicit synthetic randomness boundary for the real private selector issuer only. */
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		$token = $GLOBALS['ecpay_sql_issuance_token'] ?? null;
		if ( 32 !== $length || $special || $extra || ! is_string( $token ) || 1 !== preg_match( '/\A[A-Za-z0-9]{32}\z/', $token ) ) { throw new \YSCartEcpay\Tests\Live\SubscriptionSqlFailure( 'issuance_randomness_invalid' ); }
		return $token;
	}
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$plugin = $GLOBALS['ecpay_sql_plugin'] ?? null;
		if ( null === $plugin ) { return $value; }
		if ( 'ys_ec_resolve_fulfillment_selection_v1' === $hook ) { return $plugin->resolve_fulfillment_selection( $value, $args[0] ?? [], $args[1] ?? [] ); }
		if ( 'ys_ec_claim_fulfillment_selection_v1' === $hook ) {
			$GLOBALS['ecpay_sql_claim_calls'] = (int) ( $GLOBALS['ecpay_sql_claim_calls'] ?? 0 ) + 1;
			if ( isset( $GLOBALS['ecpay_sql_before_real_claim'] ) ) { ( $GLOBALS['ecpay_sql_before_real_claim'] )(); }
			return $plugin->claim_fulfillment_selection( $value, $args[0] ?? [], $args[1] ?? [], $args[2] ?? [] );
		}
		return $value;
	}
	function get_transient( string $key ): never { throw new \YSCartEcpay\Tests\Live\SubscriptionSqlFailure( 'unexpected_transient_authority' ); }
	function set_transient( string $key, mixed $value, int $ttl ): never { throw new \YSCartEcpay\Tests\Live\SubscriptionSqlFailure( 'unexpected_transient_authority' ); }
	function delete_transient( string $key ): never { throw new \YSCartEcpay\Tests\Live\SubscriptionSqlFailure( 'unexpected_transient_authority' ); }
}
namespace YangSheep\Ecommerce\Shipping {
	/** Fixed catalog boundary, not the real registry/lifecycle/restriction-engine integration. */
	final class YSShippingRegistry {
		public static bool $enabled = true;
		public static string $fixtureProvider = 'ecpay';
		public static function configured_enabled_method_ids(): array { return self::$enabled ? [ 'home_old', 'ys_ec_ecpay_ship_unimart' ] : []; }
		public static function get( string $id ): ?object { return self::get_operable( $id ); }
		public static function get_operable( string $id ): ?object {
			if ( ! self::$enabled || ! in_array( $id, [ 'home_old', 'ys_ec_ecpay_ship_unimart' ], true ) ) { return null; }
			return new class( $id ) {
				public function __construct( private string $id ) {}
				public function get_id(): string { return $this->id; }
				public function get_provider(): string { return 'home_old' === $this->id ? 'provider-old' : YSShippingRegistry::$fixtureProvider; }
				public function get_type(): string { return 'home_old' === $this->id ? 'home' : 'cvs'; }
				public function calculate_cost( array $items, array $address = [] ): float { return 'home_old' === $this->id ? 75.0 : 65.0; }
			};
		}
		public static function get_available_for_subscription_profile( array $data, ?array $allowed ): array {
			$result = [];
			foreach ( self::configured_enabled_method_ids() as $id ) { if ( null === $allowed || in_array( $id, $allowed, true ) ) { $result[$id] = self::get_operable( $id ); } }
			return $result;
		}
	}
}
namespace YangSheep\YSCartEcpay\Support {
	final class ProviderMaintenanceLock {
		public static function reader_lease(): object { return (object) [ 'token' => 'offline-fixture-lease' ]; }
		public static function reader_fence( string $token ): bool { return 'offline-fixture-lease' === $token; }
	}
	final class Settings {
		public static function logistics_credentials_for_method( string $method ): array { return [ 'hash_key' => 'offline-synthetic-key', 'hash_iv' => 'offline-synthetic-iv' ]; }
		public static function shipping_method_option( string $method, string $key, string $default = '' ): string { return $default; }
	}
	final class ShippingMethodOperability {
		public static function is_operable( string $method ): bool { return 'ys_ec_ecpay_ship_unimart' === $method && \YangSheep\Ecommerce\Shipping\YSShippingRegistry::$enabled; }
	}
}
namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
	class EcpayShipping {
		public function supports_cod(): bool { return true; }
		public function get_logistics_subtype(): string { return 'UNIMARTC2C'; }
	}
	final class EcpayShippingCatalog {
		public const TEMP_ROOM = 'ROOM'; public const TEMP_CHILLED = 'CHILLED'; public const TEMP_FROZEN = 'FROZEN';
		public static function map_subtypes(): array { return [ 'ys_ec_ecpay_ship_unimart' => 'UNIMARTC2C' ]; }
		public static function get( string $id ): ?array {
			return 'ys_ec_ecpay_ship_unimart' !== $id ? null : [ 'class' => EcpayShipping::class, 'logistics_type' => 'CVS', 'logistics_subtype' => 'UNIMARTC2C',
				'requires_store' => true, 'cod_capable' => true, 'temperature' => 'ROOM', 'requires_goods_weight' => false ];
		}
	}
}
