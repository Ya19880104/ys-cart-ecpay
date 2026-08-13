<?php
/**
 * 行為回歸：門市選擇的伺服器端綁定（v0.2.12，CODEX #3T-D）
 *
 * 缺口：門市選擇整包放在 localStorage、由結帳表單送回來，其中包含「這次選店是在
 * 哪一種代收前提下做的」。那是一個**可竄改的欄位**，等於沒有守門；而
 * `selection_requires_reselect()` 是給前端用的提示，沒有任何 runtime caller。
 *
 * 現在：選店回呼發出一張不透明的 token，權威資料（擁有者、購物車 scope、物流
 * 方式、subtype、門市、代收前提）全部留在伺服器。結帳時由伺服器消耗並逐項比對，
 * 任何一項對不上就要求重選；token 一次性。
 *
 * Run: php tests/regression/v033_store_selection_token.php
 */

declare(strict_types=1);

namespace YangSheep\Ecommerce\Shipping {
    interface YSShippingInterface {
        public function get_id(): string;
        public function get_title(): string;
        public function get_provider(): string;
        public function get_type(): string;
        public function is_enabled(): bool;
        public function is_available( array $order_data ): bool;
        public function calculate_cost( array $cart_items, array $address = [] ): float;
        public function get_free_threshold(): float;
        public function supports_cvs_selection(): bool;
        public function supports_cod(): bool;
        public function get_settings_fields(): array;
        public function get_supported_countries(): array;
    }
}

namespace YangSheep\Ecommerce {
    final class YSEcommerce {
        /** @var array<string,mixed> */
        public static array $settings = [];
        private static ?self $instance = null;

        public static function get_instance(): self {
            return self::$instance ??= new self();
        }

        public function get_setting(string $key, mixed $default = ''): mixed {
            return self::$settings[$key] ?? $default;
        }

