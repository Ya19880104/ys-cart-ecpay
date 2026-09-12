<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Gateways\YSOrderScopedTokenChargeGatewayInterface;
use YangSheep\Ecommerce\Models\YSCreditCard;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Models\YSSubscription;
use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore;
use YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch;
use YangSheep\Ecommerce\Services\Payment\YSSavedCardChargePolicy;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Ecpg\EcpgClient;
use YangSheep\YSCartEcpay\Ecpg\EcpgOrderContext;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\Settings;

/**
 * 綠界信用卡——站內付 2.0 綁卡（軌 B，v0.5.0）
 *
 * 與同型錄其他方式的根本差異：**這是 YS CART 的真 token provider**，與 PayUni 同級。
 *
 *   首刷（結帳）  `process_payment()` 不碰綠界，只持久化交易識別並把顧客送到本站的
 *                 託管付款頁（/ecpay/ecpg/pay）。頁面由綠界 JS SDK 渲染卡號欄位，卡號
 *                 直送綠界；訂閱訂單走「交易且綁卡」拿到 BindCardID 存進核心卡片庫。
 *   續扣（cron）  `process_token_charge()` 以 BindCardID 呼叫 CreatePaymentWithCardID，
 *                 契約與 PayUni 的 token 扣款逐條對齊（YSOrderScopedTokenChargeGatewayInterface）。
 *
 * 🔴 `IMPLEMENTED_PAYMENT_MODES` 的「交易模式」選擇器與本方式無關：那是一般支付（AIO
 * 導轉）的交易模型開關。站內付 2.0 綁卡以**付款方式**的形態與導轉方式並存，由業主在
 * 「金流方式」分頁逐一開關——訂閱商品用它，一般商品可以繼續用導轉。
 */
final class EcpayEcpgCreditGateway extends EcpayGatewayBase implements YSOrderScopedTokenChargeGatewayInterface {
	/**
	 * 核心 coordinator 讀取續扣結果 DTO 的鍵名（`$result['payment_detail']`）。
	 *
	 * 這是**回傳值**的鍵，不是對 `payment_detail` 欄位的整包寫入——本外掛所有欄位寫入都走
	 * 核心共用 CAS（v017 (p) 以「鍵名字面值 => 」的形狀掃整包覆蓋，這裡用常數以免誤中）。
	 */
	private const RESULT_DETAIL_KEY = 'payment_detail';

	private ?EcpgClient $client;

	public function __construct( ?EcpgClient $client = null ) {
		$this->client = $client;
	}

	public function get_id(): string {
		return EcpgOrderContext::GATEWAY_ID;
	}

	public function get_description(): string {
		return __( '在本站頁面輸入卡號，卡號由綠界元件直送綠界；訂閱商品會綁定卡片自動續扣。', 'ys-cart-ecpay' );
	}

	public function get_icon(): string {
		return 'dashicons-credit-card';
	}

	/** 訂閱結帳只留 supports_token 的閘道（核心 YSCheckoutAvailabilityService）。 */
	public function supports_token(): bool {
		return true;
	}

