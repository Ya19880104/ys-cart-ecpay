<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Ecpg;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;

/**
 * 綠界站內付 2.0（ECPG）AES-JSON 客戶端（v0.5.0，軌 B 綁卡）
 *
 * 所有站內付 2.0 端點共用同一個外層信封：
 *
 *     { "MerchantID": "...", "RqHeader": { "Timestamp": <Unix 秒，整數> }, "Data": "<AES Base64>" }
 *
 * 回應同樣三層：外層 `TransCode`（1＝傳輸資料被接受）→ 解密 `Data` → 內層 `RtnCode`
 * （1＝API 執行成功）。兩層**都要看**；只看其中一層是官方 skill 列為最常見的錯誤。
 *
 * 🔴 兩個 domain 不可混用（混用會 404）：
 *   - Token／建立交易／綁卡：`ecpg(-stage).ecpay.com.tw`
 *   - 查詢／請退款：`ecpayment(-stage).ecpay.com.tw`
 *
 * 🔴 結果分類是這個類別存在的理由。對一筆**扣款**而言：
 *   - `success`          TransCode 1 且 RtnCode 1——錢收到了。
 *   - `provider_failed`  綠界明確拒絕（TransCode≠1，或 RtnCode 是 10300066 以外的失敗碼）。
 *                        TransCode≠1 代表傳輸資料根本沒被接受，不會有任何授權發生。
 *   - `indeterminate`    我們**不知道**：逾時／連線中斷、HTTP 非 200、回應不是 JSON、
 *                        Data 解不開，或 RtnCode 10300066「交易付款結果待確認中」。
 *                        這一類絕不能當成失敗放行下一次扣款——可能已經收到錢了。
 *   - `rejected`         本地前置檢查沒過，**什麼都沒送出**（憑證不完整、維護鎖、送出意圖
 *                        落不了盤）。呼叫端據此安全地讓顧客重試。
 *
 * 純函式（信封、分類、回呼解碼）是 static，測試直接對拍；網路層走 `wp_remote_post()`。
 */
class EcpgClient {
	public const HOST_TOKEN_STAGE = 'https://ecpg-stage.ecpay.com.tw';
	public const HOST_TOKEN_LIVE  = 'https://ecpg.ecpay.com.tw';
	public const HOST_TRADE_STAGE = 'https://ecpayment-stage.ecpay.com.tw';
	public const HOST_TRADE_LIVE  = 'https://ecpayment.ecpay.com.tw';

	/** 官方 JS SDK（8989.md）：測試／正式各一個 host，須與 `ECPay.initialize('Stage'|'Prod')` 一致。 */
	public const SDK_PATH = '/Scripts/sdk-1.0.0.js?t=20210121100116';

	public const OUTCOME_SUCCESS         = 'success';
	public const OUTCOME_PROVIDER_FAILED = 'provider_failed';
	public const OUTCOME_INDETERMINATE   = 'indeterminate';
	public const OUTCOME_REJECTED        = 'rejected';

	/** 綠界：「交易付款結果待確認中，請勿出貨」——結果不明，要查單。 */
	public const RTN_PENDING_CONFIRMATION = 10300066;

	/** 官方建議：需與銀行連線的 API 逾時至少 30 秒（9053／35596／35630）。 */
	private const TIMEOUT_AUTHORIZE = 35;
	private const TIMEOUT_OTHER     = 20;

	// ── 純函式 ───────────────────────────────────────────────────────────────

	/**
	 * 組出送往綠界的外層信封（Data 已加密）。
	 *
	 * @param array<string,mixed> $data 內層明文
	 * @return array{MerchantID:string,RqHeader:array{Timestamp:int},Data:string}
	 */
	public static function build_envelope( string $merchant_id, array $data, string $hash_key, string $hash_iv, ?int $timestamp = null ): array {
		return [
			'MerchantID' => $merchant_id,
			// 🔴 只有 Timestamp，沒有 Revision——那是電子發票／物流 API 的欄位，帶了綠界解析失敗。
			'RqHeader'   => [ 'Timestamp' => $timestamp ?? time() ],
			'Data'       => EcpgAesCodec::encrypt( $data, $hash_key, $hash_iv ),
		];
	}