        public function update_setting(string $key, mixed $value): bool {
            self::$settings[$key] = $value;
            return true;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_CART_ECPAY_DIR', dirname(__DIR__, 2) . '/');
    define('YS_CART_ECPAY_VERSION', '0.2.12');
    define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');
    define('MINUTE_IN_SECONDS', 60);

    final class Responded extends \RuntimeException {
        public function __construct(public int $http_status) { parent::__construct('responded'); }
    }
    final class WpDie extends \RuntimeException {
        public function __construct(string $m, public int $http_status) { parent::__construct($m); }
    }

    class WP_REST_Request {
        public function __construct(private array $params = []) {}
        public function get_params(): array { return $this->params; }
    }

    $GLOBALS['ys_transients'] = [];
    $GLOBALS['ys_user_id']    = 0;
    $GLOBALS['ys_token_seq']  = 0;
    $GLOBALS['ys_transient_snapshot'] = [];
    // 訪客的身分來源：購物車 session cookie。正常結帳一定有它。
    $_COOKIE['ys_ec_session'] = 'guest-session-aaa';

    function add_filter(string $t, callable $c, int $p = 10, int $a = 1): void {}
    function remove_filter(string $t, callable $c, int $p = 10): bool { return true; }
    function apply_filters(string $t, $v, ...$a) { return $v; }
    function sanitize_text_field(string $v): string { return trim(strip_tags($v)); }
    function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
    function sanitize_key(string $k): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)) ?? ''; }
    function esc_url_raw(string $u): string { return $u; }
    function esc_url(string $u): string { return $u; }
    function esc_attr(string $v): string { return htmlspecialchars($v, ENT_QUOTES); }
    function home_url(string $p = ''): string { return 'https://example.test' . $p; }
    function admin_url(string $p = ''): string { return 'https://example.test/wp-admin/' . ltrim($p, '/'); }
    function rest_url(string $p = ''): string { return 'https://example.test/wp-json/' . ltrim($p, '/'); }
    function wp_validate_redirect(string $u, string $f = ''): string { return $u ?: $f; }
    function add_query_arg(array $a, string $u): string { return $u . '?' . http_build_query($a); }
    function current_time(string $t) { return 'timestamp' === $t ? 1767225600 : '2026-08-12 10:00:00'; }
    function wp_generate_uuid4(): string { return '11111111-2222-3333-4444-555555555555'; }
    function wp_generate_password(int $len = 12, bool $s = true, bool $e = false): string {
        // 每次都不同，才驗得出「token 是一次性的、且不可預測」。
        return substr(str_repeat('t' . (++$GLOBALS['ys_token_seq']) . 'abcdefghij', 8), 0, $len);
    }
    function wp_is_mobile(): bool { return false; }
    function wp_strip_all_tags(string $t): string { return strip_tags($t); }
    function wp_json_encode($d, int $f = 0) { return json_encode($d, $f); }
    function get_current_user_id(): int { return $GLOBALS['ys_user_id']; }
    function is_user_logged_in(): bool { return $GLOBALS['ys_user_id'] > 0; }
    function nocache_headers(): void {}
    function status_header(int $c): void { throw new Responded($c); }
    function wp_die(string $m, string $t = '', array $a = []): void { throw new WpDie($m, (int) ($a['response'] ?? 500)); }
    function set_transient(string $k, $v, int $ttl = 0): bool { $GLOBALS['ys_transients'][$k] = $v; return true; }
    function get_transient(string $k) {
        // 🔴 模擬併發：`ys_transient_snapshot` 打開時，即使記錄已被另一個 process
        // 刪掉，這個 process 仍讀得到——那正是「兩邊都先讀完才有人刪」的競態。
        if (!empty($GLOBALS['ys_transient_snapshot']) && isset($GLOBALS['ys_transient_snapshot'][$k])) {
            return $GLOBALS['ys_transient_snapshot'][$k];
        }
        return $GLOBALS['ys_transients'][$k] ?? false;
    }
    function delete_transient(string $k): bool {
        // 🔴 與 WP 一致：只有真的刪掉一列才回 true。一次性消耗就是靠這個認領。
        if (!array_key_exists($k, $GLOBALS['ys_transients'])) {
            return false;
        }
        unset($GLOBALS['ys_transients'][$k]);
        return true;
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Support/CheckMacValue.php';
    require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
    foreach (glob($root . '/src/Shipping/Ecpay/EcpayShipping*.php') ?: [] as $file) {
        require_once $file;
    }
    // 🔴 載入**核心正式的** YSRestAuth：headless 訪客的身分來源是它定義的
    // `X-YS-Guest-Token`。自己在測試裡另寫一份，就永遠測不出兩邊漂移。
    require_once dirname(__DIR__, 3) . '/ys-cart/src/Api/Storefront/YSRestAuth.php';

    /**
     * 🔴 #3X：選店結果改存成**一次性提領證**（`ys_ec_ecpay_result_<code>`），
     * 不再以 temp_id 落在一個沒有讀取端的 transient。測試跟著契約走。
     *
     * @return array<string,mixed>
     */
    function latest_selection(): array {
        foreach ( $GLOBALS['ys_transients'] as $k => $v ) {
            if ( 0 === strpos( (string) $k, 'ys_ec_ecpay_result_' ) ) {
                return (array) ( ( (array) $v )['store'] ?? [] );
            }
        }
        return [];
    }

    require_once $root . '/src/Shipping/Ecpay/EcpayStoreDirectory.php';
    require_once $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php';
    require_once $root . '/src/Support/Settings.php';

    use YangSheep\Ecommerce\YSEcommerce;
    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;
    use YangSheep\YSCartEcpay\Support\CheckMacValue;

    const HASH_KEY = 'ys-cart-test-hash-key';
    const HASH_IV  = 'ys-cart-test-hashiv';
    const MID      = '2000132';

    $pass = 0;
    $fail = 0;
    $bad  = [];

    function check(string $label, bool $ok): void {
        global $pass, $fail, $bad;
        if ($ok) { echo "  PASS  {$label}\n"; $pass++; return; }
        echo "  FAIL  {$label}\n"; $fail++; $bad[] = $label;
    }

    YSEcommerce::$settings = [
        'ys_ec_ecpay_enabled'                   => '1',
        'ys_ec_ecpay_logistics_test_mode'       => '1',
        'ys_ec_ecpay_logistics_merchant_id'     => MID,
        'ys_ec_ecpay_logistics_hash_key'        => HASH_KEY,
        'ys_ec_ecpay_logistics_hash_iv'         => HASH_IV,
        'ys_ec_ecpay_ship_family_enabled'       => '1',
        'ys_ec_ecpay_ship_unimart_enabled'      => '1',
        'ys_ec_ecpay_ship_unimart_c2c_enabled'  => '1',
    ];

    /**
     * 跑一次完整的「開地圖 → 綠界回呼」，回傳伺服器發出的 token。
     */
    function select_store(string $method_id, string $payment_method, string $store_id = '001234', string $scope = 'default'): string {
        $GLOBALS['ys_transients'] = [];
        $form = EcpayStoreSelector::build_map_form_data($method_id, 'checkout', 0, $scope, '', $payment_method);
        if (!is_array($form)) {
            return '';
        }
        $temp    = (string) $form['temp_id'];
        $session = $GLOBALS['ys_transients']['ys_ec_ecpay_map_' . $temp];

        $params = [
            'MerchantID'       => MID,
            'MerchantTradeNo'  => $session['merchant_trade_no'],
            'LogisticsSubType' => $session['logistics_subtype'],
            'CVSStoreID'       => $store_id,
            'CVSStoreName'     => '測試門市',
            'CVSAddress'       => '台北市中正區測試路 1 號',
            'ExtraData'        => $temp,
        ];
        $params['CheckMacValue'] = CheckMacValue::generate($params, HASH_KEY, HASH_IV, 'md5');

        // 🔴 綠界的選店回呼是**跨站 POST**：購物車 cookie（SameSite=Lax）不會帶。
        // 每一次選店都如實模擬這件事，否則整份測試都跑在一個現實中不存在的前提上。
        $cookies = $_COOKIE;
        $user    = $GLOBALS['ys_user_id'] ?? 0;
        $_COOKIE = [];
        $GLOBALS['ys_user_id'] = 0;

        try {
            EcpayStoreSelector::handle_store_callback(new WP_REST_Request($params));
        } catch (Responded $e) {
            // 200＝走到輸出頁，選店成功。
        } catch (WpDie $e) {
            $_COOKIE = $cookies;
            $GLOBALS['ys_user_id'] = $user;
            return '';
        }

        $_COOKIE = $cookies;
        $GLOBALS['ys_user_id'] = $user;

        return (string) ( latest_selection()['selection_token'] ?? '' );
    }

    /** 結帳欄位驗證那一關：**只讀不消耗**。 */
    function verify(string $token, string $method_id, string $payment_method, string $store_id = '001234', string $scope = 'default'): ?string {
        return EcpayStoreSelector::verify_selection(
            [ 'ecpay_store_token' => $token, 'cvs_store_id' => $store_id, 'cart_scope' => $scope ],
            $method_id,
            $payment_method
        );
    }

    /** 訂單成立那一刻的認領：原子、一次性。 */
    function checkout(string $token, string $method_id, string $payment_method, string $store_id = '001234', string $scope = 'default'): ?string {
        return EcpayStoreSelector::claim_selection(
            [ 'ecpay_store_token' => $token, 'cvs_store_id' => $store_id, 'cart_scope' => $scope ],
            $method_id,
            $payment_method
        );
    }

    echo "## v033 門市選擇的伺服器端綁定（v0.2.12）\n";

    // ── token 發放 ────────────────────────────────────────────────────────
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    check('(a) 選店成功時伺服器發出 token', '' !== $token);

    $record = $GLOBALS['ys_transients']['ys_ec_ecpay_sel_' . $token] ?? null;
    check(
        '(b) 權威資料留在伺服器（門市、subtype、代收前提、購物車 scope、擁有者）',
        is_array($record)
            && '001234' === ($record['store_id'] ?? '')
            && 'FAMI' === ($record['logistics_subtype'] ?? '')
            && 'N' === ($record['collection_mode'] ?? '')
            && 'default' === ($record['cart_scope'] ?? '')
            && 'ys_ec_credit' === ($record['payment_method'] ?? '')
            && '' !== (string) ($record['principal'] ?? '')
    );

    // ── happy path 與一次性 ───────────────────────────────────────────────
    check('(c) 相符的結帳通過', null === checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    check(
        '(d) token 是一次性的：同一張不能重複結帳',
        null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit')
    );

    // ── 付款方式切換：N→Y 與 Y→N 都必須重選 ──────────────────────────────
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    check(
        '(e) N→Y：在「不代收」下選的門市，改成貨到付款後被拒絕',
        null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_cod')
    );

    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_cod');
    check(
        '(f) Y→N：在「代收」下選的門市，改成線上付款後被拒絕',
        null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit')
    );

    // ── 竄改 ──────────────────────────────────────────────────────────────
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    check('(g) 偽造的 token 被拒絕', null !== checkout('totally-made-up-token', 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    check('(h) 缺 token 被拒絕', null !== checkout('', 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    check(
        '(i) 竄改門市代號被拒絕（伺服器認的是自己存的那一個）',
        null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit', '999999')
    );
    check(
        '(j) 換成另一個物流方式被拒絕（subtype 對不上）',
        null !== checkout($token, 'ys_ec_ecpay_ship_unimart', 'ys_ec_credit')
    );
    check(
        '(k) 換成另一個購物車 scope 被拒絕',
        null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit', '001234', 'wholesale')
    );

    // 竄改後上面幾次都沒有消耗掉 token，正確的那一次仍應通過。
    check('(l) 前面幾次被拒絕不會消耗 token；正確的結帳仍通過', null === checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));

    // ── 身分綁定（登入者與訪客都要綁） ────────────────────────────────────
    $GLOBALS['ys_user_id'] = 7;
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $GLOBALS['ys_user_id'] = 9;
    check('(m) 別的登入者不能用', null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    $GLOBALS['ys_user_id'] = 7;
    check('(n) 本人可以用', null === checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    $GLOBALS['ys_user_id'] = 0;

    // 🔴 訪客也要綁：換一個購物車 session 就不能用同一張 token。
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $_COOKIE['ys_ec_session'] = 'guest-session-bbb';
    check('(m2) 訪客：換一個購物車 session 之後不能用', null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    $_COOKIE['ys_ec_session'] = 'guest-session-aaa';
    check('(n2) 訪客：同一個購物車 session 可以用', null === checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));

    // 完全沒有身分（沒登入、沒購物車 session）＝證明不了是誰，一律拒絕。
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    unset($_COOKIE['ys_ec_session']);
    check('(m3) 沒有任何身分來源時拒絕（證明不了是誰）', null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    $_COOKIE['ys_ec_session'] = 'guest-session-aaa';

    // 🔴 精確付款方式：只比代收模式（N/Y）是不夠的。
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    check(
        '(m4) 換成另一個**同樣不代收**的金流也要重選（門市限制未必相同）',
        null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_ecpay_atm')
    );
    check('(n4) 同一個金流可以用', null === checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));

    // ── 兩個呼叫端都必須送出付款方式 ──────────────────────────────────────
    $native = (string) file_get_contents(dirname(__DIR__, 3) . '/ys-cart/assets/js/ys-ec-store-selector.js');
    $sdk    = (string) file_get_contents(dirname(__DIR__, 2) . '/sdk/ys-cart-ecpay-headless.js');

    check(
        '(o) 核心的選店 JS 會把顧客當下選定的付款方式送進 map-url',
        false !== strpos($native, 'payment_method: resolveSelectedPaymentMethod()')
            && false !== strpos($native, "querySelector('input[name=\"payment_method\"]:checked')")
    );

    check(
        '(p) 核心的選店 JS 會把 token 寫進結帳表單',
        false !== strpos($native, 'setStoreSelectionToken(storeData.selection_token')
            && false !== strpos($native, 'ys-ec-store-selection-token')
    );

    check(
        '(q) headless SDK 的 map-url 呼叫帶上 payment_method，並提供取 token 的入口',
        false !== strpos($sdk, 'payment_method:')
            && false !== strpos($sdk, 'selectionToken')
            && false !== strpos($sdk, "selectionTokenField: 'ecpay_store_token'")
    );

    // 🔴 缺付款方式時 SDK 自己就要擋，不要送一個空字串出去——那只是把一次必然
    // 失敗的往返推給伺服器，使用者看到的是一個沒頭沒尾的錯誤。
    check(
        '(q2) SDK 缺付款方式時就地 reject，不得送空字串',
        1 === preg_match('/paymentMethod\s*!==\s*.string.\s*\|\|\s*paymentMethod\s*===\s*..\s*\)\s*\{\s*return Promise\.reject/', $sdk)
            && 0 === preg_match("/payment_method:\s*typeof paymentMethod/", $sdk)
    );

    // headless 前端在另一個 origin 時沒有我方 cookie，訪客身分只能靠這個 header。
    check(
        '(q3) SDK 支援核心既有的 X-YS-Guest-Token 訪客身分',
        false !== strpos($sdk, 'X-YS-Guest-Token')
            && false !== strpos($sdk, 'setGuestToken')
    );

    // 🔴 headless 訪客的身分**只有** X-YS-Guest-Token。跑真的流程：拿掉購物車
    // cookie、改用 header，地圖要開得起來、憑證要能用；換一個 token 就不能用。
    $cookie_backup = $_COOKIE;
    $_COOKIE       = [];
    $_SERVER['HTTP_X_YS_GUEST_TOKEN'] = 'guesttokenaaaaaaaaaaaaaaaaaaaaaa';

    $headless_token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $headless_ok    = verify($headless_token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');

    $_SERVER['HTTP_X_YS_GUEST_TOKEN'] = 'guesttokenbbbbbbbbbbbbbbbbbbbbbb';
    $headless_other = verify($headless_token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');

    unset($_SERVER['HTTP_X_YS_GUEST_TOKEN']);
    $headless_none = EcpayStoreSelector::build_map_form_data('ys_ec_ecpay_ship_family', 'checkout', 0, 'default', '', 'ys_ec_credit');

    $_COOKIE = $cookie_backup;

    check(
        '(q4) headless 訪客以 X-YS-Guest-Token 開得了地圖、憑證可用，換 token 即失效',
        '' !== $headless_token
            && null === $headless_ok
            && null !== $headless_other
            && false === $headless_none
    );

    // 🔴 憑證裡的門市名稱與地址是**權威**的，不是裝飾。
    // 只比對門市代號的話，前端可以把名稱地址改成任何字串——而那兩個欄位會原樣
    // 出現在出貨單與通知信上。認領時要把伺服器那一份交出來。
    $token  = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $claim  = EcpayStoreSelector::claim_selection_authoritative(
        [
            'ecpay_store_token' => $token,
            'cvs_store_id'      => '001234',
            'cart_scope'        => 'default',
            // 前端把名稱與地址改掉了
            'cvs_store_name'    => '被竄改的門市',
            'cvs_store_addr'    => '不存在的地址',
        ],
        'ys_ec_ecpay_ship_family',
        'ys_ec_credit'
    );
    check(
        '(q6) 認領時交回伺服器保存的門市名稱與地址（不是前端送上來的）',
        null === $claim['error']
            && '001234' === ($claim['store']['cvs_store_id'] ?? '')
            && '測試門市' === ($claim['store']['cvs_store_name'] ?? '')
            && '台北市中正區測試路 1 號' === ($claim['store']['cvs_store_addr'] ?? '')
    );

    // 文件必須跟著契約走：payload、SDK 簽名、訪客身分、兩階段消耗。
    $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/headless.md');
    check(
        '(q7) headless 文件的結帳 endpoint 帶 -headless namespace，範例也帶訪客身分',
        false !== strpos($doc, 'ys-ecommerce-headless/v1/checkout/process')
            && 1 === preg_match("/checkout\/process.*?'X-YS-Guest-Token'/s", $doc)
    );

    check(
        '(q5) headless 文件已更新（payment_method、guest token、SDK 簽名、消耗時機）',
        false !== strpos($doc, '"payment_method"')
            && false !== strpos($doc, 'X-YS-Guest-Token')
            && false !== strpos($doc, 'requestStoreMapForm(apiBase, shippingId, paymentMethod, options?)')
            && 1 === preg_match('/at the moment the order is created\*\*\s*—\s*not during\s+field validation/', $doc)
            && false !== strpos($doc, 'logistics-notify')
    );

    // 🔴 併發：兩次結帳同時通過驗證、同時走到消耗那一步。
    // 「先 get 再 delete」的寫法會讓兩邊都過；以 delete 的回傳值認領才擋得住。
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    // 兩個 process 都已經讀到記錄（快照），接著才各自嘗試消耗。
    $GLOBALS['ys_transient_snapshot'] = [
        'ys_ec_ecpay_sel_' . $token => $GLOBALS['ys_transients']['ys_ec_ecpay_sel_' . $token],
    ];
    $first  = checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $second = checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $GLOBALS['ys_transient_snapshot'] = [];
    check(
        '(s) 兩次併發結帳（兩邊都已讀到記錄）只有一次能消耗同一張憑證',
        null === $first && null !== $second
    );

    $template = (string) file_get_contents(dirname(__DIR__, 3) . '/ys-cart/templates/checkout/form-checkout.php');
    check(
        '(r) 結帳表單有 ecpay_store_token 欄位（沒有它，伺服器永遠收不到憑證）',
        false !== strpos($template, 'name="ecpay_store_token"')
            && false !== strpos($template, 'id="ys-ec-store-selection-token"')
    );

    // ══ #3V：actor provenance 與兩階段消耗 ═══════════════════════════════

    // 🔴 回呼不帶 cookie（跨站 POST）是**正常流程**，不是攻擊。
    // 憑證的擁有者必須是開地圖那一刻算好的那一個，不能在回呼裡重算。
    $token  = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $record = $GLOBALS['ys_transients']['ys_ec_ecpay_sel_' . $token] ?? [];
    check(
        '(t) 不帶 cookie 的回呼仍簽出有擁有者的憑證，且回站後結帳通過',
        '' !== $token
            && 's:' . hash('sha256', 'guest-session-aaa') === (string) ($record['principal'] ?? '')
            && null === verify($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit')
    );

    // map session 沒有 actor（不是本版寫的）＝拒絕，不要簽出不能用的憑證。
    $GLOBALS['ys_transients'] = [];
    $form = EcpayStoreSelector::build_map_form_data('ys_ec_ecpay_ship_family', 'checkout', 0, 'default', '', 'ys_ec_credit');
    $temp = (string) $form['temp_id'];
    unset($GLOBALS['ys_transients']['ys_ec_ecpay_map_' . $temp]['actor']);
    $session = $GLOBALS['ys_transients']['ys_ec_ecpay_map_' . $temp];
    $params  = [
        'MerchantID'       => MID,
        'MerchantTradeNo'  => $session['merchant_trade_no'],
        'LogisticsSubType' => $session['logistics_subtype'],
        'CVSStoreID'       => '001234',
        'CVSStoreName'     => '測試門市',
        'CVSAddress'       => '台北市中正區測試路 1 號',
        'ExtraData'        => $temp,
    ];
    $params['CheckMacValue'] = CheckMacValue::generate($params, HASH_KEY, HASH_IV, 'md5');
    $rejected = false;
    try {
        EcpayStoreSelector::handle_store_callback(new WP_REST_Request($params));
    } catch (WpDie $e) {
        $rejected = true;
    } catch (Responded $e) {
        $rejected = false;
    }
    check('(u) map session 沒有 actor 時回呼被拒絕（不簽出不能用的憑證）', $rejected);

    // 算不出身分時連地圖都不開。
    $saved_cookie = $_COOKIE['ys_ec_session'] ?? '';
    unset($_COOKIE['ys_ec_session']);
    check(
        '(v) 算不出顧客身分時不開電子地圖',
        false === EcpayStoreSelector::build_map_form_data('ys_ec_ecpay_ship_family', 'checkout', 0, 'default', '', 'ys_ec_credit')
    );
    $_COOKIE['ys_ec_session'] = $saved_cookie;

    // 🔴 兩階段：驗證只讀不刪，認領才是一次性。
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $v1 = verify($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $v2 = verify($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');
    $v3 = verify($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');
    check(
        '(w) 驗證可以重複進行而不消耗憑證（其他欄位錯誤時顧客不該被沒收門市）',
        null === $v1 && null === $v2 && null === $v3
    );

    check('(x) 認領一次成功', null === checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));
    check('(y) 認領之後驗證也失效', null !== verify($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit'));

    // 認領時會再驗一次：驗證通過之後才改付款方式，一樣要被擋。
    $token = select_store('ys_ec_ecpay_ship_family', 'ys_ec_credit');
    verify($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit');
    check(
        '(z) 認領前再驗一次：驗證後才換付款方式仍被擋，且憑證沒被用掉',
        null !== checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_cod')
            && null === checkout($token, 'ys_ec_ecpay_ship_family', 'ys_ec_credit')
    );

    echo "\nREGRESSION v033_store_selection_token PASS={$pass} FAIL={$fail}\n";
    if ($fail > 0) {
        echo "Failed:\n";
        foreach ($bad as $name) { echo "  - {$name}\n"; }
        exit(1);
    }
}