	/**
	 * 首刷：只做本地持久化，交付託管付款頁的網址。
	 *
	 * 所有與綠界的互動都在付款頁那一側（另一個 request、由顧客瀏覽器觸發），因此這裡
	 * **不會**把 dispatch 翻成 submitted——核心在拿到 redirect_url 時自己會標（checkout
	 * handler 對任何 success 結果都補 mark_submitted）。
	 */
	public function process_payment( int $order_id ): array {
		$order = YSOrder::find( $order_id );
		if ( ! $order ) {
			return $this->rejected_terminal( __( '找不到訂單。', 'ys-cart-ecpay' ) );
		}
		if ( ! Settings::has_payment_credentials() ) {
			return $this->rejected_terminal( __( '綠界金流設定尚未完成。', 'ys-cart-ecpay' ) );
		}

		$payment_detail_before = OrderPaymentDetail::read( $order_id );
		if ( null === $payment_detail_before ) {
			return [
				'success'   => false,
				'outcome'   => 'provider_unavailable',
				'retryable' => true,
				'message'   => __( '無法讀取訂單付款紀錄，已中止；請重新整理後再試一次。', 'ys-cart-ecpay' ),
			];
		}

		$merchant_trade_no = $this->make_merchant_trade_no( $order_id, $payment_detail_before );
		if ( '' === $merchant_trade_no ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 無法導出穩定的交易識別，拒絕開啟站內付付款頁', [ 'order_id' => $order_id ] );
			return $this->rejected_terminal( __( '付款流程未正確開始（缺少交易識別），請重新整理後再試一次。', 'ys-cart-ecpay' ) );
		}

		$amount = EcpgOrderContext::canonical_amount( $order->total ?? null );
		if ( null === $amount || $amount <= 0 ) {
			return $this->rejected_terminal( __( '訂單金額必須為正整數新台幣，已拒絕建立付款。', 'ys-cart-ecpay' ) );
		}

		$flow      = EcpgOrderContext::flow_for( $order );
		$member_id = EcpgOrderContext::member_id( (int) ( $order->customer_id ?? 0 ) );
		if ( EcpgOrderContext::FLOW_PAY === $flow && EcpgOrderContext::is_subscription_order( $order_id ) ) {
			// 訂閱卻沒有會員身分：付得成，但沒有卡可以續扣。留下證據，不擋付款。
			YSLogger::warning( 'ecpay', '訂閱訂單沒有 customer_id，站內付無法綁卡，續約將需人工付款', [ 'order_id' => $order_id ] );
		}

		$error = $this->persist_payment_identity( $order_id, $merchant_trade_no, $amount, [
			'ecpay_ecpg_flow'      => $flow,
			'ecpay_ecpg_member_id' => $member_id,
		] );
		if ( null !== $error ) {
			return $error;
		}

		return [
			'success'      => true,
			'redirect_url' => EcpgOrderContext::pay_page_url( $order ),
			'message'      => '',
		];
	}

