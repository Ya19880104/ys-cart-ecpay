<?php
/**
 * v017 — payment_detail compare-and-swap 契約（v0.3.0：核心共用 service）
 *
 * 缺口：payment_detail 是單一 JSON 欄位，卻是多個獨立子系統的帳本——付款 webhook 寫
 * gwsr、退款 ledger 寫 `_ys_ecpay_refunds`、物流 callback 寫 shipping、核心寫生命週期
 * merge／對帳／發票 override。任何一個 writer 走 read-modify-write 整包覆蓋，就會把
 * 自己讀取之後、寫入之前別人落盤的內容整段抹掉；被抹掉的若是「這筆退款已經送出」，
 * 結果就是重複退款。
 *
 * v0.2.12 曾在本外掛內自建一份 CAS。那不夠：**核心自己**也有盲寫 writer，provider
 * 端再怎麼 CAS 都擋不住。v0.3.0 起 CAS 上收為核心 `YSPaymentDetailStore`，全生態共用
 * 同一份；本外掛只保留一層薄的結果載體（`Support\DetailWriteOutcome`）。
 *
 * 本檔直接執行**核心 production 實作**，驗證整個結果分類：
 *   (a) 正常寫入 → updated，WHERE 以舊 raw 為條件
 *   (b) 併發落敗 → 重讀重算後成功，且對方的欄位完整保留
 *   (c) 同值 → no_op，且**不送出 UPDATE**（同值 UPDATE 的 affected_rows 天生為 0）
 *   (d) query() 回 false（SQL 錯誤）→ db_error，且不重試
 *   (e) 讀取 last_error → db_error（不得當成欄位為 NULL）
 *   (f) 欄位為 SQL NULL → WHERE 用 IS NULL
 *   (g) 持續衝突 → conflict_exhausted（bounded retry）
 *   (h) mutator 回 null → aborted，且不寫入
 *   (i) mutator 回非陣列 → 不寫入
 *   (j) 欄位為壞 JSON → invalid_json，且**不得**被重寫成空物件
 *   (k) 訂單不存在 → not_found
 *   (l) decision payload 取自**勝出**那一次 mutator
 *   (m) 每次重試都重新呼叫 mutator（仲裁必須用最新值重算）
 *   (n) provider 薄層：核心缺席 → core_unavailable，且 is_persisted() 為 false
 *
 * Run: php tests/regression/v017_payment_detail_cas_contract.php
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__);
    }

    function wp_json_encode($data)
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    function current_time(string $type): string
    {
        return '2026-08-11 00:00:00';
    }

    final class FakeWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';

        /** @var list<string> */
        public array $queries = [];
        /** 目前欄位值；null ＝ SQL NULL；false ＝ 查無此列。 */
        public string|null|false $value = null;
        public string $read_error = '';
        public string $write_error = '';
        /** UPDATE 要回傳的固定值（null ＝ 依實際 CAS 判定）。 */
        public mixed $force_update_result = null;
        /** 每次讀取後、寫入前，模擬其他 writer 改動欄位。 */
        public mixed $concurrent_writer = null;
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

        public function get_row(string $sql)
        {
            ++$this->reads;
            if ('' !== $this->read_error) {
                $this->last_error = $this->read_error;
                return null;
            }
            if (false === $this->value) {
                return null; // 查無此列
            }
            $row = (object) ['payment_detail' => $this->value];
            if (null !== $this->concurrent_writer) {
                ($this->concurrent_writer)($this);
            }
            return $row;
        }

        public function query(string $sql)
        {
            ++$this->updates;
            $this->queries[] = $sql;

            if ('' !== $this->write_error) {
                $this->last_error = $this->write_error;
                return false;
            }
            if (null !== $this->force_update_result) {
                return $this->force_update_result;
            }

            // 真實 CAS 判定：WHERE 條件必須與目前值相符。
            if (str_contains($sql, 'payment_detail IS NULL')) {
                if (null !== $this->value) {
                    return 0;
                }
            } else {
                if (!preg_match("/AND payment_detail = '(.*)'\$/s", $sql, $m)) {
                    return 0;
                }
                if (str_replace("''", "'", $m[1]) !== (string) $this->value) {
                    return 0;
                }
            }

            if (!preg_match("/SET payment_detail = '(.*?)', updated_at = /s", $sql, $set)) {
                return 0;
            }
            $this->value = str_replace("''", "'", $set[1]);

            return 1;
        }
    }
}

namespace YangSheep\Ecommerce\Models {
    class YSOrder
    {
        public static int $forgets = 0;

        public static function table(): string
        {
            return 'wp_ys_ec_orders';
        }

        public static function forget(int $id): void
        {
            ++self::$forgets;
        }

