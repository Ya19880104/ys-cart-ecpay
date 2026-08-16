<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function fail_contract(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function read_required(string $path): string {
    if (!is_file($path)) {
        fail_contract("Missing required file: {$path}");
    }
    return (string) file_get_contents($path);
}

$main     = read_required($root . '/ys-cart-ecpay.php');
$plugin   = read_required($root . '/src/Plugin.php');
$manifest = read_required($root . '/manifest.php');

$requiredMainStrings = [
    'Plugin Name: YS CART - ECPay',
    'YS_CART_ECPAY_VERSION',
    'YangSheep\\YSCartEcpay\\',
    'vendor/autoload.php',
    'YSPluginHubClient::register',
    "'slug'        => 'ys-cart-ecpay'",
];

foreach ($requiredMainStrings as $needle) {
    if (false === strpos($main, $needle)) {
        fail_contract("Main plugin bootstrap missing: {$needle}");
    }
}

$requiredPluginStrings = [
    'ys_ec_provider_manifests',
    'register_manifest',
    'ys_ec_register_gateways',
    'ys_ec_register_shipping_methods',
    'ys_ec_register_storefront_routes',
    'ys_ec_shipping_requester',
    'ys_ec_shipping_carrier_adapter',
    'ys_ec_shipping_provider_labels',
    'ys_ec_ecpay_credit',
    'ys_ec_ecpay_atm',
    'ys_ec_ecpay_cvs',
    'ys_ec_ecpay_barcode',
    // 合流後（0.2.16 main）：物流方式由型錄逐一註冊——Plugin 不再抄方式 ID，
    // 「型錄加了、註冊忘了」在語法上不可能發生。這裡改驗 Plugin 引用型錄。
    'EcpayShippingCatalog::all()',
];

foreach ($requiredPluginStrings as $needle) {
    if (false === strpos($plugin, $needle)) {
        fail_contract("Plugin contract missing: {$needle}");
    }
}

// 物流方式 ID 的權威=型錄（manifest 也由它導出）。全集合斷言：B2C／宅配五式
// ＋C2C 店到店三式＋低溫三式。UNIMARTFREEZEC2C 依官方型錄審查不得存在
//（綠界目前僅載明冷凍 B2C；加入前需綠界書面確認）。
$catalog = read_required($root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php');
$requiredCatalogMethodIds = [
    'ys_ec_ecpay_ship_family',
    'ys_ec_ecpay_ship_unimart',
    'ys_ec_ecpay_ship_hilife',
    'ys_ec_ecpay_ship_tcat',
    'ys_ec_ecpay_ship_post',
    'ys_ec_ecpay_ship_family_c2c',
    'ys_ec_ecpay_ship_unimart_c2c',
    'ys_ec_ecpay_ship_hilife_c2c',
    'ys_ec_ecpay_ship_unimart_freeze',
    'ys_ec_ecpay_ship_tcat_chilled',
    'ys_ec_ecpay_ship_tcat_frozen',
];

foreach ($requiredCatalogMethodIds as $needle) {
    if (false === strpos($catalog, "'{$needle}'")) {
        fail_contract("Catalog contract missing: {$needle}");
    }
}

if (false !== strpos($catalog, "'ys_ec_ecpay_ship_unimart_freeze_c2c'")) {
    fail_contract('UNIMARTFREEZEC2C must stay out of the catalog until ECPay confirms frozen C2C in writing.');
}

if (false === strpos($manifest, 'EcpayShippingCatalog::manifest_methods()')) {
    fail_contract('manifest.php must derive shipping methods from the catalog (single source).');
}

if (false !== strpos($plugin, 'ys_ec_providers') || false !== strpos($plugin, 'ys_ec_admin_payment_menus')) {
    fail_contract('ECPay must use manifest-first provider registration, not legacy provider/menu hooks.');
}

if (false === strpos($manifest, "'slug'                => 'ys-provider-ecpay'")) {
    fail_contract('Manifest admin page must use ys-provider-ecpay.');
}

echo "v002_provider_contracts passed\n";
