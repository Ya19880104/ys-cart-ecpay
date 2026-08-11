<?php
/**
 * 核心退款授權的**唯一**驗證器
 *
 * 「這筆退款可以送 DoAction 嗎」是一個問題，先前卻有三個各自不同的答案：
 *
 *   - `process_refund()` 的 reservation closure —— 完整檢查
 *   - `mark_attempt()` 的 CAS closure          —— 只檢查「entry 存在且 gateway 相同」
 *   - `on_core_resolved()` 的 sync closure     —— 只檢查 gateway 與金額
 *
 * 於是 reserve 通過之後，核心把請求核定完成、改派給別的 gateway、或把金額改掉，
 * 後續的 arm／終態寫入完全看不出來——DoAction 照送、終態照落。
 *
 * 這個類別把判定收斂成一份，三個地方共用。所有判定都是**型別敏感**的：
 *
 *   - `gateway_id` 必須逐字元相符
 *   - `status` 必須恰好是 `submitting`
 *   - `amount` 必須是 canonical 整數（TWD 無小數）
 *   - `finalized`／`provider_done`／`record_only` 必須**存在且為 false**
 *
 * 🔴 最後一條是刻意的：缺欄位不是「沒有被設定」，而是「我們無法證明它是 false」。
 * 舊版把缺欄位當成 false 放行——legacy entry、被部分寫入的 entry、被其他外掛改
 * 過的 entry 全都會通過。退款是不可逆的，證明不了就不做。
 *
 * @package YangSheep\YSCartEcpay\Payment
 */

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

final class CoreRefundAuthorization {

    /** 本外掛的 gateway id。 */
    public const GATEWAY_ID = 'ys_ec_ecpay_credit';

    /** 必須存在且為 false 的旗標。 */
    private const REQUIRED_FALSE_FLAGS = [ 'finalized', 'provider_done', 'record_only' ];

    /**
     * 驗證核心 entry 是否授權「現在送出金流動作」
     *
     * @param mixed $core_entry `_ys_refund_finalization[$request_id]` 的原始值
     * @param int   $amount_twd 本次要退的金額（canonical 整數）
     * @return array<string,mixed>|null null＝通過；否則是可回報的 decision
     */
    public static function problem( mixed $core_entry, int $amount_twd ): ?array {
        if ( ! is_array( $core_entry ) ) {
            return [ 'action' => 'no_core_request' ];
        }

        $gateway = $core_entry['gateway_id'] ?? null;
        if ( ! is_string( $gateway ) || ! hash_equals( self::GATEWAY_ID, $gateway ) ) {
            return [
                'action'  => 'core_gateway_mismatch',
                'gateway' => is_scalar( $gateway ) ? (string) $gateway : gettype( $gateway ),
            ];
        }

        $status = $core_entry['status'] ?? null;
        if ( ! is_string( $status ) || 'submitting' !== $status ) {
            return [
                'action' => 'core_not_submitting',
                'status' => is_scalar( $status ) ? (string) $status : gettype( $status ),
            ];
        }

        foreach ( self::REQUIRED_FALSE_FLAGS as $flag ) {
            // 🔴 必須**存在**。缺欄位不是 false，是「無法證明」。
            if ( ! array_key_exists( $flag, $core_entry ) ) {
                return [ 'action' => 'core_flag_missing', 'flag' => $flag ];
            }

            $raw = $core_entry[ $flag ];

            // 🔴 #2F：**只**接受 exact bool。
            //
            // Core 產出的契約是 `false`／`true`，不是 `0`／`'0'`。接受後者等於接受
            // 一份不知道從哪來的值——被其他外掛改過的 entry、部分寫入的 entry、
            // 從 CSV 匯入的 entry 都會通過。退款是不可逆的；「看起來像 false」不夠。
            if ( true === $raw ) {
                return [ 'action' => 'core_' . $flag ];
            }
            if ( false === $raw ) {
                continue;
            }

            // 無法解讀的旗標值：不得猜。
            return [
                'action' => 'core_flag_unreadable',
                'flag'   => $flag,
                'value'  => is_scalar( $raw ) ? (string) $raw : gettype( $raw ),
            ];
        }

        // 🔴 #2F：金額只接受 Core 產出的 canonical **正整數**。
        // string／float 都不是 Core 寫出來的形狀；接受它們等於接受未知來源。
        $raw_amount  = $core_entry['amount'] ?? null;
        $core_amount = is_int( $raw_amount ) && $raw_amount > 0 ? $raw_amount : null;
        if ( null === $core_amount ) {
            return [
                'action'      => 'core_amount_unreadable',
                'core_amount' => is_scalar( $core_entry['amount'] ?? null ) ? (string) $core_entry['amount'] : gettype( $core_entry['amount'] ?? null ),
            ];
        }

        if ( $core_amount !== $amount_twd ) {
            return [ 'action' => 'core_amount_mismatch', 'core_amount' => $core_amount ];
        }

        return null;
    }

    /**
     * 從 payment_detail 取出核心 entry 並驗證。
     *
     * @param array<string,mixed> $detail
     */
    public static function problem_in( array $detail, string $request_id, int $amount_twd ): ?array {
        $ledger = is_array( $detail['_ys_refund_finalization'] ?? null ) ? $detail['_ys_refund_finalization'] : [];

        return self::problem( $ledger[ $request_id ] ?? null, $amount_twd );
    }

    /**
     * canonical 整數（TWD 無小數）。
     *
     * `(float)`／`(int)` 轉型會讓 `'1000abc'`、`'1e3'`、`true` 都變成看似合理的
     * 金額——金額是不可逆動作的依據，不接受任何「看起來像」的值。
     */
    public static function canonical_int( mixed $value ): ?int {
        if ( is_int( $value ) ) {
            return $value;
        }
        // 🔴 字串必須落在 PHP 整數範圍內。超界字串被 `(int)` 轉型會**飽和**成
        // PHP_INT_MAX——一個荒謬的金額會變成一個看起來合理的巨大整數。
        if ( is_string( $value ) && 1 === preg_match( '/^(0|-?[1-9][0-9]*)$/', $value ) ) {
            if ( (string) (int) $value !== $value ) {
                return null; // 轉型後對不回去＝超界
            }

            return (int) $value;
        }

        // JSON 解碼常見的整數值 float（1000.0）。
        if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value
            && abs( $value ) < (float) PHP_INT_MAX ) {
            return (int) $value;
        }

        return null;
    }
}
