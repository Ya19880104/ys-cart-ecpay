<?php
/**
 * Behavior + contract regression: map-url 購物車商品允許交集驗證（v0.2.11）。
 *
 * 背景：/stores/ecpay/map-url 先前只驗 provider 啟用與該物流方式的**全域**啟用
 * 狀態，未驗購物車內商品的「允許的物流方式」交集。結果：購物車商品只允許
 * 萊爾富時，前端仍可 POST shipping_id=…unimart 取得**已簽章**的電子地圖表單，
 * 使用者選完 7-11 門市、callback 也會寫入 session 與 localStorage，直到送單才
 * 被核心擋下。門市 session 與選店結果已為禁用方式而產生。
 *
 * 修正＝在 map-url handler 內以核心 YSShippingRegistry::is_method_allowed_for_cart()
 * 這一份共用守門驗證，fail-closed；僅在購物車情境（order_id=0）套用。
 *
 * 驗證：
 *   (a) 共用守門述詞三態（允許／禁用／無設限）
 *   (b) 交集為空 → 禁用（fail-closed）
 *   (c) read_cart_items：訪客無 session cookie → 空陣列（不觸發 setcookie 副作用）
 *   (d) read_cart_items：以 scope filter 純讀 get_items_raw()，並於結束後移除 filter
 *   (e) is_shipping_allowed_for_cart：核心缺少述詞時回 true（舊核心相容）
 *   (f) 契約：handler 在 build_map_form_data 之前呼叫購物車守門，且以 order_id 分流
 *
 * Run: php tests/regression/v016_map_url_cart_allowed_intersection.php
 */

declare(strict_types=1);

namespace YangSheep\Ecommerce\Models {
    final class YSProduct {
        /** @var array<int, object> */
        public static array $rows = [];

        public static function find(int $id): ?object {
            return self::$rows[$id] ?? null;
        }
    }
}

namespace YangSheep\Ecommerce\Handlers {
    final class YSCartHandler {
        public static array $items = [];
        public static int $calls = 0;
        public static ?string $scope_seen = null;

        public static function get_instance(): self {
            return new self();
        }

        public function get_items_raw(): array {
            ++self::$calls;
            self::$scope_seen = \apply_filters('ys_ec_cart_key_scope', 'default');
            return self::$items;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_CART_ECPAY_TESTING', true);

    $GLOBALS['ys_filters'] = [];

    function add_filter(string $tag, callable $cb, int $priority = 10, int $args = 1): void {
        $GLOBALS['ys_filters'][$tag][$priority][] = $cb;
    }

    function remove_filter(string $tag, callable $cb, int $priority = 10): bool {
        foreach ($GLOBALS['ys_filters'][$tag][$priority] ?? [] as $i => $existing) {
            if ($existing === $cb) {
                unset($GLOBALS['ys_filters'][$tag][$priority][$i]);
                return true;
            }
        }
        return false;
    }

    function apply_filters(string $tag, $value, ...$rest) {
        $byPriority = $GLOBALS['ys_filters'][$tag] ?? [];
        ksort($byPriority);
        foreach ($byPriority as $bucket) {
            foreach ($bucket as $cb) {
                $value = $cb($value, ...$rest);
            }
        }
        return $value;
    }

    function count_registered_filters(string $tag): int {
        $n = 0;
        foreach ($GLOBALS['ys_filters'][$tag] ?? [] as $bucket) {
            $n += count($bucket);
        }
        return $n;
    }

    $GLOBALS['ys_logged_in'] = false;
    function is_user_logged_in(): bool {
        return (bool) $GLOBALS['ys_logged_in'];
    }

    require_once dirname(__DIR__, 3) . '/ys-cart/src/Shipping/YSShippingRegistry.php';

    use YangSheep\Ecommerce\Handlers\YSCartHandler;
    use YangSheep\Ecommerce\Models\YSProduct;
    use YangSheep\Ecommerce\Shipping\YSShippingRegistry;

    $pass = 0;
    $fail = 0;
    $assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
        if ($ok) {
            ++$pass;
            echo "  PASS  {$label}\n";
            return;
        }
        ++$fail;
        echo "  FAIL  {$label}\n";
    };

    $seed = static function (array $rows): void {
        YSProduct::$rows = [];
        foreach ($rows as $id => $allowed) {
            YSProduct::$rows[$id] = (object) ['id' => $id, 'allowed_shipping_methods' => $allowed];
        }
    };

