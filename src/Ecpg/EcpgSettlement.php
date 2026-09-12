<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Ecpg;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Models\YSCreditCard;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Services\Payment\YSPaymentLifecycleService;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\ScalarColumnWriter;
use YangSheep\YSCartEcpay\Support\Settings;

/**
 * 站內付 2.0 授權結果 → YS CART 訂單狀態（v0.5.0）
 *
 * 同一筆交易的結果最多會從**三條路**進來：CreateBindCard／CreatePayment 的同步回應、
 * 3D 驗證後瀏覽器導回的 OrderResultURL、綠界幕後送的 ReturnURL（最多重送 4 次）。
 * 三條路都走這裡，順序不定、可能重複——因此每一步都必須是冪等的：
 *
 *   - 付款成功 → `mark_paid()`：核心狀態機只接受 pending／offline_payment → processing，
 *     第二次進來會被業務拒絕（retryable=false），這裡把它讀成 `already_paid`，不是錯。
 *   - 存卡 → `YSCreditCard::create_or_get()`：以 token_hash 去重，重跑拿到同一列。
 *
 * 🔴 驗證順序：MerchantID → MerchantTradeNo 歸屬 → RtnCode → TradeStatus → 金額 → TradeNo。
 * 金額比的是**建單時實際送出的** `ecpay_charged_amount`，不是 `$order->total`（可能已被
 * 其他流程改動）。任何一關沒過都不改狀態，回 `rejected` 讓呼叫端決定怎麼回應綠界。
 */
final class EcpgSettlement {
	public const STATUS_PAID           = 'paid';
	public const STATUS_ALREADY_PAID   = 'already_paid';
	public const STATUS_PENDING        = 'pending';
	public const STATUS_FAILED         = 'failed';
	public const STATUS_REJECTED       = 'rejected';
	public const STATUS_PERSIST_FAILED = 'persist_failed';

	public const VAULT_DONE    = 'done';
	public const VAULT_FAILED  = 'failed';
	public const VAULT_SKIPPED = 'skipped';

	/** States that already prove the same order is paid or in fulfillment. */
	private const PAID_FULFILLMENT_STATES = [
		'processing'    => true,
		'paid'          => true,
		'awaiting_ship' => true,
		'shipping'      => true,
		'shipped'       => true,
		'completed'     => true,
	];

