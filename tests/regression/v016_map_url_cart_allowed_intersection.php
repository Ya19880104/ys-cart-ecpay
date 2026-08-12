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
 * 這一份共用守門驗證，fail-closed。
 *
 * 初版另有兩個缺口，一併涵蓋於此：
 *   1. 守門寫成 `0 === $order_id &&` 分流，而 order_id 直接取自請求且不驗訂單存在／
 *      擁有者／品項，任何非零值都能整段跳過守門。現改為端點直接拒收 order_id。
 *   2. read_cart_items 把 handler 缺失／非陣列／例外全部轉成空陣列，而核心把空車視為
 *      「不限物流」，因此讀取失敗反而會簽發地圖表單。現以 null 表示讀取失敗並 fail-closed。
 *
 * 驗證：
 *   (a) 共用守門述詞三態（允許／禁用／無設限）
 *   (b) 交集為空 → 禁用（fail-closed）
 *   (c) read_cart_items：訪客無 session cookie → 空陣列（不觸發 setcookie 副作用）
 *   (d) read_cart_items：以 scope filter 純讀 try_get_items_raw()，並於結束後移除 filter
 *   (e) is_shipping_allowed_for_cart 端到端：禁用 sub-type 不簽發地圖
 *   (f) 讀取失敗 → null 且守門 fail-closed
 *   (i) 契約：核心述詞／typed 讀取 API 缺席一律 fail-closed（不得 return true 相容舊核心）
 *   (g) 確定為空的購物車 → 空陣列（與讀取失敗分流），不限物流
 *   (h) 契約：拒收 order_id、守門無條件執行且早於 build_map_form_data、無分流殘留
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

namespace YangSheep\Ecommerce\Api\Storefront {
    /** 只需要能分辨 success／error 兩種回應。 */
    final class YSRestResponder {
        public static function error(string $code, string $message = '', int $status = 400): \WP_REST_Response {
            return new \WP_REST_Response([ 'success' => false, 'code' => $code, 'message' => $message ], $status);
        }

        public static function success(string $code, string $message = '', array $data = []): \WP_REST_Response {
            return new \WP_REST_Response([ 'success' => true, 'code' => $code, 'data' => $data ], 200);
        }
    }
}

namespace YangSheep\Ecommerce\Gateways {
    /**
     * 金流註冊表的替身：只回答「這個 id 有沒有註冊」。
     * map-url 端點以它判斷付款方式是否有效——認不出來的字串不得被靜默當成非代收。
     */
    final class YSGatewayRegistry {
        /** @var array<string,bool> */
        public static array $registered = [
            'ys_ec_cod'         => true,
            'ys_ec_ecpay_credit' => true,
        ];

        public static function get(string $id): ?object {
            return isset(self::$registered[$id]) ? (object) [ 'id' => $id ] : null;
        }
    }
}

namespace YangSheep\Ecommerce\Handlers {
    final class YSCartHandler {
        /** null＝核心回報讀取失敗（SQL 錯誤／items 壞 JSON）。 */
        public static ?array $items = [];
        public static int $calls = 0;
        public static ?string $scope_seen = null;
        public static ?\Throwable $throw = null;

        public static function get_instance(): self {
            return new self();
        }

