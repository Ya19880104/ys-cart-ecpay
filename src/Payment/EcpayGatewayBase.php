<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Gateways\YSGatewayInterface;
use YangSheep\Ecommerce\Models\YSOrder;
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

		$merchant_trade_no = $this->make_merchant_trade_no( $order_id );
		$method_id         = $this->get_id();

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
			static function ( array $detail ) use ( $merchant_trade_no, $method_id, $charged_amount, $environment, $merchant_id ): array {
				$detail['mer_trade_no']            = $merchant_trade_no;
				$detail['ecpay_merchant_trade_no'] = $merchant_trade_no;
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

		YSOrder::update( $order_id, [
			'gateway_id'     => $method_id,
			'payment_method' => $method_id,
		] );

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

	protected function make_merchant_trade_no( int $order_id ): string {
		$raw = 'YS' . $order_id . 'T' . time();
		return substr( preg_replace( '/[^A-Za-z0-9]/', '', $raw ) ?: $raw, 0, 20 );
	}
}
