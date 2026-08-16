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

// 合流後（0.2.16 main）：lifecycle 同步不再是事後 sync_lifecycle_methods() 呼叫，
// 而是進入原子管線的 desired-state（lifecycle_methods_setting_desired／
// lifecycle_provider_settings_desired，與其它鍵同一個 commit＋readback＋rollback）。
// 物流方式 ID 亦由型錄導出（EcpayShippingCatalog::alias_to_id()），不再抄清單。
v008_check(
    'ECPay payment switches sync YS CART gateway state and lifecycle state',
    false !== strpos($admin, 'sync_gateway_enabled_list')
        && false !== strpos($admin, 'lifecycle_methods_setting_desired')
        && false !== strpos($admin, "'gateway_enabled_list'")
        && false !== strpos($admin, "'ys_ec_ecpay_credit'")
        && false !== strpos($admin, "'ys_ec_ecpay_atm'")
        && false !== strpos($admin, "'ys_ec_ecpay_cvs'")
        && false !== strpos($admin, "'ys_ec_ecpay_barcode'")
);

v008_check(
    'ECPay logistics switches sync YS CART shipping state and lifecycle state',
    false !== strpos($admin, 'sync_shipping_enabled_list')
        && false !== strpos($admin, 'lifecycle_provider_settings_desired')
        && false !== strpos($admin, "'ys_ec_shipping_enabled_list'")
        && false !== strpos($admin, 'EcpayShippingCatalog::alias_to_id()')
);

v008_check(
    'ECPay settings UI is localized for YS CART admins',
    false !== strpos($admin, "'payment'     => '金流方式'")
        && false !== strpos($admin, "'shipping'    => '物流方式'")
        && false !== strpos($admin, "YSAdminApp::open( '綠界 ECPay 設定'")
        && false !== strpos($template, '啟用綠界 ECPay')
        && false !== strpos($template, '儲存綠界設定')
);

// 合流後：物流方式 label 的權威在型錄（manifest 由它導出），金流 label 仍在 manifest。
$catalog = v008_read('src/Shipping/Ecpay/EcpayShippingCatalog.php');
v008_check(
    'ECPay provider card labels are localized',
    false !== strpos($manifest, "'name'               => '綠界 ECPay'")
        && false !== strpos($manifest, "'label' => '信用卡'")
        && false !== strpos($catalog, "=> '全家超商取貨'")
);

// 合流後：物流 method gating 走 ShippingMethodOperability::is_operable()
//（catalog 逐式檢查，內部委派 YSProviderLifecycleState::is_method_enabled）。
$operability = v008_read('src/Support/ShippingMethodOperability.php');
v008_check(
    'ECPay runtime registration is method-level gated',
    false !== strpos($plugin, "is_method_enabled( 'payment', 'ys_ec_ecpay_credit'")
        && false !== strpos($plugin, 'ShippingMethodOperability::is_operable( $method_id )')
        && false !== strpos($plugin, "YSProviderLifecycleState::is_method_enabled")
        && false !== strpos($operability, "is_method_enabled( 'shipping', \$method_id, \$manifest )")
);

// 合流後：選店器的 subtype 對照**不再自己維護**，一律向型錄查（map_subtypes()）。
v008_check(
    'ECPay CVS map supports all declared convenience store subtypes',
    false !== strpos($store, 'EcpayShippingCatalog::map_subtypes()')
        && false !== strpos($catalog, "'FAMI'")
        && false !== strpos($catalog, "'UNIMART'")
        && false !== strpos($catalog, "'HILIFE'")
        && false !== strpos($store, "rest_url( 'ys-ecommerce/v1/ecpay/store-callback' )")
);

echo "\nREGRESSION v008_toggle_sync_and_localization_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
