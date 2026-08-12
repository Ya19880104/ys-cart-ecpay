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
];

foreach ($requiredPluginStrings as $needle) {
    if (false === strpos($plugin, $needle)) {
        fail_contract("Plugin contract missing: {$needle}");
    }
}

// v0.2.12：物流方式 ID 不再逐一寫死在 Plugin.php——它們由 EcpayShippingCatalog
// 這一份型錄導出。因此改為**向型錄要答案**，而不是在原始碼裡找字串；否則任何把
// 清單上收到共用入口的重構都會讓這條紅，而紅的原因與正確性無關。
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';

$catalogIds = \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog::ids();
foreach (['ys_ec_ecpay_ship_family', 'ys_ec_ecpay_ship_unimart', 'ys_ec_ecpay_ship_hilife', 'ys_ec_ecpay_ship_tcat', 'ys_ec_ecpay_ship_post'] as $method_id) {
    if (!in_array($method_id, $catalogIds, true)) {
        fail_contract("Shipping catalog missing method: {$method_id}");
    }
}

// Plugin 必須真的以型錄驅動註冊，而不是自己再列一份。
if (false === strpos($plugin, 'EcpayShippingCatalog::all()')
    || false === strpos($plugin, 'EcpayShippingCatalog::ids()')
    || false === strpos($plugin, 'EcpayShippingCatalog::id_to_alias()')) {
    fail_contract('Plugin must drive shipping registration, discovery and legacy alias mapping from EcpayShippingCatalog.');
}

if (false !== strpos($plugin, 'ys_ec_providers') || false !== strpos($plugin, 'ys_ec_admin_payment_menus')) {
    fail_contract('ECPay must use manifest-first provider registration, not legacy provider/menu hooks.');
}

if (false === strpos($manifest, "'slug'                => 'ys-provider-ecpay'")) {
    fail_contract('Manifest admin page must use ys-provider-ecpay.');
}

echo "v002_provider_contracts passed\n";
