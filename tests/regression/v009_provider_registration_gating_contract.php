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

// 合流後（0.2.16 main）：物流 gating 集中在 ShippingMethodOperability::is_operable()
//（provider enabled＋shipping capability＋method-level lifecycle，缺 lifecycle 系統時
// 退回設定開關且 fail-closed），register_shipping_methods 由型錄逐式套用。
$operability = v009_read('src/Support/ShippingMethodOperability.php');
v009_check(
    'Shipping methods are not registered when ECPay shipping capability is disabled',
    (bool) preg_match('/function\s+register_shipping_methods\s*\([^)]*\)\s*:\s*void\s*\{(?:(?!YSShippingRegistry::register).)*ShippingMethodOperability::is_operable\s*\(/s', $plugin)
        && false !== strpos($operability, "is_capability_enabled( self::PROVIDER_ID, 'shipping', \$manifest )")
        && false !== strpos($operability, "is_method_enabled( 'shipping', \$method_id, \$manifest )")
        && false !== strpos($operability, "is_provider_enabled( self::PROVIDER_ID, \$manifest )")
);

// 每一個方式的註冊都必須通過 is_operable：型錄迴圈內 gate 先於 register，
// 且五個 B2C／宅配類別都必須存在於型錄描述中（id→class 配對）。
$catalog = v009_read('src/Shipping/Ecpay/EcpayShippingCatalog.php');
$shipping_methods = [
    'ys_ec_ecpay_ship_family'  => 'EcpayShippingFamily',
    'ys_ec_ecpay_ship_unimart' => 'EcpayShippingUnimart',
    'ys_ec_ecpay_ship_hilife'  => 'EcpayShippingHilife',
    'ys_ec_ecpay_ship_tcat'    => 'EcpayShippingTcat',
    'ys_ec_ecpay_ship_post'    => 'EcpayShippingPost',
];

$loop_gated = (bool) preg_match(
    '/foreach\s*\(\s*EcpayShippingCatalog::all\(\)[^{]*\{\s*if\s*\(\s*!\s*ShippingMethodOperability::is_operable\s*\(\s*\$method_id\s*\)\s*\)\s*\{\s*continue;.*?YSShippingRegistry::register\s*\(\s*\$method\s*\)/s',
    $plugin
);

foreach ($shipping_methods as $method_id => $class_name) {
    v009_check(
        "{$class_name} registration requires its ECPay lifecycle method switch",
        $loop_gated
            && (bool) preg_match(
                "/'{$method_id}'.{0,2000}?{$class_name}::class/s",
                $catalog
            )
    );
}

v009_check(
    'CVS map form data requires the declared ECPay shipping method switch',
    false !== strpos($store, '! ShippingMethodOperability::is_operable( $shipping_id )')
        && false !== strpos($operability, 'Settings::shipping_enabled( $alias )')
);

v009_check(
    'Store map route fails closed by lifecycle method state',
    false !== strpos($plugin, '! ShippingMethodOperability::is_operable( $shipping_id )')
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
