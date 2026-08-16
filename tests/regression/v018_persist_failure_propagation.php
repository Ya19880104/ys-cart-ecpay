<?php
/**
 * v018 — 持久化失敗必須向呼叫端傳播（v0.3.0）
 *
 * 缺口：payment_detail 的寫入失敗被靜默忽略，流程照常往下走：
 *   1. `EcpayGatewayBase::process_payment()` 寫不進 `mer_trade_no` 仍然把付款表單
 *      交給使用者。`mer_trade_no` 是這筆交易與綠界之間唯一的對應鍵——付款通知靠它
 *      找回訂單、退款靠它送 DoAction。消費者付了錢，我們沒有任何欄位認得回來。
 *   2. 付款通知寫不進 `gwsr` 仍然回 `1|OK`。回 `1|OK` 代表「收到並處理完畢」，綠界
 *      就此停止重送——那個欄位永久遺失，整條退款路徑失去判定 E／N／R 的依據。
 *   3. 物流 callback 同理：狀態沒落盤卻回 `1|OK`，這次狀態變更再也不會重送。
 *
 * 三者共同的形狀是「副作用已對外承諾，內部狀態卻沒落盤」。本檔以**執行 production
 * 方法**驗證：寫入失敗時，對外承諾必須收回。
 *
 * Run: php tests/regression/v018_persist_failure_propagation.php
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__);
    }
    if (!defined('YS_ECOMMERCE_TABLE_PREFIX')) {
        define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');
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


    /** 寫入永遠失敗的 wpdb（模擬 SQL 錯誤）。 */
    final class FailingWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public string|null|false $value = '{"a":1}';
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
            return (object) ['payment_detail' => $this->value];
        }

        public function query(string $sql)
        {
            ++$this->updates;
            $this->last_error = 'simulated SQL failure';
            return false;
        }

        public function get_var(string $sql)
        {
            return null;
        }

        public function update(...$args)
        {
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
        public static function table(): string { return 'wp_ys_ec_orders'; }
        public static function forget(int $id): void {}
        public static float $total = 1000.0;

        public static function find(int $id): ?object
        {
            global $wpdb;
            return (object) ['id' => $id, 'total' => self::$total, 'payment_detail' => $wpdb->value, 'tracking_number' => ''];
        }
        public static function update(int $id, array $data): bool { return true; }
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
        public static function payment_credentials(): array
        {
            return ['merchant_id' => 'M1', 'hash_key' => 'K', 'hash_iv' => 'I', 'test_mode' => false];
        }
        public static function has_payment_credentials(): bool { return true; }
        public static function gateway_enabled(string $k): bool { return true; }
    }
}

namespace YangSheep\YSCartEcpay\Payment {
    /** 若 process_payment 走到這裡，代表守門失效——付款表單已經被簽發。 */
    class EcpayPaymentClient
    {
        public static int $built = 0;

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

        public function build_aio_form(object $order, string $mtn, string $choose): array
        {
            // 與 production 同契約：非 canonical TWD 正整數在**計數之前**就拋例外，
            // 否則這個替身會比真實實作寬鬆，測到的就不是真的行為。
            if (!self::is_canonical_twd($order->total ?? null)) {
                throw new \InvalidArgumentException('訂單金額必須為正整數新台幣。');
            }
            ++self::$built;
            return ['action_url' => 'https://example.test/pay', 'charged_amount' => (int) $order->total];
        }
    }
}

namespace {
    $core = dirname(__DIR__, 3) . '/ys-cart/src/Services/Payment/';
    require_once $core . 'YSPaymentDetailResult.php';
    require_once $core . 'YSPaymentDetailStore.php';
    require_once $core . 'YSPaymentDispatch.php';
    require_once dirname(__DIR__, 2) . '/src/Support/DetailWriteOutcome.php';
    require_once dirname(__DIR__, 2) . '/src/Support/OrderPaymentDetail.php';
    require_once dirname(__DIR__, 2) . '/src/Payment/EcpayGatewayBase.php';
}

namespace YangSheep\YSCartEcpay\Payment {
    /** 具體子類必須在 base class 載入**之後**宣告——PHP 的 namespace block 依序執行。 */
    final class EcpayTestGateway extends EcpayGatewayBase
    {
        public function get_id(): string { return 'ys_ec_ecpay_credit'; }
        public function get_title(): string { return 'test'; }
        protected function gateway_key(): string { return 'credit'; }
        protected function choose_payment(): string { return 'Credit'; }
    }
}

namespace {

    use YangSheep\YSCartEcpay\Payment\EcpayPaymentClient;
    use YangSheep\YSCartEcpay\Payment\EcpayTestGateway;

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

