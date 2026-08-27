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

// Official SDK concatenates scalar values byte-for-byte before URL encoding.
// Trimming here would accept a payload that is not the payload ECPay signed.
$whitespaceParams = [
    'MerchantID'        => '3002607',
    'MerchantTradeNo'   => 'ORDER-WS-1',
    'RtnMsg'            => ' leading + trailing ',
];

assert_same(
    '3D0B8B73FF8DC8A1D227E16811EA97AB803D3B0416E47AFEC4D7D08AA75B86F4',
    CheckMacValue::generate($whitespaceParams, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs', 'sha256'),
    'CheckMacValue must preserve scalar whitespace exactly like the official SDK.'
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

$whitespaceSigned = $whitespaceParams;
$whitespaceSigned['CheckMacValue'] = '3D0B8B73FF8DC8A1D227E16811EA97AB803D3B0416E47AFEC4D7D08AA75B86F4';
if (!CheckMacValue::verify($whitespaceSigned, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs', 'sha256')) {
    fwrite(STDERR, "CheckMacValue verification rejected an official byte-preserving whitespace vector.\n");
    exit(1);
}

$whitespaceSigned['RtnMsg'] = trim($whitespaceSigned['RtnMsg']);
if (CheckMacValue::verify($whitespaceSigned, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs', 'sha256')) {
    fwrite(STDERR, "CheckMacValue verification accepted a whitespace-normalized payload under the original signature.\n");
    exit(1);
}

echo "v001_checkmacvalue_vectors passed\n";
