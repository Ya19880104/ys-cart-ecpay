<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;

/**
 * `payment_detail` 的 compare-and-swap 寫入（v0.2.12）
 *
 * payment_detail 是單一 JSON 欄位，任何 read-modify-write 都是整包覆蓋。本外掛至少
 * 有三個併發寫入者——付款 webhook（回寫 gwsr）、退款 ledger（`_ys_ecpay_refunds`）、
 * 核心 finalization／CLI——直接 `YSOrder::update()` 會讓後寫者靜默蓋掉先寫者，退款
 * 冪等紀錄因此可能消失，而該紀錄正是「不重複退款」的唯一依據。
 *
 * 核心未提供共用 CAS 寫入器，故於此實作；本外掛所有 payment_detail 寫入一律經過這裡。
 *
 * 三個必須分流的細節：
 *   1. `$wpdb->query()` 回 `false` ＝ SQL 錯誤，回 `0` ＝ CAS 落敗，語意完全不同。
 *   2. MySQL 的 affected_rows 算的是「實際變更的列」，同值 UPDATE 天生回 0；那不是
 *      CAS 落敗，必須在送出前先比對並視為成功的 no-op。
 *   3. 欄位可能是 SQL NULL（尚未寫過），`= NULL` 永遠不成立，WHERE 需改用 IS NULL。
 */
final class OrderPaymentDetail {

	/** 重試上限；耗盡即視為未寫入，由呼叫端決定是否 CRITICAL。 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * 以 CAS 方式修改單筆訂單的 payment_detail。
	 *
	 * @param int                                              $order_id 訂單 ID
	 * @param callable(array<string,mixed>):array<string,mixed> $mutate   對「剛讀到的」detail 做修改並回傳完整陣列
	 * @return bool true＝已寫入（含同值 no-op）；false＝SQL 錯誤、編碼失敗或重試耗盡
	 */
	public static function mutate( int $order_id, callable $mutate ): bool {
		global $wpdb;

		if ( $order_id <= 0 || ! is_object( $wpdb ) ) {
			return false;
		}

		$table = YSOrder::table();

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$wpdb->last_error = '';
			$old = $wpdb->get_var(
				$wpdb->prepare( "SELECT payment_detail FROM {$table} WHERE id = %d", $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( '' !== (string) $wpdb->last_error ) {
				return false; // 讀取失敗不可當成「欄位為 NULL」。
			}

			$was_null = ( null === $old );
			$old_str  = (string) $old;

			$decoded = json_decode( '' === $old_str ? '{}' : $old_str, true );
			$detail  = is_array( $decoded ) ? $decoded : [];

			$next = $mutate( $detail );
			if ( ! is_array( $next ) ) {
				return false;
			}

			$new = wp_json_encode( $next );
			if ( ! is_string( $new ) ) {
				return false; // 編碼失敗：寧可回報未寫入，也不要寫入殘缺 JSON。
			}

			if ( ! $was_null && $new === $old_str ) {
				return true; // 同值 no-op，見上方細節 2。
			}

			$sql = $was_null
				? $wpdb->prepare( "UPDATE {$table} SET payment_detail = %s WHERE id = %d AND payment_detail IS NULL", $new, $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->prepare( "UPDATE {$table} SET payment_detail = %s WHERE id = %d AND payment_detail = %s", $new, $order_id, $old_str ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$wpdb->last_error = '';
			$affected         = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $affected ) {
				return false; // SQL 錯誤 ≠ CAS 落敗，見上方細節 1。
			}
			if ( (int) $affected > 0 ) {
				return true;
			}

			// affected === 0：期間已被其他 writer 改動 → 重讀、重算、重試。
			usleep( 20000 * ( $attempt + 1 ) );
		}

		return false;
	}
}
