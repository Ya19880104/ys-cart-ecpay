<?php
/**
 * v021 — 退款授權的單一驗證器、查詢採信條件、終態 readback（CODEX #2E B7–B12）
 *
 * 六個缺口，共同的形狀是「同一個問題有好幾個寬鬆程度不同的答案」：
 *
 *   7.  「這筆退款可以送 DoAction 嗎」在三個地方各有一份判定：reservation 完整
 *       檢查、`mark_attempt()` 只看 gateway、core sync 只看 gateway 與金額。
 *       reserve 通過之後核心把請求核定、改派、改金額，後續完全看不出來。
 *       旗標缺欄位也被當成 false 放行——那不是「沒被設定」，是「無法證明」。
 *   8.  QueryTradeInfo 只看 `is_array( data )`：非 2xx、CheckMacValue 不符、
 *       「查無此交易」的錯誤回應全都帶著 populated data，於是一份無法證明來源
 *       （甚至不是這筆交易）的回應被拿來當退款依據。
 *   9.  core sync 拿 CAS **之外**的快照比對 gateway 與金額。
 *   10. `YSOrder::update()` 回 true 不代表寫進去了（affected=0 也是 true，而那
 *       可能是「訂單不存在」）。TradeNo 空字串也照寫。
 *   11. 終態冪等只比 status：一筆 `executed=E` 的 done 會讓 `executed=E,N` 的
 *       請求被當成「已經落盤過」。
 *   12. CLI 看不到 operation token、送出的步驟、RtnCode、商店與環境。
 *
 * 全部以**執行 production 程式碼**驗證，並以 DoAction 呼叫次數作為「有沒有真的
 * 送出金流」的量測。
 *
 * Run: php tests/regression/v021_refund_authorization_contract.php
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
        public static array $query = [
            'success' => true,
            'mac_verified' => true,
            'data' => ['MerchantTradeNo' => 'YS7Tabc', 'TradeNo' => 'TN-1'],
        ];
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
    require_once $core . 'YSPaymentDispatch.php';
    require_once dirname(__DIR__, 2) . '/src/Support/DetailWriteOutcome.php';
    require_once dirname(__DIR__, 2) . '/src/Support/OrderPaymentDetail.php';
    require_once dirname(__DIR__, 2) . '/src/Support/ScalarColumnWriter.php';
    require_once dirname(__DIR__, 2) . '/src/Payment/CoreRefundAuthorization.php';
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
                    ? ['gateway_id' => 'ys_ec_ecpay_credit', 'amount' => 1000, 'status' => 'submitting', 'finalized' => false, 'provider_done' => false, 'record_only' => false]
                    : $core_entry,
            ],
        ];
        $wpdb->value = json_encode(array_merge($base, $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        YSOrder::$total = 1000.0;
        YSOrder::$refunded = 0.0;
        Settings::$test_mode = false;
        EcpayPaymentClient::$calls = [];
        EcpayPaymentClient::$close = ['state' => 'closed', 'message' => ''];
        EcpayPaymentClient::$query = [
            'success' => true,
            'mac_verified' => true,
            'data' => ['MerchantTradeNo' => 'YS7Tabc', 'TradeNo' => 'TN-1'],
        ];
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

    use YangSheep\YSCartEcpay\Payment\CoreRefundAuthorization as Auth;

    $core_entry = static fn( array $over = [] ): array => array_merge( [
        'gateway_id'    => 'ys_ec_ecpay_credit',
        'status'        => 'submitting',
        'amount'        => 1000,
        'finalized'     => false,
        'provider_done' => false,
        'record_only'   => false,
    ], $over );

    // ══ B7：單一 validator ═══════════════════════════════════════════════
    $assert(
        null === Auth::problem( $core_entry(), 1000 ),
        '(a1) 完整且正確的 core entry → 通過'
    );

    // 超界字串不得飽和成 PHP_INT_MAX
    $assert(
        null === Auth::canonical_int( '999999999999999999999999' )
        && null === Auth::canonical_int( '1000abc' )
        && null === Auth::canonical_int( true )
        && 1000 === Auth::canonical_int( 1000 )
        && 1000 === Auth::canonical_int( '1000' ),
        '(a1b) 🔴 canonical_int：超界字串回 null（(int) 轉型會飽和成 PHP_INT_MAX）'
    );

    foreach ( [ 'finalized', 'provider_done', 'record_only' ] as $flag ) {
        $missing = $core_entry();
        unset( $missing[ $flag ] );
        $problem = Auth::problem( $missing, 1000 );
        $assert(
            is_array( $problem ) && 'core_flag_missing' === $problem['action'] && $flag === $problem['flag'],
            "(a2:{$flag}) 🔴 旗標**缺欄位** → 拒絕（缺欄位不是 false，是無法證明）"
        );
    }

    $assert(
        'core_flag_unreadable' === ( Auth::problem( $core_entry( [ 'record_only' => 'no' ] ), 1000 )['action'] ?? '' )
        && 'core_finalized' === ( Auth::problem( $core_entry( [ 'finalized' => true ] ), 1000 )['action'] ?? '' )
        // 🔴 #2F：**只**接受 exact bool。Core 產出的契約是 false／true；`'0'`
        // 不是它寫出來的形狀，接受它等於接受未知來源的值。
        && 'core_flag_unreadable' === ( Auth::problem( $core_entry( [ 'finalized' => '0' ] ), 1000 )['action'] ?? '' )
        && 'core_flag_unreadable' === ( Auth::problem( $core_entry( [ 'finalized' => 0 ] ), 1000 )['action'] ?? '' )
        && 'core_flag_unreadable' === ( Auth::problem( $core_entry( [ 'finalized' => 1 ] ), 1000 )['action'] ?? '' ),
        '(a3) 🔴 旗標只接受 exact bool：0、字串零、1 一律 fail-closed'
    );

    $assert(
        'core_amount_unreadable' === ( Auth::problem( $core_entry( [ 'amount' => '1000abc' ] ), 1000 )['action'] ?? '' )
        && 'core_amount_unreadable' === ( Auth::problem( $core_entry( [ 'amount' => true ] ), 1000 )['action'] ?? '' )
        // 🔴 #2F：只接受 Core 產出的 canonical 正整數；float／string 都不是。
        && 'core_amount_unreadable' === ( Auth::problem( $core_entry( [ 'amount' => 1000.0 ] ), 1000 )['action'] ?? '' )
        && 'core_amount_unreadable' === ( Auth::problem( $core_entry( [ 'amount' => '1000' ] ), 1000 )['action'] ?? '' )
        && 'core_amount_unreadable' === ( Auth::problem( $core_entry( [ 'amount' => 0 ] ), 0 )['action'] ?? '' )
        && 'core_amount_mismatch' === ( Auth::problem( $core_entry( [ 'amount' => 999 ] ), 1000 )['action'] ?? '' ),
        '(a4) 🔴 金額只接受 canonical 正整數（float／string／0 全部拒絕）'
    );

    $assert(
        'core_gateway_mismatch' === ( Auth::problem( $core_entry( [ 'gateway_id' => 'ys_ec_shopline_credit' ] ), 1000 )['action'] ?? '' )
        && 'core_not_submitting' === ( Auth::problem( $core_entry( [ 'status' => 'paid' ] ), 1000 )['action'] ?? '' )
        && 'no_core_request' === ( Auth::problem( null, 1000 )['action'] ?? '' ),
        '(a5) gateway／status／entry 不存在各自有可分辨的拒絕原因'
    );

    // 三個呼叫點共用同一份 validator（不得再各自寬鬆）
    $gateway_src = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Payment/EcpayCreditGateway.php' ) );
    $assert(
        2 === substr_count( $gateway_src, 'CoreRefundAuthorization::problem_in(' )
        && ! str_contains( $gateway_src, "'ys_ec_ecpay_credit' !== (string) ( \$core_entry['gateway_id'] ?? '' )" ),
        '(a6) reservation 與 mark_attempt 共用同一份 validator，無各自寬鬆的殘留'
    );

    // ══ B7 行為：reserve 之後核心改派 → 終態不得落盤 ═════════════════════
    $seed();
    $wpdb->before_write = static function ( FakeWpdb $db, string $sql ): void {
        // 只在**終態**寫入前插隊：response_trade_no 只出現在那一次。
        if ( ! str_contains( $sql, 'response_trade_no' ) ) {
            return;
        }
        $db->before_write = null;
        $detail = json_decode( (string) $db->value, true ) ?: [];
        $detail['_ys_refund_finalization']['req-1']['status'] = 'paid'; // core 已核定
        $db->value = json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    };
    $r     = $refund();
    $entry = $ledger();
    $assert(
        empty( $r['success'] )
        && 'indeterminate' === ( $r['outcome'] ?? '' )
        && 'pending' === ( $entry['status'] ?? '' ),
        '(b1) 🔴 reserve 之後核心把請求核定 → 終態不落盤、回 indeterminate'
    );

    $orphans = ( json_decode( (string) $wpdb->value, true ) ?: [] )['_ys_ecpay_orphan_facts']['req-1'] ?? [];
    $assert(
        is_array( $orphans ) && [] !== $orphans,
        '(b2) 🔴 失去授權時 provider 事實仍被保存（人工核定唯一的依據）'
    );

    // ══ B8：查詢採信條件 ═════════════════════════════════════════════════
    $bad_queries = [
        'success=false'      => [ 'success' => false, 'mac_verified' => true, 'data' => [ 'MerchantTradeNo' => 'YS7Tabc' ], 'message' => 'x' ],
        'mac 未驗'            => [ 'success' => true, 'mac_verified' => false, 'data' => [ 'MerchantTradeNo' => 'YS7Tabc' ] ],
        'MerchantTradeNo 不符' => [ 'success' => true, 'mac_verified' => true, 'data' => [ 'MerchantTradeNo' => 'OTHER' ] ],
        'TradeNo 不符'        => [ 'success' => true, 'mac_verified' => true, 'data' => [ 'MerchantTradeNo' => 'YS7Tabc', 'TradeNo' => 'TN-OTHER' ] ],
        'data 非陣列'         => [ 'success' => true, 'mac_verified' => true, 'data' => 'oops' ],
    ];

    $all_blocked = true;
    foreach ( $bad_queries as $label => $query ) {
        $seed();
        EcpayPaymentClient::$query = $query;
        $r = $refund();
        if ( ! empty( $r['success'] ) || EcpayPaymentClient::do_action_count() > 0 ) {
            $all_blocked = false;
            echo "        ↳ {$label} 未被擋下\n";
        }
    }
    $assert( $all_blocked, '(c1) 🔴 五種不可採信的查詢回應全部零 DoAction' );

    // ══ B10：typed scalar write ══════════════════════════════════════════
    $assert(
        'TN-1' === \YangSheep\YSCartEcpay\Support\ScalarColumnWriter::required_string( ' TN-1 ' )
        && null === \YangSheep\YSCartEcpay\Support\ScalarColumnWriter::required_string( '' )
        && null === \YangSheep\YSCartEcpay\Support\ScalarColumnWriter::required_string( '   ' )
        && null === \YangSheep\YSCartEcpay\Support\ScalarColumnWriter::required_string( 0 )
        && null === \YangSheep\YSCartEcpay\Support\ScalarColumnWriter::required_string( null ),
        '(d1) required_string：空字串／空白／非字串一律不是識別碼'
    );

    $controller = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Api/EcpayPaymentController.php' ) );
    $base       = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Payment/EcpayGatewayBase.php' ) );
    $logistics  = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Api/EcpayLogisticsController.php' ) );

    $assert(
        str_contains( $controller, 'ScalarColumnWriter::required_string(' )
        && str_contains( $controller, 'ScalarColumnWriter::is_persisted(' )
        && str_contains( $base, 'ScalarColumnWriter::is_persisted(' )
        && str_contains( $logistics, 'ScalarColumnWriter::is_persisted(' )
        && ! preg_match( "/if \( ! YSOrder::update\(/", $controller )
        && ! preg_match( "/if \( ! YSOrder::update\(/", $base )
        && ! preg_match( "/if \( ! YSOrder::update\(/", $logistics ),
        '(d2) 三個 scalar writer 全部改走 typed write／readback，無 `if ( ! YSOrder::update(` 殘留'
    );

    $writer_src = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/ScalarColumnWriter.php' ) );
    $assert(
        str_contains( $writer_src, "public const VERIFIED" )
        && ! str_contains( $writer_src, "public const UPDATED" )
        && str_contains( $writer_src, "public const MISSING" )
        && str_contains( $writer_src, "public const CONFLICT" )
        && str_contains( $writer_src, "public const DB_ERROR" )
        && str_contains( $writer_src, 'YSOrder::find( $order_id )' )
        && str_contains( $writer_src, "(string) \$actual[ \$column ] !== (string) \$expected" ),
        '(d3) affected=0 一律回頭讀一次；只有逐字元相符才算成功的 no-op'
    );

    // ══ B11：終態 exact readback ═════════════════════════════════════════
    $assert(
        str_contains( $gateway_src, 'self::patch_readback_matches( $entry, $patch )' )
        && str_contains( $gateway_src, 'private static function patch_readback_matches(' )
        && str_contains( $gateway_src, "\$entry[ \$key ] !== \$expected" ),
        '(e1) 終態冪等要求逐欄 readback（含 executed／response_trade_no）'
    );

    // 行為：🔴 #2G — 既有 done 的 executed 與本次計畫不同 → **不得**當成冪等
    //
    // 這一筆只執行了 `E`（取消關帳），本次的計畫是 `R`。指紋相符只證明「講的是
    // 同一筆交易」，不證明「該做的都做完了」。#2F 在這裡回成功，於是核心會把一筆
    // 只做了一半的退款結案。
    $seed( [ '_ys_ecpay_refunds' => [ 'req-1' => [
        'status'            => 'done',
        'plan'              => 'E,N',
        'executed'          => 'E',
        'response_trade_no' => 'ECPAY-OLD',
        'amount'            => 1000,
        'charged_amount'    => 1000,
        'trade_no'          => 'TN-1',
        'merchant_trade_no' => 'YS7Tabc',
        'gwsr'              => 'GW-1',
        'merchant_id'       => 'M1',
        'environment'       => 'live',
        'signature'         => 'x',
    ] ] ] );
    $r = $refund();
    $assert(
        empty( $r['success'] )
        && 'indeterminate' === ( $r['outcome'] ?? '' )
        && 0 === EcpayPaymentClient::do_action_count(),
        '(e2) 🔴 既有 done 但 executed ≠ plan → 不得冪等成功，且不送金流'
    );

    // 行為：完整的 done（plan／executed／operations／response_trade_no 齊備）
    // → 才是真正的冪等重放，而且回報的是**綠界確認的**交易編號。
    $seed( [ '_ys_ecpay_refunds' => [ 'req-1' => [
        'status'            => 'done',
        'plan'              => 'R',
        'executed'          => 'R',
        'operations'        => [
            [ 'step' => 'R', 'token' => 'op-1', 'sent_at' => '2026-08-12 00:00:00', 'result' => 'sent' ],
        ],
        'response_trade_no' => 'ECPAY-OK',
        'amount'            => 1000,
        'charged_amount'    => 1000,
        'trade_no'          => 'TN-1',
        'merchant_trade_no' => 'YS7Tabc',
        'gwsr'              => 'GW-1',
        'merchant_id'       => 'M1',
        'environment'       => 'live',
        'signature'         => 'x',
    ] ] ] );
    $r = $refund();
    $assert(
        ! empty( $r['success'] )
        && 'ECPAY-OK' === ( $r['transaction_id'] ?? '' )
        && 0 === EcpayPaymentClient::do_action_count(),
        '(e3) 完整的 done → 冪等重放，回報 response_trade_no（禁止 fallback 成 trade_no）'
    );

    // ══ B12：CLI forensic 欄位 ═══════════════════════════════════════════
    $cli = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Cli/EcpayRefundAttemptCommand.php' ) );
    $missing_fields = [];
    foreach ( [
        'operation_token', 'sent_at', 'pending_step', 'attempted_step', 'failed_step',
        'response_trade_no', 'rtn_code', 'rtn_msg', 'merchant_id', 'environment',
        'merchant_trade_no', 'gwsr', 'resolved_by', '_ys_ecpay_orphan_facts',
    ] as $field ) {
        if ( ! str_contains( $cli, $field ) ) {
            $missing_fields[] = $field;
        }
    }
    foreach ( $missing_fields as $field ) {
        echo "        ↳ CLI 未顯示 {$field}\n";
    }
    $assert( [] === $missing_fields, '(f1) CLI list 顯示完整鑑識資料' );

    // resolve 子命令本身不得寫入；on_core_resolved 是 core 核定的同步 listener，
    // 它**必須**寫（那是唯一被授權的寫入路徑），因此只掃 resolve 的方法本體。
    $resolve_start = strpos( $cli, 'public function resolve(' );
    $resolve_end   = strpos( $cli, 'public static function register_core_sync(' );
    $resolve_body  = substr( $cli, (int) $resolve_start, (int) $resolve_end - (int) $resolve_start );
    $assert(
        false !== $resolve_start
        && ! str_contains( $resolve_body, 'OrderPaymentDetail::mutate' )
        && str_contains( $resolve_body, 'wp ys-cart refund-finalization resolve' ),
        '(f2) CLI resolve 只讀不寫（核心 CLI 是唯一 writer）'
    );

    // ══ #2F：identity drift → DoAction = 0 ═══════════════════════════════
    //
    // 交易證據在 process_refund 開頭讀一次；CAS 重試時舊版完全沒有再看它們。
    // 期間 detail 被改寫（webhook 寫入另一筆交易、資料修復、換商店設定）之後，
    // 我們仍會以 A 的身分呼叫 DoAction。
    $drift_cases = [
        'trade_no'             => [ 'trade_no' => 'TN-OTHER' ],
        'mer_trade_no'         => [ 'mer_trade_no' => 'YS7Tzzz' ],
        'ecpay_merchant_id'    => [ 'ecpay_merchant_id' => 'M2' ],
        'ecpay_environment'    => [ 'ecpay_environment' => 'stage' ],
        'gwsr'                 => [ 'gwsr' => 'GW-OTHER' ],
        'ecpay_charged_amount' => [ 'ecpay_charged_amount' => 2000 ],
    ];

    $all_blocked = true;
    foreach ( $drift_cases as $label => $mutation ) {
        $seed();
        $wpdb->before_write = static function ( FakeWpdb $db, string $sql ) use ( $mutation ): void {
            // 在 reservation 落盤前把身分改掉（模擬併發改寫）。
            $db->before_write = null;
            $detail = json_decode( (string) $db->value, true ) ?: [];
            foreach ( $mutation as $k => $v ) {
                $detail[ $k ] = $v;
            }
            $db->value = json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        };

        $r = $refund();
        if ( ! empty( $r['success'] ) || EcpayPaymentClient::do_action_count() > 0 ) {
            $all_blocked = false;
            echo "        ↳ {$label} drift 未被擋下\n";
        }
    }
    $assert( $all_blocked, '(g1) 🔴 六種 identity drift 全部 DoAction=0' );

    // 不相關欄位的變動只會讓 CAS 落敗重試，不得被誤判成 drift
    $seed();
    $wpdb->before_write = static function ( FakeWpdb $db, string $sql ): void {
        $db->before_write = null;
        $detail = json_decode( (string) $db->value, true ) ?: [];
        $detail['_ys_shopline_refunds'] = [ 'other' => [ 'status' => 'pending' ] ];
        $db->value = json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    };
    $r = $refund();
    $assert(
        ! empty( $r['success'] ) && 1 === EcpayPaymentClient::do_action_count(),
        '(g2) 不相關欄位變動 → CAS 重試後仍然成功（不得被誤判成 drift）'
    );

    // ══ #2F：QueryTrade 缺 TradeNo ═══════════════════════════════════════
    $seed();
    EcpayPaymentClient::$query = [
        'success'      => true,
        'mac_verified' => true,
        'data'         => [ 'MerchantTradeNo' => 'YS7Tabc' ], // 缺 TradeNo
    ];
    $r = $refund();
    $assert(
        empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
        '(h1) 🔴 QueryTrade 缺 TradeNo → 拒絕（先前只在有值時比對）'
    );

    // ══ #2F：每步 operation append-only ══════════════════════════════════
    $seed();
    EcpayPaymentClient::$close = [ 'state' => 'to_close', 'message' => '' ];
    EcpayPaymentClient::$do_action_results = [
        [ 'success' => true, 'indeterminate' => false, 'data' => [ 'TradeNo' => 'ECPAY-E' ], 'message' => '' ],
        [ 'success' => true, 'indeterminate' => false, 'data' => [ 'TradeNo' => 'ECPAY-N' ], 'message' => '' ],
    ];
    $r     = $refund();
    $entry = $ledger();
    $ops   = is_array( $entry['operations'] ?? null ) ? $entry['operations'] : [];

    // 🔴 #2G：每一步都有**兩筆**事件——送出前的 `sent`，以及送出後不可變的
    // `result`。#2F 只有前者，於是「綠界回了什麼」只存在於 log 檔裡，而人工核定
    // 看的是訂單。
    $by_result = static function ( array $ops, string $kind ): array {
        return array_values( array_filter( $ops, static function ( $op ) use ( $kind ): bool {
            return is_array( $op ) && $kind === ( $op['result'] ?? '' );
        } ) );
    };
    $sent    = $by_result( $ops, 'sent' );
    $results = $by_result( $ops, 'result' );

    $assert(
        ! empty( $r['success'] )
        && 4 === count( $ops )
        && 2 === count( $sent )
        && 2 === count( $results )
        && 'E' === ( $sent[0]['step'] ?? '' )
        && 'N' === ( $sent[1]['step'] ?? '' )
        && ( $sent[0]['token'] ?? '' ) !== ( $sent[1]['token'] ?? '' ),
        '(i1) 🔴 E→N 每一步都 append sent＋result（舊版只留最後一步的 token）'
    );

    // (i2) result event 必須帶得出人工核定需要的全部事實。
    $missing_fields = [];
    foreach ( $results as $index => $event ) {
        foreach ( [ 'step', 'token', 'transport', 'attempted', 'executed', 'rtn_code', 'rtn_msg', 'response_trade_no', 'sent_at', 'digest', 'recorded_at' ] as $field ) {
            if ( ! array_key_exists( $field, $event ) ) {
                $missing_fields[] = $index . ':' . $field;
            }
        }
    }
    $assert(
        [] === $missing_fields
        && 'ok' === ( $results[0]['transport'] ?? '' )
        && 'ok' === ( $results[1]['transport'] ?? '' )
        && 'ECPAY-E' === ( $results[0]['response_trade_no'] ?? '' )
        && 'ECPAY-N' === ( $results[1]['response_trade_no'] ?? '' )
        // executed 是**這一步之前**已完成的步驟；attempted 含這一步。
        && '' === ( $results[0]['executed'] ?? 'x' )
        && 'E' === ( $results[0]['attempted'] ?? '' )
        && 'E' === ( $results[1]['executed'] ?? '' )
        && 'E,N' === ( $results[1]['attempted'] ?? '' )
        // 兩筆事件屬於同一個交易身分 → digest 相同
        && ( $results[0]['digest'] ?? 'a' ) === ( $results[1]['digest'] ?? 'b' ),
        '(i2) 🔴 result event 保存 token／step／attempted／executed／transport／RtnCode／RtnMsg／'
            . 'response id／digest／timestamp（缺：' . ( implode( ', ', $missing_fields ) ?: '無' ) . '）'
    );

    // (i3) 🔴 傳輸不確定與明確拒絕必須分成**不同的** transport 分類：
    //      前者不得重送（可能已生效），後者可以。
    $seed();
    EcpayPaymentClient::$close = [ 'state' => 'closed', 'message' => '' ];
    EcpayPaymentClient::$do_action_results = [
        [ 'success' => false, 'indeterminate' => true, 'data' => [], 'message' => 'timeout' ],
    ];
    $r   = $refund();
    $ops = is_array( $ledger()['operations'] ?? null ) ? $ledger()['operations'] : [];
    $ind = $by_result( $ops, 'result' );
    $assert(
        'indeterminate' === ( $r['outcome'] ?? '' )
        && 1 === count( $ind )
        && 'indeterminate' === ( $ind[0]['transport'] ?? '' )
        && 'timeout' === ( $ind[0]['rtn_msg'] ?? '' ),
        '(i3) 🔴 傳輸中斷記為 transport=indeterminate（與 rejected 分開）'
    );

    $seed();
    EcpayPaymentClient::$do_action_results = [
        [ 'success' => false, 'indeterminate' => false, 'data' => [ 'RtnCode' => '10200047' ], 'message' => '拒絕' ],
    ];
    $r   = $refund();
    $ops = is_array( $ledger()['operations'] ?? null ) ? $ledger()['operations'] : [];
    $rej = $by_result( $ops, 'result' );
    $assert(
        'rejected_terminal' === ( $r['outcome'] ?? '' )
        && 1 === count( $rej )
        && 'rejected' === ( $rej[0]['transport'] ?? '' )
        && '10200047' === ( $rej[0]['rtn_code'] ?? '' ),
        '(i4) 🔴 明確拒絕記為 transport=rejected，並保存 RtnCode'
    );

    // ══ #2F：post-send 失敗一律 append orphan fact ═══════════════════════
    $cli_src = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Cli/EcpayRefundAttemptCommand.php' ) );
    $gw_src  = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Payment/EcpayCreditGateway.php' ) );

    $assert(
        str_contains( $gw_src, "self::record_orphan_facts( \$order_id, \$request_id, array_merge( \$patch," ),
        '(j1) note_attempt 寫入失敗時把完整 patch 存成 orphan fact（不只 log）'
    );

    // ══ #2F：core sync 接受 Core 真正的狀態 ══════════════════════════════
    $assert(
        str_contains( $cli_src, "'provider_done'" )
        && str_contains( $cli_src, "'aborted_provider_rejected'" )
        && str_contains( $cli_src, "'paid' === \$mark" ),
        '(k1) 🔴 core sync 接受 provider_done／aborted_provider_rejected（否則人工核定必然被拒）'
    );

    // ══ #2F：CLI 三來源聯集 + corrupt JSON 明確報錯 ══════════════════════
    $assert(
        str_contains( $cli_src, '$request_ids = array_values( array_unique( array_merge(' )
        && str_contains( $cli_src, '$orphan_ids' )
        && str_contains( $cli_src, '$core_ledger_ids' )
        && str_contains( $cli_src, 'payment_detail 無法解析為 JSON' )
        && str_contains( $cli_src, "\$operations = is_array( \$entry['operations'] ?? null )" ),
        '(k2) CLI 以 provider／core／orphan 聯集輸出、corrupt JSON 明確報錯、每步歷史可見'
    );

    // ══ #2G：身分必須 typed-present——gwsr 不得消失 ═══════════════════════
    //
    // #2F 的 identity_drift 對 gwsr 有一條例外：「當下缺值不算漂移」。於是期間有人
    // 把 gwsr 刪掉、或資料修復把欄位清空，比對就自動跳過，DoAction 照送。
    $seed();
    $wpdb->before_write = static function ( FakeWpdb $db, string $sql ): void {
        if ( ! str_contains( $sql, '_ys_ecpay_refunds' ) ) {
            return;
        }
        $db->before_write = null;
        $detail = json_decode( (string) $db->value, true ) ?: [];
        unset( $detail['gwsr'], $detail['ecpay_gwsr'] ); // 有人把 gwsr 刪了
        $db->value = json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    };
    $r = $refund();
    $assert(
        empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
        '(j1) 🔴 gwsr 在準備期間消失 → identity drift，DoAction 呼叫次數 0'
    );

    // 其他身分欄位同樣不得消失
    foreach ( [ 'trade_no', 'mer_trade_no', 'ecpay_merchant_id', 'ecpay_environment' ] as $field ) {
        $seed();
        $wpdb->before_write = static function ( FakeWpdb $db, string $sql ) use ( $field ): void {
            if ( ! str_contains( $sql, '_ys_ecpay_refunds' ) ) {
                return;
            }
            $db->before_write = null;
            $detail = json_decode( (string) $db->value, true ) ?: [];
            unset( $detail[ $field ] );
            $db->value = json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        };
        $r = $refund();
        $assert(
            empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
            '(j2:' . $field . ') 🔴 身分欄位消失 → 拒絕，DoAction 0'
        );
    }

    // 型別不對（null／數字）也算缺
    $seed( [ 'gwsr' => 0 ] );
    $r = $refund();
    $assert(
        empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
        '(j3) 🔴 身分欄位型別不是非空字串 → 拒絕（`(string)` 轉型會把 0 變成看得下去的值）'
    );

    // ══ #2G：授權身分用 raw bytes 比較，不做 trim ═════════════════════════
    //
    // MAC 驗證可以依綠界的正規化規則；但「這份回應講的是不是我送出去的那一筆」
    // 不能靠正規化拉近。padding 只可能來自兩件事：回應不是我們以為的那一筆，
    // 或中間有東西改過它。
    $seed();
    EcpayPaymentClient::$query = [
        'success'      => true,
        'mac_verified' => true,
        'data'         => [ 'MerchantTradeNo' => ' YS7Tabc', 'TradeNo' => 'TN-1' ],
    ];
    $r = $refund();
    $assert(
        empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
        '(k1) 🔴 MerchantTradeNo 前後有空白 → 拒絕（不得 trim 之後視為相符）'
    );

    $seed();
    EcpayPaymentClient::$query = [
        'success'      => true,
        'mac_verified' => true,
        'data'         => [ 'MerchantTradeNo' => 'YS7Tabc', 'TradeNo' => "TN-1\n" ],
    ];
    $r = $refund();
    $assert(
        empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
        '(k2) 🔴 TradeNo 帶換行 → 拒絕'
    );

    // 正常（逐位元相符）仍要放行，否則這道 gate 就是壞的
    $seed();
    $r = $refund();
    $assert(
        ! empty( $r['success'] ) && 1 === EcpayPaymentClient::do_action_count(),
        '(k3) 逐位元相符 → 正常放行（gate 不是一律否決）'
    );

    // ══ #2G：charged_amount 禁止 string-cast alias ════════════════════════
    $seed( [ 'ecpay_charged_amount' => '1000abc' ] );
    $r = $refund();
    $assert(
        empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
        '(l1) 🔴 請款金額為 "1000abc" → 拒絕（`(int)` 會把它變成 1000）'
    );

    $seed( [ 'ecpay_charged_amount' => '1e3' ] );
    $r = $refund();
    $assert(
        empty( $r['success'] ) && 0 === EcpayPaymentClient::do_action_count(),
        '(l2) 🔴 請款金額為 "1e3" → 拒絕（`(int)` 會把它變成 1）'
    );

    $seed( [ 'ecpay_charged_amount' => '1000' ] );
    $r = $refund();
    $assert(
        ! empty( $r['success'] ),
        '(l3) canonical 整數字串 "1000" 仍可解讀（不是一律否決）'
    );

    echo "\nrefund authorization contract: {$pass} PASS / {$fail} FAIL\n";
    exit( $fail > 0 ? 1 : 0 );
}
