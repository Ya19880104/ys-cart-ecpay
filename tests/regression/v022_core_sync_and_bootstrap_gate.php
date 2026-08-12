<?php
/**
 * v0.3.0（#2G）— 核心核定同步的 terminal tuple ＋ bootstrap 版本 gate
 *
 * ## 為什麼同步只能接受 Core 實際寫得出來的那一個 tuple
 *
 * #2F 為了解開「provider ledger 永遠留在 pending」的死結，把接受清單放寬成一整排
 * 狀態（paid／submitting／provider_done…）。那太寬：
 *
 *   - `submitting` 代表核定**還沒完成**。這時候把 provider ledger 標成終態，等於
 *     在核心還沒下結論之前就解除凍結。
 *   - `paid`／`failed`／`aborted` 這些名稱核心根本不會寫進 `status`——接受它們只是
 *     接受一份不知道從哪來的值。
 *
 * 核心的兩個終態是**成對**的（見 `YSRefundHandler::resolve_finalization()`）：
 *
 *   paid    → status = 'provider_done'             ＋ provider_done === true
 *   aborted → status = 'aborted_provider_rejected' ＋ finalized === true
 *
 * 狀態對了而旗標沒設起來，代表核定寫入只完成一半——那時候同步會讓兩套帳本永久
 * 互相矛盾。
 *
 * ## 為什麼 bootstrap 要有版本 gate
 *
 * 本外掛沒有自己的 payment_detail 寫入器，全部走核心 2.57.0 的
 * `YSPaymentDetailStore`，並依賴 `YSPaymentDispatch` 的 ambient guard。核心太舊時
 * 註冊 gateway 只會得到一個「收得到錢、寫不了帳」的 provider——比明顯缺席危險得多。
 *
 * Run: php tests/regression/v022_core_sync_and_bootstrap_gate.php
 */

declare(strict_types=1);

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ );
    }

    function wp_json_encode( $data ) {
        return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    function current_time( string $type ): string {
        return '2026-08-12 00:00:00';
    }

    function apply_filters( string $tag, $value, ...$rest ) {
        return $value;
    }

    function sanitize_text_field( $v ): string {
        return trim( strip_tags( (string) $v ) );
    }

    function esc_html( $v ): string {
        return htmlspecialchars( (string) $v, ENT_QUOTES );
    }

    function esc_html__( string $t, string $d = '' ): string {
        return $t;
    }

    function __( string $t, string $d = '' ): string {
        return $t;
    }

    /** 記錄 init() 期間註冊了哪些 hook。 */
    $GLOBALS['ys_hooks'] = [];

    function add_action( string $tag, $cb = null, int $p = 10, int $n = 1 ): void {
        $GLOBALS['ys_hooks'][] = [ 'action', $tag ];
    }

    function add_filter( string $tag, $cb = null, int $p = 10, int $n = 1 ): void {
        $GLOBALS['ys_hooks'][] = [ 'filter', $tag ];
    }

    final class SyncFakeWpdb {
        public string $prefix     = 'wp_';
        public string $last_error = '';
        public string|null|false $value = '{}';

        public function prepare( string $sql, ...$args ): string {
            foreach ( $args as $a ) {
                $rep = is_int( $a ) ? (string) $a : "'" . str_replace( "'", "''", (string) $a ) . "'";
                $sql = preg_replace( '/%[ds]/', $rep, $sql, 1 ) ?? $sql;
            }
            return $sql;
        }

        public function get_row( string $sql ) {
            return (object) [ 'payment_detail' => $this->value ];
        }

        public function query( string $sql ) {
            $where = strrpos( $sql, ' WHERE ' );
            $tail  = false === $where ? '' : substr( $sql, $where );

            if ( preg_match( "/AND payment_detail = '(.*)'\\s*$/s", $tail, $w ) ) {
                if ( str_replace( "''", "'", $w[1] ) !== (string) $this->value ) {
                    return 0;
                }
            }
            if ( ! preg_match( "/SET payment_detail = '(.*?)', updated_at = /s", $sql, $m ) ) {
                return 0;
            }

            $this->value = str_replace( "''", "'", $m[1] );
            return 1;
        }
    }
}

namespace YangSheep\Ecommerce\Models {
    class YSOrder {
        public static function table(): string {
            return 'wp_ys_ec_orders';
        }
        public static function forget( int $id ): void {}
        public static function find( int $id ): ?object {
            global $wpdb;
            return (object) [ 'id' => $id, 'payment_detail' => $wpdb->value ];
        }
    }
}

namespace YangSheep\Ecommerce\Utils {
    class YSLogger {
        public static function error( string $c, string $m, array $x = [] ): void {}
        public static function warning( string $c, string $m, array $x = [] ): void {}
        public static function info( string $c, string $m, array $x = [] ): void {}
    }
}