	/**
	 * 把「已經拿到的綠界回應 JSON」解碼並分類。不碰網路。
	 *
	 * @param mixed $outer json_decode 後的外層（期望是陣列）
	 * @return array{outcome:string,trans_code:?int,trans_msg:string,rtn_code:?int,rtn_msg:string,data:?array,message:string}
	 */
	public static function classify_response( mixed $outer, string $hash_key, string $hash_iv ): array {
		if ( ! is_array( $outer ) || ! array_key_exists( 'TransCode', $outer ) ) {
			return self::result( self::OUTCOME_INDETERMINATE, null, '', null, '', null, '綠界回應不是可解讀的站內付 2.0 信封。' );
		}
		$trans_code = is_numeric( $outer['TransCode'] ) ? (int) $outer['TransCode'] : null;
		$trans_msg  = (string) ( $outer['TransMsg'] ?? '' );
		if ( 1 !== $trans_code ) {
			// 傳輸資料未被接受（MerchantID／Timestamp／解密失敗）：綠界沒有處理任何交易。
			return self::result( self::OUTCOME_PROVIDER_FAILED, $trans_code, $trans_msg, null, '', null, '綠界拒絕請求（TransCode ' . ( $trans_code ?? '?' ) . '）：' . $trans_msg );
		}
		$cipher = $outer['Data'] ?? null;
		$data   = is_string( $cipher ) && '' !== $cipher ? EcpgAesCodec::decrypt( $cipher, $hash_key, $hash_iv ) : null;
		if ( null === $data ) {
			return self::result( self::OUTCOME_INDETERMINATE, $trans_code, $trans_msg, null, '', null, '綠界回應的 Data 無法以目前金鑰解密。' );
		}
		$rtn_code = isset( $data['RtnCode'] ) && is_numeric( $data['RtnCode'] ) ? (int) $data['RtnCode'] : null;
		$rtn_msg  = (string) ( $data['RtnMsg'] ?? '' );
		if ( 1 === $rtn_code ) {
			return self::result( self::OUTCOME_SUCCESS, $trans_code, $trans_msg, $rtn_code, $rtn_msg, $data, '' );
		}
		if ( self::RTN_PENDING_CONFIRMATION === $rtn_code ) {
			return self::result( self::OUTCOME_INDETERMINATE, $trans_code, $trans_msg, $rtn_code, $rtn_msg, $data, '綠界回報交易付款結果待確認中（10300066）。' );
		}
		return self::result( self::OUTCOME_PROVIDER_FAILED, $trans_code, $trans_msg, $rtn_code, $rtn_msg, $data, '綠界回報失敗（RtnCode ' . ( $rtn_code ?? '?' ) . '）：' . $rtn_msg );
	}

	/**
	 * 解讀綠界回呼（ReturnURL 的 JSON body，或 OrderResultURL 的 `ResultData` JSON 字串）。
	 *
	 * 回呼沒有「失敗」這個分支：TransCode≠1 或解不開＝這不是給我們（或不是這把金鑰）的通知。
	 *
	 * @return array<string,mixed>|null 解密後的 Data；null＝不可解讀，呼叫端不得據此改任何狀態。
	 */
	public static function decode_callback( mixed $outer, string $hash_key, string $hash_iv ): ?array {
		if ( is_string( $outer ) ) {
			$outer = json_decode( $outer, true );
		}
		$classified = self::classify_response( $outer, $hash_key, $hash_iv );
		return $classified['data'];
	}

	/** `OrderInfo.TradeStatus`：'1' 已付款、'0' 成立未付款；缺欄位回 null。 */
	public static function trade_status( array $data ): ?string {
		$order_info = $data['OrderInfo'] ?? null;
		if ( ! is_array( $order_info ) || ! array_key_exists( 'TradeStatus', $order_info ) ) {
			return null;
		}
		return (string) $order_info['TradeStatus'];
	}

