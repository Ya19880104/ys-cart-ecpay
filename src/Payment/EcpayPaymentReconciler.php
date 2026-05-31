<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcileResult;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcilerInterface;

final class EcpayPaymentReconciler implements YSPaymentReconcilerInterface {
	public function supports( object $order ): bool {
		$detail = $this->payment_detail( $order );
		$gateway_id = (string) ( $order->gateway_id ?? $order->payment_method ?? '' );

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

		$payment_detail = $this->detail_from_query( $data, (string) ( $order->gateway_id ?? '' ) );
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
			'trade_no'         => (string) ( $params['TradeNo'] ?? '' ),
			'gateway_trade_no' => (string) ( $params['TradeNo'] ?? '' ),
			'mer_trade_no'     => (string) ( $params['MerchantTradeNo'] ?? '' ),
			'response_code'    => (string) ( $params['TradeStatus'] ?? '' ),
			'response_message' => (string) ( $params['RtnMsg'] ?? $params['TradeStatus'] ?? '' ),
			'pay_no'           => (string) ( $params['PaymentNo'] ?? $params['BankCode'] ?? $params['vAccount'] ?? '' ),
			'bank_type'        => (string) ( $params['BankCode'] ?? '' ),
			'expire_date'      => (string) ( $params['ExpireDate'] ?? '' ),
			'card_4no'         => (string) ( $params['card4no'] ?? $params['Card4No'] ?? '' ),
			'card_6no'         => (string) ( $params['card6no'] ?? $params['Card6No'] ?? '' ),
			'auth_code'        => (string) ( $params['auth_code'] ?? $params['AuthCode'] ?? '' ),
		];

		return YSPaymentDetailDTO::from_legacy_array( $detail, $gateway_id );
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
