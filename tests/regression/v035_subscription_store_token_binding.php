<?php
/**
 * Behavioral regression: both a fresh ECPay map selection and an owned saved
 * store reauthorization retain exact subscription scope/payment/principal
 * binding and remain one-use claims.
 *
 * Run: php -n tests/regression/v035_subscription_store_token_binding.php
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['v035_user_id'] = 7;
	$GLOBALS['v035_admin'] = false;
	$GLOBALS['v035_password_counter'] = 0;
	$GLOBALS['v035_transients'] = [];

	function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
	function sanitize_key( mixed $value ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
	}
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function esc_url_raw( string $url ): string { return $url; }
	function get_current_user_id(): int { return (int) $GLOBALS['v035_user_id']; }
	function is_user_logged_in(): bool { return get_current_user_id() > 0; }
	function current_user_can( string $capability ): bool {
		return 'manage_options' === $capability && true === $GLOBALS['v035_admin'];
	}
	function current_time( string $type ): int|string {
		return 'timestamp' === $type ? 1788062400 : '2026-08-30 12:00:00';
	}
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		++$GLOBALS['v035_password_counter'];
		$char = chr( 65 + ( $GLOBALS['v035_password_counter'] % 26 ) );
		return str_repeat( $char, $length );
	}
	function set_transient( string $key, mixed $value, int $ttl ): bool {
		$GLOBALS['v035_transients'][ $key ] = $value;
		return true;
	}
	function get_transient( string $key ): mixed { return $GLOBALS['v035_transients'][ $key ] ?? false; }
	function delete_transient( string $key ): bool {
		if ( ! array_key_exists( $key, $GLOBALS['v035_transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['v035_transients'][ $key ] );
		return true;
	}
	function rest_url( string $path = '' ): string { return 'https://shop.example.test/wp-json/' . ltrim( $path, '/' ); }
	function wp_is_mobile(): bool { return false; }
	function home_url( string $path = '' ): string { return 'https://shop.example.test' . $path; }
	function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
	function add_query_arg( array $args, string $url ): string {
		$separator = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $separator . http_build_query( $args );
	}

	final class WP_REST_Request {
		/** @param array<string,mixed> $body */
		public function __construct( private array $body = [] ) {}
		/** @return array<string,mixed> */
		public function get_json_params(): array { return $this->body; }
		/** @return array<string,mixed> */
		public function get_body_params(): array { return []; }
		/** @return array<string,mixed> */
		public function get_query_params(): array { return []; }
	}

	final class WP_REST_Response {
		/** @var array<string,string> */
		public array $headers = [];
		/** @param array<string,mixed> $data */
		public function __construct( public array $data, private int $status ) {}
		public function get_status(): int { return $this->status; }
		public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	}

	final class V035Wpdb {
		public string $prefix = 'wp_';
		/** @var list<mixed> */
		public array $prepare_args = [];
		/** @var array<string,mixed> */
		public array $address = [
			'id'                 => 81,
			'customer_id'        => 91,
			'shipping_type'      => 'cvs',
			'shipping_provider'  => 'ecpay',
			'shipping_method_id' => 'ys_ec_ecpay_ship_unimart',
			'cvs_store_id'       => '991122',
		];
		public function prepare( string $sql, mixed ...$args ): string {
			$this->prepare_args = $args;
			return $sql;
		}
		/** @return array<string,mixed> */
		public function get_row( string $sql, string $output ): array {
			return [ 81, 91 ] === $this->prepare_args ? $this->address : [];
		}
	}
	$GLOBALS['wpdb'] = new V035Wpdb();
}

namespace YangSheep\Ecommerce\Api\Storefront {
	final class YSRequestParser {
		/** @return array<string,mixed> */
		public static function params( \WP_REST_Request $request ): array {
			$params = $request->get_json_params();
			return [] !== $params ? $params : $request->get_body_params();
		}
	}

