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
	 * 未關帳失敗碼（DoAction Action=R 對未關帳交易的回應碼）
	 *
	 * @deferred-sandbox：確切碼值須以綠界測試環境對拍鎖定
	 * （docs/credit-refund-sandbox-gate.md G-2）。對拍前為空＝不會觸發 N fallback，
	 * R 失敗一律回報失敗訊息（fail-closed，不誤送 N）。
	 */
	private const UNCLOSED_RTN_CODES = [];

	/**
	 * 信用卡退刷（CreditDetail/DoAction）
	 *
	 * 僅信用卡 gateway 支援；ATM／超商依產品決策走人工退款（base 維持不支援）。
	 * 設計要點（CODEX 終審修正）：
	 *   1. **crash-safe 冪等**：以 core 傳入的 `context['refund_request_id']` 為 key，
	 *      送出前將 attempt 持久化為 pending；同 key 已成功→冪等重放、pending（結果未明）
	 *      →拒絕盲重送（人工至綠界後台確認）、failed→允許重試。
	 *   2. **Action 分流**：先送 R（退刷）；若回應碼落在 UNCLOSED_RTN_CODES（未關帳）
	 *      且為全額 → 改送 N（放棄請款）；部分金額＋未關帳 → 明確拒絕。
	 *   3. 識別碼一律取自訂單付款紀錄；金額上限＝total－已退。
	 *
	 * @deferred-sandbox 綠界測試環境對拍前不得對外宣稱支援（gate 清單見 docs/credit-refund-sandbox-gate.md）。
	 */
	public function process_refund( int $order_id, float $amount, string $reason = '', array $context = [] ): array {
		unset( $reason );

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

		// ── crash-safe 冪等（refund_request_id）──
		$request_id = (string) ( $context['refund_request_id'] ?? '' );
		$history    = is_array( $payment_detail['_ys_ecpay_refunds'] ?? null ) ? $payment_detail['_ys_ecpay_refunds'] : [];

		if ( '' !== $request_id && isset( $history[ $request_id ] ) ) {
			$entry = $history[ $request_id ];
			if ( 'done' === ( $entry['status'] ?? '' ) ) {
				// 同 request 已成功 → 冪等重放，不重送金流。
				return [
					'success'        => true,
					'transaction_id' => (string) ( $entry['trade_no'] ?? $trade_no ),
					'message'        => '（冪等重放：此退刷請求先前已成功）',
				];
			}
			if ( 'pending' === ( $entry['status'] ?? '' ) ) {
				// 前次送出後結果未明（可能已在綠界端生效）→ 禁止盲重送，防重複退刷。
				return [
					'success' => false,
					'message' => '前次退刷請求結果未明，為避免重複退刷已拒絕重送；請先於綠界後台確認該筆交易，再以新請求處理。',
				];
			}
			// failed → 允許重試（往下走）。
		}

		// 送出前持久化 pending（送出後 crash，重來會看到 pending 而拒絕盲重送）。
		if ( '' !== $request_id ) {
			$history[ $request_id ] = [
				'status' => 'pending',
				'amount' => $amount,
				'time'   => current_time( 'mysql' ),
			];
			$payment_detail['_ys_ecpay_refunds'] = $history;
			YSOrder::update( $order_id, [ 'payment_detail' => wp_json_encode( $payment_detail ) ] );
		}

		$persist = function ( array $entry ) use ( $order_id, $request_id ): void {
			if ( '' === $request_id ) {
				return;
			}
			$fresh  = YSOrder::find( $order_id );
			$detail = json_decode( (string) ( $fresh->payment_detail ?? '{}' ), true ) ?: [];
			$hist   = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
			$hist[ $request_id ]          = array_merge( $hist[ $request_id ] ?? [], $entry );
			$detail['_ys_ecpay_refunds']  = $hist;
			YSOrder::update( $order_id, [ 'payment_detail' => wp_json_encode( $detail ) ] );
		};

		// ── Action 分流：先 R（退刷）──
		$client = new EcpayPaymentClient();
		$result = $client->do_action_refund( $merchant_trade_no, $trade_no, $amount, 'R' );
		$action = 'R';

		// 未關帳 → 全額改送 N（放棄請款）；部分金額明確拒絕。
		if ( empty( $result['success'] )
			&& in_array( (string) ( $result['data']['RtnCode'] ?? '' ), self::UNCLOSED_RTN_CODES, true ) ) {
			if ( round( $amount, 2 ) < round( $total, 2 ) ) {
				$persist( [ 'status' => 'failed', 'action' => 'R', 'rtn_msg' => '未關帳交易不支援部分退刷' ] );
				return [
					'success' => false,
					'message' => '此交易尚未關帳，不支援部分退刷；請待關帳後退刷，或以全額放棄請款處理。',
				];
			}
			$action = 'N';
			$result = $client->do_action_refund( $merchant_trade_no, $trade_no, $amount, 'N' );
		}

		if ( empty( $result['success'] ) ) {
			$persist( [
				'status'   => 'failed',
				'action'   => $action,
				'rtn_code' => (string) ( $result['data']['RtnCode'] ?? '' ),
				'rtn_msg'  => (string) ( $result['message'] ?? '' ),
			] );
			return [
				'success' => false,
				'message' => '綠界退刷失敗：' . (string) ( $result['message'] ?? '未知錯誤' ),
			];
		}

		$done_trade_no = (string) ( $result['data']['TradeNo'] ?? $trade_no );
		$persist( [
			'status'   => 'done',
			'action'   => $action,
			'trade_no' => $done_trade_no,
		] );

		return [
			'success'        => true,
			'transaction_id' => $done_trade_no,
			'message'        => 'N' === $action ? '（未關帳交易，以放棄請款方式全額退款）' : '',
		];
	}
}
