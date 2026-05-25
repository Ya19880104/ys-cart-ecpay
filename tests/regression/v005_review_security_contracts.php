<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function v005_read(string $path): string {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
    return (string) file_get_contents($path);
}

function v005_check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$payment = v005_read($root . '/src/Api/EcpayPaymentController.php');
$logistics = v005_read($root . '/src/Api/EcpayLogisticsController.php');
$requester = v005_read($root . '/src/Shipping/Ecpay/EcpayShippingRequester.php');
$selector = v005_read($root . '/src/Shipping/Ecpay/EcpayStoreSelector.php');
$plugin = v005_read($root . '/src/Plugin.php');
$build = v005_read($root . '/bin/build-release.php');

v005_check(
    false !== strpos($payment, '$this->verify_payment_payload( $params )')
    && false !== strpos($payment, 'order_has_merchant_trade_no')
    && false !== strpos($payment, 'ecpay_merchant_trade_no'),
    'payment return/order lookup must require signed payload and exact stored merchant trade number'
);

v005_check(
    false !== strpos($logistics, "'' === \$credentials['merchant_id']")
    && false !== strpos($logistics, "'' === \$credentials['hash_key']")
    && false !== strpos($logistics, "'' === \$credentials['hash_iv']")
    && false !== strpos($logistics, 'INNER JOIN {$labels_table}'),
    'logistics notify must require non-empty credentials and resolve orders through shipping labels'
);

v005_check(
    false !== strpos($requester, 'verify_create_response')
    && false !== strpos($requester, "'tracking_no'")
    && false !== strpos($requester, 'ys_cart_ecpay_print')
    && false !== strpos($requester, 'printTradeDocument')
    && false !== strpos($requester, 'CheckMacValue::generate'),
    'shipping requester must verify create response, return tracking_no, and generate signed print payloads'
);

v005_check(
    false !== strpos($selector, 'empty( $params[\'CheckMacValue\'] )')
    && false !== strpos($selector, 'merchant_trade_no')
    && false !== strpos($selector, 'logistics_subtype'),
    'store callback must require signature and validate transient-bound identifiers'
);

v005_check(
    false !== strpos($plugin, 'EcpayPrintController::register()'),
    'plugin must register ECPay print controller'
);

v005_check(
    false !== strpos($build, "str_starts_with(\$relative, 'docs/superpowers/')")
    && false !== strpos($build, "str_starts_with(basename(\$relative), '.env')")
    && false !== strpos($build, "str_ends_with(\$relative, '.log')"),
    'release build must exclude internal plans, env files, and logs'
);

echo "v005_review_security_contracts passed\n";