	final class YSRestResponder {
		public static function error( string $code, string $message, int $status = 400, array $data = [] ): \WP_REST_Response {
			return new \WP_REST_Response(
				[ 'success' => false, 'code' => $code, 'message' => $message, 'data' => $data ],
				$status
			);
		}
		public static function success( string $code, string $message, array $data = [] ): \WP_REST_Response {
			return new \WP_REST_Response(
				[ 'success' => true, 'code' => $code, 'message' => $message, 'data' => $data ],
				200
			);
		}
	}

	final class YSRestAuth {
		public static function permission_customer_or_guest(): bool { return true; }
		public static function permission_customer_or_guest_write(): bool { return true; }
		public static function permission_logged_in_write(): bool { return true; }
		public static function validate_guest_token( string $token ): bool { return false; }
		public static function get_guest_token(): ?string { return null; }
	}
}

namespace YangSheep\Ecommerce\Services\Setup {
	final class YSPageResolver {
		public static function checkout_url(): string { return 'https://shop.example.test/checkout/'; }
	}
}

namespace YangSheep\Ecommerce\Gateways {
	final class YSGatewayRegistry {
		public static function get( string $id ): ?object {
			return 'ys_ec_ecpay_credit' === $id ? (object) [ 'id' => $id ] : null;
		}
	}
}

namespace YangSheep\Ecommerce\Models {
	final class YSCustomer {
		public static function find_by_user_id( int $id ): ?object {
			if ( 7 === $id ) {
				return (object) [ 'id' => 91, 'user_id' => 7 ];
			}
			return $id > 0 ? (object) [ 'id' => 199, 'user_id' => $id ] : null;
		}
	}

	final class YSSubscription {
		/** @var array<int,object> */
		public static array $rows = [];
		public static function find( int $id ): ?object { return self::$rows[ $id ] ?? null; }
	}
}

namespace YangSheep\Ecommerce\Shipping {
	final class YSShippingRegistry {
		public static bool $allowed = true;
		/** @var list<array{method:string,items:array<int,array<string,mixed>>}> */
		public static array $calls = [];
		public static function is_method_allowed_for_cart( string $method, array $items ): bool {
			self::$calls[] = [ 'method' => $method, 'items' => $items ];
			return self::$allowed;
		}
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class CheckMacValue {
		/** @param array<string,string> $fields */
		public static function generate( array $fields, string $key, string $iv, string $mode ): string { return 'CHECKMAC'; }
	}

	final class ProviderMaintenanceLock {
		public static function reader_lease(): object { return (object) [ 'token' => 'lease-token' ]; }
		public static function reader_fence( string $token ): bool { return 'lease-token' === $token; }
	}

	final class Settings {
		/** @return array{merchant_id:string,hash_key:string,hash_iv:string} */
		public static function logistics_credentials_for_method( string $method ): array {
			return [ 'merchant_id' => '2000132', 'hash_key' => 'key', 'hash_iv' => 'iv' ];
		}
		public static function logistics_endpoint( string $path, string $method ): string {
			return 'https://logistics-stage.ecpay.test' . $path;
		}
		public static function get( string $key, string $default = '' ): string { return $default; }
	}

	final class ShippingMethodOperability {
		public static function is_operable( string $method ): bool {
			return in_array( $method, [ 'ys_ec_ecpay_ship_unimart', 'ys_ec_ecpay_ship_hilife' ], true );
		}
		public static function has_operable_method(): bool { return true; }
		public static function is_configured( string $method ): bool { return self::is_operable( $method ); }
	}
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
	class EcpayShipping {
		public function supports_cod(): bool { return true; }
		public function get_logistics_subtype(): string { return 'UNIMARTC2C'; }
	}

	final class V035EcpayShipping extends EcpayShipping {}

