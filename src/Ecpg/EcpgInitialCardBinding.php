<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Ecpg;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Models\YSSubscription;
use YangSheep\Ecommerce\Services\Payment\YSInitialSubscriptionCardBinding;
use YangSheep\Ecommerce\Utils\YSLogger;

/**
 * 站內付 2.0 首購訂閱綁卡：對齊 Core 的首購卡片權威（v0.5.12）。
 *
 * 0.5.11 以前，首購 BindCardID 存進卡片庫之後直接呼叫 `bind_initial_order_card()`（不帶收據）；
 * 訂閱列若還沒建立（Core 以付款事件 outbox 建立訂閱，會晚於付款回呼），綁卡回 false，
 * 整個 `ecpg_card_vault` 效果失敗、回 VAULT_FAILED 要綠界重送——只有綠界重送能補綁。
 *
 * 0.5.12 先把「這張卡是這筆首購的扣款卡」寫進 Core 的已付款收據（`YSInitialSubscriptionCardBinding::enroll()`），
 * 之後綁卡交給 Core 以同一份權威完成（2.69.7 起建立訂閱時同步帶卡；背景續作亦同），不再依賴綠界重送。
 *
 * 三態（每一態都有反證測試）：
 *   (a) Core 收下權威（enroll 回 true）→ 帶收據呼叫 `bind_initial_order_card()`（Core 走 recover()）；
 *       訂閱尚未建立＝權威已持久在收據上、Core 的首購綁卡效果留 pending 由 Core 完成 → 視為成功，不要求重送。
 *   (b) Core 拒絕權威（enroll 回 false：2.69.6 以前的 Core 只認 PayUni、或卡片／訂單／收據不符、或拋例外）
 *       → 一律退回 0.5.11 行為：不帶收據綁卡，失敗照舊要求綠界重送。**絕不**在權威沒寫進收據時回成功，
 *       否則沒有任何一方會再綁這張卡。
 *   (c) Core 沒有 enroll（2.67.11 ～ 首購卡片權威之前的 Core）→ 同 (b)。
 */
final class EcpgInitialCardBinding {

	/** (a) 權威已寫進收據。 */
	public const ENROLLED = 'enrolled';

	/** (b) Core 拒絕權威（或 enroll 拋例外）。 */
	public const REFUSED = 'refused';

	/** (c) Core 沒有首購卡片權威 API。 */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * 首購訂單的綁卡責任。
	 *
	 * @param int         $order_id     首購訂單。
	 * @param int         $customer_id  0.5.11 傳給 legacy 綁卡的同一個 customer id。
	 * @param int         $user_id      0.5.11 傳給 legacy 綁卡的同一個 user id。
	 * @param int         $card_id      剛存進 Core 卡片庫的卡（YSCreditCard::create_or_get() 的結果）。
	 * @param string      $receipt_id   付款成功那一次的 processing 收據。
	 * @param object|null $effect_db    Core 以 idle 模式投遞效果時交來的連線（與 $effect_fence 同時有或同時無）。
	 * @param array|null  $effect_fence 同上的 session fence。
	 * @return bool true＝這次回呼的綁卡責任已完成（含交給 Core 續作）；false＝要求綠界重送。
	 */
	public static function attach(
		int $order_id,
		int $customer_id,
		int $user_id,
		int $card_id,
		string $receipt_id,
		?object $effect_db = null,
		?array $effect_fence = null
	): bool {
		$state = self::enroll( $order_id, $card_id, $receipt_id, $effect_db, $effect_fence );
		if ( self::ENROLLED === $state ) {
			// 帶收據＝Core 以收據上的權威重驗（active 卡、owner、當期收據）後才綁；
			// 訂閱還沒建立時回 false，這不是錯：Core 的首購綁卡效果留 pending，由 Core 完成。
			try {
				YSSubscription::bind_initial_order_card(
					$order_id,
					$customer_id,
					$user_id,
					EcpgOrderContext::GATEWAY_ID,
					$card_id,
					$receipt_id,
					$effect_db,
					$effect_fence
				);
			} catch ( \Throwable $error ) {
				unset( $error );
				// 權威已持久在收據上，Core 的首購綁卡效果會以同一份權威重試；不需要綠界重送。
				YSLogger::error( 'ecpay', 'ECPG 首購訂閱帶收據綁卡發生例外，已交由 Core 以收據上的權威續作', [
					'order_id' => $order_id,
					'reason'   => 'ecpg_initial_card_bind_exception',
				] );
			}
			return true;
		}

		// (b)／(c)：0.5.11 行為——不帶收據綁卡，綁不到就要求綠界重送。
		return YSSubscription::bind_initial_order_card(
			$order_id,
			$customer_id,
			$user_id,
			EcpgOrderContext::GATEWAY_ID,
			$card_id
		);
	}

