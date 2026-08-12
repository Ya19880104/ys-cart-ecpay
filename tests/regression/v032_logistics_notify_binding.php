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

        public static function update( int $id, array $data ): bool {
            self::$updates[] = [ 'id' => $id ] + $data;
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
        /** @var array<int,array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
        public array $updates = [];

        public function prepare(string $sql, ...$args): string {
            foreach ($args as $arg) {
                $sql = preg_replace('/%[dsf]/', is_string($arg) ? "'" . $arg . "'" : (string) $arg, $sql, 1);
            }
            return $sql;
        }

        public function get_row(string $sql) {
            if (false !== strpos($sql, 'shipping_labels')) {
                if ($this->read_fails) {
                    $this->last_error = 'MySQL server has gone away';
                    return null;
                }
                // sync_label() 的 readback：回傳最後一次寫進去的狀態。
                if (false !== strpos($sql, 'SELECT status FROM')) {
                    $last = end($this->updates);
                    return $last ? (object) [ 'status' => $last['data']['status'] ?? '' ] : null;
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

    // 升級前建立的舊單沒有落盤 subtype／trade no，但它的 shipping_method 一定在型錄裡，
    // 因此仍然驗得出來——不能因為舊單就整道守門失效。
    $wpdb = seed_label([ 'logistics_subtype' => '', 'merchant_trade_no' => '' ]);
    check('(f) 升級前的舊單仍以型錄的 subtype 驗證（相符則接受）', 200 === notify(base_notify()));

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
    check('(i) 找不到對應物流單時不寫入任何資料', 200 === notify(base_notify()) && [] === $wpdb->updates);

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

    echo "\nREGRESSION v032_logistics_notify_binding PASS={$pass} FAIL={$fail}\n";
    if ($fail > 0) {
        echo "Failed:\n";
        foreach ($bad as $name) { echo "  - {$name}\n"; }
        exit(1);
    }
}