        public static function find(int $id): ?object
        {
            global $wpdb;
            if (false === $wpdb->value) {
                return null;
            }
            return (object) ['id' => $id, 'payment_detail' => $wpdb->value];
        }
    }
}

namespace YangSheep\Ecommerce\Utils {
    class YSLogger
    {
        /** @var list<array{0:string,1:string,2:array}> */
        public static array $errors = [];

        public static function error(string $channel, string $message, array $context = []): void
        {
            self::$errors[] = [$channel, $message, $context];
        }

        public static function warning(string $channel, string $message, array $context = []): void {}

        public static function info(string $channel, string $message, array $context = []): void {}
    }
}

namespace {
    $core = dirname(__DIR__, 3) . '/ys-cart/src/Services/Payment/';
    require_once $core . 'YSPaymentDetailResult.php';
    require_once $core . 'YSPaymentDetailStore.php';
    require_once dirname(__DIR__, 2) . '/src/Support/DetailWriteOutcome.php';
    require_once dirname(__DIR__, 2) . '/src/Support/OrderPaymentDetail.php';

    use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailResult as R;
    use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore as Store;
    use YangSheep\YSCartEcpay\Support\DetailWriteOutcome;
    use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;

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

    $fresh = static function (string|null|false $value): FakeWpdb {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->value = $value;
        return $wpdb;
    };

    // (0) 反自欺：受測的是核心 production 檔
    $rc = new \ReflectionClass(Store::class);
    $assert(
        realpath((string) $rc->getFileName()) === realpath($core . 'YSPaymentDetailStore.php'),
        '(0) 受測對象＝核心 production YSPaymentDetailStore（非本檔替身）'
    );

    // (a) 正常寫入
    $w = $fresh('{"a":1}');
    $r = Store::mutate(7, static function (array $d): array {
        $d['b'] = 2;
        return $d;
    });
    $assert(
        R::UPDATED === $r->get_outcome()
        && '{"a":1,"b":2}' === $w->value
        && 1 === $w->updates
        && str_contains($w->queries[0], 'AND payment_detail = \'{"a":1}\''),
        '(a) 正常寫入 → updated，WHERE 以舊 raw 為條件'
    );

    // (b) 併發落敗 → 重讀重算後成功，對方欄位保留
    $w = $fresh('{"a":1}');
    $w->concurrent_writer = static function (FakeWpdb $db): void {
        $db->value = '{"a":1,"other":"webhook"}';
        $db->concurrent_writer = null; // 只干擾一次
    };
    $r = Store::mutate(7, static function (array $d): array {
        $d['mine'] = 'refund';
        return $d;
    });
    $decoded = json_decode((string) $w->value, true);
    $assert(
        R::UPDATED === $r->get_outcome()
        && 2 === $r->get_attempts()
        && is_array($decoded)
        && 'webhook' === ($decoded['other'] ?? null)
        && 'refund' === ($decoded['mine'] ?? null),
        '(b) 併發落敗 → 重讀重算後成功，且對方寫入的欄位完整保留'
    );

    // (c) 同值 → no_op，且不送 UPDATE
    $w = $fresh('{"a":1}');
    $r = Store::mutate(7, static fn(array $d): array => $d);
    $assert(
        R::NO_OP === $r->get_outcome() && 0 === $w->updates,
        '(c) 同值 → no_op，且完全不送出 UPDATE（同值 UPDATE 的 affected_rows 天生為 0）'
    );

    // (d) UPDATE 回 false（SQL 錯誤）→ db_error，不重試
    $w = $fresh('{"a":1}');
    $w->write_error = 'Deadlock found';
    $r = Store::mutate(7, static function (array $d): array {
        $d['b'] = 2;
        return $d;
    });
    $assert(
        R::DB_ERROR === $r->get_outcome() && 1 === $w->updates,
        '(d) query() 回 false（SQL 錯誤）→ db_error，且只嘗試一次（不得當成競態重試）'
    );

    // (e) 讀取 last_error → db_error
    $w = $fresh('{"a":1}');
    $w->read_error = 'MySQL server has gone away';
    $r = Store::mutate(7, static fn(array $d): array => $d + ['b' => 2]);
    $assert(
        R::DB_ERROR === $r->get_outcome() && 0 === $w->updates,
        '(e) 讀取 last_error → db_error（不得把讀取失敗當成欄位為 NULL）'
    );

    // (f) 欄位為 SQL NULL → WHERE 用 IS NULL
    $w = $fresh(null);
    $r = Store::mutate(7, static fn(array $d): array => $d + ['b' => 2]);
    $assert(
        R::UPDATED === $r->get_outcome()
        && str_contains($w->queries[0], 'payment_detail IS NULL')
        && ! str_contains($w->queries[0], 'payment_detail = NULL'),
        '(f) 欄位為 SQL NULL → WHERE 使用 IS NULL（`= NULL` 永遠不成立）'
    );

