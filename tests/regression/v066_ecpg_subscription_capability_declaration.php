<?php
/**
 * v066 — 站內付 2.0 的「支援訂閱」宣告與 Core 相容（v0.5.12）。
 *
 * 以 YS_CORE_ROOT 的**真** Core 閘道介面載入 production `EcpayEcpgCreditGateway`：
 *   - Core 有 `YSSubscriptionCapableGatewayInterface`（2.69.7 起）→ 閘道明確實作它；Core 的唯一判定
 *     `YSSubscriptionGatewayCapability` 回「支援訂閱、排程權威 core」。
 *   - 舊 Core（沒有該介面）→ 相容橋是空介面、閘道仍可載入與建構；兩個宣告方法照樣回 true／'core'，
 *     而舊 Core 結帳篩選的判準 `supports_token()` 仍為 true（行為與 0.5.11 相同）。
 * 兩種形狀的期望都手寫；形狀本身由 YS_CORE_ROOT 裡有沒有介面檔決定，並可用
 * YS_ECPG_EXPECT_CAPABILITY=declared|compat 釘住，避免把樹配錯卻假綠。
 *
 * Run: YS_CORE_ROOT=<Core 樹> php tests/regression/v066_ecpg_subscription_capability_declaration.php
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	function __( $text, $domain = '' ) { return $text; }

	$core = rtrim( str_replace( '\\', '/', (string) getenv( 'YS_CORE_ROOT' ) ), '/' );
	if ( '' === $core || ! is_file( $core . '/src/Gateways/YSGatewayInterface.php' ) ) {
		fwrite( STDERR, "YS_CORE_ROOT must name a Core tree (src/Gateways/YSGatewayInterface.php).\n" );
		exit( 2 );
	}
	$interface_file  = $core . '/src/Gateways/YSSubscriptionCapableGatewayInterface.php';
	$capability_file = $core . '/src/Gateways/YSSubscriptionGatewayCapability.php';
	$mode            = is_file( $interface_file ) ? 'declared' : 'compat';
	$pinned          = (string) getenv( 'YS_ECPG_EXPECT_CAPABILITY' );

	require_once $core . '/src/Gateways/YSGatewayInterface.php';
	require_once $core . '/src/Gateways/YSOrderScopedTokenChargeGatewayInterface.php';
	if ( 'declared' === $mode ) {
		require_once $interface_file;
		require_once $capability_file;
	}
	$root = str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	require_once $root . '/src/Support/Utf8Text.php';
	require_once $root . '/src/Support/CheckMacValue.php';
	require_once $root . '/src/Payment/EcpayPaymentClient.php';
	require_once $root . '/src/Payment/EcpayPaymentCatalog.php';
	require_once $root . '/src/Payment/EcpayGatewayBase.php';
	require_once $root . '/src/Ecpg/EcpgAesCodec.php';
	require_once $root . '/src/Ecpg/EcpgClient.php';
	require_once $root . '/src/Ecpg/EcpgOrderContext.php';
	require_once $root . '/src/Payment/EcpayEcpgCreditGateway.php';

	$pass   = 0;
	$fail   = 0;
	$assert = static function ( bool $ok, string $label, mixed $detail = null ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "  PASS  {$label}\n"; return; }
		++$fail; echo "  FAIL  {$label}" . ( null === $detail ? '' : ' -- ' . json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) . "\n";
	};
	echo "## v066 ECPG subscription capability declaration (Core shape: {$mode})\n";

	$assert( '' === $pinned || $pinned === $mode, 'P0 the Core tree has the shape the runner pinned (YS_ECPG_EXPECT_CAPABILITY)', [ 'pinned' => $pinned, 'detected' => $mode, 'core' => $core ] );

	$IFACE   = 'YangSheep\\Ecommerce\\Gateways\\YSSubscriptionCapableGatewayInterface';
	$CAP     = 'YangSheep\\Ecommerce\\Gateways\\YSSubscriptionGatewayCapability';
	$BRIDGE  = 'YangSheep\\YSCartEcpay\\Payment\\EcpgSubscriptionCapability';
	$gateway = new \YangSheep\YSCartEcpay\Payment\EcpayEcpgCreditGateway();

	$assert(
		$gateway instanceof \YangSheep\Ecommerce\Gateways\YSOrderScopedTokenChargeGatewayInterface && true === $gateway->supports_token(),
		'D1 still an order-scoped token gateway with supports_token()=true (0.5.11 contract unchanged)'
	);
	$assert(
		true === $gateway->supports_subscriptions() && 'core' === $gateway->subscription_schedule_authority(),
		'D2 declares supports_subscriptions()=true and schedule authority exactly "core" (Core-scheduled token renewals, not provider period billing)'
	);
	$assert( $gateway instanceof $BRIDGE, 'D3 the gateway always implements the compatibility bridge' );

	if ( 'declared' === $mode ) {
		$bridge = new \ReflectionClass( $BRIDGE );
		$assert( [ $IFACE ] === $bridge->getInterfaceNames(), 'D4 on Core 2.69.7 the bridge extends exactly the Core declaration interface', $bridge->getInterfaceNames() );
		$assert( $gateway instanceof $IFACE, 'D5 on Core 2.69.7 the ECPG gateway is an explicit subscription-capable gateway' );
		$assert(
			true === $CAP::supports_subscriptions( $gateway ) && 'core' === $CAP::schedule_authority( $gateway ),
			'D6 Core\'s single capability verdict reads the declaration: supports subscriptions, authority core'
		);
	} else {
		$bridge = new \ReflectionClass( $BRIDGE );
		$assert( [] === $bridge->getInterfaceNames() && ! interface_exists( $IFACE, false ), 'D4 on older Core the bridge is an empty interface (no Core declaration interface exists)', $bridge->getInterfaceNames() );
		$assert( true === $gateway->supports_token(), 'D5 on older Core the checkout rule ($has_subscription ⇒ supports_token()) still keeps this gateway' );
		$assert( ! class_exists( $CAP, false ), 'D6 older Core has no capability class; nothing in the plugin depends on it' );
	}

	echo "\nv066 ECPG subscription capability ({$mode}): {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 || 0 === $pass ? 1 : 0 );
}
