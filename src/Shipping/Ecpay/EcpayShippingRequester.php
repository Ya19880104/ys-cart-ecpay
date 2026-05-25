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

		return [
			'success'           => true,
			'label_id'          => (string) ( $params['AllPayLogisticsID'] ?? $fields['MerchantTradeNo'] ),
			'tracking_no'       => $tracking,
			'tracking_number'   => $tracking,
			'merchant_trade_no' => $fields['MerchantTradeNo'],
			'provider_trade_no' => (string) ( $params['AllPayLogisticsID'] ?? '' ),
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
			'IsCollection'      => 'N',
		];

		if ( 'CVS' === $fields['LogisticsType'] ) {
			$fields['ReceiverStoreID'] = (string) ( $order_data['receiver_store_id'] ?? '' );
			if ( 'UNIMART' === $fields['LogisticsSubType'] ) {
				$fields['CollectionAmount'] = (string) $amount;
			}
		} else {
			$fields['SenderZipCode']     = (string) ( $order_data['sender_zipcode'] ?? Settings::get( Settings::SENDER_KEYS['zipcode'], '' ) );
			$fields['SenderAddress']     = (string) ( $order_data['sender_address'] ?? Settings::get( Settings::SENDER_KEYS['address'], '' ) );
			$fields['ReceiverZipCode']   = (string) ( $order_data['receiver_zipcode'] ?? '' );
			$fields['ReceiverAddress']   = (string) ( $order_data['receiver_address'] ?? '' );
			$fields['Temperature']       = (string) ( $order_data['temperature_code'] ?? '0001' );
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
