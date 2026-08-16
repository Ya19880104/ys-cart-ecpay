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
// 排除政策已抽到 bin/release-policy.php（builder 與 v004 共用同一份）。本檔驗的是
// 「政策內容」，因此要讀政策檔；builder 只保留呼叫端，另行斷言它確實委派過去。
$policy = v005_read($root . '/bin/release-policy.php');

v005_check(
    false !== strpos($payment, '$this->verify_payment_payload( $params )')
    && false !== strpos($payment, 'order_has_merchant_trade_no')
    && false !== strpos($payment, 'ecpay_merchant_trade_no'),
    'payment return/order lookup must require signed payload and exact stored merchant trade number'
);

// 合流後（0.2.16 main）：訂單解析改為 label-first——先以 provider＋
// provider_trade_no（＋order_id＋merchant_trade_no 綁定）鎖定 shipping label，
// 再由 find_order_by_label() 取訂單；沒有 label 綁定就到不了訂單。
// 安全性質與舊 INNER JOIN 相同（訂單只能透過 label 觸達），SQL 形狀不同。
v005_check(
    false !== strpos($logistics, "'' === \$credentials['merchant_id']")
    && false !== strpos($logistics, "'' === \$credentials['hash_key']")
    && false !== strpos($logistics, "'' === \$credentials['hash_iv']")
    && false !== strpos($logistics, 'FROM {$labels_table} WHERE provider = %s AND provider_trade_no = %s')
    && false !== strpos($logistics, 'find_order_by_label')
    && false !== strpos($logistics, 'binding_matches'),
    'logistics notify must require non-empty credentials and resolve orders through shipping labels'
);

// 合流後（0.2.15 main）：打單路徑改由型錄依 subtype 決定——C2C 三家有專用
// 列印端點（打錯端點回空白單），B2C／宅配用預設 /helper/printTradeDocument。
$catalog_src = v005_read($root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php');
v005_check(
    false !== strpos($requester, 'verify_create_response')
    && false !== strpos($requester, "'tracking_no'")
    && false !== strpos($requester, 'ys_cart_ecpay_print')
    && false !== strpos($requester, 'CheckMacValue::generate')
    && false !== strpos($catalog_src, 'printTradeDocument')
    && false !== strpos($catalog_src, '/Express/PrintFAMIC2COrderInfo')
    && false !== strpos($catalog_src, '/Express/PrintUniMartC2COrderInfo')
    && false !== strpos($catalog_src, '/Express/PrintHILIFEC2COrderInfo'),
    'shipping requester must verify create response, return tracking_no, and generate signed print payloads (catalog-routed print paths)'
);

v005_check(
    false !== strpos($selector, 'empty( $params[\'CheckMacValue\'] )')
    && false !== strpos($selector, 'merchant_trade_no')
    && false !== strpos($selector, 'logistics_subtype'),
    'store callback must require signature and validate transient-bound identifiers'
);

v005_check(
    false !== strpos($selector, 'validate_map_owner')
    && false !== strpos($selector, 'get_current_user_id()')
    && false !== strpos($selector, "map_data['user_id']")
    && false !== strpos($selector, 'Invalid map session owner.'),
    'store callback must reject logged-in user mismatch for transient-bound map sessions'
);

v005_check(
    false !== strpos($plugin, 'EcpayPrintController::register()'),
    'plugin must register ECPay print controller'
);

v005_check(
    false !== strpos($policy, "str_starts_with(\$relative, 'docs/superpowers/')")
    && false !== strpos($policy, "str_starts_with(\$base, '.env')")
    && false !== strpos($policy, "str_ends_with(\$relative, '.log')")
    && false !== strpos($build, "require_once __DIR__ . '/release-policy.php'")
    && false !== strpos($build, 'ys_cart_ecpay_release_scan('),
    'release build must exclude internal plans, env files, and logs (policy shared with v004)'
);

echo "v005_review_security_contracts passed\n";