	/** 3D 驗證網址；沒有或為空字串回 ''。 */
	public static function three_d_url( array $data ): string {
		$info = $data['ThreeDInfo'] ?? null;
		return is_array( $info ) ? trim( (string) ( $info['ThreeDURL'] ?? '' ) ) : '';
	}

	// ── 端點 ───────────────────────────────────────────────────────────────────

	public static function token_host( bool $test_mode ): string {
		return $test_mode ? self::HOST_TOKEN_STAGE : self::HOST_TOKEN_LIVE;
	}

	public static function trade_host( bool $test_mode ): string {
		return $test_mode ? self::HOST_TRADE_STAGE : self::HOST_TRADE_LIVE;
	}

	public static function sdk_url( bool $test_mode ): string {
		return self::token_host( $test_mode ) . self::SDK_PATH;
	}

	/** 綁定信用卡／取得廠商驗證碰（35591）——交易且綁卡。 */
	public function get_token_by_binding_card( array $data ): array {
		return $this->post( '/Merchant/GetTokenbyBindingCard', $data, false );
	}

	/** 綁定信用卡／建立綁定信用卡交易（35596）——會實際授權 TotalAmount 並回 BindCardID。 */
	public function create_bind_card( string $bind_card_pay_token, string $merchant_member_id ): array {
		return $this->post( '/Merchant/CreateBindCard', [
			'BindCardPayToken' => $bind_card_pay_token,
			'MerchantMemberID' => $merchant_member_id,
		], true );
	}

	/** 站內付 2.0／取得廠商驗證碼（9040）——純交易。 */
	public function get_token_by_trade( array $data ): array {
		return $this->post( '/Merchant/GetTokenbyTrade', $data, false );
	}

	/** 站內付 2.0／建立交易（9053）。 */
	public function create_payment( string $pay_token, string $merchant_trade_no ): array {
		return $this->post( '/Merchant/CreatePayment', [
			'PayToken'        => $pay_token,
			'MerchantTradeNo' => $merchant_trade_no,
		], true );
	}

	/** 綁定信用卡後交易／幕後交易授權（35630）——續扣用。 */
	public function create_payment_with_card_id( array $data ): array {
		return $this->post( '/Merchant/CreatePaymentWithCardID', $data, true );
	}

	/** 綁定信用卡／查詢綁定信用卡（35613）。 */
	public function get_member_bind_card( string $merchant_member_id, string $merchant_trade_no = '' ): array {
		$data = [ 'MerchantMemberID' => $merchant_member_id ];
		if ( '' !== $merchant_trade_no ) {
			$data['MerchantTradeNo'] = $merchant_trade_no;
		}
		return $this->post( '/Merchant/GetMemberBindCard', $data, false );
	}

	/** 綁定信用卡／刪除綁定信用卡（35608）。 */
	public function delete_member_bind_card( string $bind_card_id ): array {
		return $this->post( '/Merchant/DeleteMemberBindCard', [ 'BindCardID' => $bind_card_id ], false );
	}

	/** 查詢訂單（9083／35654）——走 ecpayment domain。 */
	public function query_trade( string $merchant_trade_no ): array {
		return $this->post( '/1.0.0/Cashier/QueryTrade', [ 'MerchantTradeNo' => $merchant_trade_no ], false, true );
	}

	// ── 傳輸 ───────────────────────────────────────────────────────────────────

	/**
	 * 送出一個站內付 2.0 請求。
	 *
	 * `PlatformID`（空字串＝一般特店）與 `MerchantID` 由這裡統一補進 Data，呼叫端只管業務欄位。
	 *
	 * @param bool $authorizes 這個端點會不會造成授權／扣款。會的話：逾時較長，且**送出前**
	 *                         必須把 dispatch 標成 submitted（落不了盤就不送）。
	 * @return array{outcome:string,sent:bool,trans_code:?int,trans_msg:string,rtn_code:?int,rtn_msg:string,data:?array,message:string,http_status:int}
	 */
	public function post( string $path, array $data, bool $authorizes, bool $trade_domain = false ): array {
		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id'] || '' === $credentials['hash_key'] || '' === $credentials['hash_iv'] ) {
			return self::not_sent( '綠界金流設定不完整，未送出任何請求。' );
		}

