<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayPaymentClient {
	private int $last_http_status = 0;

	public function get_last_http_status(): int {
		return $this->last_http_status;
	}

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

	/**
	 * Query ECPay before YS CART times out a pending payment.
	 *
	 * @return array{success:bool,data:array<string,string>|null,message:string}
	 */
	public function query_trade( string $merchant_trade_no ): array {
		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id'] || '' === $credentials['hash_key'] || '' === $credentials['hash_iv'] ) {
			return [
				'success' => false,
				'data'    => null,
				'message' => 'ECPay payment settings are incomplete.',
			];
		}

		$fields = [
			'MerchantID'      => $credentials['merchant_id'],
			'MerchantTradeNo' => $merchant_trade_no,
			'TimeStamp'       => (string) time(),
			'PlatformID'      => '',
		];
		$fields['CheckMacValue'] = CheckMacValue::generate(
			$fields,
			$credentials['hash_key'],
			$credentials['hash_iv'],
			'sha256'
		);

		$response = wp_remote_post(
			Settings::payment_query_endpoint(),
			[
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'        => http_build_query( $fields ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->last_http_status = 0;
			return [
				'success' => false,
				'data'    => null,
				'message' => $response->get_error_message(),
			];
		}

		$this->last_http_status = (int) wp_remote_retrieve_response_code( $response );
		$raw                    = (string) wp_remote_retrieve_body( $response );
		$data                   = [];
		parse_str( $raw, $data );
		$data = array_map( static fn ( mixed $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '', $data );

		if ( $this->last_http_status < 200 || $this->last_http_status >= 300 ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => 'ECPay query request failed.',
			];
		}

		if ( isset( $data['CheckMacValue'] )
			&& ! CheckMacValue::verify( $data, $credentials['hash_key'], $credentials['hash_iv'], 'sha256' ) ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => 'Invalid ECPay query CheckMacValue.',
			];
		}

		if ( empty( $data['MerchantTradeNo'] ) && empty( $data['TradeStatus'] ) ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => (string) ( $data['RtnMsg'] ?? $data['Message'] ?? 'ECPay query returned no trade data.' ),
			];
		}

		return [
			'success' => true,
			'data'    => $data,
			'message' => '',
		];
	}

	/**
	 * 信用卡退刷（CreditDetail/DoAction，Action=R）
	 *
	 * @deferred-sandbox（v0.3.0）：payload 欄位與回應格式依綠界文件實作
	 * （MerchantID/MerchantTradeNo/TradeNo/Action/TotalAmount + CheckMacValue，
	 * 回應為 urlencoded、RtnCode=1 成功），尚未以綠界測試環境實際對拍——
	 * 對拍通過前不得對外宣稱支援退刷。
	 *
	 * @param string $merchant_trade_no 商店訂單編號（建單時的 MerchantTradeNo）
	 * @param string $trade_no          綠界交易編號（付款回調存的 TradeNo）
	 * @param float  $amount            退刷金額（元；綠界信用卡退刷以整數新台幣計）
	 * @return array{success:bool, data:?array, message:string}
	 */
	public function do_action_refund( string $merchant_trade_no, string $trade_no, float $amount ): array {
		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id'] || '' === $credentials['hash_key'] || '' === $credentials['hash_iv'] ) {
			return [
				'success' => false,
				'data'    => null,
				'message' => 'ECPay payment settings are incomplete.',
			];
		}

		// fail-closed 前置驗證：識別碼與金額缺一不可。
		if ( '' === $merchant_trade_no || '' === $trade_no ) {
			return [
				'success' => false,
				'data'    => null,
				'message' => '缺少綠界交易識別碼（MerchantTradeNo / TradeNo），無法退刷。',
			];
		}
		$total_amount = (int) round( $amount );
		if ( $total_amount <= 0 ) {
			return [
				'success' => false,
				'data'    => null,
				'message' => '退刷金額必須為正數。',
			];
		}

		$fields = [
			'MerchantID'      => $credentials['merchant_id'],
			'MerchantTradeNo' => $merchant_trade_no,
			'TradeNo'         => $trade_no,
			'Action'          => 'R',
			'TotalAmount'     => (string) $total_amount,
			'PlatformID'      => '',
		];
		$fields['CheckMacValue'] = CheckMacValue::generate(
			$fields,
			$credentials['hash_key'],
			$credentials['hash_iv'],
			'sha256'
		);

		$response = wp_remote_post(
			Settings::payment_do_action_endpoint(),
			[
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'        => http_build_query( $fields ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->last_http_status = 0;
			return [
				'success' => false,
				'data'    => null,
				'message' => $response->get_error_message(),
			];
		}

		$this->last_http_status = (int) wp_remote_retrieve_response_code( $response );
		$raw                    = (string) wp_remote_retrieve_body( $response );
		$data                   = [];
		parse_str( $raw, $data );
		$data = array_map( static fn ( mixed $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '', $data );

		if ( $this->last_http_status < 200 || $this->last_http_status >= 300 ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => 'ECPay DoAction request failed.',
			];
		}

		// DoAction 回應以 RtnCode=1 為成功；其餘一律 fail-closed。
		if ( '1' !== (string) ( $data['RtnCode'] ?? '' ) ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => (string) ( $data['RtnMsg'] ?? 'ECPay 退刷失敗（未知原因）。' ),
			];
		}

		return [
			'success' => true,
			'data'    => $data,
			'message' => '',
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
