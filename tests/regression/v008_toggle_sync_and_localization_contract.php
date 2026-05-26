<?php
/**
 * Toggle synchronization, localization, and CVS map contracts.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('ABSPATH')) {
    exit;
}

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function v008_read(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$relative}\n");
        exit(1);
    }

    return (string) file_get_contents($path);
}

function v008_check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        echo "[PASS] {$label}\n";
        $pass++;
        return;
    }

    echo "[FAIL] {$label}\n";
    $fail++;
}

$admin    = v008_read('src/Admin/EcpaySettings.php');
$template = v008_read('templates/admin/ecpay-settings.php');
$plugin   = v008_read('src/Plugin.php');
$manifest = v008_read('manifest.php');
$store    = v008_read('src/Shipping/Ecpay/EcpayStoreSelector.php');

echo "## Toggle sync and localization contract\n";

v008_check(
    'ECPay payment switches sync YS CART gateway state and lifecycle state',
    false !== strpos($admin, 'sync_gateway_enabled_list')
        && false !== strpos($admin, "sync_lifecycle_methods( 'payment'")
        && false !== strpos($admin, "'gateway_enabled_list'")
        && false !== strpos($admin, "'ys_ec_ecpay_credit'")
        && false !== strpos($admin, "'ys_ec_ecpay_atm'")
        && false !== strpos($admin, "'ys_ec_ecpay_cvs'")
        && false !== strpos($admin, "'ys_ec_ecpay_barcode'")
);

v008_check(
    'ECPay logistics switches sync YS CART shipping state and lifecycle state',
    false !== strpos($admin, 'sync_shipping_enabled_list')
        && false !== strpos($admin, "sync_lifecycle_methods( 'shipping'")
        && false !== strpos($admin, "'ys_ec_shipping_enabled_list'")
        && false !== strpos($admin, "'ys_ec_ecpay_ship_family'")
        && false !== strpos($admin, "'ys_ec_ecpay_ship_unimart'")
        && false !== strpos($admin, "'ys_ec_ecpay_ship_hilife'")
);

v008_check(
    'ECPay settings UI is localized for YS CART admins',
    false !== strpos($admin, "'payment'     => '金流方式'")
        && false !== strpos($admin, "'shipping'    => '物流方式'")
        && false !== strpos($admin, "YSAdminApp::open( '綠界 ECPay 設定'")
        && false !== strpos($template, '啟用綠界 ECPay')
        && false !== strpos($template, '儲存綠界設定')
);

v008_check(
    'ECPay provider card labels are localized',
    false !== strpos($manifest, "'name'               => '綠界 ECPay'")
        && false !== strpos($manifest, "'label' => '信用卡'")
        && false !== strpos($manifest, "'label'          => '全家超商取貨'")
);

v008_check(
    'ECPay runtime registration is method-level gated',
    false !== strpos($plugin, "is_method_enabled( 'payment', 'ys_ec_ecpay_credit'")
        && false !== strpos($plugin, "is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_family'")
        && false !== strpos($plugin, "YSProviderLifecycleState::is_method_enabled")
);

v008_check(
    'ECPay CVS map supports all declared convenience store subtypes',
    false !== strpos($store, "'ys_ec_ecpay_ship_family'  => 'FAMI'")
        && false !== strpos($store, "'ys_ec_ecpay_ship_unimart' => 'UNIMART'")
        && false !== strpos($store, "'ys_ec_ecpay_ship_hilife'  => 'HILIFE'")
        && false !== strpos($store, "rest_url( 'ys-ecommerce/v1/ecpay/store-callback' )")
);

echo "\nREGRESSION v008_toggle_sync_and_localization_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
