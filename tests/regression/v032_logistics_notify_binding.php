<?php
/**
 * 行為回歸：物流狀態通知必須綁定到**具體那一張**物流單（v0.2.12）
 *
 * 缺口：舊版只用一個 `AllPayLogisticsID` 認人，`MerchantTradeNo` 與
 * `LogisticsSubType` 寫成「有傳才比」——**不送那個欄位就自動通過**。B2C 與 C2C 是
 * 兩份不同的合約、兩組不同的編號空間，這樣認人遲早會把狀態寫到別人的訂單上。
 *
 * 另外舊版以 (order_id, provider_trade_no) 當更新條件，同一張訂單有多張物流單時
 * 可能一次改到不只一列。
 *
 * Run: php tests/regression/v032_logistics_notify_binding.php
 */

declare(strict_types=1);

namespace YangSheep\Ecommerce\Models {
    final class YSOrder {
        /** @var array<int,array<string,mixed>> */
        public static array $updates = [];

        /**
         * 🔴 production 的 YSOrder::update() 對 affected=0 也回 true。
         * `$GLOBALS['ys_order_silent_write']` 就是在模擬那個情境：回 true，
         * 但資料**沒有真的改變**。ACK 之前必須讀回來才抓得到。
         */
        public static function update( int $id, array $data ): bool {
            self::$updates[] = [ 'id' => $id ] + $data;
            if ( ! empty( $GLOBALS['ys_order_silent_write'] ) ) {
                return true;
            }
            if ( isset( $GLOBALS['wpdb'] ) && null !== $GLOBALS['wpdb']->order ) {
                foreach ( $data as $k => $v ) {
                    $GLOBALS['wpdb']->order->{$k} = $v;
                }
            }
            return true;
        }
    }
}

