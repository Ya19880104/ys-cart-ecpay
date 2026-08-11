<?php
/**
 * v015 — 信用卡退款行為契約（v0.3.0：原子式 reservation）
 *
 * 先前這條測試整條都是 source-text 斷言——它證明「某段字串在檔案裡」，不證明
 * 「併發時只會退一次款」。實際缺陷正是這樣漏掉的：pending 檢查讀的是方法開頭的
 * 舊快照，reserve 寫入卻在很久之後，兩個併發請求各自判定「沒有進行中的退款」，
 * 於是都走到 DoAction ＝ 退兩次。
 *
 * 本版改為**執行 production 的 `EcpayCreditGateway::process_refund()`**，以假的
 * wpdb（走真正的核心 CAS）與假的 EcpayPaymentClient 驗證行為：
 *
 *   (a) 一般路徑：closed → R，ledger 落 done
 *   (b) 併發同一 request_id：只有一個 winner 送出 DoAction，另一個被凍結擋下
 *   (c) 併發不同 request_id：同上（全單凍結，不分 request_id）
 *   (d) 冪等重放：done + fingerprint 相符 → 不再送金流
 *   (e) fingerprint 不符（request_id 撞號）→ 拒絕，不送金流
 *   (f) done 落盤失敗 → indeterminate（不得回 success）
 *   (g) E→N：E 成功但步驟 ledger 落盤失敗 → **不得**送出 N
 *   (h) test_mode → 拒絕，且 client 呼叫次數 0
 *   (i) 小數金額 → 拒絕，且 client 呼叫次數 0
 *   (j) 卡別無法證明／分期／紅利 → 拒絕，且不送 DoAction
 *   (k) 契約：仲裁與寫入在同一個 CAS closure 內（負向：不得殘留舊的先檢查後寫入）
 *
 * Run: php tests/regression/v015_credit_refund_contract.php
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

    function sanitize_text_field($value): string
    {
        return trim(strip_tags((string) $value));
    }

    function __($text, $domain = '')
    {
        return $text;
    }

    final class FakeWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public string|null|false $value = null;
        /** 寫入前的攔截器：可模擬併發改寫或注入寫入錯誤。 */
        public mixed $before_write = null;
        public string $write_error = '';
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
            if (false === $this->value) {
                return null;
            }
            return (object) ['payment_detail' => $this->value];
        }

        public function query(string $sql)
        {
            ++$this->updates;
            if (null !== $this->before_write) {
                ($this->before_write)($this, $sql);
            }
            if ('' !== $this->write_error) {
                $this->last_error = $this->write_error;
                return false;
            }
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

namespace YangSheep\Ecommerce\Gateways {
    interface YSGatewayInterface {}
}

namespace YangSheep\Ecommerce\Models {
    class YSOrder
    {
        public static float $total = 1000.0;
        public static float $refunded = 0.0;

        public static function table(): string
        {
            return 'wp_ys_ec_orders';
        }

        public static function forget(int $id): void {}

        public static function find(int $id): ?object
        {
            global $wpdb;
            if (false === $wpdb->value) {
                return null;
            }
            return (object) [
                'id' => $id,
                'total' => self::$total,
                'refunded_amount' => self::$refunded,
                'payment_detail' => $wpdb->value,
                'gateway_trade_no' => 'TN-1',
            ];
        }

        public static function update(int $id, array $data): bool
        {
            return true;
        }
    }
}

namespace YangSheep\Ecommerce\Utils {
    class YSLogger
    {
        public static array $errors = [];
        public static function error(string $c, string $m, array $ctx = []): void { self::$errors[] = [$c, $m, $ctx]; }
        public static function warning(string $c, string $m, array $ctx = []): void {}
        public static function info(string $c, string $m, array $ctx = []): void {}
    }
}

namespace YangSheep\YSCartEcpay {
    final class Plugin
    {
        public static function manifest(): array { return []; }
    }
}

namespace YangSheep\YSCartEcpay\Support {
    class Settings
    {
        public static bool $test_mode = false;

        public static function payment_credentials(): array
        {
            return [
                'merchant_id' => 'M1',
                'hash_key' => 'K',
                'hash_iv' => 'I',
                'test_mode' => self::$test_mode,
            ];
        }

        public static function has_payment_credentials(): bool { return true; }
        public static function gateway_enabled(string $k): bool { return true; }
    }
}

namespace YangSheep\YSCartEcpay\Payment {
    /** 取代真實 HTTP client：記錄每一次呼叫，讓「不得送出金流」成為可驗證的事實。 */
    class EcpayPaymentClient
    {
        /** @var list<array{0:string,1:mixed}> */
        public static array $calls = [];
        public static array $close = ['state' => 'closed', 'message' => ''];
        public static array $query = ['success' => true, 'data' => []];
        /** 每個 DoAction 動作的回應（依序取用）。 */
        public static array $do_action_results = [];

        public static function is_canonical_twd($amount): bool
        {
            if (is_int($amount)) {
                return $amount > 0;
            }
            if (!is_float($amount) || !is_finite($amount) || $amount <= 0) {
                return false;
            }
            return abs($amount - round($amount)) < 1e-9;
        }

        public function query_trade(string $mtn): array
        {
            self::$calls[] = ['query_trade', $mtn];
            return self::$query;
        }

        public function query_credit_close_status(string $gwsr, int $amount): array
        {
            self::$calls[] = ['query_close', $gwsr];
            return self::$close;
        }

        public function do_action_refund(string $mtn, string $tn, float $amount, string $action = 'R'): array
        {
            self::$calls[] = ['do_action', $action];
            $next = array_shift(self::$do_action_results);
            return $next ?? ['success' => true, 'indeterminate' => false, 'data' => ['TradeNo' => 'TN-1'], 'message' => ''];
        }

        /** DoAction 呼叫次數（「不得送出金流」的可驗證量測）。 */
        public static function do_action_count(): int
        {
            return count(array_filter(self::$calls, static fn(array $c): bool => 'do_action' === $c[0]));
        }
    }
}

namespace {
    $core = dirname(__DIR__, 3) . '/ys-cart/src/Services/Payment/';
    require_once $core . 'YSPaymentDetailResult.php';
    require_once $core . 'YSPaymentDetailStore.php';
    require_once dirname(__DIR__, 2) . '/src/Support/DetailWriteOutcome.php';
    require_once dirname(__DIR__, 2) . '/src/Support/OrderPaymentDetail.php';
    require_once dirname(__DIR__, 2) . '/src/Payment/EcpayGatewayBase.php';
    require_once dirname(__DIR__, 2) . '/src/Payment/EcpayCreditGateway.php';

    use YangSheep\Ecommerce\Models\YSOrder;
    use YangSheep\YSCartEcpay\Payment\EcpayCreditGateway;
    use YangSheep\YSCartEcpay\Payment\EcpayPaymentClient;
    use YangSheep\YSCartEcpay\Support\Settings;

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

    /** 一張「一般信用卡、已關帳」的乾淨訂單。 */
    $seed = static function (array $extra = []): FakeWpdb {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $wpdb->value = json_encode(array_merge([
            'trade_no' => 'TN-1',
            'mer_trade_no' => 'YS7Tabc',
            'gwsr' => 'GW-1',
            'payment_type' => 'Credit_CreditCard',
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        YSOrder::$total = 1000.0;
        YSOrder::$refunded = 0.0;
        Settings::$test_mode = false;
        EcpayPaymentClient::$calls = [];
        EcpayPaymentClient::$close = ['state' => 'closed', 'message' => ''];
        EcpayPaymentClient::$do_action_results = [];
        \YangSheep\Ecommerce\Utils\YSLogger::$errors = [];

        return $wpdb;
    };

    $gw = new EcpayCreditGateway();
    $ledger = static function (FakeWpdb $db): array {
        $d = json_decode((string) $db->value, true);
        return is_array($d['_ys_ecpay_refunds'] ?? null) ? $d['_ys_ecpay_refunds'] : [];
    };

    // (a) 一般路徑
    $w = $seed();
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    $l = $ledger($w);
    $assert(
        true === ($r['success'] ?? false)
        && 1 === EcpayPaymentClient::do_action_count()
        && 'done' === ($l['req-1']['status'] ?? '')
        && 1000 === ($l['req-1']['amount'] ?? null),
        '(a) closed → R，ledger 落 done 且帶 fingerprint'
    );

    // (b) 併發同一 request_id：第二個必須被凍結擋下，且不得送出 DoAction
    $w = $seed();
    $w->before_write = static function (FakeWpdb $db, string $sql) use (&$gw): void {
        if (!str_contains($sql, '_ys_ecpay_refunds')) {
            return;
        }
        $db->before_write = null; // 只插隊一次
        // 另一個併發請求在我們寫入之前先 reserve 成功
        $detail = json_decode((string) $db->value, true) ?: [];
        $detail['_ys_ecpay_refunds'] = ['req-1' => ['status' => 'pending', 'amount' => 1000, 'trade_no' => 'TN-1', 'merchant_trade_no' => 'YS7Tabc', 'gwsr' => 'GW-1']];
        $db->value = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    $assert(
        false === ($r['success'] ?? true)
        && 'indeterminate' === ($r['outcome'] ?? '')
        && 0 === EcpayPaymentClient::do_action_count(),
        '(b) 併發同一 request_id：輸家被凍結擋下，DoAction 呼叫次數 0'
    );

    // (c) 併發不同 request_id：同樣被全單凍結擋下
    $w = $seed();
    $w->before_write = static function (FakeWpdb $db, string $sql): void {
        if (!str_contains($sql, '_ys_ecpay_refunds')) {
            return;
        }
        $db->before_write = null;
        $detail = json_decode((string) $db->value, true) ?: [];
        $detail['_ys_ecpay_refunds'] = ['other-req' => ['status' => 'pending', 'amount' => 1000]];
        $db->value = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-2']);
    $assert(
        false === ($r['success'] ?? true)
        && 'indeterminate' === ($r['outcome'] ?? '')
        && 0 === EcpayPaymentClient::do_action_count(),
        '(c) 併發不同 request_id：全單凍結（不分 request_id），DoAction 呼叫次數 0'
    );

    // (d) 冪等重放
    $w = $seed(['_ys_ecpay_refunds' => ['req-1' => [
        'status' => 'done', 'amount' => 1000, 'trade_no' => 'TN-1',
        'merchant_trade_no' => 'YS7Tabc', 'gwsr' => 'GW-1',
    ]]]);
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    $assert(
        true === ($r['success'] ?? false)
        && 0 === EcpayPaymentClient::do_action_count()
        && str_contains((string) ($r['message'] ?? ''), '冪等重放'),
        '(d) done + fingerprint 相符 → 冪等重放，不再送金流'
    );

    // (e) fingerprint 不符（request_id 撞號）
    $w = $seed(['_ys_ecpay_refunds' => ['req-1' => [
        'status' => 'done', 'amount' => 500, 'trade_no' => 'OTHER',
        'merchant_trade_no' => 'OTHER', 'gwsr' => 'OTHER',
    ]]]);
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    $assert(
        false === ($r['success'] ?? true)
        && 0 === EcpayPaymentClient::do_action_count()
        && str_contains((string) ($r['message'] ?? ''), '指紋'),
        '(e) fingerprint 不符 → 拒絕且不送金流（防 request_id 撞號回報假成功）'
    );

    // (f) done 落盤失敗 → indeterminate
    $w = $seed();
    $w->before_write = static function (FakeWpdb $db, string $sql): void {
        if (str_contains($sql, '"status":"done"')) {
            $db->write_error = 'disk full';
        }
    };
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    $assert(
        false === ($r['success'] ?? true)
        && 'indeterminate' === ($r['outcome'] ?? '')
        && 1 === EcpayPaymentClient::do_action_count(),
        '(f) 金流已送出但 done 落盤失敗 → indeterminate（不得回 success）'
    );

    // (g) E→N：E 成功後步驟 ledger 落盤失敗 → 不得送 N
    $w = $seed();
    EcpayPaymentClient::$close = ['state' => 'to_close', 'message' => ''];
    $w->before_write = static function (FakeWpdb $db, string $sql): void {
        if (str_contains($sql, '準備執行下一步')) {
            $db->write_error = 'disk full';
        }
    };
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    $actions = array_values(array_map(static fn(array $c) => $c[1], array_filter(EcpayPaymentClient::$calls, static fn(array $c) => 'do_action' === $c[0])));
    $assert(
        ['E'] === $actions
        && 'indeterminate' === ($r['outcome'] ?? ''),
        '(g) E 成功但步驟 ledger 落盤失敗 → 不得送出 N（實際送出動作僅 [E]）'
    );

    // (h) test_mode
    $w = $seed();
    Settings::$test_mode = true;
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    $assert(
        false === ($r['success'] ?? true)
        && 0 === count(EcpayPaymentClient::$calls)
        && str_contains((string) ($r['message'] ?? ''), '測試模式'),
        '(h) test_mode → 拒絕，且完全沒有任何 client 呼叫（含唯讀查詢）'
    );

    // (i) 小數金額
    $w = $seed();
    $r = $gw->process_refund(7, 100.5, '', ['refund_request_id' => 'req-1']);
    $assert(
        false === ($r['success'] ?? true) && 0 === count(EcpayPaymentClient::$calls),
        '(i) 小數金額 → 拒絕，且 client 呼叫次數 0（不得四捨五入後送出不同金額）'
    );

    // (j) 卡別 gate
    $cases = [
        '分期' => ['stage' => '3'],
        '紅利' => ['red_dan' => '100'],
        '銀聯等非一般信用卡' => ['payment_type' => 'Credit_UnionPay'],
    ];
    $gate_ok = true;
    foreach ($cases as $why => $extra) {
        $w = $seed($extra);
        $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
        if (($r['success'] ?? false) || EcpayPaymentClient::do_action_count() > 0) {
            $gate_ok = false;
            echo "        ↳ {$why} 未被擋下\n";
        }
    }
    // 無法證明付款方式（舊訂單沒有任何標記）
    global $wpdb;
    $wpdb = new FakeWpdb();
    $wpdb->value = json_encode(['trade_no' => 'TN-1', 'mer_trade_no' => 'YS7Tabc', 'gwsr' => 'GW-1'], JSON_UNESCAPED_SLASHES);
    EcpayPaymentClient::$calls = [];
    EcpayPaymentClient::$query = ['success' => true, 'data' => []];
    $r = $gw->process_refund(7, 1000.0, '', ['refund_request_id' => 'req-1']);
    if (($r['success'] ?? false) || EcpayPaymentClient::do_action_count() > 0) {
        $gate_ok = false;
        echo "        ↳ 無法證明付款方式 未被擋下\n";
    }
    $assert($gate_ok, '(j) 分期／紅利／非一般信用卡／無法證明 → 一律拒絕，不送 DoAction');

    // (k) 契約：仲裁必須在 CAS closure 內，且不得殘留舊的「先檢查後寫入」
    $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Payment/EcpayCreditGateway.php'));
    $pos_reserve = strpos($src, "'action' => 'reserved'");
    $pos_do = strpos($src, '$client->do_action_refund(');
    $assert(
        false !== $pos_reserve
        && false !== $pos_do
        && $pos_reserve < $pos_do
        // 舊形態：在 mutate() 之外先掃一次 ledger 找 pending，再於稍後寫入。
        && 0 === preg_match('/foreach \( \$history as \$frozen_id/', $src)
        && ! str_contains($src, '$history = is_array( $payment_detail'),
        '(k) reservation 在 DoAction 之前，且無「先掃 ledger 再寫入」的舊分流殘留'
    );

    // (l) 契約：done 落盤失敗不得回 success
    $assert(
        ! preg_match("/done_note/", $src)
        && str_contains($src, 'indeterminate_persist_failure'),
        '(l) 終態落盤失敗一律走 indeterminate，不得再以 success＋註記帶過'
    );

    echo "\ncredit refund behaviour contract: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
