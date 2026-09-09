<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\Settings;

/**
 * 信用卡分期：`ChoosePayment` 仍是 `Credit`，差別在多送一個 `CreditInstallment`
 * 期數清單。
 *
 * 期數是**設定值**不是常數，因此不放在型錄裡，由本類別在執行期提供。
 */
final class EcpayCreditInstallmentGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_credit_installment';
	}

	/**
	 * 🔴 沒設定期數＝這個方式不可用，而不是「送一個空的 CreditInstallment」。
	 *
	 * 空值送出去，綠界會把它當成一般信用卡一次付清處理——結帳頁寫著「分期」、
	 * 消費者實際被扣的是全額。那是一個看起來成功的錯誤，比結不了帳難查得多。
	 */
	public function is_enabled(): bool {
		return parent::is_enabled() && '' !== self::installment_periods();
	}

	/**
	 * @return array<string,string>
	 */
	protected function extra_aio_fields(): array {
		$periods = self::installment_periods();

		return '' === $periods ? [] : [ 'CreditInstallment' => $periods ];
	}

	/**
	 * 已設定且合法的期數清單（逗號分隔），無合法期數時回空字串。
	 */
	public static function installment_periods(): string {
		return Settings::credit_installment_periods();
	}
}
