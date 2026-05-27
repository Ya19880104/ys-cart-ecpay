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
$manifest = v009_read('manifest.php');

echo "## Provider registration gating contract\n";

v009_check(
    'Plugin imports Settings for provider master switch gating',
    false !== strpos($plugin, 'use YangSheep\\YSCartEcpay\\Support\\Settings;')
);

v009_check(
    'Provider card and settings page are manifest-first',
    false !== strpos($plugin, "add_filter( 'ys_ec_provider_manifests'")
        && false !== strpos($manifest, "'id'                 => 'ys_ecpay'")
        && false !== strpos($manifest, "'slug'                => 'ys-provider-ecpay'")
        && false === strpos($plugin, "ys_ec_providers")
        && false === strpos($plugin, "ys_ec_admin_payment_menus")
);

v009_check(
    'Gateways are not registered when ECPay payment capability is disabled',
    (bool) preg_match('/function\s+register_gateways\s*\([^)]*\)\s*:\s*void\s*\{(?:(?!YSGatewayRegistry::register).)*is_payment_enabled\s*\(/s', $plugin)
        && false !== strpos($plugin, "is_capability_enabled( 'ys_ecpay', 'payment'")
);

v009_check(
    'Shipping methods are not registered when ECPay shipping capability is disabled',
    (bool) preg_match('/function\s+register_shipping_methods\s*\([^)]*\)\s*:\s*void\s*\{(?:(?!YSShippingRegistry::register).)*is_shipping_enabled\s*\(/s', $plugin)
        && false !== strpos($plugin, "is_capability_enabled( 'ys_ecpay', 'shipping'")
);

$shipping_methods = [
    'ys_ec_ecpay_ship_family'  => 'EcpayShippingFamily',
    'ys_ec_ecpay_ship_unimart' => 'EcpayShippingUnimart',
    'ys_ec_ecpay_ship_hilife'  => 'EcpayShippingHilife',
    'ys_ec_ecpay_ship_tcat'    => 'EcpayShippingTcat',
    'ys_ec_ecpay_ship_post'    => 'EcpayShippingPost',
];

foreach ($shipping_methods as $method_id => $class_name) {
    v009_check(
        "{$class_name} registration requires its ECPay lifecycle method switch",
        (bool) preg_match(
            "/is_method_enabled\s*\(\s*'shipping'\s*,\s*'{$method_id}'\s*\).*?YSShippingRegistry::register\s*\(\s*new\s+{$class_name}\s*\(/s",
            $plugin
        )
    );
}

v009_check(
    'CVS map form data requires the declared ECPay shipping method switch',
    false !== strpos($store, 'METHOD_ALIASES')
        && false !== strpos($store, "YSProviderLifecycleState::is_method_enabled( 'shipping', \$shipping_id")
        && false !== strpos($store, 'Settings::shipping_enabled( $method_alias )')
);

v009_check(
    'Store map route fails closed by lifecycle method state',
    false !== strpos($plugin, "is_method_enabled( 'shipping', \$shipping_id")
        && false !== strpos($plugin, 'shipping_method_disabled')
);

v009_check(
    'Release version is bumped for provider registration gating fix',
    preg_match('/Version:\s*([0-9.]+)/', $main, $version_match)
        && preg_match("/YS_CART_ECPAY_VERSION', '([0-9.]+)'/", $main, $constant_match)
        && version_compare((string) ($version_match[1] ?? ''), '0.2.4', '>=')
        && version_compare((string) ($constant_match[1] ?? ''), '0.2.4', '>=')
);

echo "\nREGRESSION v009_provider_registration_gating_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
