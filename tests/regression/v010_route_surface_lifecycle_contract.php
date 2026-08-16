<?php
/**
 * ECPay route surface lifecycle contract.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function v010_read(string $relative): string
{
    global $root;
    return (string) file_get_contents($root . '/' . ltrim($relative, '/\\'));
}

function v010_check(string $label, bool $ok): void
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

$plugin = v010_read('src/Plugin.php');
$print  = v010_read('src/Api/EcpayPrintController.php');
$requester = v010_read('src/Shipping/Ecpay/EcpayShippingRequester.php');

v010_check(
    'Payment callback routes require at least one enabled ECPay payment method',
    str_contains($plugin, 'private function has_enabled_payment_methods(): bool')
        && str_contains($plugin, 'self::REGISTERED_GATEWAY_IDS as $method_id')
        && str_contains($plugin, "is_method_enabled( 'payment', \$method_id )")
        && str_contains($plugin, 'if ( $this->has_enabled_payment_methods() )')
);

// 合流後（0.2.16 main）：物流方式清單的權威在型錄——has_enabled_shipping_methods()
// 委派 ShippingMethodOperability::has_operable_method()（逐式 is_operable 掃描），
// 不再抄一份 REGISTERED_SHIPPING_IDS。
$operability = v010_read('src/Support/ShippingMethodOperability.php');
v010_check(
    'Shipping public/storefront routes require at least one enabled ECPay shipping method',
    str_contains($plugin, 'private function has_enabled_shipping_methods(): bool')
        && str_contains($plugin, 'ShippingMethodOperability::has_operable_method()')
        && str_contains($operability, 'public static function has_operable_method(): bool')
        && str_contains($operability, 'if ( self::is_operable( (string) $method_id ) )')
        && str_contains($plugin, 'if ( ! $this->has_enabled_shipping_methods() )')
);

v010_check(
    'Print admin-post route is registered only when an ECPay shipping method is operable',
    ! str_contains($plugin, 'EcpayPrintController::register();' . PHP_EOL . PHP_EOL . "\t\tadd_filter")
        && str_contains($plugin, "add_action( 'init', [ \$this, 'sync_print_route' ], 20 )")
        && str_contains($plugin, 'public function sync_print_route(): void')
        && str_contains($plugin, 'if ( $this->has_enabled_shipping_methods() )')
        && str_contains($plugin, 'EcpayPrintController::unregister();')
        && str_contains($print, 'public static function unregister(): void')
);

v010_check(
    'Carrier requester and provider labels also fail closed when every ECPay shipping method is disabled',
    str_contains($plugin, 'register_shipping_requester')
        && str_contains($plugin, 'register_shipping_provider_label')
        && substr_count($plugin, 'has_enabled_shipping_methods()') >= 5
);

v010_check(
    'Print redirect only posts to known ECPay logistics hosts',
    str_contains($print, "wp_parse_url( \$api_url, PHP_URL_HOST )")
        && str_contains($print, "'logistics.ecpay.com.tw'")
        && str_contains($print, "'logistics-stage.ecpay.com.tw'")
        && str_contains($print, 'Unsupported print host.')
);

// 合流後：列印端點的 method gate 同樣走 ShippingMethodOperability::is_operable()
//（其內部含 provider／capability／method-level lifecycle 三層）。
v010_check(
    'Print redirect payload carries method id and is blocked when lifecycle method is disabled',
    str_contains($requester, "'method_id' => \$this->method->get_id()")
        && str_contains($print, "\$method_id = sanitize_key")
        && str_contains($print, '! ShippingMethodOperability::is_operable( $method_id )')
        && str_contains($print, 'ECPay print method is disabled.')
);

echo "\nREGRESSION v010_route_surface_lifecycle_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
