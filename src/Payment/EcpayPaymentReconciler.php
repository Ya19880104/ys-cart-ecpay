<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcileResult;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcilerInterface;

final class EcpayPaymentReconciler implements YSPaymentReconcilerInterface {
	private const ECPG_GATEWAY_ID = 'ys_ec_ecpay_ecpg_credit';

	public function supports( object $order ): bool {
		$detail = $this->payment_detail( $order );
		$gateway_id = (string) ( $order->gateway_id ?? $order->payment_method ?? '' );
		if ( self::ECPG_GATEWAY_ID === $gateway_id
			|| self::ECPG_GATEWAY_ID === (string) ( $detail['payment_method'] ?? '' ) ) {
			return false;
		}

		return 'ecpay' === (string) ( $detail['payment_provider'] ?? '' )
			|| '' !== (string) ( $detail['ecpay_merchant_trade_no'] ?? '' )
			|| str_starts_with( $gateway_id, 'ys_ec_ecpay_' );
	}

	public function reconcile( object $order ): YSPaymentReconcileResult {
		$detail = $this->payment_detail( $order );
		$merchant_trade_no = (string) ( $detail['ecpay_merchant_trade_no'] ?? $detail['mer_trade_no'] ?? '' );
		if ( '' === $merchant_trade_no ) {
			return YSPaymentReconcileResult::unsupported( 'ECPay merchant trade number is missing.' );
		}

		$result = ( new EcpayPaymentClient() )->query_trade( $merchant_trade_no );
		$data   = is_array( $result['data'] ?? null ) ? $result['data'] : [];
		if ( empty( $result['success'] ) ) {
			return YSPaymentReconcileResult::error( (string) ( $result['message'] ?? 'ECPay query failed.' ), null, $data );
		}

		$gateway_id = trim( (string) ( $order->gateway_id ?? '' ) );
		if ( '' === $gateway_id ) {
			// Migrated Core rows can still carry the canonical method only in
			// payment_method.  Use the same bounded fallback as supports() so a
			// QueryTrade response without PaymentType keeps ATM/CVS references.
			$gateway_id = trim( (string) ( $order->payment_method ?? '' ) );
		}
		$payment_detail = $this->detail_from_query( $data, $gateway_id );
		$trade_status   = (string) ( $data['TradeStatus'] ?? '' );

		if ( '1' === $trade_status ) {
			return YSPaymentReconcileResult::paid( $payment_detail, 'ECPay query confirmed payment.', $data );
		}

		if ( '10200095' === $trade_status ) {
			return YSPaymentReconcileResult::failed( $payment_detail, 'failed', 'ECPay query reported an unfinished failed trade.', $data );
		}

		if ( '0' === $trade_status && $this->is_offline_payment( $order, $data ) ) {
			return YSPaymentReconcileResult::offline_pending( $payment_detail, 'ECPay query confirmed offline payment is still pending.', $data );
		}

		if ( '0' === $trade_status ) {
			return YSPaymentReconcileResult::hold( 'ECPay query found the trade but payment is not complete yet.', $payment_detail, $data );
		}

		return YSPaymentReconcileResult::hold( 'ECPay query returned an unknown trade state.', $payment_detail, $data );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payment_detail( object $order ): array {
		$detail = json_decode( (string) ( $order->payment_detail ?? '{}' ), true );
		return is_array( $detail ) ? $detail : [];
	}

	/**
	 * @param array<string,string> $params
	 */
	private function detail_from_query( array $params, string $gateway_id ): YSPaymentDetailDTO {
		$detail = [
			'payment_type'     => (string) ( $params['PaymentType'] ?? '' ),
			'trade_status'     => (string) ( $params['TradeStatus'] ?? '' ),
			// 補單路徑也帶回金額（綠界 QueryTradeInfo 回應含 TradeAmt），讓核心金額守衛能
			// 嚴格核對，與 notify controller 層及 JKOPay／NewebPay 對帳路徑一致（縱深防禦）。
			'paid_amount'      => isset( $params['TradeAmt'] ) && is_numeric( $params['TradeAmt'] ) ? (float) $params['TradeAmt'] : 0.0,
			'trade_no'         => (string) ( $params['TradeNo'] ?? '' ),
			'gateway_trade_no' => (string) ( $params['TradeNo'] ?? '' ),
			'mer_trade_no'     => (string) ( $params['MerchantTradeNo'] ?? '' ),
			'response_code'    => (string) ( $params['TradeStatus'] ?? '' ),
			'response_message' => (string) ( $params['RtnMsg'] ?? $params['TradeStatus'] ?? '' ),
			'bank_type'        => (string) ( $params['BankCode'] ?? '' ),
			'expire_date'      => (string) ( $params['ExpireDate'] ?? '' ),
			'card_4no'         => (string) ( $params['card4no'] ?? $params['Card4No'] ?? '' ),
			'card_6no'         => (string) ( $params['card6no'] ?? $params['Card6No'] ?? '' ),
			'auth_code'        => (string) ( $params['auth_code'] ?? $params['AuthCode'] ?? '' ),
		];
		$pay_no = $this->offline_pay_no( $params, $gateway_id );
		if ( '' !== $pay_no ) {
			// BankCode is not a payment reference.  Keep pay_no absent when the
			// query omits the account/code so Core's merge retains the known value.
			$detail['pay_no'] = $pay_no;
		}

		return YSPaymentDetailDTO::from_legacy_array( $detail, $gateway_id );
	}

	/** @param array<string,string> $params */
	private function offline_pay_no( array $params, string $gateway_id = '' ): string {
		$payment_type = strtoupper( trim( (string) ( $params['PaymentType'] ?? '' ) ) );
		$is_atm       = 'ys_ec_ecpay_atm' === strtolower( trim( $gateway_id ) )
			|| 1 === preg_match( '/^ATM(?:_|$)/D', $payment_type );
		$is_cvs       = 'ys_ec_ecpay_cvs' === strtolower( trim( $gateway_id ) )
			|| 1 === preg_match( '/^CVS(?:_|$)/D', $payment_type );

		if ( $is_atm ) {
			return trim( (string) ( $params['vAccount'] ?? '' ) );
		}
		if ( $is_cvs ) {
			return trim( (string) ( $params['PaymentNo'] ?? '' ) );
		}

		return trim( (string) ( $params['PaymentNo'] ?? '' ) );
	}

	/**
	 * @param array<string,string> $data
	 */
	private function is_offline_payment( object $order, array $data ): bool {
		$gateway_id = (string) ( $order->gateway_id ?? $order->payment_method ?? '' );
		$payment_type = strtoupper( (string) ( $data['PaymentType'] ?? '' ) );

		return str_contains( $gateway_id, 'atm' )
			|| str_contains( $gateway_id, 'cvs' )
			|| str_contains( $gateway_id, 'barcode' )
			|| str_contains( $payment_type, 'ATM' )
			|| str_contains( $payment_type, 'CVS' )
			|| str_contains( $payment_type, 'BARCODE' );
	}
}
