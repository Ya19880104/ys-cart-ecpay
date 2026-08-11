<?php
/**
 * 訂單純量欄位的 typed 寫入
 *
 * 🔴 `YSOrder::update()` 回 `true` **不代表值寫進去了**。
 *
 * 核心的實作是 `$wpdb->update()`，而 MySQL 對「值與現況相同」的 UPDATE 回報
 * affected_rows = 0；核心把 `0` 也當成成功（因為對「同值寫入」而言那確實是成功）。
 * 但 `0` 還有另外兩種成因，意義完全不同：
 *
 *   - **訂單不存在**（WHERE 沒有匹配）→ 我們以為寫進去的值根本沒有落點
 *   - 併發改寫導致條件不成立
 *
 * 呼叫端拿到 `true` 之後就 ACK 給供方，於是「TradeNo 沒寫進去」「tracking_number
 * 沒寫進去」這類事實被永久吞掉——而那些欄位正是退款、對帳、查件的唯一依據。
 *
 * 這個類別把結果分成可分辨的狀態，並**一律回頭讀一次**：只有現況與我們要寫的
 * 值逐字元相同才算成功。
 *
 * @package YangSheep\YSCartEcpay\Support
 */

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;

final class ScalarColumnWriter {

    /**
     * 值已確認落在 DB 裡（readback 逐字元相符）。
     *
     * 🔴 #2F：這裡**不能**再分「UPDATED（affected=1）」與「NO_OP（affected=0
     * 但值相同）」。`YSOrder::update()` 回的是 bool——`true` 同時代表 affected=1
     * 與 affected=0，兩者分不出來。給它們兩個不同的名字等於宣稱我們知道一件
     * 我們不知道的事，而讀 log 的人會據此推論。
     *
     * 我們**能**證明的只有一件事：readback 顯示 DB 裡就是我們要的值。
     */
    public const VERIFIED = 'verified';

    /** 沒有要寫的欄位（呼叫端傳了空陣列）。 */
    public const NO_OP = 'no_op';

    /** 訂單不存在（readback 讀不到）。 */
    public const MISSING = 'missing';

    /** DB 錯誤（wpdb 回 false）。 */
    public const DB_ERROR = 'db_error';

    /** affected = 0 但現況與預期不符（併發改寫）。 */
    public const CONFLICT = 'conflict';

    /**
     * 寫入純量欄位並驗證結果
     *
     * @param array<string,scalar> $columns
     * @return array{state:string, columns:array<string,mixed>}
     */
    public static function write( int $order_id, array $columns ): array {
        if ( [] === $columns ) {
            return [ 'state' => self::NO_OP, 'columns' => [] ];
        }

        global $wpdb;

        $wpdb->last_error = '';
        $updated          = YSOrder::update( $order_id, $columns );

        if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
            return [ 'state' => self::DB_ERROR, 'columns' => [] ];
        }

        if ( false === $updated ) {
            return [ 'state' => self::DB_ERROR, 'columns' => [] ];
        }

        // 🔴 不論 true／false 都回頭讀一次。`true` 可能來自 affected=0，而
        // affected=0 可能是「同值」也可能是「訂單不存在」。
        YSOrder::forget( $order_id );
        $fresh = YSOrder::find( $order_id );

        if ( ! $fresh ) {
            return [ 'state' => self::MISSING, 'columns' => [] ];
        }

        $actual = [];
        foreach ( $columns as $column => $expected ) {
            $actual[ $column ] = property_exists( $fresh, $column ) ? $fresh->{$column} : null;

            // 型別寬鬆比較是刻意的：DB 讀回來的一律是字串，而呼叫端可能傳 int。
            // 但兩邊都先轉成字串再逐字元比——不是 `==`（那會讓 '0' 與 '' 相等）。
            if ( (string) $actual[ $column ] !== (string) $expected ) {
                return [ 'state' => self::CONFLICT, 'columns' => $actual ];
            }
        }

        return [ 'state' => self::VERIFIED, 'columns' => $actual ];
    }

    /**
     * 值確實就是我們要的（VERIFIED 或「沒有欄位要寫」）。
     *
     * @param array{state:string, columns:array<string,mixed>} $result
     */
    public static function is_persisted( array $result ): bool {
        return in_array( $result['state'] ?? '', [ self::VERIFIED, self::NO_OP ], true );
    }

    /**
     * 非空的 typed 字串（TradeNo、tracking_number 這類識別碼專用）
     *
     * 空字串不是識別碼。把它寫進去等於宣稱「這筆交易沒有編號」，而下游（退款、
     * 對帳、查件）會把那當成事實。
     */
    public static function required_string( mixed $value ): ?string {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $trimmed = trim( $value );

        return '' === $trimmed ? null : $trimmed;
    }
}
