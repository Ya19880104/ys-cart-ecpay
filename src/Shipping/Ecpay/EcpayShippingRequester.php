<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\HttpFormClient;
use YangSheep\YSCartEcpay\Support\Settings;

/**
 * 綠界物流建單／列印
 *
 * 所有 wire 欄位一律由 {@see EcpayShippingCatalog} 的 descriptor 導出，
 * 沒有任何一個值是在這裡寫死的。
 */
final class EcpayShippingRequester {
	/** 核心的貨到付款金流 ID。 */
	private const COD_GATEWAY_ID = 'ys_ec_cod';

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
		$fields      = $this->build_create_fields( $order_data, $credentials );

		$result = $this->http->post( Settings::logistics_endpoint( '/Express/Create' ), $fields );
		if ( ! $result['success'] ) {
			return [
				'success'           => false,
				'message'           => $result['message'],
				'merchant_trade_no' => $fields['MerchantTradeNo'],
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
				'raw_response' => $params,
			];
		}

		// 🔴 回應必須帶回這個方式的「貨才出得去」欄位，缺一個就當建單失敗。
		//
		// C2C 的流程是：賣家拿綠界回的 `CVSPaymentNo`（寄貨編號）＋ 7-ELEVEN 另需的
		// `CVSValidationNo`（驗證碼）到門市機台寄件。少了它們，訂單進得來、**貨出
		// 不去**——而且列印 C2C 託運單的 API 就是要這兩個欄位。
		//
		// 舊版只把 `CVSPaymentNo` 混進 tracking，驗證碼整個丟掉。
		$missing = $this->missing_required_fields( $params );
		if ( [] !== $missing ) {
			return [
				'success'      => false,
				'message'      => sprintf(
					'綠界建單回應缺少必要欄位（%s），此物流方式無法出貨；請於綠界後台確認該服務是否已開通後重試。',
					implode( '、', $missing )
				),
				'raw_response' => $params,
			];
		}

		$cvs_payment_no    = trim( (string) ( $params['CVSPaymentNo'] ?? '' ) );
		$cvs_validation_no = trim( (string) ( $params['CVSValidationNo'] ?? '' ) );

		// 🔴 追蹤碼**只**取託運單號（BookingNote）。
		//
		// 舊版沒有它時退回寄貨編號、再退回物流編號——三個語意完全不同的值被塞進
		// 同一個欄位：顧客拿去物流商網站查會查不到，而客服看到「有追蹤碼」就不會
		// 去查為什麼還沒出貨。沒有就是沒有（回單通知會補上）。
		$tracking = trim( (string) ( $params['BookingNote'] ?? '' ) );

