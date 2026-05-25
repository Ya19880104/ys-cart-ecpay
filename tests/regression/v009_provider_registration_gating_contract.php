<?php
/**
 * Provider registration gating contract.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function v009_read(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$relative}\n");
        exit(1);
    }

    return (string) file_get_contents($path);
}

function v009_check(string $label, bool $ok): void
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

$plugin = v009_read('src/Plugin.php');
$store  = v009_read('src/Shipping/Ecpay/EcpayStoreSelector.php');
$main   = v009_read('ys-cart-ecpay.php');

echo "## Provider registration gating contract\n";

v009_check(
    'Plugin imports Settings for provider master switch gating',
    false !== strpos($plugin, 'use YangSheep\\YSCartEcpay\\Support\\Settings;')
);

v009_check(
    'Gateways are not registered when ECPay provider is disabled',
    (bool) preg_match('/function\s+register_gateways\s*\([^)]*\)\s*:\s*void\s*\{(?:(?!YSGatewayRegistry::register).)*Settings::enabled\s*\(/s', $plugin)
        && false !== strpos($plugin, '! Settings::enabled()')
);

v009_check(
    'Shipping methods are not registered when ECPay provider is disabled',
    (bool) preg_match('/function\s+register_shipping_methods\s*\([^)]*\)\s*:\s*void\s*\{(?:(?!YSShippingRegistry::register).)*Settings::enabled\s*\(/s', $plugin)
        && false !== strpos($plugin, '! Settings::enabled()')
);

$shipping_aliases = [
    'ship_family'  => 'EcpayShippingFamily',
    'ship_unimart' => 'EcpayShippingUnimart',
    'ship_hilife'  => 'EcpayShippingHilife',
    'ship_tcat'    => 'EcpayShippingTcat',
    'ship_post'    => 'EcpayShippingPost',
];

foreach ($shipping_aliases as $alias => $class_name) {
    v009_check(
        "{$class_name} registration requires its ECPay method switch",
        (bool) preg_match(
            "/Settings::shipping_enabled\s*\(\s*'{$alias}'\s*\).*?YSShippingRegistry::register\s*\(\s*new\s+{$class_name}\s*\(/s",
            $plugin
        )
    );
}

v009_check(
    'CVS map form data requires the declared ECPay shipping method switch',
    false !== strpos($store, 'METHOD_ALIASES')
        && false !== strpos($store, 'Settings::shipping_enabled( $method_alias )')
);

v009_check(
    'Release version is bumped for provider registration gating fix',
    false !== strpos($main, 'Version: 0.2.3')
        && false !== strpos($main, "YS_CART_ECPAY_VERSION', '0.2.3'")
);

echo "\nREGRESSION v009_provider_registration_gating_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
