<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;

final class EcpayCreditGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_credit';
	}

	public function get_title(): string {
		return '綠界信用卡';
	}

	protected function gateway_key(): string {
		return 'credit';
	}

	protected function choose_payment(): string {
		return 'Credit';
	}

	/**
	 * 宣告具自動金流退款能力（core v2.56.4 `YSGatewayRegistry::supports_auto_refund`
	 * 的可選方法協定）——後台退款 UI 據此不顯示「訂單退款≠金流退款」警示。
	 */
	public function supports_gateway_refund(): bool {
		return true;
	}

	/**
	 * 信用卡退刷（CreditDetail/DoAction，Action=R）
	 *
	 * 僅信用卡 gateway 支援；ATM／超商依產品決策走人工退款（base 維持不支援）。
	 * 交易識別碼一律取自訂單既有付款紀錄（payment_detail / gateway_trade_no），
	 * 不信任外部輸入；金額驗證上限＝訂單 total－已退金額。
	 *
	 * @deferred-sandbox 綠界測試環境對拍前不得對外宣稱支援（見 EcpayPaymentClient::do_action_refund）。
	 */
	public function process_refund( int $order_id, float $amount, string $reason = '', array $context = [] ): array {
		unset( $context );

		$order = YSOrder::find( $order_id );
		if ( ! $order ) {
			return [ 'success' => false, 'message' => '訂單不存在。' ];
		}

		// 金額驗證：正數且不得超過可退餘額（fail-closed）。
		$total      = (float) ( $order->total ?? 0 );
		$refunded   = (float) ( $order->refunded_amount ?? 0 );
		$refundable = $total - $refunded;
		if ( $amount <= 0 || round( $amount, 2 ) > round( $refundable, 2 ) ) {
			return [
				'success' => false,
				'message' => sprintf( '退刷金額不正確（可退餘額 %s）。', number_format( max( 0, $refundable ), 2 ) ),
			];
		}

		// 交易識別碼取自訂單付款紀錄（付款回調由 reconciler 寫入），不可信外部輸入。
		$payment_detail    = json_decode( (string) ( $order->payment_detail ?? '{}' ), true ) ?: [];
		$trade_no          = (string) ( $payment_detail['trade_no'] ?? $order->gateway_trade_no ?? '' );
		$merchant_trade_no = (string) ( $payment_detail['mer_trade_no'] ?? '' );
		if ( '' === $trade_no || '' === $merchant_trade_no ) {
			return [ 'success' => false, 'message' => '找不到綠界交易識別碼，無法退刷（訂單可能非綠界信用卡付款）。' ];
		}

		$result = ( new EcpayPaymentClient() )->do_action_refund( $merchant_trade_no, $trade_no, $amount );
		if ( empty( $result['success'] ) ) {
			return [
				'success' => false,
				'message' => '綠界退刷失敗：' . (string) ( $result['message'] ?? '未知錯誤' ),
			];
		}

		return [
			'success'        => true,
			'transaction_id' => (string) ( $result['data']['TradeNo'] ?? $trade_no ),
			'message'        => '',
		];
	}
}