	final class EcpayShippingCatalog {
		/** @return array<string,mixed>|null */
		public static function get( string $id ): ?array {
			if ( ! in_array( $id, [ 'ys_ec_ecpay_ship_unimart', 'ys_ec_ecpay_ship_hilife' ], true ) ) {
				return null;
			}
			$is_unimart = 'ys_ec_ecpay_ship_unimart' === $id;
			return [
				'class'               => V035EcpayShipping::class,
				'logistics_type'      => 'CVS',
				'logistics_subtype'   => $is_unimart ? 'UNIMARTC2C' : 'HILIFEC2C',
				'requires_store'      => true,
				'cod_capable'         => true,
				'temperature'         => 'ROOM',
				'requires_goods_weight' => false,
			];
		}
		/** @return array<string,string> */
		public static function map_subtypes(): array {
			return [
				'ys_ec_ecpay_ship_unimart' => 'UNIMARTC2C',
				'ys_ec_ecpay_ship_hilife'  => 'HILIFEC2C',
			];
		}
	}

	final class EcpayStoreDirectory {
		public static int $refresh_calls = 0;
		/** @return array{name:string,address:string,phone:string} */
		public static function lookup( string $subtype, string $store_id ): array {
			if ( 'UNIMARTC2C' !== $subtype || '991122' !== $store_id ) {
				return [];
			}
			return [ 'name' => 'Canonical Store', 'address' => 'No. 1 Store Rd.', 'phone' => '0212345678' ];
		}
		public static function schedule_refresh_soon(): void { ++self::$refresh_calls; }
	}
}

namespace {
	use YangSheep\Ecommerce\Models\YSSubscription;
	use YangSheep\Ecommerce\Shipping\YSShippingRegistry;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpaySavedStoreReauthorizer;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;

	$root = dirname( __DIR__, 2 );
	require_once $root . '/src/Support/CartScope.php';
	require_once $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php';
	require_once $root . '/src/Shipping/Ecpay/EcpaySavedStoreReauthorizer.php';
	require_once $root . '/src/Plugin.php';

	$pass = 0;
	$fail = 0;
	$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
		if ( $ok ) {
			++$pass;
			echo "PASS {$label}\n";
			return;
		}
		++$fail;
		echo "FAIL {$label}\n";
	};

	$shipping = 'ys_ec_ecpay_ship_unimart';
	$payment = 'ys_ec_ecpay_credit';
	$scope = 'sub_41';

	$map = EcpayStoreSelector::build_map_form_data(
		$shipping,
		'subscription',
		0,
		$scope,
		'https://shop.example.test/subscriptions/41',
		$payment
	);
	$map_record = is_array( $map )
		? get_transient( 'ys_ec_ecpay_map_' . (string) ( $map['temp_id'] ?? '' ) )
		: false;
	$check(
		'fresh map session stores exact subscription context, scope, payment and principal',
		is_array( $map_record )
			&& 'subscription' === ( $map_record['context'] ?? '' )
			&& $scope === ( $map_record['cart_scope'] ?? '' )
			&& $payment === ( $map_record['payment_method'] ?? '' )
			&& 'u:7' === ( $map_record['actor'] ?? '' )
	);

	$issue = new \ReflectionMethod( EcpayStoreSelector::class, 'issue_selection_token' );
	$issue->setAccessible( true );
	$new_token = (string) $issue->invoke( null, [
		'shipping_id'       => $shipping,
		'cvs_type'          => 'UNIMARTC2C',
		'store_id'          => '991122',
		'store_name'        => 'Canonical Store',
		'store_address'     => 'No. 1 Store Rd.',
		'store_verified'    => 1,
		'collection_mode'   => 'N',
		'payment_method'    => $payment,
		'cart_scope'        => $scope,
	], 'u:7' );
	$new_data = [
		'ecpay_store_token' => $new_token,
		'cvs_store_id'      => '991122',
		'cart_scope'        => $scope,
	];
	$new_inspection = EcpayStoreSelector::inspect_selection_authoritative( $new_data, $shipping, $payment );
	$check(
		'fresh selection token exposes only its canonical server-owned store tuple',
		'' !== $new_token
			&& null === $new_inspection['error']
			&& [
				'store_id' => '991122',
				'store_name' => 'Canonical Store',
				'store_address' => 'No. 1 Store Rd.',
			] === $new_inspection['store']
	);
	$check(
		'fresh selection token rejects scope and payment substitution without consumption',
		null !== EcpayStoreSelector::verify_selection(
			array_merge( $new_data, [ 'cart_scope' => 'sub_42' ] ),
			$shipping,
			$payment
		)
			&& null !== EcpayStoreSelector::verify_selection( $new_data, $shipping, 'ys_ec_cod' )
			&& null === EcpayStoreSelector::verify_selection( $new_data, $shipping, $payment )
	);
	$new_claim = EcpayStoreSelector::claim_selection_authoritative( $new_data, $shipping, $payment );
	$new_replay = EcpayStoreSelector::claim_selection_authoritative( $new_data, $shipping, $payment );
	$check(
		'fresh selection token claims once under the exact subscription tuple',
		null === $new_claim['error']
			&& '991122' === ( $new_claim['store']['cvs_store_id'] ?? '' )
			&& null !== $new_replay['error']
	);

