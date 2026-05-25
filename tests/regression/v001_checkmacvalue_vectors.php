<?php
declare(strict_types=1);

define('YS_CART_ECPAY_TESTING', true);

$root = dirname(__DIR__, 2);
$file = $root . '/src/Support/CheckMacValue.php';

if (!is_file($file)) {
    fwrite(STDERR, "Missing CheckMacValue implementation: {$file}\n");
    exit(1);
}

require_once $file;

use YangSheep\YSCartEcpay\Support\CheckMacValue;

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: {$expected}\nActual:   {$actual}\n");
        exit(1);
    }
}

$aioParams = [
    'MerchantID'        => '3002607',
    'ItemName'          => "Tom's Shop",
    'TotalAmount'       => '100',
];

assert_same(
    'CF0A3D4901D99459D8641516EC57210700E8A5C9AB26B1D021301E9CB93EF78D',
    CheckMacValue::generate($aioParams, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs', 'sha256'),
    'AIO SHA256 CheckMacValue vector mismatch.'
);

$logisticsParams = [
    'MerchantID'        => '2000132',
    'LogisticsType'     => 'CVS',
    'LogisticsSubType'  => 'UNIMART',
    'MerchantTradeDate' => '2025/01/01 12:00:00',
];

assert_same(
    '545E6146FD45BDA683C88454DB34CE8D',
    CheckMacValue::generate($logisticsParams, '5294y06JbISpM5x9', 'v77hoKGq4kWxNNIS', 'md5'),
    'Domestic logistics MD5 CheckMacValue vector mismatch.'
);

$signed = $aioParams;
$signed['CheckMacValue'] = CheckMacValue::generate($signed, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs', 'sha256');

if (!CheckMacValue::verify($signed, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs', 'sha256')) {
    fwrite(STDERR, "Timing-safe CheckMacValue verification rejected a valid payload.\n");
    exit(1);
}

$signed['TotalAmount'] = '101';
if (CheckMacValue::verify($signed, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs', 'sha256')) {
    fwrite(STDERR, "CheckMacValue verification accepted a tampered payload.\n");
    exit(1);
}

echo "v001_checkmacvalue_vectors passed\n";
