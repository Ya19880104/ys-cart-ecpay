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

// 合流後（0.2.16 main）：職責分離——選店器的地圖回呼**只能**指向
// ecpay/store-callback；ecpay/logistics-notify 是物流狀態通知，由
// EcpayShippingRequester 在建立物流單時作為 ServerReplyURL 使用。
// 反混用主張因此改為：選店器不得出現 logistics-notify，Requester 必須使用它。
if (false !== strpos($source, 'ecpay/logistics-notify')) {
    fwrite(STDERR, "Store selector must not point any callback at ecpay/logistics-notify.\n");
    exit(1);
}

$requester = (string) file_get_contents($root . '/src/Shipping/Ecpay/EcpayShippingRequester.php');
if (false === strpos($requester, 'ecpay/logistics-notify')) {
    fwrite(STDERR, "Shipping requester must declare ecpay/logistics-notify as ServerReplyURL.\n");
    exit(1);
}

echo "v003_ecpay_store_selector_contract passed\n";
