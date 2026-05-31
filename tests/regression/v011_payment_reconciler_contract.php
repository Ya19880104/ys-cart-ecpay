<?php
/**
 * ECPay payment reconciliation contract.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function v011_read(string $relative): string {
    global $root;
    $path = $root . '/' . ltrim($relative, '/\\');
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$relative}\n");
        exit(1);
    }
    return (string) file_get_contents($path);
}

function v011_check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) {
        echo "[PASS] {$label}\n";
        $pass++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $fail++;
}

$main = v011_read('ys-cart-ecpay.php');
$plugin = v011_read('src/Plugin.php');
$settings = v011_read('src/Support/Settings.php');
$client = v011_read('src/Payment/EcpayPaymentClient.php');
$reconciler = v011_read('src/Payment/EcpayPaymentReconciler.php');

echo "## ECPay payment reconciliation contract\n";

v011_check(
    'Provider registers a payment reconciler only through the YS CART hook',
    str_contains($plugin, "add_action( 'ys_ec_register_payment_reconcilers'")
        && str_contains($plugin, 'public function register_payment_reconcilers')
        && str_contains($plugin, '$registry->register( new EcpayPaymentReconciler() );')
);

v011_check(
    'Reconciler registration is gated by provider payment methods and credentials',
    str_contains($plugin, 'has_enabled_payment_methods()')
        && str_contains($plugin, 'Settings::has_payment_credentials()')
        && str_contains($plugin, "interface_exists( '\\YangSheep\\Ecommerce\\Services\\Payment\\YSPaymentReconcilerInterface' )")
);

v011_check(
    'Client implements ECPay QueryTradeInfo V5 with CheckMacValue verification',
    str_contains($settings, 'payment_query_endpoint')
        && str_contains($settings, 'QueryTradeInfo/V5')
        && str_contains($client, 'public function query_trade')
        && str_contains($client, "CheckMacValue::generate")
        && str_contains($client, "CheckMacValue::verify")
);

v011_check(
    'Reconciler maps ECPay query states to normalized YS CART actions',
    str_contains($reconciler, 'implements YSPaymentReconcilerInterface')
        && str_contains($reconciler, "YSPaymentReconcileResult::paid")
        && str_contains($reconciler, "YSPaymentReconcileResult::offline_pending")
        && str_contains($reconciler, "YSPaymentReconcileResult::failed")
        && str_contains($reconciler, "YSPaymentReconcileResult::hold")
);

v011_check(
    'Reconciler recognizes only ECPay-owned orders',
    str_contains($reconciler, "payment_provider'] ?? ''")
        && str_contains($reconciler, 'ecpay_merchant_trade_no')
        && str_contains($reconciler, "str_starts_with( \$gateway_id, 'ys_ec_ecpay_' )")
);

preg_match('/Version:\s*([0-9.]+)/', $main, $version_match);
preg_match("/YS_CART_ECPAY_VERSION', '([0-9.]+)'/", $main, $constant_match);
v011_check(
    'Plugin version is bumped for payment reconciliation',
    version_compare((string) ($version_match[1] ?? ''), '0.2.6', '>=')
        && version_compare((string) ($constant_match[1] ?? ''), '0.2.6', '>=')
);

echo "\nREGRESSION v011_payment_reconciler_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