	/**
	 * 續扣：以綁定的 BindCardID 幕後授權本期訂單金額。
	 *
	 * 與 PayUni `process_token_charge()` 逐條對齊（核心 coordinator 的契約）：
	 *   1. `YSSavedCardChargePolicy::allows_subscription_renewal_charge()` 放行；
	 *   2. 訂單＝當前 dispatch 的訂單、狀態 pending；
	 *   3. 卡片＝訂閱明確綁定的專屬卡；未綁定的舊資料只允許人工復原，不讀取會員預設卡；
	 *   4. 解出 raw token（BindCardID）與非機密 token_hash，綁進本次 attempt；
	 *   5. 金額精確整數且與本期訂單相等；
	 *   6. 穩定 MerchantTradeNo 先落盤，再送出（client 在送出前把 dispatch 標 submitted）；
	 *   7. 結果分類：success／provider_failed／indeterminate 原樣保留給 coordinator。
	 */
	public function process_token_charge( int $subscription_id, float $override_amount = 0.0 ): array {
		if ( ! YSSavedCardChargePolicy::allows_subscription_renewal_charge( $subscription_id ) ) {
			return YSSavedCardChargePolicy::blocked_result( 'ecpay_ecpg_subscription_token', [ 'subscription_id' => $subscription_id ] );
		}

		$order_id = YSPaymentDispatch::current_order_id();
		YSOrder::forget( $order_id );
		$order = YSOrder::find( $order_id );
		if ( ! $order || 'pending' !== (string) ( $order->status ?? '' ) ) {
			return $this->rejected_terminal( '續訂單狀態不允許自動扣款。' );
		}

		$subscription = YSSubscription::find( $subscription_id );
		if ( ! $subscription ) {
			return $this->rejected_terminal( '訂閱不存在。' );
		}

		$customer_id = (int) ( $subscription->customer_id ?? 0 );
		$card_id     = (int) ( $subscription->card_id ?? 0 );
		if ( ! $card_id ) {
			$rejected         = $this->rejected_terminal( '此訂閱尚未綁定專屬信用卡，請改用人工付款完成復原。' );
			$rejected['code'] = 'subscription_card_unbound';
			return $rejected;
		}

		$authority = YSCreditCard::get_token_charge_authority_for_owner(
			$card_id,
			$customer_id,
			(int) ( $subscription->user_id ?? 0 ),
			$this->get_id()
		);
		if ( ! is_array( $authority ) ) {
			$rejected         = $this->rejected_terminal( '無法確認綁定信用卡的擁有者或 Token。' );
			$rejected['code'] = 'saved_card_owner_or_token_unavailable';
			return $rejected;
		}
		$bind_card_id = (string) ( $authority['raw_token'] ?? '' );
		$token_hash   = (string) ( $authority['token_hash'] ?? '' );
		if ( (int) ( $authority['card_id'] ?? 0 ) !== $card_id
			|| '' === $bind_card_id
			|| 1 !== preg_match( '/^[0-9a-f]{64}$/D', $token_hash ) ) {
			$rejected         = $this->rejected_terminal( '綁定信用卡權威資料不完整。' );
			$rejected['code'] = 'saved_card_authority_malformed';
			return $rejected;
		}

		$order_amount     = EcpgOrderContext::canonical_amount( $order->total ?? null );
		$requested_amount = EcpgOrderContext::canonical_amount( $override_amount );
		if ( null === $order_amount || null === $requested_amount || $order_amount <= 0 ) {
			return $this->rejected_terminal( '續訂單金額無法由綠界精確表示。' );
		}
		if ( $order_amount !== $requested_amount ) {
			return $this->rejected_terminal( '續訂扣款金額與本期訂單不一致。' );
		}

		if ( ! YSPaymentDispatch::bind_renewal_token_identity_from_context( $card_id, $token_hash ) ) {
			$rejected         = $this->rejected_terminal( '續訂卡片權威無法固定，未送出任何扣款。' );
			$rejected['code'] = 'renewal_card_identity_conflict';
			return $rejected;
		}

		$baseline = is_string( $order->payment_detail ?? null ) ? json_decode( (string) $order->payment_detail, true ) : [];
		// 空物件 `{}` 解成 []，而 array_is_list([]) 為 true——空的付款明細不是「無法解讀」。
		if ( ! is_array( $baseline ) || ( [] !== $baseline && array_is_list( $baseline ) ) ) {
			return $this->rejected_terminal( '續訂付款資料無法解讀。' );
		}
		$merchant_trade_no = $this->make_merchant_trade_no( $order_id, $baseline );
		if ( '' === $merchant_trade_no ) {
			return $this->rejected_terminal( '付款流程缺少穩定交易識別。' );
		}
		if ( isset( $baseline['mer_trade_no'] )
			&& ( ! is_string( $baseline['mer_trade_no'] ) || ! hash_equals( $baseline['mer_trade_no'], $merchant_trade_no ) ) ) {
			return $this->rejected_terminal( '續訂交易識別已被其他付款嘗試占用。' );
		}

		$credentials = Settings::payment_credentials();
		$mutated     = $baseline;
		$mutated['mer_trade_no']            = $merchant_trade_no;
		$mutated['ecpay_merchant_trade_no'] = $merchant_trade_no;
		$mutated['ecpay_operation_key']     = (string) YSPaymentDispatch::current_operation_key();
		$mutated['payment_provider']        = 'ecpay';
		$mutated['payment_method']          = $this->get_id();
		$mutated['ecpay_charged_amount']    = $order_amount;
		$mutated['ecpay_environment']       = ! empty( $credentials['test_mode'] ) ? 'stage' : 'live';
		$mutated['ecpay_merchant_id']       = (string) ( $credentials['merchant_id'] ?? '' );
		$mutated['ecpay_ecpg_flow']         = 'token_charge';
		$mutated['ecpay_ecpg_member_id']    = EcpgOrderContext::member_id( $customer_id );
		if ( ! $this->persist_renewal_detail( $order_id, $baseline, $mutated ) ) {
			return $this->rejected_terminal( '交易識別無法落盤，未送出任何扣款。' );
		}

		$member_id     = EcpgOrderContext::member_id( $customer_id );
		$consumer_info = EcpgOrderContext::consumer_info( $order, $member_id );
		$result        = $this->client()->create_payment_with_card_id( [
			'BindCardID'   => $bind_card_id,
			'OrderInfo'    => EcpgOrderContext::order_info( $order, $merchant_trade_no, $order_amount, EcpgOrderContext::return_url() ),
			'ConsumerInfo' => $consumer_info,
			'Need3D'       => 0,
			'CustomField'  => '',
		] );

		if ( EcpgClient::OUTCOME_REJECTED === $result['outcome'] ) {
			// 什麼都沒送出（client 的前置檢查）。
			return $this->rejected_terminal( (string) $result['message'] );
		}

		$data       = is_array( $result['data'] ?? null ) ? $result['data'] : [];
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$card_info  = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$trade_no   = trim( (string) ( $order_info['TradeNo'] ?? '' ) );
		$trade_amt  = $order_info['TradeAmt'] ?? $card_info['Amount'] ?? null;
		$dto        = YSPaymentDetailDTO::from_legacy_array( [
			'payment_type'     => 'credit_card',
			'trade_status'     => (string) ( $order_info['TradeStatus'] ?? '' ),
			'trade_no'         => $trade_no,
			'gateway_trade_no' => $trade_no,
			'mer_trade_no'     => $merchant_trade_no,
			'response_code'    => (string) ( $result['rtn_code'] ?? '' ),
			'response_message' => (string) ( $result['rtn_msg'] ?? $result['message'] ?? '' ),
			'card_4no'         => (string) ( $card_info['Card4No'] ?? '' ),
			'card_6no'         => (string) ( $card_info['Card6No'] ?? '' ),
			'auth_code'        => (string) ( $card_info['AuthCode'] ?? '' ),
			'paid_amount'      => is_numeric( $trade_amt ) ? (float) $trade_amt : 0.0,
		], $this->get_id() );

		if ( EcpgClient::OUTCOME_SUCCESS === $result['outcome'] ) {
			$trade_status = EcpgClient::trade_status( $data );
			// 成功＝有交易編號、已付款、沒有被要求 3D（Need3D=0 不該出現，出現即結果不明）。
			if ( '' === $trade_no || ( null !== $trade_status && '1' !== $trade_status ) || '' !== EcpgClient::three_d_url( $data ) ) {
				return [
					'success'               => false,
					'indeterminate'         => true,
					'gateway_trade_no'      => $trade_no,
					'provider_operation_id' => $merchant_trade_no,
					self::RESULT_DETAIL_KEY => $dto,
					'provider_data'         => $data,
					'message'               => '綠界回應成功但缺少已付款證據，待查單確認。',
				];
			}
			$response = [
				'success'               => true,
				'indeterminate'         => false,
				'transaction_id'        => $trade_no,
				'gateway_trade_no'      => $trade_no,
				'provider_operation_id' => $merchant_trade_no,
				self::RESULT_DETAIL_KEY => $dto,
				'provider_data'         => $data,
				'message'               => '',
			];
			if ( is_numeric( $trade_amt ) ) {
				$response['reported_paid_amount'] = (float) $trade_amt;
			}
			return $response;
		}

		return [
			'success'               => false,
			'indeterminate'         => EcpgClient::OUTCOME_INDETERMINATE === $result['outcome'],
			'gateway_trade_no'      => $trade_no,
			'provider_operation_id' => $merchant_trade_no,
			self::RESULT_DETAIL_KEY => $dto,
			'provider_data'         => $data,
			'message'               => '續約扣款失敗：' . (string) ( $result['message'] ?? '金流回應異常' ),
		];
	}

