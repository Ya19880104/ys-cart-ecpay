<?php
/** Core 2.67.11 is the global attempt-safe payment floor; ECPG keeps extra method capabilities. */

declare(strict_types=1);

namespace {
	$scenario = isset( $argv[1] ) && '--case' === $argv[1] ? (string) ( $argv[2] ?? '' ) : '';
	define( 'ABSPATH', __DIR__ );
	define( 'YS_ECOMMERCE_VERSION', 'legacy-version' === $scenario ? '2.67.10' : '2.67.11' );
	define( 'YS_CART_ECPAY_REQUIRES_CORE', '2.67.11' );
	define( 'YS_CART_ECPAY_VERSION', 'test' );
	define( 'HOUR_IN_SECONDS', 3600 );
	function get_transient( string $key ): false { return false; }
	function set_transient( string $key, mixed $value, int $ttl ): bool { return true; }
}

namespace YangSheep\Ecommerce\Utils {
	final class YSCrypto {
		public static function encrypt_for_storage( string $value ): string { return $value; }
		public static function decrypt_from_storage( string $value ): string { return $value; }
	}
}

namespace YangSheep\Ecommerce\Gateways {
	final class YSGatewayRegistry {
		public static array $registered = [];
		public static function register( object $gateway ): void { self::$registered[] = get_class( $gateway ); }
	}
}

namespace YangSheep\Ecommerce\Services\Shipping {
	final class YSShippingDispatchAuthority {
		public static function with_order_serialization( mixed ...$args ): mixed { return null; }
		public static function active_attempt( mixed ...$args ): mixed { return null; }
	}
	final class YSShippingPipelineService {
		public static function advance_from_carrier_status( mixed $a, mixed $b, mixed $c, mixed $d, mixed $e ): mixed { return null; }
		public static function publish_advance_hook( mixed ...$args ): void {}
	}
}

namespace YangSheep\Ecommerce\Database {
	final class YSMigration {
		public static function shipping_label_dispatch_schema_ready(): bool { return true; }
		public static function address_shipping_provider_schema_ready(): bool { return true; }
	}
}

namespace YangSheep\Ecommerce\Handlers {
	final class YSShippingHandler {
		public static function query_shipping_status_for_order( mixed ...$args ): mixed { return null; }
	}
}

namespace YangSheep\Ecommerce\Security {
	final class YSWebhookGuard {
		public static function reserve( mixed ...$args ): mixed { return null; }
		public static function commit_replay( mixed ...$args ): bool { return true; }
		public static function release_replay( mixed ...$args ): void {}
	}
	final class YSReplayReservation {
		public function get_token(): string { return 'token'; }
		public function is_acquired(): bool { return true; }
		public function can_acknowledge(): bool { return true; }
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	interface YSPaymentReconcilerInterface {}
	final class YSPaymentDetailStore {
		public static function mutate( mixed ...$args ): mixed { return null; }
		public static function read( mixed ...$args ): array { return []; }
	}
	final class YSPaymentDetailResult {}
	final class YSPaymentDispatch {
		public static function current_operation_key(): string { return 'operation'; }
		public static function current_token(): ?string { return 'token'; }
		public static function operation_key( int $order_id, array $attempt ): string { return 'operation'; }
		public static function state( array $detail ): string { return 'submitted'; }
	}
	final class YSPaymentAttempt {
		public static function current( array $detail ): array { return []; }
	}
	final class YSPaymentLifecycleService {
		public static function mark_paid( mixed ...$args ): array { return []; }
		public static function mark_failed( mixed ...$args ): array { return []; }
		public static function mark_pending_offline( mixed ...$args ): array { return []; }
	}
}

namespace YangSheep\Ecommerce\Shipping {
	final class YSShippingRegistry {
		public static function is_method_allowed_for_cart( mixed ...$args ): bool { return true; }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		public static function enabled(): bool { return true; }
		public static function gateway_enabled( string $alias ): bool { return true; }
	}
	final class ShippingMethodOperability {
		public static function has_operable_method(): bool { return false; }
	}
}

namespace YangSheep\YSCartEcpay\Payment {
	abstract class EcpayGatewayBase {}
	final class TestAioGateway extends EcpayGatewayBase {}
	final class TestEcpgGateway extends EcpayGatewayBase {}
	final class EcpayPaymentCatalog {
		public static function all(): array {
			return [
				'ys_ec_ecpay_credit' => [ 'class' => TestAioGateway::class ],
				'ys_ec_ecpay_ecpg_credit' => [ 'class' => TestEcpgGateway::class ],
			];
		}
		public static function id_to_alias(): array {
			return [ 'ys_ec_ecpay_credit' => 'credit', 'ys_ec_ecpay_ecpg_credit' => 'ecpg_credit' ];
		}
	}
}

namespace YangSheep\YSCartEcpay\Ecpg {
	final class EcpgOrderContext {
		public const GATEWAY_ID = 'ys_ec_ecpay_ecpg_credit';
	}
}

namespace YangSheep\YSCartEcpay\Api {
	final class EcpayPaymentController {
		public static int $registrations = 0;
		public static function register_routes(): void { ++self::$registrations; }
	}
	final class EcpgPaymentController {
		public static int $registrations = 0;
		public static function register_routes(): void { ++self::$registrations; }
	}
}

namespace {
	if ( isset( $argv[1] ) && '--case' === $argv[1] ) {
		$scenario = (string) ( $argv[2] ?? '' );
		if ( 'missing-binding' === $scenario ) {
			eval( 'namespace YangSheep\\Ecommerce\\Models; final class YSSubscription {}' );
		} else {
			eval( 'namespace YangSheep\\Ecommerce\\Models; final class YSSubscription { public static function bind_initial_order_card(int $o,int $c,int $u,string $g,int $card): bool { return true; } }' );
		}
		if ( 'missing-effects' === $scenario ) {
			eval( 'namespace YangSheep\\Ecommerce\\Services\\Payment; final class YSPaymentEffects { public static function enroll(...$a): bool { return true; } public static function run(...$a): array { return []; } public static function receipt_id(...$a): string { return "receipt"; } }' );
		} else {
			eval( 'namespace YangSheep\\Ecommerce\\Services\\Payment; final class YSPaymentEffects { public static function enroll(...$a): bool { return true; } public static function run(...$a): array { return []; } public static function receipt_id(...$a): string { return "receipt"; } public static function receipt(...$a): array { return []; } }' );
		}

		require dirname( __DIR__, 2 ) . '/src/Plugin.php';
		$plugin = \YangSheep\YSCartEcpay\Plugin::instance();
		$plugin->register_gateways();
		$plugin->register_public_routes();
		echo json_encode( [
			'base'        => \YangSheep\YSCartEcpay\Plugin::core_requirements(),
			'ecpg'        => \YangSheep\YSCartEcpay\Plugin::ecpg_core_requirements(),
			'gateways'    => \YangSheep\Ecommerce\Gateways\YSGatewayRegistry::$registered,
			'aio_routes'  => \YangSheep\YSCartEcpay\Api\EcpayPaymentController::$registrations,
			'ecpg_routes' => \YangSheep\YSCartEcpay\Api\EcpgPaymentController::$registrations,
		], JSON_UNESCAPED_UNICODE );
		exit;
	}

