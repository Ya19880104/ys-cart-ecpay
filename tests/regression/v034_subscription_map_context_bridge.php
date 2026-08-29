<?php
/**
 * Behavioral regression: subscription store-map requests derive every sensitive
 * checkout condition from the Core subscription row.
 *
 * Run: php -n tests/regression/v034_subscription_map_context_bridge.php
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['v034_user_id'] = 0;
	$GLOBALS['v034_admin']   = false;

	function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
	function sanitize_key( mixed $value ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
	}
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function esc_url_raw( string $url ): string { return $url; }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function __( string $text, string $domain = '' ): string { return $text; }
	function is_user_logged_in(): bool { return (int) $GLOBALS['v034_user_id'] > 0; }
	function get_current_user_id(): int { return (int) $GLOBALS['v034_user_id']; }
	function current_user_can( string $capability ): bool {
		return 'manage_options' === $capability && true === $GLOBALS['v034_admin'];
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
	}
}

namespace YangSheep\Ecommerce\Models {
	final class YSSubscription {
		/** @var array<int,object> */
		public static array $rows = [];
		public static int $find_calls = 0;

		public static function find( int $id ): ?object {
			++self::$find_calls;
			return self::$rows[ $id ] ?? null;
		}
	}
}

namespace YangSheep\Ecommerce\Gateways {
	final class YSGatewayRegistry {
		/** @var array<string,object> */
		public static array $rows = [];
		public static function get( string $id ): ?object { return self::$rows[ $id ] ?? null; }
	}
}

namespace YangSheep\Ecommerce\Security {
	final class YSRateLimiter {
		/** @var list<string> */
		public static array $calls = [];
		public static function check( string $action, int $max = 30, int $window = 60 ): bool {
			self::$calls[] = $action;
			return true;
		}
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
	final class ShippingMethodOperability {
		public static bool $operable = true;
		public static function has_operable_method(): bool { return true; }
		public static function is_operable( string $method_id ): bool { return self::$operable; }
		public static function is_configured( string $method_id ): bool { return self::$operable; }
	}
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
	final class EcpayShippingCatalog {
		/** @return array<string,mixed>|null */
		public static function get( string $id ): ?array {
			if ( 'ys_ec_ecpay_ship_unimart' !== $id ) {
				return null;
			}
			return [
				'cod_capable'       => true,
				'requires_store'    => true,
				'logistics_type'    => 'CVS',
				'logistics_subtype' => 'UNIMARTC2C',
			];
		}
	}

	final class EcpayStoreSelector {
		public const COD_GATEWAY_ID = 'ys_ec_cod';
		/** @var list<array<int,mixed>> */
		public static array $map_calls = [];
		/** @var list<string> */
		public static array $principal_scopes = [];

		public static function reset(): void {
			self::$map_calls = [];
			self::$principal_scopes = [];
		}

		public static function current_principal( string $scope ): string {
			self::$principal_scopes[] = $scope;
			$user_id = \get_current_user_id();
			return $user_id > 0 ? 'u:' . $user_id : '';
		}

		public static function build_map_form_data( mixed ...$args ): array {
			self::$map_calls[] = $args;
			return [ 'temp_id' => 'SUBSCRIPTION-MAP', 'action_url' => 'https://example.test/map' ];
		}
	}
}

namespace {
	use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
	use YangSheep\Ecommerce\Models\YSSubscription;
	use YangSheep\Ecommerce\Shipping\YSShippingRegistry;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;

	$root = dirname( __DIR__, 2 );
	require_once $root . '/src/Support/CartScope.php';
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

	$plugin = new \YangSheep\YSCartEcpay\Plugin();
	$map = static function ( array $body ) use ( $plugin ): WP_REST_Response {
		EcpayStoreSelector::reset();
		YSShippingRegistry::$calls = [];
		YSSubscription::$find_calls = 0;
		return $plugin->ecpay_map_url( new WP_REST_Request( $body ) );
	};
	$base = [
		'context'         => 'subscription',
		'subscription_id' => 41,
		'shipping_id'     => 'ys_ec_ecpay_ship_unimart',
		'return_url'      => 'https://shop.example.test/subscriptions/41',
	];
	YSSubscription::$rows[41] = (object) [
		'id'         => 41,
		'user_id'    => 7,
		'product_id' => 501,
		'variant_id' => 9,
		'gateway_id' => 'ys_ec_ecpay_credit',
		'status'     => 'active',
	];
	YSGatewayRegistry::$rows = [ 'ys_ec_ecpay_credit' => (object) [ 'id' => 'ys_ec_ecpay_credit' ] ];

