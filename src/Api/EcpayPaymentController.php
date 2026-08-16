<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\YSCartEcpay\Support\ScalarColumnWriter;
use YangSheep\Ecommerce\Security\YSInboundPermission;
use YangSheep\Ecommerce\Services\Payment\YSPaymentLifecycleService;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpayPaymentController {
	public static function register_routes(): void {
		$controller = new self();

		register_rest_route( 'ys-ecommerce/v1', '/ecpay/notify', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'notify' ],
			'permission_callback' => [ self::class, 'notify_permission' ],
		] );

		register_rest_route( 'ys-ecommerce/v1', '/ecpay/payment-info', [
			'methods'             => 'POST',
			'callback'            => [ $controller, 'payment_info' ],
			'permission_callback' => [ self::class, 'payment_info_permission' ],
		] );

		register_rest_route( 'ys-ecommerce/v1', '/ecpay/return', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $controller, 'return_page' ],
			'permission_callback' => [ self::class, 'return_permission' ],
		] );
	}

	public static function notify_permission( \WP_REST_Request $request ) {
		return self::inbound_permission( 'ecpay_notify', [
			'body_max_bytes' => 65536,
			'rate_limit'     => [ 300, 60 ],
			'allowed_types'  => [ 'application/x-www-form-urlencoded' ],
		], $request );
	}

	public static function payment_info_permission( \WP_REST_Request $request ) {
		return self::inbound_permission( 'ecpay_payment_info', [
			'body_max_bytes' => 65536,
			'rate_limit'     => [ 300, 60 ],
			'allowed_types'  => [ 'application/x-www-form-urlencoded' ],
		], $request );
	}

	public static function return_permission( \WP_REST_Request $request ) {
		return self::inbound_permission( 'ecpay_return', [
			'body_max_bytes' => 65536,
			'rate_limit'     => [ 600, 60 ],
			'allowed_types'  => [],
			'verify_ip'      => false,
		], $request );
	}

	private static function inbound_permission( string $context, array $opts, \WP_REST_Request $request ) {
		if ( ! class_exists( YSInboundPermission::class ) ) {
			return true;
		}

		$callback = YSInboundPermission::build( $context, $opts );
		return $callback( $request );
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

		// v0.3.0：核對的基準是**建單時實際送出的金額**，不是 $order->total。
		// total 可能在建單之後被其他流程改動（改價、折扣重算），拿它比對會把一筆
		// 正確的付款判成金額不符；而 `(int) round( total )` 更會讓 1000.5 的訂單
		// 「剛好」對上 1001 的付款，把錯付當成正確。
		$detail_now      = OrderPaymentDetail::read( (int) $order->id );
		$charged         = is_array( $detail_now ) && isset( $detail_now['ecpay_charged_amount'] )
			? (int) $detail_now['ecpay_charged_amount']
			: null;
		$expected_amount = null !== $charged ? $charged : (int) round( (float) ( $order->total ?? 0 ) );
		$received_amount = (int) ( $params['TradeAmt'] ?? 0 );
		if ( $expected_amount > 0 && $expected_amount !== $received_amount ) {
			$this->respond_text( '0|Amount Mismatch', 400 );
		}

		$detail = $this->detail_from_payload( $params );
		if ( '1' === (string) ( $params['RtnCode'] ?? '' ) ) {
			// v0.3.0：持久化信用卡授權單號 gwsr（NeedExtraPaidInfo=Y 回傳、已過
			// CheckMacValue 驗證）——退款的關帳狀態查詢（CreditDetail/QueryTrade）依賴它。
			// v0.3.0：連同**卡別方案的權威欄位**一起持久化。這些值來自已通過
			// CheckMacValue 驗證的付款通知，是我們唯一能證明「這筆是不是分期／
			// 紅利」的來源；退款時的 gate 需要它們，而 QueryTradeInfo 不保證每一
			// 種交易都會回傳同樣的欄位。
			//
			// `stage`（分期期數）與 `red_*`（紅利折抵）即使是 0 也要存——「明確為 0」
			// 與「沒有這個欄位」是兩種不同的證據狀態，後者不能被當成前者。
			$program_fields = [];
			foreach ( [ 'stage', 'stast', 'staed', 'red_dan', 'red_de_amt', 'red_ok_amt', 'red_yet', 'eci' ] as $program_key ) {
				if ( array_key_exists( $program_key, $params ) ) {
					$program_fields[ 'ecpay_' . $program_key ] = sanitize_text_field( (string) $params[ $program_key ] );
				}
			}
			$program_fields['ecpay_payment_type'] = sanitize_text_field( (string) ( $params['PaymentType'] ?? '' ) );

			$gwsr = sanitize_text_field( (string) ( $params['gwsr'] ?? '' ) );
			if ( '' !== $gwsr || $program_fields ) {
				// 走核心共用 CAS。此處與退款 ledger（`_ys_ecpay_refunds`）是同一個
				// payment_detail 欄位的兩個併發 writer；read-modify-write 會在
				// webhook 與退款重疊時整包覆蓋對方的寫入。
				$gwsr_written = OrderPaymentDetail::mutate(
					(int) $order->id,
					static function ( array $detail ) use ( $gwsr, $program_fields ): array {
						if ( '' !== $gwsr ) {
							$detail['gwsr'] = $gwsr;
						}
						foreach ( $program_fields as $key => $value ) {
							$detail[ $key ] = $value;
						}
						return $detail;
					}
				);

				// v0.3.0：寫不進去就**不得** ACK。gwsr 是關帳狀態查詢（CreditDetail/
				// QueryTrade）的唯一輸入，缺了它整條退款路徑都無法判定該送 E／N／R。
				// 回 1|OK 會讓綠界停止重送，這個欄位就永久遺失了；回非 1|OK 才能讓
				// 綠界依其重送機制再送一次。
				if ( ! $gwsr_written->is_persisted() ) {
					YSLogger::error( 'ecpay', 'CRITICAL: 付款通知的授權／卡別欄位寫入失敗，拒絕 ACK 以觸發綠界重送', array_merge(
						[ 'order_id' => (int) $order->id ],
						$gwsr_written->to_log_context()
					) );
					$this->respond_text( '0|Persist Failed', 500 );
					return;
				}
			}

			// v0.3.0：TradeNo 是退款、對帳、客服查詢的唯一交易識別碼。
			//
			// 🔴 兩件事都必須成立才可以 ACK：
			//   1. TradeNo 是**非空**字串。空字串不是識別碼——把它寫進去等於宣稱
			//      「這筆交易沒有編號」，而下游會把那當成事實。
			//   2. 值**確實落在 DB 裡**。`YSOrder::update()` 回 true 不代表寫進去了
			//      （affected=0 也算 true，而那可能是「訂單不存在」）。
			$trade_no = ScalarColumnWriter::required_string(
				sanitize_text_field( (string) ( $params['TradeNo'] ?? '' ) )
			);

			if ( null === $trade_no ) {
				YSLogger::error( 'ecpay', 'CRITICAL: 付款成功通知未帶 TradeNo，拒絕 ACK', [
					'order_id' => (int) $order->id,
				] );
				$this->respond_text( '0|Persist Failed', 500 );
				return;
			}

			$written = ScalarColumnWriter::write( (int) $order->id, [ 'gateway_trade_no' => $trade_no ] );
			if ( ! ScalarColumnWriter::is_persisted( $written ) ) {
				YSLogger::error( 'ecpay', 'CRITICAL: gateway_trade_no 寫入失敗，拒絕 ACK 以觸發綠界重送', [
					'order_id' => (int) $order->id,
					'state'    => $written['state'],
				] );
				$this->respond_text( '0|Persist Failed', 500 );
				return;
			}
			$transition = YSPaymentLifecycleService::mark_paid( (int) $order->id, $detail, 'webhook_ecpay_notify' );
		} else {
			$transition = YSPaymentLifecycleService::mark_failed( (int) $order->id, $detail, 'webhook_ecpay_notify' );
		}

		// v0.3.0：生命週期推進失敗（含 payment_detail CAS 失敗）同樣不得 ACK——
		// 訂單狀態沒有落盤卻告訴綠界「收到了」，這筆付款就再也不會被通知。
		if ( ! $this->transition_persisted( $transition, (int) $order->id, 'notify' ) ) {
			$this->respond_text( '0|Persist Failed', 500 );
			return;
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
			$transition = YSPaymentLifecycleService::mark_pending_offline(
				(int) $order->id,
				$this->detail_from_payload( $params ),
				'webhook_ecpay_payment_info'
			);

			// v0.3.0：取號資訊（繳費代碼、虛擬帳號、繳費期限）沒落盤就**不得** ACK。
			// 這是消費者拿去繳費的唯一憑據；回 1|OK 會讓綠界停止重送，訂單頁上就
			// 永遠沒有繳費代碼可顯示。
			if ( ! $this->transition_persisted( $transition, (int) $order->id, 'payment_info' ) ) {
				$this->respond_text( '0|Persist Failed', 500 );
				return;
			}
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
		// 🔴 R14 reader lease：驗章讀的也是當下憑證——設定 commit 期間讀到的
		// 可能是半套用 tuple，據以判「驗章失敗」會誤拒真回呼。拿不到 lease＝
		// 回 false → 呼叫端回 0|Invalid（非 2xx），綠界會重送 notify；
		// return/payment_info 則顯示暫時失敗頁——都不遺失。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return false;
		}

		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv']
			|| (string) ( $params['MerchantID'] ?? '' ) !== $credentials['merchant_id'] ) {
			return false;
		}

		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
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

	/**
	 * lifecycle 結果是否已落盤（v0.3.0）
	 *
	 * `retryable` 為 true 代表狀態或 payment_detail 沒寫進去——必須回非 1|OK 讓
	 * 綠界重送。業務拒絕（金額不符、狀態機不允許）重送也不會有不同結果，視為
	 * 已處理，否則綠界會無止盡重試一筆永遠不會成功的通知。
	 *
	 * @param array<string,mixed>|mixed $transition
	 */
	private function transition_persisted( $transition, int $order_id, string $stage ): bool {
		if ( ! is_array( $transition ) ) {
			return true;
		}
		if ( ! empty( $transition['success'] ) ) {
			return true;
		}
		if ( empty( $transition['retryable'] ) ) {
			return true; // 業務拒絕：重送無用
		}

		YSLogger::error( 'ecpay', 'CRITICAL: 狀態推進失敗，拒絕 ACK 以觸發綠界重送', [
			'order_id' => $order_id,
			'stage'    => $stage,
			'message'  => (string) ( $transition['message'] ?? '' ),
			'from'     => (string) ( $transition['from'] ?? '' ),
			'to'       => (string) ( $transition['to'] ?? '' ),
		] );

		return false;
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