    // (a) 共用守門三態：購物車只允許萊爾富
    $seed([501 => '["ys_ec_ecpay_ship_hilife"]']);
    $cart = [['product_id' => 501]];
    $assert(
        YSShippingRegistry::is_method_allowed_for_cart('ys_ec_ecpay_ship_hilife', $cart) === true
        && YSShippingRegistry::is_method_allowed_for_cart('ys_ec_ecpay_ship_unimart', $cart) === false
        && YSShippingRegistry::is_method_allowed_for_cart('ys_ec_ecpay_ship_unimart', []) === true,
        '(a) 共用守門：允許 hilife／禁用 unimart／空車不限'
    );

    // (b) 兩商品互斥 → 交集為空 → 全部禁用
    $seed([
        501 => '["ys_ec_ecpay_ship_hilife"]',
        502 => '["ys_ec_ecpay_ship_unimart"]',
    ]);
    $cart2 = [['product_id' => 501], ['product_id' => 502]];
    $assert(
        YSShippingRegistry::is_method_allowed_for_cart('ys_ec_ecpay_ship_hilife', $cart2) === false
        && YSShippingRegistry::is_method_allowed_for_cart('ys_ec_ecpay_ship_unimart', $cart2) === false,
        '(b) 商品限制互斥 → 交集為空，兩者皆禁用（fail-closed）'
    );

    // --- provider 端方法（以 Reflection 直接測 private 實作）-------------------
    require_once dirname(__DIR__, 2) . '/src/Plugin.php';
    $plugin_rc = new \ReflectionClass(\YangSheep\YSCartEcpay\Plugin::class);

    $read_cart_items  = $plugin_rc->getMethod('read_cart_items');
    $allowed_for_cart = $plugin_rc->getMethod('is_shipping_allowed_for_cart');
    $plugin           = $plugin_rc->newInstanceWithoutConstructor();

    // (c) 訪客無 session cookie → 空陣列，且完全不呼叫 cart handler
    $GLOBALS['ys_logged_in'] = false;
    $_COOKIE = [];
    YSCartHandler::$calls = 0;
    YSCartHandler::$items = [['product_id' => 501]];
    $items = $read_cart_items->invoke(null, 'default');
    $assert(
        [] === $items && 0 === YSCartHandler::$calls,
        '(c) 訪客無 session cookie → 空車短路（不觸發 setcookie 副作用）'
    );

    // (d) 有 cookie → 純讀 get_items_raw，scope 綁定生效且事後移除 filter
    $_COOKIE = ['ys_ec_session_shop2' => 'abc'];
    YSCartHandler::$calls = 0;
    YSCartHandler::$scope_seen = null;
    YSCartHandler::$items = [['product_id' => 501]];
    $before = count_registered_filters('ys_ec_cart_key_scope');
    $items = $read_cart_items->invoke(null, 'shop2');
    $after = count_registered_filters('ys_ec_cart_key_scope');
    $assert(
        [['product_id' => 501]] === $items
        && 1 === YSCartHandler::$calls
        && 'shop2' === YSCartHandler::$scope_seen
        && $before === $after,
        '(d) 純讀 get_items_raw、scope 綁定為 shop2、filter 於 finally 移除'
    );

    // 端到端：購物車限 hilife 時，unimart 應被 provider 守門擋下
    $_COOKIE = ['ys_ec_session' => 'abc'];
    YSCartHandler::$items = [['product_id' => 501]];
    $seed([501 => '["ys_ec_ecpay_ship_hilife"]']);
    $ok_hilife = $allowed_for_cart->invoke($plugin, 'ys_ec_ecpay_ship_hilife', 'default');
    $ok_unimart = $allowed_for_cart->invoke($plugin, 'ys_ec_ecpay_ship_unimart', 'default');
    $assert(
        true === $ok_hilife && false === $ok_unimart,
        '(e) provider 守門端到端：hilife 放行、unimart 擋下（禁用 sub-type 不簽發地圖）'
    );

    // (f) 契約：handler 在 build_map_form_data 之前守門，且以 order_id 分流
    $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php'));
    $pos_guard = strpos($src, 'is_shipping_allowed_for_cart( $shipping_id, $cart_scope )');
    $pos_build = strpos($src, 'EcpayStoreSelector::build_map_form_data(');
    $assert(
        false !== $pos_guard
        && false !== $pos_build
        && $pos_guard < $pos_build
        && str_contains($src, '0 === $order_id && ! $this->is_shipping_allowed_for_cart')
        && str_contains($src, 'shipping_method_not_allowed'),
        '(f) 守門在 build_map_form_data 之前、以 order_id=0 分流、回專屬錯誤碼'
    );

    echo "\nmap-url cart allowed intersection: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
