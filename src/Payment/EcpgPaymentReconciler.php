<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcileResult;
use YangSheep\Ecommerce\Services\Payment\YSPaymentReconcilerInterface;
use YangSheep\YSCartEcpay\Ecpg\EcpgClient;
use YangSheep\YSCartEcpay\Ecpg\EcpgOrderContext;
use YangSheep\YSCartEcpay\Support\Settings;

/** Reconcile ECPG orders through the AES-JSON QueryTrade protocol. */
final class EcpgPaymentReconciler implements YSPaymentReconcilerInterface {
	public function supports( object $order ): bool {
		$detail = $this->payment_detail( $order );
		$gateway_id = (string) ( $order->gateway_id ?? $order->payment_method ?? '' );
		return EcpgOrderContext::GATEWAY_ID === $gateway_id
			|| EcpgOrderContext::GATEWAY_ID === (string) ( $detail['payment_method'] ?? '' );
	}

	public function reconcile( object $order ): YSPaymentReconcileResult {
		$detail = $this->payment_detail( $order );
		$merchant_trade_no = (string) ( $detail['ecpay_merchant_trade_no'] ?? $detail['mer_trade_no'] ?? '' );
		if ( '' === $merchant_trade_no || 1 !== preg_match( '/^[A-Za-z0-9]{1,20}$/D', $merchant_trade_no ) ) {
			return YSPaymentReconcileResult::unsupported( 'ECPG merchant trade number is missing or malformed.' );
		}

		$result = ( new EcpgClient() )->query_trade( $merchant_trade_no );
		$data = is_array( $result['data'] ?? null ) ? $result['data'] : [];
		if ( EcpgClient::OUTCOME_INDETERMINATE === ( $result['outcome'] ?? null ) ) {
			return YSPaymentReconcileResult::hold( (string) ( $result['message'] ?? 'ECPG query is indeterminate.' ), null, $data );
		}
		if ( EcpgClient::OUTCOME_SUCCESS !== ( $result['outcome'] ?? null ) ) {
			return YSPaymentReconcileResult::error( (string) ( $result['message'] ?? 'ECPG query was rejected.' ), null, $data );
		}

		$credentials = Settings::payment_credentials();
		$merchant_id = (string) ( $data['MerchantID'] ?? '' );
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$reported_trade_no = (string) ( $order_info['MerchantTradeNo'] ?? '' );
		$expected_merchant = (string) ( $detail['ecpay_merchant_id'] ?? $credentials['merchant_id'] ?? '' );
		if ( '' === $expected_merchant
			|| ! hash_equals( $expected_merchant, $merchant_id )
			|| ! hash_equals( $merchant_trade_no, $reported_trade_no ) ) {
			return YSPaymentReconcileResult::error( 'ECPG query identity does not match this order.', null, $data );
		}

		$expected_amount = $this->canonical_positive_int( $detail['ecpay_charged_amount'] ?? null );
		$reported_amount = $this->canonical_positive_int( $order_info['TradeAmt'] ?? null );
		if ( null === $expected_amount || null === $reported_amount || $expected_amount !== $reported_amount ) {
			return YSPaymentReconcileResult::error( 'ECPG query amount does not match this order.', null, $data );
		}

		$payment_detail = $this->detail_from_query( $data, $merchant_trade_no, $reported_amount );
		$trade_status = EcpgClient::trade_status( $data );
		if ( '1' === $trade_status ) {
			if ( '' === (string) ( $order_info['TradeNo'] ?? '' ) ) {
				return YSPaymentReconcileResult::error( 'ECPG paid query omitted the provider trade number.', null, $data );
			}
			return YSPaymentReconcileResult::paid( $payment_detail, 'ECPG query confirmed payment.', $data );
		}
		if ( '0' === $trade_status ) {
			return YSPaymentReconcileResult::hold( 'ECPG query confirmed the trade is not paid yet.', $payment_detail, $data );
		}
		return YSPaymentReconcileResult::hold( 'ECPG query returned an unknown trade state.', $payment_detail, $data );
	}

	/** @return array<string,mixed> */
	private function payment_detail( object $order ): array {
		if ( is_array( $order->payment_detail ?? null ) ) {
			return $order->payment_detail;
		}
		$decoded = json_decode( (string) ( $order->payment_detail ?? '{}' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private function canonical_positive_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}
		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			return null;
		}
		return (int) $value;
	}

	private function detail_from_query( array $data, string $merchant_trade_no, int $amount ): YSPaymentDetailDTO {
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$card_info = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$trade_no = (string) ( $order_info['TradeNo'] ?? '' );
		return YSPaymentDetailDTO::from_legacy_array( [
			'payment_type'     => 'credit_card',
			'trade_status'     => (string) ( $order_info['TradeStatus'] ?? '' ),
			'paid_amount'      => (float) $amount,
			'trade_no'         => $trade_no,
			'gateway_trade_no' => $trade_no,
			'mer_trade_no'     => $merchant_trade_no,
			'response_code'    => (string) ( $data['RtnCode'] ?? '' ),
			'response_message' => (string) ( $data['RtnMsg'] ?? '' ),
			'card_4no'         => (string) ( $card_info['Card4No'] ?? '' ),
			'card_6no'         => (string) ( $card_info['Card6No'] ?? '' ),
			'auth_code'        => (string) ( $card_info['AuthCode'] ?? '' ),
		], EcpgOrderContext::GATEWAY_ID );
	}
}
