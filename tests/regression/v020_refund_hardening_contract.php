<?php
/**
 * v020 — 退款強化（CODEX #2D）：嚴格 schema、不可變指紋、送出前 token、唯一證據集合
 *
 * 七個缺口，每一個都能讓一筆不該送出的 DoAction 送出去，或讓一筆送出去的
 * DoAction 事後無法證明：
 *
 *   1. 核心 entry 的旗標用 `! empty()` 判定 —— `'0'` 被當成 false（正確）但
 *      `'no'`、`'false'` 被當成 true（錯誤方向不一致）；金額用 `(float)` 轉型，
 *      `'1000abc'` 變成 1000.0 而通過核對。
 *   2. 終態寫入 `'trade_no' => $done_trade_no` 直接覆蓋**指紋欄位**，之後所有
 *      fingerprint 比對都是在跟被改過的值比對。
 *   3. 綠界回應缺 TradeNo 時靜默退回成我們自己送出的 trade_no。
 *   4. reserve 與終態之間沒有再驗核心授權：期間核心可能已改派給別的 gateway。
 *   5. 卡別判定的證據集合取決於「gwsr 有沒有被快取」這個無關的狀態。
 *   6. 送出前沒有 durable 紀錄：process 在送出中途被 kill，事後看不出綠界有沒有收到。
 *   7. 人工復原有兩個入口（本外掛 CLI 直接改 ledger、核心 CLI 走同步），
 *      而本外掛那個還能用 `--trade-no` 改寫指紋。
 *
 * 全部以**執行 production 程式碼**驗證，並以 DoAction 呼叫次數作為「有沒有真的
 * 送出金流」的量測。
 *
 * Run: php tests/regression/v020_refund_hardening_contract.php
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
        public mixed $before_write = null;
        public string $write_error = '';
        /** 只讓特定內容的寫入失敗（模擬「送出前紀錄寫不進去」）。 */
        public string $fail_write_containing = '';
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
            if ('' !== $this->fail_write_containing && str_contains($sql, $this->fail_write_containing)) {
                $this->last_error = 'simulated failure';
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
    class EcpayPaymentClient
    {
        /** @var list<array{0:string,1:mixed}> */
        public static array $calls = [];
        public static array $close = ['state' => 'closed', 'message' => ''];
        public static array $query = ['success' => true, 'data' => []];
        public static array $do_action_results = [];
        /** 每次 do_action 呼叫當下的 ledger 快照（證明「送出前已落盤」）。 */
        public static array $ledger_at_send = [];

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
            global $wpdb;
            self::$calls[] = ['do_action', $action];

            $detail = json_decode((string) $wpdb->value, true);
            self::$ledger_at_send[] = is_array($detail['_ys_ecpay_refunds'] ?? null)
                ? $detail['_ys_ecpay_refunds']
                : [];

            $next = array_shift(self::$do_action_results);
            return $next ?? ['success' => true, 'indeterminate' => false, 'data' => ['TradeNo' => 'ECPAY-RESP-9'], 'message' => ''];
        }

        public static function do_action_count(): int
        {
            return count(array_filter(self::$calls, static fn(array $c): bool => 'do_action' === $c[0]));
        }

        public static function query_count(): int
        {
            return count(array_filter(self::$calls, static fn(array $c): bool => 'query_trade' === $c[0]));
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

    $seed = static function (array $extra = [], ?array $core_entry = null): FakeWpdb {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $base = [
            'trade_no' => 'TN-1',
            'mer_trade_no' => 'YS7Tabc',
            'gwsr' => 'GW-1',
            'payment_type' => 'Credit_CreditCard',
            'ecpay_payment_type' => 'Credit_CreditCard',
            'ecpay_stage' => '0',
            'ecpay_red_dan' => '0',
            'ecpay_charged_amount' => 1000,
            'ecpay_environment' => 'live',
            'ecpay_merchant_id' => 'M1',
            '_ys_refund_finalization' => [
                'req-1' => null === $core_entry
                    ? ['gateway_id' => 'ys_ec_ecpay_credit', 'amount' => 1000, 'status' => 'submitting']
                    : $core_entry,
            ],
        ];
        $wpdb->value = json_encode(array_merge($base, $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        YSOrder::$total = 1000.0;
        YSOrder::$refunded = 0.0;
        Settings::$test_mode = false;
        EcpayPaymentClient::$calls = [];
        EcpayPaymentClient::$close = ['state' => 'closed', 'message' => ''];
        EcpayPaymentClient::$query = ['success' => true, 'data' => []];
        EcpayPaymentClient::$do_action_results = [];
        EcpayPaymentClient::$ledger_at_send = [];
        \YangSheep\Ecommerce\Utils\YSLogger::$errors = [];

        return $wpdb;
    };

    $refund = static function (float $amount = 1000.0, string $request_id = 'req-1'): array {
        $gateway = new EcpayCreditGateway();
        return $gateway->process_refund(7, $amount, '', ['refund_request_id' => $request_id]);
    };

    $ledger = static function (string $request_id = 'req-1'): array {
        global $wpdb;
        $detail = json_decode((string) $wpdb->value, true);
        return is_array($detail['_ys_ecpay_refunds'][$request_id] ?? null)
            ? $detail['_ys_ecpay_refunds'][$request_id]
            : [];
    };

    // ══ F1：核心 entry 的嚴格 schema ═════════════════════════════════════
    $seed([], ['gateway_id' => 'ys_ec_ecpay_credit', 'amount' => 1000, 'status' => 'submitting', 'finalized' => '0']);
    $r = $refund();
    $assert(
        !empty($r['success']) && 1 === EcpayPaymentClient::do_action_count(),
        '(a1) finalized="0"（字串零）是明確的 false → 正常放行'
    );

    $seed([], ['gateway_id' => 'ys_ec_ecpay_credit', 'amount' => 1000, 'status' => 'submitting', 'finalized' => true]);
    $r = $refund();
    $assert(
        empty($r['success']) && 0 === EcpayPaymentClient::do_action_count()
        && str_contains((string) ($r['message'] ?? ''), '已核定完成'),
        '(a2) finalized=true → 拒絕，且一次金流都不送'
    );

    $seed([], ['gateway_id' => 'ys_ec_ecpay_credit', 'amount' => 1000, 'status' => 'submitting', 'record_only' => 'no']);
    $r = $refund();
    $assert(
        empty($r['success']) && 0 === EcpayPaymentClient::do_action_count()
        && str_contains((string) ($r['message'] ?? ''), '無法解讀'),
        '(a3) 🔴 record_only="no" 無法解讀 → fail-closed（舊版 truthiness 會把它當成 true 或 false，兩種都是猜）'
    );

    $seed([], ['gateway_id' => 'ys_ec_ecpay_credit', 'amount' => '1000abc', 'status' => 'submitting']);
    $r = $refund();
    $assert(
        empty($r['success']) && 0 === EcpayPaymentClient::do_action_count(),
        '(a4) 🔴 amount="1000abc" → 拒絕（舊版 (float) 轉型會變成 1000.0 而通過核對）'
    );

    $seed([], ['gateway_id' => 'ys_ec_ecpay_credit', 'amount' => 1000.0, 'status' => 'submitting']);
    $r = $refund();
    $assert(
        !empty($r['success']) && 1 === EcpayPaymentClient::do_action_count(),
        '(a5) amount=1000.0（JSON 解碼常見的整數值 float）→ 接受'
    );

    // ══ F2／F6：指紋不可變、回應 ID 獨立 ══════════════════════════════════
    $seed();
    $r = $refund();
    $entry = $ledger();
    $assert(
        !empty($r['success'])
        && 'TN-1' === ($entry['trade_no'] ?? '')
        && 'ECPAY-RESP-9' === ($entry['response_trade_no'] ?? '')
        && 'ECPAY-RESP-9' === ($r['transaction_id'] ?? ''),
        '(b1) 🔴 指紋內的 trade_no 維持原值，綠界回應另存 response_trade_no'
    );

    $seed();
    EcpayPaymentClient::$do_action_results = [
        ['success' => true, 'indeterminate' => false, 'data' => ['TradeNo' => ''], 'message' => ''],
    ];
    $r = $refund();
    $entry = $ledger();
    $assert(
        empty($r['success'])
        && 'indeterminate' === ($r['outcome'] ?? '')
        && 'pending' === ($entry['status'] ?? ''),
        '(b2) 🔴 回應缺 TradeNo → indeterminate 並維持凍結（不得靜默退回成自己送出的 trade_no）'
    );

    // ══ F3：送出前的 durable token ════════════════════════════════════════
    $seed();
    $r = $refund();
    $at_send = EcpayPaymentClient::$ledger_at_send[0]['req-1'] ?? [];
    $assert(
        !empty($r['success'])
        && '' !== (string) ($at_send['operation_token'] ?? '')
        && 'R' === (string) ($at_send['pending_step'] ?? '')
        && '' !== (string) ($at_send['sent_at'] ?? ''),
        '(c1) 🔴 DoAction 送出的**當下**，ledger 已有 operation_token／pending_step／sent_at'
    );

    $seed();
    $wpdb->fail_write_containing = 'operation_token';
    $r = $refund();
    $assert(
        empty($r['success'])
        && 0 === EcpayPaymentClient::do_action_count()
        && str_contains((string) ($r['message'] ?? ''), '送出前'),
        '(c2) 送出前紀錄寫不進去 → 一次金流都不送（沒有可查證的依據就不動錢）'
    );

    // ══ F4：reserve 與終態之間，核心改派給別的 gateway ═══════════════════
    $seed();
    $wpdb->before_write = static function (FakeWpdb $db, string $sql): void {
        if (!str_contains($sql, "'status':'done'") && !str_contains($sql, '"status":"done"')) {
            return;
        }
        $db->before_write = null;
        $detail = json_decode((string) $db->value, true) ?: [];
        $detail['_ys_refund_finalization']['req-1']['gateway_id'] = 'ys_ec_shopline_credit';
        $db->value = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $r = $refund();
    $entry = $ledger();
    $assert(
        empty($r['success'])
        && 'indeterminate' === ($r['outcome'] ?? '')
        && 'pending' === ($entry['status'] ?? ''),
        '(d1) 🔴 期間核心改派給別的 gateway → 終態不得落盤（CAS 內以最新值重驗）'
    );

    // ══ F5：卡別證據集合不受 gwsr 快取影響 ════════════════════════════════
    $seed(); // gwsr 已快取
    $r = $refund();
    $assert(
        1 === EcpayPaymentClient::query_count(),
        '(e1) 🔴 即使 gwsr 已快取，QueryTradeInfo 一律執行（證據集合不能取決於無關的快取）'
    );

    $seed();
    EcpayPaymentClient::$query = ['success' => true, 'data' => ['gwsr' => 'GW-1', 'Stage' => '3']];
    $r = $refund();
    $assert(
        empty($r['success'])
        && 0 === EcpayPaymentClient::do_action_count()
        && str_contains((string) ($r['message'] ?? ''), '分期'),
        '(e2) 🔴 訂單說 stage=0、查詢說 Stage=3 → 判為分期並導向人工（舊版快取命中就看不到查詢）'
    );

    $seed();
    EcpayPaymentClient::$query = ['success' => false, 'message' => 'timeout', 'data' => null];
    $r = $refund();
    $assert(
        empty($r['success']) && 0 === EcpayPaymentClient::do_action_count(),
        '(e3) 查詢失敗＝證據不完整 → 拒絕，不送金流'
    );

    // ══ F7：CLI 只讀不寫 ══════════════════════════════════════════════════
    $cli = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/EcpayRefundAttemptCommand.php'));
    $resolve_start = strpos($cli, 'public function resolve(');
    $resolve_end = strpos($cli, 'public static function register_core_sync(');
    $resolve_body = substr($cli, (int) $resolve_start, (int) $resolve_end - (int) $resolve_start);

    $assert(
        false !== $resolve_start
        && !str_contains($resolve_body, 'OrderPaymentDetail::mutate')
        && !str_contains($resolve_body, "\$entry['status']      = \$mark;")
        && str_contains($resolve_body, 'wp ys-cart refund-finalization resolve')
        && str_contains($resolve_body, "array_key_exists( 'trade-no', \$assoc )"),
        '(f1) 🔴 CLI resolve 不再改動 ledger，且明確拒絕 --trade-no（指紋不得人工改寫）'
    );

    $assert(
        !str_contains($cli, "wp ys-ecpay refund-attempts resolve --order=' . \$order_id"),
        '(f2) 復原指引全部指向核心 CLI（單一入口）'
    );

    // ══ 負向：指紋欄位不得被任何 patch 寫入 ═══════════════════════════════
    $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Payment/EcpayCreditGateway.php'));

    // 註解裡會出現被修掉的舊寫法（那是說明用的）——只掃**程式碼行**，否則這條
    // 斷言會因為我們解釋了問題而變紅。
    $code_only = implode("\n", array_filter(
        explode("\n", $src),
        static function (string $line): bool {
            $t = ltrim($line);
            return '' !== $t && !str_starts_with($t, '//') && !str_starts_with($t, '*') && !str_starts_with($t, '/*');
        }
    ));

    $assert(
        str_contains($src, 'private const FINGERPRINT_KEYS')
        && str_contains($src, "\$forbidden = array_intersect( array_keys( \$patch ), self::FINGERPRINT_KEYS );")
        && !preg_match("/'trade_no'\s*=>\s*\\\$done_trade_no/", $code_only),
        '(g1) mark_attempt 攔截任何指紋欄位的寫入，終態不再改寫 trade_no'
    );

    echo "\nrefund hardening contract: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
