<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function v006_fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function v006_read(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        v006_fail("Missing required file: {$relative}");
    }
    return (string) file_get_contents($path);
}

function v006_check(string $label, bool $ok): void {
    if (!$ok) {
        v006_fail("FAIL: {$label}");
    }
}

$plugin    = v006_read('src/Plugin.php');
$settings  = v006_read('src/Support/Settings.php');
$admin     = v006_read('src/Admin/EcpaySettings.php');
$shipping  = v006_read('src/Shipping/Ecpay/EcpayShipping.php');
$logistics = v006_read('src/Api/EcpayLogisticsController.php');
$template  = v006_read('templates/admin/ecpay-settings.php');

v006_check(
    'provider admin_url stays a relative YS CART admin path',
    false === strpos($plugin, "admin_url( 'admin.php?page=ys-ecommerce-ecpay' )")
        && false !== strpos($plugin, "'admin_url'   => 'admin.php?page=ys-ecommerce-ecpay'")
);

v006_check(
    'settings no longer define provider-private shipping fee keys',
    false === strpos($settings, 'SHIPPING_COST_KEYS')
        && false === strpos($settings, 'ys_ec_ecpay_ship_family_cost')
        && false === strpos($settings, 'ys_ec_ecpay_ship_unimart_cost')
        && false === strpos($settings, 'ys_ec_ecpay_ship_hilife_cost')
        && false === strpos($settings, 'ys_ec_ecpay_ship_tcat_cost')
        && false === strpos($settings, 'ys_ec_ecpay_ship_post_cost')
);

v006_check(
    'settings expose canonical YS CART shipping method option helpers',
    false !== strpos($settings, 'shipping_method_option')
        && false !== strpos($settings, "'shipping_' . \$method_id . '_' . \$key")
        && false !== strpos($settings, 'shipping_base_fee')
        && false !== strpos($settings, 'shipping_free_threshold')
);

v006_check(
    'ECPay shipping cost reads canonical method-id based YS CART shipping settings',
    false !== strpos($shipping, 'Settings::shipping_base_fee( $this->get_id() )')
        && false !== strpos($shipping, 'Settings::shipping_free_threshold( $this->get_id() )')
        && false === strpos($shipping, 'Settings::shipping_cost( $this->settings_key() )')
        && false === strpos($shipping, 'Settings::free_threshold( $this->settings_key() )')
);

v006_check(
    'admin save no longer persists provider-private shipping cost/free-threshold fields',
    false === strpos($admin, 'SHIPPING_COST_KEYS')
        && false === strpos($admin, '_cost' )
        && false === strpos($admin, '_free_threshold')
);

v006_check(
    'ECPay settings UI is tabbed and routes shipping rates to YS CART Shipping Settings',
    false !== strpos($template, 'ysca-tabs')
        && false !== strpos($template, 'ys_ec_ecpay_tab')
        && false !== strpos($template, 'admin.php?page=ys-ec-shipping')
);

v006_check(
    'ECPay settings UI does not render shipping cost/free-threshold inputs',
    false === strpos($template, '_cost')
        && false === strpos($template, '_free_threshold')
        && false === strpos($template, '<th>Cost</th>')
        && false === strpos($template, '<th>Free threshold</th>')
);

v006_check(
    'ECPay logistics webhook advances the shared YS CART shipping pipeline',
    false !== strpos($logistics, 'YSShippingPipelineService')
        && false !== strpos($logistics, 'advance_from_carrier_status')
        && false !== strpos($logistics, "'webhook_ecpay'")
);

echo "v006_ys_cart_architecture_contracts passed\n";