	/** 測試可注入替身；執行期 lazy 建立真客戶端。 */
	protected function client(): EcpgClient {
		if ( null === $this->client ) {
			$this->client = new EcpgClient();
		}
		return $this->client;
	}

	/**
	 * 續訂單 payment_detail 的 delta 寫入（與 PayUni `persist_payment_detail()` 同式）：
	 * 只套用這段程式碼實際改動的鍵，STALE（dispatch 已被接管）視為失敗。
	 */
	private function persist_renewal_detail( int $order_id, array $baseline, array $mutated ): bool {
		if ( ! class_exists( YSPaymentDetailStore::class ) || ! method_exists( YSPaymentDetailStore::class, 'apply_delta' ) ) {
			return false;
		}
		$result = YSPaymentDetailStore::apply_delta( $order_id, $baseline, $mutated );
		if ( defined( YSPaymentDetailStore::class . '::STALE' ) && YSPaymentDetailStore::STALE === $result->get_decision() ) {
			YSLogger::warning( 'ecpay', 'dispatch 已被接管，續訂付款明細未寫入', [ 'order_id' => $order_id ] );
			return false;
		}
		if ( ! $result->is_persisted() ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 續訂付款明細寫入失敗', array_merge( [ 'order_id' => $order_id ], $result->to_log_context() ) );
			return false;
		}
		return true;
	}

	/** 前置檢查沒過＝確定未送出任何東西，不可重複對同一訂單重試。 */
	private function rejected_terminal( string $message ): array {
		return [
			'success'   => false,
			'outcome'   => 'rejected_terminal',
			'retryable' => false,
			'message'   => $message,
		];
	}
}