        /**
         * 核心 v2.56.4 的 error-aware API：[]＝確定為空；null＝讀取失敗。
         * 舊的 get_items_raw() 刻意不提供，provider 不得再使用它做守門。
         */
        public function try_get_items_raw(): ?array {
            ++self::$calls;
            self::$scope_seen = \apply_filters('ys_ec_cart_key_scope', 'default');
            if (null !== self::$throw) {
                throw self::$throw;
            }
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

    if (! class_exists('WP_REST_Response')) {
        class WP_REST_Response {
            public function __construct(public $data = null, public int $status = 200) {}
        }
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
        '(d) 純讀 try_get_items_raw、scope 綁定為 shop2、filter 於 finally 移除'
    );

    // (d2) 非 default scope 只認**該 scope** 的 cookie
    //      先前額外接受 default cookie，於是該 scope 尚無購物車時仍會進入讀取路徑，
    //      觸發 get_or_create_session() 建立新 session（純讀請求不該有此副作用），
    //      而且讀到的會是另一個 scope 的車。
    $_COOKIE = ['ys_ec_session' => 'abc']; // 只有 default 的 cookie
    YSCartHandler::$calls = 0;
    YSCartHandler::$items = [['product_id' => 501]];
    $items = $read_cart_items->invoke(null, 'shop2');
    $assert(
        [] === $items && 0 === YSCartHandler::$calls,
        '(d2) 非 default scope 僅憑 default cookie → 視為該 scope 空車，完全不讀取'
    );

    // (d3) 核心回報讀取失敗（SQL 錯誤／壞 JSON → null）→ 守門 fail-closed
    $_COOKIE = ['ys_ec_session' => 'abc'];
    $seed([501 => '["ys_ec_ecpay_ship_hilife"]']);
    YSCartHandler::$items = null;
    $items_null = $read_cart_items->invoke(null, 'default');
    $ok_on_null = $allowed_for_cart->invoke($plugin, 'ys_ec_ecpay_ship_hilife', 'default');
    YSCartHandler::$items = [];
    $assert(
        null === $items_null && false === $ok_on_null,
        '(d3) 核心 try_get_items_raw 回 null（SQL 錯誤／壞 JSON）→ 守門 fail-closed'
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

    // (f) 讀取失敗 ≠ 空購物車：必須 fail-closed
    $_COOKIE = ['ys_ec_session' => 'abc'];
    $seed([501 => '["ys_ec_ecpay_ship_hilife"]']);
    YSCartHandler::$throw = new \RuntimeException('db down');
    $items_fail = $read_cart_items->invoke(null, 'default');
    $ok_on_fail = $allowed_for_cart->invoke($plugin, 'ys_ec_ecpay_ship_hilife', 'default');
    YSCartHandler::$throw = null;
    $assert(
        null === $items_fail && false === $ok_on_fail,
        '(f) 購物車讀取失敗 → read_cart_items 回 null、守門 fail-closed（不得因空車被視為不限）'
    );

    // (g) 空購物車仍須與讀取失敗分流：空車＝讀取成功、不限物流
    $_COOKIE = ['ys_ec_session' => 'abc'];
    YSCartHandler::$items = [];
    $items_empty = $read_cart_items->invoke(null, 'default');
    $assert(
        [] === $items_empty
        && true === $allowed_for_cart->invoke($plugin, 'ys_ec_ecpay_ship_unimart', 'default'),
        '(g) 確定為空的購物車回空陣列（非 null），不限物流'
    );

    // (h) 契約：端點拒收 order_id，且守門無條件在 build_map_form_data 之前執行。
    //     初版以 `0 === $order_id` 分流，任何非零值都能跳過守門，而 order_id 取自
    //     請求、不驗訂單存在／擁有者／品項。此處明確斷言該分流已不存在。
    $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php'));
    $pos_reject = strpos($src, "'order_id_not_supported'");
    $pos_guard  = strpos($src, 'is_shipping_allowed_for_cart( $shipping_id, $cart_scope )');
    $pos_build  = strpos($src, 'EcpayStoreSelector::build_map_form_data(');
    $assert(
        false !== $pos_reject
        && false !== $pos_guard
        && false !== $pos_build
        && $pos_reject < $pos_guard
        && $pos_guard < $pos_build
        && ! str_contains($src, '0 === $order_id && ! $this->is_shipping_allowed_for_cart')
        && ! str_contains($src, '0 === $order_id &&')
        && str_contains($src, 'shipping_method_not_allowed'),
        '(h) 非零 order_id 先被拒收、守門無條件執行且在 build_map_form_data 之前、無 order_id 分流殘留'
    );

    // (i) 契約：核心述詞或 typed 讀取 API 缺席時必須 fail-closed。
    //     初版以 `return true` 相容舊核心——那等於守門在舊核心上完全不存在；發版順序是
    //     流程約定，不能取代 runtime gate（降版／部分部署／安裝順序錯誤都會讓它靜默消失）。
    // 窗口以 byte 計，而中文註解每字 3 bytes——窗口過小會截在註解裡，讓實際呼叫落在窗外。
    $guard_fn = substr($src, (int) strpos($src, 'private function is_shipping_allowed_for_cart'), 1800);
    $read_fn  = substr($src, (int) strpos($src, 'private static function read_cart_items'), 3500);
    $assert(
        // 述詞缺席分支必須回 false（不得 return true）
        1 === preg_match('/is_method_allowed_for_cart\'\s*\)\s*\)\s*\{\s*return\s+false\s*;/', $guard_fn)
        // typed 讀取 API 缺席分支必須回 null
        && 1 === preg_match('/try_get_items_raw\'\s*\)\s*\)\s*\{\s*return\s+null\s*;/', $read_fn)
        // 實際呼叫一律走 typed API；不得殘留舊 API 的呼叫形式
        //（負向斷言需排除 try_ 前綴，否則 'get_items_raw()' 會被 'try_get_items_raw()' 命中；
        //  且只比對「呼叫」而非註解提及，避免說明文字影響契約）
        && 1 === preg_match('/->\s*try_get_items_raw\(\s*\)/', $read_fn)
        && 0 === preg_match('/->\s*(?<!try_)get_items_raw\(\s*\)/', $read_fn),
        '(i) 核心述詞缺席 → return false；typed 讀取 API 缺席 → return null；不得再使用舊 get_items_raw()'
    );

    // ══ #3V：付款方式守門 ═══════════════════════════════════════════════
    //
    // 🔴 「有帶 payment_method 這個 key」不等於「帶了一個有效的付款方式」。
    // 只檢查 key 存在的話，空字串、不存在的金流、以及「貨到付款 × 不支援代收的
    // 方式」三種都會過，而且全部被靜默當成 IsCollection=N——顧客選得到一個
    // 不支援代收的門市，結完帳，送單那天才被綠界拒絕。
    require_once dirname(__DIR__, 2) . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
    require_once dirname(__DIR__, 2) . '/src/Shipping/Ecpay/EcpayStoreSelector.php';
    $reject = $plugin_rc->getMethod('reject_invalid_payment_method');

    $error_code = static function ($response): string {
        if (! is_object($response)) {
            return '';
        }
        $data = (array) ($response->data ?? []);
        return (string) ($data['code'] ?? $data['error'] ?? '');
    };

    $assert(
        null !== $reject->invoke($plugin, '', 'ys_ec_ecpay_ship_family'),
        '(j) 空字串的付款方式被拒絕（空值不是「不代收」，是無法證明）'
    );

    $assert(
        null !== $reject->invoke($plugin, 'totally_unknown_gateway', 'ys_ec_ecpay_ship_family'),
        '(k) 未註冊的付款方式被拒絕（不得靜默當成非代收）'
    );

    $assert(
        null !== $reject->invoke($plugin, 'ys_ec_cod', 'ys_ec_ecpay_ship_unimart_freeze'),
        '(l) 貨到付款 × 不支援代收的物流方式被拒絕（不要開一張以「不代收」篩出來的地圖）'
    );

    $assert(
        null === $reject->invoke($plugin, 'ys_ec_ecpay_credit', 'ys_ec_ecpay_ship_family')
        && null === $reject->invoke($plugin, 'ys_ec_cod', 'ys_ec_ecpay_ship_family'),
        '(m) 合法組合放行（線上金流；以及貨到付款 × 支援代收的方式）'
    );

    // 核心註冊表缺席時 fail-closed：守門靜默消失比擋錯一次糟得多。
    $registry_missing = str_contains($src, "gateway_registry_unavailable");
    $assert(
        $registry_missing
        && 1 === preg_match('/method_exists\(\s*YSGatewayRegistry::class/', $src),
        '(n) 核心金流註冊表缺席時拒絕（不得因為驗不了就放行）'
    );

    echo "\nmap-url cart allowed intersection: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
