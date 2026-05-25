<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayPaymentClient {
	/**
	 * @param object $order
	 * @return array{action_url:string,fields:array<string,string>}
	 */
	public function build_aio_form( object $order, string $merchant_trade_no, string $choose_payment ): array {
		$credentials = Settings::payment_credentials();
		$amount      = max( 1, (int) round( (float) ( $order->total ?? 0 ) ) );
		$item_name   = $this->clean_item_name( $order );

		$fields = [
			'MerchantID'        => $credentials['merchant_id'],
			'MerchantTradeNo'   => $merchant_trade_no,
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
			'PaymentType'       => 'aio',
			'TotalAmount'       => (string) $amount,
			'TradeDesc'         => mb_substr( 'YS CART order ' . (string) ( $order->order_number ?? $order->id ?? '' ), 0, 200 ),
			'ItemName'          => $item_name,
			'ReturnURL'         => rest_url( 'ys-ecommerce/v1/ecpay/notify' ),
			'OrderResultURL'    => rest_url( 'ys-ecommerce/v1/ecpay/return' ),
			'ClientBackURL'     => home_url( '/checkout/thankyou/' ),
			'ChoosePayment'     => $choose_payment,
			'EncryptType'       => '1',
			'PaymentInfoURL'    => rest_url( 'ys-ecommerce/v1/ecpay/payment-info' ),
		];

		$fields['CheckMacValue'] = CheckMacValue::generate(
			$fields,
			$credentials['hash_key'],
			$credentials['hash_iv'],
			'sha256'
		);

		return [
			'action_url' => Settings::payment_endpoint(),
			'fields'     => $fields,
		];
	}

	private function clean_item_name( object $order ): string {
		$base = 'YS CART Order ' . (string) ( $order->order_number ?? $order->id ?? '' );
		$base = wp_strip_all_tags( $base );
		$base = preg_replace( '/[\x00-\x1F\x7F]/u', '', $base ) ?: $base;
		$base = mb_substr( $base, 0, 190 );

		return '' !== trim( $base ) ? $base : 'YS CART Order';
	}
}

