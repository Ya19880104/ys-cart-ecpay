<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
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
		// 🔴 R14 reader lease：付款表單以當下 payment 憑證簽 CMV——設定 commit
		// 期間簽出的表單可能帶著「隨後被回滾／被換掉」的 signer，顧客送回時
		// 必失驗。lease 拿不到＝丟例外，caller 以「未送出任何付款」語意拒絕。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			throw new \RuntimeException( '綠界設定維護中（簽章憑證變更進行中或前次變更未完成），請稍後再試。' );
		}

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

		// 🔴 表單交付＝這條路徑的「送出」（顧客瀏覽器隨後 POST 給綠界）——交付前
		// own-row fence：stalled 而被 writer 收割的 request 不得再交出舊簽章表單。
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			throw new \RuntimeException( '綠界設定維護窗與本次付款重疊，請稍後再試。' );
		}

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
		// 🔴 R14 reader lease（同 build_aio_form；查詢請求簽章＋回應驗章都用
		// 當下憑證）。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return [
				'success' => false,
				'data'    => null,
				'message' => '綠界設定維護中（簽章憑證變更進行中或前次變更未完成），本次未送出查詢，請稍後再試。',
			];
		}

		$credentials = Settings::payment_credentials();
		$merchant_trade_no = trim( $merchant_trade_no );
		if ( '' === $merchant_trade_no
			|| '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv'] ) {
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

		// 🔴 R14 pre-send fence。
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return [
				'success' => false,
				'data'    => null,
				'message' => '綠界設定維護窗與本次查詢重疊，本次未送出查詢，請稍後再試。',
			];
		}

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

		if ( '' === (string) ( $data['CheckMacValue'] ?? '' )
			|| ! CheckMacValue::verify( $data, $credentials['hash_key'], $credentials['hash_iv'], 'sha256' ) ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => 'Invalid ECPay query CheckMacValue.',
			];
		}

		$returned_merchant_id = (string) ( $data['MerchantID'] ?? '' );
		$returned_trade_no    = (string) ( $data['MerchantTradeNo'] ?? '' );
		$trade_status         = (string) ( $data['TradeStatus'] ?? '' );
		$provider_trade_no    = (string) ( $data['TradeNo'] ?? '' );
		$trade_amount         = (string) ( $data['TradeAmt'] ?? '' );

		if ( '' === $returned_merchant_id
			|| ! hash_equals( $credentials['merchant_id'], $returned_merchant_id )
			|| '' === $returned_trade_no
			|| ! hash_equals( $merchant_trade_no, $returned_trade_no )
			|| '' === $trade_status ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => 'ECPay query response identity is invalid.',
			];
		}

		if ( '1' === $trade_status
			&& ( '' === $provider_trade_no
				|| ! ctype_digit( $trade_amount )
				|| (int) $trade_amount <= 0 ) ) {
			return [
				'success' => false,
				'data'    => $data,
				'message' => 'ECPay paid query response is incomplete.',
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
