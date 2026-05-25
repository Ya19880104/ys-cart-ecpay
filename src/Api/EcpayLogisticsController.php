<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayLogisticsController {
	public static function register_routes(): void {
		$controller = new self();
		register_rest_route( 'ys-ecommerce/v1', '/ecpay/logistics-notify', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'notify' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function notify( \WP_REST_Request $request ): void {
		$params = $this->params( $request );
		if ( ! $this->verify( $params ) ) {
			$this->respond_text( '0|Invalid CheckMacValue', 400 );
		}

		$order = $this->find_order( $params );
		if ( $order ) {
			$this->update_order_shipping( $order, $params );
		}

		$this->respond_text( '1|OK' );
	}

	/**
	 * @return array<string,string>
	 */
	private function params( \WP_REST_Request $request ): array {
		$out = [];
		foreach ( $request->get_params() as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$out[ (string) $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}
		return $out;
	}

	/**
	 * @param array<string,string> $params
	 */
	private function verify( array $params ): bool {
		$credentials = Settings::logistics_credentials();
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
	 * @param array<string,string> $params
	 */
	private function find_order( array $params ): ?object {
		$logistics_id = (string) ( $params['AllPayLogisticsID'] ?? '' );
		if ( '' === $logistics_id ) {
			return null;
		}

		global $wpdb;
		$orders_table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'orders';
		$labels_table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';
		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.* FROM {$orders_table} o
				 INNER JOIN {$labels_table} l ON l.order_id = o.id
				 WHERE l.provider = %s AND l.provider_trade_no = %s
				 ORDER BY l.id DESC LIMIT 1",
				'ecpay',
				$logistics_id
			)
		);

		return $order ?: null;
	}

	/**
	 * @param array<string,string> $params
	 */
	private function update_order_shipping( object $order, array $params ): void {
		$tracking = (string) ( $params['CVSPaymentNo'] ?? $params['BookingNote'] ?? $params['AllPayLogisticsID'] ?? '' );
		$status   = (string) ( $params['LogisticsStatus'] ?? $params['RtnCode'] ?? '' );

		$payment_detail = json_decode( (string) ( $order->payment_detail ?? '{}' ), true );
		if ( ! is_array( $payment_detail ) ) {
			$payment_detail = [];
		}
		$payment_detail['shipping'] = array_merge( (array) ( $payment_detail['shipping'] ?? [] ), [
			'provider'             => 'ecpay',
			'allpay_logistics_id'  => (string) ( $params['AllPayLogisticsID'] ?? '' ),
			'logistics_status'     => $status,
			'logistics_status_msg' => (string) ( $params['LogisticsStatusName'] ?? $params['RtnMsg'] ?? '' ),
			'tracking_number'      => $tracking,
			'updated_at'           => current_time( 'mysql' ),
		] );

		YSOrder::update( (int) $order->id, [
			'payment_detail'  => wp_json_encode( $payment_detail ),
			'tracking_number' => $tracking ?: (string) ( $order->tracking_number ?? '' ),
			'shipping_status' => $this->map_status( $status ),
		] );

		$this->sync_label( (int) $order->id, (string) ( $params['AllPayLogisticsID'] ?? '' ), $tracking, $status );
	}

	private function map_status( string $status ): string {
		if ( in_array( $status, [ '300', '2063', '2067' ], true ) ) {
			return 'delivered';
		}
		if ( in_array( $status, [ '2073', '2074', '2077' ], true ) ) {
			return 'returned';
		}
		return 'in_transit';
	}

	private function sync_label( int $order_id, string $provider_trade_no, string $tracking, string $status ): void {
		if ( '' === $provider_trade_no ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';
		$wpdb->update(
			$table,
			[
				'tracking_number' => $tracking,
				'status'          => $this->map_status( $status ),
				'updated_at'      => current_time( 'mysql' ),
			],
			[
				'order_id'          => $order_id,
				'provider_trade_no' => $provider_trade_no,
			]
		);
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
