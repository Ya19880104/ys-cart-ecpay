<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php';

if (!is_file($file)) {
    fwrite(STDERR, "Missing EcpayStoreSelector implementation: {$file}\n");
    exit(1);
}

$source = (string) file_get_contents($file);

$required = [
    '/Express/map',
    'ecpay/store-callback',
    'ecpay/logistics-notify',
    'cvs_store_id',
    'cvs_store_name',
    'cvs_store_addr',
    'ys_ec_selected_store',
    'localStorage',
    'postMessage',
    'CVSStoreID',
    'CVSStoreName',
    'CVSAddress',
    'LogisticsSubType',
];

foreach ($required as $needle) {
    if (false === strpos($source, $needle)) {
        fwrite(STDERR, "Store selector contract missing: {$needle}\n");
        exit(1);
    }
}

if (preg_match('/ecpay\\/store-callback.{0,80}ecpay\\/logistics-notify/s', $source) !== 1) {
    fwrite(STDERR, "Store callback and logistics notify must both be explicit and distinct.\n");
    exit(1);
}

echo "v003_ecpay_store_selector_contract passed\n";

