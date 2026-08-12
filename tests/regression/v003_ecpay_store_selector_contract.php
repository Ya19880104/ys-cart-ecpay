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

// v0.2.12：subtype 表不再由本檔自行維護——那是第二份清單，「型錄加了方式、
// 地圖沒加」正是它造成的。改為必須向型錄查詢。
if (false === strpos($source, 'EcpayShippingCatalog::map_subtypes()')) {
    fwrite(STDERR, "Store selector must resolve LogisticsSubType from EcpayShippingCatalog, not a local table.\n");
    exit(1);
}

if (preg_match('/private const SUBTYPES|private const METHOD_ALIASES/', $source) === 1) {
    fwrite(STDERR, "Store selector must not keep a second copy of the subtype/alias tables.\n");
    exit(1);
}

echo "v003_ecpay_store_selector_contract passed\n";
