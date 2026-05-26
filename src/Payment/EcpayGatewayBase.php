<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Gateways\YSGatewayInterface;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\YSCartEcpay\Plugin;
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
		$payment_detail   = json_decode( (string) ( $order->payment_detail ?? '{}' ), true );
		if ( ! is_array( $payment_detail ) ) {
			$payment_detail = [];
		}

		$payment_detail['mer_trade_no']            = $merchant_trade_no;
		$payment_detail['ecpay_merchant_trade_no'] = $merchant_trade_no;
		$payment_detail['payment_provider']        = 'ecpay';
		$payment_detail['payment_method']          = $this->get_id();

		YSOrder::update( $order_id, [
			'payment_detail' => wp_json_encode( $payment_detail ),
			'gateway_id'     => $this->get_id(),
			'payment_method' => $this->get_id(),
		] );

		$form_data = ( new EcpayPaymentClient() )->build_aio_form( $order, $merchant_trade_no, $this->choose_payment() );

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