		return [
			'success'           => true,
			'tracking_no'       => $tracking,
			'tracking_number'   => $tracking,
			'merchant_trade_no' => $fields['MerchantTradeNo'],
			// 🔴 只認明確的 AllPayLogisticsID。上面的 required_response_fields 已經
			// 保證它非空——不得 fallback 到 MerchantTradeNo，那會讓後續的查詢、
			// 取消、回呼比對全部指向一張不存在的單。
			'provider_trade_no' => trim( (string) $params['AllPayLogisticsID'] ),
			// 寄件代碼分開存：它們是「賣家怎麼把貨交出去」的依據，不是追蹤碼。
			'cvs_payment_no'    => $cvs_payment_no,
			'cvs_validation_no' => $cvs_validation_no,
			// 核心把它寫進 label，之後回呼要用它比對這張單到底是哪一個方式。
			'logistics_subtype' => $this->method->get_logistics_subtype(),
			'shipping_method'   => $this->method->get_id(),
			'is_c2c'            => $this->method->is_c2c(),
			'raw_response'      => $params,
			'message'           => (string) ( $params['RtnMsg'] ?? '' ),
		];
	}

	/**
	 * 回應裡缺了哪些「沒有它就出不了貨」的欄位。
	 *
	 * @param array<string,string> $params
	 * @return array<int,string>
	 */
	private function missing_required_fields( array $params ): array {
		$missing = [];
		foreach ( $this->method->required_response_fields() as $field ) {
			if ( '' === trim( (string) ( $params[ $field ] ?? '' ) ) ) {
				$missing[] = $field;
			}
		}

		return $missing;
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

		// 🔴 `_status_prefix` 是 HttpFormClient 解析 `1|Key=Val&…` 這種回應時**自己加**
		// 的欄位，綠界從來沒送過它，因此它不在簽章的計算範圍內。把它一起丟進
		// CheckMacValue::verify() 會讓每一筆帶前綴的回應都驗不過——症狀是建單其實
		// 成功了，本地卻判定「簽章驗證失敗」，於是物流單變成孤兒。
		//
		// 一律剔除底線開頭的合成欄位再驗。
		$signed = [];
		foreach ( $params as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_' ) ) {
				continue;
			}
			$signed[ $key ] = $value;
		}

		return CheckMacValue::verify( $signed, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );
	}

	/**
	 * @param array<string,mixed> $order_data
	 * @param array{merchant_id:string,hash_key:string,hash_iv:string,test_mode:bool} $credentials
	 * @return array<string,string>
	 */
	private function build_create_fields( array $order_data, array $credentials ): array {
		$amount         = max( 1, min( 20000, (int) round( (float) ( $order_data['product_amount'] ?? $order_data['total'] ?? 1 ) ) ) );
		$logistics_type = $this->method->get_logistics_type();
		$is_collection  = $this->resolve_is_collection( $order_data );

		$fields = [
			'MerchantID'        => $credentials['merchant_id'],
			'MerchantTradeNo'   => $this->make_trade_no( $order_data ),
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
			'LogisticsType'     => $logistics_type,
			'LogisticsSubType'  => $this->method->get_logistics_subtype(),
			'GoodsAmount'       => (string) $amount,
			'GoodsName'         => mb_substr( wp_strip_all_tags( (string) ( $order_data['product_name'] ?? 'YS CART Order' ) ), 0, 50 ),
			'SenderName'        => mb_substr( (string) ( $order_data['sender_name'] ?? Settings::get( Settings::SENDER_KEYS['name'], '' ) ), 0, 10 ),
			'SenderCellPhone'   => (string) ( $order_data['sender_phone'] ?? Settings::get( Settings::SENDER_KEYS['phone'], '' ) ),
			'ReceiverName'      => mb_substr( (string) ( $order_data['receiver_name'] ?? '' ), 0, 10 ),
			'ReceiverCellPhone' => (string) ( $order_data['receiver_phone'] ?? '' ),
			'ServerReplyURL'    => rest_url( 'ys-ecommerce/v1/ecpay/logistics-notify' ),
			// 🔴 代收與否由**訂單實際的付款方式**決定，不是物流方式的能力，
			// 也不是一個後台開關。見 resolve_is_collection()。
			'IsCollection'      => $is_collection ? 'Y' : 'N',
		];

		if ( 'CVS' === $logistics_type ) {
			$fields += $this->build_cvs_fields( $order_data );
		} else {
			$fields += $this->build_home_fields( $order_data );
		}

		// 綠界載明 UNIMART／UNIMARTC2C／UNIMARTFREEZE 需一併傳 CollectionAmount
		// （值等於 GoodsAmount），否則建單失敗；代收時所有方式都要帶。
		if ( $is_collection || $this->method->requires_collection_amount() ) {
			$fields['CollectionAmount'] = (string) $amount;
		}

		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );

		return array_map( 'strval', $fields );
	}

	/**
	 * 這張訂單要不要代收貨款？
	 *
	 * 🔴 這是**訂單的事實**，不是物流方式的能力，也不是一個後台開關。
	 *
	 * 舊版把兩者混在一起：`supports_cod()` 讀一個後台設定，requester 直接拿它當
	 * `IsCollection`。後果是——只要業主把那個開關打開，**線上已經刷卡付完的訂單
	 * 也會送出 `IsCollection=Y`**，顧客到門市取貨時被再收一次錢。
	 *
	 * 現在只認一件事：這張訂單的付款方式是不是貨到付款。
	 *
	 * @param array<string,mixed> $order_data
	 */
	private function resolve_is_collection( array $order_data ): bool {
		// 缺欄位不是 false，是「無法證明」。猜錯任一邊都會出事（漏收 or 重複收），
		// 所以寧可中止，讓呼叫端把訂單的付款方式帶進來。
		if ( ! array_key_exists( 'payment_method', $order_data ) ) {
			throw new \RuntimeException(
				'建立綠界物流單時無法判定這張訂單是否為貨到付款（order_data 缺少 payment_method），已中止。'
			);
		}

		$payment_method = trim( (string) $order_data['payment_method'] );
		if ( self::COD_GATEWAY_ID !== $payment_method ) {
			return false;
		}

		// 訂單說要代收，但這個通路不能代收——這是矛盾，不是可以「盡力而為」的情況。
		if ( ! $this->method->supports_cod() ) {
			throw new \RuntimeException(
				sprintf(
					'訂單為貨到付款，但物流方式 %s 不支援代收貨款，已中止建立物流訂單。',
					$this->method->get_id()
				)
			);
		}

		return true;
	}

	/**
	 * 超商專屬欄位。
	 *
	 * @param array<string,mixed> $order_data
	 * @return array<string,string>
	 */
	private function build_cvs_fields( array $order_data ): array {
		$store_id = trim( (string) ( $order_data['receiver_store_id'] ?? '' ) );

		// 綠界明載門市代號必須來自電子地圖，不可手填猜測；空值送出去只會拿到
		// 一個難查的錯誤碼，不如在這裡就說清楚。
		if ( '' === $store_id ) {
			throw new \RuntimeException(
				'尚未選擇取貨門市（收件門市代號為空），已中止建立綠界物流訂單。'
			);
		}

		$fields = [ 'ReceiverStoreID' => $store_id ];

		// 🔴 `ReturnStoreID` 是**選填**，而且**只有 7-ELEVEN C2C（UNIMARTC2C）吃**
		// ——官方規格明載「未設定時退回原寄件門市」。
		//
		// 因此：不適用的 subtype 一個字都不送（送了是把別人的合約塞給它），
		// 適用但業主沒填也不是錯誤（綠界有預設行為），只在真的有值時才帶上去。
		if ( $this->method->supports_return_store() ) {
			$return_store = $this->method->get_return_store_id();
			if ( '' !== $return_store ) {
				$fields['ReturnStoreID'] = $return_store;
			}
		}

		return $fields;
	}

	/**
	 * 宅配專屬欄位。
	 *
	 * @param array<string,mixed> $order_data
	 * @return array<string,string>
	 */
	private function build_home_fields( array $order_data ): array {
		$fields = [
			'SenderZipCode'   => (string) ( $order_data['sender_zipcode'] ?? Settings::get( Settings::SENDER_KEYS['zipcode'], '' ) ),
			'SenderAddress'   => (string) ( $order_data['sender_address'] ?? Settings::get( Settings::SENDER_KEYS['address'], '' ) ),
			'ReceiverZipCode' => (string) ( $order_data['receiver_zipcode'] ?? '' ),
			'ReceiverAddress' => (string) ( $order_data['receiver_address'] ?? '' ),
		];

		// 🔴 溫層／距離／規格／指定時段是**黑貓的合約**。綠界官方明載中華郵政
		// 「請忽略」這些欄位，因此由 descriptor 決定送不送，而不是所有宅配一視同仁。
		//
		// 另外：溫層是**物流方式的屬性**。舊版讀 `$order_data['temperature_code']`，
		// 而那個 key 全 repo 只出現在讀取的那一行、沒有任何寫入點——因此宅配永遠
		// 送常溫（0001）。賣冷藏／冷凍商品時，貨到就已經退冰了。
		if ( $this->method->sends_home_conditions() ) {
			$fields['Temperature']           = $this->method->get_temperature_code();
			$fields['Distance']              = '00';
			$fields['Specification']         = '0001';
			$fields['ScheduledDeliveryTime'] = '4';
		}

		// 🔴 綠界官方明載：LogisticsSubType=POST 時 GoodsWeight 必填（上限 20 公斤）。
		// 舊版完全沒送這個欄位，郵局宅配根本建不了單。
		if ( $this->method->requires_goods_weight() ) {
			$fields['GoodsWeight'] = $this->resolve_goods_weight( $order_data );
		}

		return $fields;
	}

	/**
	 * 包裹重量（公斤，最多三位小數，上限 20）。
	 *
	 * 優先用訂單算出來的重量；商品沒填重量的站台會算出 0，此時退回後台的預設值。
	 * 兩邊都沒有就中止——猜一個重量會讓郵資算錯，而且錯在賣家身上。
	 *
	 * @param array<string,mixed> $order_data
	 */
	private function resolve_goods_weight( array $order_data ): string {
		// 🔴 null＝核心讀取失敗（SQL error），不是「這張訂單沒有重量」。
		// 把它退回後台預設值，等於拿一個猜來的重量送單。
		if ( array_key_exists( 'goods_weight', $order_data ) && null === $order_data['goods_weight'] ) {
			throw new \RuntimeException(
				'無法讀取訂單的商品重量（資料庫查詢失敗），中華郵政必填此欄位，已中止建立物流訂單。'
			);
		}

		$weight = (float) ( $order_data['goods_weight'] ?? 0 );
		if ( $weight <= 0.0 ) {
			$weight = $this->method->get_default_goods_weight();
		}

		if ( $weight <= 0.0 ) {
			throw new \RuntimeException(
				sprintf(
					'物流方式 %s 需要包裹重量（綠界規定中華郵政必填），但訂單與後台預設值都沒有，已中止建立物流訂單。',
					$this->method->get_id()
				)
			);
		}

		// 🔴 超過上限一律中止，**不 clamp**。
		//
		// 把 25 公斤悄悄改成 20 公斤送出去，綠界收下的是一張錯的單：運費算錯、
		// 到了門市才發現超重被退，而系統這邊看起來一切正常。
		if ( $weight > 20.0 ) {
			throw new \RuntimeException(
				sprintf(
					'包裹重量 %s 公斤超過中華郵政上限 20 公斤，已中止建立物流訂單（請改用其他物流方式或分批出貨）。',
					number_format( $weight, 3, '.', '' )
				)
			);
		}

		return number_format( $weight, 3, '.', '' );
	}

	/**
	 * 特店交易編號。
	 *
	 * 🔴 **由核心的建單授權提供**，不是在這裡編的。
	 *
	 * 授權在打給綠界**之前**就把這個編號落盤了，因此「本地那一列」與「綠界那張單」
	 * 必然對得起來。供應商自己另外編一個的話，回應遺失時本地就查不到那張單——
	 * 而查不到的下一步通常是再送一次，也就是同一張訂單出兩次貨。
	 *
	 * @param array<string,mixed> $order_data
	 */
	private function make_trade_no( array $order_data ): string {
		$trade_no = trim( (string) ( $order_data['merchant_trade_no'] ?? '' ) );
		if ( '' === $trade_no ) {
			throw new \RuntimeException(
				'建立綠界物流單時缺少核心提供的特店交易編號（merchant_trade_no），'
				. '無法保證與本地建單紀錄對得起來，已中止。'
			);
		}

		if ( strlen( $trade_no ) > 20 ) {
			throw new \RuntimeException(
				sprintf( '特店交易編號 %s 超過綠界上限 20 字元，已中止。', $trade_no )
			);
		}

		return $trade_no;
	}

	/**
	 * 取消物流訂單
	 *
	 * 🔴 本版**不實作**綠界的取消 API，因此明確回 `unsupported`——而不是 `false`。
	 *
	 * 差別在呼叫端：`false` 會被讀成「取消失敗」或「沒取消」，而重新取單／換門市
	 * 的流程在那之後照樣把本機標成已取消再建一張新的；綠界那邊的舊單還活著，
	 * 結果是同一張訂單出兩次貨。`unsupported` 讓核心把整條路擋掉。
	 *
	 * 綠界的取消是逐通路的獨立端點（C2C 7-ELEVEN 走 `/Express/CancelC2COrder`，
	 * B2C 走異動 API），且需要各自的參數與實測，不在本批範圍。
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function cancel_order( array $context = [] ): array {
		unset( $context );

		return [
			'success' => false,
			'outcome' => 'unsupported',
			'message' => '本版尚未實作綠界物流單取消，請於綠界後台操作後再回系統處理。',
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function query_status( string $tracking_number, array $context = [] ): array {
		unset( $tracking_number, $context );
		return [ 'success' => false, 'message' => 'ECPay logistics status query is not implemented.' ];
	}

	/**
	 * 產生託運單列印網址。
	 *
	 * 🔴 C2C 與 B2C／宅配打的是**不同的端點**，需要的欄位也不同（C2C 要寄貨編號，
	 * 7-ELEVEN 還要驗證碼），而且 C2C 一次只印一張。舊版一律打 B2C 那支，C2C 因此
	 * 印不出單。
	 *
	 * @param string|array<int,mixed>|array<int,array<string,string>> $provider_trade_no
	 * @param array<string,mixed>                                    $context
	 */
	public function get_print_url( $provider_trade_no, array $context = [] ): string {
		$spec = EcpayShippingCatalog::print_spec( $this->method->get_id() );
		if ( null === $spec || ! Settings::has_logistics_credentials() ) {
			return '';
		}

		// 核心會以 context 附帶完整 label 列（含寄貨編號／驗證碼）——C2C 只有
		// 這條路印得出來。沒有 context 時退回舊行為（只有物流編號）。
		$source = isset( $context['labels'] ) && is_array( $context['labels'] ) && [] !== $context['labels']
			? $context['labels']
			: $provider_trade_no;

		$rows = $this->normalize_print_rows( $source );
		if ( [] === $rows ) {
			return '';
		}

		$credentials = Settings::logistics_credentials();
		$fields      = [ 'MerchantID' => $credentials['merchant_id'] ];

		if ( $spec['batch'] ) {
			$ids = array_values( array_filter( array_map(
				static fn( array $row ): string => (string) ( $row['AllPayLogisticsID'] ?? '' ),
				$rows
			) ) );
			if ( [] === $ids ) {
				return '';
			}

			$fields['AllPayLogisticsID'] = implode( ',', $ids );
			$fields['PlatformID']        = '';
			$fields['PrintMode']         = '1';
		} else {
			// C2C：一次一張，且缺任何一個必要欄位就不要送——送出去只會拿到
			// 一頁綠界的錯誤畫面，賣家還以為是自己網路有問題。
			$row = $rows[0];
			foreach ( $spec['fields'] as $field ) {
				$value = trim( (string) ( $row[ $field ] ?? '' ) );
				if ( '' === $value ) {
					return '';
				}
				$fields[ $field ] = $value;
			}
		}

		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );

		$key = wp_generate_password( 24, false, false );
		set_transient( 'ys_ec_ecpay_print_' . $key, [
			'api_url'   => Settings::logistics_endpoint( $spec['path'] ),
			'fields'    => $fields,
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

	/**
	 * 把呼叫端傳來的各種形狀正規化成 [{AllPayLogisticsID, CVSPaymentNo, CVSValidationNo}, ...]。
	 *
	 * 相容三種輸入：單一字串、字串陣列（舊行為，只有物流編號），以及核心新契約
	 * 傳進來的 label 陣列（含寄件代碼）。
	 *
	 * @param string|array<int|string,mixed> $input
	 * @return array<int,array<string,string>>
	 */
	private function normalize_print_rows( $input ): array {
		$items = is_array( $input ) ? array_values( $input ) : [ $input ];
		$rows  = [];

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$row = [
					'AllPayLogisticsID' => sanitize_text_field( (string) ( $item['provider_trade_no'] ?? $item['AllPayLogisticsID'] ?? '' ) ),
					'CVSPaymentNo'      => sanitize_text_field( (string) ( $item['cvs_payment_no'] ?? $item['CVSPaymentNo'] ?? '' ) ),
					'CVSValidationNo'   => sanitize_text_field( (string) ( $item['cvs_validation_no'] ?? $item['CVSValidationNo'] ?? '' ) ),
				];
			} else {
				$row = [
					'AllPayLogisticsID' => sanitize_text_field( (string) $item ),
					'CVSPaymentNo'      => '',
					'CVSValidationNo'   => '',
				];
			}

			if ( '' !== $row['AllPayLogisticsID'] ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}
}