	$GLOBALS['v034_user_id'] = 0;
	$response = $map( $base );
	$check(
		'subscription map requires login before subscription lookup',
		401 === $response->get_status()
			&& 'authentication_required' === ( $response->data['code'] ?? '' )
			&& 0 === YSSubscription::$find_calls
			&& [] === EcpayStoreSelector::$map_calls
	);

	$GLOBALS['v034_user_id'] = 8;
	$active_other = $map( $base );
	YSSubscription::$rows[41]->status = 'cancelled';
	$terminal_other = $map( $base );
	YSSubscription::$rows[41]->status = 'active';
	$response = $map( array_merge( $base, [ 'subscription_id' => 404 ] ) );
	$check(
		'missing, active-other and terminal-other subscriptions are indistinguishable',
		404 === $response->get_status()
			&& 404 === $active_other->get_status()
			&& 404 === $terminal_other->get_status()
			&& $response->data === $active_other->data
			&& $response->data === $terminal_other->data
			&& 'subscription_not_found' === ( $response->data['code'] ?? '' )
			&& [] === EcpayStoreSelector::$map_calls
	);

	$GLOBALS['v034_user_id'] = 7;
	YSSubscription::$rows[41]->status = 'cancelled';
	$response = $map( $base );
	$check(
		'terminal subscription is stale and cannot mint a map session',
		409 === $response->get_status()
			&& 'subscription_stale' === ( $response->data['code'] ?? '' )
			&& [] === EcpayStoreSelector::$map_calls
	);
	YSSubscription::$rows[41]->status = 'active';

	$response = $map( array_merge( $base, [ 'cart_scope' => 'default' ] ) );
	$check(
		'caller cannot spoof subscription cart scope',
		409 === $response->get_status()
			&& 'subscription_scope_mismatch' === ( $response->data['code'] ?? '' )
			&& [] === EcpayStoreSelector::$map_calls
	);

	$response = $map( array_merge( $base, [ 'payment_method' => 'ys_ec_cod' ] ) );
	$check(
		'caller cannot spoof subscription payment method',
		409 === $response->get_status()
			&& 'subscription_payment_mismatch' === ( $response->data['code'] ?? '' )
			&& [] === EcpayStoreSelector::$map_calls
	);

	YSShippingRegistry::$allowed = false;
	$response = $map( $base );
	$method_call = YSShippingRegistry::$calls[0] ?? [];
	$check(
		'Core product and variant restriction is enforced without reading a cart',
		400 === $response->get_status()
			&& 'shipping_method_not_allowed' === ( $response->data['code'] ?? '' )
			&& 'ys_ec_ecpay_ship_unimart' === ( $method_call['method'] ?? '' )
			&& [ [ 'product_id' => 501, 'variant_id' => 9 ] ] === ( $method_call['items'] ?? null )
			&& [] === EcpayStoreSelector::$map_calls
	);
	YSShippingRegistry::$allowed = true;

	$response = $map( $base );
	$args = EcpayStoreSelector::$map_calls[0] ?? [];
	$check(
		'owner map session is bound to server-derived subscription scope and gateway',
		200 === $response->get_status()
			&& 'map_url_ready' === ( $response->data['code'] ?? '' )
			&& [
				'ys_ec_ecpay_ship_unimart',
				'subscription',
				0,
				'sub_41',
				'https://shop.example.test/subscriptions/41',
				'ys_ec_ecpay_credit',
			] === $args
			&& [ 'sub_41' ] === EcpayStoreSelector::$principal_scopes
	);

	$GLOBALS['v034_user_id'] = 99;
	$GLOBALS['v034_admin'] = true;
	$response = $map( array_merge( $base, [
		'cart_scope'     => 'sub_41',
		'payment_method' => 'ys_ec_ecpay_credit',
	] ) );
	$check(
		'admin may act on the exact same server-derived tuple',
		200 === $response->get_status()
			&& 'u:99' === 'u:' . $GLOBALS['v034_user_id']
			&& 'sub_41' === ( EcpayStoreSelector::$map_calls[0][3] ?? '' )
			&& 'ys_ec_ecpay_credit' === ( EcpayStoreSelector::$map_calls[0][5] ?? '' )
	);

	echo "\nsubscription map context bridge: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