namespace YangSheep\Ecommerce\Security {
    final class YSInboundPermission {
        public static function build( string $slug, array $args ): callable {
            return static fn ( $request ): bool => true;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_CART_ECPAY_DIR', dirname(__DIR__, 2) . '/');
    define('YS_CART_ECPAY_VERSION', '0.2.12');
    define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');

    final class Responded extends \RuntimeException {
        public function __construct(public int $http_status) { parent::__construct('responded'); }
    }

    class WP_REST_Request {
        public function __construct(private array $params = []) {}
        public function get_params(): array { return $this->params; }
    }

    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
    function current_time(string $type) { return 'mysql' === $type ? '2026-08-12 10:00:00' : 1767225600; }
    function wp_json_encode($data, int $flags = 0) { return json_encode($data, $flags); }
    function status_header(int $code): void { throw new Responded($code); }
    function register_rest_route(...$args): void {}

    final class FakeWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public ?object $label = null;
        public ?object $order = null;
        /** 讀 label 時要不要模擬資料庫失敗。 */
        public bool $read_fails = false;
        /** 寫 label 時要不要模擬資料庫失敗。 */
        public bool $write_fails = false;
        /** 授權表裡有沒有這個 MerchantTradeNo（＝這一單是不是我們送出去的）。 */
        public bool $dispatch_attempt_exists = false;
        /** 建單授權所屬訂單。 */
        public int $attempt_order_id = 42;
        /** 讀授權表時要不要模擬資料庫失敗。 */
        public bool $attempt_read_fails = false;
        /** 授權表存不存在（舊核心）。 */
        public bool $attempts_table_exists = true;
        /** 連 SHOW TABLES 都查不動（連線斷了、權限被撤）。 */
        public bool $show_tables_fails = false;
        /** readback 時 label 的追蹤碼（sync_label 會比對）。 */
        public string $readback_tracking = '';
        /** 模擬 update 回成功、但指定欄位沒有真的寫進 label。 */
        public array $readback_omit = [];
        /** @var array<int,array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
        public array $updates = [];

        public function prepare(string $sql, ...$args): string {
            foreach ($args as $arg) {
                $sql = preg_replace('/%[dsf]/', is_string($arg) ? "'" . $arg . "'" : (string) $arg, $sql, 1);
            }
            return $sql;
        }

        public function get_var(string $sql) {
            $this->last_error = '';

            if (false !== strpos($sql, 'SHOW TABLES LIKE')) {
                if ($this->show_tables_fails) {
                    $this->last_error = 'MySQL server has gone away';
                    return null;
                }
                preg_match("/LIKE '([^']*)'/", $sql, $m);
                $table = $m[1] ?? '';
                if (false !== strpos($table, 'shipping_label_attempts') && !$this->attempts_table_exists) {
                    return null;
                }
                return $table;
            }

            if (false !== strpos($sql, 'shipping_label_attempts')) {
                if ($this->attempt_read_fails) {
                    $this->last_error = 'MySQL server has gone away';
                    return null;
                }
                return $this->dispatch_attempt_exists ? (string) $this->attempt_order_id : null;
            }

            if (false !== strpos($sql, 'shipping_labels') && false !== strpos($sql, 'SELECT id')) {
                preg_match("/provider_trade_no = '([^']+)'/", $sql, $m);
                return $this->label && (string) ($this->label->provider_trade_no ?? '') === (string) ($m[1] ?? '')
                    ? (string) ($this->label->id ?? 0)
                    : null;
            }

            return null;
        }

        public function get_row(string $sql) {
            $this->last_error = '';
            if (false !== strpos($sql, 'shipping_labels')) {
                if ($this->read_fails) {
                    $this->last_error = 'MySQL server has gone away';
                    return null;
                }
                // sync_label() 的 readback：回傳最後一次真正寫進去的所有欄位。
                if (false === strpos($sql, 'SELECT *')) {
                    $last = end($this->updates);
                    if (!$last) {
                        return null;
                    }
                    $row = (array) ($this->label ?? (object) []);
                    foreach ((array) $last['data'] as $column => $value) {
                        if (!in_array($column, $this->readback_omit, true)) {
                            $row[$column] = $value;
                        }
                    }
                    $row['tracking_number'] = $row['tracking_number'] ?? $this->readback_tracking;
                    return (object) $row;
                }

                preg_match("/provider_trade_no = '([^']+)'/", $sql, $provider_match);
                preg_match('/order_id = (\d+)/', $sql, $order_match);
                preg_match("/merchant_trade_no = '([^']+)'/", $sql, $merchant_match);
                if (!$this->label
                    || (string) ($this->label->provider_trade_no ?? '') !== (string) ($provider_match[1] ?? '')
                    || (int) ($this->label->order_id ?? 0) !== (int) ($order_match[1] ?? 0)
                    || (string) ($this->label->merchant_trade_no ?? '') !== (string) ($merchant_match[1] ?? '')) {
                    return null;
                }
                return $this->label;
            }
            if (false !== strpos($sql, 'orders')) {
                return $this->order;
            }
            return null;
        }

        public function update(string $table, array $data, array $where) {
            if ($this->write_fails) {
                $this->last_error = 'Lock wait timeout';
                return false;
            }
            $this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
            return 1;
        }
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Support/CheckMacValue.php';
    require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
    require_once $root . '/src/Support/Settings.php';
    require_once $root . '/src/Api/EcpayLogisticsController.php';

    // Settings 需要一個設定來源。
    if (!class_exists('YangSheep\\Ecommerce\\YSEcommerce')) {
        eval('namespace YangSheep\\Ecommerce; final class YSEcommerce {
            public static array $settings = [];
            private static ?self $i = null;
            public static function get_instance(): self { return self::$i ??= new self(); }
            public function get_setting(string $k, mixed $d = "") : mixed { return self::$settings[$k] ?? $d; }
            public function update_setting(string $k, mixed $v): bool { self::$settings[$k] = $v; return true; }
        }');
    }

    use YangSheep\Ecommerce\Models\YSOrder;
    use YangSheep\Ecommerce\YSEcommerce;
    use YangSheep\YSCartEcpay\Api\EcpayLogisticsController;
    use YangSheep\YSCartEcpay\Support\CheckMacValue;

    const HASH_KEY = 'ys-cart-test-hash-key';
    const HASH_IV  = 'ys-cart-test-hashiv';
    const MID      = '2000132';

    YSEcommerce::$settings = [
        'ys_ec_ecpay_enabled'               => '1',
        'ys_ec_ecpay_logistics_test_mode'   => '1',
        'ys_ec_ecpay_logistics_merchant_id' => MID,
        'ys_ec_ecpay_logistics_hash_key'    => HASH_KEY,
        'ys_ec_ecpay_logistics_hash_iv'     => HASH_IV,
    ];

    $pass = 0;
    $fail = 0;
    $bad  = [];

    function check(string $label, bool $ok): void {
        global $pass, $fail, $bad;
        if ($ok) { echo "  PASS  {$label}\n"; $pass++; return; }
        echo "  FAIL  {$label}\n"; $fail++; $bad[] = $label;
    }

    /** 這張訂單上真實存在的那一張 C2C 物流單。 */
    function seed_label(array $overrides = []): FakeWpdb {
        $wpdb = new FakeWpdb();
        $wpdb->label = (object) array_merge([
            'id'                => 7,
            'order_id'          => 42,
            'provider'          => 'ecpay',
            'shipping_method'   => 'ys_ec_ecpay_ship_unimart_c2c',
            'logistics_subtype' => 'UNIMARTC2C',
            'merchant_trade_no' => 'YS202608L9AF31',
            'provider_trade_no' => '900000001',
            'cvs_payment_no'    => '',
            'validation_no'     => '',
        ], $overrides);
        $wpdb->order = (object) [ 'id' => 42, 'payment_detail' => '{}', 'tracking_number' => '' ];
        $wpdb->dispatch_attempt_exists = true;
        $wpdb->attempt_order_id = 42;
        $GLOBALS['wpdb'] = $wpdb;
        YSOrder::$updates = [];
        return $wpdb;
    }

    function notify(array $params): int {
        $params['CheckMacValue'] = CheckMacValue::generate($params, HASH_KEY, HASH_IV, 'md5');
        $controller = new EcpayLogisticsController();
        try {
            $controller->notify(new WP_REST_Request($params));
        } catch (Responded $e) {
            return $e->http_status;
        }
        return 0;
    }

    function base_notify(array $overrides = []): array {
        return array_merge([
            'MerchantID'        => MID,
            'AllPayLogisticsID' => '900000001',
            'MerchantTradeNo'   => 'YS202608L9AF31',
            'LogisticsSubType'  => 'UNIMARTC2C',
            'LogisticsStatus'   => '300',
            'BookingNote'       => 'BN999',
        ], $overrides);
    }

    /**
     * pipeline 的替身。核心真的有這個類別時，狀態由它決定；因此「拒絕轉換」與
     * 「寫不進去」兩種失敗必須測得到——它們的正確反應完全不同。
     */
    if (!class_exists('YangSheep\\Ecommerce\\Services\\Shipping\\YSShippingPipelineService')) {
        eval('namespace YangSheep\\Ecommerce\\Services\\Shipping; final class YSShippingPipelineService {
            public static array $result = [ "success" => true, "persisted" => true ];
            public static int $calls = 0;
            public static function advance_from_carrier_status(int $o, string $s, string $r = ""): array {
                self::$calls++;
                return self::$result;
            }
        }');
    }

    function pipeline_reset(array $result = [ 'success' => true, 'persisted' => true ]): void {
        \YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService::$result = $result;
        \YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService::$calls  = 0;
    }

    pipeline_reset();

    echo "## v032 物流狀態通知的綁定（v0.2.12）\n";

    $wpdb = seed_label();
    check('(a) 綁定齊全的通知被接受並更新那一張單', 200 === notify(base_notify()) && 1 === count($wpdb->updates));
    check('(a2) 更新以 label 主鍵為條件（不是 order_id + trade_no，避免一次改到多列）',
        [ 'id' => 7 ] === ($wpdb->updates[0]['where'] ?? []));

    $wpdb = seed_label();
    $no_subtype = base_notify();
    unset($no_subtype['LogisticsSubType']);
    check('(b) 缺 LogisticsSubType 的通知被拒絕，且完全沒有寫入',
        400 === notify($no_subtype) && [] === $wpdb->updates && [] === YSOrder::$updates);

    $wpdb = seed_label();
    check('(c) subtype 不符的通知被拒絕（B2C 的通知不得寫到 C2C 的單上）',
        400 === notify(base_notify([ 'LogisticsSubType' => 'UNIMART' ])) && [] === $wpdb->updates);

    $wpdb = seed_label();
    $no_trade = base_notify();
    unset($no_trade['MerchantTradeNo']);
    check('(d) 缺 MerchantTradeNo 的通知被拒絕', 400 === notify($no_trade) && [] === $wpdb->updates);

    $wpdb = seed_label();
    check('(e) MerchantTradeNo 不符的通知被拒絕',
        400 === notify(base_notify([ 'MerchantTradeNo' => 'TAMPERED' ])) && [] === $wpdb->updates);

    // Callback identity must come from the pre-send attempt and exact label tuple. A legacy
    // label with no merchant identity cannot be authenticated and must fail closed.
    $wpdb = seed_label([ 'logistics_subtype' => '', 'merchant_trade_no' => '' ]);
    check('(f) 缺 merchant identity 的 legacy label 不得略過三元綁定',
        400 === notify(base_notify()) && [] === $wpdb->updates && [] === YSOrder::$updates);

    $wpdb = seed_label([ 'logistics_subtype' => '', 'merchant_trade_no' => '' ]);
    check('(g) 升級前的舊單遇到不符的 subtype 一樣拒絕',
        400 === notify(base_notify([ 'LogisticsSubType' => 'FAMIC2C' ])) && [] === $wpdb->updates);

    // 🔴 這兩條是「必填」這道守門唯一真正生效的地方。
    //
    // label 已落盤 subtype／trade no 時，缺欄位會被後面的相等性比對順手擋掉；
    // 但**升級前的舊單**兩個值都是空的，相等性比對無從比起——此時若把必填寫成
    // 「有傳才比」，不送欄位就整關通過。mutation pass 正是靠這兩條抓到它。
    $wpdb = seed_label([ 'logistics_subtype' => '', 'merchant_trade_no' => '' ]);
    $legacy_no_trade = base_notify();
    unset($legacy_no_trade['MerchantTradeNo']);
    check('(f2) 舊單 + 缺 MerchantTradeNo：仍然拒絕（必填就是必填）',
        400 === notify($legacy_no_trade) && [] === $wpdb->updates);

    $wpdb = seed_label([ 'logistics_subtype' => '', 'merchant_trade_no' => '' ]);
    $legacy_empty_trade = base_notify([ 'MerchantTradeNo' => '' ]);
    check('(f3) 舊單 + MerchantTradeNo 為空字串：一樣拒絕',
        400 === notify($legacy_empty_trade) && [] === $wpdb->updates);

    // 型錄裡沒有的物流方式＝這張單不是我們建的。
    $wpdb = seed_label([ 'shipping_method' => 'ys_ec_other_provider_method' ]);
    check('(h) label 的物流方式不在型錄內時拒絕', 400 === notify(base_notify()) && [] === $wpdb->updates);

    // 找不到單就什麼都不做（回 1|OK 只是讓供應商停止重送）。
    $wpdb = seed_label();
    $wpdb->label = null;
    $wpdb->dispatch_attempt_exists = false;
    check('(i) 找不到對應物流單時不寫入任何資料', 200 === notify(base_notify()) && [] === $wpdb->updates);

    // X7: a signed callback may not combine order A's MerchantTradeNo with order B's
    // AllPayLogisticsID. The exact tuple must be checked before pipeline/order writes.
    $wpdb = seed_label([
        'id' => 8,
        'order_id' => 43,
        'merchant_trade_no' => 'YS-ORDER-B',
        'provider_trade_no' => '900000002',
    ]);
    $wpdb->attempt_order_id = 42;
    pipeline_reset();
    check(
        '(X7) swapped MerchantTradeNo/AllPayLogisticsID is rejected before any projection',
        400 === notify(base_notify([ 'AllPayLogisticsID' => '900000002' ]))
            && [] === $wpdb->updates
            && [] === YSOrder::$updates
            && 0 === \YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService::$calls
    );

    // 通知帶回寄件憑據時補上，但不覆蓋建單當下拿到的權威值。
    $wpdb = seed_label();
    notify(base_notify([ 'CVSPaymentNo' => 'CP111', 'CVSValidationNo' => '2222' ]));
    check('(j) 通知帶回的寄件憑據會補進 label',
        'CP111' === ($wpdb->updates[0]['data']['cvs_payment_no'] ?? null)
            && '2222' === ($wpdb->updates[0]['data']['validation_no'] ?? null));

    $wpdb = seed_label([ 'cvs_payment_no' => 'CP-ORIGINAL', 'validation_no' => '9999' ]);
    notify(base_notify([ 'CVSPaymentNo' => 'CP111', 'CVSValidationNo' => '2222' ]));
    check('(k) 已有的寄件憑據不被通知覆蓋（建單當下那一份才是權威）',
        !isset($wpdb->updates[0]['data']['cvs_payment_no'])
            && !isset($wpdb->updates[0]['data']['validation_no']));

    $wpdb = seed_label();
    $wpdb->readback_omit = [ 'cvs_payment_no' ];
    check(
        '(j2) CVSPaymentNo update 回成功但讀回未落盤時回 503',
        503 === notify(base_notify([ 'CVSPaymentNo' => 'CP111', 'CVSValidationNo' => '2222' ]))
    );

    $wpdb = seed_label();
    $wpdb->readback_omit = [ 'validation_no' ];
    check(
        '(j3) CVSValidationNo update 回成功但讀回未落盤時回 503',
        503 === notify(base_notify([ 'CVSPaymentNo' => 'CP111', 'CVSValidationNo' => '2222' ]))
    );

    // 寄貨編號不是追蹤碼，不得混進 tracking_number。
    $wpdb = seed_label();
    notify(base_notify([ 'CVSPaymentNo' => 'CP111' ]));
    check('(l) tracking_number 取託運單號，不取寄貨編號',
        'BN999' === ($wpdb->updates[0]['data']['tracking_number'] ?? null));

    // 🔴 ACK 不可逆：回了 1|OK，綠界就不會再送這則通知。讀不動／寫不進去時
    // 一律回非 2xx，讓對方重送——「收到了但沒存起來」在 ACK 之後無法補救。
    $wpdb = seed_label();
    $wpdb->read_fails = true;
    check(
        '(m) 讀取 label 失敗時回 503（不得 ACK 1|OK，否則那筆通知永遠遺失）',
        503 === notify(base_notify()) && [] === $wpdb->updates
    );

    $wpdb = seed_label();
    $wpdb->write_fails = true;
    check(
        '(n) 寫入失敗時回 503（全部 durable 之後才 ACK）',
        503 === notify(base_notify())
    );

    $wpdb = seed_label();
    $wpdb->order = null;
    check(
        '(o) label 在但訂單讀不到時回 503（那是我們這邊的問題，不是對方送錯）',
        503 === notify(base_notify()) && [] === $wpdb->updates
    );

    // 🔴 `YSOrder::update()` 對 affected=0 也回 true——「回 true」不代表寫進去了。
    // 沒有讀回來確認的話，這一筆會被 ACK 掉，而那個狀態永遠不會再送來。
    $wpdb = seed_label();
    $GLOBALS['ys_order_silent_write'] = true;
    $silent = notify(base_notify());
    $GLOBALS['ys_order_silent_write'] = false;
    check('(p) 訂單寫入「回 true 但沒真的改變」時回 503（必須讀回來確認）', 503 === $silent);

    // 🔴 追蹤碼只取託運單號。沒有 BookingNote 時**不得**退回物流編號——
    // 顧客拿 AllPayLogisticsID 去物流商網站是查不到的，而客服看到「有追蹤碼」
    // 就不會再追下去。
    $wpdb = seed_label();
    $no_booking = base_notify();
    unset($no_booking['BookingNote']);
    notify($no_booking);
    $order_tracking = null;
    foreach (YSOrder::$updates as $u) {
        if (array_key_exists('tracking_number', $u)) {
            $order_tracking = (string) $u['tracking_number'];
        }
    }
    check(
        '(q) 沒有託運單號時不得把 AllPayLogisticsID 當成追蹤碼',
        '900000001' !== $order_tracking
            && '900000001' !== ($wpdb->updates[0]['data']['tracking_number'] ?? null)
    );

    $wpdb = seed_label([ 'tracking_number' => 'OLD-BN' ]);
    $wpdb->order = (object) [
        'id' => 42,
        'payment_detail' => json_encode([ 'shipping' => [ 'tracking_number' => 'OLD-BN' ] ]),
        'tracking_number' => 'OLD-BN',
    ];
    $no_booking = base_notify();
    unset($no_booking['BookingNote']);
    $preserve_code = notify($no_booking);
    $stored_projection = json_decode((string) ($wpdb->order->payment_detail ?? '{}'), true);
    check(
        '(X27) 空 BookingNote 保留 top-level、payment_detail 與 label 既有 tracking',
        200 === $preserve_code
            && 'OLD-BN' === (string) ($wpdb->order->tracking_number ?? '')
            && 'OLD-BN' === (string) ($stored_projection['shipping']['tracking_number'] ?? '')
            && !array_key_exists('tracking_number', (array) ($wpdb->updates[0]['data'] ?? []))
    );

    // ══ #3V：早到的通知、狀態單調性 ═════════════════════════════════════

    // 🔴 建單的順序是「送出 → 收到回應 → 才 INSERT label」。通知完全可能在那個
    // INSERT 之前抵達，而 ACK 不可逆——回了 1|OK，那則通知就永遠不會再來。
    $wpdb = seed_label();
    $wpdb->label = null;
    $wpdb->dispatch_attempt_exists = true;
    check(
        '(r) label 還沒落盤但授權表有這筆＝我們送出去的，回 503 請對方重送',
        503 === notify(base_notify()) && [] === $wpdb->updates
    );

    $wpdb = seed_label();
    $wpdb->label = null;
    $wpdb->dispatch_attempt_exists = false;
    check(
        '(s) label 與授權表都沒有＝真的不是我們的單，回 1|OK 停止重送且不寫入',
        200 === notify(base_notify()) && [] === $wpdb->updates
    );

    $wpdb = seed_label();
    $wpdb->label = null;
    $wpdb->attempt_read_fails = true;
    check(
        '(t) 授權表讀不動時回 503（讀不到不等於不是我們的）',
        503 === notify(base_notify())
    );

    // 🔴 連 `SHOW TABLES` 都可能失敗（連線斷了、權限被撤）。把它讀成「表不存在」
    // 就會走到「不是我們的單」→ ACK，而 ACK 不可逆。
    $wpdb = seed_label();
    $wpdb->label = null;
    $wpdb->show_tables_fails = true;
    check(
        '(t2) SHOW TABLES 查不動時回 503（不得讀成「表不存在」而 ACK）',
        503 === notify(base_notify())
    );

    // pipeline 拒絕轉換（遲到／亂序的通知）：ACK 沒問題，但**不得**把 label 的
    // 狀態寫回一個更早的值——那會讓訂單與 label 各說各話。
    $wpdb = seed_label();
    pipeline_reset([ 'success' => false, 'persisted' => true, 'message' => '不允許的轉換' ]);
    $code   = notify(base_notify([ 'LogisticsStatus' => '2030' ]));
    $update = $wpdb->updates[0]['data'] ?? [];
    $order_projection = [];
    foreach (YSOrder::$updates as $u) {
        if (array_key_exists('payment_detail', $u)) {
            $decoded          = json_decode((string) $u['payment_detail'], true);
            $order_projection = (array) (($decoded['shipping'] ?? []));
        }
    }

    check(
        '(u) pipeline 拒絕轉換時照樣 ACK，但不寫 label 狀態（不倒退）',
        200 === $code
            && ! array_key_exists('status', $update)
            && ! array_key_exists('status_code', $update)
            && 'BN999' === ($update['tracking_number'] ?? '')
    );

    // 🔴 訂單那一側的投影同樣不得倒退——否則訂單說「已取貨」、payment_detail
    // 說「配送中」，而我們還 ACK 了。憑據類欄位仍然要補上。
    check(
        '(u2) pipeline 拒絕轉換時 payment_detail 的狀態投影也不被覆寫',
        ! array_key_exists('logistics_status', $order_projection)
            && ! array_key_exists('logistics_status_msg', $order_projection)
            && 'BN999' === ($order_projection['tracking_number'] ?? '')
            && '900000001' === ($order_projection['allpay_logistics_id'] ?? '')
    );

    // pipeline 寫不進去＝retryable，而且必須在**動任何東西之前**就擋下來。
    $wpdb = seed_label();
    pipeline_reset([ 'success' => true, 'persisted' => false ]);
    $code = notify(base_notify());
    check(
        '(v) pipeline 未落盤時回 503，且訂單與 label 都還沒被改過',
        503 === $code && [] === $wpdb->updates && [] === YSOrder::$updates
    );

    pipeline_reset();

    echo "\nREGRESSION v032_logistics_notify_binding PASS={$pass} FAIL={$fail}\n";
    if ($fail > 0) {
        echo "Failed:\n";
        foreach ($bad as $name) { echo "  - {$name}\n"; }
        exit(1);
    }
}