    // (a) 建單：payment_detail 寫入失敗 → 不得簽發付款表單
    //
    // v0.3.0 起 build_aio_form() 會在 CAS 之前被呼叫（要先算出 canonical 金額才能
    // 連同環境／商店身分一起持久化），因此「有沒有建表單」不再是可用的觀測點——
    // 改為斷言**回傳值不含表單**：呼叫端拿不到 form_data／redirect_url 就無法把
    // 使用者送去付款。
    global $wpdb;
    $wpdb = new FailingWpdb();
    EcpayPaymentClient::$built = 0;
    $result = (new EcpayTestGateway())->process_payment(7);
    $assert(
        false === ($result['success'] ?? true)
        && ! isset($result['form_data'])
        && ! isset($result['redirect_url']),
        '(a) 建單 payment_detail 寫入失敗 → 回 success=false，且回傳值不含付款表單／導轉網址'
    );

    // (a3) 金額非 canonical TWD 正整數 → 連表單都不建立
    $wpdb = new FailingWpdb();
    EcpayPaymentClient::$built = 0;
    \YangSheep\Ecommerce\Models\YSOrder::$total = 1000.5;
    $bad = (new EcpayTestGateway())->process_payment(7);
    \YangSheep\Ecommerce\Models\YSOrder::$total = 1000.0;
    $assert(
        false === ($bad['success'] ?? true) && 0 === EcpayPaymentClient::$built,
        '(a3) 訂單金額為小數 → 拒絕建單，且完全沒有呼叫 build_aio_form'
    );

    $assert(
        [] !== \YangSheep\Ecommerce\Utils\YSLogger::$errors,
        '(a2) 失敗有留下可觀測的 CRITICAL 紀錄'
    );

    // (b)(c) webhook／物流 callback 的 ACK 契約（原始碼順序契約）
    //
    // 這兩個進入點會 `exit`（respond_text），無法在同一個行程內執行到底而不中斷測試，
    // 因此以「寫入結果的判定必須出現在 ACK 之前，且失敗分支回非 1|OK」為契約。
    // 對應的行為面由 (a) 與 v015 涵蓋（同一個 OrderPaymentDetail 回傳型別）。
    $payment = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Api/EcpayPaymentController.php'));
    $pos_check = strpos($payment, '$gwsr_written->is_persisted()');
    $pos_fail  = strpos($payment, "'0|Persist Failed'");
    $pos_ok    = strpos($payment, "respond_text( '1|OK' )");
    $assert(
        false !== $pos_check && false !== $pos_fail && false !== $pos_ok
        && $pos_check < $pos_ok && $pos_fail < $pos_ok,
        '(b) 付款通知：gwsr 寫入結果先判定，失敗回 0|Persist Failed 且早於任何 1|OK'
    );

    $assert(
        str_contains($payment, '$transition = YSPaymentLifecycleService::mark_paid(')
        && str_contains($payment, "empty( \$transition['success'] )"),
        '(b2) 付款通知：生命週期推進結果也必須判定（推進失敗同樣不得 ACK）'
    );

    // 合流後（0.2.16 main）：callback 走 label-bound 序列化閉包——
    // update_order_shipping 多收 $label、失敗回 `0|Persistence failed`（503），
    // 最終成功 ACK 是閉包內最後一個 `'1|OK', 'status' => 200`。語意不變：
    // persist gate 與失敗回覆都必須先於最終 ACK。
    $logistics = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Api/EcpayLogisticsController.php'));
    $pos_lcheck = strpos($logistics, '! $this->update_order_shipping( $order, $params, $locked_label )');
    $pos_lfail  = strpos($logistics, "'0|Persistence failed'");
    $pos_lok    = strrpos($logistics, "'body' => '1|OK'"); // 最終成功 ACK（最後一次出現）
    $assert(
        false !== $pos_lcheck && false !== $pos_lfail && false !== $pos_lok
        && $pos_lcheck < $pos_lok && $pos_lfail < $pos_lok
        && str_contains($logistics, 'private function update_order_shipping( object $order, array $params, object $label ): bool'),
        '(c) 物流 callback：update_order_shipping 回 bool，失敗回 0|Persistence failed 且早於最終 1|OK'
    );

    // (d) 負向：三個進入點都不得再出現「忽略回傳值」的呼叫形態
    $base = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Payment/EcpayGatewayBase.php'));
    $assert(
        1 === preg_match('/\$persisted\s*=\s*OrderPaymentDetail::mutate\(/', $base)
        && 0 === preg_match('/^\s*OrderPaymentDetail::mutate\(/m', $base),
        '(d) GatewayBase 不得再有捨棄回傳值的 OrderPaymentDetail::mutate() 呼叫'
    );

    $assert(
        0 === preg_match('/^\s*OrderPaymentDetail::mutate\(/m', $payment)
        && 0 === preg_match('/^\s*OrderPaymentDetail::mutate\(/m', $logistics),
        '(d2) 兩個 webhook 控制器同樣不得捨棄寫入結果'
    );

    echo "\npersist failure propagation: {$pass} PASS / {$fail} FAIL\n";
    exit($fail > 0 ? 1 : 0);
}