	/**
	 * 以 MerchantTradeNo 找回訂單（`mer_trade_no` 或 `ecpay_merchant_trade_no` 任一命中）。
	 *
	 * `JSON_UNQUOTE(JSON_EXTRACT(...)) = %s`——不能寫成 `JSON_EXTRACT(...) = '"x"'`，那在
	 * MySQL 與 MariaDB 上都永遠為假且不報錯（Core 2.62.2 的教訓）。
	 */
	public static function find_order_by_merchant_trade_no( string $merchant_trade_no ): ?object {
		$merchant_trade_no = trim( $merchant_trade_no );
		if ( '' === $merchant_trade_no || 1 !== preg_match( '/^[A-Za-z0-9]{1,20}$/', $merchant_trade_no ) ) {
			return null;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'orders';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE JSON_UNQUOTE(JSON_EXTRACT(payment_detail, '$.mer_trade_no')) = %s
				    OR JSON_UNQUOTE(JSON_EXTRACT(payment_detail, '$.ecpay_merchant_trade_no')) = %s
				 ORDER BY id DESC LIMIT 1",
				$merchant_trade_no,
				$merchant_trade_no
			)
		);
		if ( ! is_object( $row ) ) {
			return null;
		}
		// 走 YSOrder 的正式讀取（快取、型別），並再確認歸屬。
		$order = YSOrder::find( (int) ( $row->id ?? 0 ) );
		return $order && self::order_has_merchant_trade_no( $order, $merchant_trade_no ) ? $order : null;
	}

	public static function order_has_merchant_trade_no( object $order, string $merchant_trade_no ): bool {
		$detail = self::detail_of( $order );
		return '' !== $merchant_trade_no && (
			hash_equals( (string) ( $detail['mer_trade_no'] ?? '' ), $merchant_trade_no )
			|| hash_equals( (string) ( $detail['ecpay_merchant_trade_no'] ?? '' ), $merchant_trade_no )
		);
	}

	/** @return array<string,mixed> */
	public static function detail_of( object $order ): array {
		$raw = $order->payment_detail ?? null;
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$detail = json_decode( (string) ( $raw ?? '{}' ), true );
		return is_array( $detail ) ? $detail : [];
	}

	/**
	 * 套用一份解密後的授權結果（RtnCode 1 與否都可交進來）。
	 *
	 * @param array<string,mixed> $data   解密後的 Data
	 * @param string              $source 'ecpg_confirm'｜'ecpg_return'｜'ecpg_result'｜'ecpg_charge_saved'
	 * @return array{status:string,message:string,vault:string,retryable:bool}
	 */
	public static function apply( object $order, array $data, string $source ): array {
		$order_id    = (int) ( $order->id ?? 0 );
		$credentials = Settings::payment_credentials();
		if ( '' === $credentials['merchant_id'] || (string) ( $data['MerchantID'] ?? '' ) !== $credentials['merchant_id'] ) {
			return self::outcome( self::STATUS_REJECTED, 'MerchantID 與本站設定不符。' );
		}

		$order_info        = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$merchant_trade_no = (string) ( $order_info['MerchantTradeNo'] ?? '' );
		if ( ! self::order_has_merchant_trade_no( $order, $merchant_trade_no ) ) {
			return self::outcome( self::STATUS_REJECTED, 'MerchantTradeNo 不屬於這張訂單。' );
		}

		$rtn_code = isset( $data['RtnCode'] ) && is_numeric( $data['RtnCode'] ) ? (int) $data['RtnCode'] : null;
		if ( 1 !== $rtn_code ) {
			return self::mark_failure( $order, $data, $source );
		}

		$trade_status = EcpgClient::trade_status( $data );
		if ( null !== $trade_status && '1' !== $trade_status ) {
			// 成立但未付款：不是失敗（3D 尚未完成或銀行未回覆），也不得標已付。
			return self::outcome( self::STATUS_PENDING, '綠界回報交易成立但尚未付款。' );
		}

		$detail_now = self::detail_of( $order );
		$expected   = isset( $detail_now['ecpay_charged_amount'] ) ? (int) $detail_now['ecpay_charged_amount'] : 0;
		$card_info  = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$reported   = $order_info['TradeAmt'] ?? $card_info['Amount'] ?? null;
		if ( $expected > 0 && ( ! is_numeric( $reported ) || (int) $reported !== $expected ) ) {
			YSLogger::error( 'ecpay', 'ECPG 授權金額與建單金額不符，拒絕標記已付', [
				'order_id' => $order_id,
				'expected' => $expected,
				'reported' => $reported,
				'source'   => $source,
			] );
			return self::outcome( self::STATUS_REJECTED, '授權金額與訂單金額不符。' );
		}

		$trade_no = ScalarColumnWriter::required_string( sanitize_text_field( (string) ( $order_info['TradeNo'] ?? '' ) ) );
		if ( null === $trade_no ) {
			return self::outcome( self::STATUS_REJECTED, '付款成功結果未帶綠界交易編號。' );
		}

		// 授權證據（gwsr、3D 結果、卡號片段）：退款與客服查詢依賴，走核心共用 CAS。
		$evidence = [
			'ecpay_payment_type' => sanitize_text_field( (string) ( $order_info['PaymentType'] ?? 'Credit' ) ),
			'ecpay_ecpg_source'  => $source,
		];
		foreach ( [ 'Gwsr' => 'gwsr', 'Eci' => 'ecpay_eci', 'Stage' => 'ecpay_stage', 'Stast' => 'ecpay_stast', 'Staed' => 'ecpay_staed', 'AuthCode' => 'ecpay_auth_code' ] as $from => $to ) {
			if ( array_key_exists( $from, $card_info ) && is_scalar( $card_info[ $from ] ) ) {
				$evidence[ $to ] = sanitize_text_field( (string) $card_info[ $from ] );
			}
		}
		$written = OrderPaymentDetail::mutate( $order_id, static function ( array $detail ) use ( $evidence ): array {
			foreach ( $evidence as $key => $value ) {
				$detail[ $key ] = $value;
			}
			return $detail;
		} );
		if ( ! $written->is_persisted() ) {
			YSLogger::error( 'ecpay', 'CRITICAL: ECPG 授權證據寫入失敗', array_merge( [ 'order_id' => $order_id, 'source' => $source ], $written->to_log_context() ) );
			return self::outcome( self::STATUS_PERSIST_FAILED, '付款證據寫入失敗。', self::VAULT_SKIPPED, true );
		}

		$identity = ScalarColumnWriter::write( $order_id, [ 'gateway_trade_no' => $trade_no ] );
		if ( ! ScalarColumnWriter::is_persisted( $identity ) ) {
			YSLogger::error( 'ecpay', 'CRITICAL: ECPG gateway_trade_no 寫入失敗', [ 'order_id' => $order_id, 'state' => $identity['state'] ?? '' ] );
			return self::outcome( self::STATUS_PERSIST_FAILED, '交易編號寫入失敗。', self::VAULT_SKIPPED, true );
		}

		$transition = YSPaymentLifecycleService::mark_paid( $order_id, self::detail_dto( $data, $merchant_trade_no, $trade_no ), 'webhook_' . $source );
		$status     = self::STATUS_PAID;
		if ( ! is_array( $transition ) || empty( $transition['success'] ) ) {
			if ( is_array( $transition ) && ! empty( $transition['retryable'] ) ) {
				YSLogger::error( 'ecpay', 'CRITICAL: ECPG 訂單狀態推進失敗', [
					'order_id' => $order_id,
					'source'   => $source,
					'message'  => (string) ( $transition['message'] ?? '' ),
				] );
				return self::outcome( self::STATUS_PERSIST_FAILED, '訂單狀態寫入失敗。', self::VAULT_SKIPPED, true );
			}
			// 只有已付款／履約中的狀態能證明這是同一筆成功結果的重送。
			// cancelled/refunded/failed/timeout 的非 retryable 拒絕不是付款證據；
			// 若綠界此刻回報成功，必須停在待人工收斂而不是 ACK 或建立卡片效果。
			$from = is_array( $transition ) && is_string( $transition['from'] ?? null )
				? (string) $transition['from']
				: (string) ( $order->status ?? '' );
			if ( ! isset( self::PAID_FULFILLMENT_STATES[ $from ] ) ) {
				YSLogger::error( 'ecpay', 'CRITICAL: ECPG 成功結果與訂單終局狀態衝突', [
					'order_id' => $order_id,
					'source'   => $source,
					'from'     => $from,
				] );
				return self::outcome( self::STATUS_PERSIST_FAILED, '付款結果與訂單狀態衝突。', self::VAULT_SKIPPED, true );
			}
			$status = self::STATUS_ALREADY_PAID;
		}

		return self::outcome( $status, '', self::vault_card( $order, $data, $source ) );
	}

	/**
	 * RtnCode≠1：推進到 failed（與 AIO 通知同一處置），顧客從核心的重新付款入口再試一次
	 * （新的 attempt → 新的 MerchantTradeNo）。
	 *
	 * @return array{status:string,message:string,vault:string,retryable:bool}
	 */
	public static function mark_failure( object $order, array $data, string $source ): array {
		$order_id   = (int) ( $order->id ?? 0 );
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$message    = sanitize_text_field( (string) ( $data['RtnMsg'] ?? '' ) );
		$transition = YSPaymentLifecycleService::mark_failed(
			$order_id,
			self::detail_dto( $data, (string) ( $order_info['MerchantTradeNo'] ?? '' ), (string) ( $order_info['TradeNo'] ?? '' ) ),
			'webhook_' . $source
		);
		if ( is_array( $transition ) && empty( $transition['success'] ) && ! empty( $transition['retryable'] ) ) {
			return self::outcome( self::STATUS_PERSIST_FAILED, '失敗狀態寫入失敗。', self::VAULT_SKIPPED, true );
		}
		return self::outcome( self::STATUS_FAILED, '' !== $message ? $message : '綠界回報授權失敗。' );
	}

	/**
	 * 把 BindCardID 存進核心卡片庫（YSCrypto 加密、擁有者綁定、預設卡）。
	 *
	 * 🔴 BindCardID 絕不寫進 payment_detail 或 log；它是日後扣款的唯一憑證。
	 */
	private static function vault_card( object $order, array $data, string $source ): string {
		$bind_card_id = trim( (string) ( $data['BindCardID'] ?? '' ) );
		$customer_id  = (int) ( $order->customer_id ?? 0 );
		if ( '' === $bind_card_id || $customer_id <= 0 ) {
			return self::VAULT_SKIPPED;
		}
		if ( ! class_exists( YSCreditCard::class ) || ! method_exists( YSCreditCard::class, 'create_or_get' ) ) {
			return self::VAULT_FAILED;
		}
		$card_info = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$card_id   = YSCreditCard::create_or_get( [
			'customer_id' => $customer_id,
			'user_id'     => (int) ( $order->user_id ?? 0 ),
			'gateway_id'  => EcpgOrderContext::GATEWAY_ID,
			'token'       => $bind_card_id,
			'card_last4'  => preg_replace( '/\D/', '', (string) ( $card_info['Card4No'] ?? '' ) ) ?? '',
			'card_brand'  => EcpgOrderContext::card_brand( (string) ( $card_info['Card6No'] ?? '' ) ),
			'expire_date' => EcpgOrderContext::expire_date( $card_info ),
			'is_default'  => true,
		] );
		if ( false === $card_id ) {
			YSLogger::error( 'ecpay', 'CRITICAL: ECPG 綁卡 BindCardID 無法完整落盤', [
				'order_id'    => (int) ( $order->id ?? 0 ),
				'customer_id' => $customer_id,
				'source'      => $source,
			] );
			return self::VAULT_FAILED;
		}
		YSLogger::info( 'ecpay', 'ECPG 綁卡已存入卡片庫', [
			'order_id'    => (int) ( $order->id ?? 0 ),
			'customer_id' => $customer_id,
			'card_id'     => $card_id,
			'source'      => $source,
		] );
		return self::VAULT_DONE;
	}

	/** 成功／失敗共用的 DTO；金額用綠界回報的 TradeAmt（核心金額守衛會再核一次）。 */
	private static function detail_dto( array $data, string $merchant_trade_no, string $trade_no ): YSPaymentDetailDTO {
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$card_info  = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$amount     = $order_info['TradeAmt'] ?? $card_info['Amount'] ?? null;
		return YSPaymentDetailDTO::from_legacy_array( [
			'payment_type'     => 'credit_card',
			'trade_status'     => sanitize_text_field( (string) ( $order_info['TradeStatus'] ?? ( $data['RtnCode'] ?? '' ) ) ),
			'trade_no'         => sanitize_text_field( $trade_no ),
			'gateway_trade_no' => sanitize_text_field( $trade_no ),
			'mer_trade_no'     => sanitize_text_field( $merchant_trade_no ),
			'response_code'    => sanitize_text_field( (string) ( $data['RtnCode'] ?? '' ) ),
			'response_message' => sanitize_text_field( (string) ( $data['RtnMsg'] ?? '' ) ),
			'card_4no'         => sanitize_text_field( (string) ( $card_info['Card4No'] ?? '' ) ),
			'card_6no'         => sanitize_text_field( (string) ( $card_info['Card6No'] ?? '' ) ),
			'auth_code'        => sanitize_text_field( (string) ( $card_info['AuthCode'] ?? '' ) ),
			'paid_amount'      => is_numeric( $amount ) ? (float) $amount : 0.0,
		], EcpgOrderContext::GATEWAY_ID );
	}

	/** @return array{status:string,message:string,vault:string,retryable:bool} */
	private static function outcome( string $status, string $message, string $vault = self::VAULT_SKIPPED, bool $retryable = false ): array {
		return [ 'status' => $status, 'message' => $message, 'vault' => $vault, 'retryable' => $retryable ];
	}
}
