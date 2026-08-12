<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Gateways\YSGatewayInterface;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\YSCartEcpay\Support\ScalarColumnWriter;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\Settings;

abstract class EcpayGatewayBase implements YSGatewayInterface {
	abstract protected function gateway_key(): string;
	abstract protected function choose_payment(): string;

	public function get_description(): string {
		return '使用綠界 ECPay AIO 金流付款。';
	}

	public function get_icon(): string {
		return 'dashicons-money-alt';
	}

	public function is_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' )
			&& ! \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( 'payment', $this->get_id(), Plugin::manifest() ) ) {
			return false;
		}

		return Settings::gateway_enabled( $this->gateway_key() ) && Settings::has_payment_credentials();
	}

	public function is_available( array $order_data ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$total = (float) ( $order_data['total'] ?? $order_data['order_total'] ?? 0 );
		return $total >= $this->get_min_amount()
			&& ( 0.0 === $this->get_max_amount() || $total <= $this->get_max_amount() );
	}

	public function get_min_amount(): float {
		return 1.0;
	}

	public function get_max_amount(): float {
		return 0.0;
	}

	public function process_payment( int $order_id ): array {
		$order = YSOrder::find( $order_id );
		if ( ! $order ) {
			return [ 'success' => false, 'message' => __( '找不到訂單。', 'ys-cart-ecpay' ) ];
		}

		if ( ! Settings::has_payment_credentials() ) {
			return [ 'success' => false, 'message' => __( '綠界金流設定尚未完成。', 'ys-cart-ecpay' ) ];
		}

		// 讀取失敗不得當成空陣列：續作時要靠它認出「這個 operation 已經有交易編號」。
		$payment_detail_before = OrderPaymentDetail::read( $order_id );
		if ( null === $payment_detail_before ) {
			return [
				'success' => false,
				'message' => __( '無法讀取訂單付款紀錄，已中止；請重新整理後再試一次。', 'ys-cart-ecpay' ),
			];
		}

		// 🔴 v0.3.0（#2G）：交易識別由核心的**穩定 operation key** 導出。
		//
		// 舊版是 `'YS' . $order_id . 'T' . time()`：同一次付款嘗試每呼叫一次就得到
		// 一個新的 MerchantTradeNo。只要 `process_payment()` 被重新進入——續作、
		// 接管、未來新增的任何路徑——綠界那邊就會多出一筆**新交易**，而顧客手上
		// 那張舊表單仍然付得成；我們的 `mer_trade_no` 已經指向另一筆，那筆錢因此
		// 無人認領。
		//
		// 現在它是 order_id／attempt 世代／attempt nonce 的函數：同一次嘗試無論被
		// 驅動幾次都導出同一個編號，「續作同一筆交易」因此是結構上的保證，而不是
		// 「只要沒有人再呼叫一次」的約定。
		$merchant_trade_no = $this->make_merchant_trade_no( $order_id, $payment_detail_before );
		if ( '' === $merchant_trade_no ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 無法導出穩定的交易識別，拒絕簽發付款表單', [
				'order_id' => $order_id,
			] );

			return [
				'success' => false,
				'message' => __( '付款流程未正確開始（缺少交易識別），請重新整理後再試一次。', 'ys-cart-ecpay' ),
			];
		}

		$method_id     = $this->get_id();
		$operation_key = class_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch' )
			? (string) \YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch::current_operation_key()
			: '';

		// v0.3.0：先算出實際要送出的金額（非 canonical TWD 正整數會直接拋例外），
		// 並在**送出付款表單之前**連同環境與商店身分一起持久化。
		try {
			$form_data = ( new EcpayPaymentClient() )->build_aio_form( $order, $merchant_trade_no, $this->choose_payment() );
		} catch ( \InvalidArgumentException $e ) {
			YSLogger::error( 'ecpay', '建單金額不合法，拒絕簽發付款表單', [
				'order_id' => $order_id,
				'message'  => $e->getMessage(),
			] );

			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}

		$charged_amount = (int) ( $form_data['charged_amount'] ?? 0 );
		$credentials    = Settings::payment_credentials();
		$environment    = ! empty( $credentials['test_mode'] ) ? 'stage' : 'live';
		$merchant_id    = (string) ( $credentials['merchant_id'] ?? '' );

		// payment_detail 走核心共用 CAS（v0.3.0：YSPaymentDetailStore），其餘為獨立
		// 純量欄位，不參與 JSON 整包覆蓋，維持一般 update。
		$persisted = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail ) use ( $merchant_trade_no, $method_id, $charged_amount, $environment, $merchant_id, $operation_key ): array {
				$detail['mer_trade_no']            = $merchant_trade_no;
				$detail['ecpay_merchant_trade_no'] = $merchant_trade_no;
				// 這個交易編號屬於哪一次 dispatch operation——續作時據此沿用同一個。
				$detail['ecpay_operation_key'] = $operation_key;
				$detail['payment_provider']        = 'ecpay';
				$detail['payment_method']          = $method_id;
				// 實際送出的金額——退款端據此判定全額／部分，不再回頭讀 $order->total。
				$detail['ecpay_charged_amount'] = $charged_amount;
				// 環境與商店身分：設定被切換（stage↔live、換商店代號）之後，若不綁定
				// 這兩個值，退款會拿著**另一個環境／另一家商店**的憑證去操作這筆交易。
				$detail['ecpay_environment'] = $environment;
				$detail['ecpay_merchant_id'] = $merchant_id;
				return $detail;
			}
		);

		// v0.3.0：寫入失敗**必須**中止建單。`mer_trade_no` 是這筆交易與綠界之間唯一
		// 的對應鍵——付款通知靠它找回訂單、退款靠它送 DoAction。舊版忽略回傳值仍然
		// 把付款表單交給使用者，於是消費者付了款，而我們沒有任何欄位可以認回這筆錢。
		if ( ! $persisted->is_persisted() ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 建單 payment_detail 寫入失敗，拒絕簽發付款表單', array_merge(
				[
					'order_id'          => $order_id,
					'merchant_trade_no' => $merchant_trade_no,
					'method'            => $method_id,
				],
				$persisted->to_log_context()
			) );

			return [
				'success' => false,
				'message' => __( '付款資料寫入失敗，請重新整理後再試一次。', 'ys-cart-ecpay' ),
			];
		}

		// v0.3.0：gateway identity 寫入失敗**不得**交付款表單。
		// 付款通知回來時，核心以 gateway_id 決定由哪個 provider 處理、退款以它判定
		// 歸屬；沒寫進去卻讓使用者付了款，這筆交易在系統裡不屬於任何 gateway——
		// 通知無人認領、退款也找不到執行者。
		$identity = ScalarColumnWriter::write( $order_id, [
			'gateway_id'     => $method_id,
			'payment_method' => $method_id,
		] );

		if ( ! ScalarColumnWriter::is_persisted( $identity ) ) {
			YSLogger::error( 'ecpay', 'CRITICAL: gateway identity 寫入失敗，拒絕簽發付款表單', [
				'order_id' => $order_id,
				'method'   => $method_id,
				'state'    => $identity['state'],
			] );

			return [
				'success' => false,
				'message' => __( '付款資料寫入失敗，請重新整理後再試一次。', 'ys-cart-ecpay' ),
			];
		}

		return [
			'success'      => true,
			'redirect_url' => $form_data['action_url'],
			'form_data'    => $form_data,
			'message'      => '',
		];
	}

	public function process_refund( int $order_id, float $amount, string $reason = '', array $context = [] ): array {
		unset( $order_id, $amount, $reason, $context );
		return [ 'success' => false, 'message' => __( '此版本尚未提供綠界退款功能。', 'ys-cart-ecpay' ) ];
	}

	public function supports_token(): bool {
		return false;
	}

	public function process_token_charge( int $subscription_id, float $override_amount = 0.0 ): array {
		unset( $subscription_id, $override_amount );
		return [ 'success' => false, 'message' => __( '綠界目前不支援訂閱扣款。', 'ys-cart-ecpay' ) ];
	}

	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * 由核心的穩定 operation key 導出 MerchantTradeNo（v0.3.0，#2G）
	 *
	 * 綠界的 MerchantTradeNo 上限 20 個英數字元，而且對同一個商店必須永久唯一。
	 * 兩個需求同時成立的方式是：**決定性地**從「這一次付款嘗試」導出。
	 *
	 * 唯一性來自 operation key（含 order_id、attempt 世代與 nonce）；`YS` 前綴
	 * 之後接它的雜湊，湊滿 20 字元。
	 *
	 * 🔴 沒有 dispatch context 時回**空字串**，呼叫端據此中止。舊版在這裡退回
	 * `time()`——那正是要移除的行為：時間會變，於是「同一次嘗試」每被驅動一次
	 * 就在綠界那邊多出一筆交易。核心自 2.57.0 起在建單與重付兩條路徑都提供
	 * context（版本 gate 保證核心不會更舊），因此空字串代表流程沒有正確開始，
	 * 不是可以拿預設值頂替的情況。
	 *
	 * @param array<string,mixed> $payment_detail 建單前讀到的 payment_detail
	 */
	protected function make_merchant_trade_no( int $order_id, array $payment_detail = [] ): string {
		$dispatch = '\YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch';

		if ( ! class_exists( $dispatch ) || ! method_exists( $dispatch, 'current_operation_key' ) ) {
			return '';
		}

		$key = (string) $dispatch::current_operation_key();
		if ( '' === $key ) {
			return '';
		}

		$expected = 'YS' . strtoupper( substr( hash( 'sha256', $key ), 0, 18 ) );

		// 續作：這個 operation 已經有交易編號就沿用它（即使導出邏輯日後改版，
		// 已經送到綠界的那一個才是事實）。
		$recorded_key = (string) ( $payment_detail['ecpay_operation_key'] ?? '' );
		$recorded_mtn = (string) ( $payment_detail['mer_trade_no'] ?? '' );
		if ( '' !== $recorded_key && '' !== $recorded_mtn && hash_equals( $key, $recorded_key ) ) {
			return $recorded_mtn;
		}

		return $expected;
	}
}
