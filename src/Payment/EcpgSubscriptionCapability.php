<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * 付款方式「支援訂閱」宣告的相容橋（v0.5.12）。
 *
 * Core 2.69.7 起提供可選介面 `YSSubscriptionCapableGatewayInterface`（`supports_subscriptions()`／
 * `subscription_schedule_authority()`）。PHP 不能在類別宣告上「有就實作」，所以由這支橋接：
 *
 *   - Core 有該介面 → 本介面 extends 它，ECPG 閘道因此是明確宣告的訂閱付款方式；
 *   - 舊 Core（2.67.11 ～ 2.69.6）沒有 → 本介面是空的，閘道上的兩個方法只是普通方法，
 *     Core 仍以 `supports_token()` 相容判定，行為與 0.5.11 相同（最低 Core 不提高）。
 *
 * 判斷只在第一次載入本檔時做一次；Core 的 autoloader 還沒註冊時會落到空介面＝相容路徑，
 * 結果仍然「支援訂閱」，不會變成不能結帳。
 */
if ( interface_exists( \YangSheep\Ecommerce\Gateways\YSSubscriptionCapableGatewayInterface::class ) ) {
	interface EcpgSubscriptionCapability extends \YangSheep\Ecommerce\Gateways\YSSubscriptionCapableGatewayInterface {
	}
} else {
	interface EcpgSubscriptionCapability {
	}
}
