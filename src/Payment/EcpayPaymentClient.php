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
	 * 信用卡交易關帳狀態查詢（CreditDetail/QueryTrade/V2）— query-first 退款分流用
	 *
	 * @deferred-live-verification：欄位名（CreditRefundId=gwsr 授權單號）、回應
	 * JSON 結構（RtnValue.status：已授權／要關帳／已關帳…）依綠界文件實作，
	 * 確切契約須以受控正式商店實測鎖定（gate G-Q）。未知/未映射狀態一律回
	 * `unknown`——caller 必須拒絕操作（fail-closed）。
	 *
	 * @param string $gwsr   綠界授權單號（QueryTradeInfo 回應的 gwsr）
	 * @param int    $amount 交易金額（元）
	 * @return array{success:bool, state:string, raw:?array, message:string}
	 *               state ∈ authorized|to_close|closed|unknown
	 */
	public function query_credit_close_status( string $gwsr, int $amount ): array {
		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id'] || '' === $credentials['hash_key'] || '' === $credentials['hash_iv'] ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => 'ECPay payment settings are incomplete.' ];
		}
		if ( '' === $gwsr || $amount <= 0 ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => '缺少授權單號（gwsr）或金額，無法查詢關帳狀態。' ];
		}

		$fields = [
			'MerchantID'     => $credentials['merchant_id'],
			'CreditRefundId' => $gwsr,
			'CreditAmount'   => (string) $amount,
			'CreditCheckCode' => '',
		];
		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'sha256' );

		$response = wp_remote_post(
			Settings::payment_credit_query_endpoint(),
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
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => $response->get_error_message() ];
		}

		$this->last_http_status = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $this->last_http_status < 200 || $this->last_http_status >= 300 || ! is_array( $data ) ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => 'ECPay credit query failed.' ];
		}

		$status_text = (string) ( $data['RtnValue']['status'] ?? $data['status'] ?? '' );
		$state_map   = [
			'已授權' => 'authorized',
			'要關帳' => 'to_close',
			'已關帳' => 'closed',
		];
		$state = $state_map[ $status_text ] ?? 'unknown';

		return [
			'success' => 'unknown' !== $state,
			'state'   => $state,
			'raw'     => $data,
			'message' => 'unknown' === $state ? "未映射的關帳狀態：{$status_text}" : '',
		];
	}

	/**
	 * 信用卡請退款操作（CreditDetail/DoAction）
	 *
	 * Action 語意（依 query-first 關帳狀態，caller 決定；官方流程）：
	 *   - N＝放棄請款／取消授權（已授權未關帳；僅全額）
	 *   - E＝取消關帳（要關帳狀態、全額退款時先 E 再 N）
	 *   - R＝退刷（要關帳的部分退款、或已關帳交易）
	 *
	 * 回傳 `indeterminate`：**傳輸層不確定**（wp_error／非 2xx／回應無 RtnCode）＝
	 * 綠界端可能已生效——caller 必須維持 pending、禁止盲重送；只有 RtnCode 明確
	 * 非 1 才是 provider 明確拒絕（可重試）。
	 *
	 * @deferred-live-verification：payload 與回應依綠界文件實作；stage 環境
	 * DoAction 官方明載不可用，驗證一律走受控正式商店（docs/credit-refund-sandbox-gate.md）。
	 *
	 * @param string $merchant_trade_no 商店訂單編號（建單時的 MerchantTradeNo）
	 * @param string $trade_no          綠界交易編號（付款回調存的 TradeNo）
	 * @param float  $amount            金額（元；綠界信用卡以整數新台幣計）
	 * @param string $action            'R'／'N'／'E'
	 * @return array{success:bool, indeterminate:bool, data:?array, message:string}
	 */
	public function do_action_refund( string $merchant_trade_no, string $trade_no, float $amount, string $action = 'R' ): array {
		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id'] || '' === $credentials['hash_key'] || '' === $credentials['hash_iv'] ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => null,
				'message' => 'ECPay payment settings are incomplete.',
			];
		}

		// fail-closed 前置驗證：識別碼、金額、動作缺一不可。
		if ( ! in_array( $action, [ 'R', 'N', 'E' ], true ) ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => null,
				'message' => '不支援的 DoAction 動作（僅允許 R／N／E）。',
			];
		}
		if ( '' === $merchant_trade_no || '' === $trade_no ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => null,
				'message' => '缺少綠界交易識別碼（MerchantTradeNo / TradeNo），無法退刷。',
			];
		}
		$total_amount = (int) round( $amount );
		if ( $total_amount <= 0 ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => null,
				'message' => '退刷金額必須為正數。',
			];
		}

		$fields = [
			'MerchantID'      => $credentials['merchant_id'],
			'MerchantTradeNo' => $merchant_trade_no,
			'TradeNo'         => $trade_no,
			'Action'          => $action,
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
			// 傳輸層失敗：請求可能已抵達綠界並生效 → indeterminate（禁止盲重送）。
			$this->last_http_status = 0;
			return [
				'success' => false,
				'indeterminate' => true,
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
			// 非 2xx：結果不確定（可能已處理）→ indeterminate。
			return [
				'success' => false,
				'indeterminate' => true,
				'data'    => $data,
				'message' => 'ECPay DoAction request failed.',
			];
		}

		// 回應無 RtnCode（無法解析）：不確定 → indeterminate。
		if ( ! isset( $data['RtnCode'] ) || '' === (string) $data['RtnCode'] ) {
			return [
				'success' => false,
				'indeterminate' => true,
				'data'    => $data,
				'message' => 'ECPay DoAction 回應無法解析（無 RtnCode）。',
			];
		}

		// provider 明確拒絕（RtnCode 存在且非 1）：可安全重試。
		if ( '1' !== (string) $data['RtnCode'] ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => $data,
				'message' => (string) ( $data['RtnMsg'] ?? 'ECPay 退刷失敗（未知原因）。' ),
			];
		}

		return [
			'success' => true,
			'indeterminate' => false,
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
