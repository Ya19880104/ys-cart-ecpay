<?php
/**
 * v017 — payment_detail compare-and-swap 契約
 *
 * 缺口：payment_detail 是單一 JSON 欄位，本外掛有多個併發 writer（付款 webhook 回寫
 * gwsr、退款 ledger `_ys_ecpay_refunds`、建單時寫 merchant_trade_no），先前全部走
 * read-modify-write + YSOrder::update() 整包覆蓋。webhook 與退款重疊時，後寫者會靜默
 * 蓋掉先寫者——而被蓋掉的正是「不重複退款」的唯一依據。
 *
 * 其中 EcpayCreditGateway 的 gwsr 回寫更嚴重：它寫回的是方法開頭讀到的**舊快照**，
 * 不只有競態視窗，是必然覆蓋。
 *
 * 三個必須分流的細節（MySQL／wpdb 天性）：
 *   1. query() 回 false ＝ SQL 錯誤；回 0 ＝ CAS 落敗。混為一談會把錯誤當成競態而重試。
 *   2. affected_rows 算「實際變更的列」，同值 UPDATE 天生回 0——那不是落敗。
 *   3. 欄位為 SQL NULL 時 `= NULL` 永遠不成立，WHERE 必須用 IS NULL。
 *
 * 驗證：
 *   (a) 正常路徑：以舊值為條件寫入，回 true
 *   (b) 併發：期間被改動（affected=0）→ 重讀重算後成功，且對方的欄位不被覆蓋
 *   (c) 同值 no-op → true（不得因 affected=0 判為落敗）
 *   (d) UPDATE 回 false（SQL 錯誤）→ false，且不重試
 *   (e) 讀取時 last_error → false（不得把讀取失敗當成欄位為 NULL）
 *   (f) 欄位為 NULL → WHERE 使用 IS NULL
 *   (g) 持續衝突 → 重試耗盡回 false（呼叫端據此中止）
 *   (h) mutate callback 回非陣列 → false（不寫入殘缺資料）
 *
 * Run: php tests/regression/v017_payment_detail_cas_contract.php
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__);
    }

    function wp_json_encode($data) {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 記錄每次 UPDATE 的 SQL，供 (f) 檢查 WHERE 形態。 */
    final class FakeWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';

        /** @var list<string> */
        public array $queries = [];
        /** 目前欄位值；null ＝ SQL NULL。 */
        public ?string $value = null;
        /** 讀取時要注入的錯誤訊息。 */
        public string $read_error = '';
        /** UPDATE 要回傳的固定值（null ＝ 依實際 CAS 判定）。 */
        public $force_update_result = null;
        /** 每次讀取後、寫入前，模擬其他 writer 改動欄位。 */
        public $concurrent_writer = null;
        public int $reads = 0;
        public int $updates = 0;

        public function prepare(string $sql, ...$args): string
        {
            foreach ($args as $a) {
                $rep = is_int($a) ? (string) $a : "'" . str_replace("'", "''", (string) $a) . "'";
                $sql = preg_replace('/%[ds]/', $rep, $sql, 1) ?? $sql;
            }
            return $sql;
        }

        public function get_var(string $sql): ?string
        {
            ++$this->reads;
            if ('' !== $this->read_error) {
                $this->last_error = $this->read_error;
                return null;
            }
            $current = $this->value;
            if (null !== $this->concurrent_writer) {
                ($this->concurrent_writer)($this);
            }
            return $current;
        }

        public function query(string $sql)
        {
            ++$this->updates;
            $this->queries[] = $sql;
            if (null !== $this->force_update_result) {
                return $this->force_update_result;
            }
            // 還原 CAS 語意：SET 的新值寫入前，比對 WHERE 的舊值是否仍成立。
            if (!preg_match("/SET payment_detail = '(.*)' WHERE id = \d+ AND payment_detail (IS NULL|= '(.*)')\z/s", $sql, $m)) {
                return false;
            }
            $new = str_replace("''", "'", $m[1]);
            if ('IS NULL' === $m[2]) {
                if (null !== $this->value) {
                    return 0;
                }
            } else {
                $expected = str_replace("''", "'", $m[3]);
                if ($this->value !== $expected) {
                    return 0;
                }
            }
            if ($this->value === $new) {
                return 0; // MySQL：同值不算變更
            }
            $this->value = $new;
            return 1;
        }
    }
}

namespace YangSheep\Ecommerce\Models {
    final class YSOrder
    {
        public static function table(): string
        {
            return 'wp_ys_ec_orders';
        }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/src/Support/OrderPaymentDetail.php';

    use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;

