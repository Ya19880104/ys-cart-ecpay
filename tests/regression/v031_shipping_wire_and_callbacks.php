<?php
/**
 * 行為回歸：送單 wire 欄位 × 電子地圖 × 回呼綁定（v0.2.12）
 *
 * 這一支把**真的**送出去的欄位攔下來檢查——不是看原始碼裡有沒有某個字串，而是
 * 問「綠界最後會收到什麼」。
 *
 * 涵蓋 CODEX #2I Section 4 的：
 *   (4)  每個超商方式的地圖 subtype 正確、且與送單一致
 *   (5)  黑貓 0001/0002/0003 真的出現在 requester 欄位裡
 *   (7)  同一個物流方式：COD 訂單 IsCollection=Y、已付款訂單 IsCollection=N
 *   (8)  付款方式切換會讓不相符的門市選擇失效
 *   (10) 回呼缺 subtype／錯 subtype／錯 MerchantTradeNo 全部拒絕
 *   ＋ C2C 退貨門市上 wire、寄件碼缺漏 fail-closed、MerchantTradeNo 不含時間
 *   ＋ 中華郵政不送宅配條件欄位、且必送 GoodsWeight
 *
 * Run: php tests/regression/v031_shipping_wire_and_callbacks.php
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
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
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
    define('YS_CART_ECPAY_BASENAME', 'ys-cart-ecpay/ys-cart-ecpay.php');
    define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');
    define('MINUTE_IN_SECONDS', 60);

    /** wp_die() 的替身：把「這個請求被拒絕了」變成可以斷言的例外。 */
    final class WpDie extends \RuntimeException {
        public int $http_status;
        public function __construct(string $message, int $status) {
            parent::__construct($message);
            $this->http_status = $status;
        }
    }

    /** render/respond 的替身：在真的 exit 之前把控制權交回測試。 */
    final class Responded extends \RuntimeException {
        public int $http_status;
        public function __construct(int $status) {
            parent::__construct('responded');
            $this->http_status = $status;
        }
    }

    class WP_REST_Request {
        /** @param array<string,mixed> $params */
        public function __construct(private array $params = []) {}
        /** @return array<string,mixed> */
        public function get_params(): array { return $this->params; }
    }

    $GLOBALS['ys_transients']  = [];
    $GLOBALS['ys_last_post']   = null;
    $GLOBALS['ys_next_body']   = '';
    $GLOBALS['ys_filters']     = [];

    function add_filter(string $tag, callable $cb, int $priority = 10, int $args = 1): void {
        $GLOBALS['ys_filters'][$tag][$priority][] = $cb;
    }
    function remove_filter(string $tag, callable $cb, int $priority = 10): bool { return true; }
    function apply_filters(string $tag, $value, ...$args) { return $value; }

    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? ''; }
    function esc_url_raw(string $url): string { return $url; }
    function esc_url(string $url): string { return $url; }
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function home_url(string $path = ''): string { return 'https://example.test' . $path; }
    function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
    function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
    function wp_validate_redirect(string $url, string $fallback = ''): string { return $url ?: $fallback; }
    function add_query_arg(array $args, string $url): string {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
    function current_time(string $type) { return 'timestamp' === $type ? 1767225600 : '2026-08-12 10:00:00'; }
    function wp_generate_uuid4(): string { return '11111111-2222-3333-4444-555555555555'; }
    function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string {
        return substr(str_repeat('abcdefghij', 5), 0, $length);
    }
    function wp_rand(int $min = 0, int $max = 0): int { return $min; }
    function wp_is_mobile(): bool { return false; }
    function wp_strip_all_tags(string $text): string { return strip_tags($text); }
    function wp_json_encode($data, int $flags = 0) { return json_encode($data, $flags); }
    function get_current_user_id(): int { return 0; }
    function is_user_logged_in(): bool { return false; }
    function nocache_headers(): void {}
    // header_remove() 是 PHP 內建函式，不能重宣告——CLI 下呼叫它是無害的 no-op。
    function status_header(int $code): void { throw new Responded($code); }
    function wp_die(string $message, string $title = '', array $args = []): void {
        throw new WpDie($message, (int) ($args['response'] ?? 500));
    }
    function set_transient(string $key, $value, int $ttl = 0): bool {
        $GLOBALS['ys_transients'][$key] = $value;
        return true;
    }
    function get_transient(string $key) { return $GLOBALS['ys_transients'][$key] ?? false; }
    function delete_transient(string $key): bool { unset($GLOBALS['ys_transients'][$key]); return true; }

    function wp_remote_post(string $url, array $args = []) {
        $GLOBALS['ys_last_post'] = [ 'url' => $url, 'fields' => $args['body'] ?? [] ];
        return [ 'body' => $GLOBALS['ys_next_body'] ];
    }
    function is_wp_error($thing): bool { return false; }
    function wp_remote_retrieve_body($response): string { return (string) ($response['body'] ?? ''); }

    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Support/CheckMacValue.php';
    require_once $root . '/src/Support/HttpFormClient.php';
    require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
    foreach (glob($root . '/src/Shipping/Ecpay/EcpayShipping*.php') ?: [] as $file) {
        require_once $file;
    }
    require_once $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php';
    require_once $root . '/src/Support/Settings.php';

    use YangSheep\Ecommerce\YSEcommerce;
    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShipping;
    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester;
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

    /** 把所有方式都設定成「可用」：憑證齊全、開關打開、C2C 有退貨門市、郵局有重量。 */
    function reset_settings(): void {
        $settings = [
            'ys_ec_ecpay_enabled'                   => '1',
            'ys_ec_ecpay_logistics_test_mode'       => '1',
            'ys_ec_ecpay_logistics_merchant_id'     => MID,
            'ys_ec_ecpay_logistics_hash_key'        => HASH_KEY,
            'ys_ec_ecpay_logistics_hash_iv'         => HASH_IV,
            'shipping_ecpay_sender_name'            => '寄件人',
            'shipping_ecpay_sender_phone'           => '0912345678',
            'shipping_ecpay_sender_zipcode'         => '106',
            'shipping_ecpay_sender_address'         => '台北市大安區測試路1號',
        ];

        $n = 0;
        foreach (EcpayShippingCatalog::all() as $method_id => $descriptor) {
            $settings[$descriptor['enabled_option']] = '1';
            if ($descriptor['requires_return_store']) {
                $settings[$descriptor['return_store_option']] = 'RS' . str_pad((string) (++$n), 4, '0', STR_PAD_LEFT);
            }
            if ($descriptor['requires_goods_weight']) {
                $settings['shipping_' . $method_id . '_goods_weight'] = '1.500';
            }
        }

        YSEcommerce::$settings = $settings;
    }

    /** 產生一份可通過簽章驗證的建單回應。 */
    function canned_response(array $extra = []): string {
        $params = array_merge([
            'MerchantID'        => MID,
            'RtnCode'           => '1',
            'RtnMsg'            => 'OK',
            'AllPayLogisticsID' => '900000001',
            'BookingNote'       => 'BN123456',
        ], $extra);
        $params['CheckMacValue'] = CheckMacValue::generate($params, HASH_KEY, HASH_IV, 'md5');

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        return '1|' . implode('&', $pairs);
    }

    /**
     * 實際送一次建單，回傳 [送出去的欄位, requester 的回傳值]。
     *
     * @return array{0:array<string,string>,1:array<string,mixed>|\Throwable}
     */
    function send(string $method_id, array $order_data, array $response_extra = []): array {
        $descriptor = EcpayShippingCatalog::get($method_id);
        /** @var EcpayShipping $method */
        $method = new $descriptor['class']();

        $GLOBALS['ys_next_body'] = canned_response($response_extra);
        $GLOBALS['ys_last_post'] = null;

        $requester = new EcpayShippingRequester($method);
        try {
            $result = $requester->create_order($order_data);
        } catch (\Throwable $e) {
            return [ (array) ($GLOBALS['ys_last_post']['fields'] ?? []), $e ];
        }

        return [ (array) ($GLOBALS['ys_last_post']['fields'] ?? []), $result ];
    }

    function base_order(array $overrides = []): array {
        return array_merge([
            'order_number'      => 'YS20260812001',
            'product_name'      => '測試商品',
            'product_amount'    => 1000,
            'receiver_name'     => '收件人',
            'receiver_phone'    => '0987654321',
            'receiver_store_id' => '896539',
            'receiver_zipcode'  => '110',
            'receiver_address'  => '台北市信義區測試路2號',
            'payment_method'    => 'ys_ec_credit',
        ], $overrides);
    }

    echo "## v031 送單 wire × 電子地圖 × 回呼綁定（v0.2.12）\n";
    reset_settings();

    // ── (4) 每個超商方式的 subtype：地圖與送單必須是同一個 ─────────────────
    $subtype_ok = true;
    $map_ok     = true;
    foreach (EcpayShippingCatalog::all() as $method_id => $descriptor) {
        $order = base_order();
        if ('HOME' === $descriptor['logistics_type']) {
            unset($order['receiver_store_id']);
        }
        [$fields, $result] = send($method_id, $order, 'CVS' === $descriptor['logistics_type'] && $descriptor['requires_return_store']
            ? [ 'CVSPaymentNo' => 'CP123456', 'CVSValidationNo' => '4551' ]
            : []);

        if (($fields['LogisticsSubType'] ?? null) !== $descriptor['logistics_subtype']
            || ($fields['LogisticsType'] ?? null) !== $descriptor['logistics_type']) {
            $subtype_ok = false;
        }

        if ('CVS' !== $descriptor['logistics_type']) {
            continue;
        }

        $form = EcpayStoreSelector::build_map_form_data($method_id, 'checkout', 0, 'default', '', 'ys_ec_credit');
        if (!is_array($form)
            || ($form['fields']['LogisticsSubType'] ?? null) !== $descriptor['logistics_subtype']
            || ($form['fields']['IsCollection'] ?? null) !== ($fields['IsCollection'] ?? null)) {
            $map_ok = false;
        }
    }
    check('(4a) 每個方式送出的 LogisticsType／LogisticsSubType 都等於型錄值', $subtype_ok);
    check('(4b) 電子地圖與送單送出同一個 subtype 與同一個 IsCollection', $map_ok);

    // ── (5) 黑貓三個溫層真的出現在 wire 上 ────────────────────────────────
    [$tcat_room]    = send('ys_ec_ecpay_ship_tcat', base_order());
    [$tcat_chilled] = send('ys_ec_ecpay_ship_tcat_chilled', base_order());
    [$tcat_frozen]  = send('ys_ec_ecpay_ship_tcat_frozen', base_order());

    check(
        '(5a) 黑貓常溫／冷藏／冷凍分別送出 Temperature 0001／0002／0003',
        '0001' === ($tcat_room['Temperature'] ?? null)
            && '0002' === ($tcat_chilled['Temperature'] ?? null)
            && '0003' === ($tcat_frozen['Temperature'] ?? null)
    );

    check(
        '(5b) 黑貓三者的 LogisticsSubType 都是 TCAT（溫層才是區分）',
        'TCAT' === ($tcat_room['LogisticsSubType'] ?? null)
            && 'TCAT' === ($tcat_chilled['LogisticsSubType'] ?? null)
            && 'TCAT' === ($tcat_frozen['LogisticsSubType'] ?? null)
    );

    // 中華郵政：官方明載「請忽略」宅配條件欄位，且 GoodsWeight 必填。
    [$post_fields] = send('ys_ec_ecpay_ship_post', base_order());
    check(
        '(5c) 中華郵政不送 Temperature／Distance／Specification／ScheduledDeliveryTime',
        !isset($post_fields['Temperature'])
            && !isset($post_fields['Distance'])
            && !isset($post_fields['Specification'])
            && !isset($post_fields['ScheduledDeliveryTime'])
    );
    check(
        '(5d) 中華郵政送出 GoodsWeight（綠界必填）',
        isset($post_fields['GoodsWeight']) && (float) $post_fields['GoodsWeight'] > 0
    );

    YSEcommerce::$settings['shipping_ys_ec_ecpay_ship_post_goods_weight'] = '';
    [, $post_no_weight] = send('ys_ec_ecpay_ship_post', base_order());
    check(
        '(5e) 郵局缺重量時 fail-closed（中止建單，不猜一個重量）',
        $post_no_weight instanceof \Throwable
    );
    reset_settings();

    // ── (7) 代收由訂單決定，不是由物流方式的能力決定 ──────────────────────
    [$cod_fields]  = send('ys_ec_ecpay_ship_tcat', base_order([ 'payment_method' => 'ys_ec_cod' ]));
    [$paid_fields] = send('ys_ec_ecpay_ship_tcat', base_order([ 'payment_method' => 'ys_ec_ecpay_credit' ]));

    check(
        '(7a) 同一個物流方式：COD 訂單 IsCollection=Y、已付款訂單 IsCollection=N',
        'Y' === ($cod_fields['IsCollection'] ?? null) && 'N' === ($paid_fields['IsCollection'] ?? null)
    );

    check(
        '(7b) 代收時才帶 CollectionAmount，且金額等於 GoodsAmount',
        ($cod_fields['CollectionAmount'] ?? null) === ($cod_fields['GoodsAmount'] ?? 'x')
            && !isset($paid_fields['CollectionAmount'])
    );

    // 7-ELEVEN 家族依綠界規定無論代收與否都要帶 CollectionAmount。
    [$unimart_fields] = send('ys_ec_ecpay_ship_unimart', base_order());
    check(
        '(7c) UNIMART 即使不代收也帶 CollectionAmount（綠界載明必填）',
        ($unimart_fields['CollectionAmount'] ?? null) === ($unimart_fields['GoodsAmount'] ?? 'x')
            && 'N' === ($unimart_fields['IsCollection'] ?? null)
    );

    // 不支援代收的通路收到 COD 訂單＝矛盾，必須中止而不是「盡力而為」。
    [, $freeze_cod] = send('ys_ec_ecpay_ship_unimart_freeze', base_order([ 'payment_method' => 'ys_ec_cod' ]));
    check(
        '(7d) 不支援代收的通路遇到 COD 訂單直接中止',
        $freeze_cod instanceof \Throwable
    );

    // 缺 payment_method 不是「預設不代收」，是無法證明。
    $no_pm = base_order();
    unset($no_pm['payment_method']);
    [, $missing_pm] = send('ys_ec_ecpay_ship_tcat', $no_pm);
    check(
        '(7e) order_data 缺 payment_method 時中止（缺欄位＝無法證明，不是 false）',
        $missing_pm instanceof \Throwable
    );

    // ── C2C：退貨門市上 wire、缺值 fail-closed ────────────────────────────
    [$c2c_fields, $c2c_result] = send(
        'ys_ec_ecpay_ship_unimart_c2c',
        base_order(),
        [ 'CVSPaymentNo' => 'CP987654', 'CVSValidationNo' => '4551' ]
    );

    check(
        '(C2C-a) C2C 送出自己的 ReturnStoreID',
        ($c2c_fields['ReturnStoreID'] ?? '') === (string) YSEcommerce::$settings['ys_ec_ecpay_ship_unimart_c2c_return_store_id']
            && '' !== ($c2c_fields['ReturnStoreID'] ?? '')
    );

    check(
        '(C2C-b) 建單結果把寄貨編號與驗證碼分開回傳給核心落盤',
        is_array($c2c_result)
            && true === ($c2c_result['success'] ?? false)
            && 'CP987654' === ($c2c_result['cvs_payment_no'] ?? '')
            && '4551' === ($c2c_result['cvs_validation_no'] ?? '')
            && 'UNIMARTC2C' === ($c2c_result['logistics_subtype'] ?? '')
    );

    YSEcommerce::$settings['ys_ec_ecpay_ship_unimart_c2c_return_store_id'] = '';
    [, $c2c_no_return] = send('ys_ec_ecpay_ship_unimart_c2c', base_order(), [ 'CVSPaymentNo' => 'CP1', 'CVSValidationNo' => '1' ]);
    check('(C2C-c) 缺退貨門市時中止建單（不猜一個門市）', $c2c_no_return instanceof \Throwable);
    reset_settings();

    // 7-ELEVEN 交貨便少了驗證碼＝賣家寄不出去，必須當建單失敗。
    [, $c2c_missing_validation] = send('ys_ec_ecpay_ship_unimart_c2c', base_order(), [ 'CVSPaymentNo' => 'CP1' ]);
    check(
        '(C2C-d) 7-ELEVEN 交貨便回應缺驗證碼＝建單失敗（貨出不去）',
        is_array($c2c_missing_validation) && false === ($c2c_missing_validation['success'] ?? true)
    );

    // 全家只需要寄貨編號，不因為缺驗證碼而失敗。
    [, $fami_ok] = send('ys_ec_ecpay_ship_family_c2c', base_order(), [ 'CVSPaymentNo' => 'FM123' ]);
    check(
        '(C2C-e) 全家店到店只需寄貨編號（依 descriptor 判定，不是一刀切）',
        is_array($fami_ok) && true === ($fami_ok['success'] ?? false)
    );

    // 超商缺收件門市＝沒選店，直接中止。
    $no_store = base_order();
    $no_store['receiver_store_id'] = '';
    [, $no_store_result] = send('ys_ec_ecpay_ship_family', $no_store);
    check('(CVS-a) 缺收件門市代號時中止建單', $no_store_result instanceof \Throwable);

    // ── MerchantTradeNo 必須穩定（不含時間） ─────────────────────────────
    [$f1] = send('ys_ec_ecpay_ship_tcat', base_order());
    [$f2] = send('ys_ec_ecpay_ship_tcat', base_order());
    [$f3] = send('ys_ec_ecpay_ship_tcat', base_order([ 'label_attempt' => 2 ]));
    [$f4] = send('ys_ec_ecpay_ship_tcat_frozen', base_order());

    check(
        '(TN-a) 同一張訂單 × 同一方式 × 同一次建單 → MerchantTradeNo 完全相同（重試不會變成第二張單）',
        ($f1['MerchantTradeNo'] ?? 'a') === ($f2['MerchantTradeNo'] ?? 'b')
    );
    check(
        '(TN-b) 刻意重開一張（label_attempt 加一）才會換編號',
        ($f1['MerchantTradeNo'] ?? 'a') !== ($f3['MerchantTradeNo'] ?? 'a')
    );
    check(
        '(TN-c) 不同物流方式各自有自己的編號',
        ($f1['MerchantTradeNo'] ?? 'a') !== ($f4['MerchantTradeNo'] ?? 'a')
    );
    check(
        '(TN-d) MerchantTradeNo 長度不超過綠界上限 20',
        strlen((string) ($f1['MerchantTradeNo'] ?? '')) <= 20
    );

    // ── (8) 付款方式切換會讓舊的門市選擇失效 ──────────────────────────────
    $cod_form  = EcpayStoreSelector::build_map_form_data('ys_ec_ecpay_ship_family', 'checkout', 0, 'default', '', 'ys_ec_cod');
    $plain_form = EcpayStoreSelector::build_map_form_data('ys_ec_ecpay_ship_family', 'checkout', 0, 'default', '', 'ys_ec_credit');

    check(
        '(8a) 地圖的 IsCollection 由當下的付款方式決定',
        'Y' === ($cod_form['fields']['IsCollection'] ?? null)
            && 'N' === ($plain_form['fields']['IsCollection'] ?? null)
    );

    $selection_cod = [ 'shipping_id' => 'ys_ec_ecpay_ship_family', 'collection_mode' => 'Y' ];
    check(
        '(8b) 在「代收」下選的門市，改成線上付款後必須重選',
        true === EcpayStoreSelector::selection_requires_reselect($selection_cod, 'ys_ec_credit')
            && false === EcpayStoreSelector::selection_requires_reselect($selection_cod, 'ys_ec_cod')
    );

    check(
        '(8c) 舊的門市選擇沒有記錄代收前提時一律重選（無法證明相符）',
        true === EcpayStoreSelector::selection_requires_reselect(
            [ 'shipping_id' => 'ys_ec_ecpay_ship_family' ],
            'ys_ec_cod'
        )
    );

    // C2C 沒填退貨門市時，連地圖都不該開。
    YSEcommerce::$settings['ys_ec_ecpay_ship_hilife_c2c_return_store_id'] = '';
    check(
        '(8d) 未設定退貨門市的 C2C 不簽發電子地圖表單',
        false === EcpayStoreSelector::build_map_form_data('ys_ec_ecpay_ship_hilife_c2c', 'checkout', 0, 'default', '', 'ys_ec_credit')
    );
    reset_settings();

    // ── (10) 選店回呼的綁定：缺欄位也拒絕 ─────────────────────────────────
    /** 建立一次地圖 session，並回傳 (temp_id, session)。 */
    function open_map_session(string $method_id, string $payment_method = 'ys_ec_credit'): array {
        $GLOBALS['ys_transients'] = [];
        $form = EcpayStoreSelector::build_map_form_data($method_id, 'checkout', 0, 'default', '', $payment_method);
        $temp = (string) $form['temp_id'];
        return [ $temp, $GLOBALS['ys_transients']['ys_ec_ecpay_map_' . $temp] ];
    }

    function callback_status(array $params): int {
        try {
            EcpayStoreSelector::handle_store_callback(new WP_REST_Request($params));
        } catch (WpDie $e) {
            return $e->http_status;
        } catch (Responded $e) {
            return $e->http_status;
        }

        return 0;
    }

    function signed_callback(array $params): array {
        $params['CheckMacValue'] = CheckMacValue::generate($params, HASH_KEY, HASH_IV, 'md5');
        return $params;
    }

    [$temp, $session] = open_map_session('ys_ec_ecpay_ship_family');
    $good = signed_callback([
        'MerchantID'       => MID,
        'MerchantTradeNo'  => $session['merchant_trade_no'],
        'LogisticsSubType' => 'FAMI',
        'CVSStoreID'       => '001234',
        'CVSStoreName'     => '測試門市',
        'CVSAddress'       => '台北市中正區測試路 1 號',
        'ExtraData'        => $temp,
    ]);
    check('(10a) 綁定齊全的回呼被接受（走到輸出頁）', 200 === callback_status($good));

    check(
        '(10a2) 接受時把代收前提一起寫進門市選擇（供前端判斷是否需要重選）',
        'N' === (string) ($GLOBALS['ys_transients']['ys_ec_ecpay_store_' . $temp]['collection_mode'] ?? '')
    );

    [$temp, $session] = open_map_session('ys_ec_ecpay_ship_family');
    $no_subtype = $good;
    unset($no_subtype['LogisticsSubType'], $no_subtype['CheckMacValue']);
    $no_subtype['MerchantTradeNo'] = $session['merchant_trade_no'];
    $no_subtype['ExtraData']       = $temp;
    check('(10b) 缺 LogisticsSubType 的回呼被拒絕（不是「有傳才比」）', 400 === callback_status(signed_callback($no_subtype)));

    [$temp, $session] = open_map_session('ys_ec_ecpay_ship_family');
    $wrong_subtype = signed_callback([
        'MerchantID'       => MID,
        'MerchantTradeNo'  => $session['merchant_trade_no'],
        // B2C 的 session 收到 C2C 的 subtype——這正是跨通路誤配。
        'LogisticsSubType' => 'FAMIC2C',
        'CVSStoreID'       => '001234',
        'ExtraData'        => $temp,
    ]);
    check('(10c) subtype 不符的回呼被拒絕（B2C/C2C 不得互相認領）', 400 === callback_status($wrong_subtype));

    [$temp, $session] = open_map_session('ys_ec_ecpay_ship_family');
    $wrong_trade_no = signed_callback([
        'MerchantID'       => MID,
        'MerchantTradeNo'  => 'YSMAPTAMPERED',
        'LogisticsSubType' => 'FAMI',
        'CVSStoreID'       => '001234',
        'ExtraData'        => $temp,
    ]);
    check('(10d) MerchantTradeNo 不符的回呼被拒絕', 400 === callback_status($wrong_trade_no));

    [$temp, $session] = open_map_session('ys_ec_ecpay_ship_family');
    $no_trade_no = signed_callback([
        'MerchantID'       => MID,
        'LogisticsSubType' => 'FAMI',
        'CVSStoreID'       => '001234',
        'ExtraData'        => $temp,
    ]);
    check('(10e) 缺 MerchantTradeNo 的回呼被拒絕', 400 === callback_status($no_trade_no));

    [$temp, $session] = open_map_session('ys_ec_ecpay_ship_family');
    $no_store = signed_callback([
        'MerchantID'       => MID,
        'MerchantTradeNo'  => $session['merchant_trade_no'],
        'LogisticsSubType' => 'FAMI',
        'CVSStoreID'       => '',
        'ExtraData'        => $temp,
    ]);
    check('(10f) 沒有門市代號的回呼被拒絕（那不是一次有效的選店）', 400 === callback_status($no_store));

    echo "\nREGRESSION v031_shipping_wire_and_callbacks PASS={$pass} FAIL={$fail}\n";
    if ($fail > 0) {
        echo "Failed:\n";
        foreach ($bad as $name) { echo "  - {$name}\n"; }
        exit(1);
    }
}