	YSSubscription::$rows[41] = (object) [
		'id'         => 41,
		'user_id'    => 7,
		'product_id' => 501,
		'variant_id' => 9,
		'gateway_id' => $payment,
		'status'     => 'active',
	];
	$plugin = new \YangSheep\YSCartEcpay\Plugin();
	$saved_route = static function ( array $body ) use ( $plugin ): WP_REST_Response {
		YSShippingRegistry::$calls = [];
		return $plugin->ecpay_reauthorize_saved_store( new WP_REST_Request( $body ) );
	};
	$saved_base = [
		'context'         => 'subscription',
		'subscription_id' => 41,
		'address_id'      => 81,
		'shipping_id'     => $shipping,
	];

	$GLOBALS['v035_user_id'] = 8;
	$active_other = $saved_route( array_merge( $saved_base, [
		'cart_scope' => $scope,
		'payment_method' => $payment,
	] ) );
	YSSubscription::$rows[41]->status = 'cancelled';
	$terminal_other = $saved_route( array_merge( $saved_base, [
		'cart_scope' => $scope,
		'payment_method' => $payment,
	] ) );
	YSSubscription::$rows[41]->status = 'active';
	$GLOBALS['v035_user_id'] = 7;
	$missing = $saved_route( array_merge( $saved_base, [
		'subscription_id' => 404,
		'cart_scope' => 'sub_404',
		'payment_method' => $payment,
	] ) );
	$check(
		'saved route hides missing, active-other and terminal-other subscriptions identically',
		404 === $missing->get_status()
			&& 404 === $active_other->get_status()
			&& 404 === $terminal_other->get_status()
			&& $missing->data === $active_other->data
			&& $missing->data === $terminal_other->data
			&& 'subscription_not_found' === ( $missing->data['code'] ?? '' )
	);

	YSSubscription::$rows[41]->status = 'cancelled';
	$terminal_owner = $saved_route( $saved_base );
	$check(
		'authorized owner cannot reauthorize a terminal subscription',
		409 === $terminal_owner->get_status()
			&& 'subscription_stale' === ( $terminal_owner->data['code'] ?? '' )
	);
	YSSubscription::$rows[41]->status = 'active';

	$malformed = $saved_route( array_merge( $saved_base, [
		'address_id' => [ 81 ],
	] ) );
	$check(
		'saved route rejects malformed fields before subscription product work',
		400 === $malformed->get_status()
			&& 'invalid_saved_store_request' === ( $malformed->data['code'] ?? '' )
			&& [] === YSShippingRegistry::$calls
	);

	$scope_spoof = $saved_route( array_merge( $saved_base, [
		'cart_scope' => 'default',
		'payment_method' => $payment,
	] ) );
	$payment_spoof = $saved_route( array_merge( $saved_base, [
		'cart_scope' => $scope,
		'payment_method' => 'ys_ec_cod',
	] ) );
	$check(
		'saved route rejects caller scope and payment substitution',
		409 === $scope_spoof->get_status()
			&& 'subscription_scope_mismatch' === ( $scope_spoof->data['code'] ?? '' )
			&& 409 === $payment_spoof->get_status()
			&& 'subscription_payment_mismatch' === ( $payment_spoof->data['code'] ?? '' )
	);

