<?php
/**
 * ECPay public docs and skill must publish the canonical store-map payload.
 */

declare(strict_types=1);

$root   = dirname(__DIR__, 2);
$sdk    = (string) file_get_contents($root . '/sdk/ys-cart-ecpay-headless.js');
$docs   = (string) file_get_contents($root . '/docs/headless.md');
$skill  = (string) file_get_contents($root . '/skills/ys-cart-ecpay-headless.md');
$readme = (string) file_get_contents($root . '/README.md');

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

$check(
    'SDK exposes canonical store-map helper',
    str_contains($sdk, 'requestStoreMapForm')
        && str_contains($sdk, 'shipping_id: shippingId')
        && str_contains($sdk, '/wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url')
);

$check(
    'Docs publish shipping_id payload',
    str_contains($docs, '"shipping_id": "ys_ec_ecpay_ship_unimart"')
        && str_contains($docs, 'Use `shipping_id` as the public payload key')
        && str_contains($docs, 'YsCartEcpay.requestStoreMapForm')
);

$check(
    'Skill instructs agents to send shipping_id',
    str_contains($skill, 'as `shipping_id`')
        && str_contains($skill, 'provider-facing callback surfaces')
);

$check(
    'README documents headless route and callback boundary',
    str_contains($readme, '"shipping_id": "ys_ec_ecpay_ship_unimart"')
        && str_contains($readme, 'provider-facing callback routes')
        && str_contains($readme, 'YsCartEcpay.requestStoreMapForm')
);

echo "v013_headless_docs_contract FAIL={$fail}" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
