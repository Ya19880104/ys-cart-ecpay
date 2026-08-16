<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Security\YSInboundPermission;
use YangSheep\Ecommerce\Services\Shipping\YSShippingDispatchAuthority;
use YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
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
		// 🔴 R14 reader lease：驗章與後續處理讀當下憑證——設定 commit 期間
		// 回非 2xx（綠界會重送物流回呼），不誤拒也不遺失。lease 由 request
		// 結束時自動釋放。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			$this->respond_text( '0|Provider settings maintenance', 503 );
		}

		$params = $this->params( $request );
		$lookup = $this->find_label( $params );
		$label_method = 'found' === (string) ( $lookup['status'] ?? '' ) && is_object( $lookup['label'] ?? null )
			? (string) ( $lookup['label']->shipping_method ?? '' )
			: '';
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			$this->respond_text( '0|Provider settings maintenance', 503 );
		}
		if ( ! $this->verify( $params, $label_method ) ) {
			$this->respond_text( '0|Invalid CheckMacValue', 400 );
		}

		// 🔴 「查不到」與「查不動」是兩件事。
		//
		// ACK 不可逆——回了 `1|OK`，綠界就不會再送這則通知。資料庫暫時讀不到時
		// 若也回 OK，那筆狀態就永遠遺失了。不確定時一律回非 2xx，讓對方重送。
		if ( 'error' === $lookup['status'] ) {
			$this->respond_text( '0|Storage unavailable', 503 );
		}

		if ( 'mismatch' === $lookup['status'] ) {
			$this->respond_text( '0|Logistics binding mismatch', 400 );
		}

		if ( 'pending' === $lookup['status'] ) {
			$this->respond_text( '0|Label not persisted yet', 503 );
		}

		if ( 'not_found' === $lookup['status'] ) {
			// 確定不是我們的單：回 1|OK 讓對方停止重送，但**什麼都不寫**。
			$this->respond_text( '1|OK' );
		}

		$initial_label = $lookup['label'];
		$order_id      = (int) ( $initial_label->order_id ?? 0 );
		if ( $order_id <= 0 || ! $this->binding_matches( $initial_label, $params ) ) {
			$this->respond_text( '0|Logistics binding mismatch', 400 );
		}

		// Different signed status callbacks are not replay duplicates. They still mutate the same
		// order projections, so the pipeline decision and every durable write must share Core's
		// order-wide advisory serialization boundary.
		$serialized = $this->with_notify_serialization(
			$order_id,
			function () use ( $params, $order_id ): array {
				$locked_lookup = $this->find_label( $params );
				$status        = (string) ( $locked_lookup['status'] ?? 'error' );

				if ( 'mismatch' === $status ) {
					return [ 'body' => '0|Logistics binding mismatch', 'status' => 400 ];
				}
				if ( 'not_found' === $status || 'pending' === $status ) {
					return [ 'body' => '0|Label not persisted yet', 'status' => 503 ];
				}
				if ( 'found' !== $status ) {
					return [ 'body' => '0|Storage unavailable', 'status' => 503 ];
				}

				$locked_label = $locked_lookup['label'];
				if ( $order_id !== (int) ( $locked_label->order_id ?? 0 )
					|| ! $this->binding_matches( $locked_label, $params ) ) {
					return [ 'body' => '0|Logistics binding mismatch', 'status' => 400 ];
				}

				$authority_status = $this->callback_authority_status( $locked_label );
				if ( 'stale' === $authority_status ) {
					return [ 'body' => '1|OK', 'status' => 200 ];
				}
				if ( 'current' !== $authority_status ) {
					return [ 'body' => '0|Storage unavailable', 'status' => 503 ];
				}

				// The lock can wait behind another callback. Drop any request-local order snapshot before
				// the first transition decision so a later worker cannot project stale state backwards.
				YSOrder::forget( $order_id );
				$order = $this->find_order_by_label( $locked_label );
				if ( null === $order ) {
					return [ 'body' => '0|Storage unavailable', 'status' => 503 ];
				}

				if ( ! $this->update_order_shipping( $order, $params, $locked_label ) ) {
					return [ 'body' => '0|Persistence failed', 'status' => 503 ];
				}

				return [ 'body' => '1|OK', 'status' => 200 ];
			}
		);

		$release = (string) ( $serialized['serialization_release'] ?? '' );
		$result  = $serialized['result'] ?? null;
		if ( true !== ( $serialized['guard'] ?? false )
			|| ! is_array( $result )
			|| ! in_array( $release, [ 'released', 'session_closed', 'reentrant' ], true ) ) {
			$this->respond_text( '0|Serialization unavailable', 503 );
		}

		$this->respond_text(
			(string) ( $result['body'] ?? '0|Storage unavailable' ),
			(int) ( $result['status'] ?? 503 )
		);
	}

	/** @return array{guard:bool,reason:string,result:mixed,serialization_release:string} */
	private function with_notify_serialization( int $order_id, callable $fn ): array {
		return YSShippingDispatchAuthority::with_order_serialization( $order_id, $fn );
	}

	/**
	 * 依物流編號找出本站的物流單列。
	 *
	 * 🔴 回 typed 結果，把「確定不是我們的單」與「資料庫讀不動」分開。
	 * 兩者壓成同一個 `null` 的話，暫時性的 SQL 失敗會走到「回 1|OK」——
	 * 而 ACK 之後那筆通知就永遠不會再來了。
	 *
	 * @param array<string,string> $params
	 * @return array{status:string,label:?object}
	 */
	private function find_label( array $params ): array {
		$logistics_id = trim( (string) ( $params['AllPayLogisticsID'] ?? '' ) );
		$trade_no      = trim( (string) ( $params['MerchantTradeNo'] ?? '' ) );
		if ( '' === $logistics_id || '' === $trade_no ) {
			return [ 'status' => 'mismatch', 'label' => null ];
		}

		global $wpdb;
		$labels_table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';
		$attempt      = $this->find_dispatch_attempt_order( $trade_no );
		if ( 'error' === $attempt['status'] ) {
			return [ 'status' => 'error', 'label' => null ];
		}

		if ( 'found' === $attempt['status'] ) {
			$order_id = (int) $attempt['order_id'];
			$label    = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$labels_table} WHERE provider = %s AND provider_trade_no = %s AND order_id = %d AND merchant_trade_no = %s ORDER BY id DESC LIMIT 1",
				'ecpay',
				$logistics_id,
				$order_id,
				$trade_no
			) );

			if ( null !== $label ) {
				return [ 'status' => 'found', 'label' => $label ];
			}
			if ( '' !== (string) $wpdb->last_error ) {
				return [ 'status' => 'error', 'label' => null ];
			}

			// If this provider trade number belongs to another local tuple, this is a
			// malformed cross-order callback, not an early-arrival retry.
			$other = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$labels_table} WHERE provider = %s AND provider_trade_no = %s LIMIT 1",
				'ecpay',
				$logistics_id
			) );
			if ( '' !== (string) $wpdb->last_error ) {
				return [ 'status' => 'error', 'label' => null ];
			}

			return null !== $other
				? [ 'status' => 'mismatch', 'label' => null ]
				: [ 'status' => 'pending', 'label' => null ];
		}

		// No pre-send authority exists for this MerchantTradeNo. A provider trade number
		// that nevertheless exists locally is an identity mismatch; otherwise it is
		// definitively unrelated to this installation and may be ACKed without writes.
		$other = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$labels_table} WHERE provider = %s AND provider_trade_no = %s LIMIT 1",
			'ecpay',
			$logistics_id
		) );
		if ( '' !== (string) $wpdb->last_error ) {
			return [ 'status' => 'error', 'label' => null ];
		}

		return null !== $other
			? [ 'status' => 'mismatch', 'label' => null ]
			: [ 'status' => 'not_found', 'label' => null ];
	}

	/**
	 * 這個 `MerchantTradeNo` 是不是我們送出去過的建單
	 *
	 * 授權表在**送出之前**就落盤，因此它是「這一單是不是我們發的」唯一可靠的
	 * 依據——label 表要等回應回來才寫得進去。
	 *
	 * @param array<string,string> $params
	 * @return string 'found'|'not_found'|'error'
	 */
	private function find_dispatch_attempt_order( string $trade_no ): array {
		global $wpdb;
		$attempts = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_label_attempts';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $attempts ) );

		// 🔴 `SHOW TABLES` 也會失敗（連線斷了、權限被撤）。把失敗讀成「表不存在」
		// 就會走到「不是我們的單」→ ACK，而那是不可逆的。查不動時回 error，
		// 呼叫端會回 503 讓對方重送。
		if ( '' !== (string) $wpdb->last_error ) {
			return [ 'status' => 'error', 'order_id' => 0 ];
		}

		if ( $exists !== $attempts ) {
			// Runtime callback identity cannot be proven without the durable attempt table.
			return [ 'status' => 'error', 'order_id' => 0 ];
		}

		$found = $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT order_id FROM {$attempts} WHERE merchant_trade_no = %s AND provider = %s LIMIT 1",
			$trade_no,
			'ecpay'
		) );

		if ( null === $found ) {
			return '' !== (string) $wpdb->last_error
				? [ 'status' => 'error', 'order_id' => 0 ]
				: [ 'status' => 'not_found', 'order_id' => 0 ];
		}

		return [ 'status' => 'found', 'order_id' => (int) $found ];
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
		if ( '' === $stored_trade_no || trim( (string) $params['MerchantTradeNo'] ) !== $stored_trade_no ) {
			return false;
		}

		$stored_subtype   = trim( (string) ( $label->logistics_subtype ?? '' ) );
		$expected_subtype = '' !== $stored_subtype ? $stored_subtype : (string) $descriptor['logistics_subtype'];

		return trim( (string) $params['LogisticsSubType'] ) === $expected_subtype;
	}

	/**
	 * Decide whether this exact label still owns callback authority while the order lock is held.
	 *
	 * Local cancelled flags are not sufficient proof for legacy rows: older Core releases could
	 * mark a label cancelled without a provider-confirmed cancellation. Attempt-backed rows use
	 * Core's active label_id as the authority; legacy rows are matched against Core's equivalent
	 * newest no-attempt fallback without excluding those local flags.
	 *
	 * @return string 'current'|'stale'|'error'
	 */
	private function callback_authority_status( object $label ): string {
		$order_id = (int) ( $label->order_id ?? 0 );
		$label_id = (int) ( $label->id ?? 0 );
		if ( $order_id <= 0 || $label_id <= 0
			|| ! is_callable( [ YSShippingDispatchAuthority::class, 'active_attempt' ] ) ) {
			return 'error';
		}

		try {
			$authority = YSShippingDispatchAuthority::active_attempt(
				$order_id,
				(string) ( $label->shipping_method ?? '' )
			);
		} catch ( \Throwable $e ) {
			return 'error';
		}

		if ( null === $authority ) {
			return 'stale';
		}

		if ( ! empty( $authority->legacy ) ) {
			return $this->legacy_callback_authority_status( $order_id, $label_id );
		}

		$authority_label_id = (int) ( $authority->label_id ?? 0 );
		if ( $authority_label_id <= 0 ) {
			return 'error';
		}

		return $authority_label_id === $label_id ? 'current' : 'stale';
	}

	/** @return string 'current'|'stale'|'error' */
	private function legacy_callback_authority_status( int $order_id, int $label_id ): string {
		global $wpdb;

		$labels   = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';
		$attempts = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_label_attempts';
		$row      = $wpdb->get_row( $wpdb->prepare(
			"SELECT l.id FROM {$labels} l
			 LEFT JOIN {$attempts} a ON a.label_id = l.id
			 WHERE l.order_id = %d
			   AND a.id IS NULL
			 ORDER BY l.id DESC LIMIT 1",
			$order_id
		) );

		if ( null === $row ) {
			return '' !== (string) ( $wpdb->last_error ?? '' ) ? 'error' : 'stale';
		}

		return (int) ( $row->id ?? 0 ) === $label_id ? 'current' : 'stale';
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
	private function verify( array $params, string $method_id = '' ): bool {
		$credentials = '' !== trim( $method_id )
			? Settings::logistics_credentials_for_method( $method_id )
			: Settings::logistics_credentials_for_subtype( (string) ( $params['LogisticsSubType'] ?? '' ) );
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
	private function update_order_shipping( object $order, array $params, object $label ): bool {
		// 🔴 追蹤碼**只**取託運單號（BookingNote）。
		//
		// 寄貨編號（CVSPaymentNo）是賣家交貨用的憑據、物流編號（AllPayLogisticsID）
		// 是綠界內部的交易序號——三個語意完全不同。舊版沒有託運單號時退回物流編號，
		// 於是顧客拿去物流商網站查會查不到，而客服看到「有追蹤碼」就不會再追。
		// 沒有就是沒有（後續的通知會補上）。
		$tracking = trim( (string) ( $params['BookingNote'] ?? '' ) );
		$status   = (string) ( $params['LogisticsStatus'] ?? $params['RtnCode'] ?? '' );

		// 🔴 pipeline 先決定，狀態才寫得下去。
		//
		// 順序反過來的話（先寫 order/label 的狀態、再問 pipeline），遇到一則
		// **遲到或亂序**的通知就會這樣：label 已經被改成「配送中」，pipeline 才
		// 說「已取貨不能倒退回配送中」而拒絕——結果 order 說已取貨、label 說
		// 配送中，兩邊各說各話，而我們還回了 1|OK 讓對方不要再送。
		//
		// 三種結果分開處理：
		//   persisted=false  寫不進去＝retryable，回非 2xx，**什麼都還沒寫**。
		//   success=false    不允許的轉換＝已知且重送也不會變 → 不寫狀態、照樣 ACK。
		//   success=true     正常前進 → 狀態可以寫。
		$status_advance_allowed = true;
		if ( '' !== $status && class_exists( YSShippingPipelineService::class ) ) {
			$advanced = YSShippingPipelineService::advance_from_carrier_status(
				(int) $order->id,
				$status,
				'webhook_ecpay'
			);

			if ( is_array( $advanced ) ) {
				if ( false === ( $advanced['persisted'] ?? true ) ) {
					return false;
				}
				$status_advance_allowed = ! empty( $advanced['success'] );
			}
		}

		// 🔴 狀態類的欄位只在 pipeline 允許前進時才覆寫。
		// 遲到／亂序的通知若照樣寫進投影，訂單狀態說「已取貨」而 payment_detail
		// 說「配送中」——同一件事兩邊各說各話，而我們還 ACK 了。
		// 憑據類（物流編號、追蹤碼）不受影響：它們是補齊，不是倒退。
		$projection = [
			'provider'            => 'ecpay',
			'allpay_logistics_id' => (string) ( $params['AllPayLogisticsID'] ?? '' ),
			'updated_at'          => current_time( 'mysql' ),
		];
		if ( '' !== $tracking ) {
			$projection['tracking_number'] = $tracking;
		}

		if ( $status_advance_allowed ) {
			$projection['logistics_status']     = $status;
			$projection['logistics_status_msg'] = (string) ( $params['LogisticsStatusName'] ?? $params['RtnMsg'] ?? '' );
		}

		// v0.3.0：payment_detail 走核心共用 CAS（YSPaymentDetailStore）。notify
		// 序列化鎖只擋得住「同類」物流 callback；付款通知與退款 finalization ledger
		// 是同一個 JSON 欄位的**另一群** writer——這裡若維持整包 read-modify-write，
		// 會把剛落盤的退款憑據（不重複退款的唯一依據）整段抹掉。
		$mutated = OrderPaymentDetail::mutate(
			(int) $order->id,
			static function ( array $detail ) use ( $projection ): array {
				$detail['shipping'] = array_merge(
					is_array( $detail['shipping'] ?? null ) ? $detail['shipping'] : [],
					$projection
				);
				return $detail;
			}
		);
		if ( ! $mutated->is_persisted() ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 物流 callback 的 payment_detail 寫入失敗（不 ACK，等重送）', array_merge(
				[
					'order_id' => (int) $order->id,
					'status'   => $status,
				],
				$mutated->to_log_context()
			) );

			return false;
		}

		$order_update = [];
		if ( '' !== $tracking ) {
			$order_update['tracking_number'] = $tracking;
		}

		// 沒有 pipeline 時由這裡直接對映；有 pipeline 時狀態由它負責，這裡不碰。
		$legacy_status = $this->map_status( $status );
		if ( ! class_exists( YSShippingPipelineService::class )
			&& $status_advance_allowed
			&& null !== $legacy_status ) {
			$order_update['shipping_status'] = $legacy_status;
		}

		// 🔴 `YSOrder::update()` 對 affected=0 也回 true——「回 true」不代表寫進去了。
		// ACK 不可逆，所以每一個寫入都要**讀回來**確認。
		// （payment_detail 已由上方 CAS 落盤並驗證；這裡只剩純量欄位，可能為空。）
		if ( [] !== $order_update ) {
			if ( false === YSOrder::update( (int) $order->id, $order_update ) ) {
				return false;
			}

			if ( ! $this->order_update_persisted( (int) $order->id, $order_update ) ) {
				return false;
			}
		}

		return $this->sync_label( $label, $params, $tracking, $status, $status_advance_allowed );
	}

	/**
	 * 訂單那幾個欄位真的落盤了嗎
	 *
	 * @param array<string,mixed> $expected
	 */
	private function order_update_persisted( int $order_id, array $expected ): bool {
		global $wpdb;

		$orders_table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'orders';
		$row          = $wpdb->get_row( $wpdb->prepare(
			"SELECT payment_detail, tracking_number FROM {$orders_table} WHERE id = %d",
			$order_id
		) );

		if ( null === $row ) {
			return false;
		}

		foreach ( [ 'payment_detail', 'tracking_number' ] as $column ) {
			if ( ! array_key_exists( $column, $expected ) ) {
				continue;
			}
			if ( (string) ( $row->{$column} ?? '' ) !== (string) $expected[ $column ] ) {
				return false;
			}
		}

		return true;
	}

	private function map_status( string $status ): ?string {
		return EcpayShippingCatalog::pipeline_state_for_logistics_status( $status );
	}

	/**
	 * 更新這**一張**物流單。
	 *
	 * 🔴 舊版以 (order_id, provider_trade_no) 當條件更新，同一張訂單有多張物流單
	 * 時可能一次改到不只一列。綁定既然已經驗到具體那一列，就直接以主鍵更新。
	 *
	 * @param array<string,string> $params
	 * @param bool                 $write_status pipeline 允許前進時才寫狀態欄位
	 */
	private function sync_label( object $label, array $params, string $tracking, string $status, bool $write_status = true ): bool {
		global $wpdb;
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';

		$update = [
			'updated_at' => current_time( 'mysql' ),
		];

		// 🔴 pipeline 說這個轉換不允許（遲到／亂序的通知）時就不要寫狀態。
		// 寫下去的話 label 會被改回一個比訂單更早的狀態——同一件事兩邊各說各話。
		// 憑據類欄位（追蹤碼、寄貨編號）不受影響：它們是補齊，不是倒退。
		$pipeline_status = $write_status ? $this->map_status( $status ) : null;
		$label_status = null === $pipeline_status
			? null
			: EcpayShippingCatalog::label_status_for_pipeline_state( $pipeline_status );
		if ( null !== $label_status ) {
			$update['status']            = $label_status;
			$update['status_code']       = $status;
			$update['status_updated_at'] = current_time( 'mysql' );
		}

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

		if ( false === $wpdb->update( $table, $update, [ 'id' => (int) $label->id ] ) ) {
			return false;
		}

		// affected=0 也回 0（不是 false），因此要讀回本次實際寫入的**每一欄**。
		// C2C 的寄件碼若只檢查「呼叫過 update」而沒讀回，silent no-op 仍會被 ACK。
		$columns   = array_keys( $update );
		$persisted = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal column allowlist from $update.
			"SELECT " . implode( ', ', $columns ) . " FROM {$table} WHERE id = %d",
			(int) $label->id
		) );

		if ( null === $persisted ) {
			return false;
		}

		foreach ( $update as $column => $value ) {
			if ( (string) ( $persisted->{$column} ?? '' ) !== (string) $value ) {
				return false;
			}
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
