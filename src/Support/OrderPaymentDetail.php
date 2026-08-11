<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailResult;
use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore;

/**
 * `payment_detail` 寫入的**相容薄層**（v0.3.0）
 *
 * v0.2.12 時這裡是一份 provider 私有的 CAS 實作。那不夠：payment_detail 是單一 JSON
 * 欄位，核心自己也有多個 writer（付款生命週期 merge、對帳狀態、發票 override、退款
 * finalization ledger）。provider 端再怎麼 CAS，只要**核心**還有任何一個 read-modify-write
 * 的盲寫 writer，它照樣會把 provider 剛落盤的退款憑據整段抹掉——而那正是「不重複退款」
 * 的唯一依據。
 *
 * 因此 CAS 已上收為核心 v2.57.0 的 `YSPaymentDetailStore`，全生態共用同一份。本類別只
 * 保留兩件事：
 *   1. 核心版本守門——服務缺席時**一律失敗**，絕不退回自己那份寫入器。舊實作在舊核心
 *      上仍能寫，但那等於兩套互不相認的 CAS 各自為政，比沒有 CAS 更危險。
 *   2. 把核心的 typed result 原樣交給呼叫端，讓 provider 端能分辨 updated／no-op／
 *      aborted／db-error／conflict-exhausted 並據此決定要不要回報成功。
 */
final class OrderPaymentDetail {

	/**
	 * 以核心共用 CAS 修改單筆訂單的 payment_detail。
	 *
	 * @param int      $order_id 訂單 ID
	 * @param callable $mutator  fn( array $detail, int $attempt, mixed &$decision ): ?array
	 */
	public static function mutate( int $order_id, callable $mutator, ?int $max_attempts = null ): DetailWriteOutcome {
		if ( ! self::is_available() ) {
			// 核心服務缺席時**不得**退回 provider 自己的寫入器：兩套互不相認的 CAS
			// 各自為政比沒有 CAS 更危險（雙方都以為自己贏了）。
			return DetailWriteOutcome::core_unavailable();
		}

		$result = null === $max_attempts
			? YSPaymentDetailStore::mutate( $order_id, $mutator )
			: YSPaymentDetailStore::mutate( $order_id, $mutator, $max_attempts );

		return DetailWriteOutcome::from_core( $result );
	}

	/**
	 * 讀取目前的 payment_detail。
	 *
	 * @return array|null null＝訂單不存在、欄位無法解讀，或核心服務缺席。
	 *                    三者呼叫端都必須 fail-closed，不得當成空陣列。
	 */
	public static function read( int $order_id ): ?array {
		if ( ! self::is_available() ) {
			return null;
		}

		return YSPaymentDetailStore::read( $order_id );
	}

	/** 核心是否提供共用 CAS（YS CART 2.57.0+）。 */
	public static function is_available(): bool {
		return class_exists( YSPaymentDetailStore::class )
			&& method_exists( YSPaymentDetailStore::class, 'mutate' )
			&& class_exists( YSPaymentDetailResult::class );
	}
}
