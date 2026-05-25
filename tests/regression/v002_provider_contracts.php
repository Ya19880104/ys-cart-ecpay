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

$main   = read_required($root . '/ys-cart-ecpay.php');
$plugin = read_required($root . '/src/Plugin.php');

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
    'ys_ec_register_gateways',
    'ys_ec_register_shipping_methods',
    'ys_ec_providers',
    'ys_ec_admin_payment_menus',
    'ys_ec_register_storefront_routes',
    'ys_ec_shipping_requester',
    'ys_ec_shipping_carrier_adapter',
    'ys_ec_shipping_provider_labels',
    'ys_ec_external_admin_pages',
    'ys_ec_ecpay_credit',
    'ys_ec_ecpay_atm',
    'ys_ec_ecpay_cvs',
    'ys_ec_ecpay_barcode',
    'ys_ec_ecpay_ship_family',
    'ys_ec_ecpay_ship_unimart',
    'ys_ec_ecpay_ship_hilife',
    'ys_ec_ecpay_ship_tcat',
    'ys_ec_ecpay_ship_post',
    'ys-ecommerce-ecpay',
];

foreach ($requiredPluginStrings as $needle) {
    if (false === strpos($plugin, $needle)) {
        fail_contract("Plugin contract missing: {$needle}");
    }
}

echo "v002_provider_contracts passed\n";