	YSShippingRegistry::$allowed = false;
	$method_restricted = $saved_route( $saved_base );
	$method_call = YSShippingRegistry::$calls[0] ?? [];
	$check(
		'saved route enforces the subscription product and variant shipping restriction',
		400 === $method_restricted->get_status()
			&& 'shipping_method_not_allowed' === ( $method_restricted->data['code'] ?? '' )
			&& $shipping === ( $method_call['method'] ?? '' )
			&& [ [ 'product_id' => 501, 'variant_id' => 9 ] ] === ( $method_call['items'] ?? null )
	);
	YSShippingRegistry::$allowed = true;

	$method_spoof = $saved_route( array_merge( $saved_base, [
		'shipping_id' => 'ys_ec_ecpay_ship_hilife',
		'cart_scope' => $scope,
		'payment_method' => $payment,
	] ) );
	$check(
		'saved address cannot be rebound to another allowed shipping method',
		409 === $method_spoof->get_status()
			&& 'saved_store_incompatible' === ( $method_spoof->data['code'] ?? '' )
	);

	$GLOBALS['v035_user_id'] = 99;
	$GLOBALS['v035_admin'] = true;
	$admin_saved = $saved_route( $saved_base );
	$admin_token = (string) ( $admin_saved->data['data']['selection_token'] ?? '' );
	$admin_claim = EcpayStoreSelector::claim_selection_authoritative( [
		'ecpay_store_token' => $admin_token,
		'cvs_store_id'      => '991122',
		'cart_scope'        => $scope,
	], $shipping, $payment );
	$check(
		'admin may reauthorize the same server-derived subscription tuple',
		200 === $admin_saved->get_status()
			&& '' !== $admin_token
			&& null === $admin_claim['error']
	);
	$GLOBALS['v035_admin'] = false;
	$GLOBALS['v035_user_id'] = 7;

	$saved = $saved_route( $saved_base );
	$saved_token = (string) ( $saved->data['data']['selection_token'] ?? '' );
	$saved_data = [
		'ecpay_store_token' => $saved_token,
		'cvs_store_id'      => '991122',
		'cart_scope'        => $scope,
	];
	$check(
		'saved route derives and returns the exact subscription tuple',
		200 === $saved->get_status()
			&& '' !== $saved_token
			&& $scope === ( $saved->data['data']['cart_scope'] ?? '' )
			&& $payment === ( $saved->data['data']['payment_method'] ?? '' )
			&& 1 === ( $saved->data['data']['store_verified'] ?? 0 )
			&& 'no-store, private' === ( $saved->headers['Cache-Control'] ?? '' )
	);
	$check(
		'saved-store token rejects scope and payment substitution without consumption',
		null !== EcpayStoreSelector::verify_selection(
			array_merge( $saved_data, [ 'cart_scope' => 'sub_42' ] ),
			$shipping,
			$payment
		)
			&& null !== EcpayStoreSelector::verify_selection( $saved_data, $shipping, 'ys_ec_cod' )
			&& null === EcpayStoreSelector::verify_selection( $saved_data, $shipping, $payment )
	);
	$saved_claim = EcpayStoreSelector::claim_selection_authoritative( $saved_data, $shipping, $payment );
	$saved_replay = EcpayStoreSelector::claim_selection_authoritative( $saved_data, $shipping, $payment );
	$check(
		'saved-store token claims once under the exact subscription tuple',
		null === $saved_claim['error']
			&& 'Canonical Store' === ( $saved_claim['store']['cvs_store_name'] ?? '' )
			&& null !== $saved_replay['error']
	);

	echo "\nsubscription store token binding: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