	$run = static function ( string $scenario ): array {
		$process = proc_open( [ PHP_BINARY, __FILE__, '--case', $scenario ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'cannot start child process' ); }
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		$decoded = json_decode( $stdout, true );
		return [ 'exit' => $exit, 'stderr' => $stderr, 'result' => is_array( $decoded ) ? $decoded : [] ];
	};

	$pass = 0;
	$fail = 0;
	$check = static function ( string $label, bool $ok, array $context ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "PASS {$label}\n"; return; }
		++$fail; echo "FAIL {$label} -- " . json_encode( $context, JSON_UNESCAPED_UNICODE ) . "\n";
	};
	$aio_class  = \YangSheep\YSCartEcpay\Payment\TestAioGateway::class;
	$ecpg_class = \YangSheep\YSCartEcpay\Payment\TestEcpgGateway::class;

	$complete = $run( 'complete' );
	$check( 'Core 2.67.11 with attempt/lifecycle, receipt and binder APIs registers AIO plus ECPG', 0 === $complete['exit'] && true === ( $complete['result']['base']['met'] ?? false ) && true === ( $complete['result']['ecpg']['met'] ?? false ) && [ $aio_class, $ecpg_class ] === ( $complete['result']['gateways'] ?? [] ) && 1 === ( $complete['result']['aio_routes'] ?? 0 ) && 1 === ( $complete['result']['ecpg_routes'] ?? 0 ), $complete );

	$missing_binding = $run( 'missing-binding' );
	$check( 'missing binder blocks only ECPG gateway/routes while AIO bootstrap stays live', 0 === $missing_binding['exit'] && true === ( $missing_binding['result']['base']['met'] ?? false ) && 'ecpg_core_capability_missing' === ( $missing_binding['result']['ecpg']['reason'] ?? '' ) && [ $aio_class ] === ( $missing_binding['result']['gateways'] ?? [] ) && 1 === ( $missing_binding['result']['aio_routes'] ?? 0 ) && 0 === ( $missing_binding['result']['ecpg_routes'] ?? -1 ), $missing_binding );

	$missing_effects = $run( 'missing-effects' );
	$check( 'incomplete receipt API blocks only ECPG gateway/routes', 0 === $missing_effects['exit'] && true === ( $missing_effects['result']['base']['met'] ?? false ) && 'ecpg_core_capability_missing' === ( $missing_effects['result']['ecpg']['reason'] ?? '' ) && [ $aio_class ] === ( $missing_effects['result']['gateways'] ?? [] ) && 1 === ( $missing_effects['result']['aio_routes'] ?? 0 ) && 0 === ( $missing_effects['result']['ecpg_routes'] ?? -1 ), $missing_effects );

	$legacy = $run( 'legacy-version' );
	$entry = (string) file_get_contents( dirname( __DIR__, 2 ) . '/ys-cart-ecpay.php' );
	$gate_pos = strpos( $entry, 'Plugin::core_requirements()' );
	$init_pos = strpos( $entry, 'Plugin::instance()->init()' );
	$check(
		'Core 2.67.10 is below the global attempt-safe bootstrap floor',
		0 === $legacy['exit']
		&& false === ( $legacy['result']['base']['met'] ?? true )
		&& 'core_too_old' === ( $legacy['result']['base']['reason'] ?? '' )
		&& 'ecpg_core_too_old' === ( $legacy['result']['ecpg']['reason'] ?? '' )
		&& [ $aio_class ] === ( $legacy['result']['gateways'] ?? [] )
		&& 1 === ( $legacy['result']['aio_routes'] ?? 0 )
		&& 0 === ( $legacy['result']['ecpg_routes'] ?? -1 )
		&& false !== $gate_pos
		&& false !== $init_pos
		&& $gate_pos < $init_pos
		&& str_contains( $entry, "if ( ! \$ys_cart_ecpay_gate['met'] )" ),
		$legacy
	);

	echo "\nECPG subscription card capability gate: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