    $pass = 0;
    $fail = 0;
    $assert = function (bool $ok, string $label) use (&$pass, &$fail): void {
        if ($ok) {
            ++$pass;
            echo "  PASS  {$label}\n";
        } else {
            ++$fail;
            echo "  FAIL  {$label}\n";
        }
    };

    $fresh = function (?string $value = null): FakeWpdb {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->value = $value;
        return $wpdb;
    };

    // (a) 正常路徑
    $db = $fresh(json_encode(['gwsr' => 'G1']));
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d): array => $d + ['note' => 'x']);
    $decoded = json_decode((string) $db->value, true);
    $assert(
        true === $ok && 'G1' === ($decoded['gwsr'] ?? null) && 'x' === ($decoded['note'] ?? null) && 1 === $db->updates,
        '(a) 正常路徑：以舊值為條件寫入成功，既有欄位保留'
    );

    // (b) 併發：讀後被別人改動 → 第一次 affected=0，重讀重算後成功且不覆蓋對方
    $db = $fresh(json_encode(['gwsr' => 'G1']));
    $injected = false;
    $db->concurrent_writer = static function (FakeWpdb $self) use (&$injected): void {
        if ($injected) {
            return;
        }
        $injected = true;
        // 付款 webhook 在退款 ledger 讀取之後、寫入之前完成寫入。
        $self->value = json_encode(['gwsr' => 'G2-from-webhook']);
    };
    $ok = OrderPaymentDetail::mutate(7, static function (array $d): array {
        $d['_ys_ecpay_refunds'] = ['req-1' => ['status' => 'pending']];
        return $d;
    });
    $decoded = json_decode((string) $db->value, true);
    $assert(
        true === $ok
        && 'G2-from-webhook' === ($decoded['gwsr'] ?? null)
        && 'pending' === ($decoded['_ys_ecpay_refunds']['req-1']['status'] ?? null)
        && $db->updates >= 2,
        '(b) 併發：重試後兩個 writer 的資料並存（webhook 的 gwsr 未被退款 ledger 覆蓋）'
    );

    // (c) 同值 no-op：affected=0 但不是落敗
    $payload = json_encode(['gwsr' => 'G1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db = $fresh($payload);
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d): array => $d);
    $assert(
        true === $ok && 0 === $db->updates,
        '(c) 同值 → 送出前即判為 no-op 回 true（不得因 affected=0 判為 CAS 落敗）'
    );

    // (d) UPDATE 回 false ＝ SQL 錯誤：立即失敗，不得重試
    $db = $fresh(json_encode(['a' => 1]));
    $db->force_update_result = false;
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d): array => $d + ['b' => 2]);
    $assert(
        false === $ok && 1 === $db->updates,
        '(d) UPDATE 回 false（SQL 錯誤）→ 立即回 false 且只嘗試一次（錯誤 ≠ 競態）'
    );

    // (e) 讀取失敗不得被當成「欄位為 NULL」
    $db = $fresh(json_encode(['a' => 1]));
    $db->read_error = 'MySQL server has gone away';
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d): array => $d + ['b' => 2]);
    $assert(
        false === $ok && 0 === $db->updates,
        '(e) 讀取時 last_error → 回 false 且完全不寫入'
    );

    // (f) 欄位為 NULL → WHERE 必須用 IS NULL
    $db = $fresh(null);
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d): array => $d + ['first' => true]);
    $assert(
        true === $ok
        && 1 === count($db->queries)
        && str_contains($db->queries[0], 'payment_detail IS NULL')
        && ! str_contains($db->queries[0], "payment_detail = ''"),
        '(f) 欄位為 SQL NULL 時 WHERE 使用 IS NULL（`= NULL` 永遠不成立）'
    );

    // (g) 持續衝突 → 重試耗盡回 false
    $db = $fresh(json_encode(['n' => 0]));
    $n = 0;
    $db->concurrent_writer = static function (FakeWpdb $self) use (&$n): void {
        $self->value = json_encode(['n' => ++$n]);
    };
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d): array => $d + ['mine' => true]);
    $assert(
        false === $ok && $db->updates >= 5,
        '(g) 持續衝突 → 重試耗盡回 false（呼叫端據此中止並記 CRITICAL）'
    );

    // (h) callback 回非陣列 → 不寫入
    $db = $fresh(json_encode(['a' => 1]));
    /** @phpstan-ignore-next-line 故意回傳錯型別 */
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d) => 'not-an-array');
    $assert(
        false === $ok && 0 === $db->updates,
        '(h) mutate callback 回非陣列 → 回 false 且不寫入'
    );

    echo "\npayment_detail CAS contract: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
