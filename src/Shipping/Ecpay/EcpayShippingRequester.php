<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\HttpFormClient;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayShippingRequester {
	private EcpayShipping $method;
	private HttpFormClient $http;

	public function __construct( EcpayShipping $method, ?HttpFormClient $http = null ) {
		$this->method = $method;
		$this->http   = $http ?: new HttpFormClient();
	}

	/**
	 * @param array<string,mixed> $order_data
	 * @return array<string,mixed>
	 */
	public function create_order( array $order_data ): array {
		$credentials = Settings::logistics_credentials();
		$fields = $this->build_create_fields( $order_data, $credentials );

		$result = $this->http->post( Settings::logistics_endpoint( '/Express/Create' ), $fields );
		if ( ! $result['success'] ) {
			return [
				'success' => false,
				'message' => $result['message'],
			];
		}

		$params = $result['params'];
		if ( ! $this->verify_create_response( $params, $credentials ) ) {
			return [
				'success'      => false,
				'message'      => 'ECPay logistics response signature verification failed.',
				'raw_response' => $result['body'],
			];
		}

		$rtn_code = (string) ( $params['RtnCode'] ?? '' );
		if ( ! in_array( $rtn_code, [ '1', '300' ], true ) ) {
			return [
				'success'      => false,
				'message'      => (string) ( $params['RtnMsg'] ?? 'ECPay logistics create failed.' ),
				'raw_response' => $result['body'],
			];
		}

		$tracking = (string) ( $params['CVSPaymentNo'] ?? $params['BookingNote'] ?? $params['AllPayLogisticsID'] ?? '' );

		// 🔴 v0.3.0：C2C 的**寄件代碼**必須完整帶回來。
		//
		// 店到店的流程是：賣家拿著綠界回的 `CVSPaymentNo`（寄貨編號）＋
		// `CVSValidationNo`（驗證碼）到門市寄件。少了它們，訂單進得來、**貨出不去**
		// ——而舊版只把 `CVSPaymentNo` 當成 tracking 混在一起，驗證碼整個丟掉。
		//
		// 7-11 的驗證碼是必要的第二段；其他超商可能不回，因此只在 C2C 且缺
		// `CVSPaymentNo` 時才算失敗（那是真的寄不出去）。
		$cvs_payment_no    = (string) ( $params['CVSPaymentNo'] ?? '' );
		$cvs_validation_no = (string) ( $params['CVSValidationNo'] ?? '' );

		if ( $this->method->is_c2c() && '' === $cvs_payment_no ) {
			return [
				'success'      => false,
				'message'      => 'C2C 店到店建立成功但未取得寄貨編號（CVSPaymentNo），賣家無法到門市寄件；請於綠界後台確認後重試。',
				'raw_response' => $params,
			];
		}

		return [
			'success'           => true,
			'label_id'          => (string) ( $params['AllPayLogisticsID'] ?? $fields['MerchantTradeNo'] ),
			'tracking_no'       => $tracking,
			'tracking_number'   => $tracking,
			'merchant_trade_no' => $fields['MerchantTradeNo'],
			'provider_trade_no' => (string) ( $params['AllPayLogisticsID'] ?? '' ),
			// 寄件代碼分開存：它們是「賣家怎麼把貨交出去」的依據，不是追蹤碼。
			'cvs_payment_no'    => $cvs_payment_no,
			'cvs_validation_no' => $cvs_validation_no,
			'is_c2c'            => $this->method->is_c2c(),
			'raw_response'      => $params,
			'message'           => (string) ( $params['RtnMsg'] ?? '' ),
		];
	}

	/**
	 * @param array<string,string> $params
	 * @param array{merchant_id:string,hash_key:string,hash_iv:string,test_mode:bool} $credentials
	 */
	private function verify_create_response( array $params, array $credentials ): bool {
		if ( '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv']
			|| empty( $params['CheckMacValue'] )
			|| (string) ( $params['MerchantID'] ?? '' ) !== $credentials['merchant_id'] ) {
			return false;
		}

		return CheckMacValue::verify( $params, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );
	}

	/**
	 * @param array<string,mixed> $order_data
	 * @param array{merchant_id:string,hash_key:string,hash_iv:string,test_mode:bool} $credentials
	 * @return array<string,string>
	 */
	private function build_create_fields( array $order_data, array $credentials ): array {
		$amount = max( 1, min( 20000, (int) round( (float) ( $order_data['product_amount'] ?? $order_data['total'] ?? 1 ) ) ) );
		$type   = $this->method->get_type();

		$fields = [
			'MerchantID'        => $credentials['merchant_id'],
			'MerchantTradeNo'   => $this->make_trade_no( (string) ( $order_data['order_number'] ?? '' ) ),
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
			'LogisticsType'     => 'cvs' === $type ? 'CVS' : 'HOME',
			'LogisticsSubType'  => $this->method->get_logistics_subtype(),
			'GoodsAmount'       => (string) $amount,
			'GoodsName'         => mb_substr( wp_strip_all_tags( (string) ( $order_data['product_name'] ?? 'YS CART Order' ) ), 0, 50 ),
			'SenderName'        => mb_substr( (string) ( $order_data['sender_name'] ?? Settings::get( Settings::SENDER_KEYS['name'], '' ) ), 0, 10 ),
			'SenderCellPhone'   => (string) ( $order_data['sender_phone'] ?? Settings::get( Settings::SENDER_KEYS['phone'], '' ) ),
			'ReceiverName'      => mb_substr( (string) ( $order_data['receiver_name'] ?? '' ), 0, 10 ),
			'ReceiverCellPhone' => (string) ( $order_data['receiver_phone'] ?? '' ),
			'ServerReplyURL'    => rest_url( 'ys-ecommerce/v1/ecpay/logistics-notify' ),
			// 🔴 v0.3.0：代收與否由**物流方式**回答，不再寫死 'N'。
			// 寫死的後果是：業主開了貨到付款，送出去的仍然是「不代收」——
			// 貨送到了，錢沒收。
			'IsCollection'      => $this->method->supports_cod() ? 'Y' : 'N',
		];

		if ( 'CVS' === $fields['LogisticsType'] ) {
			$fields['ReceiverStoreID'] = (string) ( $order_data['receiver_store_id'] ?? '' );

			// 🔴 v0.3.0：C2C（店到店）必填**退貨門市**。
			//
			// 綠界規定 C2C 建立訂單時必須指定退貨門市；沒有它，送單直接被拒。
			// 缺值時 fail-closed——不猜一個門市，那會讓退貨寄到別人家。
			if ( $this->method->is_c2c() ) {
				$return_store = $this->method->get_return_store_id();
				if ( '' === $return_store ) {
					throw new \RuntimeException(
						'C2C 店到店尚未設定退貨門市代號（綠界規定必填），已中止建立物流訂單。'
					);
				}

				$fields['ReturnStoreID'] = $return_store;
			}

			// 代收金額只在真的代收時才送。
			if ( 'Y' === $fields['IsCollection'] ) {
				$fields['CollectionAmount'] = (string) $amount;
			}
		} else {
			$fields['SenderZipCode']     = (string) ( $order_data['sender_zipcode'] ?? Settings::get( Settings::SENDER_KEYS['zipcode'], '' ) );
			$fields['SenderAddress']     = (string) ( $order_data['sender_address'] ?? Settings::get( Settings::SENDER_KEYS['address'], '' ) );
			$fields['ReceiverZipCode']   = (string) ( $order_data['receiver_zipcode'] ?? '' );
			$fields['ReceiverAddress']   = (string) ( $order_data['receiver_address'] ?? '' );
			// 🔴 v0.3.0：溫層由**物流方式**回答。
			//
			// 舊版讀 `$order_data['temperature_code']`，而那個 key 全 repo 只出現
			// 在這一行、沒有任何寫入點——因此宅配永遠送常溫（0001）。賣冷藏／
			// 冷凍商品時，貨到就已經退冰了。
			$fields['Temperature']       = $this->method->get_temperature_code();
			$fields['Distance']          = '00';
			$fields['Specification']     = '0001';
			$fields['ScheduledDeliveryTime'] = '4';
		}

		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );

		return array_map( 'strval', $fields );
	}

	private function make_trade_no( string $order_number ): string {
		$raw = preg_replace( '/[^A-Za-z0-9]/', '', $order_number );
		if ( '' === $raw || null === $raw ) {
			$raw = 'YS' . time();
		}
		return substr( $raw . 'L' . substr( (string) time(), -6 ), 0, 20 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_order( array $context = [] ): array {
		unset( $context );
		return [ 'success' => false, 'message' => 'ECPay logistics cancellation is not implemented.' ];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function query_status( string $tracking_number, array $context = [] ): array {
		unset( $tracking_number, $context );
		return [ 'success' => false, 'message' => 'ECPay logistics status query is not implemented.' ];
	}

	public function get_print_url( $provider_trade_no, array $context = [] ): string {
		unset( $context );
		$ids = is_array( $provider_trade_no ) ? $provider_trade_no : [ $provider_trade_no ];
		$ids = array_values( array_filter( array_map( static fn( $id ): string => sanitize_text_field( (string) $id ), $ids ) ) );
		if ( empty( $ids ) || ! Settings::has_logistics_credentials() ) {
			return '';
		}

		$credentials = Settings::logistics_credentials();
		$fields = [
			'MerchantID'        => $credentials['merchant_id'],
			'AllPayLogisticsID' => implode( ',', $ids ),
			'PlatformID'        => '',
			'PrintMode'         => '1',
		];
		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );

		$key = wp_generate_password( 24, false, false );
		set_transient( 'ys_ec_ecpay_print_' . $key, [
			'api_url' => Settings::logistics_endpoint( '/helper/printTradeDocument' ),
			'fields'  => $fields,
			'method_id' => $this->method->get_id(),
		], 10 * MINUTE_IN_SECONDS );

		return add_query_arg(
			[
				'action' => 'ys_cart_ecpay_print',
				'key'    => rawurlencode( $key ),
			],
			admin_url( 'admin-post.php' )
		);
	}
}