    // (g) 持續衝突 → conflict_exhausted
    $w = $fresh('{"a":1}');
    $w->force_update_result = 0;
    $r = Store::mutate(7, static fn(array $d): array => $d + ['b' => 2], 3);
    $assert(
        R::CONFLICT_EXHAUSTED === $r->get_outcome() && 3 === $w->updates,
        '(g) 持續衝突 → conflict_exhausted（bounded retry，此處上限 3）'
    );

    // (h) mutator 回 null → aborted，不寫入
    $w = $fresh('{"a":1}');
    $r = Store::mutate(7, static function (array $d, int $attempt, &$decision) {
        $decision = 'nothing-to-do';
        return null;
    });
    $assert(
        R::ABORTED === $r->get_outcome() && 0 === $w->updates && 'nothing-to-do' === $r->get_decision(),
        '(h) mutator 回 null → aborted、不寫入，且 decision payload 帶回'
    );

    // (i) mutator 回非陣列 → 不寫入
    $w = $fresh('{"a":1}');
    $r = Store::mutate(7, static fn(array $d) => 'oops');
    $assert(
        ! $r->is_persisted() && 0 === $w->updates,
        '(i) mutator 回非陣列 → 不寫入殘缺資料'
    );

    // (j) 壞 JSON → invalid_json，且不得被重寫成空物件
    $w = $fresh('{"broken');
    $r = Store::mutate(7, static fn(array $d): array => $d + ['b' => 2]);
    $assert(
        R::INVALID_JSON === $r->get_outcome()
        && 0 === $w->updates
        && '{"broken' === $w->value,
        '(j) 欄位為壞 JSON → invalid_json，且原始內容原封不動（不得自動洗成空物件）'
    );

    // (k) 訂單不存在
    $w = $fresh(false);
    $r = Store::mutate(7, static fn(array $d): array => $d);
    $assert(
        R::NOT_FOUND === $r->get_outcome() && 0 === $w->updates,
        '(k) 查無訂單 → not_found'
    );

    // (l)(m) decision 取自勝出那一次；每次重試都重新呼叫 mutator
    $w = $fresh('{"n":0}');
    $w->concurrent_writer = static function (FakeWpdb $db): void {
        $db->value = '{"n":1}';
        $db->concurrent_writer = null;
    };
    $seen = [];
    $r = Store::mutate(7, static function (array $d, int $attempt, &$decision) use (&$seen): array {
        $seen[] = (int) ($d['n'] ?? -1);
        $decision = 'attempt-' . $attempt;
        $d['n'] = (int) ($d['n'] ?? 0) + 10;
        return $d;
    });
    $assert(
        'attempt-2' === $r->get_decision() && [0, 1] === $seen && '{"n":11}' === $w->value,
        '(l)(m) 每次重試都以最新值重新呼叫 mutator，decision 與落盤值都取自勝出那一次'
    );

    // (n) provider 薄層語意
    $unavailable = DetailWriteOutcome::core_unavailable();
    $w = $fresh('{"a":1}');
    $ok = OrderPaymentDetail::mutate(7, static fn(array $d): array => $d + ['b' => 2]);
    $assert(
        DetailWriteOutcome::CORE_UNAVAILABLE === $unavailable->get_outcome()
        && ! $unavailable->is_persisted()
        && ! $unavailable->is_aborted()
        && $ok instanceof DetailWriteOutcome
        && $ok->is_persisted(),
        '(n) provider 薄層：核心缺席 → core_unavailable 且非 persisted；核心在場 → 原樣搬運結論'
    );

    // (o) 契約：provider 端不得再自帶 CAS 實作
    $shim = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/OrderPaymentDetail.php'));
    $assert(
        str_contains($shim, 'YSPaymentDetailStore::mutate')
        && ! preg_match('/UPDATE\s+\{\$table\}/', $shim)
        && ! str_contains($shim, '$wpdb->query'),
        '(o) provider 薄層只委派核心 service，不得自帶 UPDATE／$wpdb->query'
    );

    // (p) 契約：本外掛已無任何 payment_detail 的整包覆蓋寫入
    $leaks = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ('php' !== strtolower($file->getExtension())) {
            continue;
        }
        $src = str_replace("\r\n", "\n", (string) file_get_contents((string) $file->getPathname()));
        if (preg_match("/'payment_detail'\s*=>/", $src) || preg_match('/UPDATE\s+\{?\$table\}?\s+SET\s+payment_detail/', $src)) {
            $leaks[] = basename((string) $file->getPathname());
        }
    }
    $assert([] === $leaks, '(p) src/ 內無 payment_detail 整包覆蓋殘留（' . (implode(', ', $leaks) ?: '無') . '）');

    echo "\npayment_detail CAS contract: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
