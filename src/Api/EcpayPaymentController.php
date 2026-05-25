<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Services\Payment\YSPaymentLifecycleService;
use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayPaymentController {
	public static function register_routes(): void {
		$controller = new self();

		register_rest_route( 'ys-ecommerce/v1', '/ecpay/notify', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'notify' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'ys-ecommerce/v1', '/ecpay/payment-info', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'payment_info' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'ys-ecommerce/v1', '/ecpay/return', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $controller, 'return_page' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function notify( \WP_REST_Request $request ): void {
		$params = $this->params( $request );
		if ( ! $this->verify_payment_payload( $params ) ) {
			$this->respond_text( '0|Invalid CheckMacValue', 400 );
		}

		$order = $this->find_order_by_merchant_trade_no( (string) ( $params['MerchantTradeNo'] ?? '' ) );
		if ( ! $order ) {
			$this->respond_text( '0|Order Not Found', 404 );
		}

		$expected_amount = (int) round( (float) ( $order->total ?? 0 ) );
		$received_amount = (int) ( $params['TradeAmt'] ?? 0 );
		if ( $expected_amount > 0 && $expected_amount !== $received_amount ) {
			$this->respond_text( '0|Amount Mismatch', 400 );
		}

		$detail = $this->detail_from_payload( $params );
		if ( '1' === (string) ( $params['RtnCode'] ?? '' ) ) {
			YSOrder::update( (int) $order->id, [
				'gateway_trade_no' => sanitize_text_field( (string) ( $params['TradeNo'] ?? '' ) ),
			] );
			YSPaymentLifecycleService::mark_paid( (int) $order->id, $detail, 'webhook_ecpay_notify' );
		} else {
			YSPaymentLifecycleService::mark_failed( (int) $order->id, $detail, 'webhook_ecpay_notify' );
		}

		$this->respond_text( '1|OK' );
	}

	public function payment_info( \WP_REST_Request $request ): void {
		$params = $this->params( $request );
		if ( ! $this->verify_payment_payload( $params ) ) {
			$this->respond_text( '0|Invalid CheckMacValue', 400 );
		}

		$order = $this->find_order_by_merchant_trade_no( (string) ( $params['MerchantTradeNo'] ?? '' ) );
		if ( ! $order ) {
			$this->respond_text( '0|Order Not Found', 404 );
		}

		$rtn_code = (string) ( $params['RtnCode'] ?? '' );
		if ( in_array( $rtn_code, [ '2', '10100073' ], true ) ) {
			YSPaymentLifecycleService::mark_pending_offline(
				(int) $order->id,
				$this->detail_from_payload( $params ),
				'webhook_ecpay_payment_info'
			);
		}

		$this->respond_text( '1|OK' );
	}

	public function return_page( \WP_REST_Request $request ): void {
		$params = $this->params( $request );
		$order  = $this->verify_payment_payload( $params )
			? $this->find_order_by_merchant_trade_no( (string) ( $params['MerchantTradeNo'] ?? '' ) )
			: null;

		$url = $order
			? home_url( '/checkout/thankyou/' . rawurlencode( (string) ( $order->order_key ?? '' ) ) )
			: home_url( '/checkout/' );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @return array<string,string>
	 */
	private function params( \WP_REST_Request $request ): array {
		$params = [];
		foreach ( $request->get_params() as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$params[ (string) $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}
		return $params;
	}

	/**
	 * @param array<string,string> $params
	 */
	private function verify_payment_payload( array $params ): bool {
		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv']
			|| (string) ( $params['MerchantID'] ?? '' ) !== $credentials['merchant_id'] ) {
			return false;
		}

		return CheckMacValue::verify( $params, $credentials['hash_key'], $credentials['hash_iv'], 'sha256' );
	}

	/**
	 * @param array<string,string> $params
	 */
	private function detail_from_payload( array $params ): YSPaymentDetailDTO {
		$detail = [
			'payment_type'     => (string) ( $params['PaymentType'] ?? '' ),
			'trade_status'     => (string) ( $params['RtnCode'] ?? '' ),
			'trade_no'         => (string) ( $params['TradeNo'] ?? '' ),
			'gateway_trade_no' => (string) ( $params['TradeNo'] ?? '' ),
			'mer_trade_no'     => (string) ( $params['MerchantTradeNo'] ?? '' ),
			'response_code'    => (string) ( $params['RtnCode'] ?? '' ),
			'response_message' => (string) ( $params['RtnMsg'] ?? '' ),
			'pay_no'           => (string) ( $params['PaymentNo'] ?? $params['BankCode'] ?? $params['vAccount'] ?? '' ),
			'bank_type'        => (string) ( $params['BankCode'] ?? '' ),
			'expire_date'      => (string) ( $params['ExpireDate'] ?? '' ),
			'card_4no'         => (string) ( $params['card4no'] ?? $params['Card4No'] ?? '' ),
			'card_6no'         => (string) ( $params['card6no'] ?? $params['Card6No'] ?? '' ),
			'auth_code'        => (string) ( $params['auth_code'] ?? $params['AuthCode'] ?? '' ),
		];

		return YSPaymentDetailDTO::from_legacy_array( $detail, '' );
	}

	private function find_order_by_merchant_trade_no( string $merchant_trade_no ): ?object {
		if ( '' === $merchant_trade_no ) {
			return null;
		}

		if ( preg_match( '/^YS(\d+)T[A-Za-z0-9]+$/', $merchant_trade_no, $matches ) ) {
			$order = YSOrder::find( (int) $matches[1] );
			if ( $order && $this->order_has_merchant_trade_no( $order, $merchant_trade_no ) ) {
				return $order;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'orders';
		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE JSON_UNQUOTE(JSON_EXTRACT(payment_detail, '$.mer_trade_no')) = %s
				    OR JSON_UNQUOTE(JSON_EXTRACT(payment_detail, '$.ecpay_merchant_trade_no')) = %s
				 ORDER BY id DESC LIMIT 1",
				$merchant_trade_no,
				$merchant_trade_no
			)
		);

		return $order ?: null;
	}

	private function order_has_merchant_trade_no( object $order, string $merchant_trade_no ): bool {
		$detail = json_decode( (string) ( $order->payment_detail ?? '{}' ), true );
		if ( ! is_array( $detail ) ) {
			return false;
		}

		return hash_equals( (string) ( $detail['mer_trade_no'] ?? '' ), $merchant_trade_no )
			|| hash_equals( (string) ( $detail['ecpay_merchant_trade_no'] ?? '' ), $merchant_trade_no );
	}

	private function respond_text( string $body, int $status = 200 ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( $status );
		header_remove( 'Content-Type' );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