		// 🔴 R14 reader lease：與 AIO 客戶端同一條規則——設定 commit 期間不得用可能被
		// 回滾的金鑰簽任何請求。拿不到 lease＝未送出。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return self::not_sent( '綠界設定維護中（簽章憑證變更進行中或前次變更未完成），未送出任何請求。' );
		}

		$payload = [ 'PlatformID' => '', 'MerchantID' => $credentials['merchant_id'] ] + $data;
		try {
			$envelope = self::build_envelope( $credentials['merchant_id'], $payload, $credentials['hash_key'], $credentials['hash_iv'] );
		} catch ( \Throwable $e ) {
			return self::not_sent( '綠界請求無法加密：' . $e->getMessage() );
		}

		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return self::not_sent( '綠界設定維護窗與本次請求重疊，未送出任何請求。' );
		}

		// 🔴 會授權的端點：送出意圖必須在網路呼叫之前落盤（v2.57.0 #2H，與 PayUni requester 同式）。
		// dispatch 停在 reserved 是「確定什麼都沒送出」的唯一證據；這裡翻成 submitted 之後，
		// 連線中斷／逾時才會被正確判為結果不明而不是可重試的失敗。
		if ( $authorizes
			&& class_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch' )
			&& method_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch', 'mark_submitted_from_context' )
			&& ! \YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch::mark_submitted_from_context() ) {
			return self::not_sent( '付款資料寫入失敗，未送出任何請求。' );
		}

		$host = $trade_domain ? self::trade_host( $credentials['test_mode'] ) : self::token_host( $credentials['test_mode'] );
		$body = json_encode( $envelope );
		if ( false === $body ) {
			return self::not_sent( '綠界請求信封無法序列化。' );
		}

		$response = wp_remote_post( $host . $path, [
			'timeout'     => $authorizes ? self::TIMEOUT_AUTHORIZE : self::TIMEOUT_OTHER,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => [ 'Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json' ],
			'body'        => $body,
		] );

		// 🔴 傳輸層失敗永遠是 indeterminate：只證明我們沒收到回應，不證明綠界沒收到請求。
		if ( is_wp_error( $response ) ) {
			return self::result( self::OUTCOME_INDETERMINATE, null, '', null, '', null, '無法連線綠界：' . $response->get_error_message() ) + [ 'sent' => true, 'http_status' => 0 ];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			return self::result( self::OUTCOME_INDETERMINATE, null, '', null, '', null, '綠界回應 HTTP ' . $status . '。' ) + [ 'sent' => true, 'http_status' => $status ];
		}

		$classified = self::classify_response( json_decode( $raw, true ), $credentials['hash_key'], $credentials['hash_iv'] );
		return $classified + [ 'sent' => true, 'http_status' => $status ];
	}

	/** @return array{outcome:string,sent:bool,trans_code:?int,trans_msg:string,rtn_code:?int,rtn_msg:string,data:?array,message:string,http_status:int} */
	private static function not_sent( string $message ): array {
		return self::result( self::OUTCOME_REJECTED, null, '', null, '', null, $message ) + [ 'sent' => false, 'http_status' => 0 ];
	}

	/** @return array{outcome:string,trans_code:?int,trans_msg:string,rtn_code:?int,rtn_msg:string,data:?array,message:string} */
	private static function result( string $outcome, ?int $trans_code, string $trans_msg, ?int $rtn_code, string $rtn_msg, ?array $data, string $message ): array {
		return [
			'outcome'    => $outcome,
			'trans_code' => $trans_code,
			'trans_msg'  => $trans_msg,
			'rtn_code'   => $rtn_code,
			'rtn_msg'    => $rtn_msg,
			'data'       => $data,
			'message'    => $message,
		];
	}
}
