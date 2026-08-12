<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Security\YSInboundPermission;
use YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
use YangSheep\YSCartEcpay\Support\CheckMacValue;
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

		$label = $this->find_label( $params );
		if ( null === $label ) {
			// 找不到對應的物流單就不要動任何訂單。回 1|OK 是為了讓綠界停止重送
			// （這不是我們的單），但**什麼都不寫**。
			$this->respond_text( '1|OK' );
		}

		// 🔴 綁定必須逐項相符，缺欄位也拒絕。
		//
		// 舊版只用一個 AllPayLogisticsID 認人，而且 MerchantTradeNo／
		// LogisticsSubType 寫成「有傳才比」——不傳就自動通過。B2C 與 C2C 是兩份
		// 不同的合約、兩組不同的編號空間，這樣認人遲早會把狀態寫到別人的訂單上。
		if ( ! $this->binding_matches( $label, $params ) ) {
			$this->respond_text( '0|Logistics binding mismatch', 400 );
		}

		$order = $this->find_order_by_label( $label );
		if ( $order ) {
			$this->update_order_shipping( $order, $params, $label );
		}

		$this->respond_text( '1|OK' );
	}

	/**
	 * 依物流編號找出本站的物流單列。
	 *
	 * @param array<string,string> $params
	 */
	private function find_label( array $params ): ?object {
		$logistics_id = trim( (string) ( $params['AllPayLogisticsID'] ?? '' ) );
		if ( '' === $logistics_id ) {
			return null;
		}

		global $wpdb;
		$labels_table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';
		$label        = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$labels_table} WHERE provider = %s AND provider_trade_no = %s ORDER BY id DESC LIMIT 1",
			'ecpay',
			$logistics_id
		) );

		return $label ?: null;
	}

	/**
	 * 這則通知是不是真的屬於這張物流單。
	 *
	 * 三道都必須通過：
	 *   1. `MerchantTradeNo` 必須帶，且（已落盤時）與建單當初的完全相同。
	 *   2. `LogisticsSubType` 必須帶，且與這個物流方式在型錄中的 subtype 相同。
	 *   3. 這張 label 的 shipping_method 必須是本外掛型錄裡的方式。
	 *
	 * 第 2 道刻意以**型錄**為準而不是只看 label 落盤值：升級前建立的舊單沒有
	 * 落盤 subtype，但它的 shipping_method 一定在型錄裡，因此仍然驗得出來。
	 *
	 * @param array<string,string> $params
	 */
	private function binding_matches( object $label, array $params ): bool {
		$descriptor = EcpayShippingCatalog::get( (string) ( $label->shipping_method ?? '' ) );
		if ( null === $descriptor ) {
			return false;
		}

		foreach ( [ 'MerchantTradeNo', 'LogisticsSubType' ] as $field ) {
			if ( ! array_key_exists( $field, $params ) || '' === trim( (string) $params[ $field ] ) ) {
				return false;
			}
		}

		$stored_trade_no = trim( (string) ( $label->merchant_trade_no ?? '' ) );
		if ( '' !== $stored_trade_no && trim( (string) $params['MerchantTradeNo'] ) !== $stored_trade_no ) {
			return false;
		}

		$stored_subtype   = trim( (string) ( $label->logistics_subtype ?? '' ) );
		$expected_subtype = '' !== $stored_subtype ? $stored_subtype : (string) $descriptor['logistics_subtype'];

		return trim( (string) $params['LogisticsSubType'] ) === $expected_subtype;
	}

	private function find_order_by_label( object $label ): ?object {
		global $wpdb;
		$orders_table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'orders';
		$order        = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$orders_table} WHERE id = %d",
			(int) $label->order_id
		) );

		return $order ?: null;
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
	private function update_order_shipping( object $order, array $params, object $label ): void {
		// 追蹤碼優先取託運單號；沒有時才退回物流編號。
		// 🔴 寄貨編號（CVSPaymentNo）**不是**追蹤碼，不要混進來——它是賣家交貨用的
		// 憑據，另外落盤。
		$tracking = trim( (string) ( $params['BookingNote'] ?? '' ) );
		if ( '' === $tracking ) {
			$tracking = trim( (string) ( $params['AllPayLogisticsID'] ?? '' ) );
		}
		$status = (string) ( $params['LogisticsStatus'] ?? $params['RtnCode'] ?? '' );

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

		$order_update = [
			'payment_detail'  => wp_json_encode( $payment_detail ),
			'tracking_number' => $tracking ?: (string) ( $order->tracking_number ?? '' ),
		];

		if ( ! class_exists( YSShippingPipelineService::class ) ) {
			$order_update['shipping_status'] = $this->map_status( $status );
		}

		YSOrder::update( (int) $order->id, $order_update );

		$this->sync_label( $label, $params, $tracking, $status );

		if ( '' !== $status && class_exists( YSShippingPipelineService::class ) ) {
			YSShippingPipelineService::advance_from_carrier_status(
				(int) $order->id,
				$status,
				'webhook_ecpay'
			);
		}
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
	 * 更新這**一張**物流單。
	 *
	 * 🔴 舊版以 (order_id, provider_trade_no) 當條件更新，同一張訂單有多張物流單
	 * 時可能一次改到不只一列。綁定既然已經驗到具體那一列，就直接以主鍵更新。
	 *
	 * @param array<string,string> $params
	 */
	private function sync_label( object $label, array $params, string $tracking, string $status ): void {
		global $wpdb;
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';

		$update = [
			'status'            => $this->map_status( $status ),
			'status_code'       => $status,
			'status_updated_at' => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		];

		if ( '' !== $tracking ) {
			$update['tracking_number'] = $tracking;
		}

		// C2C 的寄件憑據有可能在通知裡才補齊；有帶就補上，但**不覆蓋**既有值——
		// 建單當下拿到的那一份才是權威。
		$carried = [
			'cvs_payment_no' => trim( (string) ( $params['CVSPaymentNo'] ?? '' ) ),
			'validation_no'  => trim( (string) ( $params['CVSValidationNo'] ?? '' ) ),
		];
		foreach ( $carried as $column => $value ) {
			if ( '' !== $value && '' === trim( (string) ( $label->{$column} ?? '' ) ) ) {
				$update[ $column ] = $value;
			}
		}

		$wpdb->update( $table, $update, [ 'id' => (int) $label->id ] );
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
