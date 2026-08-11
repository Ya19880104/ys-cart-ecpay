<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Security\YSInboundPermission;
use YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayLogisticsController {
	public static function register_routes(): void {
		$controller = new self();
		register_rest_route( 'ys-ecommerce/v1', '/ecpay/logistics-notify', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'notify' ],
			'permission_callback' => [ self::class, 'notify_permission' ],
		] );
	}

	public static function notify_permission( \WP_REST_Request $request ) {
		if ( ! class_exists( YSInboundPermission::class ) ) {
			return true;
		}

		$callback = YSInboundPermission::build( 'ecpay_logistics_notify', [
			'body_max_bytes' => 65536,
			'rate_limit'     => [ 300, 60 ],
			'allowed_types'  => [ 'application/x-www-form-urlencoded' ],
		] );
		return $callback( $request );
	}

	public function notify( \WP_REST_Request $request ): void {
		$params = $this->params( $request );
		if ( ! $this->verify( $params ) ) {
			$this->respond_text( '0|Invalid CheckMacValue', 400 );
		}

		$order = $this->find_order( $params );
		if ( $order && ! $this->update_order_shipping( $order, $params ) ) {
			// v0.3.0：物流狀態沒落盤就**不得** ACK。回 1|OK 會讓綠界停止重送，
			// 這筆狀態變更（出貨、到店、退貨）就永久遺失了。
			$this->respond_text( '0|Persist Failed', 500 );
			return;
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
	private function update_order_shipping( object $order, array $params ): bool {
		$tracking = (string) ( $params['CVSPaymentNo'] ?? $params['BookingNote'] ?? $params['AllPayLogisticsID'] ?? '' );
		$status   = (string) ( $params['LogisticsStatus'] ?? $params['RtnCode'] ?? '' );

		$shipping_patch = [
			'provider'             => 'ecpay',
			'allpay_logistics_id'  => (string) ( $params['AllPayLogisticsID'] ?? '' ),
			'logistics_status'     => $status,
			'logistics_status_msg' => (string) ( $params['LogisticsStatusName'] ?? $params['RtnMsg'] ?? '' ),
			'tracking_number'      => $tracking,
			'updated_at'           => current_time( 'mysql' ),
		];

		// v0.3.0：payment_detail 走核心共用 CAS。物流 callback 與付款通知、退款 ledger
		// 是同一個欄位的併發 writer——舊寫法在這裡整包覆蓋，會把剛落盤的退款憑據抹掉。
		$persisted = OrderPaymentDetail::mutate(
			(int) $order->id,
			static function ( array $detail ) use ( $shipping_patch ): array {
				$detail['shipping'] = array_merge(
					is_array( $detail['shipping'] ?? null ) ? $detail['shipping'] : [],
					$shipping_patch
				);
				return $detail;
			}
		);

		if ( ! $persisted->is_persisted() ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 物流 callback 的 payment_detail 寫入失敗', array_merge(
				[
					'order_id' => (int) $order->id,
					'status'   => $status,
				],
				$persisted->to_log_context()
			) );

			return false;
		}

		$order_update = [
			'tracking_number' => $tracking ?: (string) ( $order->tracking_number ?? '' ),
		];

		if ( ! class_exists( YSShippingPipelineService::class ) ) {
			$order_update['shipping_status'] = $this->map_status( $status );
		}

		// v0.3.0：純量欄位（tracking_number／shipping_status）同樣不得靜默失敗。
		// 消費者是靠 tracking_number 查件的；寫不進去卻回 1|OK，這次狀態變更就永久
		// 遺失，而訂單頁上什麼都不會顯示。
		if ( ! YSOrder::update( (int) $order->id, $order_update ) ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 物流 callback 的訂單欄位寫入失敗', [
				'order_id' => (int) $order->id,
				'status'   => $status,
				'tracking' => $tracking,
			] );

			return false;
		}

		if ( ! $this->sync_label( (int) $order->id, (string) ( $params['AllPayLogisticsID'] ?? '' ), $tracking, $status ) ) {
			return false;
		}

		if ( '' !== $status && class_exists( YSShippingPipelineService::class ) ) {
			// pipeline 推進失敗代表出貨狀態機沒有前進——後續的到貨、取件、退貨判定
			// 全都建立在它上面，這裡回 true 等於讓整條狀態機停在舊狀態且無人知情。
			$advanced = YSShippingPipelineService::advance_from_carrier_status(
				(int) $order->id,
				$status,
				'webhook_ecpay'
			);

			if ( false === $advanced ) {
				YSLogger::error( 'ecpay', 'CRITICAL: 物流 pipeline 推進失敗', [
					'order_id' => (int) $order->id,
					'status'   => $status,
				] );

				return false;
			}
		}

		return true;
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

	/**
	 * 同步 tracking_number 到 shipping_labels 表。
	 *
	 * v0.3.0：回傳成敗。`$wpdb->update()` 回 `false` 代表 SQL 失敗（回 `0` 只是
	 * 「值沒變」，不是錯誤）——把 SQL 失敗吞掉再 ACK，出貨單上的追蹤碼就與訂單
	 * 不一致，而且沒有任何機制會回來補。
	 */
	private function sync_label( int $order_id, string $provider_trade_no, string $tracking, string $status ): bool {
		if ( '' === $provider_trade_no ) {
			return true; // 沒有 provider 單號可對應，非錯誤
		}

		global $wpdb;
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';
		$updated = $wpdb->update(
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

		if ( false === $updated ) {
			YSLogger::error( 'ecpay', 'CRITICAL: shipping_labels 追蹤碼同步失敗', [
				'order_id'          => $order_id,
				'provider_trade_no' => $provider_trade_no,
			] );

			return false;
		}

		return true;
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
