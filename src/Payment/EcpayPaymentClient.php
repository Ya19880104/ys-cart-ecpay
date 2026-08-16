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
	 * 建立 AIO 付款表單。
	 *
	 * v0.3.0：金額改為 **canonical TWD 正整數**，非整數一律拒絕。
	 * 舊寫法 `max( 1, (int) round( $order->total ) )` 會把 1000.5 送成 1001——
	 * 消費者實際被扣的是 1001，我們的訂單卻記著 1000.5；退款端以 `total` 判定
	 * 「是否全額」與可退餘額，於是永遠無法精確退回那一筆。`max(1, …)` 更會把
	 * 0 元訂單悄悄變成 1 元交易。
	 *
	 * @param object $order
	 * @return array{action_url:string,fields:array<string,string>,charged_amount:int}
	 * @throws \InvalidArgumentException 金額非 canonical TWD 正整數
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

		$total = $order->total ?? null;
		if ( is_string( $total ) && '' !== $total && is_numeric( $total ) ) {
			$total = 0.0 + $total; // DB 取回可能是字串
		}
		if ( ! self::is_canonical_twd( $total ) ) {
			throw new \InvalidArgumentException(
				sprintf( '訂單金額必須為正整數新台幣（收到 %s），已拒絕建立付款表單。', var_export( $order->total ?? null, true ) )
			);
		}
		$amount    = (int) $total;
		$item_name = $this->clean_item_name( $order );

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
			// v0.3.0：要求綠界回傳額外付款資訊（含信用卡授權單號 gwsr）——
			// 信用卡退款的關帳狀態查詢（CreditDetail/QueryTrade）以 gwsr 為 key。
			'NeedExtraPaidInfo' => 'Y',
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
			'action_url'     => Settings::payment_endpoint(),
			'fields'         => $fields,
			// 呼叫端必須把這個值持久化：它是**實際送出的金額**，退款端據此判定
			// 全額／部分，不得再回頭讀 $order->total（可能已被其他流程改動）。
			'charged_amount' => $amount,
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
				'success'      => false,
				'data'         => null,
				'mac_verified' => false,
				'message'      => '綠界設定維護中（簽章憑證變更進行中或前次變更未完成），本次未送出查詢，請稍後再試。',
			];
		}

		$credentials       = Settings::payment_credentials();
		$merchant_trade_no = trim( $merchant_trade_no );
		if ( '' === $merchant_trade_no
			|| '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv'] ) {
			return [
				'success'      => false,
				'data'         => null,
				'mac_verified' => false,
				'message'      => 'ECPay payment settings are incomplete.',
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
				'success'      => false,
				'data'         => null,
				'mac_verified' => false,
				'message'      => '綠界設定維護窗與本次查詢重疊，本次未送出查詢，請稍後再試。',
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

		// 🔴 0.2.16（main 合流）：QueryTradeInfo/V5 官方回應**必帶** CheckMacValue——
		// 缺 MAC 或驗不過一律 fail-closed，不再「有帶就驗、沒帶就算了」。退款是
		// 不可逆的動作，它的依據不能是一份無法證明來源的回應；`mac_verified` 契約
		// 保留給呼叫端（成功路徑恆為 true）。
		if ( '' === (string) ( $data['CheckMacValue'] ?? '' )
			|| ! CheckMacValue::verify( $data, $credentials['hash_key'], $credentials['hash_iv'], 'sha256' ) ) {
			return [
				'success'      => false,
				'data'         => $data,
				'mac_verified' => false,
				'message'      => 'Invalid ECPay query CheckMacValue.',
			];
		}

		// 🔴 0.2.16（main 合流）：回應身分綁定——MerchantID／MerchantTradeNo 必須
		// 與送出的請求一致；已付款（TradeStatus=1）還必須帶完整 TradeNo／TradeAmt。
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
				'success'      => false,
				'data'         => $data,
				'mac_verified' => true,
				'message'      => 'ECPay query response identity is invalid.',
			];
		}

		if ( '1' === $trade_status
			&& ( '' === $provider_trade_no
				|| ! ctype_digit( $trade_amount )
				|| (int) $trade_amount <= 0 ) ) {
			return [
				'success'      => false,
				'data'         => $data,
				'mac_verified' => true,
				'message'      => 'ECPay paid query response is incomplete.',
			];
		}

		return [
			'success'      => true,
			'data'         => $data,
			'mac_verified' => true,
			'message'      => '',
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
		// 🔴 R14/0.2.16 reader lease：關帳查詢同樣以當下 payment 憑證簽 CMV，
		// 且其結果是退款 action 仲裁的依據——不得在設定 commit 窗口內出網。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => '綠界設定維護中（簽章憑證變更進行中或前次變更未完成），本次未送出查詢，請稍後再試。' ];
		}

		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id'] || '' === $credentials['hash_key'] || '' === $credentials['hash_iv'] ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => 'ECPay payment settings are incomplete.' ];
		}

		// CreditCheckCode 為官方必填（綠界後台「信用卡收單」查詢檢查碼）——未設定即無法查詢。
		$credit_check_code = (string) ( $credentials['credit_check_code'] ?? '' );
		if ( '' === $credit_check_code ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => '尚未設定「信用卡查詢檢查碼」（CreditCheckCode），請至綠界設定頁填入後再執行退款。' ];
		}
		if ( '' === $gwsr || $amount <= 0 ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => '缺少授權單號（gwsr）或金額，無法查詢關帳狀態。' ];
		}

		$fields = [
			'MerchantID'      => $credentials['merchant_id'],
			'CreditRefundId'  => $gwsr,
			'CreditAmount'    => (string) $amount,
			'CreditCheckCode' => $credit_check_code,
		];
		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'sha256' );

		// 🔴 R14 pre-send fence。
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return [ 'success' => false, 'state' => 'unknown', 'raw' => null, 'message' => '綠界設定維護窗與本次查詢重疊，本次未送出查詢，請稍後再試。' ];
		}

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

		// 官方判定規則：以 close_data 中「最後一筆**正金額**紀錄」的 status 為準
		// （「要關帳」等狀態位於 close_data；頂層 status 僅作 fallback）。
		$rtn_value  = is_array( $data['RtnValue'] ?? null ) ? $data['RtnValue'] : $data;
		$close_rows = is_array( $rtn_value['close_data'] ?? null ) ? $rtn_value['close_data'] : [];

		$status_text = '';
		foreach ( $close_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_amount = (float) ( $row['amount'] ?? 0 );
			$row_status = (string) ( $row['status'] ?? '' );
			if ( $row_amount > 0 && '' !== $row_status ) {
				$status_text = $row_status; // 迭代到最後一筆正金額紀錄
			}
		}
		if ( '' === $status_text ) {
			$status_text = (string) ( $rtn_value['status'] ?? '' );
		}

		$state_map = [
			'已授權'  => 'authorized',
			'要關帳'  => 'to_close',
			'已關帳'  => 'closed',
			'操作取消' => 'cancelled', // 前次操作已取消 → 可執行 N（放棄請款）
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
		// 🔴 R14/0.2.16 reader lease：退刷是**不可逆出網動作**，簽章憑證絕不可在
		// 設定 commit 窗口內讀取。lease 拿不到＝本次未送出（非 indeterminate）。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => null,
				'message' => '綠界設定維護中（簽章憑證變更進行中或前次變更未完成），本次未送出退刷，請稍後再試。',
			];
		}

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
		// v0.3.0：canonical TWD 整數契約。先前 `(int) round( $amount )` 會把 100.4 靜默
		// 變成 100、100.5 變成 101——送出去的金額與呼叫端要求的**不是同一個數字**，而
		// 這是一筆不可逆的金流動作。任何非整數一律在送出前拒絕，不四捨五入。
		if ( ! self::is_canonical_twd( $amount ) ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => null,
				'message' => sprintf( '退刷金額必須為正整數新台幣（收到 %s），已拒絕送出。', var_export( $amount, true ) ),
			];
		}
		$total_amount = (int) $amount;

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

		// 🔴 R14 pre-send fence：被 writer 收割的 request 不得把舊簽章的退刷送出網。
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return [
				'success' => false,
				'indeterminate' => false,
				'data'    => null,
				'message' => '綠界設定維護窗與本次退刷重疊，本次未送出，請稍後再試。',
			];
		}

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

	/**
	 * 金額是否為 canonical TWD 正整數（v0.3.0）
	 *
	 * 綠界信用卡的 TotalAmount 只接受整數新台幣。float 在此是「呼叫端表達整數的載體」，
	 * 不是可四捨五入的近似值——100.4 與 100.5 是呼叫端算錯了，不是我們該替它決定要送
	 * 100 還是 101。`0.1 + 0.2` 這類二進位浮點誤差同樣落在拒絕範圍內（1e-9 容差）。
	 *
	 * @param float|int $amount
	 */
	public static function is_canonical_twd( $amount ): bool {
		if ( is_int( $amount ) ) {
			return $amount > 0;
		}
		if ( ! is_float( $amount ) || ! is_finite( $amount ) ) {
			return false;
		}
		if ( $amount <= 0 ) {
			return false;
		}
		if ( $amount > (float) PHP_INT_MAX ) {
			return false;
		}

		return abs( $amount - round( $amount ) ) < 1e-9;
	}

	private function clean_item_name( object $order ): string {
		$base = 'YS CART Order ' . (string) ( $order->order_number ?? $order->id ?? '' );
		$base = wp_strip_all_tags( $base );
		$base = preg_replace( '/[\x00-\x1F\x7F]/u', '', $base ) ?: $base;
		$base = mb_substr( $base, 0, 190 );

		return '' !== trim( $base ) ? $base : 'YS CART Order';
	}
}