namespace {
    $core = dirname( __DIR__, 3 ) . '/ys-cart/src/Services/Payment/';
    require_once $core . 'YSPaymentDetailResult.php';
    require_once $core . 'YSPaymentDispatch.php';
    require_once $core . 'YSPaymentDetailStore.php';
    require_once dirname( __DIR__, 2 ) . '/src/Support/DetailWriteOutcome.php';
    require_once dirname( __DIR__, 2 ) . '/src/Support/OrderPaymentDetail.php';
    require_once dirname( __DIR__, 2 ) . '/src/Payment/CoreRefundAuthorization.php';
    require_once dirname( __DIR__, 2 ) . '/src/Cli/EcpayRefundAttemptCommand.php';

    use YangSheep\YSCartEcpay\Cli\EcpayRefundAttemptCommand as Sync;

    $pass   = 0;
    $fail   = 0;
    $assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
        if ( $ok ) {
            ++$pass;
            echo "  PASS  {$label}\n";
            return;
        }
        ++$fail;
        echo "  FAIL  {$label}\n";
    };

    $fingerprint = [
        'amount'            => 1000,
        'charged_amount'    => 1000,
        'trade_no'          => 'TN-1',
        'merchant_trade_no' => 'YS7Tabc',
        'gwsr'              => 'GW-1',
        'merchant_id'       => 'M1',
        'environment'       => 'live',
    ];

    /** 造出「provider attempt 仍 pending、core entry 為指定形狀」的訂單。 */
    $seed = static function ( array $core_entry ) use ( $fingerprint ): void {
        global $wpdb;
        $wpdb = new SyncFakeWpdb();
        $wpdb->value = wp_json_encode( [
            'trade_no'                => 'TN-1',
            'mer_trade_no'            => 'YS7Tabc',
            'gwsr'                    => 'GW-1',
            'ecpay_merchant_id'       => 'M1',
            'ecpay_environment'       => 'live',
            'ecpay_charged_amount'    => 1000,
            '_ys_ecpay_refunds'       => [
                'req-1' => array_merge( $fingerprint, [ 'status' => 'pending' ] ),
            ],
            '_ys_refund_finalization' => [ 'req-1' => $core_entry ],
        ] );
    };

    $status_of = static function (): string {
        global $wpdb;
        $d = json_decode( (string) $wpdb->value, true ) ?: [];
        return (string) ( $d['_ys_ecpay_refunds']['req-1']['status'] ?? '?' );
    };

    $snapshot = [ 'gateway_id' => 'ys_ec_ecpay_credit' ];

    // ── (a) paid 的**唯一**合法 tuple ──────────────────────────────────────
    $seed( [
        'gateway_id'    => 'ys_ec_ecpay_credit',
        'amount'        => 1000,
        'status'        => 'provider_done',
        'provider_done' => true,
        'finalized'     => false,
        'record_only'   => false,
    ] );
    $r = Sync::on_core_resolved( [], 7, 'req-1', 'paid', $snapshot );
    $assert(
        1 === count( $r ) && ! empty( $r[0]['success'] ) && 'done' === $status_of(),
        '(a) paid + provider_done(status) + provider_done(flag)=true → 同步為 done'
    );

    // ── (b) 🔴 status 對但旗標沒設起來 → 拒絕 ──────────────────────────────
    $seed( [
        'gateway_id'    => 'ys_ec_ecpay_credit',
        'amount'        => 1000,
        'status'        => 'provider_done',
        'provider_done' => false, // 核定寫入只完成一半
        'finalized'     => false,
        'record_only'   => false,
    ] );
    $r = Sync::on_core_resolved( [], 7, 'req-1', 'paid', $snapshot );
    $assert(
        1 === count( $r ) && empty( $r[0]['success'] ) && 'pending' === $status_of(),
        '(b) 🔴 status=provider_done 但旗標 false → 拒絕同步（核定只完成一半）'
    );

    // ── (c) 🔴 submitting 不再被接受（核定還沒完成）────────────────────────
    foreach ( [ 'submitting', 'paid', 'provider_pending', '' ] as $bad_status ) {
        $seed( [
            'gateway_id'    => 'ys_ec_ecpay_credit',
            'amount'        => 1000,
            'status'        => $bad_status,
            'provider_done' => true,
            'finalized'     => false,
            'record_only'   => false,
        ] );
        $r = Sync::on_core_resolved( [], 7, 'req-1', 'paid', $snapshot );
        $assert(
            1 === count( $r ) && empty( $r[0]['success'] ) && 'pending' === $status_of(),
            '(c:' . ( '' === $bad_status ? '(空)' : $bad_status ) . ') 🔴 非 provider_done 的狀態一律拒絕'
        );
    }

    // ── (d) aborted 的**唯一**合法 tuple ───────────────────────────────────
    $seed( [
        'gateway_id'    => 'ys_ec_ecpay_credit',
        'amount'        => 1000,
        'status'        => 'aborted_provider_rejected',
        'finalized'     => true,
        'provider_done' => false,
        'record_only'   => false,
    ] );
    $r = Sync::on_core_resolved( [], 7, 'req-1', 'aborted', $snapshot );
    $assert(
        1 === count( $r ) && ! empty( $r[0]['success'] ) && 'failed' === $status_of(),
        '(d) aborted + aborted_provider_rejected + finalized=true → 同步為 failed'
    );

    // ── (e) 🔴 aborted 但 finalized 沒設 → 拒絕 ────────────────────────────
    $seed( [
        'gateway_id'    => 'ys_ec_ecpay_credit',
        'amount'        => 1000,
        'status'        => 'aborted_provider_rejected',
        'finalized'     => false,
        'provider_done' => false,
        'record_only'   => false,
    ] );
    $r = Sync::on_core_resolved( [], 7, 'req-1', 'aborted', $snapshot );
    $assert(
        1 === count( $r ) && empty( $r[0]['success'] ) && 'pending' === $status_of(),
        '(e) 🔴 aborted 但 finalized 沒設起來 → 拒絕同步'
    );

    // ── (f) 🔴 aborted 卻同時 provider_done=true → 兩個事實矛盾，拒絕 ───────
    $seed( [
        'gateway_id'    => 'ys_ec_ecpay_credit',
        'amount'        => 1000,
        'status'        => 'aborted_provider_rejected',
        'finalized'     => true,
        'provider_done' => true, // 說沒退成，又說 provider 完成了
        'record_only'   => false,
    ] );
    $r = Sync::on_core_resolved( [], 7, 'req-1', 'aborted', $snapshot );
    $assert(
        1 === count( $r ) && empty( $r[0]['success'] ) && 'pending' === $status_of(),
        '(f) 🔴 aborted 卻標著 provider_done → 互相矛盾，拒絕同步'
    );

    // ── (g) 旗標型別必須是 exact bool（1／"1" 不算）─────────────────────────
    $seed( [
        'gateway_id'    => 'ys_ec_ecpay_credit',
        'amount'        => 1000,
        'status'        => 'provider_done',
        'provider_done' => 1, // 不是 true
        'finalized'     => false,
        'record_only'   => false,
    ] );
    $r = Sync::on_core_resolved( [], 7, 'req-1', 'paid', $snapshot );
    $assert(
        1 === count( $r ) && empty( $r[0]['success'] ) && 'pending' === $status_of(),
        '(g) 🔴 旗標是 1 而不是 true → 拒絕（型別敏感）'
    );

    // ══ bootstrap 版本 gate ═════════════════════════════════════════════════
    require_once dirname( __DIR__, 2 ) . '/src/Plugin.php';

    use YangSheep\YSCartEcpay\Plugin;

    $assert(
        '2.57.0' === Plugin::REQUIRES_CORE,
        '(h) 宣告的最低核心版本是 2.57.0'
    );

    // 核心常數不存在（外掛單獨啟用、或核心未載入）
    $assert(
        ! defined( 'YS_ECOMMERCE_VERSION' ) && false === Plugin::core_version_ok(),
        '(h2) 核心版本常數缺席 → 不滿足（不得當成「可能沒問題」）'
    );

    // init() 在版本不符時只掛通知，其餘一律不註冊
    $GLOBALS['ys_hooks'] = [];
    Plugin::instance()->init();
    $tags = array_map( static fn( array $h ): string => $h[1], $GLOBALS['ys_hooks'] );

    $assert(
        [ 'admin_notices' ] === $tags,
        '(h3) 🔴 版本不符 → 只掛 admin_notices，gateway／物流／REST／CLI 一律不註冊 — 實得 '
            . json_encode( $tags )
    );

    // 通知內容要說得出「需要什麼」與「現在是什麼」
    ob_start();
    Plugin::instance()->render_core_version_notice();
    $notice = (string) ob_get_clean();

    $assert(
        str_contains( $notice, 'notice-error' )
        && str_contains( $notice, '2.57.0' ),
        '(h4) 後台通知帶出所需版本'
    );

    // 版本足夠時放行（用常數模擬已載入的核心）
    define( 'YS_ECOMMERCE_VERSION', '2.57.0' );
    $assert( true === Plugin::core_version_ok(), '(h5) 核心 2.57.0 → 放行' );

    echo "\ncore sync tuple + bootstrap gate: {$pass} PASS / {$fail} FAIL\n";
    exit( $fail > 0 ? 1 : 0 );
}