	/**
	 * 把首購卡片權威寫進 Core 的已付款收據。
	 *
	 * 以 mark_paid 之後的訂單為準（訂單的 gateway 欄位在付款 CAS 裡才寫定），所以先 forget 再 find；
	 * Core 的 enroll() 會自己再讀一次訂單、重驗 active 且 owner-exact 的卡片與當期收據。
	 *
	 * @return string self::ENROLLED｜self::REFUSED｜self::UNSUPPORTED
	 */
	private static function enroll( int $order_id, int $card_id, string $receipt_id, ?object $effect_db, ?array $effect_fence ): string {
		if ( ! self::core_supports_initial_card_authority() ) {
			return self::UNSUPPORTED;
		}
		if ( $order_id <= 0 || $card_id <= 0 || '' === $receipt_id
			|| ( null === $effect_db ) !== ( null === $effect_fence ) ) {
			return self::REFUSED;
		}

		try {
			YSOrder::forget( $order_id );
			$paid_order = YSOrder::find( $order_id );
			if ( ! is_object( $paid_order ) ) {
				return self::REFUSED;
			}
			$enrolled = null === $effect_db
				? YSInitialSubscriptionCardBinding::enroll( $paid_order, $receipt_id, $card_id )
				: YSInitialSubscriptionCardBinding::enroll( $paid_order, $receipt_id, $card_id, $effect_db, $effect_fence );
		} catch ( \Throwable $error ) {
			unset( $error );
			// 例外可能發生在寫入前或後，無法證明權威已持久：當作拒絕，走 0.5.11 的保守路徑。
			YSLogger::error( 'ecpay', 'ECPG 首購訂閱卡片權威補登發生例外，改用舊版綁卡流程', [
				'order_id' => $order_id,
				'reason'   => 'ecpg_initial_card_enroll_exception',
			] );
			return self::REFUSED;
		}

		if ( true !== $enrolled ) {
			YSLogger::info( 'ecpay', 'Core 未收下 ECPG 首購訂閱卡片權威，改用舊版綁卡流程', [
				'order_id' => $order_id,
				'reason'   => 'ecpg_initial_card_enroll_refused',
			] );
			return self::REFUSED;
		}

		return self::ENROLLED;
	}

	/**
	 * Core 是否提供首購卡片權威：enroll() 與「帶收據」的 bind_initial_order_card() 必須同時存在。
	 * 只有其一時不寫權威（寫了卻沒有帶收據的綁卡可以接手，等於把卡片留在半路）。
	 */
	private static function core_supports_initial_card_authority(): bool {
		if ( ! class_exists( YSInitialSubscriptionCardBinding::class )
			|| ! method_exists( YSInitialSubscriptionCardBinding::class, 'enroll' )
			|| ! class_exists( YSSubscription::class )
			|| ! method_exists( YSSubscription::class, 'bind_initial_order_card' ) ) {
			return false;
		}
		try {
			return ( new \ReflectionMethod( YSSubscription::class, 'bind_initial_order_card' ) )->getNumberOfParameters() >= 8;
		} catch ( \ReflectionException $error ) {
			unset( $error );
			return false;
		}
	}
}
